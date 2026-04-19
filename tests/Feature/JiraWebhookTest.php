<?php

namespace Tests\Feature;

use App\Enums\IssueStatus;
use App\Enums\SourceType;
use App\Models\Issue;
use App\Models\Source;
use App\Models\WebhookDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class JiraWebhookTest extends TestCase
{
    use RefreshDatabase;

    private function createSource(array $config = []): Source
    {
        return Source::factory()->create([
            'type' => SourceType::Jira,
            'external_account' => 'jira-user',
            'is_active' => true,
            'config' => array_merge(['cloud_id' => 'test-cloud-id'], $config),
        ]);
    }

    private function issuePayload(string $event = 'jira:issue_created', array $overrides = []): array
    {
        return array_replace_recursive([
            'timestamp' => 1_712_700_000_000,
            'webhookEvent' => $event,
            'issue_event_type_name' => str_replace('jira:', '', $event),
            'issue' => [
                'id' => '10001',
                'key' => 'TEST-1',
                'self' => 'https://jira.example.com/issue/10001',
                'fields' => [
                    'summary' => 'Jira bug',
                    'description' => null,
                    'assignee' => ['displayName' => 'Jane'],
                    'labels' => ['backend'],
                    'status' => ['name' => 'To Do'],
                ],
            ],
        ], $overrides);
    }

    private function postWebhook(Source $source, array $payload, ?string $token = null): TestResponse
    {
        $token ??= $source->webhook_secret;

        return $this->postJson(route('webhooks.jira', [$source, $token]), $payload);
    }

    public function test_rejects_invalid_token(): void
    {
        $source = $this->createSource();

        $response = $this->postWebhook($source, $this->issuePayload(), token: 'wrong-token');

        $response->assertStatus(401);
        $this->assertDatabaseCount('issues', 0);
    }

    public function test_issue_created_creates_issue(): void
    {
        $source = $this->createSource();

        $response = $this->postWebhook($source, $this->issuePayload('jira:issue_created'));

        $response->assertOk();
        $this->assertDatabaseHas('issues', [
            'source_id' => $source->id,
            'external_id' => 'TEST-1',
            'title' => 'Jira bug',
            'assignee' => 'Jane',
            'raw_status' => 'To Do',
        ]);
    }

    public function test_issue_updated_updates_existing(): void
    {
        $source = $this->createSource();
        Issue::factory()->create([
            'source_id' => $source->id,
            'external_id' => 'TEST-1',
            'title' => 'Old',
            'status' => IssueStatus::Queued,
            'raw_status' => 'To Do',
        ]);

        $response = $this->postWebhook($source, $this->issuePayload('jira:issue_updated', [
            'issue' => ['fields' => ['summary' => 'New', 'status' => ['name' => 'In Progress']]],
        ]));

        $response->assertOk();
        $issue = Issue::first();
        $this->assertEquals('New', $issue->title);
        $this->assertEquals('In Progress', $issue->raw_status);
    }

    public function test_issue_deleted_rejects_queued_issue(): void
    {
        $source = $this->createSource();
        Issue::factory()->create([
            'source_id' => $source->id,
            'external_id' => 'TEST-1',
            'status' => IssueStatus::Queued,
        ]);

        $response = $this->postWebhook($source, $this->issuePayload('jira:issue_deleted'));

        $response->assertOk();
        $issue = Issue::first();
        $this->assertEquals(IssueStatus::Rejected, $issue->status);
    }

    public function test_issue_updated_to_done_rejects_queued_issue(): void
    {
        $source = $this->createSource();
        Issue::factory()->create([
            'source_id' => $source->id,
            'external_id' => 'TEST-1',
            'title' => 'closeable',
            'status' => IssueStatus::Queued,
        ]);

        $response = $this->postWebhook($source, $this->issuePayload('jira:issue_updated', [
            'issue' => ['fields' => ['status' => ['name' => 'Done']]],
        ]));

        $response->assertOk();
        $issue = Issue::first();
        $this->assertEquals(IssueStatus::Rejected, $issue->status);
        $this->assertEquals('closed:Done', $issue->raw_status);
    }

    public function test_issue_updated_to_done_preserves_accepted_status(): void
    {
        $source = $this->createSource();
        Issue::factory()->create([
            'source_id' => $source->id,
            'external_id' => 'TEST-1',
            'title' => 'in flight',
            'status' => IssueStatus::Accepted,
        ]);

        $response = $this->postWebhook($source, $this->issuePayload('jira:issue_updated', [
            'issue' => ['fields' => ['status' => ['name' => 'Done']]],
        ]));

        $response->assertOk();
        $issue = Issue::first();
        $this->assertEquals(IssueStatus::Accepted, $issue->status);
        $this->assertEquals('closed:Done', $issue->raw_status);
    }

    public function test_issue_updated_to_open_status_reopens_sync_rejected_issue(): void
    {
        $source = $this->createSource();
        Issue::factory()->create([
            'source_id' => $source->id,
            'external_id' => 'TEST-1',
            'title' => 'reopenable',
            'status' => IssueStatus::Rejected,
            'raw_status' => 'closed:Done',
        ]);

        $response = $this->postWebhook($source, $this->issuePayload('jira:issue_updated', [
            'issue' => ['fields' => ['status' => ['name' => 'To Do']]],
        ]));

        $response->assertOk();
        $issue = Issue::first();
        $this->assertEquals(IssueStatus::Queued, $issue->status);
        // upsertIssue runs after markReopened, so raw_status reflects the current Jira status
        $this->assertEquals('To Do', $issue->raw_status);
    }

    public function test_issue_updated_to_open_does_not_revive_user_rejected_issue(): void
    {
        $source = $this->createSource();
        Issue::factory()->create([
            'source_id' => $source->id,
            'external_id' => 'TEST-1',
            'title' => 'user-rejected',
            'status' => IssueStatus::Rejected,
            'raw_status' => null,
        ]);

        $response = $this->postWebhook($source, $this->issuePayload('jira:issue_updated', [
            'issue' => ['fields' => ['status' => ['name' => 'To Do']]],
        ]));

        $response->assertOk();
        $issue = Issue::first();
        $this->assertEquals(IssueStatus::Rejected, $issue->status, 'user-driven rejection must not resurrect on upstream reopen');
    }

    public function test_idempotency_via_synthetic_delivery_id(): void
    {
        $source = $this->createSource();

        $this->postWebhook($source, $this->issuePayload())->assertOk();
        $this->postWebhook($source, $this->issuePayload())->assertOk();

        $this->assertDatabaseCount('webhook_deliveries', 1);
        $this->assertDatabaseCount('issues', 1);
    }

    public function test_idempotency_via_atlassian_header(): void
    {
        $source = $this->createSource();

        $this->withHeaders(['Atlassian-Webhook-Identifier' => 'hook-abc'])
            ->postJson(route('webhooks.jira', [$source, $source->webhook_secret]), $this->issuePayload())
            ->assertOk();

        $this->withHeaders(['Atlassian-Webhook-Identifier' => 'hook-abc'])
            ->postJson(route('webhooks.jira', [$source, $source->webhook_secret]), $this->issuePayload('jira:issue_created', [
                'timestamp' => 1_712_700_999_999,
            ]))
            ->assertOk();

        $this->assertDatabaseCount('webhook_deliveries', 1);
    }

    public function test_paused_source_drops_event(): void
    {
        $source = $this->createSource();
        $source->update(['is_intake_paused' => true]);

        $response = $this->postWebhook($source, $this->issuePayload());

        $response->assertOk();
        $this->assertDatabaseCount('issues', 0);
        $this->assertEquals('source intake paused', WebhookDelivery::first()->error);
    }

    public function test_unknown_event_acked_and_skipped(): void
    {
        $source = $this->createSource();

        $response = $this->postWebhook($source, $this->issuePayload('comment_created'));

        $response->assertOk();
        $response->assertJson(['ok' => true, 'ignored' => true]);
        $this->assertDatabaseCount('issues', 0);
    }

    public function test_webhook_url_shown_in_intake_ui(): void
    {
        $source = $this->createSource();

        $response = $this->get(route('intake.sources.show', $source));

        $response->assertSee(route('webhooks.jira', [$source, $source->webhook_secret]), false);
    }
}
