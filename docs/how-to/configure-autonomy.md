# How to configure autonomy

This guide shows you how to set autonomy levels for the pipeline and add escalation rules that tighten autonomy when specific conditions fire.

## Prerequisites

- Relay running locally (`composer dev`)
- Read [Architecture: autonomy resolution](../explanation/architecture.md#autonomy-resolution) to understand the scope hierarchy and the tighten/loosen invariants

## Set the global default

Navigate to `/config` in the UI. The global default applies to every (issue, stage) pair unless tightened or loosened by a more specific scope. Choose from `Manual`, `Supervised`, `Assisted`, `Autonomous`. The baseline is `Supervised`.

## Tighten autonomy for a specific stage

On the same page, use the per-stage overrides. A stage override may only **tighten** relative to the global default (it cannot loosen). For example, if the global default is `Autonomous`, the Release stage can be locked to `Supervised` for extra oversight.

## Loosen autonomy for a specific issue

Issue-scoped overrides apply in the issue detail page. An issue override may only **loosen** relative to the stage level. This is the correct place to mark a specific issue as `Autonomous` for end-to-end hands-off execution once the stage baseline is tighter.

## Add an escalation rule

Escalation rules always tighten regardless of other config. Add rules on the `/config` page using one of four condition types:

| Condition type | Example value |
| --- | --- |
| `label_match` | `security` |
| `file_path_match` | `app/Services/AiProviders/*` |
| `diff_size` | `500` (lines changed) |
| `touched_directory_match` | `database/migrations` |

Each rule stores its condition as `{type, value}` JSON and a target autonomy level. When the rule fires during stage transition, the effective level is tightened to the target.

### Rule order matters

Rules are evaluated in list order. Use the move-up / move-down controls on `/config` to reorder.

## Verify resolution

After changing config, open an issue and observe the stage pill in the run timeline. `AwaitingApproval` indicates a `Manual` or `Supervised` gate fired. The activity feed logs which rule or scope was responsible.

## See also

- [Architecture: autonomy resolution](../explanation/architecture.md#autonomy-resolution)
- [Stuck states reference](../reference/stuck-states.md)
