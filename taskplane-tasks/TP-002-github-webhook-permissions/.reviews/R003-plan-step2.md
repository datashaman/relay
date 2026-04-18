## Plan Review: Step 2 — UI/API + resilience

### Verdict: APPROVE

### Summary
The three Step 2 checkboxes cover the right outcomes on top of the Step 1 foundation: Step 1 already persists a per-repo `managed_webhooks` state map with `managed` / `needs_permission` / `error` classifications, so Step 2's job is to surface that state, wire actionable guidance for permission failures, and keep the manual webhook+secret path usable as a fallback. The Step 0 notes already fix the target UX states (`managed`, `needs_permission`, `error`, `manual`), which is enough direction for the worker to implement without more granular planning. Tests are appropriately deferred to Step 3.

### Issues Found
None blocking. The worker has acceptance criteria 5 (status + actionable messaging) and 7 (back-compat for manual setups) covered by existing checkboxes.

### Missing Items
- None at outcome level. Worth flagging for the worker's attention (not a new checkbox): acceptance criterion 4 (“Secret values are handled securely and are not exposed in plaintext where they should not be”) interacts with this step. `resources/views/pages/⚡intake.blade.php:420` still renders `webhook_secret` as a plaintext `<input>` for every GitHub source. Step 0 notes explicitly stated “intake card shows managed webhook status … instead of exposing copy/paste secret by default.” The existing checkbox *Update intake UI/API responses to surface managed webhook state and guidance* is broad enough to include hiding/collapsing the secret when all repos are `managed`, so no new checkbox is required — but if the worker ships this step without revisiting the plaintext-secret block, AC 4/5 will be only partly satisfied.

### Suggestions
- Per-repo state is keyed by `repoFullName` in `source.config.managed_webhooks` — surface it at the repo-row level in the intake card (and in the select-repos save flash) rather than a single source-level aggregate, so users can act on the exact repo that needs admin/reconnect.
- For `needs_permission`, the actionable message should offer a concrete next step — i.e. a reconnect link that re-runs `OauthService::generateAuthUrl()` with the now-broader `admin:repo_hook` scope — since pre-existing OAuth users will land in this state after upgrade.
- Define a clear rule for the `manual` fallback state: e.g. a source is `manual` when there is no token, or when `managed_webhooks` is empty and `webhook_secret` is in use. Keep the plaintext-secret UI visible only in that state so AC 7 (existing manual setups keep working) is preserved without leaking secrets in the common managed case.
- If an API/JSON response path (e.g. Livewire payloads, `/api/...`) surfaces `managed_webhooks`, make sure `config.managed_webhooks[*].reason` strings don't echo raw GitHub error bodies that could include tokens/IDs — the `truncateReason()` helper already caps length, but consider a sanitizer or whitelist of known messages.
- Consider addressing the R002 suggestion about pruning `managed_webhooks` entries for de-selected repos here, since Step 2 already touches the state-surfacing path; stale rows would otherwise show up in the new UI.
- Document the `managed` / `needs_permission` / `error` / `manual` contract in code (a small enum or constants file) so UI and service share one source of truth — avoids string drift between the blade template and `GitHubWebhookManager`.
