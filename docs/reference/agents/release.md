---
type: reference
title: Release Agent
created: 2026-04-18
tags: [reference, agent, release]
related:
  - "[[preflight]]"
  - "[[implement]]"
  - "[[verify]]"
  - "[[orchestrator]]"
  - "[[worktree-service]]"
  - "[[configuration]]"
---

# Release Agent

**Service:** `App\Services\ReleaseAgent`
**Stage:** `StageName::Release`
**Color:** Teal `#1D9E75`

## Purpose

Commits the implement agent's changes, pushes the branch, opens a pull request, updates the changelog, and optionally triggers deployment. Cannot modify source code — only commits and pushes what the implement agent produced.

## Inputs

- `Stage` with `run->worktree_path`, `run->branch`.
- `Run::$preflight_doc` — basis for the commit message and PR body.
- `Run::$issue->source->oauthTokens` — GitHub API credentials.
- `Run::$repository` (falls back to `$issue->repository`) — must be in `owner/repo` format.
- `config('relay.changelog_path')`, `config('relay.deploy_hook')`.

## Outputs

- Git commit + push to the remote on `run->branch`.
- Pull request on the repository default branch.
- Optional changelog entry at `config('relay.changelog_path')`.
- Optional deploy hook invocation.
- `StageEvent` rows: `release_started`, `tool_call`, `pr_created`, `deploy_triggered`, `release_complete`, `release_loop_limit`.
- `ReleaseProgressUpdated` events broadcast to the UI at each step (`committed`, `pushed`, `pr_created`, `changelog_updated`, `deploy_triggered`, `complete`).
- Run completes via `OrchestratorService->complete(Stage, ['pr_url' => ...])`.

## Tools

| Tool | Description |
|------|-------------|
| `git_commit` | Stage + commit current worktree changes with the supplied message. |
| `git_push` | Push `run->branch` to origin. `force` flag supported. |
| `create_pr` | Open a pull request via the GitHub API using the source OAuth token. |
| `write_changelog` | Prepend an entry to `config('relay.changelog_path')`. |
| `trigger_deploy` | Execute `config('relay.deploy_hook')` as a shell command. |
| `read_file` | Read a file's contents. |
| `list_files` | List files in a directory. |
| `git_diff` | Show the current diff. |
| `git_log` | Show recent commits. |
| `run_shell` | Execute a shell command. |
| `release_complete` | Signal that the release process is done. |

## Behavior

1. Receives the run context including the preflight doc and implement diff.
2. Commits changes with a message derived from the preflight doc.
3. Pushes the branch to the remote.
4. Creates a pull request via `GitHubClient`.
5. Updates the changelog.
6. Optionally triggers deployment.
7. Signals `release_complete`.

## Dependencies

- Requires `issue.source.oauthTokens` for GitHub API authentication.
- Requires a repository with name in `owner/repo` format. The agent reads `run->repository` (set at run start); falls back to `issue->repository` for older runs predating the explicit field.
- Changelog path and deploy hook configured in `config/relay.php`.

## Emitted log events

| `event` value | Level | Additional fields |
|---|---|---|
| `release.execute_started` | info | `stage`, `iteration`, `branch` |
| `release.pr_created` | info | `stage`, `pr_url`, `pr_number` |
| `release.deploy_triggered` | info | `stage`, `exit_code`, `success` |
| `release.complete` | info | `stage`, `iteration`, `pr_url` |
| `release.loop_limit` | info | `stage`, `iteration`, `max_loops` |
| `stage_started` / `stage_completed` / `stage_failed` | info / info / error | orchestrator boundary events |
| `run_completed` | info | emitted when the release completes the final stage |
| `ai_call` / `ai_error` | info / error | per provider call |

Grep recipe: `jq 'select(.event | startswith("release."))' storage/logs/pipeline-*.log`.

## Collaborators

**Upstream (callers):**

- `App\Jobs\ExecuteStageJob`.

**Downstream (dependencies):**

- `App\Services\AiProviderManager` — provider for `Release`.
- `App\Services\OrchestratorService` — `complete()`, `fail()`.
- `App\Services\GitHubClient` — PR creation.
- `App\Services\OauthService` — token refresh when making GitHub calls.
- `App\Support\Logging\PipelineLogger`, `App\Models\StageEvent`, `App\Events\ReleaseProgressUpdated`.

## Error modes

- Missing `worktree_path` or `branch` — tool returns an `Error:` string; agent should signal completion with no PR.
- Missing OAuth token — `create_pr` returns `Error: No OAuth token available for this source.`.
- Repository not in `owner/repo` format — `create_pr` returns an `Error:` string.
- GitHub API failure — `create_pr` catches `Throwable`, returns the error message; agent can retry or escalate.
- Deploy hook exit non-zero — `trigger_deploy` returns the truncated output and a failure prefix.
- Tool-loop exhaustion — `fail()` with `Release agent exceeded maximum tool call loops.`.

## Constraints

- Cannot modify source code.
- Cannot run tests.
- Only commits and pushes what the implement agent produced.
