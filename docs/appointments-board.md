# Appointments Board implementation and operation

## Updated API split

`GET /api/branches` returns `{ data: [{ id, name }], meta: { default_branch_id } }`.
Vue loads it once per mounted board, then explicitly sends the selected branch
to `GET /api/appointments`. Search, status, dates and pagination never reload
branches. A failed bootstrap has an explicit retry; leaving and reopening the
board fetches fresh options. No branch data is cached across users or sessions.

Both endpoints live in `routes/api.php`. They use cookie decryption and session
middleware for the existing Fortify login, followed by auth, verification and
the service-advisor role check. These are GET-only routes; the Inertia page stays
in `routes/web.php`.

**Budget change:** appointments use 2 SQL statements, branches use 2 once.
The initial two-request API load totals 4, so it does not satisfy the original
aggregate <= 3-query AC. Subsequent appointment reloads satisfy that budget.
This tradeoff follows the requested independent authenticated branches endpoint;
the repeated user lookup is counted, not hidden. The separate HTML page request
is still outside these API counts. Existing p95 evidence below predates the split
unless explicitly labeled otherwise.

## Scope and access

Visit `/appointments` after signing in as a `service_advisor`. The sidebar link is
shown for that role; both the page and JSON endpoint enforce the role server-side.
This slice is read-only. Customers do not need accounts and there is no appointment
creation, editing, polling or kiosk mode.

The linked advisor's branch is the default. Every service advisor can select any
branch in this implementation. A user without an advisor link defaults to the
first branch alphabetically. This is deliberate cross-branch access, not a branch
authorization policy. Add server-side scoping if business requirements change.

## Request and response

`GET /api/appointments?from=2026-09-01&to=2026-09-30&branch_id=1&status=booked&q=ABC&page=1`

Both JSON routes are defined in `routes/api.php` with explicit session middleware
for same-origin Fortify authentication. Other Week 1 API routes are unchanged.

- `from` and `to` are required `YYYY-MM-DD` dates. The end date is inclusive.
- `branch_id` is required for appointments. A positive unknown/deleted branch returns empty results.
- `status` is optional; the five allowed statuses match the existing schema.
- `q` is at most 100 characters and searches customer name or vehicle plate.
  `%`, `_` and `!` are literal characters, not user-supplied LIKE wildcards.
- `page` defaults to 1 and is bounded to 1–10000. Page size is fixed at 50.
- The appointments response has `data` and `meta` (`branch_id`, `page`, `per_page`,
  `has_more`, `timezone`). It intentionally has no expensive total count.

Existing seeded DATETIME values are interpreted in the application's timezone
(`config/app.php`, currently UTC). Dates become `[from midnight, day-after-to
midnight)` bounds. The UI displays stored wall-clock timestamps and their timezone
without browser timezone conversion. Confirm existing data conventions before
changing the timezone. The initial range is the user's current calendar month.

## Vue behavior

`Index.vue` owns filter controls and accessible loading, validation, error, empty
and pagination states. The composable owns fetch lifecycle. Only search changes
wait 300ms; other filters/page navigation load immediately. Filter changes reset
the page to 1. Results remain dimmed during loading, clearly marked as loading.

Every change immediately increments a request generation, clears the previous
timer and aborts the old controller. Both the generation and signal are checked
before changing data, errors or loading. Unmount/disposal also invalidates pending
work. The tests deliberately resolve an aborted request after a newer response
and inside the debounce window, proving correctness even when cancellation is
ignored. Vitest runs in Node with Vue effect scopes; no simulated DOM is needed
for these composable tests.

## SQL and budget

Each appointment request uses one user lookup and one bounded appointment query:
**two SQL statements including auth**. The separate branches/default-advisor
request uses two statements once per board mount, for four on initial API load.
The latter joins display fields, filters timestamps directly, and fetches 51 rows
to report whether a next page exists. Ordering by timestamp then ID is stable for
ties. Search materializes customer/vehicle matches inside the same SQL statement,
uses indexed appointment references, and unions the matching appointment IDs.
Each source is ordered and limited to `page * 50 + 1` before display joins. This
preserves the first K union rows while avoiding expensive display joins for every
possible match. UNION removes duplicates when both name and plate match.
No relationships are lazily
loaded per row. This is offset pagination; concurrent writes can shift pages.

The existing `(branch_id, status, scheduled_at)` index handles a chosen status.
The new `(branch_id, scheduled_at, id)` index handles all statuses without an
avoidable filesort. Two additional indexes, `(customer_id, branch_id, scheduled_at)`
and `(vehicle_id, branch_id, scheduled_at)`, support lookup from the small search
match sets. These add storage and write overhead in exchange for bounded read
latency; benchmark writes before production rollout. The migrations are additive, but production index creation
still needs a version-specific DDL/locking plan.

The budget includes authentication, validation, options, rows and session SQL
for the API request. It excludes the separate Inertia HTML shell request and
seeding/benchmark setup. File sessions avoid session-table reads/writes; database
sessions add queries and violate the three-query budget. The repo's default and
`.env.example` now use `SESSION_DRIVER=file`. For multiple app servers use a shared
non-SQL session store such as Redis and remeasure; local disk sessions alone do
not provide cross-server sessions. No caching hides the data-query cost.

## Running locally

Use PHP >= 8.4.1 for the installed lockfile, with PDO for your database, mbstring,
OpenSSL, fileinfo, and the project's other required extensions. CI now selects PHP
8.4 to match installed dependency requirements. Node 22 is used by CI.

```sh
composer install
npm ci
php artisan migrate
php artisan db:seed --class=UserSeeder
php artisan config:clear
npm run build
php artisan serve
```

Set `APP_ENV=local` and `SESSION_DRIVER=file` in your local `.env`. Changing the
session driver means signing in again. The demo credentials are
`advisor@fleetops.test` / `password` unless previously changed. The user seeder
preserves existing credentials and advisor links.

For hot reload, run `npm run dev` in a second terminal instead of relying on the
production build. Vite's Wayfinder plugin invokes `php`, so put the compatible PHP
directory on PATH in the build terminal as well.

Do not rerun the FleetOps seeder on an existing 100k dataset merely to get a login.
It appends business records. On a NEW dedicated benchmark database, migrate and
run `php artisan db:seed` once. This creates the business dataset and the local
demo user; the user seeder alone does not create 100k appointments.

## Verification and benchmark

Database tests use `Tests\Concerns\RefreshInMemoryDatabase`. Its migration hook
runs ordinary `migrate` on SQLite `:memory:` only, never `migrate:fresh` or
`db:wipe`. Test transactions are rolled back between cases. `Tests\TestCase`
rejects persistent database configurations before database setup, and PHPUnit
uses separate configuration/route cache paths so local benchmark caches cannot
select the development database. Seed the local dataset separately from tests.

```sh
npm run test:unit
npm run types:check
php artisan test --filter='AppointmentBoardTest|AdvisorUserTest'
```

For manual HTTP timing, set `INERTIA_DEVTOOLS_ENABLED=false` in `.env`, cache
configuration and routes, and use the authenticated board through the browser
Network panel against the dedicated 100k-row Herd dataset. Keep the machine,
dataset, filters and warmup procedure consistent when comparing measurements.
Record the request URL, response status, duration and profiling headers with the
evidence. Browser timing is environment-dependent and is not an automated test.

Local opt-in `X-Board-Profile: 1` requests return `Server-Timing` diagnostics and
`X-Board-Query-Count`. SQL time is a subset of application time; the diagnostic
intervals exclude work after middleware returns, such as RequestHandled event
listeners. Use the full client HTTP measurement as the acceptance result.
Profiling headers are absent outside the local environment.

After measuring, run `php artisan config:clear` and `php artisan route:clear`
before normal development or tests, so cached local database settings cannot
override the test environment. Recreate caches before comparing timings. Set
`INERTIA_DEVTOOLS_ENABLED=true` and clear configuration when you need its UI.

Capture screenshots and recorded values under the PR template's Evidence section; see
`docs/appointments-board-pr-note.md` for this implementation's measurements.
CI runs deterministic correctness/query-count tests; local wall-clock timing is
kept out of CI to avoid hardware-dependent flaky tests.

`migration-safety-note.md` is the requested expand–contract playbook. It does not
execute a column-type change on your database.
