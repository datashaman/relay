<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessJiraWebhookJob;
use App\Models\Source;
use App\Models\WebhookDelivery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class JiraDynamicWebhookController extends Controller
{
    public function __invoke(Request $request, Source $source): JsonResponse
    {
        if ($source->type->value !== 'jira') {
            return response()->json(['ok' => false, 'error' => 'source is not a jira source'], 404);
        }

        $header = (string) $request->header('Authorization', '');

        if (! str_starts_with($header, 'Bearer ')) {
            return response()->json(['ok' => false, 'error' => 'missing bearer token'], 401);
        }

        $jwt = substr($header, 7);

        if (! $this->verifyJwt($jwt)) {
            return response()->json(['ok' => false, 'error' => 'invalid token'], 401);
        }

        $payload = $request->json()->all();
        $event = $payload['webhookEvent'] ?? null;

        $deliveryId = $request->header('Atlassian-Webhook-Identifier')
            ?: $this->syntheticDeliveryId($event, $payload);

        if ($deliveryId === null) {
            return response()->json(['ok' => false, 'error' => 'missing delivery id'], 400);
        }

        $source->update([
            'webhook_last_delivery_at' => now(),
            'webhook_last_error' => null,
        ]);

        $delivery = WebhookDelivery::firstOrCreate(
            [
                'source_id' => $source->id,
                'external_delivery_id' => $deliveryId,
            ],
            [
                'event_type' => $event,
                'action' => $payload['issue_event_type_name'] ?? null,
                'payload' => $payload,
            ],
        );

        if ($delivery->processed_at !== null) {
            return response()->json(['ok' => true, 'duplicate' => true]);
        }

        if (! $delivery->wasRecentlyCreated) {
            return response()->json(['ok' => true, 'in_flight' => true]);
        }

        $handledEvents = ['jira:issue_created', 'jira:issue_updated', 'jira:issue_deleted'];

        if (! in_array($event, $handledEvents, true)) {
            $delivery->update(['processed_at' => now()]);

            return response()->json(['ok' => true, 'ignored' => true]);
        }

        ProcessJiraWebhookJob::dispatch($delivery);

        return response()->json(['ok' => true]);
    }

    private function verifyJwt(string $jwt): bool
    {
        $secret = config('services.jira.client_secret');

        if (! is_string($secret) || $secret === '') {
            return false;
        }

        $parts = explode('.', $jwt);

        if (count($parts) !== 3) {
            return false;
        }

        [$headerB64, $payloadB64, $signatureB64] = $parts;

        $headerJson = $this->base64UrlDecode($headerB64);
        $payloadJson = $this->base64UrlDecode($payloadB64);
        $signature = $this->base64UrlDecode($signatureB64);

        if ($headerJson === null || $payloadJson === null || $signature === null) {
            return false;
        }

        $header = json_decode($headerJson, true);
        $claims = json_decode($payloadJson, true);

        if (! is_array($header) || ! is_array($claims)) {
            return false;
        }

        if (($header['alg'] ?? null) !== 'HS256') {
            return false;
        }

        $expected = hash_hmac('sha256', $headerB64.'.'.$payloadB64, $secret, true);

        if (! hash_equals($expected, $signature)) {
            return false;
        }

        $now = time();

        if (isset($claims['exp']) && (int) $claims['exp'] + 60 < $now) {
            return false;
        }

        if (isset($claims['iat']) && (int) $claims['iat'] - 60 > $now) {
            return false;
        }

        $expectedIss = config('services.jira.client_id');

        if (is_string($expectedIss) && $expectedIss !== '' && isset($claims['iss']) && $claims['iss'] !== $expectedIss) {
            return false;
        }

        return true;
    }

    private function base64UrlDecode(string $input): ?string
    {
        $padded = strtr($input, '-_', '+/');
        $padLen = (4 - (strlen($padded) % 4)) % 4;
        $padded .= str_repeat('=', $padLen);

        $decoded = base64_decode($padded, true);

        return $decoded === false ? null : $decoded;
    }

    private function syntheticDeliveryId(?string $event, array $payload): ?string
    {
        $timestamp = $payload['timestamp'] ?? null;
        $issueKey = $payload['issue']['key'] ?? $payload['issue']['id'] ?? null;

        if (! $timestamp || ! $issueKey || ! $event) {
            return null;
        }

        return hash('sha256', $event.':'.$issueKey.':'.$timestamp);
    }
}
