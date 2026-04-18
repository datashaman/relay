---
type: reference
title: Implement Agent
created: 2026-04-18
tags: [reference, agent, implement]
related:
  - "[[preflight]]"
  - "[[verify]]"
  - "[[release]]"
  - "[[orchestrator]]"
  - "[[worktree-service]]"
  - "[[configuration]]"
---

# Implement Agent

**Service:** `App\Services\ImplementAgent`
**Stage:** `StageName::Implement`
**Color:** Amber `#BA7517`

## Purpose

Produces a code diff inside the run's git worktree based on the preflight document. Operates in a tool-call loop: chat → execute tools → append results → chat again until signaling `implementation_complete`.

## Inputs

- `Stage` with `run->worktree_path` populated — the stage fails fast if the worktree is missing.
- `context['failure_report']` — present on bounce iterations; prepended to the prompt as "Previous Verification Failure".
- `Run::$preflight_doc` — the primary instruction surface.
- Resolved `AiProvider` for `StageName::Implement`.

## Outputs

- A set of edits to files under `run->worktree_path` (not committed — Release handles the commit).
- `StageEvent` rows: `implement_started`, `tool_call` (per tool), `implement_complete`, `implement_no_tool_call`, `implement_loop_limit`.
- Live `DiffUpdated` events broadcast as the worktree mutates (after every `write_file` and at completion).
- Terminal call to `OrchestratorService->complete(Stage)` on success, or `->fail(Stage, reason)` on loop exhaustion / missing worktree.

## Tools

| Tool | Description |
|------|-------------|
| `read_file` | Read a file's contents. Path must be within the worktree. |
| `write_file` | Write or overwrite a file. Path must be within the worktree. |
| `list_files` | List files in a directory within the worktree. |
| `run_shell` | Execute a shell command in the worktree. Timeouts and output caps enforced. |
| `run_linter` | Run the configured linter against specified files. |
| `git_status` | Show the current git status of the worktree. |
| `git_diff` | Show the current diff of uncommitted changes. |
| `implementation_complete` | Signal that implementation is done. Terminates the tool loop. |

## Behavior

1. Receives the preflight document as context (not the original issue).
2. On bounced iterations, receives the verify failure report prepended to context.
3. Reads relevant files, makes edits, runs linter checks.
4. Signals completion via `implementation_complete`.
5. Live diff updates broadcast via `DiffUpdated` event.

## Loop limits

`ImplementAgent::MAX_TOOL_LOOPS` caps the chat-execute-chat cycle. On exceeding the cap, the stage fails with `Implement agent exceeded maximum tool call loops.` — the run then transitions through `OrchestratorService->fail()` and ends in `RunStatus::Failed`.

## Emitted log events

All events go to the `pipeline` channel via `PipelineLogger`; `run_id` and `issue_id` are included in every entry.

| `event` value | Level | Additional fields |
|---|---|---|
| `implement.execute_started` | info | `stage`, `iteration`, `has_failure_context` |
| `implement.complete` | info | `stage`, `iteration`, `files_changed_count` |
| `implement.loop_limit` | error-adjacent (info emit, stage_failed follows) | `stage`, `iteration`, `max_loops` |
| `stage_started` / `stage_completed` / `stage_failed` | info / info / error | emitted by the orchestrator around `execute()` |
| `stage_bounced` | info | emitted by the orchestrator if Verify bounces this stage back |
| `ai_call` / `ai_error` | info / error | per-provider-request; `log_context.loop` carries the tool-loop iteration |

Grep recipe: `jq 'select(.event | startswith("implement."))' storage/logs/pipeline-*.log`.

## Collaborators

**Upstream (callers):**

- `App\Jobs\ExecuteStageJob` — entry point.

**Downstream (dependencies):**

- `App\Services\AiProviderManager` — provider for `Implement` stage.
- `App\Services\OrchestratorService` — `complete()`, `fail()`.
- `App\Services\WorktreeService` — indirectly, via the `worktree_path` set on the run by `OrchestratorService->startRun()`.
- `App\Support\Logging\PipelineLogger`, `App\Models\StageEvent`, `App\Events\DiffUpdated`.

## Error modes

- Missing `worktree_path` — immediate `fail()` with `No worktree path configured for this run.`.
- Tool-loop exhaustion — `fail()` after `MAX_TOOL_LOOPS` iterations.
- AI provider exception — bubbles up; handled by `ExecuteStageJob`.
- Blocked shell command — `run_shell` returns an `Error:` string, the model can recover.
- Path escape in `read_file` / `write_file` / `list_files` — returned as an `Error:` string; the tool call is recorded with `success: false`.

## Constraints

- All file operations scoped to the run's worktree — path escape attempts rejected.
- Cannot run test suites (phpunit, pest, jest, mocha, pytest, rspec).
- Cannot push or create PRs (`git push`, `gh pr` blocked).
- Shell command timeouts and output size caps enforced.
- Cannot run `rm -rf /`.

## Blocked Commands

Test runners: `phpunit`, `pest`, `jest`, `mocha`, `pytest`, `rspec`
Git operations: `git push`, `git remote`, `gh pr`
Destructive: `rm -rf /`
