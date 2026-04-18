# TP-002: GitHub webhook management via app permissions — Status

**Current Step:** Step 1: Implement webhook management
**Status:** 🟡 In Progress
**Last Updated:** 2026-04-18
**Review Level:** 2
**Review Counter:** 0
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
**Status:** 🟨 In Progress

- [ ] Add/update GitHub permission configuration and auth flow handling
- [ ] Implement service logic to create/update/find intake webhook for selected repos
- [ ] Ensure idempotency (no duplicate hooks; safe updates)
- [ ] Integrate secure secret handling in webhook provisioning path

---

### Step 2: UI/API + resilience
**Status:** ⬜ Not Started

- [ ] Update intake UI/API responses to surface managed webhook state and guidance
- [ ] Add clear handling/messages for insufficient permissions and repo constraints
- [ ] Preserve compatibility for existing manual webhook setups

---

### Step 3: Verification & docs
**Status:** ⬜ Not Started

- [ ] Add/adjust tests for webhook creation, update/reuse, and permission failures
- [ ] Verify end-to-end intake hookup flow no longer requires manual copy/paste in normal case
- [ ] Update operator-facing docs to describe the new automatic flow and required permissions
- [ ] Summarize migration/back-compat behavior in docs/release notes

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

