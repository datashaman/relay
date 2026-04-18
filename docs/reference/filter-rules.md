---
type: reference
title: Filter Rule Service
created: 2026-04-18
tags: [reference, service, intake, filter]
related:
  - "[[orchestrator]]"
  - "[[escalation-rules]]"
  - "[[autonomy-resolver]]"
  - "[[configuration]]"
---

# Filter Rule Service

**Service:** `App\Services\FilterRuleService`

## Purpose

Decides what to do with every incoming issue during source sync: accept it, queue it for manual triage, auto-accept and kick off a run, or drop it. Driven by a single `FilterRule` per `Source`.

## Rule shape

Each `Source` has at most one `FilterRule` with these fields:

| Field | Type | Meaning |
|---|---|---|
| `include_labels` | string[] | If set, the issue must have at least one of these labels. |
| `exclude_labels` | string[] | If any of these labels is present, the issue is dropped. |
| `unassigned_only` | bool | If true, issues with an `assignee` are dropped. |
| `auto_accept_labels` | string[] | If the issue has any of these labels, it is stored as `Accepted` with `auto_accepted: true`. |

Label comparisons are case-insensitive. `include` and `exclude` must not overlap — `validateNoConflict()` guards this at save time.

## Public API

| Method | Purpose |
|---|---|
| `evaluate(issueData, source)` | Returns the issue attribute array (ready for `Issue::create`), or `null` if the rule rejects the issue. |
| `matchesFilters(issueData, rule)` | Pure predicate — include/exclude/assignee checks. |
| `isAutoAccepted(issueData, rule)` | Pure predicate — auto-accept labels check. |
| `applyToSync(issueData, source)` | Full intake path: evaluate → `Issue::firstOrCreate` → if new + auto-accepted + has repository, `OrchestratorService::startRun($issue)`. |
| `validateNoConflict(includeLabels, excludeLabels)` | Throws `ValidationException` if include/exclude overlap. |

## Inputs

- `issueData`: `['external_id', 'title', 'body'?, 'external_url'?, 'assignee'?, 'labels'?, 'raw_status'?, 'repository_id'?, 'component_id'?]`. Source-specific sync services produce this shape from GitHub / Jira payloads.
- `source`: `App\Models\Source` (the connected source owning the rule).

## Outputs

- `null` (rule rejects) or an attribute array for `Issue::firstOrCreate`.
- Side effect of `applyToSync`: an `Issue` row + optionally a new `Run` when the issue is auto-accepted.

## Status mapping

- Matched + auto-accept label → `IssueStatus::Accepted`, `auto_accepted = true`.
- Matched + no auto-accept label → `IssueStatus::Queued`, `auto_accepted = false`.
- Unmatched → no issue is created.
- No filter rule on the source → every synced issue becomes `Queued` (auto-accept is opt-in).

## Collaborators

**Upstream (callers):**

- `App\Services\GitHubClient`, `App\Services\JiraClient` (sync paths) — call `applyToSync`.
- `App\Livewire\Config\FilterRulesPanel` — `validateNoConflict` when persisting edits.

**Downstream (dependencies):**

- `App\Models\FilterRule`, `App\Models\Issue`, `App\Models\Source`.
- `App\Services\OrchestratorService::startRun()` — auto-start for freshly-created auto-accepted issues with a linked repository.

## Error modes

- Missing `external_id` / `title` — the calling sync service is expected to sanitize; `firstOrCreate` will throw on a null `external_id` due to DB constraints.
- Include/exclude label overlap — `validateNoConflict` throws `ValidationException`.
- No repository linked on an auto-accepted issue — the run is *not* started, the issue stays `Accepted` awaiting a manual run kickoff.

## Not emitted

`FilterRuleService` does not emit `PipelineLogger` events — it runs before a `Run` exists. Sync-level logging is the responsibility of the connecting client (`GitHubClient` / `JiraClient`).

## See also

- [[escalation-rules]] — runtime tightening layered on top of autonomy decisions.
- [[autonomy-resolver]] — autonomy level resolution (separate concern: filters decide *whether* a run starts, autonomy decides *how* it proceeds).
