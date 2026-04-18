## Code Review: Step 2 — UI/API + resilience

### Verdict: APPROVE

### Summary
All three Step 2 outcomes are implemented. The intake blade view now surfaces a per-source GitHub webhook lifecycle badge (`managed` / `needs_permission` / `error` / `manual` / `unconfigured`) with per-repo reason messaging and a collapsible manual-setup fallback. `SourceController::syncNow` and `testConnection` JSON responses gained a structured `webhook` status payload. `GitHubWebhookManager` now distinguishes 401/403 (permission) from 404/422 (repo/admin constraint → `manual`) as the plan promised, giving users actionable guidance. Back-compat for manual webhooks is preserved (URL + secret still available via the `<details>` fallback whenever managed mode is unavailable).

### Issues Found
*None blocking.*

### Pattern Violations
- None. Code matches existing Laravel/Blade conventions in the file.

### Test Gaps
Step 2 itself adds no tests, but Step 3 is explicitly the verification step and already lists:
- webhook creation / update / reuse
- permission failure path
It would be good if Step 3 also covers:
- The new 404/422 → `manual` branch in `GitHubWebhookManager::provisionForSelectedRepositories`.
- `SourceController::githubWebhookStatus` overall-state derivation (unconfigured / managed / needs_permission / error / manual precedence).
- The JSON `webhook` payload on `syncNow` and `testConnection` (including failure response still carrying the payload).

Flagging here so Step 3 can pick them up; not blocking Step 2.

### Suggestions
- **Duplication of state derivation (minor):** `resources/views/pages/⚡intake.blade.php` (lines ~398–418) and `SourceController::githubWebhookStatus()` both recompute the overall webhook state with slightly different ordering (blade: needs_permission → error → managed → manual default; controller: same ordering but different default for empty managed count). Consider extracting a single helper (e.g. on `Source` or a small value object) so API and UI cannot drift. Not required — logic is currently consistent.
- **404/422 counter bucket (minor):** In `GitHubWebhookManager`, repo-constraint failures (404/422) increment `$otherErrors` even though their state is `manual`, not `error`. The counters are internal/returned to callers; consider whether a `manual_fallbacks` counter would be useful for logging/telemetry later. Non-blocking.
- **Manual fallback still renders `$source->webhook_secret` plaintext inside `<details>`:** This is intentional back-compat, but consider adding a short warning note in the `<summary>` region ("Only use if managed setup is unavailable") so operators don't default to manual. Purely UX.
- **`$manualRepos` list:** The Blade view computes `$manualRepos` but only uses it inside the mixed list below (via `in_array($repoState, [...], true)`); that's fine. The earlier `else` branch message ("Relay is in manual fallback mode…") is reached only when *no* managed/needs_permission/error repos exist but the webhook state fell through — worth double-checking the copy matches the case of *mixed* managed+manual that currently lands on `managed` overall. Non-blocking.
