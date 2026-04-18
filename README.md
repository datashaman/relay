# Relay

[![CI](https://github.com/datashaman/relay/actions/workflows/ci.yml/badge.svg)](https://github.com/datashaman/relay/actions/workflows/ci.yml)

An agentic issue pipeline that moves issues through four stages — **Preflight → Implement → Verify → Release** — each handled by a focused agent with a bounded tool set. Human-in-the-loop is configurable at workspace, stage, and issue scopes.

Relay ships as a local-first native app built on Laravel 13 / PHP 8.4 with NativePHP for desktop (macOS, Windows, Linux) and mobile (iOS, Android). No cloud backend required.

## Requirements

- PHP 8.4+
- Composer 2
- Node.js 18+ and npm
- SQLite
- At least one AI provider API key (Anthropic, OpenAI, Gemini) or Claude Code CLI installed

## Install

```bash
git clone <repo-url> relay && cd relay
composer setup
```

`composer setup` runs: `composer install`, copies `.env.example` → `.env`, generates an app key, runs migrations, installs npm dependencies, and builds frontend assets.

## Configure

Copy `.env.example` to `.env` and set your provider credentials:

```env
# AI provider (anthropic, openai, gemini, claude_code_cli)
AI_PROVIDER=anthropic
ANTHROPIC_API_KEY=sk-...

# GitHub OAuth (for issue sync and PR creation)
GITHUB_CLIENT_ID=
GITHUB_CLIENT_SECRET=

# Jira OAuth (optional)
JIRA_CLIENT_ID=
JIRA_CLIENT_SECRET=
```

Provider selection can also be configured per-workspace and per-stage through the UI.

## Run (development)

```bash
composer dev
```

This starts four processes concurrently:
- Laravel dev server (`php artisan serve`)
- Queue worker (`php artisan queue:listen`)
- Log tail (`php artisan pail`)
- Vite dev server (`npm run dev`)

## Run (desktop app)

```bash
composer native:dev
```

Launches the NativePHP Electron desktop window with Vite hot-reload.

## Build (desktop)

```bash
php artisan native:build
```

Produces installable packages for macOS, Windows, and Linux.

## Build (mobile)

```bash
php artisan native:build:ios
php artisan native:build:android
```

Requires the NativePHP mobile package (vendored in `packages/nativephp-mobile/`).

## Test

```bash
composer test
```

Runs `php artisan config:clear` then `php artisan test`. Tests use in-memory SQLite and a sync queue driver.

## Project Structure

```
app/
├── Contracts/       # Interfaces (AiProvider, etc.)
├── Enums/           # Backed string enums (StageName, AutonomyLevel, etc.)
├── Events/          # Broadcast events (StageTransitioned, RunStuck, etc.)
├── Http/Controllers/# Route controllers
├── Jobs/            # Queue jobs (ExecuteStageJob, SyncSourceIssuesJob)
├── Models/          # Eloquent models
├── Providers/       # Service providers
└── Services/        # Business logic, agents, AI providers
config/
├── ai.php           # AI provider configuration
├── relay.php        # App-level settings (iteration cap, deploy hook)
├── nativephp.php    # NativePHP build and updater config
└── services.php     # OAuth credentials (GitHub, Jira)
database/
├── factories/       # Model factories for testing
├── migrations/      # Schema migrations
└── seeders/         # Default data (global autonomy config)
packages/            # Vendored NativePHP packages with L13 patches
tests/
├── Feature/         # Feature and integration tests
├── Unit/            # Unit tests
└── fixtures/        # Recorded API response fixtures
```

## Documentation

Start at [docs/README.md](docs/README.md). The docs are organised by the [Diataxis](https://diataxis.fr/) framework:

- **Tutorials** — first-run walkthrough and configuring a custom AI provider
- **How-to guides** — source connectors, autonomy, adding providers/stages, plus a [troubleshooting](docs/how-to/troubleshooting/index.md) section keyed to the `pipeline` log channel
- **Explanation** — [Architecture](docs/explanation/architecture.md)
- **Reference** — per-agent, per-provider, and per-service pages; see the [index](docs/README.md#reference)

See [CONTRIBUTING.md](CONTRIBUTING.md) for development workflow and conventions.
