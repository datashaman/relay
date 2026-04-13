# Relay Documentation

This directory is organised using the [Diataxis](https://diataxis.fr/) framework.

## Tutorials

Learning-oriented walkthroughs for new users.

- [Running your first issue through the pipeline](tutorials/first-run.md)

## How-to guides

Task-oriented recipes for practitioners.

- [Connect a GitHub source](how-to/connect-github.md)
- [Connect a Jira source](how-to/connect-jira.md)
- [Configure autonomy levels](how-to/configure-autonomy.md)
- [Add a new AI provider](how-to/add-ai-provider.md)
- [Add a new agent stage](how-to/add-stage.md)

## Explanation

Understanding-oriented discussion of the design.

- [Architecture](explanation/architecture.md) — pipeline, orchestrator, autonomy resolution, provider adapters, data model

## Reference

Information-oriented technical descriptions.

- [Agents](reference/agents/) — per-stage tool surface, behavior, constraints
  - [Preflight](reference/agents/preflight.md)
  - [Implement](reference/agents/implement.md)
  - [Verify](reference/agents/verify.md)
  - [Release](reference/agents/release.md)
- [Configuration](reference/configuration.md) — environment variables and config files
- [Stuck states](reference/stuck-states.md) — failure modes and resolutions
