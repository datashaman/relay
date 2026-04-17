<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessJiraWebhookJob;
use App\Models\Source;
use App\Models\WebhookDelivery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class JiraWebhookController extends Controller
{
    public function __invoke(Request $request, Source $source, string $token): JsonResponse
    {
        if ($source->type->value !== 'jira') {
            return response()->json(['ok' => false, 'error' => 'source is not a jira source'], 404);
        }

        $secret = $source->webhook_secret;

        if (! $secret || ! hash_equals($secret, $token)) {
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
