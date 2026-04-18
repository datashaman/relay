<?php

namespace Tests\Feature;

use App\Enums\SourceType;
use App\Models\Source;
use App\Models\WebhookDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JiraDynamicWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.jira.client_id' => 'test-client-id',
            'services.jira.client_secret' => 'test-client-secret',
        ]);
    }

    private function createSource(): Source
    {
        return Source::factory()->create([
            'type' => SourceType::Jira,
            'external_account' => 'acme',
            'is_active' => true,
            'config' => ['cloud_id' => 'cloud-123'],
        ]);
    }

    private function buildJwt(array $claims = [], string $secret = 'test-client-secret', string $alg = 'HS256'): string
    {
        $header = $this->base64UrlEncode(json_encode(['alg' => $alg, 'typ' => 'JWT']));
        $claims = array_merge([
            'iss' => 'test-client-id',
            'iat' => time(),
            'exp' => time() + 60,
        ], $claims);
        $payload = $this->base64UrlEncode(json_encode($claims));

        $signature = $alg === 'none'
            ? ''
            : $this->base64UrlEncode(hash_hmac('sha256', $header.'.'.$payload, $secret, true));

        return $header.'.'.$payload.'.'.$signature;
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function issuePayload(string $event = 'jira:issue_created'): array
    {
        return [
            'timestamp' => 1_712_700_000_000,
            'webhookEvent' => $event,
            'issue_event_type_name' => str_replace('jira:', '', $event),
            'issue' => [
                'id' => '10001',
                'key' => 'TEST-1',
                'self' => 'https://jira.example.com/issue/10001',
                'fields' => [
                    'summary' => 'Bug',
                    'description' => null,
                    'assignee' => ['displayName' => 'Jane'],
                    'labels' => [],
                    'status' => ['name' => 'To Do'],
                ],
            ],
        ];
    }

    public function test_accepts_delivery_with_valid_jwt(): void
    {
        $source = $this->createSource();
        $jwt = $this->buildJwt();

        $response = $this->postJson(
            route('webhooks.jira.dynamic', $source),
            $this->issuePayload(),
            [
                'Authorization' => 'Bearer '.$jwt,
                'Atlassian-Webhook-Identifier' => 'delivery-abc',
            ],
        );

        $response->assertOk();
        $this->assertDatabaseHas('webhook_deliveries', [
            'source_id' => $source->id,
            'external_delivery_id' => 'delivery-abc',
        ]);
    }

    public function test_rejects_missing_bearer_header(): void
    {
        $source = $this->createSource();

        $response = $this->postJson(route('webhooks.jira.dynamic', $source), $this->issuePayload());

        $response->assertStatus(401);
    }

    public function test_rejects_bad_signature(): void
    {
        $source = $this->createSource();
        $jwt = $this->buildJwt(secret: 'wrong-secret');

        $response = $this->postJson(route('webhooks.jira.dynamic', $source), $this->issuePayload(), [
            'Authorization' => 'Bearer '.$jwt,
        ]);

        $response->assertStatus(401);
    }

    public function test_rejects_unexpected_algorithm(): void
    {
        $source = $this->createSource();
        $jwt = $this->buildJwt(alg: 'none');

        $response = $this->postJson(route('webhooks.jira.dynamic', $source), $this->issuePayload(), [
            'Authorization' => 'Bearer '.$jwt,
        ]);

        $response->assertStatus(401);
    }

    public function test_rejects_expired_token(): void
    {
        $source = $this->createSource();
        $jwt = $this->buildJwt(claims: ['exp' => time() - 3600, 'iat' => time() - 7200]);

        $response = $this->postJson(route('webhooks.jira.dynamic', $source), $this->issuePayload(), [
            'Authorization' => 'Bearer '.$jwt,
        ]);

        $response->assertStatus(401);
    }

    public function test_rejects_wrong_issuer(): void
    {
        $source = $this->createSource();
        $jwt = $this->buildJwt(claims: ['iss' => 'someone-else']);

        $response = $this->postJson(route('webhooks.jira.dynamic', $source), $this->issuePayload(), [
            'Authorization' => 'Bearer '.$jwt,
        ]);

        $response->assertStatus(401);
    }

    public function test_duplicate_delivery_is_idempotent(): void
    {
        $source = $this->createSource();
        $jwt = $this->buildJwt();

        WebhookDelivery::create([
            'source_id' => $source->id,
            'external_delivery_id' => 'delivery-abc',
            'event_type' => 'jira:issue_created',
            'action' => 'issue_created',
            'payload' => $this->issuePayload(),
            'processed_at' => now(),
        ]);

        $response = $this->postJson(route('webhooks.jira.dynamic', $source), $this->issuePayload(), [
            'Authorization' => 'Bearer '.$jwt,
            'Atlassian-Webhook-Identifier' => 'delivery-abc',
        ]);

        $response->assertOk();
        $response->assertJson(['ok' => true, 'duplicate' => true]);
    }
}
