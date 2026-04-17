<?php

namespace App\Jobs;

use App\Enums\IssueStatus;
use App\Enums\SourceType;
use App\Models\Component;
use App\Models\Issue;
use App\Models\Repository;
use App\Models\Source;
use App\Services\FilterRuleService;
use App\Services\GitHubClient;
use App\Services\JiraClient;
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

    public function handle(OauthService $oauth, FilterRuleService $filterService): void
    {
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
                SourceType::GitHub => $this->fetchGitHubIssues($token, $oauth),
                SourceType::Jira => $this->fetchJiraIssues($token, $oauth),
            };

            foreach ($rawIssues as $issueData) {
                $this->syncIssue($issueData, $filterService);
            }

            $this->source->update([
                'last_synced_at' => now(),
                'sync_error' => null,
                'next_retry_at' => null,
            ]);
        } catch (\Throwable $e) {
            $this->recordError($e->getMessage());
        }
    }

    private function fetchGitHubIssues($token, OauthService $oauth): array
    {
        $client = new GitHubClient($token, $oauth);
        $repos = $this->source->config['repositories'] ?? [];

        if (empty($repos)) {
            throw new \RuntimeException('No repositories configured for this GitHub source. Add repositories to source config.');
        }

        $mapped = [];

        foreach ($repos as $repoFullName) {
            if ($this->source->isRepositoryPaused($repoFullName)) {
                continue;
            }

            [$owner, $repo] = explode('/', $repoFullName, 2);
            $repository = Repository::firstOrCreate(
                ['name' => $repoFullName],
            );

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

    private function fetchJiraIssues($token, OauthService $oauth): array
    {
        $client = new JiraClient($token, $oauth, $this->source);
        $jql = $this->buildJiraJql();
        $issues = $client->allIssues($jql);

        $mapped = [];
        foreach ($issues as $jiraIssue) {
            $attrs = JiraClient::mapToIssueAttributes($jiraIssue);
            unset($attrs['status']);
            $attrs['component_id'] = $this->resolveComponentId($attrs);
            unset($attrs['component_external_id'], $attrs['component_name']);
            $mapped[] = $attrs;
        }

        return $mapped;
    }

    private function resolveComponentId(array $attrs): ?int
    {
        $externalId = $attrs['component_external_id'] ?? null;
        $name = $attrs['component_name'] ?? null;

        if (! $externalId) {
            return null;
        }

        $component = Component::firstOrCreate(
            ['source_id' => $this->source->id, 'external_id' => $externalId],
            ['name' => $name ?? $externalId],
        );

        if ($name !== null && $component->name !== $name) {
            $component->update(['name' => $name]);
        }

        return $component->id;
    }

    private function buildJiraJql(): string
    {
        $config = $this->source->config ?? [];
        $base = $config['jql'] ?? 'status != Done';

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

    private function syncIssue(array $issueData, FilterRuleService $filterService): void
    {
        $existing = Issue::where('source_id', $this->source->id)
            ->where('external_id', $issueData['external_id'])
            ->first();

        if ($existing) {
            $this->updateExistingIssue($existing, $issueData);

            return;
        }

        $filterService->applyToSync($issueData, $this->source);
    }

    private function updateExistingIssue(Issue $issue, array $issueData): void
    {
        $updatable = ['title', 'body', 'external_url', 'assignee', 'labels', 'raw_status'];
        $changes = [];

        foreach ($updatable as $field) {
            if (array_key_exists($field, $issueData) && $issue->{$field} !== $issueData[$field]) {
                $changes[$field] = $issueData[$field];
            }
        }

        if ($issue->repository_id === null && ! empty($issueData['repository_id'])) {
            $changes['repository_id'] = $issueData['repository_id'];
        }

        if (array_key_exists('component_id', $issueData) && $issue->component_id !== $issueData['component_id']) {
            $changes['component_id'] = $issueData['component_id'];
        }

        if (! empty($changes)) {
            $issue->update($changes);
        }
    }

    private function isIntakePaused(): bool
    {
        if ($this->source->is_intake_paused) {
            return true;
        }

        if ($this->source->backlog_threshold) {
            $queuedCount = $this->source->issues()
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
    }
}
