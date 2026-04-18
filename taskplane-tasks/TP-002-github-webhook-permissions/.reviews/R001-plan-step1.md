## Plan Review: Step 1 — Implement webhook management

### Verdict: APPROVE

### Summary
The Step 1 checklist is outcome-level and maps cleanly to acceptance criteria 1–4 (permissions, automatic creation, idempotency, secret handling). Step 0 notes already established the surrounding context (current scopes `repo, read:org, workflow`; target addition `admin:repo_hook`; managed/needs_permission/error/manual states; manual compatibility fallback), which gives Step 1 a sound basis to execute against. UI surfacing (AC5), verification (AC6), and back-compat docs (AC7) are correctly deferred to Steps 2/3.

### Issues Found
*None blocking.*

### Missing Items
*None — the four checklist items cover the step's required outcomes.*

### Suggestions
- **Existing-token reauth path.** Users who connected before the new scope was added will hold tokens lacking `admin:repo_hook`. Consider making sure the "auth flow handling" checkbox explicitly accounts for detecting insufficient granted scopes on the stored token (e.g. via the scopes already persisted by `OauthService`) so the provisioning service can surface `needs_permission` deterministically instead of only learning about it from a 403. Not blocking — can be handled inside the existing checkbox.
- **Webhook config shape.** When implementing create/update, worth settling early on which events are subscribed (push, pull_request, issues, etc.), `content_type: json`, and whether `active=true` — these influence the idempotency comparison ("is the existing hook 'compatible'?"). A small internal constant/config avoids drift between create and update paths.
- **Secret rotation semantics.** `ensureWebhookSecret()` currently generates a secret for display. When the provisioning path takes over, decide whether an existing stored secret is reused (preferred for idempotency) or rotated on each reconcile. Either is fine, but the decision should be explicit in the service logic to avoid repeatedly invalidating webhooks on sync.
- **Scope separator.** `config/services.php` currently uses space-separated scopes via the array; GitHub OAuth expects comma/space-tolerant input and `OauthService::generateAuthUrl()` handles separators — just a reminder to verify the chosen separator still round-trips `admin:repo_hook` through the stored `scopes` parsing at line 154.

