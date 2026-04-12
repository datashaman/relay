# Relay — Design Documentation

An agentic issue pipeline. Each stage is handled by a focused agent with a specific tool set. Human-in-the-loop is configurable at the workspace, stage, and issue level.

---

## Pipeline

```
Preflight → Implement → Verify → Release
```

Four stages. Each agent only has access to the tools relevant to its stage.

| Stage | Agent tools |
|-------|-------------|
| Preflight | Issue tracker, web search, clarification dialogue |
| Implement | File editor, terminal, linter |
| Verify | Test runner, static analysis, coverage diff |
| Release | Git, CI/CD, changelog writer, deploy API |

---

## Screens

| File | Description |
|------|-------------|
| `index.html` | Overview and navigation |
| `01-issue-view.html` | 3-panel layout — queue, active issue, approval panel |
| `02-preflight.html` | Clarification mode + pre-flight doc output (interactive) |
| `03-implement-verify-release.html` | Live diff, test runner, changelog, PR |
| `04-run-history.html` | Full run timeline with bounces and iteration labels |
| `05-stuck-states.html` | Four stuck state scenarios with resolution actions |
| `06-activity-feed.html` | Global pipeline health — stats, snapshot, event stream |
| `07-intake.html` | Connected sources, filter rules, incoming queue |
| `08-configure-autonomy.html` | Global level, per-stage overrides, escalation rules |

---

## Key Design Decisions

### 1. Why four stages (not five)

An earlier design had Specify and Plan as separate stages, but they were merged into Preflight. The context needed to do both is identical — the agent reads the issue and the codebase regardless. Splitting them meant duplicating context-gathering work or passing a large state blob between agents.

The merged Preflight agent has two modes:
- **Clear issue** → skips straight to producing the pre-flight doc
- **Ambiguous issue** → enters a structured clarification loop first, then produces the doc

From the pipeline's perspective it's still one stage — it just takes longer when clarification is needed.

### 2. Autonomy model

Four levels, configurable at three scopes:

| Level | Behaviour |
|-------|-----------|
| Manual | Pause before every stage transition |
| Supervised | Pause only when escalation rules fire |
| Assisted | Run end-to-end, notify on completion |
| Autonomous | Fully silent — no interruptions |

**Scopes (most to least specific):**
1. Escalation rules — always override everything, cannot be bypassed
2. Issue level — can only loosen from the stage default
3. Stage level — can only tighten from the global default
4. Global default — baseline for all issues

**Key insight:** Stage overrides can only tighten (more human control). Issue overrides can only loosen (less human control). Escalation rules can always tighten regardless of any other setting.

**Escalation rules** evaluate before each stage transition — not once at triage. An issue that starts as "trivial" can be bumped to supervised mid-flight if the implement agent's diff unexpectedly touches auth.

### 3. Preflight document

The pre-flight doc is the contract between Preflight and Implement. The implement agent doesn't read the original issue — it reads this doc. That's what gives the doc its discipline: every section has a consumer.

Sections:
- **Summary** — one paragraph synthesis
- **Requirements** — what needs to be true after implementation
- **Acceptance criteria** — testable, numbered conditions
- **Affected files** — specific paths with reason
- **Approach** — technical narrative
- **Scope assessment** — size, risk flags, suggested autonomy level

### 4. Clarification mode

The preflight agent front-loads what it already knows before asking anything. This signals the agent did real work before interrupting you, and lets you correct any of the confirmed facts if they're wrong.

Questions are structured as choices (radio buttons) where possible — discrete options rather than open text, which makes answering fast and constrains the answer space.

### 5. The verify → implement loop

When verify fails, the failure context (test name, assertion mismatch, file location) is passed back to the implement agent as a patch target. The implement agent doesn't have to re-derive what went wrong — the diagnosis travels with the bounce. The pre-flight doc stays valid; only the specific bug needs fixing.

### 6. Stuck states

Four meaningfully different failure modes, each with a different resolution path:

| State | Cause | Primary action |
|-------|-------|----------------|
| Iteration cap | Bounced N times, hard stop | Give guidance |
| Timeout | Running too long, no progress signal | Give guidance or restart |
| Agent uncertain | Agent explicitly flags low confidence | Give guidance or take over |
| External blocker | Missing credential, service down | Fix environment |

**Give guidance** injects context into the agent's next attempt without restarting the full preflight cycle. The user types what the agent doesn't know ("decoded.exp is a string, not a number"), and that gets prepended to the implement agent's context on retry.

**Iteration cap** is configurable per-workspace. Future: agents can self-assess and propose whether to escalate.

### 7. Run history

Issues that bounce show a `↺ N` badge in the sidebar (iteration count, not bounce count). The run history timeline breaks into labelled iterations so you can read the story of a run.

The failure context passed between stages is visible in the history — you can see exactly what the verify agent handed back to implement, and what guidance the user added.

### 8. Activity feed

The feed records both agent events and human actions. When you gave guidance, approved a stage, or overrode a decision, that's in the stream at the same weight as an agent event. This matters for debugging — if an issue went wrong, you can trace exactly when a human touched it.

Stuck issues float to the top with unread dots. The "N stuck" chip in the topbar is a persistent signal that never disappears until resolved.

### 9. Intake model

Issues arrive from connected sources (GitHub Issues, Linear, Jira, or manual entry). Filter rules per source decide which issues enter the queue:

- **Include labels** — any of these → enter queue
- **Exclude labels** — any of these → skip
- **Unassigned only** — Relay never competes with ongoing human work
- **Auto-accept** — specific labels skip the queue entirely

The queue is the last human checkpoint before agents take over. Once accepted, the preflight agent picks it up.

The **pause intake** toggle prevents queue backlog when the pipeline is busy — configurable threshold.

### 10. Focused agents with bounded tool sets

Each agent only has access to tools relevant to its stage. This is what makes the autonomy model trustworthy — you know exactly what surface area each agent has. The implement agent cannot run tests; the verify agent cannot edit files; the release agent cannot modify source code.

---

## Agent architecture notes

- Each stage has one agent with a fixed, stage-specific tool set
- Agents receive structured input (pre-flight doc, failure context) not free-form issue descriptions
- Agents can propose their own autonomy level (future feature) based on diff scope and confidence
- Escalation rules are evaluated by the orchestrator, not by the agents themselves

---

## Color system (stage reference)

| Stage | Color |
|-------|-------|
| Preflight | Purple `#534AB7` |
| Implement | Amber `#BA7517` |
| Verify | Green `#639922` |
| Release | Teal `#1D9E75` |
| Stuck | Amber with border `#EF9F27` |
