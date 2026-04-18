---
type: how-to
title: Troubleshooting Stage Failures
created: 2026-04-18
tags: [how-to, troubleshooting, pipeline]
related:
  - "[[index]]"
  - "[[ai-provider-errors]]"
  - "[[merge-conflicts]]"
  - "[[preflight]]"
  - "[[implement]]"
  - "[[verify]]"
  - "[[release]]"
  - "[[orchestrator]]"
  - "[[worktree-service]]"
  - "[[stuck-states]]"
---

# Troubleshooting Stage Failures

Use this guide when a stage has transitioned to `Failed` or the run is marked `Stuck`. Every agent emits namespaced `event` values on the `pipeline` log channel — this guide maps each one to the concrete checks that resolve it.

## Orient first

For any failed run, dump the full event timeline:

```bash
jq 'select(.run_id == 42)' storage/logs/pipeline-*.log | jq -r '.event'
```

The orchestrator-emitted events form the spine of the timeline:

| Event | What it means |
|---|---|
| `run_started` | Worktree exists, Preflight dispatched. |
| `stage_started` | Stage entered `Running`. |
| `stage_awaiting_approval` | Autonomy gate paused the stage. |
| `stage_bounced` | Verify sent Implement back for another loop. |
| `stage_completed` | Stage succeeded. |
| `stage_failed` | Stage or run terminated abnormally. |
| `run_completed` | Release finished. |

If `stage_failed` is present, inspect the preceding agent-namespaced event to find the root cause.

## Preflight failures — [[preflight]]

Filter:

```bash
jq 'select(.run_id == 42 and (.event | startswith("preflight.")))' \
    storage/logs/pipeline-*.log
```

Common patterns:

- **`preflight.clarification_needed` but the user never answered.** The stage is `AwaitingApproval`, not failed. Open the issue detail page and submit the clarification, or use `OrchestratorService::giveGuidance()`.
- **`preflight.assessment_complete` with `questions_count == 0` but no `preflight.doc_generated` follows.** The second provider call failed — check for an adjacent `ai_error`. See [[ai-provider-errors]].
- **Immediate `stage_failed` with no `preflight.*` entry.** The agent crashed before emitting. Look in the standard Laravel log for the exception and its `exception_class` field.

## Implement failures — [[implement]]

Filter:

```bash
jq 'select(.run_id == 42 and (.event | startswith("implement.")))' \
    storage/logs/pipeline-*.log
```

Common patterns:

- **`implement.loop_limit`.** The tool-call loop hit `MAX_TOOL_LOOPS`. Either the task is too large (split the issue) or the model is looping. Try a different provider via the workspace/stage override.
- **No `implement.complete` but `stage_failed` with `No worktree path configured for this run.`.** The worktree was never created or got cleaned up. Re-run `WorktreeService::createWorktree()` via a `restart` — see [[worktree-service]].
- **Shell tool errors (`Error: …`) on every call.** The model may be attempting blocked commands (`phpunit`, `git push`, etc.). These are intentional — blocked commands belong to Verify and Release.

## Verify failures — [[verify]]

Filter:

```bash
jq 'select(.run_id == 42 and (.event | startswith("verify.")))' \
    storage/logs/pipeline-*.log
```

Common patterns:

- **`verify.test_results` with `status: "failed"`.** This is a *normal bounce*, not a stage failure — `stage_bounced` follows and a fresh Implement stage picks up the failure report. Only worry if the run reaches `StuckState::IterationCap` (see [[stuck-states]]).
- **`verify.static_analysis_results` with non-zero `exit_code`** — same path as above. The failure report is attached to the next Implement.
- **`verify.loop_limit`.** The agent could not finalize a pass/fail decision within `MAX_TOOL_LOOPS`. Review the last few `tool_call` `StageEvent`s in the UI; often a missing test runner in the worktree is the culprit.
- **`stage_failed` without any `verify.*` entry.** Usually `No worktree path configured for this run.`. Same recovery as Implement.

## Release failures — [[release]]

Filter:

```bash
jq 'select(.run_id == 42 and (.event | startswith("release.")))' \
    storage/logs/pipeline-*.log
```

Common patterns:

- **No `release.pr_created`, `stage_failed` present.** Look at the last `release.*` event. Missing OAuth token and malformed `owner/repo` are the two most common causes — both surface as `Error:` strings in the `create_pr` tool and in `StageEvent`.
- **`release.pr_created` then `stage_failed`.** Usually a deploy-hook exit code. `release.deploy_triggered` logs `exit_code` and `success`; re-run the hook locally to confirm.
- **Conflict banner on the run.** Release refuses to start while `has_conflicts` is true. Go to [[merge-conflicts]].

## Recovering from a failed stage

| Situation | Recovery |
|---|---|
| Transient error (rate limit, network) | `OrchestratorService::retryStage($stage)` — re-runs the same stage at the same iteration. |
| Root-cause fix applied in config or env | `OrchestratorService::restart($run)` — clears stuck flags, re-enters the latest stage. |
| You need to steer the agent | `OrchestratorService::giveGuidance($run, $text)` — adds guidance to context and opens a fresh stage. |
| Run hit the iteration cap | See [[stuck-states]] for the `IterationCap` resolution path. |

## See also

- [[stuck-states]] — recovery paths for each `StuckState`.
- [[orchestrator]] — transition API used by the recovery calls above.
- [[ai-provider-errors]] — when the root cause is upstream of the agent.
