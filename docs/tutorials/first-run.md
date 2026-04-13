# Running your first issue through the pipeline

In this tutorial, we will connect a GitHub source, accept an issue into Relay, and watch it progress through the four stages — Preflight, Implement, Verify, Release — with a human approval gate at each transition.

By the end, you will have seen a pull request opened by the Release agent against a practice repository.

## Before you begin

- Relay is installed and `composer dev` is running (see [README](../../README.md))
- You have an Anthropic API key set as `ANTHROPIC_API_KEY` in `.env`
- You have a GitHub OAuth app with `GITHUB_CLIENT_ID` and `GITHUB_CLIENT_SECRET` configured
- You have a throwaway repository on GitHub with at least one open issue — use something small (a typo fix, a readme tweak) for your first run

## Step 1: Connect your GitHub source

Open `http://localhost:8000` in your browser. You will land on the Overview page. From the sidebar, go to **Intake** and click **Connect GitHub**.

You will be redirected through the GitHub OAuth flow. Approve the `repo` scope. On return, Relay lists your accessible repositories.

## Step 2: Sync issues

From **Intake**, click **Sync now** on your connected source. Relay fetches open issues into the intake queue.

Notice that issues appear as **pending** — they have not yet entered the pipeline. Intake is a filter stage, not a pipeline stage.

## Step 3: Accept an issue

Pick a small issue from the intake list and click **Accept**. The issue moves out of intake and a new Run is created. The first stage, **Preflight** (purple), starts immediately.

## Step 4: Review the preflight document

Open the issue detail page. The Preflight agent will either produce a preflight document immediately (clear issue) or ask you clarifying questions (ambiguous issue).

If asked questions, answer them. The preflight document then appears with six sections: Summary, Requirements, Acceptance Criteria, Affected Files, Approach, Scope Assessment.

With `Supervised` autonomy (the default), the run pauses with **Awaiting approval**. Click **Approve** to advance to Implement.

## Step 5: Watch Implement produce a diff

The Implement stage (amber) now runs. You will see live diff updates in the timeline as the agent reads files, writes changes, and runs the linter.

When the agent signals `implementation_complete`, the stage transitions — or pauses for approval if you are in `Manual` or `Supervised` mode.

## Step 6: Verify runs the test suite

The Verify stage (green) executes the test suite and static analysis. If tests pass, the run advances. If tests fail, Relay **bounces** back to Implement with a failure report attached, and you will see the iteration counter increment.

## Step 7: Release opens a pull request

Approve the transition to Release (teal). The Release agent commits the diff, pushes the branch, and opens a pull request via the GitHub API. The PR body is generated from the preflight document.

Open the PR link from the run timeline and confirm the changes look correct on GitHub.

## What you've built

You have run a complete four-stage pipeline end-to-end, with human approval at each gate, and produced a real pull request on GitHub.

## Next steps

- [Configure autonomy levels](../how-to/configure-autonomy.md) so routine issues skip the approval gates
- Read [Architecture](../explanation/architecture.md) to understand the orchestrator
- Browse the [agent references](../reference/agents/) to see each stage's tool surface
