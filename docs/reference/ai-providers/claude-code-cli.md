---
type: reference
title: Claude Code CLI Provider
created: 2026-04-18
tags: [reference, ai-provider, claude-code-cli]
related:
  - "[[anthropic]]"
  - "[[openai]]"
  - "[[gemini]]"
  - "[[configuration]]"
  - "[[implement]]"
---

# Claude Code CLI Provider

**Service:** `App\Services\AiProviders\ClaudeCodeCliProvider`
**Contract:** `App\Contracts\AiProvider`
**Provider key:** `claude_code_cli`

## Purpose

Runs Anthropic's Claude Code CLI as a subprocess, consuming its NDJSON `stream-json` output, and exposes the result through the shared `AiProvider` contract. Unlike HTTP providers, the model uses its own built-in tools (Read, Write, Edit, Bash, Grep, Glob) to operate directly on the worktree — Relay's agent tool surface is only used as the "terminal" signal (e.g. `implementation_complete`).

## Config keys (`config/ai.providers.claude_code_cli`)

| Key | Env var | Default |
|---|---|---|
| `command` | `CLAUDE_CODE_COMMAND` | `claude --dangerously-skip-permissions --print --output-format stream-json --verbose` |
| `working_directory` | `CLAUDE_CODE_WORKING_DIR` | — (falls back to `options.cwd` which the agents set to the run's worktree) |
| `timeout` | `CLAUDE_CODE_TIMEOUT` | `300` seconds |

Runtime overrides in `provider_configs.settings` — see [[configuration]].

## Required env vars

No API key — the CLI handles its own auth via `claude` user config on the host. Ensure `claude` is on `$PATH` for the queue worker user.

## Supported models

Whatever `claude --model ...` accepts. The provider forwards `options.model` as `--model <name>`; if absent, the CLI's own default applies.

## Request shape

`chat(messages, tools, options)`:

- `messages`: all message `content` strings concatenated with blank-line separators. Role is ignored — the CLI sees a single prompt.
- `tools`: used only to locate the **terminal** tool:
  - single-tool flows (preflight assess / doc): that tool.
  - multi-tool flows (implement/verify/release): the tool whose name ends in `_complete`.
  When a terminal tool is found, the provider appends a "Response Format" footer that instructs the model to emit a single JSON object matching the tool's `parameters` schema. That JSON is synthesized into a `tool_calls` entry on return.
- `options`:
  - `model` — passed as `--model`.
  - `cwd` — working directory override (agents set this to the run's worktree).
  - `allowedTools` — each value becomes `--allowedTools <name>`.
  - `log_context` — attached to `ai_call` / `ai_error` log entries.

Invocation:
```
claude --dangerously-skip-permissions --print --output-format stream-json --verbose \
  [--model <model>] [--allowedTools <tool>]... -- <prompt>
```

## Response shape (normalized)

```php
[
  'content' => string,                 // concatenated assistant text blocks, or the final `result` event
  'tool_calls' => [                     // tool_use blocks from the CLI + synthesized terminal call
    ['id' => …, 'name' => …, 'arguments' => …],
  ],
  'usage' => [
    'input_tokens' => int,              // from the final `result` event
    'output_tokens' => int,
  ],
  'raw' => [...],                      // every decoded NDJSON event
]
```

The terminal JSON is extracted from the `content` text via a lenient parser that handles bare objects, fenced ```json blocks, and objects embedded in prose.

## Token accounting

Taken from the `result` event at the end of the stream (`event.usage.input_tokens` / `output_tokens`). Emitted via `PipelineLogger::aiCall(provider: 'claude_code_cli', …)`.

## Streaming

`stream()` iterates the subprocess's stdout line-by-line and yields normalized events: `content` (from `assistant` events), `done` (from `result`), or `other`. No tool-call streaming is normalized.

## Error handling

- Non-zero exit → `PipelineLogger::aiError(provider: 'claude_code_cli', model, exit_code, stderr)` and `RuntimeException("Claude Code CLI failed (exit N): <stderr>")`.
- Timeout (`CLAUDE_CODE_TIMEOUT`) → Symfony `ProcessTimedOutException` bubbles up.
- Missing terminal JSON in output → `tool_calls` is returned empty. For multi-tool flows the agent treats this as a no-op completion, which the orchestrator will either `complete()` or `fail()` on loop-limit.

## Known limitations

- One-shot only — each call spawns a fresh `claude` process. No session continuity; conversation history is re-sent on every call.
- All messages collapse into one prompt; the provider cannot distinguish system vs. user.
- Tool schemas must be authorable as JSON the model can return verbatim. Complex `anyOf` / `$ref` constructs won't round-trip reliably.
- Requires `claude` on the host; there is no HTTP fallback.

## Collaborators

- Instantiated by `AiProviderManager::make('claude_code_cli', settings)`.
- Most common consumer is [[implement]], where the CLI's built-in Read/Write/Bash/Grep do the actual editing.
- Emits structured logs through `PipelineLogger`.
