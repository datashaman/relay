# Phase 01: Branch Setup + Unit Test Coverage

Start the hardening effort on branch `chore/hardening-pass` and close the unit-test coverage gap. Right now `tests/Unit/` only contains `ExampleTest.php`; every real test is a Feature test that boots Laravel. This phase adds fast, isolated unit tests for the pure services (`AutonomyResolver`, `FilterRuleService`, `MergeConflictDetector`) and the AI provider adapters. By the end, `php artisan test --testsuite=Unit` should run and pass with meaningful coverage — a tangible, runnable deliverable.

## Tasks

- [x] Create and switch to branch `chore/hardening-pass` from `main`. Verify git status is clean and confirm the branch with `git branch --show-current`.
  - Branched off `main` (at `da3af51`). `git branch --show-current` → `chore/hardening-pass`.
  - Working tree had pre-existing uncommitted infrastructure files (modified `.gitignore` adding `.gitnexus`, plus untracked `.claude/`, `AGENTS.md`, `Auto Run Docs/`, `CLAUDE.md`) carried over from `main`. No project source code was dirty.

- [x] Inspect existing feature tests and service code before writing new unit tests:
  - Read `tests/Feature/AutonomyResolverTest.php`, `tests/Feature/FilterRuleServiceTest.php`, `tests/Feature/MergeConflictDetectorTest.php` to understand current coverage and fixtures.
  - Read `app/Services/AutonomyResolver.php`, `app/Services/FilterRuleService.php`, `app/Services/MergeConflictDetector.php` to identify pure (no-DB, no-HTTP) code paths.
  - Read `app/Services/AiProviders/AiProviderManager.php`, `AnthropicProvider.php`, `OpenAiProvider.php`, `GeminiProvider.php`, `ClaudeCodeCliProvider.php` and note their constructor signatures, external dependencies, and HTTP client interactions.
  - Check `phpunit.xml` for existing `Unit` testsuite config and adjust if needed so Unit tests do NOT use `RefreshDatabase` or boot the full app.
  - **Findings recorded** in `Auto Run Docs/Working/inspection-phase-01.md`. Summary:
    - `phpunit.xml` already has a `<testsuite name="Unit">` pointing at `tests/Unit` — no config change needed. `tests/Unit/ExampleTest.php` already extends `PHPUnit\Framework\TestCase` (the pattern new tests should follow).
    - `AutonomyResolver` has NO injectable collaborators — every method hits `AutonomyConfig` Eloquent directly. Pure unit coverage is limited to the `AutonomyLevel` / `AutonomyScope` / `StageName` enums unless we accept a repository-pattern refactor (flagged for user approval before next task).
    - `FilterRuleService` has clear pure targets: `matchesFilters`, `isAutoAccepted`, static `validateNoConflict`, and `evaluate(...)` with a stubbed `Source`. Feature test has 22 cases covering the DB paths.
    - `MergeConflictDetector` is DB+Process heavy; pure logic is limited to newline-parsing in `listConflictFiles`. A small helper extraction (`parseConflictFilesFromOutput`) would unlock meaningful unit tests — flagged for user approval.
    - `AiProviderManager::make()` is a pure `match` but calls `config()`, which needs a minimal container bound in unit tests.
    - `Anthropic` / `OpenAi` / `Gemini` providers all go through `Illuminate\Support\Facades\Http` — unit tests need `Http::fake()` which in turn needs the HTTP facade bootstrapped (so these "unit" tests will still extend `Tests\TestCase` but drop `RefreshDatabase`). Target 3 cases each: happy path, error, malformed.
    - `ClaudeCodeCliProvider` has the richest pure helpers (`buildArgs`, `splitCommand`, `buildPrompt`, `parseStreamJsonOutput`, `pickTerminalTool`, `synthesizeToolCall`, `extractJson`, `normalizeEvent`) — best unit-test value via reflection on the privates.

- [x] Run `gitnexus_impact` on each service before modifying anything around it:
  - `gitnexus_impact({target: "AutonomyResolver", direction: "upstream"})`
  - `gitnexus_impact({target: "FilterRuleService", direction: "upstream"})`
  - `gitnexus_impact({target: "MergeConflictDetector", direction: "upstream"})`
  - Record the blast-radius results in `Auto Run Docs/Initiation/Working/impact-phase-01.md` so later phases can reuse the data. We are only adding tests (no edits to production code) but capture risk levels for reference.
  - **Results recorded** in `Auto Run Docs/Initiation/Working/impact-phase-01.md`. Summary:
    - `AutonomyResolver` — **LOW** (3 upstream; only `⚡config.blade.php` + the feature test).
    - `FilterRuleService` — **MEDIUM** (16 upstream; real production callers include `SyncSourceIssuesJob`, `SourceController`, `MobileSyncService`, and the Electron mobile mirror).
    - `MergeConflictDetector` — **LOW** (3 upstream; only `routes/console.php` + the feature test).
  - Note: the GitNexus index WAL was corrupt on first call (`"Corrupted wal file"`); ran `npx gitnexus clean --force && npx gitnexus analyze` (re-index at `da3af51`: 5,536 nodes / 15,352 edges / 224 flows) before impact queries succeeded.

- [x] Write unit tests for `AutonomyResolver` in `tests/Unit/Services/AutonomyResolverTest.php`:
  - Use plain `PHPUnit\Framework\TestCase` (not Laravel's `TestCase`) so the test runs without booting the app.
  - Cover the pure decision-logic branches: autonomy levels, escalation thresholds, per-repo overrides, default fallbacks.
  - Inject collaborators via constructor or mocks using Mockery — do not touch the database or HTTP.
  - Mirror fixture setups from the existing feature test where helpful, but strip out DB/Eloquent concerns.
  - **Implementation notes:** 13 test methods / 43 executed cases / 106 assertions, 21ms runtime, extends `PHPUnit\Framework\TestCase` with zero DB/HTTP/container touch. Uses PHPUnit 12's `#[DataProvider]` attribute to exhaustively cross-check every 4×4 pair for both `isTighterThanOrEqual` and `isLooserThanOrEqual` (32 cases). Also covers: the four `AutonomyLevel` cases and string values, monotonic strictly-increasing `order()`, reflexivity (each level is both tighter-or-equal and looser-or-equal to itself), Manual-is-tightest / Autonomous-is-loosest bookends, mutual-exclusion of tighter vs. looser for distinct levels, `AutonomyScope` + `StageName` enum shape, and the two invariant-semantics cases the resolver relies on (stage-tightens-from-global and issue-loosens-from-stage).
  - **Flagged for user (blocker for deeper unit coverage):** `AutonomyResolver` has no DI seam — every public method calls `AutonomyConfig::where(...)` directly. Unit-testing `resolve()`, `getGlobalDefault()`, `validateAndSave()`, `validateInvariant()` in isolation would require a small repository-pattern refactor (e.g. inject an `AutonomyConfigRepository`). Per Phase 01 inspection, this was flagged as out-of-scope. The existing feature test at `tests/Feature/AutonomyResolverTest.php` (25 cases incl. the 3-scope cascade, validate tighten/loosen, error-message shape) continues to cover the DB-backed paths. The enum unit tests here cover the pure decision logic those methods delegate to.

- [ ] Write unit tests for `FilterRuleService` in `tests/Unit/Services/FilterRuleServiceTest.php`:
  - Cover include/exclude rule evaluation, label/title/body matchers, precedence logic, empty-rule edge cases.
  - Build issue payloads as plain arrays or DTOs — no Eloquent models.
  - Keep every test under ~20ms by avoiding `CreatesApplication`.

- [ ] Write unit tests for `MergeConflictDetector` in `tests/Unit/Services/MergeConflictDetectorTest.php`:
  - Cover pure parsing/detection functions (conflict marker scanning, file classification, summary formatting).
  - Mock any `Process`/shell interactions with Mockery; do not spawn real git processes.
  - If the detector requires a working tree, abstract the git runner via a test double rather than hitting the filesystem.

- [ ] Write unit tests for the AI provider adapters in `tests/Unit/Services/AiProviders/`:
  - `AiProviderManagerTest.php` — provider resolution by config key, fallback behaviour, unknown-provider exception.
  - `AnthropicProviderTest.php`, `OpenAiProviderTest.php`, `GeminiProviderTest.php` — request-payload shaping, response parsing, token-usage extraction, error mapping. Use Laravel's `Http::fake()` only if the provider depends on `Http` facade; otherwise inject a mock HTTP client.
  - `ClaudeCodeCliProviderTest.php` — command-line argument building and output parsing, with the process runner stubbed.
  - Do NOT call real APIs. Cover at least: happy path, rate-limit/HTTP error, malformed response.

- [ ] Configure the Unit testsuite for speed and run it:
  - Ensure `phpunit.xml` has a `<testsuite name="Unit">` entry pointing at `tests/Unit`, separate from `Feature`.
  - Run `./vendor/bin/phpunit --testsuite=Unit` (or `php artisan test --testsuite=Unit`) and confirm every new test passes.
  - Run the full suite with `php artisan test` to ensure nothing regressed.
  - If any test is slow (>50ms), refactor it to drop Laravel bootstrapping.

- [ ] Run `gitnexus_detect_changes({scope: "all"})` and confirm the only touched paths are under `tests/Unit/` and `phpunit.xml`. Then stage and commit with message `test: add unit test coverage for pure services and AI providers`. Do NOT push or open a PR — leave that to the user.
