# Vercel + Render + Supabase deployment

The browser calls the Laravel API on Render. Only Laravel connects to Supabase
PostgreSQL. Sanctum verifies registered bearer tokens; the staged Supabase Auth migration is described at the end. Its browser SDK is not
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
   permissions. Supabase Auth is unused only during the staged Laravel compatibility phase. Do not expose database credentials
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
| `APP_TIMEZONE` | `Asia/Manila`, or the warehouse's IANA timezone |
| `SANCTUM_EXPIRATION_MINUTES` | `720` (12-hour token lifetime) |
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

## Stock-control upgrade and remaining deployment checks

The stock-control migration adds batch locations, stock versions, before/after
movement balances, counted batch allocations, and duplicate-request identifiers.
Create an external database backup and verify restoration into a separate test
database **before deploying this migration**. Render startup runs pending migrations
automatically, so take the backup before pushing to the deployed branch. Never test
restoration over the production project.

Supabase Free does not include scheduled project backups. Keep a database export
outside Git in secure storage; use Supabase's documented CLI export procedure and
verify that it includes the Laravel tables, their data, and sequence values. Record
the export date and verify a restoration rather than treating an untested file as
a recovery plan. See [Supabase database backups](https://supabase.com/docs/guides/platform/backups).
No backup or restore rehearsal is supplied by the application repository.

New product opening stock is allocated to an opening batch with a ledger record.
Legacy stock greater than allocated batch quantities is allocated on the next
authorized stock operation without changing the warehouse total. Existing batches
whose combined quantities exceed product stock require a physical count and an
authorized adjustment; the application does not silently discard quantities.

Product metadata editing cannot change stock. Transfers move quantities between
locations inside the same warehouse and leave total stock unchanged. Return is
limited to inspected, resaleable goods. Expired batches cannot be received,
returned, dispatched, or transferred as usable stock. Counts record every batch
allocation; approval updates product and batch quantities together and rejects
requests whose stock version has changed. Pending requests created before this
migration must be rejected and resubmitted with a fresh count. Historical movement
balances remain unrecorded where the original records did not store them.

The frontend supplies a request UUID for movement/count submissions and reuses it
after uncertain failures. Replaying the same request does not repeat a write;
reusing its UUID with changed values is rejected. Older clients that omit the UUID
do not receive this protection. After other uncertain CRUD failures, refresh and
verify the result before submitting another change.

Audit access is filtered on the backend, non-reviewers see only their own
adjustments, and the last active administrator cannot be demoted. Sanctum tokens
expire after 720 minutes by default; logout revokes the current token. Use
`SANCTUM_EXPIRATION_MINUTES` to change the session policy. Warehouse calendar dates
use `APP_TIMEZONE`. Verify expired-session handling on the deployed frontend.

Reports are client-calculated from persisted API data. History is paginated, and
historical reports load all permitted pages before allowing print/CSV export.
Before real warehouse use, complete PostgreSQL CI, hosted integration checks,
backup/restore verification, and a physical reconciliation of legacy records.
Local SQLite and browser checks alone do not establish production sign-off.

## Recovery, notifications and planning upgrade

Three additional migrations introduce recoverable deletion, database notifications
and product planning profiles. Take and test an external backup before deploying.
Only deletions made after this upgrade enter Trash; earlier permanent deletions
cannot be recovered by these migrations. SKU, batch-number and email uniqueness
remain reserved while records are in Trash. Restore a product before restoring
its deleted batches. User restoration does not re-enable the account or resurrect
revoked tokens. Existing stock/history deletion safeguards remain enforced.

Movement and adjustment notifications are stored transactionally through Laravel's
database channel. No email/SMS provider or background worker is required. Inactive
and deleted users are excluded. Adjustment notices follow current reviewer/owner
access, including after role changes. Existing activity is not backfilled into the
notification inbox. Read state is scoped to each authenticated account.

Product profiles store one of four demand classifications, up to 60 verified
monthly sales totals and up to 12 calendar-month multiplier rules. New perishable
products must begin with zero stock and be received into dated batches. Verify
manufacturer/supplier expiry dates; receiving dates do not establish shelf life.
Usable inventory excludes expired batches and unallocated quantities; total
on-hand inventory remains separately visible. Review/allocate legacy discrepancies
before relying on usable-stock figures. PostgreSQL CI and hosted checks still apply.

## Database hardening and SQL Editor repairs

The PostgreSQL integrity migration reads
[database/supabase_schema_repair.sql](database/supabase_schema_repair.sql).
The following migration reads
[database/supabase_security.sql](database/supabase_security.sql) when Supabase's
`anon` role is present. Ordinary PostgreSQL CI does not need Supabase roles.
SQLite does not implement these PostgreSQL-specific checks.

On the reviewed live project, the SQL repairs have already run. When the code is
later deployed, `php artisan migrate --force` registers the new migrations and
reruns the idempotent schema/security SQL. Do not manually insert migration rows,
use `migrate:fresh`, disable constraints or run `db:seed` to repair production.
The default seeder now creates no accounts.

For SQL Editor maintenance of an existing database whose Laravel migrations have
already created all 16 tables:

1. Run [supabase_security.sql](database/supabase_security.sql) as `postgres` to
   block public Data API access.
2. Deploy the matching email-normalization API change, then run
   [supabase_schema_repair.sql](database/supabase_schema_repair.sql).
   Invalid existing rows cause a transaction failure; no automatic deletion or
   account merging occurs. Case/space formatting is normalized with a system
   audit event. Canonical email collisions need ownership review. On the reviewed
   live database, only the email-format CHECK is deferred until API deployment;
   canonical uniqueness and the other integrity/security repairs are enabled.
3. Only for the three audited legacy SKUs, run
   [supabase_legacy_opening_balances.sql](database/supabase_legacy_opening_balances.sql).
   It preserves totals and fails if the audited gap has changed. It resolves
   `wba.repair_actor_email` to an existing active Administration Manager. The
   reviewed authorizer is configured as a replaceable email, never a numeric ID.
   Already-balanced products are skipped. This is not a physical stock count.
4. Optionally import
   [supabase_appliance_catalog.sql](database/supabase_appliance_catalog.sql).
   Review the catalog and `wba.seed_actor_email` first. It inserts eight realistic
   appliance entries with zero stock, skips existing SKUs (including Trash) and
   audits only newly inserted rows. Verify actual supplier, reorder parameters,
   lead time, stock and expiry using the application. No fake sale or receipt
   records are inserted.
5. Run [supabase_audit.sql](database/supabase_audit.sql). It is read-only and
   returns security/relationship/stock checks without credential values.

The schema and security migrations are deliberately forward-only: automated
rollback would restore dangerous privileges/deletion rules. Use a reviewed
forward migration for reversals. The SQL transactions use a short lock timeout
and leave application columns, platform schemas and existing history intact.
Existing `postgres`-owned default grants are revoked; Supabase-managed
`supabase_admin` defaults are not modified. Any future table/function added
manually through the dashboard needs its own security review. A backend-only
project may also disable its Data API in project settings; the application does
not depend on it.

Laravel still connects directly through the configured PostgreSQL credentials.
Its table-owner connection bypasses RLS; employee access remains in the Laravel
API's job-specific authorization. The original Laravel-only security baseline must run before the new Auth bridge. Do not apply Auth UUID policies directly to
these numeric Laravel account IDs or pass service keys to Vite. A separate
least-privilege runtime connection would require distinct migration/runtime
credentials and a reviewed deployment change; it was not introduced by this
repair.

Catalog names/models were checked against manufacturer information:
[Carrier](https://carrier.com.ph/for-businesses/products/carrier-optima-inverter-window-type),
[Samsung](https://images.samsung.com/is/content/samsung/assets/ph/offer/2026/promo-mechanics/Always_On_Home_Appliances_Promo_Mechanics.pdf),
[LG](https://www.lg.com/ph/washer-dryers/top-load-washing-machines/tx2312dt5g/),
[Sharp](https://www.ph.sharp/products/air-care/fp-j30e),
[Panasonic](https://help.na.panasonic.com/small-kitchen-appliances/rice-and-multi-cookers/sr-df181/),
[Hanabishi](https://myhanabishi.com/collections/fans-air-coolers/high-velocity-fan),
[Condura](https://condura.com/product/condura-no-frost-inverter-multi-door/).
These references do not establish warehouse quantities, shelf lives, sales or
supplier agreements.

[Supabase secure-data guidance](https://supabase.com/docs/guides/database/secure-data),
[Supabase grants and RLS](https://supabase.com/docs/guides/api/securing-your-api).

## Admin and Staff login portals (Supabase Auth migration)

Administration Managers use `/auth/admin/login` and the frontend `#/admin/sign-in`.
Every other supported department/role uses `/auth/login` and `#/sign-in`.
Correct passwords on the wrong portal return 403 without an application session.
Role changes revoke sessions; dashboard and portal-specific API endpoints enforce
membership. Warehouse, Sales and Purchasing managers retain their existing job
permissions and do not acquire Administration access.

The staged default `APP_AUTH_PROVIDER=laravel` preserves live login while accounts
are imported. The target `APP_AUTH_PROVIDER=supabase` verifies passwords through
Supabase Auth and checks every registered access JWT against its current identity.
There is no Laravel password fallback in Supabase mode. The browser receives no
service key, local password hash or refresh token. Access tokens expire and require
sign-in again; automatic refresh is not implemented.

`public.users.id` remains the warehouse foreign-key identity. Its unique
`auth_user_id` UUID references `auth.users.id` with deletion restricted. One shared
profile holds job permissions; mutually exclusive, security-invoker Admin/Staff
views avoid duplicated names, credentials and membership tables. Limited profile
column grants and RLS permit own-profile reads, plus Administration reads of Staff
profiles. No browser role-assignment writes or RPC are granted. Inventory still
passes through Laravel's job-specific authorization. Supabase roles in editable
user metadata are never trusted.

The complete SQL for the existing migrated warehouse is
[database/supabase_admin_staff_auth.sql](database/supabase_admin_staff_auth.sql).
It prepares identity links, constraints, indexes, RLS, views and restricted
finalization functions. It never writes passwords directly into managed Auth
schemas or automatically links an arbitrary matching email. Import passwords
through the official Auth Admin API using the committed command. Compatible bcrypt
and Argon2 hashes preserve existing passwords; see
[Supabase hash-import guidance](https://supabase.com/docs/guides/platform/migrating-to-supabase/auth0).

A live cutover is pending until an external backup and restore rehearsal, trusted
email ownership, server configuration, account import and hosted portal smoke tests
are verified. Prepared code and local mocked-provider tests are not evidence of a
completed hosted migration.


### Controlled Auth cutover

1. Before deployment, export the existing database outside Git and verify a restore
   in a separate project. This repository does not contain a verified backup.
2. Deploy the schema and portal changes with `APP_AUTH_PROVIDER=laravel`. Old tokens
   without a portal ability are revoked by the SQL; employees sign in again through
   the correct page. The SQL can also be pasted as one file in SQL Editor, as postgres.
3. Configure server-only `SUPABASE_URL`, `SUPABASE_PUBLISHABLE_KEY` and
   `SUPABASE_SERVICE_ROLE_KEY` on a trusted PHP runner with PostgreSQL support. Keep
   the same project URL on import reruns. Never put the service key in Vite or Git.
4. Run `php artisan app:import-supabase-users` for a read-only inventory. Once backup,
   restore and employee email ownership are actually verified, run:
   `php artisan app:import-supabase-users --execute --backup-confirmed --confirm-managed-emails`.
   This resumes only identities with a matching deterministic UUID, email and trusted
   import metadata. Collisions stop the import without merging accounts.
5. Configure `APP_AUTH_PROVIDER=supabase`, clear/rebuild Laravel config through the
   deployment process, and verify both hosted portals, wrong-portal 403, logout,
   password setup, user administration and inventory actions. Provider failures
   produce errors rather than falling back to local credentials. Disable public
   Supabase signups for this administrator-managed workforce; unassigned identities
   are denied warehouse access even if signup is accidentally enabled.
6. Only after those checks, run the import command again with all execution flags
   and `--finalize`. This removes local password copies, revokes pre-cutover sessions
   and installs a deferred identity constraint. New account creation can temporarily
   insert a profile inside a transaction but cannot commit without an Auth UUID.
   The SQL finalizer requires an explicit session setting and is never exposed as
   a browser RPC. After finalization, do not switch back to Laravel passwords.

An Auth API write and a PostgreSQL transaction cannot share a distributed commit.
An Auth API timeout may require administrator reconciliation before a retry. The
import's deterministic IDs allow safe inspection/resume instead of duplicate
accounts. Password changes explicitly report when saved credentials require a fresh
sign-in because the subsequent session request failed. A database commit failure
following a successful provider change still requires reconciliation; keep backups.
