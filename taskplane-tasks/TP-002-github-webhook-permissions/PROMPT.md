# Task: TP-002 - GitHub webhook management via app permissions

**Size:** M
**Area:** general
**Created:** 2026-04-18

## Review Level: 2

## Dependencies
**None**

## Context to Read First
- `README.md`
- `docs/tutorials/first-run.md`

## Problem Statement
The current intake connection flow exposes a webhook URL and shared secret in the UI and requires users to manually configure these values in each target repository. This creates avoidable setup friction and increases risk of copy/paste mistakes.

Relay should manage repository webhook creation/updates directly once the user has authorized with sufficient GitHub permissions.

## Goal
Amend the GitHub integration permissions and intake flow so Relay can create and maintain webhook configuration programmatically for authorized repositories, eliminating manual webhook+secret setup for normal use.

## Acceptance Criteria
1. Required GitHub OAuth/App permissions are identified, implemented, and documented.
2. Relay can create the intake webhook automatically for an authorized repository.
3. If a compatible webhook already exists, Relay reuses or updates it safely (idempotent behavior).
4. Secret values are handled securely and are not exposed in plaintext where they should not be.
5. Intake UI/API shows webhook status (managed, needs permission, error) with actionable messaging.
6. Verification covers success and permission-failure paths.
7. Existing manually configured repositories continue to work, with migration/back-compat behavior documented.

## Implementation Notes
- Prefer GitHub App-style least-privilege permissions for webhook management.
- Ensure robust error handling for 401/403 permission failures and repository admin limitations.
- Avoid duplicate webhook creation on repeated sync/setup attempts.
- Keep any fallback/manual mode explicit and clearly labeled.

---

### Step 0: Preflight & permission design
- [ ] Confirm current intake flow behavior (where webhook URL/secret are shown and why)
- [ ] Identify current GitHub OAuth/App permission set in code/config
- [ ] Define minimum additional permissions required for repository webhook management
- [ ] Document expected UX changes and fallback states before implementation

### Step 1: Implement webhook management
- [ ] Add/update GitHub permission configuration and auth flow handling
- [ ] Implement service logic to create/update/find intake webhook for selected repos
- [ ] Ensure idempotency (no duplicate hooks; safe updates)
- [ ] Integrate secure secret handling in webhook provisioning path

### Step 2: UI/API + resilience
- [ ] Update intake UI/API responses to surface managed webhook state and guidance
- [ ] Add clear handling/messages for insufficient permissions and repo constraints
- [ ] Preserve compatibility for existing manual webhook setups

### Step 3: Verification & docs
- [ ] Add/adjust tests for webhook creation, update/reuse, and permission failures
- [ ] Verify end-to-end intake hookup flow no longer requires manual copy/paste in normal case
- [ ] Update operator-facing docs to describe the new automatic flow and required permissions
- [ ] Summarize migration/back-compat behavior in docs/release notes
