## Code Review: Step 1 — Implement webhook management

### Verdict: APPROVE

### Summary
The implementation meets the Step 1 outcomes: `admin:repo_hook` is added to the OAuth scope list; a dedicated `GitHubWebhookManager` handles find-or-update-or-create with URL-based matching (idempotent); permission vs. other errors are classified and persisted per-repo in `source.config.managed_webhooks`; the secret is sourced from `Source::ensureWebhookSecret()` (encrypted cast) and never persisted into status metadata. Provisioning is wired into both the intake save path and the sync job so re-runs are safe. Test coverage is appropriately deferred to Step 3.

### Issues Found
None blocking.

### Pattern Violations
None — new endpoints reuse the existing `GitHubClient::request()` retry/throw pipeline; config updates preserve existing keys via `array_merge`.

### Test Gaps
- No unit tests yet for `GitHubWebhookManager` (idempotent match-by-URL, 401/403 → `needs_permission`, other errors → `error`, success → `managed`). Acceptable for Step 1 since STATUS defers to Step 3, but this must be covered there.

### Suggestions
- **`GitHubWebhookManager.php:15`** — `$repos` may contain paused repositories (`$source->isRepositoryPaused(...)` is skipped in sync for issue fetch but not here). Consider skipping paused repos, or intentionally document that webhook management proceeds regardless of pause state.
- **`GitHubWebhookManager.php:68`** — `managed_webhooks` entries for repositories no longer present in `$source->config['repositories']` are never pruned; over time stale rows accumulate. Consider filtering `$states` to the current `$repos` set before persisting.
- **`SyncSourceIssuesJob.php:74`** — Provisioning fires on *every* sync (one `listRepositoryWebhooks` call per repo per run). Fine for correctness, but consider throttling via a `managed_webhooks[*].updated_at` check to avoid unnecessary GitHub API usage on frequent syncs.
- **`GitHubWebhookManager.php:78`** — On permission errors, `webhook_last_error` is set to `null`. That's defensible (per-repo state carries the detail) but worth a short comment so a future reader doesn't mistake it for a bug.
- **`github-select-repos.blade.php:55`** — The flash message pluralization is readable but a bit long; consider a structured status block for Step 2's UI surface rather than appending sentences.
- Consider noting in docs (Step 3) that the new `admin:repo_hook` scope forces existing OAuth users to reconnect to pick up the broader grant.
