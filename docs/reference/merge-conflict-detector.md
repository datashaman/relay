---
type: reference
title: Merge Conflict Detector
created: 2026-04-18
tags: [reference, service, git, merge]
related:
  - "[[worktree-service]]"
  - "[[orchestrator]]"
  - "[[release]]"
  - "[[stuck-states]]"
---

# Merge Conflict Detector

**Service:** `App\Services\MergeConflictDetector`

## Purpose

Periodically dry-runs a merge of each active run's target branch into its worktree branch to surface conflicts before Release tries to land the PR. Aborts the merge immediately after so the worktree is left untouched.

## Outcomes

Returned from `probe()`:

| Constant | Meaning |
|---|---|
| `OUTCOME_CLEAN` (`clean`) | Dry-run merge succeeded — no conflicts. Clears prior conflict flags on the run. |
| `OUTCOME_CONFLICT` (`conflict`) | Merge failed with unmerged paths. Sets `has_conflicts`, records `conflict_files`, emits a `conflict_detected` stage event on the latest stage. |
| `OUTCOME_SKIPPED` (`skipped`) | Run is not probeable: wrong status, missing worktree, no repository, or a stage is actively running. `reason` describes which. |
| `OUTCOME_ERROR` (`error`) | `git fetch` or `git merge` failed for a reason other than a conflict (e.g. network, branch missing). `reason` carries the stderr. |

## Public API

| Method | Purpose |
|---|---|
| `probe(Run)` | Dry-run a merge for one run. Always aborts the merge afterwards. |
| `probeAllActive()` | Iterates `Run::query()->active()->whereNotNull('worktree_path')->whereNotNull('branch')` and probes each. Catches `Throwable` per run and returns outcome summaries. |

## Probing rules

- Run must be `pending`, `running`, or `stuck`.
- `run.worktree_path` and a linked `Repository` must exist.
- No stage may be in status `Running` — that guard prevents probing while an agent holds the worktree.
- The target branch is `repository.default_branch`, falling back to `'main'`.
- `git merge --abort` is only attempted when `MERGE_HEAD` exists.

## Inputs

- `Run` model (with `repository`, `stages`, `issue.repository`).
- The on-disk git worktree at `run.worktree_path`.

## Outputs

- Mutations on `runs`: `has_conflicts`, `conflict_detected_at`, `conflict_files`.
- `StageEvent` rows: `conflict_detected` (first occurrence), `conflict_cleared` (when a previously-conflicted run comes back clean).
- Return value from `probe()` / `probeAllActive()` summarizing outcome.

## Shell commands issued

All run with a 60-second timeout via `Illuminate\Support\Facades\Process`:

- `git rev-parse -q --verify MERGE_HEAD` (guard)
- `git merge --abort` (cleanup)
- `git fetch origin <target>`
- `git merge --no-commit --no-ff origin/<target>`
- `git diff --name-only --diff-filter=U` (list conflicts)

## Collaborators

**Upstream (callers):**

- Scheduled command(s) / Livewire UI that invoke `probeAllActive()`.

**Downstream (dependencies):**

- `App\Models\Run`, `App\Models\StageEvent`, `App\Enums\StageStatus`.
- `Illuminate\Support\Facades\Process`, `Illuminate\Support\Facades\Log` (used for unexpected probe errors, not for per-run outcomes).

## Error modes

- Fetch fails — `OUTCOME_ERROR`, reason includes `fetch_failed: <stderr>`.
- Merge fails with no conflict files — treated as `OUTCOME_ERROR` (`merge_failed: <stderr>`); usually means the target branch vanished.
- `probeAllActive()` catches per-run `Throwable` and records it as `OUTCOME_ERROR` so one broken run can't stop the batch.

## Not yet emitted

`MergeConflictDetector` predates Phase 04's `PipelineLogger`. It writes via `Illuminate\Support\Facades\Log::warning` for probe exceptions; outcome details live on the `StageEvent` and on the `runs` table rather than in the `pipeline` channel. Adding structured `PipelineLogger::event($run, 'merge_conflict.*')` calls is a future enhancement.

## See also

- [[worktree-service]] — manages the underlying worktree state probed here.
- [[release]] — consumer of the conflict flag.
- [[stuck-states]] — related recovery surfaces for runs that can't advance.
