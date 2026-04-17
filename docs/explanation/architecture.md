# Architecture

## Pipeline

```
┌──────────┐    ┌───────────┐    ┌────────┐    ┌─────────┐
│ Preflight│───▶│ Implement │───▶│ Verify │───▶│ Release │
│  (purple)│    │  (amber)  │    │ (green)│    │  (teal) │
└──────────┘    └───────────┘    └────────┘    └─────────┘
                      ▲               │
                      │   bounce with │
                      │   failure ctx │
                      └───────────────┘
```

Four stages. Each agent has a fixed, stage-specific tool set. The implement agent cannot run tests; the verify agent cannot edit files; the release agent cannot modify source code.

Issues enter via connected sources (GitHub Issues, Jira), pass through an intake queue with configurable filter rules, then flow through the pipeline. Each stage transition is gated by the autonomy system.

## Orchestrator

`App\Services\OrchestratorService` drives the pipeline. It manages stage lifecycle and enforces autonomy gates at every transition.

**Stage lifecycle methods:**
- `startRun(Issue, Repository?, context)` — creates a Run pinned to a specific repository, starts Preflight. For GitHub issues the repo is taken from `issue->repository`; for Jira issues (where the issue belongs to a Component that maps to one or more repositories) the caller picks a specific repo.
- `startStage(Stage, context)` — dispatches execution via `ExecuteStageJob`
- `pause(Stage)` — sets AwaitingApproval for human gate
- `resume(Stage, context)` — resumes after approval
- `bounce(Stage, failureReport)` — Verify → Implement retry with failure context
- `complete(Stage)` — advances to the next stage
- `markStuck(Run, StuckState)` — flags a run as stuck with one of four states

**Stage order** is defined in `OrchestratorService::STAGE_ORDER`:
`Preflight → Implement → Verify → Release`

**Transition logic:** Before each transition, the orchestrator calls `EscalationRuleService::resolveWithEscalation()` to compute the effective autonomy level. If the level is Manual or Supervised, the stage pauses for approval. If Assisted or Autonomous, it auto-advances.

**Events:** Every state change is persisted to `stage_events` and broadcast via Laravel events (`StageTransitioned`, `RunStuck`, `DiffUpdated`). The UI polls `/runs/{run}/progress` for live updates.

## Stage Contracts

Each stage is defined by the `StageName` enum (`app/Enums/StageName.php`):

| Enum | Value | Agent | Service |
|------|-------|-------|---------|
| `Preflight` | `preflight` | PreflightAgent | `App\Services\PreflightAgent` |
| `Implement` | `implement` | ImplementAgent | `App\Services\ImplementAgent` |
| `Verify` | `verify` | VerifyAgent | `App\Services\VerifyAgent` |
| `Release` | `release` | ReleaseAgent | `App\Services\ReleaseAgent` |

`ExecuteStageJob` dispatches to the correct agent service using a `match` on the stage name.

**Stage status flow:**
```
Running → Completed
Running → AwaitingApproval → Running (resumed)
Running → Bounced (Verify failure → back to Implement)
```

## Autonomy Resolution

```
┌─────────────────────────────────────────────────┐
│             Escalation Rules                     │
│         (always override, always tighten)        │
├─────────────────────────────────────────────────┤
│  Issue + Stage override                          │
│  Issue global override    ← can only LOOSEN      │
├─────────────────────────────────────────────────┤
│  Stage override           ← can only TIGHTEN     │
├─────────────────────────────────────────────────┤
│  Global default           (baseline: Supervised) │
└─────────────────────────────────────────────────┘
```

`App\Services\AutonomyResolver` resolves the effective level for a given (issue, stage) pair by walking the scope hierarchy from most specific to least specific.

**Four levels** (`App\Enums\AutonomyLevel`):

| Level | Order | Behavior |
|-------|-------|----------|
| Manual | 0 | Pause before every stage transition |
| Supervised | 1 | Pause only when escalation rules fire |
| Assisted | 2 | Run end-to-end, notify on completion |
| Autonomous | 3 | Fully silent, no interruptions |

**Invariants:**
- Stage overrides can only tighten (lower order) from the global default
- Issue overrides can only loosen (higher order) from the stage level
- Escalation rules can always tighten regardless of other config

**Escalation rules** (`App\Services\EscalationRuleService`) evaluate before every stage transition. Condition types: `label_match`, `file_path_match`, `diff_size`, `touched_directory_match`. Each condition uses `{type, value}` JSON format.

## Provider Adapters

`App\Contracts\AiProvider` defines the interface:

```php
public function chat(array $messages, array $tools = [], array $options = []): array;
public function stream(array $messages, array $tools = [], array $options = []): \Generator;
```

`chat()` returns `{content, tool_calls, usage, raw}`. `stream()` yields `{type, content, tool_calls, usage}`.

**Implementations** in `app/Services/AiProviders/`:

| Provider | Default Model | Config Key |
|----------|--------------|------------|
| `AnthropicProvider` | claude-sonnet-4-6 | `ANTHROPIC_API_KEY` |
| `OpenAiProvider` | gpt-4o | `OPENAI_API_KEY` |
| `GeminiProvider` | gemini-2.5-flash | `GEMINI_API_KEY` |
| `ClaudeCodeCliProvider` | (local binary) | `CLAUDE_CODE_BINARY` |

**Resolution:** `AiProviderManager` resolves which provider to use via a scope cascade: workspace+stage → workspace → global+stage → global → config default (`config/ai.php`). Provider selection is persisted in the `provider_configs` table.

## Verify → Implement Bounce

When Verify fails, the failure report (test name, assertion, file, line) is passed back to Implement as a patch target. The preflight doc is reused — not regenerated. The implement agent receives the failure context prepended to its input on retry.

Each bounce increments the iteration counter. When the configurable iteration cap is reached, the run enters a stuck state (`StuckState::IterationCap`).

## Stuck States

Four failure modes, each with a specific resolution path:

| State | Cause | Resolution |
|-------|-------|------------|
| `IterationCap` | Bounced N times | Give guidance |
| `Timeout` | No progress signal | Give guidance or restart |
| `AgentUncertain` | Agent flags low confidence | Give guidance or take over |
| `ExternalBlocker` | Missing credential, service down | Fix environment |

`OrchestratorService::giveGuidance()` stores guidance on the run, clears stuck state, and creates a new stage with guidance in context.

## Data Model

```
Source ──< Issue ──< Run ──< Stage ──< StageEvent
  │         │         │
  │         │         └── repository_id (the repo this run operates on)
  │         │
  │         ├── repository_id  (GitHub: set at sync time)
  │         └── component_id   (Jira: set at sync time)
  │
  ├── OauthToken
  ├── FilterRule
  └── Component ──>< Repository  (many-to-many via component_repository)

AutonomyConfig (keyed by scope + scope_id + stage)
EscalationRule (condition JSON, target level)
ProviderConfig (keyed by scope + stage)
```

Two paths link an issue to a repository:

- **GitHub:** `issue.repository_id` is set during sync (one repo per issue, matches the GitHub repo the issue lives in).
- **Jira:** `issue.component_id` is set during sync from the issue's first Jira component (sorted by id). A Component belongs to a Source and can be attached to one or more Repositories via `component_repository`. When starting a run, the caller picks which of those repos to operate on, and the choice is stored on `run.repository_id`.

All models use Eloquent with enum-cast columns. Sensitive fields (tokens, secrets) use the `encrypted` cast.

## Live Updates

The UI uses polling (not WebSockets). `RunProgressController` at `/runs/{run}/progress` returns JSON. JavaScript polls at 2-second intervals when the tab is active, 10-second intervals when idle, with `visibilitychange` reconnect.
