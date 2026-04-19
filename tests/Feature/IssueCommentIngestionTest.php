<?php

namespace Tests\Feature;

use App\Enums\IssueStatus;
use App\Enums\RunStatus;
use App\Enums\SourceType;
use App\Enums\StageName;
use App\Enums\StageStatus;
use App\Jobs\ProcessGitHubIssueCommentJob;
use App\Jobs\ProcessJiraIssueCommentJob;
use App\Jobs\ResumePreflightFromCommentJob;
use App\Models\Issue;
use App\Models\Repository;
use App\Models\Run;
use App\Models\Source;
use App\Models\Stage;
use App\Models\WebhookDelivery;
use App\Services\OrchestratorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class IssueCommentIngestionTest extends TestCase
{
    use RefreshDatabase;

    private function createGitHubSource(string $channel = 'on_issue', ?string $botLogin = 'relay-bot'): Source
    {
        return Source::factory()->create([
            'type' => SourceType::GitHub,
            'external_account' => 'octocat',
            'bot_login' => $botLogin,
            'is_active' => true,
            'config' => [
                'repositories' => ['octocat/repo'],
                'preflight' => ['clarification_channel' => $channel],
            ],
        ]);
    }

    private function setupAwaitingPreflight(Source $source, string $externalId = 'octocat/repo#1'): array
    {
        $repository = Repository::factory()->create(['name' => 'octocat/repo']);
        $issue = Issue::factory()->create([
            'source_id' => $source->id,
            'repository_id' => $repository->id,
            'external_id' => $externalId,
            'status' => IssueStatus::InProgress,
        ]);
        $run = Run::factory()->create([
            'issue_id' => $issue->id,
            'repository_id' => $repository->id,
            'status' => RunStatus::Running,
            'clarification_channel' => 'on_issue',
            'clarification_questions' => [
                ['id' => 'q1', 'text' => 'Which?', 'type' => 'text'],
            ],
            'started_at' => now(),
        ]);
        $stage = Stage::factory()->create([
            'run_id' => $run->id,
            'name' => StageName::Preflight,
            'status' => StageStatus::AwaitingApproval,
            'started_at' => now(),
        ]);

        return [$issue, $run, $stage];
    }

    private function postGitHubComment(Source $source, array $payload): TestResponse
    {
        $body = json_encode($payload);
        $signature = 'sha256='.hash_hmac('sha256', $body, $source->webhook_secret);

        return $this->call('POST', route('webhooks.github', $source), [], [], [], [
            'HTTP_X_GITHUB_EVENT' => 'issue_comment',
            'HTTP_X_HUB_SIGNATURE_256' => $signature,
            'HTTP_X_GITHUB_DELIVERY' => 'delivery-'.uniqid(),
            'CONTENT_TYPE' => 'application/json',
        ], $body);
    }

    private function commentPayload(string $body, string $authorLogin, int $issueNumber = 1): array
    {
        return [
            'action' => 'created',
            'repository' => ['full_name' => 'octocat/repo'],
            'issue' => ['number' => $issueNumber],
            'comment' => [
                'id' => 12345,
                'body' => $body,
                'user' => ['login' => $authorLogin],
            ],
        ];
    }

    public function test_github_issue_comment_from_user_dispatches_resume_job(): void
    {
        Queue::fake();
        $source = $this->createGitHubSource('on_issue');
        [$issue, $run, $stage] = $this->setupAwaitingPreflight($source);

        $response = $this->postGitHubComment($source, $this->commentPayload('We mean the admin dashboard.', 'real-user'));

        $response->assertOk();
        Queue::assertPushed(ProcessGitHubIssueCommentJob::class);
    }

    public function test_github_issue_comment_processes_and_dispatches_resume(): void
    {
        Queue::fake([ResumePreflightFromCommentJob::class]);
        $source = $this->createGitHubSource('on_issue');
        [$issue, $run, $stage] = $this->setupAwaitingPreflight($source);

        $delivery = WebhookDelivery::create([
            'source_id' => $source->id,
            'external_delivery_id' => 'd1',
            'event_type' => 'issue_comment',
            'action' => 'created',
            'payload' => $this->commentPayload('User answer.', 'real-user'),
        ]);

        (new ProcessGitHubIssueCommentJob($delivery))->handle();

        Queue::assertPushed(ResumePreflightFromCommentJob::class, function ($job) use ($run) {
            return $job->run->id === $run->id && $job->commentBody === 'User answer.';
        });
    }

    public function test_github_issue_comment_from_bot_is_dropped(): void
    {
        Queue::fake([ResumePreflightFromCommentJob::class]);
        $source = $this->createGitHubSource('on_issue', botLogin: 'relay-bot');
        [$issue, $run, $stage] = $this->setupAwaitingPreflight($source);

        $delivery = WebhookDelivery::create([
            'source_id' => $source->id,
            'external_delivery_id' => 'd2',
            'event_type' => 'issue_comment',
            'action' => 'created',
            'payload' => $this->commentPayload('Self comment.', 'relay-bot'),
        ]);

        (new ProcessGitHubIssueCommentJob($delivery))->handle();

        Queue::assertNothingPushed();
        $this->assertNotNull($delivery->fresh()->processed_at);
        $this->assertStringContainsString('bot', $delivery->fresh()->error);
    }

    public function test_github_issue_comment_for_in_app_source_is_dropped_at_controller(): void
    {
        Queue::fake();
        $source = $this->createGitHubSource('in_app');
        [$issue, $run, $stage] = $this->setupAwaitingPreflight($source);

        $response = $this->postGitHubComment($source, $this->commentPayload('hello.', 'real-user'));

        $response->assertOk();
        Queue::assertNotPushed(ProcessGitHubIssueCommentJob::class);
    }

    public function test_github_comment_with_no_active_run_is_dropped(): void
    {
        Queue::fake([ResumePreflightFromCommentJob::class]);
        $source = $this->createGitHubSource('on_issue');

        $delivery = WebhookDelivery::create([
            'source_id' => $source->id,
            'external_delivery_id' => 'd3',
            'event_type' => 'issue_comment',
            'action' => 'created',
            'payload' => $this->commentPayload('orphan.', 'someone'),
        ]);

        (new ProcessGitHubIssueCommentJob($delivery))->handle();

        Queue::assertNothingPushed();
        $this->assertStringContainsString('issue not tracked', $delivery->fresh()->error);
    }

    public function test_jira_comment_created_dispatches_resume(): void
    {
        Queue::fake([ResumePreflightFromCommentJob::class]);
        $source = Source::factory()->create([
            'type' => SourceType::Jira,
            'external_account' => 'acme',
            'bot_account_id' => 'aaid:bot',
            'config' => ['cloud_id' => 'cloud-1', 'preflight' => ['clarification_channel' => 'on_issue']],
        ]);

        $issue = Issue::factory()->create([
            'source_id' => $source->id,
            'external_id' => 'ACME-1',
        ]);
        $run = Run::factory()->create([
            'issue_id' => $issue->id,
            'status' => RunStatus::Running,
            'clarification_channel' => 'on_issue',
            'clarification_questions' => [['id' => 'q1', 'text' => 'Which?', 'type' => 'text']],
            'started_at' => now(),
        ]);
        Stage::factory()->create([
            'run_id' => $run->id,
            'name' => StageName::Preflight,
            'status' => StageStatus::AwaitingApproval,
        ]);

        $delivery = WebhookDelivery::create([
            'source_id' => $source->id,
            'external_delivery_id' => 'jc1',
            'event_type' => 'comment_created',
            'payload' => [
                'webhookEvent' => 'comment_created',
                'issue' => ['key' => 'ACME-1'],
                'comment' => [
                    'body' => 'A user reply.',
                    'author' => ['accountId' => 'aaid:user'],
                ],
            ],
        ]);

        (new ProcessJiraIssueCommentJob($delivery))->handle();

        Queue::assertPushed(ResumePreflightFromCommentJob::class, function ($job) use ($run) {
            return $job->run->id === $run->id && $job->commentBody === 'A user reply.';
        });
    }

    public function test_jira_comment_from_bot_is_dropped(): void
    {
        Queue::fake([ResumePreflightFromCommentJob::class]);
        $source = Source::factory()->create([
            'type' => SourceType::Jira,
            'external_account' => 'acme',
            'bot_account_id' => 'aaid:bot',
            'config' => ['cloud_id' => 'cloud-1', 'preflight' => ['clarification_channel' => 'on_issue']],
        ]);
        $issue = Issue::factory()->create([
            'source_id' => $source->id,
            'external_id' => 'ACME-2',
        ]);
        $run = Run::factory()->create([
            'issue_id' => $issue->id,
            'status' => RunStatus::Running,
            'clarification_channel' => 'on_issue',
            'clarification_questions' => [['id' => 'q1', 'text' => 'Which?', 'type' => 'text']],
            'started_at' => now(),
        ]);
        Stage::factory()->create([
            'run_id' => $run->id,
            'name' => StageName::Preflight,
            'status' => StageStatus::AwaitingApproval,
        ]);

        $delivery = WebhookDelivery::create([
            'source_id' => $source->id,
            'external_delivery_id' => 'jc2',
            'event_type' => 'comment_created',
            'payload' => [
                'webhookEvent' => 'comment_created',
                'issue' => ['key' => 'ACME-2'],
                'comment' => [
                    'body' => 'self.',
                    'author' => ['accountId' => 'aaid:bot'],
                ],
            ],
        ]);

        (new ProcessJiraIssueCommentJob($delivery))->handle();

        Queue::assertNothingPushed();
        $this->assertStringContainsString('bot', $delivery->fresh()->error);
    }

    public function test_jira_adf_comment_body_is_extracted(): void
    {
        Queue::fake([ResumePreflightFromCommentJob::class]);
        $source = Source::factory()->create([
            'type' => SourceType::Jira,
            'external_account' => 'acme',
            'config' => ['cloud_id' => 'cloud-1', 'preflight' => ['clarification_channel' => 'on_issue']],
        ]);
        $issue = Issue::factory()->create([
            'source_id' => $source->id,
            'external_id' => 'ACME-3',
        ]);
        $run = Run::factory()->create([
            'issue_id' => $issue->id,
            'status' => RunStatus::Running,
            'clarification_channel' => 'on_issue',
            'clarification_questions' => [['id' => 'q1', 'text' => 'Which?', 'type' => 'text']],
            'started_at' => now(),
        ]);
        Stage::factory()->create([
            'run_id' => $run->id,
            'name' => StageName::Preflight,
            'status' => StageStatus::AwaitingApproval,
        ]);

        $delivery = WebhookDelivery::create([
            'source_id' => $source->id,
            'external_delivery_id' => 'jc3',
            'event_type' => 'comment_created',
            'payload' => [
                'webhookEvent' => 'comment_created',
                'issue' => ['key' => 'ACME-3'],
                'comment' => [
                    'body' => [
                        'type' => 'doc',
                        'version' => 1,
                        'content' => [
                            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Hello reply']]],
                        ],
                    ],
                    'author' => ['accountId' => 'aaid:user'],
                ],
            ],
        ]);

        (new ProcessJiraIssueCommentJob($delivery))->handle();

        Queue::assertPushed(ResumePreflightFromCommentJob::class, function ($job) {
            return $job->commentBody === 'Hello reply';
        });
    }

    public function test_resume_job_writes_comment_into_clarification_answers_and_resumes_stage(): void
    {
        Queue::fake();
        $source = $this->createGitHubSource('on_issue');
        [$issue, $run, $stage] = $this->setupAwaitingPreflight($source);

        (new ResumePreflightFromCommentJob($run, 'A user reply.', 'real-user'))
            ->handle(app(OrchestratorService::class));

        $run->refresh();
        $stage->refresh();
        $this->assertSame('A user reply.', $run->clarification_answers[ResumePreflightFromCommentJob::COMMENT_KEY]);
        $this->assertSame(StageStatus::Running, $stage->status);
    }
}
