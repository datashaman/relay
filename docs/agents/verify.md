# Verify Agent

**Service:** `App\Services\VerifyAgent`
**Stage:** `StageName::Verify`
**Color:** Green `#639922`

## Purpose

Runs tests, static analysis, and coverage diffs against the implement agent's output. Enforces quality gates before release. Cannot edit files — read-only access to the worktree.

## Tools

| Tool | Description |
|------|-------------|
| `run_tests` | Execute the configured test suite. Runner auto-detected: checks `vendor/bin/` for pest/phpunit and `node_modules/.bin/` for jest/mocha/vitest. |
| `run_static_analysis` | Run static analysis tools against the codebase. |
| `coverage_diff` | Compare test coverage before and after the implement agent's changes. |
| `read_file` | Read a file's contents (read-only). |
| `list_files` | List files in a directory. |
| `run_shell` | Execute a shell command (read-only context). |
| `git_diff` | Show the diff of changes made by the implement agent. |
| `verification_complete` | Signal verification result (pass or fail with structured report). |

## Behavior

1. Receives the run context including the preflight document and implement diff
2. Runs the test suite and static analysis
3. Compares coverage metrics
4. If all gates pass: signals `verification_complete` with pass → orchestrator advances to Release
5. If any gate fails: emits a structured failure report and signals fail → orchestrator bounces back to Implement

## Failure Report Structure

When verification fails, the report includes:
- Test name
- Assertion / error message
- File path
- Line number

This report travels back to the implement agent as a patch target on bounce.

## Constraints

- Cannot edit or write files
- Cannot push or create PRs
- Read-only access to the worktree
