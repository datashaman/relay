<?php

namespace Tests\Feature;

use App\Contracts\AiProvider;
use App\Enums\IssueStatus;
use App\Enums\RunStatus;
use App\Enums\StageName;
use App\Enums\StageStatus;
use App\Events\DiffUpdated;
use App\Jobs\ExecuteStageJob;
use App\Models\Issue;
use App\Models\Run;
use App\Models\Stage;
use App\Services\AiProviders\AiProviderManager;
use App\Services\ImplementAgent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ImplementAgentTest extends TestCase
{
    use RefreshDatabase;

    private string $worktreePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->worktreePath = sys_get_temp_dir() . '/relay-test-worktree-' . uniqid();
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
            $path = $dir . '/' . $item;
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
            'preflight_doc' => "# Preflight Doc\n\n## Summary\nAdd a user profile page.\n\n## Requirements\n- Show user name\n\n## Acceptance Criteria\n1. Profile page renders\n\n## Affected Files\n- app/profile.php\n\n## Approach\nCreate profile view.\n\n## Scope Assessment\n- **Size**: small\n- **Risk Flags**: None\n- **Suggested Autonomy**: assisted",
            'started_at' => now(),
        ]);

        $stage = Stage::factory()->create([
            'run_id' => $run->id,
            'name' => StageName::Implement,
            'status' => StageStatus::Running,
            'started_at' => now(),
        ]);

        return [$issue, $run, $stage];
    }

    private function createMultiCallProvider(array $responses): AiProvider
    {
        return new class($responses) implements AiProvider
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
    }

    private function bindMultiCallProvider(array $responses): void
    {
        $mock = $this->createMultiCallProvider($responses);
        $manager = $this->createMock(AiProviderManager::class);
        $manager->method('resolve')->willReturn($mock);
        $this->app->instance(AiProviderManager::class, $manager);
    }

    private function mockWriteAndComplete(): array
    {
        return [
            [
                'content' => null,
                'tool_calls' => [
                    [
                        'id' => 'call_1',
                        'name' => 'write_file',
                        'arguments' => ['path' => 'app/profile.php', 'content' => '<?php echo "profile";'],
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
                        'name' => 'implementation_complete',
                        'arguments' => [
                            'summary' => 'Created profile page.',
                            'files_changed' => ['app/profile.php'],
                        ],
                    ],
                ],
                'usage' => ['input_tokens' => 150, 'output_tokens' => 50],
                'raw' => [],
            ],
        ];
    }

    public function test_implement_agent_writes_file_and_completes(): void
    {
        Queue::fake();
        Event::fake([DiffUpdated::class]);
        Process::fake([
            'git diff*' => Process::result('diff output'),
            'git diff --name-only*' => Process::result("app/profile.php\n"),
            'git status*' => Process::result('M app/profile.php'),
        ]);

        [$issue, $run, $stage] = $this->setupRunWithStage();
        $this->bindMultiCallProvider($this->mockWriteAndComplete());

        app(ImplementAgent::class)->execute($stage, []);

        $stage->refresh();
        $this->assertEquals(StageStatus::Completed, $stage->status);
        $this->assertFileExists($this->worktreePath . '/app/profile.php');
        $this->assertEquals('<?php echo "profile";', file_get_contents($this->worktreePath . '/app/profile.php'));
    }

    public function test_implement_agent_records_events(): void
    {
        Queue::fake();
        Event::fake([DiffUpdated::class]);
        Process::fake([
            'git diff*' => Process::result(''),
            'git diff --name-only*' => Process::result(''),
            'git status*' => Process::result(''),
        ]);

        [$issue, $run, $stage] = $this->setupRunWithStage();
        $this->bindMultiCallProvider($this->mockWriteAndComplete());

        app(ImplementAgent::class)->execute($stage, []);

        $events = $stage->events;
        $this->assertTrue($events->contains('type', 'implement_started'));
        $this->assertTrue($events->contains('type', 'tool_call'));
        $this->assertTrue($events->contains('type', 'implement_complete'));
    }

    public function test_implement_agent_rejects_path_escape(): void
    {
        Queue::fake();
        Process::fake();

        [$issue, $run, $stage] = $this->setupRunWithStage();

        $this->bindMultiCallProvider([
            [
                'content' => null,
                'tool_calls' => [
                    [
                        'id' => 'call_1',
                        'name' => 'read_file',
                        'arguments' => ['path' => '../../../etc/passwd'],
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
                        'name' => 'implementation_complete',
                        'arguments' => ['summary' => 'Done.', 'files_changed' => []],
                    ],
                ],
                'usage' => ['input_tokens' => 100, 'output_tokens' => 50],
                'raw' => [],
            ],
        ]);

        app(ImplementAgent::class)->execute($stage, []);

        $toolEvent = $stage->events()->where('type', 'tool_call')->first();
        $this->assertNotNull($toolEvent);
        $this->assertFalse($toolEvent->payload['success']);
    }

    public function test_implement_agent_blocks_test_runner_commands(): void
    {
        Queue::fake();
        Process::fake();

        [$issue, $run, $stage] = $this->setupRunWithStage();

        $this->bindMultiCallProvider([
            [
                'content' => null,
                'tool_calls' => [
                    [
                        'id' => 'call_1',
                        'name' => 'run_shell',
                        'arguments' => ['command' => 'vendor/bin/phpunit'],
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
                        'name' => 'implementation_complete',
                        'arguments' => ['summary' => 'Done.', 'files_changed' => []],
                    ],
                ],
                'usage' => ['input_tokens' => 100, 'output_tokens' => 50],
                'raw' => [],
            ],
        ]);

        app(ImplementAgent::class)->execute($stage, []);

        $toolEvent = $stage->events()->where('type', 'tool_call')->first();
        $this->assertFalse($toolEvent->payload['success']);
    }

    public function test_implement_agent_blocks_git_push_commands(): void
    {
        Queue::fake();
        Process::fake();

        [$issue, $run, $stage] = $this->setupRunWithStage();

        $this->bindMultiCallProvider([
            [
                'content' => null,
                'tool_calls' => [
                    [
                        'id' => 'call_1',
                        'name' => 'run_shell',
                        'arguments' => ['command' => 'git push origin main'],
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
                        'name' => 'implementation_complete',
                        'arguments' => ['summary' => 'Done.', 'files_changed' => []],
                    ],
                ],
                'usage' => ['input_tokens' => 100, 'output_tokens' => 50],
                'raw' => [],
            ],
        ]);

        app(ImplementAgent::class)->execute($stage, []);

        $toolEvent = $stage->events()->where('type', 'tool_call')->first();
        $this->assertFalse($toolEvent->payload['success']);
    }

    public function test_implement_agent_fails_without_worktree(): void
    {
        Queue::fake();
        Process::fake();

        [$issue, $run, $stage] = $this->setupRunWithStage();
        $run->update(['worktree_path' => null]);

        $this->bindMultiCallProvider([]);

        app(ImplementAgent::class)->execute($stage, []);

        $stage->refresh();
        $this->assertEquals(StageStatus::Failed, $stage->status);
    }

    public function test_implement_agent_receives_preflight_doc_not_issue(): void
    {
        Queue::fake();
        Event::fake([DiffUpdated::class]);
        Process::fake([
            'git diff*' => Process::result(''),
            'git diff --name-only*' => Process::result(''),
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
                        ['id' => 'c1', 'name' => 'implementation_complete', 'arguments' => ['summary' => 'Done.', 'files_changed' => []]],
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

        app(ImplementAgent::class)->execute($stage, []);

        $userMessage = collect($capturedMessages)->firstWhere('role', 'user')['content'];
        $this->assertStringContainsString('# Preflight Document', $userMessage);
        $this->assertStringContainsString('Add a user profile page.', $userMessage);
        $this->assertStringNotContainsString($issue->body, $userMessage);
    }

    public function test_implement_agent_includes_failure_report_in_context(): void
    {
        Queue::fake();
        Event::fake([DiffUpdated::class]);
        Process::fake([
            'git diff*' => Process::result(''),
            'git diff --name-only*' => Process::result(''),
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
                        ['id' => 'c1', 'name' => 'implementation_complete', 'arguments' => ['summary' => 'Fixed.', 'files_changed' => []]],
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

        app(ImplementAgent::class)->execute($stage, [
            'failure_report' => ['Test testProfile failed: expected 200 got 404'],
        ]);

        $userMessage = collect($capturedMessages)->firstWhere('role', 'user')['content'];
        $this->assertStringContainsString('Previous Verification Failure', $userMessage);
        $this->assertStringContainsString('testProfile failed', $userMessage);
    }

    public function test_implement_agent_broadcasts_diff_on_write(): void
    {
        Queue::fake();
        Event::fake([DiffUpdated::class]);
        Process::fake([
            'git diff*' => Process::result("diff --git a/app/profile.php\n+new content"),
            'git diff --name-only*' => Process::result("app/profile.php\n"),
        ]);

        [$issue, $run, $stage] = $this->setupRunWithStage();
        $this->bindMultiCallProvider($this->mockWriteAndComplete());

        app(ImplementAgent::class)->execute($stage, []);

        Event::assertDispatched(DiffUpdated::class, function ($event) use ($stage) {
            return $event->stage->id === $stage->id;
        });
    }

    public function test_implement_agent_handles_no_tool_call_response(): void
    {
        Queue::fake();
        Process::fake();

        [$issue, $run, $stage] = $this->setupRunWithStage();

        $this->bindMultiCallProvider([
            [
                'content' => 'I have completed the implementation.',
                'tool_calls' => [],
                'usage' => ['input_tokens' => 100, 'output_tokens' => 50],
                'raw' => [],
            ],
        ]);

        app(ImplementAgent::class)->execute($stage, []);

        $stage->refresh();
        $this->assertEquals(StageStatus::Completed, $stage->status);
    }

    public function test_execute_stage_job_dispatches_implement_agent(): void
    {
        Queue::fake();
        Event::fake([DiffUpdated::class]);
        Process::fake([
            'git diff*' => Process::result(''),
            'git diff --name-only*' => Process::result(''),
        ]);

        [$issue, $run, $stage] = $this->setupRunWithStage();
        $this->bindMultiCallProvider([
            [
                'content' => null,
                'tool_calls' => [
                    ['id' => 'c1', 'name' => 'implementation_complete', 'arguments' => ['summary' => 'Done.', 'files_changed' => []]],
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

    public function test_implement_agent_read_file_tool(): void
    {
        Queue::fake();
        Event::fake([DiffUpdated::class]);
        Process::fake([
            'git diff*' => Process::result(''),
            'git diff --name-only*' => Process::result(''),
        ]);

        [$issue, $run, $stage] = $this->setupRunWithStage();

        file_put_contents($this->worktreePath . '/existing.txt', 'hello world');

        $capturedMessages = [];
        $mock = new class($capturedMessages) implements AiProvider
        {
            private int $callIndex = 0;

            public function __construct(private &$captured) {}

            public function chat(array $messages, array $tools = [], array $options = []): array
            {
                $this->captured = $messages;
                $this->callIndex++;

                if ($this->callIndex === 1) {
                    return [
                        'content' => null,
                        'tool_calls' => [
                            ['id' => 'c1', 'name' => 'read_file', 'arguments' => ['path' => 'existing.txt']],
                        ],
                        'usage' => ['input_tokens' => 10, 'output_tokens' => 10],
                        'raw' => [],
                    ];
                }

                return [
                    'content' => null,
                    'tool_calls' => [
                        ['id' => 'c2', 'name' => 'implementation_complete', 'arguments' => ['summary' => 'Done.', 'files_changed' => []]],
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

        app(ImplementAgent::class)->execute($stage, []);

        $toolResult = collect($capturedMessages)->where('role', 'tool')->first();
        $this->assertNotNull($toolResult);
        $this->assertEquals('hello world', $toolResult['content']);
    }

    public function test_implement_agent_list_files_tool(): void
    {
        Queue::fake();
        Event::fake([DiffUpdated::class]);
        Process::fake([
            'git diff*' => Process::result(''),
            'git diff --name-only*' => Process::result(''),
        ]);

        [$issue, $run, $stage] = $this->setupRunWithStage();

        mkdir($this->worktreePath . '/src', 0755, true);
        file_put_contents($this->worktreePath . '/src/index.php', '<?php');
        file_put_contents($this->worktreePath . '/src/helper.php', '<?php');

        $capturedMessages = [];
        $mock = new class($capturedMessages) implements AiProvider
        {
            private int $callIndex = 0;

            public function __construct(private &$captured) {}

            public function chat(array $messages, array $tools = [], array $options = []): array
            {
                $this->captured = $messages;
                $this->callIndex++;

                if ($this->callIndex === 1) {
                    return [
                        'content' => null,
                        'tool_calls' => [
                            ['id' => 'c1', 'name' => 'list_files', 'arguments' => ['path' => 'src']],
                        ],
                        'usage' => ['input_tokens' => 10, 'output_tokens' => 10],
                        'raw' => [],
                    ];
                }

                return [
                    'content' => null,
                    'tool_calls' => [
                        ['id' => 'c2', 'name' => 'implementation_complete', 'arguments' => ['summary' => 'Done.', 'files_changed' => []]],
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

        app(ImplementAgent::class)->execute($stage, []);

        $toolResult = collect($capturedMessages)->where('role', 'tool')->first();
        $this->assertNotNull($toolResult);
        $this->assertStringContainsString('index.php', $toolResult['content']);
        $this->assertStringContainsString('helper.php', $toolResult['content']);
    }

    public function test_implement_agent_shell_command_executes_in_worktree(): void
    {
        Queue::fake();
        Event::fake([DiffUpdated::class]);

        Process::fake([
            '*' => Process::result('command output'),
        ]);

        [$issue, $run, $stage] = $this->setupRunWithStage();

        $this->bindMultiCallProvider([
            [
                'content' => null,
                'tool_calls' => [
                    ['id' => 'c1', 'name' => 'run_shell', 'arguments' => ['command' => 'ls -la']],
                ],
                'usage' => ['input_tokens' => 10, 'output_tokens' => 10],
                'raw' => [],
            ],
            [
                'content' => null,
                'tool_calls' => [
                    ['id' => 'c2', 'name' => 'implementation_complete', 'arguments' => ['summary' => 'Done.', 'files_changed' => []]],
                ],
                'usage' => ['input_tokens' => 10, 'output_tokens' => 10],
                'raw' => [],
            ],
        ]);

        app(ImplementAgent::class)->execute($stage, []);

        Process::assertRan(function ($process) {
            $command = $process->command;
            if (is_array($command)) {
                $command = implode(' ', $command);
            }

            return str_contains($command, 'ls -la');
        });
    }

    public function test_implement_agent_blocks_pest_and_jest(): void
    {
        Queue::fake();
        Process::fake();

        [$issue, $run, $stage] = $this->setupRunWithStage();

        $this->bindMultiCallProvider([
            [
                'content' => null,
                'tool_calls' => [
                    ['id' => 'c1', 'name' => 'run_shell', 'arguments' => ['command' => 'vendor/bin/pest']],
                ],
                'usage' => ['input_tokens' => 10, 'output_tokens' => 10],
                'raw' => [],
            ],
            [
                'content' => null,
                'tool_calls' => [
                    ['id' => 'c2', 'name' => 'implementation_complete', 'arguments' => ['summary' => 'Done.', 'files_changed' => []]],
                ],
                'usage' => ['input_tokens' => 10, 'output_tokens' => 10],
                'raw' => [],
            ],
        ]);

        app(ImplementAgent::class)->execute($stage, []);

        $toolEvent = $stage->events()->where('type', 'tool_call')->first();
        $this->assertFalse($toolEvent->payload['success']);
    }
}
