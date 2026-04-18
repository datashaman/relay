---
type: reference
title: Worktree Service
created: 2026-04-18
tags: [reference, service, git, worktree]
related:
  - "[[orchestrator]]"
  - "[[merge-conflict-detector]]"
  - "[[implement]]"
  - "[[release]]"
  - "[[configuration]]"
---

# Worktree Service

**Service:** `App\Services\WorktreeService`

## Purpose

Manages per-run git worktrees: ensures the repository is cloned, creates a disposable worktree on a fresh branch for each run, optionally runs setup/teardown/run scripts, and garbage-collects stale worktrees left behind by crashed runs.

## Path conventions

- Repository clone: `config('relay.repos_root') . '/' . repository.name` (e.g. `.../relay-repos/owner/repo`).
- Per-run worktree: `repository.worktree_root ?? repository.path` + `/relay-<run-id>`.
- Per-run branch: `run.branch` if set, else `relay/run-<run-id>`.

## Public API

| Method | Purpose |
|---|---|
| `createWorktree(Run, Repository): string` | Ensures the repo is cloned, runs `git worktree add -b <branch> <path> <default_branch>`, stores `worktree_path` + `branch` on the run, optionally runs `repository.setup_script`. Returns the worktree path. |
| `ensureCloned(Repository, ?Source)` | Lazily `git clone` the repository into `repos_root` if `repository.path` is not set. Records `path` + `default_branch`. |
| `removeWorktree(Run, Repository)` | Runs `repository.teardown_script`, then `git worktree remove --force` and clears `run.worktree_path`. |
| `runRunScript(Run, Repository)` | Executes `repository.run_script` inside the worktree; returns stdout+stderr. No-op if no script or no worktree. |
| `recoverStaleWorktrees(Repository): string[]` | `git worktree list --porcelain`, removes any `/relay-<id>$` worktrees whose associated run either no longer exists or no longer points at that path. Returns the paths recovered. |

## Script environment

Each script (`setup_script`, `teardown_script`, `run_script`) runs via `sh -c` inside the worktree with:

- `RELAY_RUN_ID`
- `RELAY_ISSUE_ID`
- `RELAY_BRANCH`
- `RELAY_WORKTREE`

300-second timeout. Output (stdout+stderr) is recorded on the latest stage as a `script_output` `StageEvent` with `exit_code`. Non-zero exits rethrow via `Process::throw()`.

## Clone URL resolution

`buildCloneUrl(Repository, ?Source)` picks:

1. If the source is `github` and has an OAuth token → `https://x-access-token:<token>@github.com/<name>.git`.
2. Otherwise → `git@github.com:<name>.git` (SSH fallback; relies on a host-configured agent).

Token leaks in error output are scrubbed: `x-access-token:<anything>@` → `x-access-token:***@`.

## Inputs

- `Run`, `Repository`, optional `Source` (for clone credentials).
- Shell environment (git, optional ssh agent).
- `config('relay.repos_root')`.

## Outputs

- Updated `repositories.path` / `default_branch` on first clone.
- Updated `runs.worktree_path` / `branch` on create.
- Cleared `runs.worktree_path` on remove.
- `StageEvent(type: 'script_output')` rows for each script invocation.
- On-disk git worktrees.

## Collaborators

**Upstream (callers):**

- `App\Services\OrchestratorService::startRun()` — `createWorktree`.
- Cleanup commands (`recoverStaleWorktrees`).
- Release flow when `repository.teardown_script` is configured (`removeWorktree`).

**Downstream (dependencies):**

- `Illuminate\Support\Facades\Process` — all git invocations.
- `App\Models\Repository`, `App\Models\Run`, `App\Models\Source`, `App\Models\StageEvent`.

## Error modes

- `git clone` fails — `RuntimeException("git clone failed (exit N). stderr: ...")` with tokens scrubbed.
- `git worktree add` / `remove` fails — `Process::throw()` raises `ProcessFailedException`. Orchestrator catches this in `startRun()` and fails the run with `failRunImmediately()`.
- Script exits non-zero — `Process::throw()` raises, caller must handle. The `script_output` event is written **before** the throw.

## Not emitted

`WorktreeService` does not emit `PipelineLogger` events. All structured logging happens one layer up in the orchestrator (`run_started`, `stage_failed` with the worktree-failure reason).

## See also

- [[merge-conflict-detector]] — consumes the worktree to probe merges.
- [[orchestrator]] — lifecycle integration.
- [[configuration]] — `RELAY_REPOS_ROOT`, per-repository scripts.
