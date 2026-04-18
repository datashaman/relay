---
type: reference
title: Autonomy Resolver
created: 2026-04-18
tags: [reference, service, autonomy]
related:
  - "[[orchestrator]]"
  - "[[escalation-rules]]"
  - "[[filter-rules]]"
  - "[[configuration]]"
---

# Autonomy Resolver

**Service:** `App\Services\AutonomyResolver`

## Purpose

Resolves the **base** autonomy level for a given `(issue, stage)` pair by walking the configured scope cascade. The orchestrator wraps this with `EscalationRuleService::resolveWithEscalation()` to apply runtime rule-based tightening — see [[escalation-rules]].

## Scope cascade

For `resolve(issueId, stage)`, the first match wins:

1. `AutonomyConfig(scope: Issue, scope_id: issueId, stage: stage)` — issue + stage override.
2. `AutonomyConfig(scope: Issue, scope_id: issueId, stage: null)` — issue-wide override.
3. `AutonomyConfig(scope: Stage, scope_id: null, stage: stage)` — global stage default.
4. `AutonomyConfig(scope: Global, scope_id: null, stage: null)` — global default.
5. Hard-coded fallback: `AutonomyLevel::Supervised`.

## Autonomy levels

| Level | Auto-advance? | UI gate |
|---|---|---|
| `Manual` | No | Required approval on every transition |
| `Supervised` | No | Required approval |
| `Assisted` | Yes | Transition logged but proceeds |
| `Autonomous` | Yes | No gate |

## Public API

| Method | Purpose |
|---|---|
| `resolve(issueId, stage)` | The scope cascade above. Returns `AutonomyLevel`. |
| `getGlobalDefault()` | The `Global` row, or `Supervised`. |
| `validateAndSave(scope, scopeId, stage, level)` | Apply invariants, `updateOrCreate` the `AutonomyConfig`. |
| `validateInvariant(scope, scopeId, stage, level)` | Throws `ValidationException` if the invariant below is violated. |

## Invariants

- **Stage overrides must tighten from global.** A `Stage` row cannot set a looser level than the current `Global` default. `Manual < Supervised < Assisted < Autonomous` (tighter → looser).
- **Issue overrides must loosen from the effective stage level.** An `Issue` row cannot set a tighter level than the stage would already require. When `stage` is null, the tightest stage is used.

Both invariants throw `Illuminate\Validation\ValidationException` with a descriptive message on violation.

## Inputs

- `Issue::id`, `StageName` — resolution keys.
- Rows in the `autonomy_configs` table.

## Outputs

- An `AutonomyLevel` enum value, or a persisted `AutonomyConfig` row (via `validateAndSave`).

## Collaborators

**Upstream (callers):**

- `App\Services\EscalationRuleService::resolveWithEscalation()` — calls `resolve()` for the base level.
- `App\Livewire\Config\AutonomyPanel` — `validateAndSave`.

**Downstream (dependencies):**

- `App\Models\AutonomyConfig` — read / write.
- `App\Enums\AutonomyLevel`, `AutonomyScope`, `StageName`.

## Error modes

- Validation failures surface as `ValidationException` with field key `level`.
- No logging is emitted at this layer — `PipelineLogger` calls happen in the orchestrator.

## See also

- [[escalation-rules]] — runtime tightening layered on top of this resolver.
- [[filter-rules]] — intake autonomy (auto-accept vs queued).
- [[configuration]] — environment-level defaults.
