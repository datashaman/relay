---
type: reference
title: OpenAI Provider
created: 2026-04-18
tags: [reference, ai-provider, openai]
related:
  - "[[anthropic]]"
  - "[[gemini]]"
  - "[[claude-code-cli]]"
  - "[[configuration]]"
---

# OpenAI Provider

**Service:** `App\Services\AiProviders\OpenAiProvider`
**Contract:** `App\Contracts\AiProvider`
**Provider key:** `openai`

## Purpose

Adapter for the OpenAI Chat Completions API. Normalizes request/response to the shared `AiProvider` contract.

## Config keys (`config/ai.providers.openai`)

| Key | Env var | Default |
|---|---|---|
| `api_key` | `OPENAI_API_KEY` | — (required) |
| `model` | `OPENAI_MODEL` | `gpt-4o` |
| `base_url` | `OPENAI_BASE_URL` | `https://api.openai.com` |

Runtime overrides (per-workspace / per-stage) live in `provider_configs.settings` — see [[configuration]].

## Required env vars

- `OPENAI_API_KEY` — platform.openai.com key with `chat.completions.create` permission.

## Supported models

Any model the account can invoke via `v1/chat/completions` — `gpt-4o`, `gpt-4o-mini`, `o1`, etc. The provider does not validate the name; it forwards `options.model` verbatim.

## Request shape

`chat(messages, tools, options)`:

- `messages`: passed through unchanged (OpenAI accepts the same role shape Relay emits).
- `tools`: mapped to the function-call shape `{type: 'function', function: {name, description, parameters}}`.
- `options`:
  - `model` — override.
  - `max_tokens` — included only when set (omitted by default to preserve model-specific limits).
  - `log_context` — threaded into `ai_call` / `ai_error` log entries.

Endpoint: `POST {base_url}/v1/chat/completions`.
Auth: `Authorization: Bearer {api_key}` via `Http::withToken()`.

## Response shape (normalized)

```php
[
  'content' => string|null,            // choices[0].message.content
  'tool_calls' => [                     // choices[0].message.tool_calls
    ['id' => …, 'name' => …, 'arguments' => array (parsed from JSON)],
  ],
  'usage' => [
    'input_tokens' => int,              // maps from prompt_tokens
    'output_tokens' => int,             // maps from completion_tokens
  ],
  'raw' => [...],
]
```

## Token accounting

Tokens are read from `data.usage.prompt_tokens` / `completion_tokens` and renamed to `input_tokens` / `output_tokens` for consistency with [[anthropic]] and [[gemini]]. Emitted via `PipelineLogger::aiCall`.

## Streaming

`stream()` issues the same POST with `stream: true`, parses SSE frames, and yields `content` chunks keyed on `delta.content`. Tool-call streaming is not currently normalized — only text content is yielded.

## Error handling

- `RequestException` on non-2xx → `PipelineLogger::aiError(provider: 'openai', …)` then rethrown. Common statuses:
  - `401` — missing/invalid key.
  - `429` — rate limit or quota exhausted.
  - `400` — bad request (usually model or tool schema).

## Known limitations

- Tool-call arguments are JSON-decoded opportunistically; malformed JSON becomes an empty array (`[]`) rather than throwing, which can mask agent errors.
- Streaming yields only text content; tool calls arrive via the non-streaming `chat()` path.
- No automatic retry on `429`. The caller (agent tool loop) will surface the exception to `ExecuteStageJob`.

## Collaborators

- Instantiated by `AiProviderManager::make('openai', settings)`.
- Called by any stage agent when the provider cascade resolves to `openai`.
- Emits structured logs through `PipelineLogger`.
