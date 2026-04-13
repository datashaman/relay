# Stuck States Reference

A run enters a stuck state when the orchestrator cannot make forward progress. Each state maps to a specific resolution path.

## States

**Enum:** `App\Enums\StuckState`

| Case | Value | Cause | Resolution |
| --- | --- | --- | --- |
| `IterationCap` | `iteration_cap` | Verify → Implement bounced `RELAY_ITERATION_CAP` times (default 5). | Give guidance. |
| `Timeout` | `timeout` | No progress signal within the stage's timeout window. | Give guidance or restart the stage. |
| `AgentUncertain` | `agent_uncertain` | Agent flagged low confidence during execution. | Give guidance or take over manually. |
| `ExternalBlocker` | `external_blocker` | Missing credential, external service unreachable, or other environment-side failure. | Fix the environment, then resume. |

## Resolution API

`OrchestratorService::markStuck(Run, StuckState)` flags a run as stuck and surfaces it in the topbar stuck pill.

`OrchestratorService::giveGuidance(Run, string $guidance)` stores guidance on the run, clears the stuck state, and creates a new stage with the guidance in context.

## UI surfaces

| Surface | Behavior |
| --- | --- |
| Topbar stuck pill | Count of runs in any stuck state. Click to filter the Overview list. |
| Issue detail page | Stuck banner with a guidance textarea (`POST /issues/runs/{run}/guidance`). |
| Activity feed | Emits a `run_stuck` entry with the `StuckState` value. |

## Related

- [Architecture: stuck states](../explanation/architecture.md#stuck-states)
- [Architecture: Verify → Implement bounce](../explanation/architecture.md#verify--implement-bounce)
- [Configure autonomy](../how-to/configure-autonomy.md) — escalation rules can preempt some stuck states
