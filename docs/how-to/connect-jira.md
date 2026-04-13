# How to connect a Jira source

This guide shows you how to register a Jira OAuth 2.0 (3LO) app in the Atlassian Developer Console and connect it to Relay.

## Prerequisites

- Relay running locally (`composer dev`) at `http://localhost:8000`
- An Atlassian account with admin access to the Jira Cloud site you want to connect
- Admin consent to install new OAuth apps in that site

## 1. Register the OAuth 2.0 (3LO) app

1. Go to [developer.atlassian.com](https://developer.atlassian.com/) → **Console → My apps → Create → OAuth 2.0 integration**.
2. Name the app (e.g., `Relay (local)`) and click **Create**.

## 2. Add the Jira platform and permissions

On the app's **Permissions** page, add the **Jira platform** (Jira API). For each permission below, click **Configure**, then add these scopes:

| Scope | Purpose |
| --- | --- |
| `read:jira-work` | Read issues, projects, comments |
| `write:jira-work` | Create comments, update issue state on release |
| `read:jira-user` | Resolve assignees and reporters |
| `offline_access` | Refresh tokens so long-running runs don't re-prompt |

These match the scopes requested in `config/services.php` — they must be enabled on the Atlassian app or the OAuth flow will fail with an insufficient-scope error.

## 3. Set the callback URL

On the app's **Authorization** page, under **OAuth 2.0 (3LO)**:

- **Callback URL** — `http://localhost:8000/oauth/jira/callback`

Save.

## 4. Copy client credentials

On the **Settings** page, copy the **Client ID** and **Secret**.

## 5. Add the credentials to `.env`

```env
JIRA_CLIENT_ID=xxxxxxxxxxxxxxxxxxxxxxxx
JIRA_CLIENT_SECRET=xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
JIRA_REDIRECT_URI=http://localhost:8000/oauth/jira/callback
```

Restart `composer dev` after editing `.env`.

## 6. Connect from the Relay UI

1. Navigate to `/intake`.
2. Click **Connect Jira**.
3. Approve the requested scopes in the Atlassian consent screen.
4. On return, Relay fetches the list of Jira sites your account can access.

## 7. Select a site

Atlassian OAuth tokens are account-scoped but most API calls require a specific **cloud site ID**. Relay prompts you to choose which site this connection targets at `/jira/select-site`. Pick the site whose issues you want to sync.

The selected site is persisted on the source. To change it later, disconnect and reconnect.

## 8. Test the connection

From the intake page, click **Test connection** on the Jira source. A green badge confirms the token and site are valid. Click **Sync now** to pull issues into the intake queue.

## 9. Map components to repositories

Unlike GitHub issues, Jira issues don't live in a specific repository. Relay resolves the target repo via the issue's **Jira component**:

1. Each synced issue captures its first component (lowest component id wins when an issue has multiple).
2. Components are listed per Jira source under **Components → Repos** on the intake page, or directly at `/sources/{source}/components`.
3. On that page, attach one or more repositories to each component. Repositories must already exist in Relay — add them via the normal repository flow first.

When you **Accept** a Jira issue from the intake queue, Relay shows one button per attached repository. Each click starts a Run pinned to that repo. If an issue legitimately spans three repos, you can start three independent Runs — one per repo — from the same issue.

**Component with no repos attached:** the Accept button is replaced by a "Map repos →" link. The issue stays in the queue until you attach at least one repository.

**Issue with no component:** the intake shows "No component" and no Accept button. Add the component in Jira, or resync, before it can be worked on.

**Auto-accept filter rules + Jira:** because a repo choice can't be inferred, auto-accepted Jira issues land in `Accepted` status but do not auto-start a Run. You still click a repository to start. Auto-accept for GitHub is unchanged.

## Troubleshooting

### `invalid_scope`

The app's Permissions page is missing one of the four scopes. Add it and re-authorize — existing tokens do not pick up new scopes.

### `redirect_uri mismatch`

The callback URL on the Authorization page must match `JIRA_REDIRECT_URI` exactly.

### No sites appear after authorization

The account has no Jira site access or the app was installed against a personal account that doesn't belong to the expected site. Verify the user can see the site at `https://<your-site>.atlassian.net`.

## See also

- [Configuration reference](../reference/configuration.md)
- [How to connect a GitHub source](connect-github.md)
