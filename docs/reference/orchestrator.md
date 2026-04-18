---
type: reference
title: Orchestrator Service
created: 2026-04-18
tags: [reference, service, orchestrator]
related:
  - "[[preflight]]"
  - "[[implement]]"
  - "[[verify]]"
  - "[[release]]"
  - "[[autonomy-resolver]]"
  - "[[escalation-rules]]"
  - "[[worktree-service]]"
  - "[[stuck-states]]"
  - "[[configuration]]"
---

# Orchestrator Service

**Service:** `App\Services\OrchestratorService`

## Purpose

Drives the pipeline state machine. Creates runs, stages, and enforces autonomy/escalation gates at every transition. Every agent terminates by calling back into this service.

## Stage order

Fixed: `Preflight → Implement → Verify → Release`. Defined by the constant `STAGE_ORDER`.

## Public API

| Method | Purpose |
|---|---|
| `startRun(Issue, ?Repository, context)` | Create a `Run` + first `Stage` (Preflight), create the git worktree, dispatch the stage job. Returns the `Run`. |
| `startStage(Stage, context)` | Re-enter `transitionStage()` for an existing stage (used on resume paths). |
| `pause(Stage)` | Mark stage `AwaitingApproval`, broadcast. |
| `resume(Stage, context)` | Return from `AwaitingApproval` → `Running`, dispatch `ExecuteStageJob`. |
| `bounce(Stage, failureReport)` | Verify-failed path. Marks current stage `Bounced`, increments `run.iteration`, opens a new Implement stage with the failure report — or marks the run stuck (`StuckState::IterationCap`) if the cap is reached. |
| `complete(Stage, context)` | Mark stage `Completed`, advance to the next stage (or complete the run after Release). |
| `fail(Stage, ?reason)` | Mark stage and run `Failed`. |
| `markStuck(Stage, StuckState, context)` | Mark stage + run `Stuck` with a specific [[stuck-states]] value; dispatch `RunStuck`. |
| `giveGuidance(Run, guidance)` | User-provided unstick. Opens a fresh copy of the latest stage with the guidance attached. |
| `retryStage(Stage)` | Re-run the same stage at the same iteration. |
| `restart(Run)` | Clear stuck flags and re-enter the latest stage. |

## Inputs

- `Issue`, `Repository` (either passed or resolved from `issue->repository`), `Stage` — all Eloquent models.
- `config('relay.iteration_cap')` — default `5`. Used in `bounce()`.

## Outputs

- Mutations to `runs.*`, `stages.*`, `issues.status`.
- `StageEvent` rows capturing every transition (`started`, `paused`, `resumed`, `bounced`, `completed`, `failed`, `stuck`, `awaiting_approval`, `restarted`, `retried`, `guidance_received`).
- Laravel events: `StageTransitioned` (every status change), `RunStuck` (when a run enters `Stuck`).
- Job dispatches: `ExecuteStageJob` on every transition to `Running`.

## Autonomy integration

Before each `Running` transition, `transitionStage()` calls `EscalationRuleService::resolveWithEscalation(issue, stage, context, stage)`. The returned `AutonomyLevel` is recorded on the started/awaiting event. If it is `Manual` or `Supervised`, the stage pauses for approval instead of dispatching.

## Emitted log events

All events via `App\Support\Logging\PipelineLogger` on the `pipeline` channel.

| `event` value | Level | Notes |
|---|---|---|
| `run_started` | info | At `startRun`, after the worktree is created. |
| `stage_started` | info | Emitted by `PipelineLogger::stageStarted` in `transitionStage()`. |
| `stage_completed` | info | Emitted by `stageCompleted` in `complete()`. |
| `stage_bounced` | info | Emitted in `bounce()`. |
| `stage_awaiting_approval` | info | Emitted when autonomy gates pause the stage. |
| `stage_failed` | error | Emitted in `failStage()`, `markStuck()`, and `failRunImmediately()`. |
| `run_completed` | info | Emitted when Release completes. |

## Collaborators

**Upstream (callers):**

- `App\Livewire\*` (intake UI + config panels) — `startRun`, `resume`, `retryStage`, `restart`, `giveGuidance`.
- `App\Services\FilterRuleService::applyToSync` — auto-starts runs for `auto_accepted` issues.
- `App\Jobs\ExecuteStageJob` — calls `complete()`, `fail()`, `bounce()`, `pause()`, `markStuck()` from inside agents.
- `App\Console\Commands\*` for stuck-state recovery.

**Downstream (dependencies):**

- `App\Services\EscalationRuleService` → `AutonomyResolver` for autonomy resolution.
- `App\Services\WorktreeService` for `createWorktree()`.
- `App\Jobs\ExecuteStageJob` dispatch.
- `PipelineLogger`, `StageEvent`, `StageTransitioned`, `RunStuck`.

## Error modes

- `WorktreeService::createWorktree()` throws — `startRun()` calls `failRunImmediately()`; the run ends in `Failed` before Preflight executes.
- Issue has no repository — same path, reason `Issue is not linked to a repository.`.
- Bounce without a previous stage — impossible in the current stage order (Preflight has no predecessor), but the code guards with `failStage()` anyway.
- `bounce()` with `run.iteration >= iteration_cap` — marks stuck with `StuckState::IterationCap` rather than creating another Implement stage.

## See also

- [[autonomy-resolver]] — base-level resolution.
- [[escalation-rules]] — runtime tightening.
- [[stuck-states]] — the four `StuckState` values the orchestrator can set.
- [[worktree-service]] — worktree lifecycle.
