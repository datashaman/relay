# How to add a new AI provider

This guide shows you how to integrate an additional AI provider into Relay's provider cascade.

## Prerequisites

- Working Relay development environment (see [README](../../README.md))
- Provider API credentials or local binary path
- Familiarity with `App\Contracts\AiProvider`

## Steps

### 1. Create the provider class

Add a class under `app/Services/AiProviders/` that implements `App\Contracts\AiProvider`:

```php
<?php

namespace App\Services\AiProviders;

use App\Contracts\AiProvider;

class MyProvider implements AiProvider
{
    public function __construct(private array $config) {}

    public function chat(array $messages, array $tools = [], array $options = []): array
    {
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
        yield ['type' => 'text', 'content' => '...', 'tool_calls' => null, 'usage' => null];
    }
}
```

### 2. Normalize tool calls

The `chat()` return must always use this structure, regardless of the provider's native format:

```php
'tool_calls' => [
    ['id' => 'call_123', 'name' => 'tool_name', 'arguments' => ['key' => 'value']],
]
```

### 3. Register in `AiProviderManager`

Add a case to the `make()` method:

```php
'my_provider' => new MyProvider($settings),
```

### 4. Add config

In `config/ai.php`, under `providers`:

```php
'my_provider' => [
    'api_key' => env('MY_PROVIDER_API_KEY'),
    'model' => env('MY_PROVIDER_MODEL', 'default-model'),
    'base_url' => env('MY_PROVIDER_BASE_URL', 'https://api.example.com'),
],
```

### 5. Write tests with recorded fixtures

Store recorded API responses in `tests/fixtures/` and fake the HTTP layer:

```php
Http::fake([
    'api.example.com/*' => Http::response(
        file_get_contents(base_path('tests/fixtures/my_provider_chat.json')),
        200
    ),
]);
```

## See also

- [Configuration reference](../reference/configuration.md)
- [Architecture: provider adapters](../explanation/architecture.md#provider-adapters)
