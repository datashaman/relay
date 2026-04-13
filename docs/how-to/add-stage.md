# How to add a new agent stage

This guide shows you how to add a new stage to Relay's pipeline.

## Prerequisites

- Working Relay development environment (see [README](../../README.md))
- Read [Architecture: stage contracts](../explanation/architecture.md#stage-contracts) to understand the lifecycle
- A clear contract: what the agent reads, what it produces, which tools it gets

## Steps

### 1. Create the agent service

Add the agent class under `app/Services/` with `SYSTEM_PROMPT` and `TOOLS` constants. The tool set must be bounded — each stage has a fixed, stage-specific surface.

### 2. Add the stage to the `StageName` enum

Update `app/Enums/StageName.php` with the new case.

### 3. Add a case in `ExecuteStageJob`

Extend the `match` expression so the job dispatches to the new agent service.

### 4. Update stage order

Update `OrchestratorService::STAGE_ORDER` to include the new stage in the correct position.

### 5. Update the "ignores unhandled stages" test

The feature test that enumerates `StageName` cases must be updated so it continues to pass with the new stage.

## See also

- [Agent references](../reference/agents/) — tool surface for existing stages
- [Architecture: orchestrator](../explanation/architecture.md#orchestrator)
