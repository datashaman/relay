---
type: reference
title: Docs Gap Audit (Phase 05)
created: 2026-04-18
tags: [reference, audit, diataxis]
related:
  - "[[README]]"
  - "[[configuration]]"
  - "[[stuck-states]]"
  - "[[architecture]]"
---

# Docs Gap Audit — Diataxis Coverage

Rows are code surfaces under `app/Services/` (plus `app/Contracts/AiProvider`). Columns are Diataxis categories. Cells mark coverage: `✅` present, `❌` missing, `➖` intentionally omitted, `△` partially covered elsewhere (e.g. bundled into an architecture page rather than a dedicated reference).

This file drives the remainder of Phase 05 — every `❌` must become `✅` or be converted to `➖` with a reason.

## 1. Existing docs inventory

| File | Diataxis | Primary subject |
| --- | --- | --- |
| `docs/README.md` | index | Entry point |
| `docs/tutorials/first-run.md` | tutorial | Zero → first completed run |
| `docs/how-to/connect-github.md` | how-to | Connect a GitHub source |
| `docs/how-to/connect-jira.md` | how-to | Connect a Jira source |
| `docs/how-to/configure-autonomy.md` | how-to | Autonomy levels + escalation rules |
| `docs/how-to/add-ai-provider.md` | how-to | Implement `AiProvider` contract |
| `docs/how-to/add-stage.md` | how-to | Register a new pipeline stage |
| `docs/explanation/architecture.md` | explanation | Pipeline, orchestrator, autonomy, providers, data model |
| `docs/reference/configuration.md` | reference | Env vars, config files, DB-persisted config |
| `docs/reference/stuck-states.md` | reference | StuckState enum + resolutions |
| `docs/reference/agents/preflight.md` | reference | PreflightAgent |
| `docs/reference/agents/implement.md` | reference | ImplementAgent |
| `docs/reference/agents/verify.md` | reference | VerifyAgent |
| `docs/reference/agents/release.md` | reference | ReleaseAgent |

## 2. Gap matrix — code surfaces vs. Diataxis

Legend: ✅ covered · ❌ missing · ➖ intentionally omitted · △ partial / bundled.

### Agents

| Code surface (`app/Services/`) | Reference | How-to | Explanation | Tutorial |
| --- | --- | --- | --- | --- |
| `PreflightAgent` | ✅ `reference/agents/preflight.md` (Phase-04 log events + collaborators added) | ✅ `how-to/troubleshooting/stage-failures.md` | △ architecture.md pipeline diagram | ✅ `tutorials/first-run.md` |
| `ImplementAgent` | ✅ `reference/agents/implement.md` (Phase-04 log events + collaborators added) | ✅ `how-to/troubleshooting/stage-failures.md` | △ architecture.md | ✅ first-run |
| `VerifyAgent` | ✅ `reference/agents/verify.md` (Phase-04 log events + collaborators added) | ✅ `how-to/troubleshooting/stage-failures.md` | △ architecture.md | ✅ first-run |
| `ReleaseAgent` | ✅ `reference/agents/release.md` (Phase-04 log events + collaborators added) | ✅ `how-to/troubleshooting/stage-failures.md` | △ architecture.md | ✅ first-run |

All four agent reference pages were extended in this phase to document the `PipelineLogger` events, token-usage fields, and upstream/downstream collaborators.

### AI providers

| Code surface (`app/Services/AiProviders/`) | Reference | How-to | Explanation | Tutorial |
| --- | --- | --- | --- | --- |
| `App\Contracts\AiProvider` (contract) | ➖ contract itself — documented via per-provider reference pages + `how-to/add-ai-provider.md` | ✅ `how-to/add-ai-provider.md` | △ architecture.md "Provider adapters" | ✅ `tutorials/configure-custom-ai-provider.md` |
| `AiProviderManager` | ➖ internal dispatcher — cascade covered in architecture.md + `tutorials/configure-custom-ai-provider.md`; no public surface beyond `resolve()` | △ add-ai-provider | △ architecture.md scope cascade | ✅ `tutorials/configure-custom-ai-provider.md` |
| `AnthropicProvider` | ✅ `reference/ai-providers/anthropic.md` | △ add-ai-provider | △ architecture.md | ➖ |
| `OpenAiProvider` | ✅ `reference/ai-providers/openai.md` | △ add-ai-provider | △ architecture.md | ➖ |
| `GeminiProvider` | ✅ `reference/ai-providers/gemini.md` | △ add-ai-provider | △ architecture.md | ➖ |
| `ClaudeCodeCliProvider` | ✅ `reference/ai-providers/claude-code-cli.md` | △ add-ai-provider | △ architecture.md | ➖ |

### Orchestration & domain services

| Code surface (`app/Services/`) | Reference | How-to | Explanation | Tutorial |
| --- | --- | --- | --- | --- |
| `OrchestratorService` | ✅ `reference/orchestrator.md` | ✅ `how-to/troubleshooting/stage-failures.md` | △ architecture.md "Orchestrator" | ✅ first-run |
| `AutonomyResolver` | ✅ `reference/autonomy-resolver.md` | ✅ `how-to/configure-autonomy.md` | △ architecture.md "Autonomy resolution" | ➖ |
| `FilterRuleService` | ✅ `reference/filter-rules.md` | △ configure-autonomy (escalation only) | △ architecture.md "Intake filters" | ➖ |
| `EscalationRuleService` | ✅ `reference/escalation-rules.md` | △ configure-autonomy | △ architecture.md | ➖ |
| `MergeConflictDetector` | ✅ `reference/merge-conflict-detector.md` | ✅ `how-to/troubleshooting/merge-conflicts.md` | △ architecture.md | ➖ |
| `WorktreeService` | ✅ `reference/worktree-service.md` | ✅ `how-to/troubleshooting/stage-failures.md` | △ architecture.md | ➖ |
| `GitHubClient` | ➖ thin HTTP wrapper — user surface lives in `how-to/connect-github.md` | ✅ connect-github | △ architecture.md | ➖ |
| `JiraClient` | ➖ thin HTTP wrapper — user surface lives in `how-to/connect-jira.md` | ✅ connect-jira | △ architecture.md | ➖ |
| `OauthService` | ➖ internal OAuth broker — exposed via connect-github / connect-jira how-tos | ✅ connect-github / connect-jira | △ architecture.md | ➖ |
| `MobileOauthService` | ➖ mobile-shell-only variant of OauthService; no desktop surface | △ connect-github (desktop only today) | △ configuration.md "Mobile" | ➖ |
| `MobileSyncService` | ➖ internal to the NativePHP mobile shell | ➖ mobile is internal to the NativePHP shell | △ configuration.md "Mobile" | ➖ |
| `PushNotificationService` | ➖ internal infrastructure — no user-facing config beyond configuration.md | ➖ internal infrastructure | △ architecture.md | ➖ |

### Troubleshooting (how-to category)

| Failure mode | How-to | Notes |
| --- | --- | --- |
| Index / entry point | ✅ `how-to/troubleshooting/index.md` | Entry point with pipeline `event` table. |
| AI provider errors (rate limit, auth, malformed) | ✅ `how-to/troubleshooting/ai-provider-errors.md` | Keyed to Phase-04 `pipeline` log channel events with `jq` recipes. |
| Stage failures (Preflight / Implement / Verify / Release) | ✅ `how-to/troubleshooting/stage-failures.md` | Keyed to structured `event` values per agent. |
| Merge conflicts | ✅ `how-to/troubleshooting/merge-conflicts.md` | Covers `MergeConflictDetector` probe + `ResolveConflictsJob` AI-assisted flow. |

### Explanation

| Topic | File | Status |
| --- | --- | --- |
| Pipeline philosophy | `explanation/architecture.md` | ✅ |
| Orchestrator | `explanation/architecture.md` | △ bundled — acceptable |
| Autonomy model | `explanation/architecture.md` | △ bundled — acceptable per Phase-05 brief |
| Filter rule engine | `explanation/architecture.md` | △ bundled — acceptable |
| Escalation rule engine | `explanation/architecture.md` | △ bundled — acceptable |
| Provider adapters | `explanation/architecture.md` | △ bundled — acceptable |

Phase 05 explicitly asks that the autonomy model, the filter/escalation engines, and the pipeline philosophy each *exist* in explanation — they do, inside `architecture.md`. If a subsequent review decides any deserves its own page, add then; for this phase they are covered.

### Tutorials

| Topic | File | Status |
| --- | --- | --- |
| First run (zero → PR) | `tutorials/first-run.md` | ✅ |
| Configure a custom AI provider | ✅ `tutorials/configure-custom-ai-provider.md` | Walks the `AiProviderManager` cascade: global env default → stage-scoped `ProviderConfig` → workspace-scoped `ProviderConfig`. Cross-links `[[first-run]]`, `[[add-ai-provider]]`, `[[configuration]]`, per-provider refs, and `[[ai-provider-errors]]`. |

## 3. Execution flows worth referencing

The following symbols are the canonical anchors to cite in the new reference pages:

- `App\Services\OrchestratorService` (stage lifecycle: `startRun`, `startStage`, `pause`, `resume`, `bounce`, `complete`)
- `App\Support\Logging\PipelineLogger` (structured log channel introduced in Phase 04 — emits `event` values that drive troubleshooting docs)
- `App\Services\AiProviders\AiProviderManager` (scope cascade: workspace+stage → workspace → global+stage → global → default)
- Per-provider classes in `app/Services/AiProviders/` (request/response + token accounting)

When writing reference pages, link directly to the file/line anchors in `app/Services/…` rather than to process names.

## 4. Summary — work remaining for Phase 05

1. **Reference (13 pages — ✅ done in this phase):**
   - Extended 4 agent pages with Phase-04 log events + collaborators.
   - Added 4 AI-provider pages under `reference/ai-providers/`.
   - Added 6 orchestration/service pages at `reference/` root (`orchestrator`, `autonomy-resolver`, `filter-rules`, `escalation-rules`, `merge-conflict-detector`, `worktree-service`).
2. **How-to troubleshooting (4 pages):** index + ai-provider-errors + stage-failures + merge-conflicts.
3. **Tutorials (1 page — ✅ done in this phase):** added `configure-custom-ai-provider.md`. Explanation coverage remains bundled in `explanation/architecture.md` (autonomy model, filter/escalation engines, pipeline philosophy) per Phase-05 brief — no new explanation pages needed.
4. **Index updates:** `docs/README.md` grouped reference index + troubleshooting section. Minimal touch to root `README.md`.
5. **Intentionally omitted (➖):** `GitHubClient`, `JiraClient`, `OauthService`, `MobileOauthService`, `MobileSyncService`, `PushNotificationService`, `AiProviderManager` — each is either already fully covered by its user-facing how-to, or it is internal wiring with no user-facing surface. Revisit if a future task surfaces a user-visible failure mode.

Update this file as each `❌` is resolved.
