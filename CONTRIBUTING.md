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

## Adding a New AI Provider

1. **Create the provider class** in `app/Services/AiProviders/`:

```php
<?php

namespace App\Services\AiProviders;

use App\Contracts\AiProvider;

class MyProvider implements AiProvider
{
    public function __construct(private array $config) {}

    public function chat(array $messages, array $tools = [], array $options = []): array
    {
        // Make API call, return normalized response:
        return [
            'content' => $responseText,
            'tool_calls' => [
                ['id' => '...', 'name' => '...', 'arguments' => [...]],
            ],
            'usage' => ['input_tokens' => 0, 'output_tokens' => 0],
            'raw' => $rawApiResponse,
        ];
    }

    public function stream(array $messages, array $tools = [], array $options = []): \Generator
    {
        // Yield chunks:
        yield ['type' => 'text', 'content' => '...', 'tool_calls' => null, 'usage' => null];
    }
}
```

2. **Register in `AiProviderManager`** — add a case to the `make()` method:

```php
'my_provider' => new MyProvider($settings),
```

3. **Add config** in `config/ai.php`:

```php
'my_provider' => [
    'api_key' => env('MY_PROVIDER_API_KEY'),
    'model' => env('MY_PROVIDER_MODEL', 'default-model'),
    'base_url' => env('MY_PROVIDER_BASE_URL', 'https://api.example.com'),
],
```

4. **Write tests** with recorded response fixtures in `tests/fixtures/`:

```php
Http::fake([
    'api.example.com/*' => Http::response(
        file_get_contents(base_path('tests/fixtures/my_provider_chat.json')),
        200
    ),
]);
```

5. **Normalize tool calls** — the `chat()` return must always use this structure regardless of the provider's native format:

```php
'tool_calls' => [
    ['id' => 'call_123', 'name' => 'tool_name', 'arguments' => ['key' => 'value']],
]
```

## Adding a New Agent Stage

1. Create the agent service in `app/Services/` with `SYSTEM_PROMPT` and `TOOLS` constants
2. Add the stage to `StageName` enum
3. Add a case in `ExecuteStageJob`'s match expression
4. Update `OrchestratorService::STAGE_ORDER`
5. Update the "ignores unhandled stages" test

## Testing

- Use `Http::fake()` for external API mocking
- Use `Process::fake()` with callback-based faking (not wildcard strings) for CLI tools
- Use `Event::fake([SpecificEvent::class])` for broadcast assertions
- Use `Queue::assertPushed()` for job dispatch assertions
- Tests run against in-memory SQLite with the sync queue driver
