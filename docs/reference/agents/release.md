# Release Agent

**Service:** `App\Services\ReleaseAgent`
**Stage:** `StageName::Release`
**Color:** Teal `#1D9E75`

## Purpose

Commits the implement agent's changes, pushes the branch, opens a pull request, updates the changelog, and optionally triggers deployment. Cannot modify source code — only commits and pushes what the implement agent produced.

## Tools

| Tool | Description |
|------|-------------|
| `commit_changes` | Create a git commit with a generated message based on the preflight doc and diff. |
| `push_branch` | Push the branch to the remote repository. |
| `create_pr` | Open a pull request via the GitHub API. PR body generated from the preflight doc. |
| `update_changelog` | Append an entry to the configured changelog file (`config('relay.changelog_path')`). |
| `trigger_deploy` | Fire the optional deploy hook (`config('relay.deploy_hook')`) after PR merge. |
| `read_file` | Read a file's contents. |
| `list_files` | List files in a directory. |
| `git_diff` | Show the current diff. |
| `run_shell` | Execute a shell command. |
| `release_complete` | Signal that the release process is done. |

## Behavior

1. Receives the run context including the preflight doc and implement diff
2. Commits changes with a message derived from the preflight doc
3. Pushes the branch to the remote
4. Creates a pull request via `GitHubClient`
5. Updates the changelog
6. Optionally triggers deployment
7. Signals `release_complete`

## Dependencies

- Requires `issue.source.oauthTokens` for GitHub API authentication
- Requires `issue.repository` with name in `owner/repo` format
- Changelog path and deploy hook configured in `config/relay.php`

## Constraints

- Cannot modify source code
- Cannot run tests
- Only commits and pushes what the implement agent produced
