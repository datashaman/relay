---
type: analysis
title: Phase 04 Observability — Orientation & Impact
created: 2026-04-18
tags:
  - hardening-pass
  - observability
  - logging
  - phase-04
related:
  - '[[Phase-04-Observability]]'
---

# Phase 04 — Observability Orientation Notes

Orientation pass before instrumenting the Preflight → Implement → Verify → Release
pipeline with structured logging. Captures stage boundaries, existing log surface,
AI-provider shape, and blast radius for the orchestrator so the next Phase 04
tasks can start from a known baseline.

## Stage Boundaries & Existing Log Surface

### `OrchestratorService` (`app/Services/OrchestratorService.php`)

Drives the stage machine. Every transition already emits a `StageEvent`
(persisted via `recordEvent()`) and broadcasts `StageTransitioned`. There are no
`Log::` calls at all today. Instrumentation hooks:

- `startRun()` — first entry, creates `Run`/first `Stage`, handles repo-missing
  and worktree-setup failures via `failRunImmediately()`.
- `transitionStage()` — the choke point: runs autonomy resolution, either pauses
  (awaiting_approval) or flips to `Running` and dispatches `ExecuteStageJob`.
- `complete()` / `fail()` / `failStage()` — terminal per-stage transitions;
  `completeRun()` is the pipeline-complete boundary.
- `bounce()` — Verify→Implement retry loop; increments `run->iteration`, honours
  `relay.iteration_cap`, and calls `markStuck()` when the cap trips.
- `markStuck()` — terminal stuck path with `StuckState` + `RunStuck` event.
- `resume()` / `retryStage()` / `restart()` / `giveGuidance()` — user-driven
  re-entries; all eventually funnel through `transitionStage()`.

These are the natural hooks for `PipelineLogger::stageStarted/Completed/Failed`
events in the next task. `recordEvent()` stays — DB events and structured logs
are complementary.

### Stage Agents (`app/Services/*Agent.php`)

All four agents already emit detailed `StageEvent`s via a private
`recordEvent()` (identical copy-paste across files). They do NOT write to the
logger today. Key sub-steps worth mirroring to the pipeline channel:

| Agent | Entry event | Sub-steps currently recorded | Exit/terminal |
|-------|-------------|------------------------------|---------------|
| `PreflightAgent` | (n/a — calls AI, then branches) | `assessment_complete`, `clarification_needed`, `doc_generated` | `orchestrator->complete()` or `->pause()` |
| `ImplementAgent` | `implement_started` | `tool_call` (per tool), `implement_no_tool_call`, `implement_complete`, `implement_loop_limit` | `orchestrator->complete()` / `->fail()` |
| `VerifyAgent` | `verify_started` | `tool_call`, `test_results`, `static_analysis_results`, `coverage_results`, `verify_no_tool_call`, `verify_complete`, `verify_loop_limit` | `orchestrator->complete()` on pass, `->bounce()` on fail |
| `ReleaseAgent` | `release_started` | `tool_call`, `pr_created`, `deploy_triggered`, `release_no_tool_call`, `release_complete`, `release_loop_limit` | `orchestrator->complete()` |

Each agent's `execute(Stage, array $context)` is the natural entry/exit boundary.
Failure paths today throw or call `$orchestrator->fail()` — those need
`PipelineLogger::stageFailed` with the exception class + message (no stack at
INFO, per the task brief).

## AI Provider Hooks for Token Usage

All four providers in `app/Services/AiProviders/` already normalize usage data
into `['input_tokens' => int, 'output_tokens' => int]` on the `chat()` return
payload. None currently log anything.

| Provider | Usage source (response) | Notes |
|----------|-------------------------|-------|
| `AnthropicProvider` | `$data['usage']['input_tokens']` / `output_tokens` | Single `chat()` path; `stream()` surfaces usage on `message_stop` |
| `OpenAiProvider` | `$data['usage']['prompt_tokens']` / `completion_tokens` | Already re-mapped to `input_tokens`/`output_tokens` in `normalizeResponse` |
| `GeminiProvider` | `$data['usageMetadata']['promptTokenCount']` / `candidatesTokenCount` | Re-mapped to `input_tokens`/`output_tokens` |
| `ClaudeCodeCliProvider` | `result` line in NDJSON: `$event['usage']['input_tokens']` / `output_tokens` | CLI subprocess; usage only emitted on the terminal `result` event |

**Signature note for task 3 (AI instrumentation):** the `AiProvider` contract
(`chat`/`stream`) already accepts `array $options = []` as the third arg. We can
thread `stage`/`run_id`/`issue_id` through `$options` without breaking the
signature — no `$context` param addition required. Worth verifying when that
task is picked up.

**Error path:** Anthropic/OpenAI/Gemini use `$response->throw()`, which raises
`Illuminate\Http\Client\RequestException`. `ClaudeCodeCliProvider` throws
`RuntimeException` with the exit code + stderr. Both are the right seams for a
single provider-layer error log.

## Logging Config

`config/logging.php` ships the stock Laravel channels (stack/single/daily/
slack/papertrail/stderr/syslog/errorlog/null). `bootstrap/providers.php` only
registers `AppServiceProvider` — no custom logging setup. A new `pipeline`
channel (`daily` driver → `storage/logs/pipeline-%date%.log`, JSON formatter
via Monolog tap, 14-day retention) is a clean addition; nothing to unwind.

## Blast-Radius — `OrchestratorService`

`gitnexus_impact({target: "OrchestratorService", direction: "upstream"})`:

- **Risk: MEDIUM** — 9 direct importers, no process-graph coverage.
- d=1 importers:
  - `app/Jobs/ResolveConflictsJob.php`
  - `app/Http/Controllers/IssueController.php`
  - `app/Http/Controllers/IssueViewController.php`
  - `resources/views/pages/⚡intake.blade.php`
  - `resources/views/pages/⚡preflight-clarification.blade.php`
  - `tests/Feature/OrchestratorServiceTest.php`
  - `tests/Feature/IssueViewTest.php`
  - `tests/Feature/ResolveConflictsJobTest.php`
  - `packages/nativephp-electron/.../PreflightController.php` (vendored copy)
- d=2: `routes/web.php` (+ the nativephp-electron vendored copy).

**How this shapes the next task:** we're adding calls (logger emits), not
changing method signatures on `OrchestratorService`, so the d=1 callers stay
unaffected at the source level. No HIGH/CRITICAL concerns to pause on; proceed
with instrumentation. The three feature tests (`OrchestratorServiceTest`,
`IssueViewTest`, `ResolveConflictsJobTest`) are the guardrails — rerun after
instrumenting.

## Self-Check Summary

- [x] Read all four stage agents + `OrchestratorService`
- [x] Read all four AI providers + `AiProviderManager`
- [x] Read `config/logging.php`, `bootstrap/providers.php`
- [x] Ran `gitnexus_context` on `OrchestratorService`
- [x] Ran `gitnexus_impact` upstream — MEDIUM risk, no HIGH/CRITICAL
- [x] Notes saved to `Auto Run Docs/Initiation/Working/impact-phase-04.md`

## Phase-04 Task 3 Impact Re-check

`gitnexus_impact({target: "transitionStage", direction: "upstream"})` returns
**CRITICAL** (7 direct callers, 5 affected processes, 28+ transitive tests). This
reflects the centrality of `transitionStage` as the orchestrator's choke point —
not added risk from this change. We are **only adding `PipelineLogger::*` calls**;
no method signatures or control flow change, so the d=1 callers and tests remain
unaffected at the API level. Proceed with instrumentation; rely on the existing
`OrchestratorServiceTest` suite as the regression guardrail.
