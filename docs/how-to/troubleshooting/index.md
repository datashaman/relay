---
type: how-to
title: Troubleshooting
created: 2026-04-18
tags: [how-to, troubleshooting]
related:
  - "[[ai-provider-errors]]"
  - "[[stage-failures]]"
  - "[[merge-conflicts]]"
  - "[[stuck-states]]"
  - "[[configuration]]"
---

# Troubleshooting

Entry point for diagnosing failed or stuck runs. Every page in this section is keyed to the structured `event` values emitted on the `pipeline` log channel by `App\Support\Logging\PipelineLogger` — so you can jump between the UI, the logs, and the relevant reference page without losing context.

## Where to look first

1. **UI** — open the run's detail page. The stage timeline surfaces `StageEvent` rows and the current `StuckState` (if any).
2. **Pipeline log channel** — structured JSON lines under `storage/logs/pipeline-*.log`. Every entry carries `event`, `run_id`, and `issue_id`. Filter with `jq`:

   ```bash
   jq 'select(.run_id == 42)' storage/logs/pipeline-*.log
   ```

3. **Laravel log** — the default channel still captures framework-level exceptions that escape `ExecuteStageJob`.

## Common failure modes

| Symptom | Start here |
|---|---|
| `401 / 429 / 400` responses from the model | [[ai-provider-errors]] |
| Stage marked `Failed` or run marked `Stuck` | [[stage-failures]] |
| Run shows a conflict banner; Release won't start | [[merge-conflicts]] |
| Run is stuck and you don't know why | [[stuck-states]] |

## Pipeline `event` reference

The table below lists every event currently emitted. Most troubleshooting paths start by grepping for one of these:

| Event | Emitter | Meaning |
|---|---|---|
| `run_started` | `OrchestratorService::startRun` | A new run was created; worktree exists. |
| `stage_started` | orchestrator | Stage transitioned to `Running`. |
| `stage_completed` | orchestrator | Stage finished successfully. |
| `stage_bounced` | orchestrator | Verify sent Implement back for another iteration. |
| `stage_awaiting_approval` | orchestrator | Autonomy gate paused the stage. |
| `stage_failed` | orchestrator | Stage failed or was marked stuck. |
| `run_completed` | orchestrator | Release finished the final stage. |
| `preflight.*` | [[preflight]] | `execute_started`, `assessment_complete`, `clarification_needed`, `doc_generated`. |
| `implement.*` | [[implement]] | `execute_started`, `complete`, `loop_limit`. |
| `verify.*` | [[verify]] | `execute_started`, `test_results`, `static_analysis_results`, `complete`, `loop_limit`. |
| `release.*` | [[release]] | `execute_started`, `pr_created`, `deploy_triggered`, `complete`, `loop_limit`. |
| `ai_call` / `ai_error` | AI provider adapter | Per-request accounting and error capture. |

## See also

- [[stuck-states]] — recovery paths for each `StuckState` value.
- [[configuration]] — env vars, config files, and the provider scope cascade.
- [[orchestrator]] — the transition API referenced throughout these guides.
