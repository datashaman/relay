<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessGitHubWebhookJob;
use App\Models\Source;
use App\Models\WebhookDelivery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GitHubWebhookController extends Controller
{
    public function __invoke(Request $request, Source $source): JsonResponse
    {
        if ($source->type->value !== 'github') {
            return response()->json(['ok' => false, 'error' => 'source is not a github source'], 404);
        }

        $secret = $source->webhook_secret;

        if (! $secret) {
            return response()->json(['ok' => false, 'error' => 'webhook not configured'], 404);
        }

        $signature = $request->header('X-Hub-Signature-256', '');
        $rawBody = $request->getContent();

        if (! $this->verifySignature($signature, $rawBody, $secret)) {
            return response()->json(['ok' => false, 'error' => 'invalid signature'], 401);
        }

        $event = $request->header('X-GitHub-Event', '');
        $deliveryId = $request->header('X-GitHub-Delivery', '');

        if ($deliveryId === '') {
            return response()->json(['ok' => false, 'error' => 'missing delivery id'], 400);
        }

        $payload = $request->json()->all();

        $source->update([
            'webhook_last_delivery_at' => now(),
            'webhook_last_error' => null,
        ]);

        if ($event === 'ping') {
            return response()->json(['ok' => true, 'pong' => true]);
        }

        $delivery = WebhookDelivery::firstOrCreate(
            [
                'source_id' => $source->id,
                'external_delivery_id' => $deliveryId,
            ],
            [
                'event_type' => $event,
                'action' => $payload['action'] ?? null,
                'payload' => $payload,
            ],
        );

        if ($delivery->processed_at !== null) {
            return response()->json(['ok' => true, 'duplicate' => true]);
        }

        if (! $delivery->wasRecentlyCreated) {
            // Re-acking an in-flight duplicate is safe; drop.
            return response()->json(['ok' => true, 'in_flight' => true]);
        }

        if ($event !== 'issues') {
            $delivery->update(['processed_at' => now()]);

            return response()->json(['ok' => true, 'ignored' => true]);
        }

        ProcessGitHubWebhookJob::dispatch($delivery);

        return response()->json(['ok' => true]);
    }

    private function verifySignature(string $header, string $rawBody, string $secret): bool
    {
        if (! str_starts_with($header, 'sha256=')) {
            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($expected, $header);
    }
}
