---
type: reference
title: Escalation Rule Service
created: 2026-04-18
tags: [reference, service, autonomy, escalation]
related:
  - "[[autonomy-resolver]]"
  - "[[orchestrator]]"
  - "[[filter-rules]]"
  - "[[configuration]]"
---

# Escalation Rule Service

**Service:** `App\Services\EscalationRuleService`

## Purpose

Takes the base autonomy level from [[autonomy-resolver]] and *tightens* it at runtime based on issue and stage context. Used by the orchestrator on every stage transition to decide whether the stage should pause for approval or auto-advance.

## Rule shape

Each `EscalationRule`:

| Field | Meaning |
|---|---|
| `name` | Human-readable label shown in the UI. |
| `order` | Evaluation order (ascending). |
| `is_enabled` | Soft toggle. |
| `condition.type` | `label_match` \| `file_path_match` \| `diff_size` \| `touched_directory_match`. |
| `condition.value` | Type-specific value (label string, glob pattern, integer threshold, directory path). |
| `condition.operator` | Only used by `diff_size`: `>`, `>=`, `<`, `<=`, `=` — default `>=`. |
| `target_level` | The `AutonomyLevel` to tighten to when the rule matches. |

## Condition types

| Type | Context key needed | Behavior |
|---|---|---|
| `label_match` | — (reads `issue.labels`) | case-insensitive label present on the issue. |
| `file_path_match` | `context.files: string[]` | `fnmatch(pattern, file)` match. |
| `diff_size` | `context.diff_size: int` | numeric comparison. |
| `touched_directory_match` | `context.directories: string[]` | exact match or `str_starts_with($dir, $target.'/')`. |

Rules without the required context silently return `false`. Orchestrator callers are responsible for populating `context.files` / `directories` / `diff_size` when relevant.

## Public API

| Method | Purpose |
|---|---|
| `resolveWithEscalation(issue, stage, context, stageModel?)` | The main entry. Returns the effective `AutonomyLevel` (base, or the tightest matched target if that is tighter). When a `stageModel` is passed, records a `StageEvent(type: escalation_matched)` listing which rules fired. |
| `evaluateRules(issue, context)` | Returns `EscalationRule[]` that matched. |
| `matchesCondition(rule, issue, context)` | Predicate for a single rule. |

## Tightening rule

The tightest of the matched `target_level`s is chosen, and then compared against the base level: the **tightest** of (matched-tightest, base) wins. A rule can never *loosen* — if the base is already tighter than every match, the base is kept.

## Inputs

- `Issue`, `StageName`, `context` array, optional `Stage` model (needed only for recording).

## Outputs

- `AutonomyLevel`.
- Optional `StageEvent` row (`type: escalation_matched`) on the supplied `Stage`.

## Collaborators

**Upstream (callers):**

- `App\Services\OrchestratorService::transitionStage()` — on every stage transition.

**Downstream (dependencies):**

- `App\Services\AutonomyResolver` — base level.
- `App\Models\EscalationRule`, `StageEvent`.
- `App\Enums\AutonomyLevel`, `StageName`.

## Error modes

- No enabled rules — returns the base level unchanged.
- Missing context keys for conditional types — that rule matches `false` (silent).
- Unknown `condition.type` — matches `false`.

## Not emitted

`EscalationRuleService` does not emit `PipelineLogger` events. The orchestrator's `stage_started` / `stage_awaiting_approval` entries carry the resolved `autonomy_level`, which reflects escalation already.

## See also

- [[autonomy-resolver]] — base-level resolution.
- [[orchestrator]] — integration point.
- [[filter-rules]] — intake autonomy (a separate decision made before the run exists).
