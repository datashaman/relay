<?php

namespace Tests\Feature;

use App\Enums\SourceType;
use App\Models\OauthToken;
use App\Models\Source;
use App\Services\GitHubWebhookManager;
use App\Services\OauthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GitHubWebhookManagerTest extends TestCase
{
    use RefreshDatabase;

    private function createSourceWithToken(array $config = []): array
    {
        $source = Source::factory()->create([
            'type' => SourceType::GitHub,
            'external_account' => 'octocat',
            'config' => array_merge(['repositories' => ['owner/repo']], $config),
        ]);

        $token = OauthToken::factory()->create([
            'source_id' => $source->id,
            'provider' => 'github',
            'access_token' => 'gho_test_token',
            'expires_at' => now()->addHour(),
        ]);

        return [$source, $token];
    }

    public function test_provisioning_creates_missing_webhook_and_records_managed_state(): void
    {
        [$source, $token] = $this->createSourceWithToken();

        Http::fake([
            'api.github.com/repos/owner/repo/hooks' => Http::sequence()
                ->push([], 200)
                ->push(['id' => 321], 201),
        ]);

        $result = app(GitHubWebhookManager::class)->provisionForSelectedRepositories($source, $token, app(OauthService::class));

        $source->refresh();
        $this->assertSame(1, $result['managed']);
        $this->assertSame('managed', $source->config['managed_webhooks']['owner/repo']['state']);
        $this->assertSame(321, $source->config['managed_webhooks']['owner/repo']['hook_id']);

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains((string) $request->url(), '/repos/owner/repo/hooks'));
    }

    public function test_provisioning_reuses_existing_webhook_and_updates_it(): void
    {
        [$source, $token] = $this->createSourceWithToken();

        $webhookUrl = route('webhooks.github', $source);

        Http::fake([
            'api.github.com/repos/owner/repo/hooks' => Http::response([
                [
                    'id' => 77,
                    'config' => ['url' => $webhookUrl],
                ],
            ], 200),
            'api.github.com/repos/owner/repo/hooks/77' => Http::response([
                'id' => 77,
                'config' => ['url' => $webhookUrl],
            ], 200),
        ]);

        $result = app(GitHubWebhookManager::class)->provisionForSelectedRepositories($source, $token, app(OauthService::class));

        $source->refresh();
        $this->assertSame(1, $result['managed']);
        $this->assertSame('managed', $source->config['managed_webhooks']['owner/repo']['state']);
        $this->assertSame(77, $source->config['managed_webhooks']['owner/repo']['hook_id']);

        Http::assertSent(fn ($request) => $request->method() === 'PATCH'
            && str_contains((string) $request->url(), '/repos/owner/repo/hooks/77'));
        Http::assertNotSent(fn ($request) => $request->method() === 'POST'
            && str_contains((string) $request->url(), '/repos/owner/repo/hooks'));
    }

    public function test_provisioning_marks_permission_failures_as_needs_permission(): void
    {
        [$source, $token] = $this->createSourceWithToken();

        Http::fake([
            'api.github.com/repos/owner/repo/hooks' => Http::response([
                'message' => 'Resource not accessible by integration',
            ], 403),
        ]);

        $result = app(GitHubWebhookManager::class)->provisionForSelectedRepositories($source, $token, app(OauthService::class));

        $source->refresh();
        $this->assertSame(0, $result['managed']);
        $this->assertSame(1, $result['permission_errors']);
        $this->assertSame('needs_permission', $source->config['managed_webhooks']['owner/repo']['state']);
        $this->assertStringContainsString('Resource not accessible', $source->config['managed_webhooks']['owner/repo']['reason']);
    }
}
