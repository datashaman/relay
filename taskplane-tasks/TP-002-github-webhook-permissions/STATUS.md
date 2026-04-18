# TP-002: GitHub webhook management via app permissions — Status

**Current Step:** Not Started
**Status:** 🔵 Ready for Execution
**Last Updated:** 2026-04-18
**Review Level:** 2
**Review Counter:** 0
**Iteration:** 0
**Size:** M

---

### Step 0: Preflight & permission design
**Status:** ⬜ Not Started

- [ ] Confirm current intake flow behavior (where webhook URL/secret are shown and why)
- [ ] Identify current GitHub OAuth/App permission set in code/config
- [ ] Define minimum additional permissions required for repository webhook management
- [ ] Document expected UX changes and fallback states before implementation

---

### Step 1: Implement webhook management
**Status:** ⬜ Not Started

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

---

## Blockers

*None*

---

## Notes

*Reserved for execution notes*
