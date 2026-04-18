# TP-002: GitHub webhook management via app permissions — Status

**Current Step:** Step 3: Verification & docs
**Status:** ✅ Complete
**Last Updated:** 2026-04-18
**Review Level:** 2
**Review Counter:** 4
**Iteration:** 1
**Size:** M

---

### Step 0: Preflight & permission design
**Status:** ✅ Complete

- [x] Confirm current intake flow behavior (where webhook URL/secret are shown and why)
- [x] Identify current GitHub OAuth/App permission set in code/config
- [x] Define minimum additional permissions required for repository webhook management
- [x] Document expected UX changes and fallback states before implementation

---

### Step 1: Implement webhook management
**Status:** ✅ Complete

- [x] Add/update GitHub permission configuration and auth flow handling
- [x] Implement service logic to create/update/find intake webhook for selected repos
- [x] Ensure idempotency (no duplicate hooks; safe updates)
- [x] Integrate secure secret handling in webhook provisioning path

---

### Step 2: UI/API + resilience
**Status:** ✅ Complete

- [x] Update intake UI/API responses to surface managed webhook state and guidance
- [x] Add clear handling/messages for insufficient permissions and repo constraints
- [x] Preserve compatibility for existing manual webhook setups

---

### Step 3: Verification & docs
**Status:** ✅ Complete

- [x] Add/adjust tests for webhook creation, update/reuse, and permission failures
- [x] Verify end-to-end intake hookup flow no longer requires manual copy/paste in normal case
- [x] Update operator-facing docs to describe the new automatic flow and required permissions
- [x] Summarize migration/back-compat behavior in docs/release notes

---

## Reviews

| # | Type | Step | Verdict | File |
|---|------|------|---------|------|

---

## Discoveries

| Discovery | Disposition | Location |
|-----------|-------------|----------|

---

## Execution Log

| Timestamp | Action | Outcome |
|-----------|--------|---------|
| 2026-04-18 | Task staged | STATUS.md created |
| 2026-04-18 08:53 | Task started | Runtime V2 lane-runner execution |
| 2026-04-18 08:53 | Step 0 started | Preflight & permission design |

---

## Blockers

*None*

---

## Notes

- 2026-04-18: Confirmed current intake behavior in `resources/views/pages/⚡intake.blade.php` — every source card renders a webhook URL, and GitHub cards also render plaintext `webhook_secret` for manual copy/paste into repository webhook settings. The page calls `$sources->each->ensureWebhookSecret()` so a secret always exists for display/manual setup.
- 2026-04-18: Identified current GitHub auth permissions in `config/services.php` as OAuth scopes `repo`, `read:org`, and `workflow` (requested by `OauthService::generateAuthUrl()`), and docs/tutorial copy currently only mentions approving `repo` scope.
- 2026-04-18: Defined webhook-management permission target: require explicit repository webhook admin capability (`admin:repo_hook` for OAuth apps; equivalent GitHub App permission is Repository Webhooks read/write). Treat missing webhook-admin permission as a first-class state in API/UI rather than silent failure.
- 2026-04-18: Documented planned UX/fallback states for implementation: (1) intake card shows managed webhook status (`managed`, `needs_permission`, `error`, `manual`) instead of exposing copy/paste secret by default, (2) webhook provisioning runs automatically after repository selection and during sync retries, (3) permission/admin failures show actionable reconnect/admin messaging, and (4) existing manual webhooks remain accepted as compatibility fallback when managed provisioning cannot complete.
- 2026-04-18: GitNexus impact check before Step 1 edits: `GitHubClient` returned HIGH risk (23 upstream dependents across sync, release tooling, intake views, and tests). Proceeding with additive API changes and targeted regression coverage to avoid breaking existing call sites.
- 2026-04-18: Updated GitHub OAuth scope config to include `admin:repo_hook` alongside existing scopes so auth flow can request explicit webhook-management capability.
- 2026-04-18: Added `GitHubWebhookManager` service plus new `GitHubClient` webhook endpoints (`list/create/update`) to auto-provision Relay intake webhooks for selected repositories, storing per-repo managed state in `source.config.managed_webhooks`.
- 2026-04-18: Idempotency handled by matching existing repo hooks on Relay callback URL and patching the existing hook instead of creating duplicates; sync path now re-runs provisioning safely.
- 2026-04-18: Provisioning path now always sources secrets from `Source::ensureWebhookSecret()` (encrypted at rest via model casts) and avoids persisting plaintext secrets in webhook status metadata.
- 2026-04-18: Intake UI now renders GitHub webhook lifecycle states (`managed`, `needs_permission`, `error`, `manual`, `unconfigured`) with repo-level guidance, and source JSON endpoints now include a structured `webhook` status payload for GitHub sources.
- 2026-04-18: Webhook provisioning failures now distinguish permission failures (401/403 → `needs_permission`) from repository/admin constraints (404/422 → `manual`) so users get actionable reconnect/admin guidance instead of opaque errors.
- 2026-04-18: Existing manual webhook flow is preserved as explicit fallback in intake via collapsible manual setup details (URL + secret) whenever managed mode is unavailable.
- 2026-04-18: Added `GitHubWebhookManagerTest` coverage for webhook create, update/reuse (idempotency), and permission-failure state mapping.
- 2026-04-18: Verified normal-case intake flow via `GithubSelectReposTest::test_managed_webhook_path_hides_manual_copy_paste_in_normal_case` — successful managed provisioning now lands on intake without manual copy/paste fallback prompts.
- 2026-04-18: Updated operator docs (`README.md`, `docs/tutorials/first-run.md`) to document required GitHub webhook scopes and new automatic webhook provisioning behavior + fallback messaging.
- 2026-04-18: Added migration/back-compat guidance in `docs/how-to/connect-github.md` covering managed-first behavior, manual fallback continuity, and idempotent re-runs.
- 2026-04-18: Full verification complete (`composer test`): 797 passed, 0 failed.
- 2026-04-18 08:55: Review R001 plan Step 1 approved.
- 2026-04-18 09:02: Review R002 code Step 1 approved.
- 2026-04-18 09:04: Review R003 plan Step 2 approved.
- 2026-04-18 09:08: Review R004 code Step 2 approved.
