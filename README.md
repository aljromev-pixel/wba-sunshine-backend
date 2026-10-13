# Sunshine Inventory API

Laravel API for the Sunshine inventory management frontend. It provides token authentication, inventory workflows, management CRUD, alerts, and audit history.

## Requirements

- PHP 8.4+
- Composer
- SQLite for local development; PostgreSQL 17 for the Render + Supabase deployment

## Local setup

```bash
composer install
copy .env.example .env
php artisan key:generate
```

Configure the database settings in `.env`, then run:

```bash
php artisan migrate
php artisan serve
```

The local API is available at `http://127.0.0.1:8000/api`.

## Frontend connection

Configure the frontend's `.env` file with:

```env
VITE_API_BASE_URL=http://127.0.0.1:8000/api
```

For deployment, replace this with the public HTTPS URL of this API. Never commit populated `.env` files, passwords, tokens, or application secrets.

## API overview

All routes are versioned under `/api/v1`. Except for login, routes require a Laravel Sanctum bearer token.

- `POST /auth/login`, `GET /auth/me`, `POST /auth/logout`
- `GET /inventory`
- `POST /inventory/movements`
- `POST /inventory/adjustments`
- `POST /inventory/adjustments/{adjustment}/review`
- Product, batch, and user-management CRUD under `/products`, `/batches`, and `/users`

Management routes are role protected. Warehouse Supervisors/Managers and Administration Managers manage products and batches; only Administration Managers manage users.

## Verification

For Vercel + Render + Supabase deployment, follow [DEPLOYMENT.md](DEPLOYMENT.md).
It includes database creation, exact environment variables, first-admin setup,
frontend configuration, verification, and remaining production sign-off issues.
The backend CI workflow tests PostgreSQL and the Docker image after changes are pushed.

```bash
php artisan test
```

## Team

- Project Manager & Scrum Master, Analysis, Compilation: Sy, John Howell J.
- Lead Developers, Database, ERD, Data Models: Suan, Christopher B.; Vasquez, Aljrome A.
- DevOps / Architecture, DFD, Business Rules: Vecina, Aryana C.; Zarate, Christopher M.
- QA, Validation, Testing Scenarios: Tinasas, Shiela Mae C.

## Supabase database review — 14 October 2026

The reviewed deployment uses PostgreSQL 17.11 and Laravel Sanctum bearer tokens.
This describes the pre-migration deployment: Supabase hosts PostgreSQL; its Auth service was not the application's identity
provider. The new shared Auth UUID bridge and portal views supersede that original decision; see the migration section below. The frontend
uses the Laravel API and never receives a PostgreSQL password or Supabase service
key. Reports and alerts derive from persisted API data rather than duplicate
report/alert tables.

### Schema traced to the application

| Table(s) | Backend consumer | Frontend use and retained contract |
| --- | --- | --- |
| users | AuthController, UserController, UserResource, Sanctum | Login, role navigation, Users, Trash. Keep numeric ID, first/last names, combined display name, email, hashed password, department/role, active/password-change flags and deletion attribution. Password and remember token remain hidden. Nullable legacy first/last names are preserved. |
| personal_access_tokens | Sanctum HasApiTokens | Session bearer token, logout/revocation and expiration. Keep token hash, abilities, expiration/use timestamps and Laravel's polymorphic type/ID fields. User is the only supported token owner. |
| sessions, password_reset_tokens | Laravel session/password infrastructure | Infrastructure rather than public frontend data. Keep documented framework schema; an empty table is not proof that it is unnecessary. |
| products | ProductController/Resource, InventoryController, WarehouseStock | Inventory, movements, reorder, reports and planning. Keep SKU/name/category/supplier/unit, total stock, storage capacity, reorder point, lead time, monthly sales, demand classification, historical monthly sales, seasonal rules, stock version and Trash fields. Capacity means warehouse unit capacity, not appliance litre/kg capacity. |
| batches | BatchController/Resource, movement/adjustment controllers | Batch lists, FIFO, expiry timeline, location transfer and cycle count. Keep globally unique number, product link, quantity, receipt/expiry dates, location, origin lot link and Trash fields. |
| inventory_movements | InventoryMovementController/Resource, history API | Movement pages, transaction history, notifications and reports. Keep actor/product/source/destination batch links, transaction type/quantity/reference/status, before/after balances, locations, return condition, request UUID/fingerprint and timestamps. Older nullable historical balances are not fabricated. |
| inventory_adjustments | InventoryAdjustmentController/Resource | Cycle-count requests and approval/rejection. Keep requester/reviewer, product, quantities/reason/status, reviewed date, stock version, JSON batch-count snapshot and request UUID/fingerprint. JSON is a historical snapshot, not an extra live batch source. |
| audit_logs | All write controllers, audit resource/history | Audit Trail. Keep actor/department/role snapshots, action/module/reference/description and timestamps. Never seed or record plaintext passwords. |
| notifications | InventoryUpdates, InventoryNotice, NotificationController | Transactional movement/review notices and user-owned read state. Keep Laravel's UUID notification ID, type, polymorphic user link, JSON payload/read timestamp and timestamps. |
| cache, cache_locks | Laravel database cache | Server cache/rate limits/locks; not browser-readable data. |
| jobs, job_batches, failed_jobs | Laravel queue infrastructure | Preserve framework support even when the current deployment uses a synchronous queue. |
| migrations | Laravel migrator | Deployment history. Manual SQL does not insert invented migration history. The new migrations safely rerun the same repair SQL when deployed. |

No current-feature tables were missing. All 16 application/framework tables were
retained. No custom public views, enums, RPC/database functions or user-defined
triggers existed to remove. Supabase-managed schemas were left intact. Serial
numbers, warranties, asset assignment, power consumption and maintenance tables
would introduce features the application does not implement, so they were not
added. Existing bigint IDs, date fields, JSON snapshots and Laravel timestamps
were retained; converting timestamps without knowing their original time zone
would alter history.

### Findings and repairs

- **Critical:** all 16 tables originally had RLS disabled and automatic direct
  Data API read/write grants. RLS is now enabled, with no browser-user policies;
  privileges on these tables/sequences are revoked from `PUBLIC`, `anon`,
  `authenticated` and `service_role`. Future objects created by `postgres`
  no longer receive automatic API grants. Laravel's direct table-owner access is
  preserved; no `FORCE ROW LEVEL SECURITY` is applied.
- Added PostgreSQL nonnegative quantity/version checks, valid demand-pattern and
  department/job-role checks, JSON array checks, batch date/location checks and
  a self-review prohibition. Legacy nullable historical fields stay nullable.
- Added sessions-to-user, Sanctum-token-to-user and notification-to-user foreign
  keys, with framework-compatible null/cascade actions. Morph columns remain,
  constrained to the only application owner model.
- Product history no longer disappears through physical cascade deletion.
  Source/destination/origin batch composite foreign keys prevent cross-product
  links. Soft deletion and Trash restoration keep their existing behavior.
- Existing SKU, batch number, request-id and token uniqueness is retained.
  Canonical email uniqueness covers case/space variants; create/update validation
  now uses the same normalized email as sign-in. The new email-format CHECK is
  deferred on live until these API changes deploy, so the old API is not rejected
  for an otherwise valid mixed-case email. The migration normalizes existing
  emails with a system audit record, aborting rather than merging collisions.
- Added indexes for active product batches/FIFO and ID-cursor movement/requester/
  pending history queries. Existing history indexes are retained because this
  small live dataset cannot justify deleting them based on workload evidence.
- API integer bounds and computed receipt overflow checks now return HTTP 422
  rather than reaching a PostgreSQL integer overflow after a partial workflow.
  The default movement factory now creates a batch of its own product.
- The default database seeder no longer creates a known-password demo user.
  Initial account creation remains `app:bootstrap-admin`; staff creation remains
  authenticated administrator user management.
- Frontend category filtering now uses saved API categories, including categories
  beyond the previous four hardcoded options.
- With explicit authorization, missing allocations were documented as legacy
  opening balances: AC-001 +50 allocated, TH-001 +12, APF-001 +40. **Saved totals
  remain 50, 12 and 65**, respectively. All three now equal active batch sums.
  Three opening ledger records and three audit records identify the authorizing
  administrator by a resolved existing email, not a hardcoded user ID.
  Unknown original receipt/expiry/location facts remain explicitly unverified;
  `received_at` on these legacy allocations is the allocation date.

### Job-specific permissions retained

| Job | Ordinary movements | Product/batch CRUD and inventory Trash | Adjustment request | Review another employee's request | User management | Audit |
| --- | --- | --- | --- | --- | --- | --- |
| Warehouse Staff | Stock In/Out, Transfer, Return | No | Yes | No | No | No |
| Warehouse Supervisor/Manager | Stock In/Out, Transfer, Return | Yes | Yes | Yes | No | Yes |
| Sales Staff | Stock Out only | No | No | No | No | No |
| Sales Supervisor | Stock Out only | No | No | No | No | Yes |
| Purchasing Staff | None | No | No | No | No | No |
| Purchasing Manager | None | No | No | Yes | No | Yes |
| Administration Manager | Stock In/Out, Transfer, Return | Yes | Yes | Yes | Yes | Yes |

Purchasing has FIFO/reorder access; Purchasing Managers and Administration Managers
have reports access. Notifications/read state are account-scoped. Non-reviewers
see their own adjustment history. Self-review remains rejected by the API
(HTTP 422) and database; unauthorized review is HTTP 403. Other administrator
credentials remain protected from editing/deletion in user management.
RLS does not interpret Laravel bearer tokens or employee roles: Laravel enforces
this matrix, while PostgreSQL blocks the alternative public Data API path.
See [Supabase grants and RLS](https://supabase.com/docs/guides/api/securing-your-api).

### SQL artifacts and verification

See [DEPLOYMENT.md](DEPLOYMENT.md#database-hardening-and-sql-editor-repairs) for
run order. Security, history/quantity integrity and authorized legacy allocation
repairs have already been applied to the reviewed live project. Email-format
enforcement awaits the matching API deployment. The appliance catalog remains
**optional and has not been imported into live inventory**.

Validation recorded during this review:

- PostgreSQL accepted the repair scripts against existing rows; rollback-only
  rehearsals preceded the live transaction.
- Final live checks: 16 RLS-enabled tables, zero API-exposed tables, zero stock
  mismatches, three documented openings, three repair audit events and zero
  unvalidated new constraints.
- Actual PostgreSQL rejection checks blocked negative stock and a cross-product
  movement; the test changes were rolled back.
- The final schema script was rerun in PostgreSQL with a simulated mixed-case
  email, verifying canonical normalization and its system audit record; all
  rehearsal changes were rolled back.
- Optional catalog import tested twice on temporary PostgreSQL tables: eight
  distinct entries, eight audit events, no duplicates, zero invented stock.
- Backend: 107 passed, 596 assertions; two PostgreSQL-only PHPUnit cases skipped
  by the local SQLite runner. The equivalent SQL rejection checks above passed
  on PostgreSQL; the full PostgreSQL CI suite still needs to run after pushing.
- Frontend production build and browser regression suite passed, including
  API-driven categories, desktop/mobile layouts, permission/reviewer matrix,
  session/error/retry states and inventory workflows.
- Deployed backend health returned HTTP 200; unauthenticated inventory returned
  HTTP 401. A credentialed hosted end-to-end warehouse session was not performed.

### Facts still requiring warehouse verification

Two legacy Pending adjustment requests lack a stock-version snapshot and cannot
be approved safely. An authorized reviewer should reject them and request fresh
physical batch counts; their original history has been preserved.

The three legacy products still have the default Stable-Demand classification.
Classify AC-001 as Seasonal-Critical and TH-001 as Traceable-High Accuracy after
confirming their business use. APF-001 matches the project's Perishable-Rotating
case, but its 40 legacy units have no verified expiry. Establish their original
lot/date/location facts, update batches and then classify the product as
perishable; the API intentionally rejects that classification while stocked
batches lack verified dates. Do not invent a nine-month expiry from an allocation
date, sales history or seasonal multipliers.

No Supabase-managed backup was shown in the dashboard. An external export and
successful restore rehearsal were not verified. Keep these as production sign-off
requirements, along with PostgreSQL CI and an authenticated hosted workflow
check. The local SQLite database predates several deployed migrations; it is not
evidence of the live schema and was not silently migrated or overwritten.

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
