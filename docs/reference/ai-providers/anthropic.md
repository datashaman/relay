---
type: reference
title: Anthropic Provider
created: 2026-04-18
tags: [reference, ai-provider, anthropic]
related:
  - "[[openai]]"
  - "[[gemini]]"
  - "[[claude-code-cli]]"
  - "[[configuration]]"
  - "[[preflight]]"
  - "[[implement]]"
  - "[[verify]]"
  - "[[release]]"
---

# Anthropic Provider

**Service:** `App\Services\AiProviders\AnthropicProvider`
**Contract:** `App\Contracts\AiProvider`
**Provider key:** `anthropic`

## Purpose

Adapter for the Anthropic Messages API. Normalizes request/response to the shared `AiProvider` contract so every stage agent can target it interchangeably with [[openai]], [[gemini]], and [[claude-code-cli]].

## Config keys (`config/ai.providers.anthropic`)

| Key | Env var | Default |
|---|---|---|
| `api_key` | `ANTHROPIC_API_KEY` | — (required) |
| `model` | `ANTHROPIC_MODEL` | `claude-sonnet-4-6` |
| `base_url` | `ANTHROPIC_BASE_URL` | `https://api.anthropic.com` |

Runtime overrides (per-workspace / per-stage) are stored in `provider_configs.settings` and merged over the static config — see [[configuration]] for the scope cascade.

## Required env vars

- `ANTHROPIC_API_KEY` — anthropic.com API key with `messages.create` permission.

## Supported models

Whatever Anthropic's Messages API accepts today — `claude-sonnet-4-6`, `claude-opus-4-6`, `claude-haiku-4-5`, etc. The provider forwards `options.model` verbatim, so any string the account can call is valid.

## Request shape

`chat(messages, tools, options)`:

- `messages`: `[{role: 'system'|'user'|'assistant'|'tool', content: string}, …]`. System messages are extracted into `body.system`; everything else goes into `body.messages`.
- `tools`: mapped to Anthropic's tool shape (`name`, `description`, `input_schema`).
- `options`:
  - `model` — override the default.
  - `max_tokens` — default `4096`.
  - `log_context` — passed through to `PipelineLogger::aiCall` / `aiError` (includes `run_id`, `issue_id`, `stage`, `loop`).

Endpoint: `POST {base_url}/v1/messages`.
Headers: `x-api-key`, `anthropic-version: 2023-06-01`.

## Response shape (normalized)

```php
[
  'content' => string|null,           // concatenated text blocks
  'tool_calls' => [                    // each tool_use block
    ['id' => …, 'name' => …, 'arguments' => …],
  ],
  'usage' => [
    'input_tokens' => int,
    'output_tokens' => int,
  ],
  'raw' => [...],                     // the original API payload
]
```

## Token accounting

Token counts come from `data.usage.input_tokens` / `data.usage.output_tokens` on the `messages` response. Both are emitted on every successful call as `ai_call` log entries: `{event: "ai_call", provider: "anthropic", model, tokens_prompt, tokens_completion, duration_ms, …log_context}`.

## Streaming

`stream()` issues the same POST with `stream: true` and yields normalized SSE frames:
`content` (from `content_block_delta`), `done` (from `message_stop` — carries `usage`), or `other`.

## Error handling

- `Illuminate\Http\Client\RequestException` on non-2xx — logged via `PipelineLogger::aiError(provider, model, status, body, log_context)` then rethrown. Common statuses:
  - `401` — invalid or missing `ANTHROPIC_API_KEY`.
  - `429` — rate limit. See [[troubleshooting]] for pipeline-log grep recipes.
  - `400` — malformed request (usually model name or tool schema).

## Known limitations

- System prompts are coerced to a single string. If multiple `system` messages are passed, only the last survives.
- The `tools` array is remapped per-call with no caching. High-frequency use will incur a small serialization cost.
- Streaming aggregates the raw body then splits on newlines; this buffers the full response before yielding, so the "streaming" benefit is currently token-count accuracy rather than progressive display.

## Collaborators

- Instantiated by `AiProviderManager::make('anthropic', settings)`.
- Called by every agent (`PreflightAgent`, `ImplementAgent`, `VerifyAgent`, `ReleaseAgent`) via the manager's `resolve()`.
- Emits structured logs through `App\Support\Logging\PipelineLogger`.
