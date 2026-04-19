<?php

namespace App\Services;

use App\Models\OauthToken;
use App\Models\Source;
use Illuminate\Http\Client\RequestException;

class JiraWebhookManager
{
    private const BASE_EVENTS = ['jira:issue_created', 'jira:issue_updated', 'jira:issue_deleted'];

    /**
     * Resolve the Jira event subscription list for this source. When the
     * preflight clarification channel is on_issue, comment events are added
     * so reply comments can resume the clarification loop.
     *
     * @return array<int, string>
     */
    public static function eventsFor(Source $source): array
    {
        $events = self::BASE_EVENTS;

        if ($source->clarificationChannel() === 'on_issue') {
            $events[] = 'comment_created';
            $events[] = 'comment_updated';
        }

        return $events;
    }

    public function provisionForSource(Source $source, OauthToken $token, OauthService $oauth): array
    {
        if ($source->type->value !== 'jira') {
            throw new \InvalidArgumentException('Source is not a Jira source.');
        }

        $projectKeys = array_values($source->config['projects'] ?? []);

        if ($projectKeys === []) {
            $this->clearState($source, 'No Jira projects selected; webhook not provisioned.');

            return $this->stateSummary('manual', 'No Jira projects selected.');
        }

        $client = new JiraClient($token, $oauth, $source);
        $callbackUrl = route('webhooks.jira.dynamic', $source);
        $jql = $this->buildJql($projectKeys);
        $events = self::eventsFor($source);

        $existing = $source->config['managed_jira_webhook'] ?? null;

        // Backward-compat: pre-channel state lacks an `events` key. Treat it
        // as the legacy BASE_EVENTS shape so existing managed webhooks aren't
        // recreated unnecessarily on the first read.
        $existingEvents = $existing['events'] ?? self::BASE_EVENTS;

        if (
            ($existing['state'] ?? null) === 'managed'
            && ($existing['jql'] ?? null) === $jql
            && $existingEvents === $events
            && ! empty($existing['webhook_ids'] ?? [])
        ) {
            return $existing;
        }

        try {
            $this->deleteExistingWebhooks($client, $source);

            $response = $client->createWebhooks($callbackUrl, [[
                'events' => $events,
                'jqlFilter' => $jql,
            ]]);

            $entries = $response['webhookRegistrationResult'] ?? [];
            $created = $this->pickCreated($entries);

            if ($created === []) {
                return $this->recordError($source, 'Jira returned no webhook ID.', 'error');
            }

            $state = [
                'state' => 'managed',
                'webhook_ids' => $created,
                'expires_at' => now()->addDays(30)->toIso8601String(),
                'jql' => $jql,
                'events' => $events,
                'updated_at' => now()->toIso8601String(),
                'reason' => null,
            ];

            $this->storeState($source, $state, clearError: true);

            return $state;
        } catch (RequestException $e) {
            $status = $e->response->status();
            $isPermissionError = in_array($status, [401, 403], true);
            $reason = $this->truncateReason($e->response->body() ?: $e->getMessage());

            return $this->recordError(
                $source,
                $reason,
                $isPermissionError ? 'needs_permission' : 'error',
            );
        } catch (\Throwable $e) {
            return $this->recordError($source, $this->truncateReason($e->getMessage()), 'error');
        }
    }

    public function refreshForSource(Source $source, OauthToken $token, OauthService $oauth): array
    {
        $state = $source->config['managed_jira_webhook'] ?? null;
        $ids = $state['webhook_ids'] ?? [];

        if ($ids === []) {
            return $this->stateSummary('manual', 'No managed Jira webhook to refresh.');
        }

        $client = new JiraClient($token, $oauth, $source);

        try {
            $client->refreshWebhooks($ids);

            $state['expires_at'] = now()->addDays(30)->toIso8601String();
            $state['updated_at'] = now()->toIso8601String();
            $state['reason'] = null;
            $state['state'] = 'managed';

            $this->storeState($source, $state, clearError: true);

            return $state;
        } catch (RequestException $e) {
            $reason = $this->truncateReason($e->response->body() ?: $e->getMessage());

            return $this->recordError($source, $reason, 'error');
        } catch (\Throwable $e) {
            return $this->recordError($source, $this->truncateReason($e->getMessage()), 'error');
        }
    }

    public function deprovisionForSource(Source $source, OauthToken $token, OauthService $oauth): void
    {
        $state = $source->config['managed_jira_webhook'] ?? null;
        $ids = $state['webhook_ids'] ?? [];

        if ($ids === []) {
            return;
        }

        $client = new JiraClient($token, $oauth, $source);

        try {
            $client->deleteWebhooks($ids);
        } catch (\Throwable) {
            // best effort — caller is tearing down the source anyway
        }

        $this->clearState($source, null);
    }

    private function deleteExistingWebhooks(JiraClient $client, Source $source): void
    {
        $state = $source->config['managed_jira_webhook'] ?? null;
        $ids = $state['webhook_ids'] ?? [];

        if ($ids === []) {
            return;
        }

        try {
            $client->deleteWebhooks($ids);
        } catch (\Throwable) {
            // best effort — we're about to recreate
        }
    }

    private function buildJql(array $projectKeys): string
    {
        $quoted = array_map(fn (string $k) => '"'.addslashes($k).'"', $projectKeys);

        return 'project in ('.implode(', ', $quoted).')';
    }

    private function pickCreated(array $entries): array
    {
        $ids = [];

        foreach ($entries as $entry) {
            if (isset($entry['createdWebhookId'])) {
                $ids[] = (int) $entry['createdWebhookId'];
            } elseif (isset($entry['id'])) {
                $ids[] = (int) $entry['id'];
            }
        }

        return $ids;
    }

    private function recordError(Source $source, string $reason, string $stateKey): array
    {
        $state = [
            'state' => $stateKey,
            'webhook_ids' => [],
            'expires_at' => null,
            'jql' => null,
            'updated_at' => now()->toIso8601String(),
            'reason' => $reason,
        ];

        $this->storeState($source, $state, clearError: false);

        $source->update([
            'webhook_last_error' => $reason,
        ]);

        return $state;
    }

    private function storeState(Source $source, array $state, bool $clearError): void
    {
        $config = $source->config ?? [];
        $config['managed_jira_webhook'] = $state;

        $updates = ['config' => $config];

        if ($clearError) {
            $updates['webhook_last_error'] = null;
        }

        $source->update($updates);
    }

    private function clearState(Source $source, ?string $reason): void
    {
        $config = $source->config ?? [];
        unset($config['managed_jira_webhook']);

        $source->update([
            'config' => $config,
            'webhook_last_error' => $reason,
        ]);
    }

    private function stateSummary(string $state, string $reason): array
    {
        return [
            'state' => $state,
            'webhook_ids' => [],
            'expires_at' => null,
            'jql' => null,
            'updated_at' => now()->toIso8601String(),
            'reason' => $reason,
        ];
    }

    private function truncateReason(string $message): string
    {
        return mb_substr(trim($message), 0, 180);
    }
}
