<?php

namespace Tests\Feature;

use App\Contracts\AiProvider;
use App\Enums\IssueStatus;
use App\Enums\RunStatus;
use App\Enums\SourceType;
use App\Enums\StageName;
use App\Enums\StageStatus;
use App\Enums\StuckState;
use App\Models\Issue;
use App\Models\OauthToken;
use App\Models\Repository;
use App\Models\Run;
use App\Models\Source;
use App\Models\Stage;
use App\Services\AiProviders\AiProviderManager;
use App\Services\PreflightAgent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PreflightCommentChannelTest extends TestCase
{
    use RefreshDatabase;

    private function createGitHubSource(string $channel = 'on_issue'): Source
    {
        return Source::factory()->create([
            'type' => SourceType::GitHub,
            'external_account' => 'octocat',
            'bot_login' => 'relay-bot',
            'config' => [
                'repositories' => ['octocat/repo'],
                'preflight' => ['clarification_channel' => $channel],
            ],
        ]);
    }

    private function setupRunWithStage(Source $source, string $externalId = 'octocat/repo#1'): array
    {
        $repository = Repository::factory()->create(['name' => 'octocat/repo']);
        $issue = Issue::factory()->create([
            'source_id' => $source->id,
            'repository_id' => $repository->id,
            'external_id' => $externalId,
            'title' => 'Add login page',
            'body' => 'Users should be able to log in.',
            'external_url' => 'https://github.com/octocat/repo/issues/1',
            'status' => IssueStatus::InProgress,
            'labels' => [],
            'assignee' => null,
        ]);
        $run = Run::factory()->create([
            'issue_id' => $issue->id,
            'repository_id' => $repository->id,
            'status' => RunStatus::Running,
            'started_at' => now(),
        ]);
        $stage = Stage::factory()->create([
            'run_id' => $run->id,
            'name' => StageName::Preflight,
            'status' => StageStatus::Running,
            'started_at' => now(),
        ]);

        OauthToken::factory()->create([
            'source_id' => $source->id,
            'provider' => 'github',
            'access_token' => 'gho_test',
            'expires_at' => now()->addHour(),
        ]);

        return [$issue, $run, $stage];
    }

    private function bindAmbiguousProvider(?array $questions = null): void
    {
        $questions ??= [
            ['id' => 'q1', 'text' => 'Which auth provider?', 'type' => 'choice', 'options' => ['OAuth', 'Local']],
            ['id' => 'q2', 'text' => 'Any layout constraints?', 'type' => 'text'],
        ];

        $response = [
            'content' => null,
            'tool_calls' => [[
                'id' => 'call_1',
                'name' => 'assess_issue',
                'arguments' => [
                    'ready' => false,
                    'known_facts' => ['Login page requested'],
                    'questions' => $questions,
                ],
            ]],
            'usage' => ['input_tokens' => 50, 'output_tokens' => 50],
            'raw' => [],
        ];

        $mock = new class($response) implements AiProvider
        {
            public function __construct(private array $response) {}

            public function chat(array $messages, array $tools = [], array $options = []): array
            {
                return $this->response;
            }

            public function stream(array $messages, array $tools = [], array $options = []): \Generator
            {
                yield $this->response;
            }
        };

        $manager = $this->createMock(AiProviderManager::class);
        $manager->method('resolve')->willReturn($mock);
        $this->app->instance(AiProviderManager::class, $manager);
    }

    public function test_ambiguous_assessment_on_issue_source_posts_comment(): void
    {
        Queue::fake();
        $source = $this->createGitHubSource('on_issue');
        [$issue, $run, $stage] = $this->setupRunWithStage($source);

        Http::fake([
            'api.github.com/repos/octocat/repo/issues/1/comments' => Http::response([
                'id' => 12345,
                'html_url' => 'https://github.com/octocat/repo/issues/1#issuecomment-12345',
            ], 201),
        ]);

        $this->bindAmbiguousProvider();

        app(PreflightAgent::class)->execute($stage, []);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return $request->method() === 'POST'
                && str_contains((string) $request->url(), '/repos/octocat/repo/issues/1/comments')
                && str_contains($body['body'] ?? '', 'Which auth provider?');
        });

        $stage->refresh();
        $this->assertEquals(StageStatus::AwaitingApproval, $stage->status);
        $run->refresh();
        $this->assertEquals('on_issue', $run->clarification_channel);
    }

    public function test_ambiguous_assessment_on_in_app_source_does_not_post_comment(): void
    {
        Queue::fake();
        $source = $this->createGitHubSource('in_app');
        [$issue, $run, $stage] = $this->setupRunWithStage($source);

        Http::fake();
        $this->bindAmbiguousProvider();

        app(PreflightAgent::class)->execute($stage, []);

        Http::assertNothingSent();

        $run->refresh();
        $this->assertEquals('in_app', $run->clarification_channel);
    }

    public function test_repeated_execution_for_same_round_does_not_double_post(): void
    {
        Queue::fake();
        $source = $this->createGitHubSource('on_issue');
        [$issue, $run, $stage] = $this->setupRunWithStage($source);

        Http::fake([
            'api.github.com/repos/octocat/repo/issues/1/comments' => Http::response(['id' => 1], 201),
        ]);

        $this->bindAmbiguousProvider();

        app(PreflightAgent::class)->execute($stage, []);

        // Re-run on the same stage at the same round (no answers submitted).
        $stage->refresh();
        $stage->update(['status' => StageStatus::Running]);
        $run->refresh();
        $run->update([
            'clarification_questions' => $run->clarification_questions,
            'clarification_answers' => null,
        ]);

        app(PreflightAgent::class)->execute($stage, []);

        Http::assertSentCount(1);
    }

    public function test_run_pinned_to_in_app_even_if_source_toggles_mid_flight(): void
    {
        Queue::fake();
        $source = $this->createGitHubSource('in_app');
        [$issue, $run, $stage] = $this->setupRunWithStage($source);

        Http::fake();
        $this->bindAmbiguousProvider();

        app(PreflightAgent::class)->execute($stage, []);
        $run->refresh();
        $this->assertEquals('in_app', $run->clarification_channel);

        // Toggle the source mid-flight.
        $config = $source->config;
        $config['preflight']['clarification_channel'] = 'on_issue';
        $source->update(['config' => $config]);

        // Simulate a follow-up round resume.
        $stage->refresh();
        $stage->update(['status' => StageStatus::Running]);
        $run->update(['clarification_answers' => ['q1' => 'OAuth']]);

        $this->bindAmbiguousProvider([
            ['id' => 'q3', 'text' => 'Persistence layer?', 'type' => 'text'],
        ]);

        app(PreflightAgent::class)->execute($stage, []);

        // The run is still in_app — no GitHub call.
        Http::assertNothingSent();

        $run->refresh();
        $this->assertEquals('in_app', $run->clarification_channel);
    }

    public function test_round_cap_on_issue_posts_no_consensus_comment(): void
    {
        Queue::fake();
        $source = $this->createGitHubSource('on_issue');
        [$issue, $run, $stage] = $this->setupRunWithStage($source);

        config()->set('relay.preflight.max_clarification_rounds', 2);

        // Pretend we've already done two rounds; channel snapshotted.
        $run->update([
            'preflight_round' => 2,
            'clarification_channel' => 'on_issue',
            'clarification_questions' => [
                ['id' => 'q1', 'text' => 'Which dashboard?', 'type' => 'text'],
            ],
            'clarification_answers' => ['q1' => 'still vague'],
        ]);

        Http::fake([
            'api.github.com/repos/octocat/repo/issues/1/comments' => Http::response(['id' => 99], 201),
        ]);

        // No assessment will be invoked — round cap aborts first.
        $this->bindAmbiguousProvider();

        app(PreflightAgent::class)->execute($stage, []);

        $run->refresh();
        $this->assertEquals(RunStatus::Stuck, $run->status);
        $this->assertEquals(StuckState::PreflightNoConsensus, $run->stuck_state);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return $request->method() === 'POST'
                && str_contains((string) $request->url(), '/repos/octocat/repo/issues/1/comments')
                && str_contains($body['body'] ?? '', 'no consensus');
        });
    }
}
