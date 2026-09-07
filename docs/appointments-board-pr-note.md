# PR: Add the authenticated Appointments Board

## API split follow-up

The board JSON routes now live in `routes/api.php`. `GET /api/branches` is fetched
once per board mount and provides options/default branch. `GET /api/appointments`
requires `branch_id`, returns no branches, and makes no branch-list query.
Both endpoints preserve Fortify session authentication and the service-advisor check.

Query counts are now 2 per appointment reload and 2 for the one-time branches
bootstrap. **The first two-request load totals 4 SQL statements and exceeds the
original aggregate <= 3 AC.** The historical three-query results below describe
the prior combined endpoint, not current initial-load compliance. The manual
measurement evidence reports this distinction. A positive unknown branch ID
returns empty appointment results; a missing/invalid branch ID returns 422.

Post-split verification on 2026-09-04: nine board feature tests (85 assertions),
nine Vitest tests, TypeScript, build and targeted PHPStan pass. Manual Herd
browser/network measurement on the same 100k rows recorded **89.42ms p95**, **2 SQL queries**,
with 20 warmups and 200 samples. Branch bootstrap was separately verified at 2
SQL queries. The historical six-scenario timings below have not been rerun for
this split and should not be presented as new measurements.

## What this PR does

- Adds a Vue 3/TypeScript board backed by a session-authenticated appointments API,
  with branch, status, inclusive date range, customer/plate search and pagination.
- Prevents stale responses from changing rows, error or loading state using a
  300ms search debounce, immediate cancellation and a request-generation check.
- Bounds API SQL to three statements including authentication and adds indexes
  and bounded search candidate queries for the existing 100k-row dataset.
- Documents a live appointments column-type change with an expand–contract plan.

## Acceptance criteria and evidence

| Criterion | Evidence |
| --- | --- |
| Fullstack board | `/appointments` and `GET /api/appointments`; browser login, branch/status/search, empty results, invalid dates and pagination verified |
| Debounce and stale response protection | Seven Vitest tests; mocked requests deliberately resolve despite abort |
| <= 3 queries | Feature test reloads the session-authenticated user and measures all endpoint SQL across six filter cases |
| p95 < 200ms on 100k rows | Manual browser/network measurements below, captured against the dedicated local dataset |
| Migration safety note | Root `migration-safety-note.md`, including dual writers, resumable backfill, parity checks, rollback window and delayed contraction |

## Local timing note — measured 2026-09-04

Manual measurement preparation:

```sh
php artisan config:cache
php artisan route:cache
```

- HTTP server: Herd PHP 8.4.24, CGI/FastCGI, OPcache enabled, Windows,
  MySQL 8.0.46. Requests were inspected through the browser Network panel.
- CPU identifier: Intel64 Family 6 Model 140 Stepping 1, GenuineIntel.
- Exactly 100,000 appointments. Busiest branch: ID 2, 24,803 appointments.
- Seeded range: 2025-09-04 through 2026-10-04. Board/status/page-two cases use
  2026-09-01 through 2026-09-30; full-range/search cases use the seeded range.
- File sessions, application debug enabled, application timezone UTC.
  Configuration and routes cached; `INERTIA_DEVTOOLS_ENABLED=false`.
- 20 warmups then 200 measured samples per scenario; 1,200 measured requests total.
- Sequential requests, nearest-rank p95 calculated separately for each scenario.
- Measures real authenticated sequential HTTP to `http://fleetops.test`, including
  PHP/Laravel bootstrap, middleware, SQL, serialization and complete response
  transfer. Setup, browser rendering and debounce are excluded. Warmups are
  declared in advance and excluded; no measured outliers are removed.

| Scenario | Maximum SQL count | HTTP p95 (ms) |
| --- | ---: | ---: |
| Board, current month | 3 | 67.74 |
| All statuses, full seeded range | 3 | 71.07 |
| Booked, current month | 3 | 66.70 |
| Contains search, common letter `a` | 3 | 114.09 |
| Contains search, no match | 3 | 72.02 |
| Page two, current month | 3 | 68.01 |

All measured scenarios passed both budgets. Results describe this machine and
dataset; repeat the manual browser/network measurements after schema/query
changes and before production deployment.

The user's browser run initially measured 251.90ms p95. Our real HTTP run with
configuration/routes cached but Inertia DevTools still enabled measured 241.95ms
p95 (200 samples). Disabling its local disk recording reduced the board result
to 67.74ms. Inertia's RequestHandled listener flushes/prunes its disk repository
after the middleware timing interval, explaining why application/SQL timings
alone did not expose the full delay. The branch list remains in the response.

An earlier in-process measurement (maximum scenario p95 96.53ms) used a warm
kernel and excluded bootstrap and transport. It
does not prove the HTTP acceptance criterion; the real HTTP results above replace
that evidence. These measurements cover the listed filters at concurrency one,
not arbitrary deep pagination or concurrent production traffic.

## Query plans and tradeoffs

- All statuses: EXPLAIN uses `appointments_branch_scheduled_id_idx` with a range
  scan and index condition; display joins use primary-key `eq_ref` lookups.
  The prior index alone required a filesort for this case.
- Selected status: EXPLAIN uses the existing
  `appointments_branch_status_scheduled_idx`, also with an index condition.
- Search matches the smaller customer/vehicle tables and uses new appointment
  reference indexes. Ordered source limits keep only the candidates needed for
  the page before joining display columns. UNION prevents duplicates.
- The additional indexes cost disk space and write work. Reassess for production
  write volume. Contains matching still scans the small customer/vehicle tables;
  this is not a general search engine for millions of customers.
- File sessions are necessary for this three-SQL local setup. Database sessions
  add their own statements; the benchmark counts them and fails the budget.
  The separate Inertia shell request is outside the API-load measurement.
- Offset pagination can shift under concurrent writes. Deep pages cost more;
  cursor pagination would be a separate contract change if needed.

## Validation and known limitations

- Full PHP feature/unit suite: 61 tests and 245 assertions passed using the
  temporary official PHP 8.4 runtime. Seven Vitest tests, Vue type checking and
  the production build pass.
- After adding the HTTP profiler: all nine AppointmentBoard feature tests pass
  (63 assertions), including local-only/opt-in headers and the query budget.
  New middleware, query profile and HTTP benchmark command pass PHPStan/Pint.
- Changed board PHP files pass targeted PHPStan. Repository-wide PHPStan still
  reports seven pre-existing errors in AdvisorFactory, AppointmentFactory,
  ServiceRecordFactory, VehicleFactory and FleetOpsSeeder (cached array types and
  factory generic variance). Consequently a fully green `composer ci:check` is
  not claimed. These existing dataset implementations were not rewritten here.
- Branch access is deliberately cross-branch for service advisors; the linked
  advisor chooses the initial branch, not an authorization boundary.
- Timestamps use the existing application's UTC wall-clock convention.
- No remote PR was opened. Copy this note into the repository PR template's
  Evidence section when opening the PR.

## Reviewer self-review

- [ ] I read every changed line and can explain its behavior.
- AI-assisted portions: board frontend/API/query/tests, benchmark, index migrations,
  session/CI configuration, documentation, and the preceding user–advisor work.
- [ ] Confirm cross-branch service-advisor access matches the intended business rule.
- [ ] Confirm session-store and timestamp conventions for the target environment.
- [ ] Review the production DDL plan before applying the new indexes to a live table.
