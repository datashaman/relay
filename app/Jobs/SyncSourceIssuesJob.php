<?php

namespace App\Jobs;

use App\Enums\IssueStatus;
use App\Enums\SourceType;
use App\Events\SourceSynced;
use App\Models\Issue;
use App\Models\Repository;
use App\Models\Source;
use App\Services\FrameworkDetector;
use App\Services\GitHubClient;
use App\Services\GitHubWebhookManager;
use App\Services\IssueIntakeService;
use App\Services\JiraClient;
use App\Services\JiraWebhookManager;
use App\Services\OauthService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncSourceIssuesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public Source $source,
    ) {}

    public function handle(OauthService $oauth, IssueIntakeService $intake, ?GitHubWebhookManager $webhookManager = null, ?JiraWebhookManager $jiraWebhookManager = null, ?FrameworkDetector $frameworkDetector = null): void
    {
        $webhookManager ??= app(GitHubWebhookManager::class);
        $jiraWebhookManager ??= app(JiraWebhookManager::class);
        $frameworkDetector ??= app(FrameworkDetector::class);

        if ($this->isIntakePaused()) {
            return;
        }

        $token = $this->source->oauthTokens()
            ->where('provider', $this->source->type->value)
            ->first();

        if (! $token) {
            $this->recordError('No OAuth token found for this source.');

            return;
        }

        try {
            $token = $oauth->refreshIfExpired($token);

            $rawIssues = match ($this->source->type) {
                SourceType::GitHub => $this->fetchGitHubIssues($token, $oauth, $webhookManager, $frameworkDetector),
                SourceType::Jira => $this->fetchJiraIssues($token, $oauth, $intake, $jiraWebhookManager),
            };

            foreach ($rawIssues as $issueData) {
                if (($issueData['state'] ?? 'open') === 'closed') {
                    $intake->markClosed($this->source, $issueData['external_id'], $issueData['state_reason'] ?? null);

                    continue;
                }

                // Open-path issues go through upsertIssue, which reconciles any
                // prior sync-driven rejection back to Queued using the same row
                // it already fetches — sources without discrete reopen webhook
                // events (e.g. Jira) get reopens reconciled here without a
                // separate DB round-trip.
                unset($issueData['state'], $issueData['state_reason']);
                $intake->upsertIssue($this->source, $issueData);
            }

            $this->source->update([
                'last_synced_at' => now(),
                'sync_error' => null,
                'next_retry_at' => null,
            ]);

            SourceSynced::dispatch(
                $this->source->id,
                true,
                null,
                now()->toIso8601String(),
            );
        } catch (\Throwable $e) {
            $this->recordError($e->getMessage());
        }
    }

    private function fetchGitHubIssues($token, OauthService $oauth, GitHubWebhookManager $webhookManager, FrameworkDetector $frameworkDetector): array
    {
        $client = new GitHubClient($token, $oauth);
        $repos = $this->source->config['repositories'] ?? [];

        if (empty($repos)) {
            throw new \RuntimeException('No repositories configured for this GitHub source. Add repositories to source config.');
        }

        $webhookManager->provisionForSelectedRepositories($this->source, $token, $oauth);

        $mapped = [];

        foreach ($repos as $repoFullName) {
            if ($this->source->isRepositoryPaused($repoFullName)) {
                continue;
            }

            [$owner, $repo] = explode('/', $repoFullName, 2);
            $repository = Repository::firstOrCreate(
                ['name' => $repoFullName],
            );

            $frameworkDetector->detect($client, $repository, $owner, $repo);

            $issues = $client->allIssues($owner, $repo);

            foreach ($issues as $ghIssue) {
                if (GitHubClient::isPullRequest($ghIssue)) {
                    continue;
                }
                $attrs = GitHubClient::mapToIssueAttributes($ghIssue);
                $attrs['external_id'] = $repoFullName.'#'.$attrs['external_id'];
                $attrs['repository_id'] = $repository->id;
                $mapped[] = $attrs;
            }
        }

        return $mapped;
    }

    private function fetchJiraIssues($token, OauthService $oauth, IssueIntakeService $intake, JiraWebhookManager $webhookManager): array
    {
        $client = new JiraClient($token, $oauth, $this->source);

        $webhookManager->provisionForSource($this->source, $token, $oauth);

        $jql = $this->buildJiraJql();
        $issues = $client->allIssues($jql);

        $mapped = [];
        foreach ($issues as $jiraIssue) {
            $attrs = JiraClient::mapToIssueAttributes($jiraIssue);
            unset($attrs['status']);
            $attrs['component_id'] = $intake->resolveComponentId($this->source, $attrs);
            unset($attrs['component_external_id'], $attrs['component_name']);
            $mapped[] = $attrs;
        }

        return $mapped;
    }

    private function buildJiraJql(): string
    {
        $config = $this->source->config ?? [];
        // Default is a time-bounded clause (not a status filter) so the sync
        // sees Done/Closed/Resolved transitions and can reconcile them back
        // to the local queue. Users can override with a narrower JQL via
        // source config if they prefer.
        $base = $config['jql'] ?? 'updated >= -30d';

        $clauses = [];

        $projects = array_filter($config['projects'] ?? []);
        if (! empty($projects)) {
            $quoted = implode(',', array_map(fn ($k) => '"'.$k.'"', $projects));
            $clauses[] = 'project in ('.$quoted.')';
        }

        $statuses = array_filter($config['statuses'] ?? []);
        if (! empty($statuses)) {
            $quoted = implode(',', array_map(fn ($s) => '"'.$s.'"', $statuses));
            $clauses[] = 'status in ('.$quoted.')';
        }

        if (! empty($config['only_mine'])) {
            $clauses[] = 'assignee = currentUser()';
        }

        if (! empty($config['only_active_sprint'])) {
            $clauses[] = 'sprint in openSprints()';
        }

        $clauses[] = '('.$base.')';

        return implode(' AND ', $clauses).' ORDER BY updated DESC';
    }

    private function isIntakePaused(): bool
    {
        if ($this->source->is_intake_paused) {
            return true;
        }

        if ($this->source->backlog_threshold) {
            $queuedCount = Issue::where('source_id', $this->source->id)
                ->active()
                ->where('status', IssueStatus::Queued)
                ->count();

            if ($queuedCount >= $this->source->backlog_threshold) {
                return true;
            }
        }

        return false;
    }

    private function recordError(string $message): void
    {
        $this->source->update([
            'last_synced_at' => now(),
            'sync_error' => $message,
            'next_retry_at' => now()->addMinutes($this->source->sync_interval ?? 5),
        ]);

        SourceSynced::dispatch(
            $this->source->id,
            false,
            $message,
            now()->toIso8601String(),
        );
    }
}
