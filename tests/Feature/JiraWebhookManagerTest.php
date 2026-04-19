<?php

namespace Tests\Feature;

use App\Enums\SourceType;
use App\Models\OauthToken;
use App\Models\Source;
use App\Services\JiraWebhookManager;
use App\Services\OauthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class JiraWebhookManagerTest extends TestCase
{
    use RefreshDatabase;

    private function createSourceWithToken(array $config = []): array
    {
        $source = Source::factory()->create([
            'type' => SourceType::Jira,
            'external_account' => 'acme',
            'config' => array_merge(['cloud_id' => 'cloud-123', 'projects' => ['ACME', 'REL']], $config),
        ]);

        $token = OauthToken::factory()->create([
            'source_id' => $source->id,
            'provider' => 'jira',
            'access_token' => 'jira_test_token',
            'expires_at' => now()->addHour(),
        ]);

        return [$source, $token];
    }

    public function test_provision_creates_webhook_and_records_managed_state(): void
    {
        [$source, $token] = $this->createSourceWithToken();

        Http::fake([
            'api.atlassian.com/ex/jira/cloud-123/rest/api/3/webhook' => Http::response([
                'webhookRegistrationResult' => [
                    ['createdWebhookId' => 4242],
                ],
            ], 200),
        ]);

        $state = app(JiraWebhookManager::class)->provisionForSource($source, $token, app(OauthService::class));

        $source->refresh();
        $this->assertSame('managed', $state['state']);
        $this->assertSame([4242], $state['webhook_ids']);
        $this->assertSame('managed', $source->config['managed_jira_webhook']['state']);
        $this->assertSame([4242], $source->config['managed_jira_webhook']['webhook_ids']);
        $this->assertNull($source->webhook_last_error);

        Http::assertSent(function ($request) use ($source) {
            $body = $request->data();

            return $request->method() === 'POST'
                && str_contains((string) $request->url(), '/ex/jira/cloud-123/rest/api/3/webhook')
                && ($body['url'] ?? null) === route('webhooks.jira.dynamic', $source)
                && str_contains($body['webhooks'][0]['jqlFilter'] ?? '', 'project in ("ACME", "REL")')
                && in_array('jira:issue_created', $body['webhooks'][0]['events'] ?? [], true);
        });
    }

    public function test_provision_short_circuits_when_jql_and_ids_unchanged(): void
    {
        [$source, $token] = $this->createSourceWithToken([
            'managed_jira_webhook' => [
                'state' => 'managed',
                'webhook_ids' => [999],
                'expires_at' => now()->addDays(20)->toIso8601String(),
                'jql' => 'project in ("ACME", "REL")',
                'updated_at' => now()->subDay()->toIso8601String(),
                'reason' => null,
            ],
        ]);

        Http::fake();

        $state = app(JiraWebhookManager::class)->provisionForSource($source, $token, app(OauthService::class));

        $this->assertSame('managed', $state['state']);
        $this->assertSame([999], $state['webhook_ids']);
        Http::assertNothingSent();
    }

    public function test_provision_deletes_stale_webhook_before_recreating(): void
    {
        [$source, $token] = $this->createSourceWithToken([
            'managed_jira_webhook' => [
                'state' => 'managed',
                'webhook_ids' => [999],
                'expires_at' => now()->addDays(20)->toIso8601String(),
                'jql' => 'project in ("OLD")',
                'updated_at' => now()->subDay()->toIso8601String(),
                'reason' => null,
            ],
        ]);

        Http::fake([
            'api.atlassian.com/ex/jira/cloud-123/rest/api/3/webhook' => Http::sequence()
                ->push([], 202)
                ->push([
                    'webhookRegistrationResult' => [['createdWebhookId' => 777]],
                ], 200),
        ]);

        $state = app(JiraWebhookManager::class)->provisionForSource($source, $token, app(OauthService::class));

        $source->refresh();
        $this->assertSame([777], $state['webhook_ids']);
        $this->assertSame([777], $source->config['managed_jira_webhook']['webhook_ids']);

        $methods = [];
        Http::assertSent(function ($request) use (&$methods) {
            $methods[] = $request->method();

            return true;
        });

        $this->assertSame(['DELETE', 'POST'], $methods);
    }

    public function test_provision_with_no_projects_clears_state_without_api_call(): void
    {
        [$source, $token] = $this->createSourceWithToken(['projects' => []]);

        Http::fake();

        $state = app(JiraWebhookManager::class)->provisionForSource($source, $token, app(OauthService::class));

        $this->assertSame('manual', $state['state']);
        Http::assertNothingSent();
    }

    public function test_events_for_includes_comment_events_when_channel_is_on_issue(): void
    {
        $source = Source::factory()->create([
            'type' => SourceType::Jira,
            'config' => [
                'cloud_id' => 'cloud-x',
                'preflight' => ['clarification_channel' => 'on_issue'],
            ],
        ]);

        $events = JiraWebhookManager::eventsFor($source);

        $this->assertContains('jira:issue_created', $events);
        $this->assertContains('comment_created', $events);
        $this->assertContains('comment_updated', $events);
    }

    public function test_events_for_omits_comment_events_for_in_app_channel(): void
    {
        $source = Source::factory()->create([
            'type' => SourceType::Jira,
            'config' => ['cloud_id' => 'cloud-x'],
        ]);

        $events = JiraWebhookManager::eventsFor($source);

        $this->assertContains('jira:issue_created', $events);
        $this->assertNotContains('comment_created', $events);
    }

    public function test_channel_change_triggers_recreate_with_new_events(): void
    {
        [$source, $token] = $this->createSourceWithToken([
            'managed_jira_webhook' => [
                'state' => 'managed',
                'webhook_ids' => [555],
                'expires_at' => now()->addDays(30)->toIso8601String(),
                'jql' => 'project in ("ACME", "REL")',
                'events' => ['jira:issue_created', 'jira:issue_updated', 'jira:issue_deleted'],
                'updated_at' => now()->subDay()->toIso8601String(),
                'reason' => null,
            ],
            'preflight' => ['clarification_channel' => 'on_issue'],
        ]);

        Http::fake([
            'api.atlassian.com/ex/jira/cloud-123/rest/api/3/webhook' => Http::sequence()
                ->push([], 202)
                ->push([
                    'webhookRegistrationResult' => [['createdWebhookId' => 666]],
                ], 200),
        ]);

        app(JiraWebhookManager::class)->provisionForSource($source, $token, app(OauthService::class));

        Http::assertSent(function ($request) {
            if ($request->method() !== 'POST') {
                return false;
            }
            $body = $request->data();

            return in_array('comment_created', $body['webhooks'][0]['events'] ?? [], true);
        });
    }

    public function test_provision_marks_permission_errors(): void
    {
        [$source, $token] = $this->createSourceWithToken();

        Http::fake([
            'api.atlassian.com/ex/jira/cloud-123/rest/api/3/webhook' => Http::response(['message' => 'forbidden'], 403),
        ]);

        $state = app(JiraWebhookManager::class)->provisionForSource($source, $token, app(OauthService::class));

        $source->refresh();
        $this->assertSame('needs_permission', $state['state']);
        $this->assertSame('needs_permission', $source->config['managed_jira_webhook']['state']);
        $this->assertNotNull($source->webhook_last_error);
    }

    public function test_refresh_extends_expiry(): void
    {
        [$source, $token] = $this->createSourceWithToken([
            'managed_jira_webhook' => [
                'state' => 'managed',
                'webhook_ids' => [4242],
                'expires_at' => now()->addDays(2)->toIso8601String(),
                'jql' => 'project in ("ACME")',
                'updated_at' => now()->subDay()->toIso8601String(),
                'reason' => null,
            ],
        ]);

        Http::fake([
            'api.atlassian.com/ex/jira/cloud-123/rest/api/3/webhook/refresh' => Http::response([], 200),
        ]);

        $state = app(JiraWebhookManager::class)->refreshForSource($source, $token, app(OauthService::class));

        $source->refresh();
        $this->assertSame('managed', $state['state']);
        $this->assertTrue(now()->diffInDays($state['expires_at'], false) >= 29);
        Http::assertSent(fn ($request) => $request->method() === 'PUT'
            && str_contains((string) $request->url(), '/webhook/refresh')
            && $request->data() === ['webhookIds' => [4242]]);
    }

    public function test_deprovision_deletes_managed_webhook(): void
    {
        [$source, $token] = $this->createSourceWithToken([
            'managed_jira_webhook' => [
                'state' => 'managed',
                'webhook_ids' => [4242],
                'expires_at' => now()->addDays(30)->toIso8601String(),
                'jql' => 'project in ("ACME")',
                'updated_at' => now()->toIso8601String(),
                'reason' => null,
            ],
        ]);

        Http::fake([
            'api.atlassian.com/ex/jira/cloud-123/rest/api/3/webhook' => Http::response([], 202),
        ]);

        app(JiraWebhookManager::class)->deprovisionForSource($source, $token, app(OauthService::class));

        $source->refresh();
        $this->assertArrayNotHasKey('managed_jira_webhook', $source->config ?? []);

        Http::assertSent(fn ($request) => $request->method() === 'DELETE'
            && str_contains((string) $request->url(), '/webhook')
            && $request->data() === ['webhookIds' => [4242]]);
    }
}
