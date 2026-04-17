<?php

namespace App\Jobs;

use App\Models\WebhookDelivery;
use App\Services\IssueIntakeService;
use App\Services\JiraClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessJiraWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public WebhookDelivery $delivery,
    ) {}

    public function handle(IssueIntakeService $intake): void
    {
        $delivery = $this->delivery;
        $source = $delivery->source;

        if (! $source) {
            $delivery->update(['processed_at' => now(), 'error' => 'source missing']);

            return;
        }

        $payload = $delivery->payload ?? [];
        $event = $payload['webhookEvent'] ?? null;
        $jiraIssue = $payload['issue'] ?? null;

        try {
            if ($source->is_intake_paused) {
                $delivery->update(['processed_at' => now(), 'error' => 'source intake paused']);

                return;
            }

            if (! $jiraIssue) {
                $delivery->update(['processed_at' => now(), 'error' => 'malformed payload']);

                return;
            }

            $externalId = (string) ($jiraIssue['key'] ?? $jiraIssue['id'] ?? '');

            if ($externalId === '') {
                $delivery->update(['processed_at' => now(), 'error' => 'missing issue key']);

                return;
            }

            if ($event === 'jira:issue_deleted') {
                $intake->markDeleted($source, $externalId);
                $delivery->update(['processed_at' => now()]);

                return;
            }

            $attrs = JiraClient::mapToIssueAttributes($jiraIssue);
            unset($attrs['status']);
            $attrs['component_id'] = $intake->resolveComponentId($source, $attrs);
            unset($attrs['component_external_id'], $attrs['component_name']);

            $intake->upsertIssue($source, $attrs);

            $delivery->update(['processed_at' => now()]);
        } catch (\Throwable $e) {
            $delivery->update(['processed_at' => now(), 'error' => $e->getMessage()]);
            $source->update(['webhook_last_error' => $e->getMessage()]);

            throw $e;
        }
    }
}
