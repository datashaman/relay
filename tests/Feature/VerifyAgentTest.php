<?php

namespace Tests\Feature;

use App\Contracts\AiProvider;
use App\Enums\IssueStatus;
use App\Enums\RunStatus;
use App\Enums\StageName;
use App\Enums\StageStatus;
use App\Events\TestResultUpdated;
use App\Jobs\ExecuteStageJob;
use App\Models\Issue;
use App\Models\Run;
use App\Models\Stage;
use App\Services\AiProviders\AiProviderManager;
use App\Services\VerifyAgent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class VerifyAgentTest extends TestCase
{
    use RefreshDatabase;

    private string $worktreePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->worktreePath = sys_get_temp_dir().'/relay-test-verify-'.uniqid();
        mkdir($this->worktreePath, 0755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->worktreePath)) {
            $this->removeDirectory($this->worktreePath);
        }
        parent::tearDown();
    }

    private function removeDirectory(string $dir): void
    {
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir.'/'.$item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    private function setupRunWithStage(): array
    {
        $issue = Issue::factory()->create([
            'title' => 'Add user profile page',
            'body' => 'Users should see their profile info.',
            'status' => IssueStatus::InProgress,
        ]);

        $run = Run::factory()->create([
            'issue_id' => $issue->id,
            'status' => RunStatus::Running,
            'worktree_path' => $this->worktreePath,
            'branch' => 'relay/run-1',
            'preflight_doc' => "# Preflight Doc\n\n## Summary\nAdd a user profile page.\n\n## Acceptance Criteria\n1. Profile page renders\n2. Tests pass",
            'started_at' => now(),
        ]);

        $stage = Stage::factory()->create([
            'run_id' => $run->id,
            'name' => StageName::Verify,
            'status' => StageStatus::Running,
            'started_at' => now(),
        ]);

        return [$issue, $run, $stage];
    }

    private function bindMultiCallProvider(array $responses): void
    {
        $mock = new class($responses) implements AiProvider
        {
            private int $callIndex = 0;

            public function __construct(private array $responses) {}

            public function chat(array $messages, array $tools = [], array $options = []): array
            {
                return $this->responses[$this->callIndex++] ?? end($this->responses);
            }

            public function stream(array $messages, array $tools = [], array $options = []): \Generator
            {
                yield [];
            }
        };

        $manager = $this->createMock(AiProviderManager::class);
        $manager->method('resolve')->willReturn($mock);
        $this->app->instance(AiProviderManager::class, $manager);
    }

    private function mockTestsPassAndComplete(): array
    {
        return [
            [
                'content' => null,
                'tool_calls' => [
                    [
                        'id' => 'call_1',
                        'name' => 'run_tests',
                        'arguments' => [],
                    ],
                ],
                'usage' => ['input_tokens' => 100, 'output_tokens' => 50],
                'raw' => [],
            ],
            [
                'content' => null,
                'tool_calls' => [
                    [
                        'id' => 'call_2',
                        'name' => 'verification_complete',
                        'arguments' => [
                            'passed' => true,
                            'summary' => 'All tests pass. Implementation meets acceptance criteria.',
                            'failures' => [],
                        ],
                    ],
                ],
                'usage' => ['input_tokens' => 150, 'output_tokens' => 50],
                'raw' => [],
            ],
        ];
    }

    public function test_verify_agent_passes_and_completes_stage(): void
    {
        Queue::fake();
        Event::fake([TestResultUpdated::class]);
        Process::fake([
            '*' => Process::result('OK (5 tests, 10 assertions)'),
        ]);

        mkdir($this->worktreePath.'/vendor/bin', 0755, true);
        touch($this->worktreePath.'/vendor/bin/pest');

        [$issue, $run, $stage] = $this->setupRunWithStage();
        $this->bindMultiCallProvider($this->mockTestsPassAndComplete());

        app(VerifyAgent::class)->execute($stage, []);

        $stage->refresh();
        $this->assertEquals(StageStatus::Completed, $stage->status);
    }

    public function test_verify_agent_bounces_on_failure(): void
    {
        Queue::fake();
        Event::fake([TestResultUpdated::class]);
        Process::fake([
            '*' => Process::result('FAILURES! Tests: 5, Assertions: 8, Failures: 2', '', 1),
        ]);

        mkdir($this->worktreePath.'/vendor/bin', 0755, true);
        touch($this->worktreePath.'/vendor/bin/phpunit');

        [$issue, $run, $stage] = $this->setupRunWithStage();

        $this->bindMultiCallProvider([
            [
                'content' => null,
                'tool_calls' => [
                    ['id' => 'call_1', 'name' => 'run_tests', 'arguments' => []],
                ],
                'usage' => ['input_tokens' => 100, 'output_tokens' => 50],
                'raw' => [],
            ],
            [
                'content' => null,
                'tool_calls' => [
                    [
                        'id' => 'call_2',
                        'name' => 'verification_complete',
                        'arguments' => [
                            'passed' => false,
                            'summary' => 'Two tests failed.',
                            'failures' => [
                                [
                                    'test' => 'testProfileRenders',
                                    'assertion' => 'expected 200 got 404',
                                    'file' => 'tests/ProfileTest.php',
                                    'line' => 15,
                                ],
                                [
                                    'test' => 'testProfileShowsName',
                                    'assertion' => 'expected "John" got null',
                                    'file' => 'tests/ProfileTest.php',
                                    'line' => 25,
                                ],
                            ],
                        ],
                    ],
                ],
                'usage' => ['input_tokens' => 150, 'output_tokens' => 50],
                'raw' => [],
            ],
        ]);

        app(VerifyAgent::class)->execute($stage, []);

        $stage->refresh();
        $this->assertEquals(StageStatus::Bounced, $stage->status);
    }

    public function test_verify_agent_records_events(): void
    {
        Queue::fake();
        Event::fake([TestResultUpdated::class]);
        Process::fake([
            '*' => Process::result('OK (3 tests)'),
        ]);

        mkdir($this->worktreePath.'/vendor/bin', 0755, true);
        touch($this->worktreePath.'/vendor/bin/pest');

        [$issue, $run, $stage] = $this->setupRunWithStage();
        $this->bindMultiCallProvider($this->mockTestsPassAndComplete());

        app(VerifyAgent::class)->execute($stage, []);

        $events = $stage->events;
        $this->assertTrue($events->contains('type', 'verify_started'));
        $this->assertTrue($events->contains('type', 'tool_call'));
        $this->assertTrue($events->contains('type', 'test_results'));
        $this->assertTrue($events->contains('type', 'verify_complete'));
    }

    public function test_verify_agent_cannot_edit_files(): void
    {
        Queue::fake();
        Event::fake([TestResultUpdated::class]);
        Process::fake([
            '*' => Process::result(''),
        ]);

        [$issue, $run, $stage] = $this->setupRunWithStage();

        $this->bindMultiCallProvider([
            [
                'content' => null,
                'tool_calls' => [
                    [
                        'id' => 'call_1',
                        'name' => 'run_shell',
                        'arguments' => ['command' => 'echo "test" > hacked.txt'],
                    ],
                ],
                'usage' => ['input_tokens' => 10, 'output_tokens' => 10],
                'raw' => [],
            ],
            [
                'content' => null,
                'tool_calls' => [
                    [
                        'id' => 'call_2',
                        'name' => 'verification_complete',
                        'arguments' => ['passed' => true, 'summary' => 'Done.'],
                    ],
                ],
                'usage' => ['input_tokens' => 10, 'output_tokens' => 10],
                'raw' => [],
            ],
        ]);

        app(VerifyAgent::class)->execute($stage, []);

        $tools = collect(self::getConstant(VerifyAgent::class, 'TOOLS'))->pluck('name')->toArray();
        $this->assertNotContains('write_file', $tools);
    }

    public function test_verify_agent_blocks_git_push(): void
    {
        Queue::fake();
        Event::fake([TestResultUpdated::class]);
        Process::fake();

        [$issue, $run, $stage] = $this->setupRunWithStage();

        $this->bindMultiCallProvider([
            [
                'content' => null,
                'tool_calls' => [
                    ['id' => 'call_1', 'name' => 'run_shell', 'arguments' => ['command' => 'git push origin main']],
                ],
                'usage' => ['input_tokens' => 10, 'output_tokens' => 10],
                'raw' => [],
            ],
            [
                'content' => null,
                'tool_calls' => [
                    ['id' => 'call_2', 'name' => 'verification_complete', 'arguments' => ['passed' => true, 'summary' => 'Done.']],
                ],
                'usage' => ['input_tokens' => 10, 'output_tokens' => 10],
                'raw' => [],
            ],
        ]);

        app(VerifyAgent::class)->execute($stage, []);

        $toolEvent = $stage->events()->where('type', 'tool_call')->first();
        $this->assertFalse($toolEvent->payload['success']);
    }

    public function test_verify_agent_blocks_pr_creation(): void
    {
        Queue::fake();
        Event::fake([TestResultUpdated::class]);
        Process::fake();

        [$issue, $run, $stage] = $this->setupRunWithStage();

        $this->bindMultiCallProvider([
            [
                'content' => null,
                'tool_calls' => [
                    ['id' => 'call_1', 'name' => 'run_shell', 'arguments' => ['command' => 'gh pr create --title test']],
                ],
                'usage' => ['input_tokens' => 10, 'output_tokens' => 10],
                'raw' => [],
            ],
            [
                'content' => null,
                'tool_calls' => [
                    ['id' => 'call_2', 'name' => 'verification_complete', 'arguments' => ['passed' => true, 'summary' => 'Done.']],
                ],
                'usage' => ['input_tokens' => 10, 'output_outputs' => 10],
                'raw' => [],
            ],
        ]);

        app(VerifyAgent::class)->execute($stage, []);

        $toolEvent = $stage->events()->where('type', 'tool_call')->first();
        $this->assertFalse($toolEvent->payload['success']);
    }

    public function test_verify_agent_fails_without_worktree(): void
    {
        Queue::fake();
        Process::fake();

        [$issue, $run, $stage] = $this->setupRunWithStage();
        $run->update(['worktree_path' => null]);

        $this->bindMultiCallProvider([]);

        app(VerifyAgent::class)->execute($stage, []);

        $stage->refresh();
        $this->assertEquals(StageStatus::Failed, $stage->status);
    }

    public function test_verify_agent_broadcasts_test_results(): void
    {
        Queue::fake();
        Event::fake([TestResultUpdated::class]);
        Process::fake([
            '*' => Process::result('OK (5 tests, 10 assertions)'),
        ]);

        mkdir($this->worktreePath.'/vendor/bin', 0755, true);
        touch($this->worktreePath.'/vendor/bin/pest');

        [$issue, $run, $stage] = $this->setupRunWithStage();
        $this->bindMultiCallProvider($this->mockTestsPassAndComplete());

        app(VerifyAgent::class)->execute($stage, []);

        Event::assertDispatched(TestResultUpdated::class, function ($event) use ($stage) {
            return $event->stage->id === $stage->id;
        });
    }

    public function test_verify_agent_detects_test_runners(): void
    {
        mkdir($this->worktreePath.'/vendor/bin', 0755, true);
        touch($this->worktreePath.'/vendor/bin/pest');

        $agent = app(VerifyAgent::class);
        $method = new \ReflectionMethod($agent, 'detectTestRunner');

        $this->assertEquals('vendor/bin/pest', $method->invoke($agent, $this->worktreePath));

        unlink($this->worktreePath.'/vendor/bin/pest');
        touch($this->worktreePath.'/vendor/bin/phpunit');

        $this->assertEquals('vendor/bin/phpunit', $method->invoke($agent, $this->worktreePath));
    }

    public function test_verify_agent_rejects_path_escape(): void
    {
        Queue::fake();
        Event::fake([TestResultUpdated::class]);
        Process::fake([
            '*' => Process::result(''),
        ]);

        [$issue, $run, $stage] = $this->setupRunWithStage();

        $this->bindMultiCallProvider([
            [
                'content' => null,
                'tool_calls' => [
                    ['id' => 'call_1', 'name' => 'read_file', 'arguments' => ['path' => '../../../etc/passwd']],
                ],
                'usage' => ['input_tokens' => 10, 'output_tokens' => 10],
                'raw' => [],
            ],
            [
                'content' => null,
                'tool_calls' => [
                    ['id' => 'call_2', 'name' => 'verification_complete', 'arguments' => ['passed' => true, 'summary' => 'Done.']],
                ],
                'usage' => ['input_tokens' => 10, 'output_tokens' => 10],
                'raw' => [],
            ],
        ]);

        app(VerifyAgent::class)->execute($stage, []);

        $toolEvent = $stage->events()->where('type', 'tool_call')->first();
        $this->assertFalse($toolEvent->payload['success']);
    }

    public function test_verify_agent_structured_failure_report(): void
    {
        Queue::fake();
        Event::fake([TestResultUpdated::class]);
        Process::fake([
            '*' => Process::result('FAILURES!', '', 1),
        ]);

        [$issue, $run, $stage] = $this->setupRunWithStage();

        $this->bindMultiCallProvider([
            [
                'content' => null,
                'tool_calls' => [
                    [
                        'id' => 'call_1',
                        'name' => 'verification_complete',
                        'arguments' => [
                            'passed' => false,
                            'summary' => 'Tests failed.',
                            'failures' => [
                                [
                                    'test' => 'testUserCanLogin',
                                    'assertion' => 'expected 200 got 500',
                                    'file' => 'tests/AuthTest.php',
                                    'line' => 42,
                                ],
                            ],
                        ],
                    ],
                ],
                'usage' => ['input_tokens' => 100, 'output_tokens' => 50],
                'raw' => [],
            ],
        ]);

        app(VerifyAgent::class)->execute($stage, []);

        $verifyEvent = $stage->events()->where('type', 'verify_complete')->first();
        $this->assertNotNull($verifyEvent);
        $this->assertFalse($verifyEvent->payload['passed']);
        $this->assertEquals(1, $verifyEvent->payload['failure_count']);
        $this->assertEquals('testUserCanLogin', $verifyEvent->payload['failures'][0]['test']);
    }

    public function test_execute_stage_job_dispatches_verify_agent(): void
    {
        Queue::fake();
        Event::fake([TestResultUpdated::class]);
        Process::fake([
            '*' => Process::result(''),
        ]);

        [$issue, $run, $stage] = $this->setupRunWithStage();

        $this->bindMultiCallProvider([
            [
                'content' => null,
                'tool_calls' => [
                    ['id' => 'c1', 'name' => 'verification_complete', 'arguments' => ['passed' => true, 'summary' => 'All good.']],
                ],
                'usage' => ['input_tokens' => 10, 'output_tokens' => 10],
                'raw' => [],
            ],
        ]);

        $job = new ExecuteStageJob($stage, []);
        $job->handle();

        $stage->refresh();
        $this->assertEquals(StageStatus::Completed, $stage->status);
    }

    public function test_verify_agent_includes_diff_in_context(): void
    {
        Queue::fake();
        Event::fake([TestResultUpdated::class]);
        Process::fake([
            '*' => Process::result('diff --git a/app/profile.php'),
        ]);

        [$issue, $run, $stage] = $this->setupRunWithStage();

        $capturedMessages = null;
        $mock = new class($capturedMessages) implements AiProvider
        {
            public function __construct(private &$captured) {}

            public function chat(array $messages, array $tools = [], array $options = []): array
            {
                if ($this->captured === null) {
                    $this->captured = $messages;
                }

                return [
                    'content' => null,
                    'tool_calls' => [
                        ['id' => 'c1', 'name' => 'verification_complete', 'arguments' => ['passed' => true, 'summary' => 'Done.']],
                    ],
                    'usage' => ['input_tokens' => 10, 'output_tokens' => 10],
                    'raw' => [],
                ];
            }

            public function stream(array $messages, array $tools = [], array $options = []): \Generator
            {
                yield [];
            }
        };

        $manager = $this->createMock(AiProviderManager::class);
        $manager->method('resolve')->willReturn($mock);
        $this->app->instance(AiProviderManager::class, $manager);

        app(VerifyAgent::class)->execute($stage, []);

        $userMessage = collect($capturedMessages)->firstWhere('role', 'user')['content'];
        $this->assertStringContainsString('Current Implementation Diff', $userMessage);
        $this->assertStringContainsString('Preflight Document', $userMessage);
    }

    private static function getConstant(string $class, string $name): mixed
    {
        $reflection = new \ReflectionClass($class);
        $constant = $reflection->getReflectionConstant($name);

        return $constant->getValue();
    }
}
