---
type: tutorial
title: Configure a custom AI provider
created: 2026-04-18
tags: [tutorial, ai-provider, configuration]
related:
  - "[[first-run]]"
  - "[[add-ai-provider]]"
  - "[[configuration]]"
  - "[[anthropic]]"
  - "[[openai]]"
  - "[[gemini]]"
  - "[[claude-code-cli]]"
  - "[[architecture]]"
---

# Configure a custom AI provider

In this tutorial, we will point Relay at a different AI provider than the default, then narrow that choice to a single pipeline stage so that — for example — Verify uses a cheap, fast model while Implement uses a stronger one.

By the end, you will have the provider cascade resolving three different providers for three different contexts: a global default, a stage override, and a workspace override.

If you have not yet completed [[first-run]], do that first — this tutorial assumes you already have a running pipeline and at least one workspace.

## Before you begin

- Relay is installed and `composer dev` is running (see [README](../../README.md))
- You have API keys for at least two of the four built-in providers: Anthropic, OpenAI, Gemini, or the Claude Code CLI (local)
- You have read the [[add-ai-provider]] how-to if you plan to add a brand-new provider class — this tutorial covers only the four that ship with Relay

## Step 1: Set the global default

The global default is what `AiProviderManager::resolve()` falls back to when no `ProviderConfig` row matches. It is controlled by a single environment variable:

```bash
# .env
AI_PROVIDER=anthropic
```

Valid values are `anthropic`, `openai`, `gemini`, and `claude_code_cli`. Each provider then reads its own env vars for credentials and model selection — see [[configuration]] for the full table.

For this tutorial, set:

```bash
AI_PROVIDER=anthropic
ANTHROPIC_API_KEY=sk-ant-…
OPENAI_API_KEY=sk-…
GEMINI_API_KEY=…
```

Restart `composer dev` so Laravel re-reads the env file.

## Step 2: Override the provider for a single stage

Provider overrides are stored in the `provider_configs` table. A row is keyed by `(scope, scope_id, stage)`:

| Field | Meaning |
| --- | --- |
| `scope` | `global` or `workspace` |
| `scope_id` | the workspace id when `scope = workspace`, else `null` |
| `stage` | `preflight`, `implement`, `verify`, `release`, or `null` for "all stages" |
| `provider` | provider key: `anthropic`, `openai`, `gemini`, `claude_code_cli` |
| `settings` | JSON merged over the static `config/ai.providers.<key>` values |

The manager walks the cascade from most specific to least: workspace+stage → workspace → global+stage → global → default env. See `App\Services\AiProviders\AiProviderManager::resolveConfig()` for the exact order.

To point Verify at a cheaper, faster model while leaving the rest of the pipeline on the default, open `php artisan tinker`:

```php
\App\Models\ProviderConfig::create([
    'provider' => 'gemini',
    'scope'    => 'global',
    'scope_id' => null,
    'stage'    => \App\Enums\StageName::Verify,
    'settings' => [
        'model' => 'gemini-2.5-flash',
    ],
]);
```

The `settings` JSON is merged over `config/ai.providers.gemini`, so you only need to specify fields you want to override — `api_key` and `base_url` come from the env.

## Step 3: Override the provider for one workspace

Perhaps a particular workspace needs to run fully offline on the local Claude Code CLI. Add a workspace-scoped row:

```php
\App\Models\ProviderConfig::create([
    'provider' => 'claude_code_cli',
    'scope'    => 'workspace',
    'scope_id' => $workspaceId,      // id of the target workspace
    'stage'    => null,              // applies to every stage
    'settings' => [
        'working_directory' => '/path/to/local/checkout',
        'timeout'           => 600,
    ],
]);
```

Because workspace+stage is checked before workspace-only, you can layer both: a workspace-wide `claude_code_cli` plus a workspace+Verify override that routes Verify back to `gemini`.

## Step 4: Confirm the cascade resolves correctly

Still in `tinker`, call the resolver directly:

```php
$mgr = app(\App\Services\AiProviders\AiProviderManager::class);

get_class($mgr->resolve());                                                  // global default
get_class($mgr->resolve(stage: \App\Enums\StageName::Verify));               // stage override
get_class($mgr->resolve(workspaceId: $workspaceId));                         // workspace override
get_class($mgr->resolve(workspaceId: $workspaceId,
                        stage: \App\Enums\StageName::Verify));               // workspace+stage wins
```

Each call returns the concrete adapter (`AnthropicProvider`, `GeminiProvider`, `ClaudeCodeCliProvider`, …) that `resolve()` picked for that context.

## Step 5: Watch it fire in a real run

Accept a small issue (as in [[first-run]]) and watch the timeline. The `pipeline` log channel emits an `ai_call` event for every provider invocation, tagged with the provider key, model, and token usage. Tail it in a second terminal:

```bash
tail -f storage/logs/pipeline.log | jq 'select(.event == "ai_call") | {stage, provider, model, tokens_prompt, tokens_completion}'
```

You should see `gemini` during Verify and your default during the other stages. If the workspace override is active for this run, Claude Code CLI will appear for every stage except Verify.

## What you've built

You now have a three-level provider cascade wired up: a global default, a stage-scoped override, and a workspace-scoped override — all resolving through `AiProviderManager` without touching the stage agents themselves.

## Next steps

- Read [[add-ai-provider]] if the four built-in adapters aren't enough and you need to add your own
- Browse the per-provider reference pages — [[anthropic]], [[openai]], [[gemini]], [[claude-code-cli]] — for request/response shapes, token accounting, and known limitations
- See the [[architecture]] explanation page for how the provider cascade fits into the wider orchestrator
- When a provider misbehaves, the [[ai-provider-errors]] troubleshooting guide has `jq` recipes keyed to the same pipeline log channel you tailed in Step 5
