---
type: reference
title: Verify Agent
created: 2026-04-18
tags: [reference, agent, verify]
related:
  - "[[preflight]]"
  - "[[implement]]"
  - "[[release]]"
  - "[[orchestrator]]"
  - "[[configuration]]"
---

# Verify Agent

**Service:** `App\Services\VerifyAgent`
**Stage:** `StageName::Verify`
**Color:** Green `#639922`

## Purpose

Runs tests, static analysis, and coverage diffs against the implement agent's output. Enforces quality gates before release. Cannot edit files — read-only access to the worktree.

## Inputs

- `Stage` with `run->worktree_path`.
- `Run::$preflight_doc` — supplies acceptance criteria.
- The uncommitted diff at `run->worktree_path` (read via `git diff`).

## Outputs

- `StageEvent` rows: `verify_started`, `tool_call`, `test_results`, `static_analysis_results`, `verify_complete`, `verify_no_tool_call`, `verify_loop_limit`.
- `TestResultUpdated` events broadcast to the UI.
- Terminal transition: `OrchestratorService->complete(Stage)` on pass, `->bounce(Stage, failureReport)` on failure, `->fail(Stage, reason)` on loop exhaustion / missing worktree.

## Tools

| Tool | Description |
|------|-------------|
| `run_tests` | Execute the configured test suite. Runner auto-detected: checks `vendor/bin/` for pest/phpunit and `node_modules/.bin/` for jest/mocha/vitest. |
| `run_static_analysis` | Run static analysis tools against the codebase. |
| `coverage_diff` | Compare test coverage before and after the implement agent's changes. |
| `read_file` | Read a file's contents (read-only). |
| `list_files` | List files in a directory. |
| `run_shell` | Execute a shell command (read-only context). |
| `git_diff` | Show the diff of changes made by the implement agent. |
| `verification_complete` | Signal verification result (pass or fail with structured report). |

## Behavior

1. Receives the run context including the preflight document and implement diff.
2. Runs the test suite and static analysis.
3. Compares coverage metrics.
4. If all gates pass: signals `verification_complete` with pass → orchestrator advances to Release.
5. If any gate fails: emits a structured failure report and signals fail → orchestrator bounces back to Implement.

## Failure Report Structure

When verification fails, the report includes:

- Test name
- Assertion / error message
- File path
- Line number

This report travels back to the implement agent as a patch target on bounce.

## Emitted log events

| `event` value | Level | Additional fields |
|---|---|---|
| `verify.execute_started` | info | `stage`, `iteration` |
| `verify.test_results` | info | `stage`, `runner`, `exit_code`, `status` (`passed`/`failed`) |
| `verify.static_analysis_results` | info | `stage`, `analyzer`, `exit_code`, `status` |
| `verify.complete` | info | `stage`, `iteration`, `passed`, `failure_count` |
| `verify.loop_limit` | info | `stage`, `iteration`, `max_loops` |
| `stage_started` / `stage_completed` / `stage_bounced` / `stage_failed` | info / info / info / error | orchestrator-emitted boundary events |
| `ai_call` / `ai_error` | info / error | per provider request with `log_context.purpose` implied by the tool context |

Grep recipe: `jq 'select(.event | startswith("verify."))' storage/logs/pipeline-*.log`.

## Collaborators

**Upstream (callers):**

- `App\Jobs\ExecuteStageJob`.

**Downstream (dependencies):**

- `App\Services\AiProviderManager` — provider for `Verify`.
- `App\Services\OrchestratorService` — `complete()`, `bounce()`, `fail()`.
- `App\Support\Logging\PipelineLogger`, `App\Models\StageEvent`, `App\Events\TestResultUpdated`.

## Error modes

- Missing `worktree_path` — immediate `fail()`.
- No test runner detected — the `run_tests` tool returns an `Error:` string; the model can still complete with a pass if no tests exist, but the typical outcome is a structured failure.
- Tool-loop exhaustion — `fail()` after `MAX_TOOL_LOOPS`.
- Any non-zero exit from `run_tests` or `run_static_analysis` — the tool returns a `FAILED` string; the model should signal `verification_complete` with `passed: false`.

## Constraints

- Cannot edit or write files.
- Cannot push or create PRs.
- Read-only access to the worktree.
