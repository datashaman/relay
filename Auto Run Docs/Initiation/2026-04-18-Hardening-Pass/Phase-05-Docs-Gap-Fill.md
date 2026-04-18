# Phase 05: Diataxis Docs Gap-Fill

Audit the existing Diataxis structure (`docs/tutorials`, `docs/how-to`, `docs/reference`, `docs/explanation`) against the actual agents and pipeline stages, then fill the gaps. Produce reference pages for every agent/stage/service that currently lacks one and a troubleshooting how-to section using real failure modes surfaced by the Phase 04 logging work.

## Tasks

- [x] Inventory current docs and code surfaces:
  - List all markdown files under `docs/` and note which agents/stages/services each one covers.
  - Enumerate the documentation targets from code: every class in `app/Services/` (agents, providers, orchestrator, filter/autonomy/escalation engines, worktree, push notifications, oauth, mobile sync).
  - Build a gap matrix at `docs/reference/_audit.md` — rows are code surfaces, columns are Diataxis categories, cells mark present/missing. Use it to drive the rest of this phase.
  - Use `gitnexus_query({query: "pipeline stage transition"})` and `gitnexus_query({query: "AI provider call"})` to find any execution flows worth referencing in docs.

- [x] Write missing reference pages in `docs/reference/`:
  - One markdown file per agent: `preflight-agent.md`, `implement-agent.md`, `verify-agent.md`, `release-agent.md`. Each documents: purpose, inputs, outputs, side effects, error modes, emitted log events (from Phase 04), and upstream/downstream collaborators.
  - One page per AI provider: `ai-providers/anthropic.md`, `openai.md`, `gemini.md`, `claude-code-cli.md`. Document: config keys, required env vars, supported models, request/response shape, token accounting, known limitations.
  - One page per orchestration service: `orchestrator.md`, `autonomy-resolver.md`, `filter-rules.md`, `escalation-rules.md`, `merge-conflict-detector.md`, `worktree-service.md`.
  - Every page uses this front matter:
    ```yaml
    ---
    type: reference
    title: <Human Title>
    created: 2026-04-18
    tags: [reference, <area>]
    related:
      - "[[<Related-Page>]]"
    ---
    ```
  - Link related pages with `[[Wiki-Link]]` syntax for graph navigation.

- [ ] Write or extend how-to guides in `docs/how-to/`:
  - `troubleshooting/index.md` as an entry point listing common failure modes.
  - `troubleshooting/ai-provider-errors.md` — rate limits, auth failures, malformed responses; reference the pipeline log channel introduced in Phase 04 with grep snippets.
  - `troubleshooting/stage-failures.md` — what to check when Preflight/Implement/Verify/Release fail, keyed to the structured log `event` values.
  - `troubleshooting/merge-conflicts.md` — using the AI-assisted conflict resolution flow.
  - Each file uses `type: how-to` front matter and cross-links to the relevant reference pages.

- [ ] Fill missing explanation and tutorial coverage if the audit surfaced gaps:
  - In `docs/explanation/`, ensure a page exists covering the autonomy model, the filter/escalation rule engines, and the overall pipeline philosophy. Add any that are missing.
  - In `docs/tutorials/`, confirm there is a "first run" tutorial that walks a user from zero to a completed pipeline run. If absent, add `first-run.md`. If present, add a brief tutorial for configuring a custom AI provider as a second entry point.
  - All new pages use appropriate Diataxis front-matter `type` and cross-link generously.

- [ ] Update the top-level `docs/README.md` (and root `README.md` if needed) so the new pages are discoverable:
  - Add a reference index listing every page in `docs/reference/` grouped by category (agents, providers, services).
  - Add a troubleshooting section linking to the new how-to pages.
  - Keep changes to the root `README.md` minimal — just ensure the docs link points at the right starting page.

- [ ] Final verification:
  - Re-open `docs/reference/_audit.md` and confirm every previously-missing cell is now covered or explicitly marked "intentionally omitted" with a reason.
  - Run `./vendor/bin/pint --test`, `composer phpstan`, and `php artisan test` — no code changed, but confirm everything still passes.
  - `gitnexus_detect_changes({scope: "all"})` — all changes under `docs/` plus possibly `README.md`.
  - Commit with message `docs: fill Diataxis reference and troubleshooting gaps`. Do not push.
