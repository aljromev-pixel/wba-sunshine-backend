# Vercel + Render + Supabase deployment

The browser calls the Laravel API on Render. Only Laravel connects to Supabase
PostgreSQL. Keep Sanctum authentication; Supabase Auth and its browser SDK are not
needed. This prepares a demonstration/staging deployment, not a sign-off on every
inventory business rule.

## 1. Publish and verify the repositories

Commit and push the frontend and backend changes to the branches you will deploy.
Wait for the frontend build and the backend `Backend verification` GitHub Actions
workflow to pass. The backend workflow runs tests against PostgreSQL 17, builds the
PHP 8.4 Docker image, starts it against the disposable database, and checks `/up`
and an unauthenticated inventory request. No Supabase secrets belong in CI.

Local checks: `php artisan test --compact` in the backend and `npm run build` in
the frontend. Local tests use in-memory SQLite; PostgreSQL/container compatibility
is not established until the new CI workflow passes. Docker and PostgreSQL were
not installed on the preparation machine, so these checks could not run locally.

## 2. Create a Supabase database

1. Create a new Supabase project. Choose a region near your Render region, and
   generate/save a strong database password in your password manager.
2. Wait for the project to finish provisioning. Open **Connect**, choose
   **Session pooler**, and use port **5432**. Copy the exact host and username
   supplied by the dashboard; the username normally contains the project reference.
   Do not invent these values or use the transaction pooler on port 6543.
3. Since this app accesses PostgreSQL only through Laravel, open the Data API
   integration settings and turn **Enable Data API** off before running migrations.
   This prevents automatically exposed public-schema tables from bypassing Laravel
   permissions. Supabase Auth can remain unused. Do not expose database credentials
   or a service-role key to Vercel/browser code.
4. Laravel migrations create the application tables. Do not create them manually
   in Supabase, and never run `migrate:fresh` or demo seeders on this database.

See [Supabase's Laravel connection guide](https://supabase.com/docs/guides/getting-started/quickstarts/laravel)
and [disabling the Data API](https://supabase.com/docs/guides/api/securing-your-api).

This deploy starts a new database; it does not move local SQLite/MySQL records.
If existing records are needed, plan a separate export/import and verify counts,
relationships, stock and user credentials before switching environments.

## 3. Generate the Laravel key

Run this locally in the backend terminal:

```powershell
php artisan key:generate --show --no-interaction
```

Copy the complete `base64:...` value to Render's `APP_KEY` secret. This command
does not change your local `.env`. Keep the key stable across deployments and
never commit it or paste it into issues. Do not use Render's generic random-value
generator for APP_KEY: Laravel requires a correctly sized key.

## 4. Create the Render web service

1. Select **New > Web Service** and connect the backend GitHub repository.
2. Select the deployed branch (usually `main`), the repository root, and **Docker**
   runtime. Dockerfile path: `./Dockerfile`. Leave the Docker command override empty.
3. Select a nearby region and the **Free** instance for a demonstration.
4. Set health check path to **/up**. Copy the actual service URL Render assigns.
5. Add these environment variables before deploying:

| Variable | Value |
| --- | --- |
| `APP_NAME` | `Sunshine Inventory API` |
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `APP_KEY` | Generated Laravel key; secret |
| `APP_URL` | Actual `https://YOUR-SERVICE.onrender.com` URL |
| `PORT` | `10000` |
| `DB_CONNECTION` | `pgsql` |
| `DB_HOST` | Exact Supabase **Session pooler** hostname |
| `DB_PORT` | `5432` |
| `DB_DATABASE` | `postgres` (confirm in Connect) |
| `DB_USERNAME` | Exact Session pooler username from Connect |
| `DB_PASSWORD` | Database password; secret, not an API key |
| `DB_SSLMODE` | `require` |
| `CORS_ALLOWED_ORIGINS` | Exact Vercel origin, e.g. `https://YOUR-FRONTEND.vercel.app` |
| `SESSION_DRIVER` | `database` |
| `CACHE_STORE` | `database` |
| `QUEUE_CONNECTION` | `sync` |
| `LOG_CHANNEL` | `stderr` |
| `LOG_LEVEL` | `warning` |
| `RUN_BOOTSTRAP_ADMIN` | `true` for initial setup only |
| `BOOTSTRAP_ADMIN_NAME` | Name of your initial administrator |
| `BOOTSTRAP_ADMIN_EMAIL` | Your chosen administrator sign-in email |
| `BOOTSTRAP_ADMIN_PASSWORD` | Unique password of at least 12 characters; secret |

`RENDER=true` is supplied by Render and enables proxy handling. Do not set `DB_URL`
or `DATABASE_URL` alongside the separate DB settings. This app reads `DB_URL`, not
the `DATABASE_URL` name shown by some generic hosting examples.

CORS values are origins only, with no trailing slash/path. Separate multiple
explicitly approved origins with commas. Do not use `*` or permit every Vercel
preview domain. Add a specific preview origin only when needed.

If Vercel has not assigned the frontend URL, import the frontend repository first,
copy its project domain, and use it here. Its first deployment can be updated
after the backend URL is known.

Create/deploy the service. The image installs locked production Composer packages;
startup validates settings, runs pending migrations, optionally creates the first
administrator, caches configuration/routes/views, and starts Apache on `$PORT`.
Migrations fail the startup instead of silently serving a broken deployment.
This startup migration strategy is for a single instance. Before scaling or using
overlapping deployments, move migrations to an isolated deployment job/pre-deploy
step and establish a migration rollback/backup plan.

## 5. Remove bootstrap credentials

After successful initial deployment and login:

1. Set `RUN_BOOTSTRAP_ADMIN=false`.
2. Delete all three `BOOTSTRAP_ADMIN_*` variables from Render and redeploy.
3. Use the frontend's Administration Manager user-management page to add the team
   accounts with the appropriate department/role. Commit identities are not login
   credentials; no team passwords are embedded in the repository.

Bootstrap will not overwrite an existing password, promote a staff account, or
create another administrator once one exists. Its password is removed from the
web process environment before configuration caching. Do not run `db:seed`: the
default development seeder is not a production administrator provisioning tool.

## 6. Connect Vercel

Import the frontend GitHub repository with Vite preset, Node 24, `npm ci`,
`npm run build`, output `dist`. Existing `vercel.json` supplies these commands.
Set these for Production and any approved Preview environment:

```env
VITE_API_BASE_URL=https://YOUR-SERVICE.onrender.com/api
VITE_API_TIMEOUT_MS=90000
```

Replace the placeholder with your actual Render hostname. The API client appends
`v1/...`; do not add `/v1` to the base URL. These are public configuration values,
not database credentials. Vite embeds them at build time: redeploy after changes.
The timeout accepts 1000–120000 milliseconds; local default is 15000. Render Free
may take around a minute to wake, so 90000 gives it more time. Requests are not
automatically retried: doing so could duplicate successful writes.

## 7. Verify the deployed integration

- Render logs show completed migrations and a running web server; `/up` returns 200.
- `/api/v1/inventory` without authentication returns JSON 401, not an HTML error.
- Sign in at Vercel using the initial administrator. Reload and confirm restoration.
- Create a temporary product with stock 0, then a batch; verify stock after reload.
- Exercise inventory movements and adjustments with separate requester/reviewer
  accounts. Verify forbidden roles receive 403 and self-review receives 422.
- Verify user CRUD, logout, and revoked-token rejection. Clean up only disposable
  records without history; history records are deliberately protected.
- Check the browser Network tab for HTTPS API calls and no CORS failures.
- After idle time, confirm the UI handles backend wake-up and Retry.

Use the existing QA_REGRESSION.md in the frontend for the detailed checklist.
Never run its live runner against Supabase: that runner uses its own disposable
SQLite database and does not provide hosted PostgreSQL sign-off.

## Limits and troubleshooting

Render Free sleeps after 15 idle minutes, has ephemeral local files, and does not
provide shell access. Supabase Free can pause low-activity projects over seven
days. Restore a paused project in Supabase before testing; do not assume it wakes
automatically. Check quotas and keep an external database export before important
demos. Uploaded files would need durable object storage; this setup does not add it.

- Build fails: inspect Composer/PHP-extension errors; confirm the new CI run passed.
- Database connection fails: check Session pooler hostname, full username, password,
  port 5432, SSL mode and project pause state. Do not replace it with an IPv6-only
  direct hostname unless your network supports that connection.
- Missing table: inspect the migration failure in Render logs, not `migrate:fresh`.
- Login fails: confirm bootstrap completed and your exact email/password; wrong
  attempts are rate limited. Secrets should be changed through Render, not Git.
- CORS fails: compare the browser Origin against `CORS_ALLOWED_ORIGINS`, then redeploy.
- 500 response: read backend logs; keep `APP_DEBUG=false` on the public service.

[Render Docker/Laravel guide](https://render.com/docs/deploy-php-laravel-docker),
[Render free limits](https://render.com/docs/free),
[Supabase project pausing](https://supabase.com/docs/guides/platform/free-project-pausing).

## Application sign-off still required

Reporting source-of-truth and report calculations remain open. Backend adjustment
approval can overwrite stock changed since submission and does not reconcile batch
quantities; product stock editing also needs a consistent reconciliation rule.
Expired batches still need backend write rejection. Audit/adjustment reads need
role filtering in the combined inventory endpoint, and expiry alert calculations
need correction. Resolve and retest these before production use with real records.
Sanctum tokens currently have no automatic expiry; define a token lifetime and
revocation policy before production sign-off.
Deployment preparation is not a claim that these business/security issues are fixed.
