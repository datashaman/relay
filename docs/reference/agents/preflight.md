# Preflight Agent

**Service:** `App\Services\PreflightAgent`
**Stage:** `StageName::Preflight`
**Color:** Purple `#534AB7`

## Purpose

Front-loads known facts about an issue and produces a structured preflight document that serves as the contract for all downstream stages. The implement agent reads this document — not the original issue.

## Modes

- **Clear issue** — skips straight to producing the preflight doc
- **Ambiguous issue** — enters a structured clarification loop (radio-button choices where possible), then produces the doc

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

1. Receives the issue content and repository context
2. Calls `assess_issue` to determine confidence level
3. If ambiguous: presents clarifying questions to the user, waits for answers
4. Calls `generate_preflight_doc` to produce the structured document
5. Document is stored on the Run model as `preflight_doc` with version history in `preflight_doc_history`

## Constraints

- Cannot edit source files
- Cannot run tests
- Cannot push or create PRs
