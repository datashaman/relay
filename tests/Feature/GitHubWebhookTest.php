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

class GitHubWebhookTest extends TestCase
{
    use RefreshDatabase;

    private function createSource(array $config = []): Source
    {
        return Source::factory()->create([
            'type' => SourceType::GitHub,
            'external_account' => 'testuser',
            'is_active' => true,
            'config' => array_merge(['repositories' => ['owner/repo']], $config),
        ]);
    }

    private function postWebhook(Source $source, string $event, array $payload, ?string $secret = null, ?string $deliveryId = null, ?string $signature = null, bool $omitDeliveryId = false): TestResponse
    {
        $secret ??= $source->webhook_secret;
        $deliveryId ??= 'delivery-'.fake()->uuid();
        $body = json_encode($payload);
        $signature ??= 'sha256='.hash_hmac('sha256', $body, $secret);

        $server = [
            'HTTP_X_GITHUB_EVENT' => $event,
            'HTTP_X_HUB_SIGNATURE_256' => $signature,
            'CONTENT_TYPE' => 'application/json',
        ];

        if (! $omitDeliveryId) {
            $server['HTTP_X_GITHUB_DELIVERY'] = $deliveryId;
        }

        return $this->call('POST', route('webhooks.github', $source), [], [], [], $server, $body);
    }

    private function issuesPayload(string $action = 'opened', array $overrides = []): array
    {
        return array_replace_recursive([
            'action' => $action,
            'repository' => ['full_name' => 'owner/repo'],
            'issue' => [
                'number' => 1,
                'title' => 'Bug report',
                'body' => 'Something is broken',
                'html_url' => 'https://github.com/owner/repo/issues/1',
                'assignee' => ['login' => 'dev1'],
                'labels' => [['name' => 'bug']],
            ],
        ], $overrides);
    }

    public function test_source_created_with_webhook_secret(): void
    {
        $source = $this->createSource();

        $this->assertNotEmpty($source->webhook_secret);
        $this->assertEquals(40, strlen($source->webhook_secret));
    }

    public function test_webhook_rejects_invalid_signature(): void
    {
        $source = $this->createSource();

        $response = $this->postWebhook($source, 'issues', $this->issuesPayload(), signature: 'sha256=deadbeef');

        $response->assertStatus(401);
        $this->assertDatabaseCount('issues', 0);
        $this->assertDatabaseCount('webhook_deliveries', 0);
    }

    public function test_webhook_rejects_missing_delivery_id(): void
    {
        $source = $this->createSource();

        $response = $this->postWebhook($source, 'issues', $this->issuesPayload(), omitDeliveryId: true);

        $response->assertStatus(400);
    }

    public function test_ping_event_is_acknowledged(): void
    {
        $source = $this->createSource();

        $response = $this->postWebhook($source, 'ping', ['zen' => 'Keep it logically awesome.']);

        $response->assertOk();
        $response->assertJson(['ok' => true, 'pong' => true]);
        $this->assertNotNull($source->fresh()->webhook_last_delivery_at);
    }

    public function test_issue_opened_creates_issue(): void
    {
        $source = $this->createSource();

        $response = $this->postWebhook($source, 'issues', $this->issuesPayload('opened'));

        $response->assertOk();
        $this->assertDatabaseHas('issues', [
            'source_id' => $source->id,
            'external_id' => 'owner/repo#1',
            'title' => 'Bug report',
            'assignee' => 'dev1',
        ]);
    }

    public function test_issue_edited_updates_existing(): void
    {
        $source = $this->createSource();
        Issue::factory()->create([
            'source_id' => $source->id,
            'external_id' => 'owner/repo#1',
            'title' => 'Old title',
            'status' => IssueStatus::Queued,
        ]);

        $response = $this->postWebhook($source, 'issues', $this->issuesPayload('edited', [
            'issue' => ['title' => 'New title'],
        ]));

        $response->assertOk();
        $this->assertEquals('New title', Issue::first()->title);
    }

    public function test_issue_deleted_rejects_queued_issue(): void
    {
        $source = $this->createSource();
        Issue::factory()->create([
            'source_id' => $source->id,
            'external_id' => 'owner/repo#1',
            'title' => 'x',
            'status' => IssueStatus::Queued,
        ]);

        $response = $this->postWebhook($source, 'issues', $this->issuesPayload('deleted'));

        $response->assertOk();
        $issue = Issue::first();
        $this->assertEquals(IssueStatus::Rejected, $issue->status);
        $this->assertEquals('deleted', $issue->raw_status);
    }

    public function test_issue_closed_rejects_queued_issue(): void
    {
        $source = $this->createSource();
        Issue::factory()->create([
            'source_id' => $source->id,
            'external_id' => 'owner/repo#1',
            'title' => 'closeable',
            'status' => IssueStatus::Queued,
        ]);

        $response = $this->postWebhook($source, 'issues', $this->issuesPayload('closed', [
            'issue' => ['state_reason' => 'completed'],
        ]));

        $response->assertOk();
        $issue = Issue::first();
        $this->assertEquals(IssueStatus::Rejected, $issue->status);
        $this->assertEquals('closed:completed', $issue->raw_status);
    }

    public function test_issue_closed_preserves_accepted_status(): void
    {
        $source = $this->createSource();
        Issue::factory()->create([
            'source_id' => $source->id,
            'external_id' => 'owner/repo#1',
            'title' => 'in flight',
            'status' => IssueStatus::Accepted,
        ]);

        $response = $this->postWebhook($source, 'issues', $this->issuesPayload('closed'));

        $response->assertOk();
        $issue = Issue::first();
        $this->assertEquals(IssueStatus::Accepted, $issue->status);
        $this->assertEquals('closed', $issue->raw_status);
    }

    public function test_issue_reopened_returns_sync_rejected_to_queue(): void
    {
        $source = $this->createSource();
        Issue::factory()->create([
            'source_id' => $source->id,
            'external_id' => 'owner/repo#1',
            'title' => 'reopenable',
            'status' => IssueStatus::Rejected,
            'raw_status' => 'closed:completed',
        ]);

        $response = $this->postWebhook($source, 'issues', $this->issuesPayload('reopened'));

        $response->assertOk();
        $issue = Issue::first();
        $this->assertEquals(IssueStatus::Queued, $issue->status);
        $this->assertNull($issue->raw_status);
    }

    public function test_issue_reopened_does_not_revive_user_rejected_issue(): void
    {
        $source = $this->createSource();
        Issue::factory()->create([
            'source_id' => $source->id,
            'external_id' => 'owner/repo#1',
            'title' => 'user-rejected',
            'status' => IssueStatus::Rejected,
            'raw_status' => null,
        ]);

        $response = $this->postWebhook($source, 'issues', $this->issuesPayload('reopened'));

        $response->assertOk();
        $issue = Issue::first();
        $this->assertEquals(IssueStatus::Rejected, $issue->status, 'user-driven rejection must not resurrect on upstream reopen');
    }

    public function test_idempotency_by_delivery_id(): void
    {
        $source = $this->createSource();
        $deliveryId = 'fixed-delivery-id';

        $this->postWebhook($source, 'issues', $this->issuesPayload(), deliveryId: $deliveryId)->assertOk();
        $this->postWebhook($source, 'issues', $this->issuesPayload(), deliveryId: $deliveryId)->assertOk();

        $this->assertDatabaseCount('webhook_deliveries', 1);
        $this->assertDatabaseCount('issues', 1);
    }

    public function test_paused_source_drops_event_but_acks(): void
    {
        $source = $this->createSource();
        $source->update(['is_intake_paused' => true]);

        $response = $this->postWebhook($source, 'issues', $this->issuesPayload());

        $response->assertOk();
        $this->assertDatabaseCount('issues', 0);
        $delivery = WebhookDelivery::first();
        $this->assertNotNull($delivery->processed_at);
        $this->assertEquals('source intake paused', $delivery->error);
    }

    public function test_paused_repository_drops_event(): void
    {
        $source = $this->createSource();
        $source->update(['paused_repositories' => ['owner/repo']]);

        $response = $this->postWebhook($source, 'issues', $this->issuesPayload());

        $response->assertOk();
        $this->assertDatabaseCount('issues', 0);
        $this->assertEquals('repository paused', WebhookDelivery::first()->error);
    }

    public function test_pull_request_ignored(): void
    {
        $source = $this->createSource();

        $response = $this->postWebhook($source, 'issues', $this->issuesPayload('opened', [
            'issue' => ['pull_request' => ['url' => 'x']],
        ]));

        $response->assertOk();
        $this->assertDatabaseCount('issues', 0);
    }

    public function test_unknown_event_acked_and_skipped(): void
    {
        $source = $this->createSource();

        $response = $this->postWebhook($source, 'issue_comment', ['action' => 'created']);

        $response->assertOk();
        $response->assertJson(['ok' => true, 'ignored' => true]);
        $this->assertDatabaseCount('issues', 0);
        $this->assertNotNull(WebhookDelivery::first()->processed_at);
    }

    public function test_repository_not_in_config_is_dropped(): void
    {
        $source = $this->createSource(['repositories' => ['other/repo']]);

        $response = $this->postWebhook($source, 'issues', $this->issuesPayload());

        $response->assertOk();
        $this->assertDatabaseCount('issues', 0);
        $this->assertEquals('repository not configured', WebhookDelivery::first()->error);
    }

    public function test_webhook_url_shown_in_intake_ui(): void
    {
        $source = $this->createSource();

        $response = $this->get(route('intake.index'));

        $response->assertSee(route('webhooks.github', $source), false);
        $response->assertSee($source->webhook_secret);
    }

    public function test_intake_page_backfills_missing_webhook_secret(): void
    {
        $source = $this->createSource();
        $source->forceFill(['webhook_secret' => null])->saveQuietly();

        $this->assertNull($source->fresh()->webhook_secret);

        $this->get(route('intake.index'))->assertOk();

        $this->assertNotEmpty($source->fresh()->webhook_secret);
    }
}
