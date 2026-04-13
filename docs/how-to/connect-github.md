# How to connect a GitHub source

This guide shows you how to register a GitHub OAuth app and connect it to Relay so issues can be synced and pull requests opened.

## Prerequisites

- Relay running locally (`composer dev`) at `http://localhost:8000`
- A GitHub account with permission to create OAuth apps in the target organisation or personal account

## 1. Register the OAuth app on GitHub

1. Go to **GitHub → Settings → Developer settings → OAuth Apps → New OAuth App** (or under an organisation's Developer settings for a team-owned app).
2. Fill in:
   - **Application name** — e.g., `Relay (local)`
   - **Homepage URL** — `http://localhost:8000`
   - **Authorization callback URL** — `http://localhost:8000/oauth/github/callback`
3. Click **Register application**.
4. On the next page, click **Generate a new client secret** and copy both the **Client ID** and **Client secret**.

## 2. Configure scopes

The authorization request uses three scopes automatically (declared in `config/services.php`):

| Scope | Needed for |
| --- | --- |
| `repo` | Read issues, push branches, open pull requests on private repos |
| `read:org` | Enumerate organisations you belong to |
| `workflow` | Update `.github/workflows/*` when the Implement agent touches CI files |

You do not configure scopes on the GitHub app page — they are requested at authorization time.

## 3. Add the credentials to `.env`

```env
GITHUB_CLIENT_ID=Iv1.xxxxxxxxxxxxxxxx
GITHUB_CLIENT_SECRET=xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
GITHUB_REDIRECT_URI=http://localhost:8000/oauth/github/callback
```

Restart `composer dev` (or at least `php artisan serve`) after editing `.env`.

## 4. Connect from the Relay UI

1. Navigate to `/intake`.
2. Click **Connect GitHub**.
3. You will be redirected to GitHub. Approve the requested scopes.
4. On return, Relay stores the token (encrypted) and lists your accessible repositories.

## 5. Test the connection

From the intake page, click **Test connection** on the GitHub source. A green badge confirms the token works.

Click **Sync now** to pull open issues into the intake queue.

## Troubleshooting

### `redirect_uri_mismatch`

The callback URL in your `.env` must match exactly what you registered on GitHub — including scheme, port, and path. Update the GitHub app if you changed `APP_URL` or `GITHUB_REDIRECT_URI`.

### Token works but no private repos visible

The OAuth app needs to be **installed** on each organisation whose private repos you want to see. Visit the org's **Settings → Third-party Access** and approve the app.

## See also

- [Configuration reference](../reference/configuration.md)
- [How to connect a Jira source](connect-jira.md)
