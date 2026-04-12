<?php

namespace App\Jobs;

use App\Enums\IssueStatus;
use App\Enums\SourceType;
use App\Models\Issue;
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
            [$owner, $repo] = explode('/', $repoFullName, 2);
            $issues = $client->allIssues($owner, $repo);

            foreach ($issues as $ghIssue) {
                if (GitHubClient::isPullRequest($ghIssue)) {
                    continue;
                }
                $attrs = GitHubClient::mapToIssueAttributes($ghIssue);
                $attrs['external_id'] = $repoFullName . '#' . $attrs['external_id'];
                $mapped[] = $attrs;
            }
        }

        return $mapped;
    }

    private function fetchJiraIssues($token, OauthService $oauth): array
    {
        $client = new JiraClient($token, $oauth, $this->source);
        $jql = $this->source->config['jql'] ?? 'status != Done ORDER BY updated DESC';
        $issues = $client->allIssues($jql);

        $mapped = [];
        foreach ($issues as $jiraIssue) {
            $attrs = JiraClient::mapToIssueAttributes($jiraIssue);
            unset($attrs['status']);
            $mapped[] = $attrs;
        }

        return $mapped;
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
        $updatable = ['title', 'body', 'external_url', 'assignee', 'labels'];
        $changes = [];

        foreach ($updatable as $field) {
            if (array_key_exists($field, $issueData) && $issue->{$field} !== $issueData[$field]) {
                $changes[$field] = $issueData[$field];
            }
        }

        if (! empty($changes)) {
            $issue->update($changes);
        }
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
