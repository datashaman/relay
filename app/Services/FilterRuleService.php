<?php

namespace App\Services;

use App\Enums\IssueStatus;
use App\Models\FilterRule;
use App\Models\Issue;
use App\Models\Source;
use Illuminate\Validation\ValidationException;

class FilterRuleService
{
    public function evaluate(array $issueData, Source $source): ?array
    {
        $rule = $source->filterRule;

        if (! $rule) {
            return $this->buildIssueAttributes($issueData, $source, autoAccepted: false);
        }

        if (! $this->matchesFilters($issueData, $rule)) {
            return null;
        }

        $autoAccepted = $this->isAutoAccepted($issueData, $rule);

        return $this->buildIssueAttributes($issueData, $source, $autoAccepted);
    }

    public function matchesFilters(array $issueData, FilterRule $rule): bool
    {
        $labels = array_map('strtolower', $issueData['labels'] ?? []);

        if (! empty($rule->include_labels)) {
            $includeLabels = array_map('strtolower', $rule->include_labels);
            if (empty(array_intersect($labels, $includeLabels))) {
                return false;
            }
        }

        if (! empty($rule->exclude_labels)) {
            $excludeLabels = array_map('strtolower', $rule->exclude_labels);
            if (! empty(array_intersect($labels, $excludeLabels))) {
                return false;
            }
        }

        if ($rule->unassigned_only && ! empty($issueData['assignee'])) {
            return false;
        }

        return true;
    }

    public function isAutoAccepted(array $issueData, FilterRule $rule): bool
    {
        if (empty($rule->auto_accept_labels)) {
            return false;
        }

        $labels = array_map('strtolower', $issueData['labels'] ?? []);
        $autoAcceptLabels = array_map('strtolower', $rule->auto_accept_labels);

        return ! empty(array_intersect($labels, $autoAcceptLabels));
    }

    public static function validateNoConflict(array $includeLabels, array $excludeLabels): void
    {
        $include = array_map('strtolower', $includeLabels);
        $exclude = array_map('strtolower', $excludeLabels);
        $overlap = array_intersect($include, $exclude);

        if (! empty($overlap)) {
            throw ValidationException::withMessages([
                'exclude_labels' => 'Labels cannot appear in both include and exclude: ' . implode(', ', $overlap),
            ]);
        }
    }

    public function applyToSync(array $issueData, Source $source): ?Issue
    {
        $attributes = $this->evaluate($issueData, $source);

        if ($attributes === null) {
            return null;
        }

        $issue = Issue::firstOrCreate(
            ['source_id' => $source->id, 'external_id' => $issueData['external_id']],
            $attributes,
        );

        if ($issue->wasRecentlyCreated && ($attributes['auto_accepted'] ?? false)) {
            app(OrchestratorService::class)->startRun($issue);
        }

        return $issue;
    }

    private function buildIssueAttributes(array $issueData, Source $source, bool $autoAccepted): array
    {
        return [
            'source_id' => $source->id,
            'external_id' => $issueData['external_id'],
            'title' => $issueData['title'],
            'body' => $issueData['body'] ?? null,
            'external_url' => $issueData['external_url'] ?? null,
            'assignee' => $issueData['assignee'] ?? null,
            'labels' => $issueData['labels'] ?? [],
            'status' => $autoAccepted ? IssueStatus::Accepted : IssueStatus::Queued,
            'auto_accepted' => $autoAccepted,
            'repository_id' => $issueData['repository_id'] ?? null,
        ];
    }
}
