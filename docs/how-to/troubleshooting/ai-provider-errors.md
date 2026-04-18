---
type: how-to
title: Troubleshooting AI Provider Errors
created: 2026-04-18
tags: [how-to, troubleshooting, ai-provider]
related:
  - "[[index]]"
  - "[[stage-failures]]"
  - "[[anthropic]]"
  - "[[openai]]"
  - "[[gemini]]"
  - "[[claude-code-cli]]"
  - "[[configuration]]"
---

# Troubleshooting AI Provider Errors

Every AI provider adapter normalizes errors through `App\Support\Logging\PipelineLogger::aiError(...)`, which writes a structured `ai_error` entry to the `pipeline` log channel. Use those entries as the first diagnostic — they carry the provider name, model, HTTP status, a truncated error body, and the `log_context` (which includes `run_id`, `issue_id`, `stage`, and loop iteration).

## Prerequisites

- Relay running with `LOG_CHANNEL` including `pipeline` (default for `local`).
- `jq` installed for structured log queries.
- Familiarity with the provider reference pages: [[anthropic]], [[openai]], [[gemini]], [[claude-code-cli]].

## Find every provider error for a run

```bash
jq 'select(.event == "ai_error" and .run_id == 42)' \
    storage/logs/pipeline-*.log
```

All provider calls emit `ai_call` on success. To see the full request timeline for a run (successes plus failures):

```bash
jq 'select(.run_id == 42 and (.event == "ai_call" or .event == "ai_error"))' \
    storage/logs/pipeline-*.log
```

## Rate limits (HTTP 429)

Filter:

```bash
jq 'select(.event == "ai_error" and .status == 429)' \
    storage/logs/pipeline-*.log
```

Typical causes and fixes:

- **Burst across parallel runs.** Reduce concurrency by lowering the queue worker count (`php artisan queue:work --max-jobs=1`), or configure per-stage autonomy gates in [[configure-autonomy]] so that fewer runs advance simultaneously.
- **Account-level tier exceeded.** Check the provider dashboard; upgrade the tier or set a different `model` on the failing stage via the workspace/stage override (scope cascade in [[configuration]]).
- **Streaming retry storm.** If you see several `429` entries within seconds for the same `run_id`, the agent re-dispatched after transient failure. Let the job's built-in failure path run — `ExecuteStageJob` marks the stage failed so the run surfaces in the stuck UI rather than looping forever.

## Authentication failures (HTTP 401 / 403)

```bash
jq 'select(.event == "ai_error" and (.status == 401 or .status == 403))' \
    storage/logs/pipeline-*.log
```

Checks:

1. The provider's env var is set and loaded (`php artisan tinker` → `config('ai.providers.<provider>.api_key')`).
2. The key has the right scopes (Messages / Chat Completions / Generate, as applicable).
3. No per-workspace override is masking the env-level key with an empty string — look in `provider_configs.settings` for the run's workspace.

## Malformed responses

A malformed response usually surfaces as a `400` or as a parse-time exception raised by the agent (e.g. `Preflight` tool missing required fields). Both paths log `ai_error` if the HTTP call failed, then bubble up through `ExecuteStageJob` and produce a `stage_failed` entry. Query both sides:

```bash
jq 'select(.run_id == 42 and (.event == "ai_error" or .event == "stage_failed"))' \
    storage/logs/pipeline-*.log
```

Common culprits:

- **Wrong `model`.** Provider returned `400 invalid_model`. Override the model via workspace/stage config or unset the override to fall back to the provider default.
- **Tool schema rejected.** Some providers enforce stricter JSON Schema than others. See the per-provider reference pages for known schema quirks.
- **Token overflow.** Very large preflight docs can exceed a model's input window. Truncate the preflight doc history, or switch to a larger-context model.

## Verifying a fix

After fixing credentials or config, trigger a single run:

```bash
php artisan tinker
> App\Services\OrchestratorService::make()->retryStage($stage);
```

Then watch the log in a second terminal:

```bash
tail -f storage/logs/pipeline-*.log | jq 'select(.event | startswith("ai_"))'
```

A successful retry emits `ai_call` with non-zero `tokens_prompt` / `tokens_completion`. If you see a fresh `ai_error`, re-open the applicable reference page.

## See also

- [[anthropic]], [[openai]], [[gemini]], [[claude-code-cli]] — per-provider request/response details and known limitations.
- [[stage-failures]] — what to do once `ai_error` has caused a stage to fail.
- [[configuration]] — provider scope cascade and env var reference.
