<?php

namespace Tests\Feature;

use App\Contracts\AiProvider;
use App\Enums\IssueStatus;
use App\Enums\RunStatus;
use App\Enums\StageName;
use App\Enums\StageStatus;
use App\Events\ReleaseProgressUpdated;
use App\Jobs\ExecuteStageJob;
use App\Models\Issue;
use App\Models\OauthToken;
use App\Models\Repository;
use App\Models\Run;
use App\Models\Source;
use App\Models\Stage;
use App\Services\AiProviders\AiProviderManager;
use App\Services\ReleaseAgent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ReleaseAgentTest extends TestCase
{
    use RefreshDatabase;

    private string $worktreePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->worktreePath = sys_get_temp_dir() . '/relay-test-release-' . uniqid();
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
        $repository = Repository::factory()->create([
            'name' => 'testowner/testrepo',
            'default_branch' => 'main',
        ]);

        $source = Source::factory()->create();
        $token = OauthToken::factory()->create(['source_id' => $source->id]);

        $issue = Issue::factory()->create([
            'source_id' => $source->id,
            'repository_id' => $repository->id,
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
            'name' => StageName::Release,
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

    private function mockCommitPushPrComplete(): array
    {
        return [
            [
                'content' => null,
                'tool_calls' => [
                    [
                        'id' => 'call_1',
                        'name' => 'git_commit',
                        'arguments' => ['message' => 'feat: add user profile page'],
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
                        'name' => 'git_push',
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
                        'id' => 'call_3',
                        'name' => 'create_pr',
                        'arguments' => [
                            'title' => 'Add user profile page',
                            'body' => '## Summary\nAdds user profile page.\n\n## Acceptance Criteria\n- Profile page renders\n- Tests pass',
                        ],
                    ],
                ],
                'usage' => ['input_tokens' => 100, 'output_tokens' => 50],
                'raw' => [],
            ],
            [
                'content' => null,
                'tool_calls' => [
                    [
                        'id' => 'call_4',
                        'name' => 'release_complete',
                        'arguments' => [
                            'pr_url' => 'https://github.com/testowner/testrepo/pull/42',
                            'summary' => 'Released user profile page feature.',
                        ],
                    ],
                ],
                'usage' => ['input_tokens' => 100, 'output_tokens' => 50],
                'raw' => [],
            ],
        ];
    }

    public function test_release_agent_commits_pushes_creates_pr_and_completes(): void
    {
        Queue::fake();
        Event::fake([ReleaseProgressUpdated::class]);
        Process::fake([
            '*' => Process::result('Success'),
        ]);
        Http::fake([
            'api.github.com/*' => Http::response([
                'html_url' => 'https://github.com/testowner/testrepo/pull/42',
                'number' => 42,
            ]),
        ]);

        [$issue, $run, $stage] = $this->setupRunWithStage();
        $this->bindMultiCallProvider($this->mockCommitPushPrComplete());

        app(ReleaseAgent::class)->execute($stage, []);

        $stage->refresh();
        $this->assertEquals(StageStatus::Completed, $stage->status);
    }

    public function test_release_agent_records_events(): void
    {
        Queue::fake();
        Event::fake([ReleaseProgressUpdated::class]);
        Process::fake([
            '*' => Process::result('Success'),
        ]);
        Http::fake([
            'api.github.com/*' => Http::response([
                'html_url' => 'https://github.com/testowner/testrepo/pull/42',
                'number' => 42,
            ]),
        ]);

        [$issue, $run, $stage] = $this->setupRunWithStage();
        $this->bindMultiCallProvider($this->mockCommitPushPrComplete());

        app(ReleaseAgent::class)->execute($stage, []);

        $events = $stage->events;
        $this->assertTrue($events->contains('type', 'release_started'));
        $this->assertTrue($events->contains('type', 'tool_call'));
        $this->assertTrue($events->contains('type', 'pr_created'));
        $this->assertTrue($events->contains('type', 'release_complete'));
    }

    public function test_release_agent_broadcasts_progress(): void
    {
        Queue::fake();
        Event::fake([ReleaseProgressUpdated::class]);
        Process::fake([
            '*' => Process::result('Success'),
        ]);
        Http::fake([
            'api.github.com/*' => Http::response([
                'html_url' => 'https://github.com/testowner/testrepo/pull/42',
                'number' => 42,
            ]),
        ]);

        [$issue, $run, $stage] = $this->setupRunWithStage();
        $this->bindMultiCallProvider($this->mockCommitPushPrComplete());

        app(ReleaseAgent::class)->execute($stage, []);

        Event::assertDispatched(ReleaseProgressUpdated::class, function ($event) use ($stage) {
            return $event->stage->id === $stage->id;
        });
    }

    public function test_release_agent_fails_without_worktree(): void
    {
        Queue::fake();
        Process::fake();

        [$issue, $run, $stage] = $this->setupRunWithStage();
        $run->update(['worktree_path' => null]);

        $this->bindMultiCallProvider([]);

        app(ReleaseAgent::class)->execute($stage, []);

        $stage->refresh();
        $this->assertEquals(StageStatus::Failed, $stage->status);
    }

    public function test_release_agent_cannot_modify_source_code(): void
    {
        $tools = collect(self::getConstant(ReleaseAgent::class, 'TOOLS'))->pluck('name')->toArray();
        $this->assertNotContains('write_file', $tools);
        $this->assertNotContains('edit_file', $tools);
    }

    public function test_release_agent_blocks_write_commands(): void
    {
        Queue::fake();
        Event::fake([ReleaseProgressUpdated::class]);
        Process::fake();

        [$issue, $run, $stage] = $this->setupRunWithStage();

        $this->bindMultiCallProvider([
            [
                'content' => null,
                'tool_calls' => [
                    ['id' => 'call_1', 'name' => 'run_shell', 'arguments' => ['command' => 'sed -i "s/old/new/" file.php']],
                ],
                'usage' => ['input_tokens' => 10, 'output_tokens' => 10],
                'raw' => [],
            ],
            [
                'content' => null,
                'tool_calls' => [
                    ['id' => 'call_2', 'name' => 'release_complete', 'arguments' => ['summary' => 'Done.']],
                ],
                'usage' => ['input_tokens' => 10, 'output_tokens' => 10],
                'raw' => [],
            ],
        ]);

        app(ReleaseAgent::class)->execute($stage, []);

        $toolEvent = $stage->events()->where('type', 'tool_call')->first();
        $this->assertFalse($toolEvent->payload['success']);
    }

    public function test_release_agent_writes_changelog(): void
    {
        Queue::fake();
        Event::fake([ReleaseProgressUpdated::class]);
        Process::fake([
            '*' => Process::result(''),
        ]);

        file_put_contents($this->worktreePath . '/CHANGELOG.md', "# Changelog\n\n## Previous\n- Old entry\n");

        [$issue, $run, $stage] = $this->setupRunWithStage();

        $this->bindMultiCallProvider([
            [
                'content' => null,
                'tool_calls' => [
                    [
                        'id' => 'call_1',
                        'name' => 'write_changelog',
                        'arguments' => ['entry' => "## 2026-04-12 - Add user profile page\n- Added profile page with user info display"],
                    ],
                ],
                'usage' => ['input_tokens' => 10, 'output_tokens' => 10],
                'raw' => [],
            ],
            [
                'content' => null,
                'tool_calls' => [
                    ['id' => 'call_2', 'name' => 'release_complete', 'arguments' => ['summary' => 'Done.']],
                ],
                'usage' => ['input_tokens' => 10, 'output_tokens' => 10],
                'raw' => [],
            ],
        ]);

        app(ReleaseAgent::class)->execute($stage, []);

        $content = file_get_contents($this->worktreePath . '/CHANGELOG.md');
        $this->assertStringContainsString('Add user profile page', $content);
        $this->assertStringContainsString('Old entry', $content);
    }

    public function test_release_agent_trigger_deploy_without_hook(): void
    {
        Queue::fake();
        Event::fake([ReleaseProgressUpdated::class]);
        Process::fake();

        [$issue, $run, $stage] = $this->setupRunWithStage();

        $this->bindMultiCallProvider([
            [
                'content' => null,
                'tool_calls' => [
                    ['id' => 'call_1', 'name' => 'trigger_deploy', 'arguments' => []],
                ],
                'usage' => ['input_tokens' => 10, 'output_tokens' => 10],
                'raw' => [],
            ],
            [
                'content' => null,
                'tool_calls' => [
                    ['id' => 'call_2', 'name' => 'release_complete', 'arguments' => ['summary' => 'Done.']],
                ],
                'usage' => ['input_tokens' => 10, 'output_tokens' => 10],
                'raw' => [],
            ],
        ]);

        app(ReleaseAgent::class)->execute($stage, []);

        $toolEvent = $stage->events()->where('type', 'tool_call')->first();
        $this->assertTrue($toolEvent->payload['success']);
    }

    public function test_release_agent_rejects_path_escape(): void
    {
        Queue::fake();
        Event::fake([ReleaseProgressUpdated::class]);
        Process::fake();

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
                    ['id' => 'call_2', 'name' => 'release_complete', 'arguments' => ['summary' => 'Done.']],
                ],
                'usage' => ['input_tokens' => 10, 'output_tokens' => 10],
                'raw' => [],
            ],
        ]);

        app(ReleaseAgent::class)->execute($stage, []);

        $toolEvent = $stage->events()->where('type', 'tool_call')->first();
        $this->assertFalse($toolEvent->payload['success']);
    }

    public function test_execute_stage_job_dispatches_release_agent(): void
    {
        Queue::fake();
        Event::fake([ReleaseProgressUpdated::class]);
        Process::fake([
            '*' => Process::result(''),
        ]);

        [$issue, $run, $stage] = $this->setupRunWithStage();

        $this->bindMultiCallProvider([
            [
                'content' => null,
                'tool_calls' => [
                    ['id' => 'c1', 'name' => 'release_complete', 'arguments' => ['summary' => 'All done.']],
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

    public function test_release_agent_includes_context_in_messages(): void
    {
        Queue::fake();
        Event::fake([ReleaseProgressUpdated::class]);
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
                        ['id' => 'c1', 'name' => 'release_complete', 'arguments' => ['summary' => 'Done.']],
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

        app(ReleaseAgent::class)->execute($stage, []);

        $userMessage = collect($capturedMessages)->firstWhere('role', 'user')['content'];
        $this->assertStringContainsString('Preflight Document', $userMessage);
        $this->assertStringContainsString('Branch:', $userMessage);
        $this->assertStringContainsString('relay/run-1', $userMessage);
    }

    public function test_release_agent_git_commit_requires_message(): void
    {
        Queue::fake();
        Event::fake([ReleaseProgressUpdated::class]);
        Process::fake();

        [$issue, $run, $stage] = $this->setupRunWithStage();

        $this->bindMultiCallProvider([
            [
                'content' => null,
                'tool_calls' => [
                    ['id' => 'call_1', 'name' => 'git_commit', 'arguments' => ['message' => '']],
                ],
                'usage' => ['input_tokens' => 10, 'output_tokens' => 10],
                'raw' => [],
            ],
            [
                'content' => null,
                'tool_calls' => [
                    ['id' => 'call_2', 'name' => 'release_complete', 'arguments' => ['summary' => 'Done.']],
                ],
                'usage' => ['input_tokens' => 10, 'output_tokens' => 10],
                'raw' => [],
            ],
        ]);

        app(ReleaseAgent::class)->execute($stage, []);

        $toolEvent = $stage->events()->where('type', 'tool_call')->first();
        $this->assertFalse($toolEvent->payload['success']);
    }

    private static function getConstant(string $class, string $name): mixed
    {
        $reflection = new \ReflectionClass($class);
        $constant = $reflection->getReflectionConstant($name);

        return $constant->getValue();
    }
}
