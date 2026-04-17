<?php

namespace App\Jobs;

use App\Models\Repository;
use App\Models\Source;
use App\Models\WebhookDelivery;
use App\Services\GitHubClient;
use App\Services\IssueIntakeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessGitHubWebhookJob implements ShouldQueue
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
        $action = $payload['action'] ?? null;

        try {
            if ($source->is_intake_paused) {
                $delivery->update(['processed_at' => now(), 'error' => 'source intake paused']);

                return;
            }

            $repoFullName = $payload['repository']['full_name'] ?? null;

            if ($repoFullName && $source->isRepositoryPaused($repoFullName)) {
                $delivery->update(['processed_at' => now(), 'error' => 'repository paused']);

                return;
            }

            $configuredRepos = $source->config['repositories'] ?? [];

            if (! empty($configuredRepos) && $repoFullName && ! in_array($repoFullName, $configuredRepos, true)) {
                $delivery->update(['processed_at' => now(), 'error' => 'repository not configured']);

                return;
            }

            $ghIssue = $payload['issue'] ?? null;

            if (! $ghIssue || ! $repoFullName) {
                $delivery->update(['processed_at' => now(), 'error' => 'malformed payload']);

                return;
            }

            if (GitHubClient::isPullRequest($ghIssue)) {
                $delivery->update(['processed_at' => now(), 'error' => 'pull request ignored']);

                return;
            }

            $externalId = $repoFullName.'#'.($ghIssue['number'] ?? '');

            if ($action === 'deleted') {
                $intake->markDeleted($source, $externalId);
                $delivery->update(['processed_at' => now()]);

                return;
            }

            $attrs = GitHubClient::mapToIssueAttributes($ghIssue);
            $attrs['external_id'] = $externalId;
            $attrs['repository_id'] = Repository::firstOrCreate(['name' => $repoFullName])->id;

            $intake->upsertIssue($source, $attrs);

            $delivery->update(['processed_at' => now()]);
        } catch (\Throwable $e) {
            $delivery->update(['processed_at' => now(), 'error' => $e->getMessage()]);
            $source->update(['webhook_last_error' => $e->getMessage()]);

            throw $e;
        }
    }
}
