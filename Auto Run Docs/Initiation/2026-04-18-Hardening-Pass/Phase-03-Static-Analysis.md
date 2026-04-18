# Phase 03: Static Analysis + Code Style

Add Laravel Pint (already a dev dep) configuration and introduce PHPStan with Larastan for type-aware static analysis. Apply an initial pass and wire both tools into CI so regressions get caught at PR time.

## Tasks

- [x] Add Laravel Pint configuration:
  - Create `pint.json` using the `laravel` preset plus a small rule set consistent with the existing codebase (search the codebase for prevailing styles — trailing commas, import ordering, array syntax — before choosing rules so Pint does not churn hundreds of files).
  - Do a dry run with `./vendor/bin/pint --test` and record the violation count.
  - Run `./vendor/bin/pint` to apply the fixes. Review the diff by directory and keep the changes focused on whitespace, imports, and formatting.
  - If any diff looks semantic (not just formatting), revert that file and add an exclusion in `pint.json`.

  **Notes (2026-04-18):**
  - Prevailing style already matched the `laravel` preset closely: short array syntax, trailing commas on multiline, PSR-12 imports — no custom rules required beyond the preset.
  - Added `exclude`/`notPath` entries for `dist/`, `storage/`, `bootstrap/cache`, `vendor/`, `node_modules/`, and the bundled PHP snapshot under `packages/nativephp-electron/resources/js/resources` (and its `out/`, `build/`, `node_modules/` siblings) so Pint only lints source we actually maintain.
  - Dry run reported **52 files with 165 fixer applications**; full output saved to `Auto Run Docs/Initiation/Working/pint-initial-dryrun.json`.
  - After `./vendor/bin/pint`, the diff is formatting-only: `concat_space` (remove spaces around `.`), `no_unused_imports`, `ordered_imports`, `fully_qualified_strict_types`, `braces_position`, `single_line_empty_body`, `new_with_parentheses`, etc. Spot-checked several files — no semantic changes, so no file was reverted or excluded.
  - `./vendor/bin/pint --test` now returns `{"result":"pass"}`; `php artisan test` → 755 passed (1731 assertions).

- [ ] Install and configure PHPStan + Larastan:
  - `composer require --dev larastan/larastan` (pulls in phpstan).
  - Create `phpstan.neon` at the repo root extending the Larastan config, level `5` as a starting bar, with `paths: [app, config, database, routes, tests]`, excluding `database/migrations` and `storage`.
  - Add a `phpstan` script to `composer.json` under `scripts`: `"phpstan": "./vendor/bin/phpstan analyse --memory-limit=1G"`.

- [ ] Do an initial triage run and fix or baseline:
  - Run `composer phpstan` and capture the error list in `Auto Run Docs/Initiation/Working/phpstan-initial.txt`.
  - Fix trivially-correct issues (missing type hints, unused imports, wrong return types) where the risk is LOW. Before touching any service method, run `gitnexus_impact({target: "<method>", direction: "upstream"})` and skip anything HIGH/CRITICAL — baseline those instead.
  - Generate a baseline for the remainder: `./vendor/bin/phpstan analyse --generate-baseline phpstan-baseline.neon`. Include the baseline from `phpstan.neon` via `includes: [phpstan-baseline.neon]`.
  - Re-run `composer phpstan` and confirm zero errors.

- [ ] Update CI to run both tools:
  - Add/extend a `static-analysis` job in `.github/workflows/ci.yml` that runs `composer install`, then `./vendor/bin/pint --test` and `composer phpstan`.
  - Remove the `continue-on-error` / TODO comment from the Pint job added in Phase 02 now that style is clean.
  - Ensure the job runs in parallel with `test` and `lint`.

- [ ] Verify end-to-end:
  - Run `php artisan test` to confirm formatting changes did not break runtime behaviour.
  - Run `./vendor/bin/pint --test` and `composer phpstan` — both should exit 0.
  - Run `gitnexus_detect_changes({scope: "all"})` and review the scope. Expected changes: `pint.json`, `phpstan.neon`, `phpstan-baseline.neon`, `composer.json`, `composer.lock`, `.github/workflows/ci.yml`, plus formatting-only edits across `app/`.

- [ ] Commit as two logical commits: `style: apply Laravel Pint formatting` (pure formatting) and `chore: add PHPStan with Larastan and wire into CI` (config + CI + baseline). Do not push.
