<?php

namespace App\Services;

use App\Enums\IssueStatus;
use App\Models\Component;
use App\Models\Issue;
use App\Models\Source;

class IssueIntakeService
{
    public function __construct(
        private FilterRuleService $filterService,
    ) {}

    public function upsertIssue(Source $source, array $issueData): ?Issue
    {
        $existing = Issue::where('source_id', $source->id)
            ->where('external_id', $issueData['external_id'])
            ->first();

        if ($existing) {
            $this->updateExistingIssue($existing, $issueData);

            return $existing->fresh();
        }

        return $this->filterService->applyToSync($issueData, $source);
    }

    public function markDeleted(Source $source, string $externalId): ?Issue
    {
        $issue = Issue::where('source_id', $source->id)
            ->where('external_id', $externalId)
            ->first();

        if (! $issue) {
            return null;
        }

        $changes = ['raw_status' => 'deleted'];

        if ($issue->status === IssueStatus::Queued) {
            $changes['status'] = IssueStatus::Rejected;
        }

        $issue->update($changes);

        return $issue->fresh();
    }

    public function resolveComponentId(Source $source, array $attrs): ?int
    {
        $externalId = $attrs['component_external_id'] ?? null;
        $name = $attrs['component_name'] ?? null;

        if (! $externalId) {
            return null;
        }

        $component = Component::firstOrCreate(
            ['source_id' => $source->id, 'external_id' => $externalId],
            ['name' => $name ?? $externalId],
        );

        if ($name !== null && $component->name !== $name) {
            $component->update(['name' => $name]);
        }

        return $component->id;
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
}
