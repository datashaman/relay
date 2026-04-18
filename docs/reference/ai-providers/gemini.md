---
type: reference
title: Gemini Provider
created: 2026-04-18
tags: [reference, ai-provider, gemini]
related:
  - "[[anthropic]]"
  - "[[openai]]"
  - "[[claude-code-cli]]"
  - "[[configuration]]"
---

# Gemini Provider

**Service:** `App\Services\AiProviders\GeminiProvider`
**Contract:** `App\Contracts\AiProvider`
**Provider key:** `gemini`

## Purpose

Adapter for Google's Generative Language API (`generativelanguage.googleapis.com`). Normalizes request/response to the shared `AiProvider` contract.

## Config keys (`config/ai.providers.gemini`)

| Key | Env var | Default |
|---|---|---|
| `api_key` | `GEMINI_API_KEY` | — (required) |
| `model` | `GEMINI_MODEL` | `gemini-2.5-flash` |
| `base_url` | `GEMINI_BASE_URL` | `https://generativelanguage.googleapis.com` |

Runtime overrides in `provider_configs.settings` — see [[configuration]].

## Required env vars

- `GEMINI_API_KEY` — Google AI Studio key; passed as a URL query parameter (`?key=...`).

## Supported models

Any Gemini model exposed by the `v1beta/models/{model}:generateContent` endpoint — `gemini-2.5-flash`, `gemini-2.5-pro`, `gemini-2.0-flash`, etc.

## Request shape

`chat(messages, tools, options)`:

- `messages`: system messages are extracted into `body.systemInstruction`; remaining messages are mapped — `user` stays `user`, `assistant` becomes `model`. Each message becomes `{role, parts: [{text: content}]}`.
- `tools`: wrapped under `body.tools[0].functionDeclarations`, one entry per tool (`name`, `description`, `parameters`).
- `options`:
  - `model` — override.
  - `max_tokens` — emitted as `generationConfig.maxOutputTokens` when set.
  - `log_context` — threaded into `ai_call` / `ai_error`.

Endpoint: `POST {base_url}/v1beta/models/{model}:generateContent?key={api_key}`.

## Response shape (normalized)

```php
[
  'content' => string|null,              // concatenated candidates[0].content.parts[*].text
  'tool_calls' => [                       // parts with `functionCall`
    ['id' => 'gemini_<uniqid>', 'name' => …, 'arguments' => …],
  ],
  'usage' => [
    'input_tokens' => int,                // maps from usageMetadata.promptTokenCount
    'output_tokens' => int,               // maps from usageMetadata.candidatesTokenCount
  ],
  'raw' => [...],
]
```

## Token accounting

Read from `data.usageMetadata.promptTokenCount` / `candidatesTokenCount` and remapped to `input_tokens` / `output_tokens`. Emitted via `PipelineLogger::aiCall`.

## Streaming

`stream()` calls `:streamGenerateContent?alt=sse` and yields `content` frames carrying `candidates[0].content.parts[0].text`. Tool-call streaming is not normalized.

## Error handling

- `RequestException` on non-2xx → `PipelineLogger::aiError(provider: 'gemini', …)` then rethrown. Common statuses:
  - `400 INVALID_ARGUMENT` — malformed `parts` or unsupported tool schema.
  - `403 PERMISSION_DENIED` — API key lacks access to the requested model.
  - `429 RESOURCE_EXHAUSTED` — quota.

## Known limitations

- Tool-call IDs are synthesized (`gemini_<uniqid>`) because Gemini responses omit call IDs.
- Tool `parameters` schemas must use Google's OpenAPI-ish flavor (`type: "OBJECT"` vs JSON Schema's `"object"`). The provider passes schemas through as-is; agents are expected to author schemas that work across providers.
- The API key travels as a query parameter; avoid logging full URLs. `PipelineLogger` never logs URLs but anything that proxies `Http::post` output might.

## Collaborators

- Instantiated by `AiProviderManager::make('gemini', settings)`.
- Called by any stage agent when the provider cascade resolves to `gemini`.
- Emits structured logs through `PipelineLogger`.
