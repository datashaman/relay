<?php

namespace Tests\Feature;

use App\Contracts\AiProvider;
use App\Enums\AutonomyLevel;
use App\Enums\AutonomyScope;
use App\Enums\IssueStatus;
use App\Enums\StageName;
use App\Models\AutonomyConfig;
use App\Models\Issue;
use App\Services\AiProviders\AiProviderManager;
use App\Services\OrchestratorService;
use App\Services\PreflightAgent;
use App\Support\Logging\PipelineLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Logger;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Monolog\Handler\TestHandler;
use Monolog\Logger as MonologLogger;
use Tests\TestCase;

class PipelineObservabilityTest extends TestCase
{
    use RefreshDatabase;

    private TestHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->handler = new TestHandler;
        $handler = $this->handler;

        Log::extend('pipeline-test', function () use ($handler) {
            return new Logger(new MonologLogger('pipeline', [$handler]));
        });

        config(['logging.channels.pipeline.driver' => 'pipeline-test']);
        Log::forgetChannel('pipeline');
    }

    private function bindMultiCallProvider(array $responses): void
    {
        $provider = new class($responses) implements AiProvider
        {
            private int $idx = 0;

            public function __construct(private array $responses) {}

            public function chat(array $messages, array $tools = [], array $options = []): array
            {
                $logContext = is_array($options['log_context'] ?? null) ? $options['log_context'] : [];

                PipelineLogger::aiCall(
                    'fake',
                    'fake-model',
                    ['input_tokens' => 10, 'output_tokens' => 4],
                    array_merge($logContext, ['duration_ms' => 1]),
                );

                return $this->responses[$this->idx++] ?? end($this->responses);
            }

            public function stream(array $messages, array $tools = [], array $options = []): \Generator
            {
                yield [];
            }
        };

        $manager = $this->createMock(AiProviderManager::class);
        $manager->method('resolve')->willReturn($provider);
        $this->app->instance(AiProviderManager::class, $manager);
    }

    private function assessResponse(): array
    {
        return [
            'content' => null,
            'tool_calls' => [
                [
                    'id' => 'call_assess',
                    'name' => 'assess_issue',
                    'arguments' => [
                        'confidence' => 'clear',
                        'known_facts' => ['User needs a login page'],
                    ],
                ],
            ],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 4],
            'raw' => [],
        ];
    }

    private function docResponse(): array
    {
        return [
            'content' => null,
            'tool_calls' => [
                [
                    'id' => 'call_doc',
                    'name' => 'generate_preflight_doc',
                    'arguments' => [
                        'summary' => 'Add a login page.',
                        'requirements' => ['Email + password login'],
                        'acceptance_criteria' => ['Users can log in'],
                        'affected_files' => ['app/Http/Controllers/LoginController.php'],
                        'approach' => 'Create a login controller and view.',
                        'scope_assessment' => ['size' => 'small', 'risk_flags' => [], 'suggested_autonomy' => 'supervised'],
                    ],
                ],
            ],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 4],
            'raw' => [],
        ];
    }

    public function test_happy_path_emits_stage_started_stage_completed_and_ai_call(): void
    {
        Queue::fake();

        AutonomyConfig::create([
            'scope' => AutonomyScope::Global,
            'scope_id' => null,
            'stage' => null,
            'level' => AutonomyLevel::Autonomous,
        ]);

        $this->bindMultiCallProvider([
            $this->assessResponse(),
            $this->docResponse(),
        ]);

        $issue = Issue::factory()->create(['status' => IssueStatus::Accepted]);

        /** @var OrchestratorService $orchestrator */
        $orchestrator = app(OrchestratorService::class);

        $run = $orchestrator->startRun($issue);
        $preflight = $run->stages()->where('name', StageName::Preflight)->firstOrFail();

        app(PreflightAgent::class)->execute($preflight, []);
        $orchestrator->complete($preflight->fresh());

        $events = array_map(
            fn ($record) => $record['context']['event'] ?? null,
            $this->handler->getRecords(),
        );

        $this->assertContains('run_started', $events, 'expected run_started to be logged');
        $this->assertContains('stage_started', $events, 'expected stage_started to be logged');
        $this->assertContains('stage_completed', $events, 'expected stage_completed to be logged');
        $this->assertContains('ai_call', $events, 'expected at least one ai_call to be logged');
    }

    public function test_ai_call_entry_carries_run_and_stage_log_context(): void
    {
        Queue::fake();

        AutonomyConfig::create([
            'scope' => AutonomyScope::Global,
            'scope_id' => null,
            'stage' => null,
            'level' => AutonomyLevel::Autonomous,
        ]);

        $this->bindMultiCallProvider([
            $this->assessResponse(),
            $this->docResponse(),
        ]);

        $issue = Issue::factory()->create(['status' => IssueStatus::Accepted]);
        $orchestrator = app(OrchestratorService::class);
        $run = $orchestrator->startRun($issue);
        $preflight = $run->stages()->where('name', StageName::Preflight)->firstOrFail();

        app(PreflightAgent::class)->execute($preflight, []);

        $aiCalls = array_filter(
            $this->handler->getRecords(),
            fn ($record) => ($record['context']['event'] ?? null) === 'ai_call',
        );

        $this->assertNotEmpty($aiCalls, 'expected ai_call entries');
        $first = array_values($aiCalls)[0];
        $this->assertSame('fake', $first['context']['provider']);
        $this->assertSame('fake-model', $first['context']['model']);
        $this->assertSame(10, $first['context']['tokens_prompt']);
        $this->assertSame(4, $first['context']['tokens_completion']);
        $this->assertSame($run->id, $first['context']['run_id']);
        $this->assertSame($issue->id, $first['context']['issue_id']);
        $this->assertSame('preflight', $first['context']['stage']);
    }

    public function test_stage_completed_entry_includes_duration_ms(): void
    {
        Queue::fake();

        AutonomyConfig::create([
            'scope' => AutonomyScope::Global,
            'scope_id' => null,
            'stage' => null,
            'level' => AutonomyLevel::Autonomous,
        ]);

        $this->bindMultiCallProvider([
            $this->assessResponse(),
            $this->docResponse(),
        ]);

        $issue = Issue::factory()->create(['status' => IssueStatus::Accepted]);
        $orchestrator = app(OrchestratorService::class);
        $run = $orchestrator->startRun($issue);
        $preflight = $run->stages()->where('name', StageName::Preflight)->firstOrFail();

        app(PreflightAgent::class)->execute($preflight, []);
        $orchestrator->complete($preflight->fresh());

        $completed = array_values(array_filter(
            $this->handler->getRecords(),
            fn ($record) => ($record['context']['event'] ?? null) === 'stage_completed',
        ));

        $this->assertNotEmpty($completed);
        $this->assertSame('preflight', $completed[0]['context']['stage']);
        $this->assertArrayHasKey('duration_ms', $completed[0]['context']);
        $this->assertIsInt($completed[0]['context']['duration_ms']);
    }
}
