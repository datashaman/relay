# Relay Documentation

This directory is organised using the [Diataxis](https://diataxis.fr/) framework.

## Tutorials

Learning-oriented walkthroughs for new users.

- [Running your first issue through the pipeline](tutorials/first-run.md)
- [Configure a custom AI provider](tutorials/configure-custom-ai-provider.md)

## How-to guides

Task-oriented recipes for practitioners.

- [Connect a GitHub source](how-to/connect-github.md)
- [Connect a Jira source](how-to/connect-jira.md)
- [Configure autonomy levels](how-to/configure-autonomy.md)
- [Add a new AI provider](how-to/add-ai-provider.md)
- [Add a new agent stage](how-to/add-stage.md)

### Troubleshooting

- [Troubleshooting index](how-to/troubleshooting/index.md) — entry point keyed to `pipeline` log `event` values
- [AI provider errors](how-to/troubleshooting/ai-provider-errors.md) — rate limits, auth failures, malformed responses
- [Stage failures](how-to/troubleshooting/stage-failures.md) — what to check when Preflight / Implement / Verify / Release fail
- [Merge conflicts](how-to/troubleshooting/merge-conflicts.md) — using the AI-assisted conflict resolution flow

## Explanation

Understanding-oriented discussion of the design.

- [Architecture](explanation/architecture.md) — pipeline, orchestrator, autonomy resolution, provider adapters, data model

## Reference

Information-oriented technical descriptions.

### Agents

- [Preflight](reference/agents/preflight.md)
- [Implement](reference/agents/implement.md)
- [Verify](reference/agents/verify.md)
- [Release](reference/agents/release.md)

### AI providers

- [Anthropic](reference/ai-providers/anthropic.md)
- [OpenAI](reference/ai-providers/openai.md)
- [Gemini](reference/ai-providers/gemini.md)
- [Claude Code CLI](reference/ai-providers/claude-code-cli.md)

### Orchestration services

- [Orchestrator](reference/orchestrator.md)
- [Autonomy resolver](reference/autonomy-resolver.md)
- [Filter rules](reference/filter-rules.md)
- [Escalation rules](reference/escalation-rules.md)
- [Merge conflict detector](reference/merge-conflict-detector.md)
- [Worktree service](reference/worktree-service.md)

### Operational reference

- [Configuration](reference/configuration.md) — environment variables and config files
- [Stuck states](reference/stuck-states.md) — failure modes and resolutions
- [Audit matrix](reference/_audit.md) — Diataxis coverage for every code surface
