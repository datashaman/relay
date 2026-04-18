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

    public function markClosed(Source $source, string $externalId, ?string $stateReason = null): ?Issue
    {
        $issue = Issue::where('source_id', $source->id)
            ->where('external_id', $externalId)
            ->first();

        if (! $issue) {
            return null;
        }

        $rawStatus = $stateReason !== null ? 'closed:'.$stateReason : 'closed';

        $transitioned = Issue::where('id', $issue->id)
            ->where('status', IssueStatus::Queued)
            ->update(['status' => IssueStatus::Rejected, 'raw_status' => $rawStatus]);

        if ($transitioned === 0) {
            // Local pipeline state (Accepted / InProgress / Completed / Failed / Stuck)
            // wins — don't clobber it, just record the upstream close.
            Issue::where('id', $issue->id)->update(['raw_status' => $rawStatus]);
        }

        return $issue->fresh();
    }

    public function markReopened(Source $source, string $externalId): ?Issue
    {
        $issue = Issue::where('source_id', $source->id)
            ->where('external_id', $externalId)
            ->first();

        if (! $issue) {
            return null;
        }

        $syncDrivenClose = $issue->raw_status !== null
            && (str_starts_with($issue->raw_status, 'closed') || $issue->raw_status === 'deleted');

        if ($issue->status === IssueStatus::Rejected && $syncDrivenClose) {
            Issue::where('id', $issue->id)->update([
                'status' => IssueStatus::Queued,
                'raw_status' => null,
            ]);
        }

        return $issue->fresh();
    }

    public function markDeleted(Source $source, string $externalId): ?Issue
    {
        $issue = Issue::where('source_id', $source->id)
            ->where('external_id', $externalId)
            ->first();

        if (! $issue) {
            return null;
        }

        $rejected = Issue::where('id', $issue->id)
            ->where('status', IssueStatus::Queued)
            ->update(['status' => IssueStatus::Rejected, 'raw_status' => 'deleted']);

        if ($rejected === 0) {
            Issue::where('id', $issue->id)->update(['raw_status' => 'deleted']);
        }

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
