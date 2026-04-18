# Phase 02: GitHub Actions CI

Add a CI workflow so tests, lint, and (later) static analysis run automatically on every pull request and push. This phase wires up `.github/workflows/ci.yml` with PHP 8.4, Composer caching, Node for asset builds, and the SQLite-backed Laravel test database. By the end, pushing the branch should trigger a green CI run.

## Tasks

- [x] Inspect the project before writing the workflow:
  - Confirm PHP version from `composer.json` (`php: ^8.4`) and Node version from `package.json` / `.nvmrc` if present.
  - Check `phpunit.xml` for database config (SQLite in-memory vs file) and required env vars.
  - Check `.env.example` for required app keys so CI can generate a valid test env.
  - Search for any existing `.github/` directory to avoid clobbering templates or issue forms.

  **Findings (2026-04-18):**
  - `composer.json` requires `php: ^8.4`; Laravel 13, Pint 1.27, PHPUnit 12.5.
  - No `.nvmrc` present. `package.json` declares no engines; existing workflow pins Node 20. Use `node-version: '20'` for the new `ci.yml`.
  - `phpunit.xml` pins `DB_CONNECTION=sqlite` and `DB_DATABASE=:memory:` via env block, so tests do not need a file-backed DB; however the Phase-02 task instructs CI to create `database/database.sqlite` and run `php artisan migrate --force` before `php artisan test` (harmless: the migrate uses `.env` which is seeded from `.env.example` where `DB_CONNECTION=sqlite` without `DB_DATABASE`, defaulting to `database/database.sqlite`). Tests themselves still use the in-memory DB from phpunit.xml.
  - Required env: `APP_KEY` (generated via `php artisan key:generate`); `.env.example` already contains a complete `DB_CONNECTION=sqlite` default plus empty AI provider keys (safe for CI — no tests require live API calls per Phase 01 verification).
  - `.github/workflows/` already contains **`tests.yml`** (a pre-existing coverage-focused workflow). Phase-02 says to create `ci.yml` — it will co-exist alongside `tests.yml`. Flag for follow-up: once `ci.yml` stabilises, the older `tests.yml` may be redundant (not in scope for this phase).
  - No issue/PR templates or other `.github/` content to worry about.

- [x] Create `.github/workflows/ci.yml` with a single `test` job that:
  - Triggers on `push` to any branch and `pull_request` targeting `main`.
  - Runs on `ubuntu-latest`.
  - Uses `shivammathur/setup-php@v2` with PHP 8.4, extensions `mbstring, sqlite3, pdo_sqlite, intl, bcmath, gd, zip`, and coverage `none`.
  - Caches Composer (`~/.composer/cache`) keyed on `composer.lock`.
  - Runs `composer install --prefer-dist --no-progress --no-interaction`.
  - Sets up Node via `actions/setup-node@v4` with caching keyed on `package-lock.json`, runs `npm ci --ignore-scripts` and `npm run build`.
  - Copies `.env.example` to `.env`, runs `php artisan key:generate`, creates `database/database.sqlite`, runs `php artisan migrate --force`.
  - Runs `php artisan test` as the final step.

  **Completed (2026-04-18):** Created `.github/workflows/ci.yml` with the `test` job matching the spec. Triggers on all pushes and PRs targeting `main`. Co-exists with the pre-existing `tests.yml` (coverage workflow). Commit will follow in the final task of this phase.

- [x] Add a second job `lint` (or parallel step) that runs `./vendor/bin/pint --test` to enforce code style without modifying files. Make it independent of `test` so both jobs run in parallel. Leave a TODO comment near the job mentioning that Phase 03 will add a PHPStan job.

  **Completed (2026-04-18):** Added a `lint` job to `.github/workflows/ci.yml` running in parallel with `test`. Job reuses the PHP 8.4 setup + Composer cache pattern, then runs `./vendor/bin/pint --test`. TODO comment points at Phase 03 PHPStan work. Pint preflight intentionally deferred to the later sanity-check task — if it fails there, we'll revisit adding `continue-on-error: true`.

- [x] Add a status-badge line to the top of `README.md` pointing at the new workflow (GitHub Actions badge URL format: `https://github.com/<owner>/<repo>/actions/workflows/ci.yml/badge.svg`). Determine `<owner>/<repo>` from `git remote get-url origin`.

  **Completed (2026-04-18):** Origin is `git@github.com:datashaman/relay.git` → owner/repo is `datashaman/relay`. Inserted a linked CI badge immediately below the `# Relay` heading pointing at `.github/workflows/ci.yml`.

- [x] Sanity-check the workflow locally where possible:
  - Run `composer install` and `php artisan test` on a clean checkout-like state to confirm the steps succeed.
  - Run `./vendor/bin/pint --test` and note any pre-existing style violations — do NOT auto-fix them here (Phase 03 owns that). If failures would block CI, scope the Pint job to a warning-only mode (`continue-on-error: true`) with a TODO to remove in Phase 03.

  **Completed (2026-04-18):**
  - `composer install --prefer-dist --no-progress --no-interaction` → "Nothing to install, update or remove" (deps already in sync); package discovery succeeded.
  - `php artisan test` → **755 passed (1731 assertions)** in 24.07s. No failures or errors.
  - `./vendor/bin/pint --test` → **FAIL** with many pre-existing violations. Two classes of findings:
    1. **First-party sources (Phase 03 will fix):** `app/Http/Controllers/*`, `app/Services/*` (WorktreeService, OauthService, ReleaseAgent, JiraClient, FilterRuleService, ImplementAgent, MergeConflictDetector, GitHubClient, VerifyAgent, PreflightAgent), `app/Jobs/ResolveConflictsJob.php`, `app/Providers/NativeAppServiceProvider.php`, `config/nativephp.php`, `database/migrations/2026_04_12_000001_create_sources_table.php`, `database/factories/*`, `database/seeders/DemoDataSeeder.php`, many `tests/Feature/*` and `tests/Unit/Services/*` files, and `packages/nativephp-laravel/**` + `packages/nativephp-electron/src/Updater/UpdaterManager.php`. Typical fixers: `concat_space`, `unary_operator_spaces`, `not_operator_with_successor_space`, `braces_position`, `single_line_empty_body`, `class_definition`, `no_unused_imports`, `ordered_imports`, `fully_qualified_strict_types`, `new_with_parentheses`.
    2. **Build artefacts that should not be linted at all (flag for Phase 03 pint.json excludes):** `dist/mac-arm64/Laravel.app/**` and `packages/nativephp-electron/resources/js/resources/app/**`. These are generated Electron bundles duplicating first-party code — Phase 03 should add them to `pint.json` `exclude` rather than "fixing" them.
  - Action taken: added `continue-on-error: true` to the `lint` job in `.github/workflows/ci.yml` plus an explicit Phase-03 removal TODO. CI will still surface Pint output but won't fail the run until Phase 03 normalises style.

- [ ] Run `gitnexus_detect_changes({scope: "all"})` and confirm changes are limited to `.github/workflows/ci.yml` and `README.md`. Commit with message `ci: add GitHub Actions workflow for tests and lint`. Do not push.
