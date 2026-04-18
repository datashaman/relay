---
type: how-to
title: Resolving Merge Conflicts
created: 2026-04-18
tags: [how-to, troubleshooting, git, merge]
related:
  - "[[index]]"
  - "[[stage-failures]]"
  - "[[merge-conflict-detector]]"
  - "[[worktree-service]]"
  - "[[release]]"
  - "[[implement]]"
  - "[[stuck-states]]"
---

# Resolving Merge Conflicts

Use this guide when a run surfaces a conflict banner or the Release stage refuses to open a PR. Relay detects conflicts proactively via [[merge-conflict-detector]] and offers an AI-assisted resolution flow that reuses the [[implement]] agent.

## How conflicts are detected

`App\Services\MergeConflictDetector::probeAllActive()` runs on a schedule. For each active run it:

1. Aborts any leftover `MERGE_HEAD`.
2. Fetches the repository's default branch.
3. Dry-runs `git merge --no-commit --no-ff origin/<target>` in the worktree.
4. Captures unmerged paths via `git diff --name-only --diff-filter=U`.
5. Aborts the merge so the worktree is left untouched.

The probe returns one of four outcomes (`OUTCOME_CLEAN`, `OUTCOME_CONFLICT`, `OUTCOME_SKIPPED`, `OUTCOME_ERROR` — see [[merge-conflict-detector]]). On `OUTCOME_CONFLICT`, the run is flagged:

- `runs.has_conflicts = true`
- `runs.conflict_detected_at = now()`
- `runs.conflict_files = [...]`
- A `conflict_detected` `StageEvent` is recorded on the latest stage.

The UI surfaces this as a banner on the run detail page.

## Resolve via the UI

1. Open the run detail page.
2. Click **Resolve conflicts**. This issues `POST /issues/runs/{run}/resolve-conflicts` (`IssueViewController::resolveConflicts`), which dispatches `App\Jobs\ResolveConflictsJob`.
3. The job:
   - Creates a resolution `Stage` reusing the latest stage's name/iteration.
   - Fetches + starts the merge (`git merge --no-commit --no-ff origin/<target>`).
   - If there are no conflict files, commits and pushes immediately.
   - Otherwise, builds a synthetic preflight doc listing the conflicted files and hands off to `ImplementAgent` with `context['resolving_conflicts'] = true`.
   - After the agent finishes, re-lists conflict files. If any remain, the merge is aborted and the stage marked failed. If all are resolved, the merge commit is created and pushed.
   - On success, calls `OrchestratorService::restart($run)` to continue the pipeline.

`StageEvent` rows track the flow: `conflict_resolution_started`, `conflict_resolved`, `conflict_resolution_failed`.

## Resolve manually

When the AI-assisted flow fails repeatedly (e.g. the conflicts require semantic knowledge the agent lacks), resolve by hand in the worktree:

```bash
cd <run.worktree_path>
git fetch origin <default_branch>
git merge origin/<default_branch>
# edit conflicted files, remove <<<<<<< ======= >>>>>>> markers
git add -A
git commit -m "Merge origin/<default_branch>"
git push origin <run.branch>
```

Then clear the conflict flag so Release can proceed:

```bash
php artisan tinker
> $run = App\Models\Run::find(42);
> $run->update([
    'has_conflicts' => false,
    'conflict_detected_at' => null,
    'conflict_files' => null,
  ]);
> app(App\Services\OrchestratorService::class)->restart($run->fresh());
```

The next probe cycle will confirm `OUTCOME_CLEAN` and emit `conflict_cleared`.

## Debugging a failed resolution

The resolve job writes events to `stage_events`, not to the `pipeline` log channel directly (see the "Not yet emitted" note in [[merge-conflict-detector]]). Filter the stage events:

```bash
php artisan tinker
> App\Models\StageEvent::query()
    ->whereIn('type', ['conflict_resolution_started', 'conflict_resolved', 'conflict_resolution_failed'])
    ->whereHas('stage', fn ($q) => $q->where('run_id', 42))
    ->latest()->get();
```

Common failure reasons:

- `Failed to fetch target branch: …` — network, missing remote, or the default branch was renamed/deleted. Fix the remote, then retry.
- `AI left unresolved conflicts: …` — the agent removed some markers but not all. Re-run, or resolve manually.
- `Conflict resolution threw: …` — unexpected exception. Check the Laravel log for a stack trace.

If the Implement agent's own tool loop is the bottleneck, see [[stage-failures]] for the `implement.loop_limit` path.

## See also

- [[merge-conflict-detector]] — the probe service, outcomes, and shell commands it issues.
- [[worktree-service]] — the underlying worktree state the probe reads.
- [[release]] — the consumer of the conflict flag.
- [[stuck-states]] — recovery paths when a conflict keeps a run stuck.
