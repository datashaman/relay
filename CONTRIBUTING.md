# Contributing

## Setup

```bash
composer setup
```

See [README.md](README.md) for prerequisites and configuration.

## Development Workflow

```bash
composer dev       # Start all dev processes
composer test      # Run tests
```

## Branching

- `main` — stable, deployable
- Feature branches: `feature/<description>`
- Bug fixes: `fix/<description>`

## Commit Style

```
feat: US-NNN - Short description
fix: Brief description of what was fixed
refactor: What was restructured
```

Prefix with `feat:`, `fix:`, `refactor:`, `test:`, or `docs:`. Keep the first line under 72 characters.

## Code Conventions

- **Services** go in `app/Services/` — inject via constructor
- **Contracts** (interfaces) go in `app/Contracts/`
- **Enums** go in `app/Enums/` as backed string enums
- **Model casts** use the `protected function casts(): array` method, not the `$casts` property
- **Sensitive fields** use the `encrypted` cast
- **Seeders** use `firstOrCreate` for idempotency
- **Test fixtures** for recorded API responses go in `tests/fixtures/`

## Testing

- Use `Http::fake()` for external API mocking
- Use `Process::fake()` with callback-based faking (not wildcard strings) for CLI tools
- Use `Event::fake([SpecificEvent::class])` for broadcast assertions
- Use `Queue::assertPushed()` for job dispatch assertions
- Tests run against in-memory SQLite with the sync queue driver

## Extending Relay

Task-oriented guides live in [`docs/how-to/`](docs/how-to/):

- [Connect a GitHub source](docs/how-to/connect-github.md)
- [Connect a Jira source](docs/how-to/connect-jira.md)
- [Configure autonomy levels](docs/how-to/configure-autonomy.md)
- [Add a new AI provider](docs/how-to/add-ai-provider.md)
- [Add a new agent stage](docs/how-to/add-stage.md)
