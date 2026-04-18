---
type: reference
title: Preflight Agent
created: 2026-04-18
tags: [reference, agent, preflight]
related:
  - "[[implement]]"
  - "[[verify]]"
  - "[[release]]"
  - "[[orchestrator]]"
  - "[[autonomy-resolver]]"
  - "[[configuration]]"
  - "[[stuck-states]]"
---

# Preflight Agent

**Service:** `App\Services\PreflightAgent`
**Stage:** `StageName::Preflight`
**Color:** Purple `#534AB7`

## Purpose

Front-loads known facts about an issue and produces a structured preflight document that serves as the contract for all downstream stages. The implement agent reads this document — not the original issue.

## Inputs

- `Stage` model (with `run`, `run->issue`, `run->worktree_path`) — supplied by `ExecuteStageJob`.
- `context` array — may contain `skip_to_doc` (used when clarification answers already exist).
- `Run::$clarification_answers` — user-supplied answers if the stage previously paused for clarification.
- The resolved `AiProvider` (`AiProviderManager->resolve(null, StageName::Preflight)`) — see [[anthropic]] / [[openai]] / [[gemini]] / [[claude-code-cli]].

## Outputs

- `Run::$known_facts` — array of extracted facts.
- `Run::$clarification_questions` — populated when the assessment is ambiguous.
- `Run::$preflight_doc` — the structured document consumed by Implement / Verify / Release.
- `Run::$preflight_doc_history` — prior versions preserved when the doc is regenerated.
- `StageEvent` rows: `assessment_complete`, `clarification_needed`, `doc_generated`.
- Terminal call to `OrchestratorService->pause(Stage)` (ambiguous path) or `->complete(Stage)` (clear path).

## Modes

- **Clear issue** — skips straight to producing the preflight doc.
- **Ambiguous issue** — enters a structured clarification loop (radio-button choices where possible), then produces the doc on the next invocation (triggered by `OrchestratorService->giveGuidance()` or `resume()`).

## Tools

| Tool | Description |
|------|-------------|
| `assess_issue` | Evaluates the issue and determines clear vs. ambiguous. Returns known facts and optional clarifying questions. |
| `generate_preflight_doc` | Produces the structured preflight document from the assessed issue and any user answers. |

## Preflight Document Sections

| Section | Purpose |
|---------|---------|
| Summary | One-paragraph synthesis |
| Requirements | What must be true after implementation |
| Acceptance Criteria | Numbered, testable conditions |
| Affected Files | Specific paths with reasoning |
| Approach | Technical narrative |
| Scope Assessment | Size, risk flags, suggested autonomy level |

## Behavior

1. Receives the issue content and repository context.
2. Calls `assess_issue` to determine confidence level.
3. If ambiguous: presents clarifying questions to the user, waits for answers (stage becomes `AwaitingApproval`).
4. Calls `generate_preflight_doc` to produce the structured document.
5. Document is stored on the Run model as `preflight_doc` with version history in `preflight_doc_history`.

## Emitted log events

All events go to the `pipeline` log channel via `App\Support\Logging\PipelineLogger`. Each carries `run_id` and `issue_id` plus the fields below.

| `event` value | Level | Additional fields |
|---|---|---|
| `preflight.execute_started` | info | `stage`, `iteration`, `has_clarification_answers` |
| `preflight.assessment_complete` | info | `stage`, `confidence` (`clear`/`ambiguous`), `known_facts_count`, `questions_count` |
| `preflight.clarification_needed` | info | `stage`, `questions_count` |
| `preflight.doc_generated` | info | `stage`, `sections` (array of doc section keys), `version` |
| `stage_started` | info | emitted by the orchestrator as the stage enters `Running` |
| `stage_completed` | info | emitted by the orchestrator when `complete()` is called |
| `stage_failed` | error | emitted by the orchestrator when the stage fails / goes stuck |
| `ai_call` / `ai_error` | info / error | emitted by the provider; `log_context.purpose` is `preflight.assess` or `preflight.generate_doc` |

Grep recipe: `jq 'select(.event | startswith("preflight."))' storage/logs/pipeline-*.log`.

## Collaborators

**Upstream (callers):**

- `App\Jobs\ExecuteStageJob` — the only direct entry point. Dispatched by `OrchestratorService` on stage transition.

**Downstream (dependencies):**

- `App\Services\AiProviderManager` — resolves the provider for the `Preflight` stage.
- `App\Services\OrchestratorService` — called as `pause($stage)` or `complete($stage)` at the end of `execute()`.
- `App\Support\Logging\PipelineLogger` — structured logging.
- `App\Models\StageEvent` — persisted audit trail for the UI's stage timeline.

## Error modes

- `AiProvider` throws `Illuminate\Http\Client\RequestException` — emits `ai_error`, bubbles up, `ExecuteStageJob` catches and marks the stage failed.
- Tool response missing required fields — `parseAssessment()` / `parseDocResponse()` throw; same path as above.
- No `run->clarification_answers` on resume despite pause — the stage re-enters `assess_issue`; the model may re-ask (acceptable, not a bug).

## Constraints

- Cannot edit source files.
- Cannot run tests.
- Cannot push or create PRs.
