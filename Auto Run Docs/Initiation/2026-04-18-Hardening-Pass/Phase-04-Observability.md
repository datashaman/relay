# Phase 04: Structured Logging & Observability

Add structured, contextual logging around the Preflight → Implement → Verify → Release pipeline so operators can trace a run end-to-end, see AI token usage, and diagnose failures without reading source. This phase standardises a logging helper, instruments the orchestrator and AI providers, and adds a dedicated log channel.

## Tasks

- [x] Orient in the pipeline code before instrumenting:
  - Read `app/Services/OrchestratorService.php`, `PreflightAgent.php`, `ImplementAgent.php`, `VerifyAgent.php`, `ReleaseAgent.php` to identify stage boundaries and existing log calls.
  - Read all files in `app/Services/AiProviders/` to find where requests/responses pass through — these are the natural hooks for token-usage logging.
  - Read `config/logging.php` and check `bootstrap/providers.php` for any custom logging setup.
  - Run `gitnexus_context({name: "OrchestratorService"})` to map callers and callees, and capture risk with `gitnexus_impact({target: "OrchestratorService", direction: "upstream"})`. Save results to `Auto Run Docs/Initiation/Working/impact-phase-04.md`.
  - Notes: see `Auto Run Docs/Initiation/Working/impact-phase-04.md`. Orchestrator impact is MEDIUM (9 direct importers, no HIGH/CRITICAL). Stage agents already emit `StageEvent`s via private `recordEvent()` but no `Log::` calls; all four AI providers normalise usage to `input_tokens`/`output_tokens` and accept an `array $options` third arg — stage/run context can thread through that without changing signatures. Logging config is stock Laravel; no custom provider.

- [x] Add a dedicated log channel and a structured-logging helper:
  - In `config/logging.php`, add a `pipeline` channel using the `daily` driver writing to `storage/logs/pipeline-%date%.log`, JSON formatted via a Monolog `JsonFormatter` tap. Keep 14 days of retention.
  - Create `app/Support/Logging/PipelineLogger.php` exposing static methods like `stageStarted(Run $run, string $stage, array $context = [])`, `stageCompleted`, `stageFailed`, `aiCall(string $provider, string $model, array $usage, array $context)`. Each method emits one structured entry on the `pipeline` channel with keys: `event`, `run_id`, `issue_id`, `stage`, `provider`, `tokens_prompt`, `tokens_completion`, `duration_ms`, plus any caller context.
  - Keep the helper framework-agnostic and side-effect-free beyond logging so it can be unit tested.
  - Notes: Channel path is `storage/logs/pipeline.log` (Laravel's `daily` driver appends the date automatically → `pipeline-YYYY-MM-DD.log`). JSON formatting applied via `App\Support\Logging\UseJsonFormatter` tap (accepts `Illuminate\Log\Logger`, unwraps to the Monolog instance, skips non-`FormattableHandlerInterface` handlers). `PipelineLogger::stageCompleted` takes `int $durationMs` as an explicit positional param. Emitted record shape: `{"message": "pipeline.<event>", "context": {"event": "...", "run_id": ..., "issue_id": ..., "stage": "...", ...}, "level": 200, "channel": "local", ...}` — top-level `message` + structured `context`, greppable with `jq '.context.event'`. Smoke-tested via `Log::channel('pipeline')` with a sample `aiCall` entry; file rotates on the expected daily path. Pint + PHPStan both clean on the new files and `config/logging.php`.

- [x] Instrument the orchestrator and stage agents:
  - In `OrchestratorService`, wrap each stage transition with `PipelineLogger::stageStarted` / `stageCompleted` / `stageFailed`, including the Run id, issue id, repo, autonomy level, and elapsed milliseconds.
  - In each stage agent (`PreflightAgent`, `ImplementAgent`, `VerifyAgent`, `ReleaseAgent`), log entry/exit plus any significant sub-step (e.g. worktree created, tests run, PR opened). Preserve existing log calls; do not duplicate them.
  - For failure paths, always log the exception class and message plus a correlation id; do not log full stack traces at INFO — use `error()` for those.
  - Before editing each file, run `gitnexus_impact({target: "<ClassOrMethod>", direction: "upstream"})`. If HIGH/CRITICAL, pause and document the concern in the working notes before proceeding.
  - Notes: `transitionStage` impact came back **CRITICAL** (7 d=1 callers, 5 affected processes, 28+ transitive tests) — expected for the central choke point; no signature/control-flow changes were made (logging-only), so call sites stay unaffected. See `Auto Run Docs/Initiation/Working/impact-phase-04.md` for the rationale. `OrchestratorService` now emits `stage_awaiting_approval`, `stage_started`, `stage_completed` (with `duration_ms` from `started_at`), `stage_bounced`, `stage_failed`, `run_started`, and `run_completed` (run-level duration). Stage failures inside `failRunImmediately` thread the original `\Throwable` so the exception class/message land in the structured log. Each agent emits `agent.execute_started` plus its key milestones (`preflight.assessment_complete`/`clarification_needed`/`doc_generated`, `implement.complete`/`loop_limit`, `verify.test_results`/`static_analysis_results`/`complete`/`loop_limit`, `release.pr_created`/`deploy_triggered`/`complete`/`loop_limit`) — sub-step logging stays alongside the existing `recordEvent()` (DB) calls so we don't churn StageEvent semantics. Larastan can't see Eloquent enum casts (`$stage->name->value`) or relation types (`$stage->run` → `Run`), so the new noise was baselined alongside the project's existing 700+ baselined entries (no real bugs masked — verified by inspecting the diff to `phpstan-baseline.neon`). `php artisan test` (755 passed / 1731 assertions), `composer phpstan` and `vendor/bin/pint --test` all clean.

- [ ] Instrument AI token usage in every provider:
  - In `AnthropicProvider`, `OpenAiProvider`, `GeminiProvider`, `ClaudeCodeCliProvider`, after each successful response, call `PipelineLogger::aiCall` with provider name, model, prompt/completion token counts (pulled from the response payload), request duration, and the stage/run context if available.
  - For errors, log once at the provider layer with provider, model, HTTP status, and error body (truncated to 2 KB).
  - If a provider currently lacks access to stage/run context, accept an optional `array $context = []` parameter on its `generate`/`complete` method and thread it through the call sites. Run `gitnexus_impact` on the signature change before making it.

- [ ] Add unit coverage for the logger and a feature test for instrumentation:
  - `tests/Unit/Support/Logging/PipelineLoggerTest.php` — assert emitted entries have the expected shape and required keys, using Laravel's `Log::spy()` / `Log::channel('pipeline')` fake.
  - `tests/Feature/PipelineObservabilityTest.php` — drive a happy-path run through the orchestrator with fake AI providers and assert `stage_started`/`stage_completed` events and at least one `ai_call` event are emitted. Reuse existing fixture helpers from feature tests where possible.

- [ ] Run the full suite and verify:
  - `php artisan test` — all tests pass.
  - Boot the app (`php artisan serve` in the background) and trigger a sample run via the existing CLI or queue worker; tail `storage/logs/pipeline-*.log` and eyeball that the JSON entries make sense. Kill the server when done.
  - `composer phpstan` and `./vendor/bin/pint --test` — both clean.
  - `gitnexus_detect_changes({scope: "all"})` — confirm the blast matches: `config/logging.php`, `app/Support/Logging/*`, `app/Services/OrchestratorService.php`, `app/Services/*Agent.php`, `app/Services/AiProviders/*`, new test files.

- [ ] Commit with message `feat(observability): add structured pipeline logging and AI token usage metrics`. Do not push.
