# Implement Agent

**Service:** `App\Services\ImplementAgent`
**Stage:** `StageName::Implement`
**Color:** Amber `#BA7517`

## Purpose

Produces a code diff inside the run's git worktree based on the preflight document. Operates in a tool-call loop: chat → execute tools → append results → chat again until signaling `implementation_complete`.

## Tools

| Tool | Description |
|------|-------------|
| `read_file` | Read a file's contents. Path must be within the worktree. |
| `write_file` | Write or overwrite a file. Path must be within the worktree. |
| `list_files` | List files in a directory within the worktree. |
| `run_shell` | Execute a shell command in the worktree. Timeouts and output caps enforced. |
| `run_linter` | Run the configured linter against specified files. |
| `git_status` | Show the current git status of the worktree. |
| `git_diff` | Show the current diff of uncommitted changes. |
| `implementation_complete` | Signal that implementation is done. Terminates the tool loop. |

## Behavior

1. Receives the preflight document as context (not the original issue)
2. On bounced iterations, receives the verify failure report prepended to context
3. Reads relevant files, makes edits, runs linter checks
4. Signals completion via `implementation_complete`
5. Live diff updates broadcast via `DiffUpdated` event

## Constraints

- All file operations scoped to the run's worktree — path escape attempts rejected
- Cannot run test suites (phpunit, pest, jest, mocha, pytest, rspec)
- Cannot push or create PRs (`git push`, `gh pr` blocked)
- Shell command timeouts and output size caps enforced
- Cannot run `rm -rf /`

## Blocked Commands

Test runners: `phpunit`, `pest`, `jest`, `mocha`, `pytest`, `rspec`
Git operations: `git push`, `git remote`, `gh pr`
Destructive: `rm -rf /`
