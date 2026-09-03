-- ─────────────────────────────────────────────────────────────────────────────
-- FleetOps · three production-shaped slow queries against the seeded schema
-- (100k appointments / 150k service_records) — pulled from the slow-query log
-- after the branch board complaints.
-- Task: annotate each EXPLAIN, propose the index (or rewrite), add the
-- migration, measure before/after.
-- ─────────────────────────────────────────────────────────────────────────────

-- Schema reference (abbreviated) ────────────────────────────────────────────
-- appointments:    id PK · branch_id · advisor_id · customer_id · vehicle_id
--                  status (booked|checked_in|in_service|completed|cancelled)
--                  scheduled_at DATETIME · created_at · updated_at
-- service_records: id PK · appointment_id · branch_id · advisor_id · vehicle_id
--                  service_type · cost DECIMAL(8,2) · completed_at · notes TEXT
-- vehicles:        id PK · customer_id · plate_number · make · model · year
-- customers:       id PK · name · email · phone
-- Existing indexes: PRIMARY keys only. (Plus the indexes YOU add today.)

-- ── Query 1 — Branch board: today's checked-in/in-service appointments ──────
SELECT id, customer_id, vehicle_id, advisor_id, scheduled_at, status
FROM appointments
WHERE branch_id = 3
  AND status IN ('checked_in', 'in_service')
  AND scheduled_at >= '2026-08-17 00:00:00'
  AND scheduled_at <  '2026-08-18 00:00:00'
ORDER BY scheduled_at;

-- EXPLAIN (seeded data, no new indexes):
-- id | select_type | table       | type | possible_keys | key  | rows   | Extra
--  1 | SIMPLE      | appointments| ALL  | NULL          | NULL | 99,412 | Using where; Using filesort

-- ── Query 2 — Customer history across all their vehicles ────────────────────
-- Factories generate random emails: Maria is not guaranteed to exist. Set this
-- to a customer with 2026 service history, and keep it identical for every run.
-- To find a candidate (read-only):
-- SELECT c.email, COUNT(*) AS jobs
-- FROM customers c JOIN vehicles v ON v.customer_id = c.id
-- JOIN service_records sr ON sr.vehicle_id = v.id
-- WHERE sr.completed_at >= '2026-01-01' AND sr.completed_at < '2027-01-01'
-- GROUP BY c.email ORDER BY jobs DESC, c.email LIMIT 1;
SET @history_email = 'maria.santos@example.com';

-- Q2 original (preserved for comparison):
SELECT v.plate_number, sr.service_type, sr.cost, sr.completed_at
FROM service_records sr
JOIN vehicles v   ON v.id = sr.vehicle_id
JOIN customers c  ON c.id = v.customer_id
WHERE c.email = @history_email
  AND YEAR(sr.completed_at) = 2026
ORDER BY sr.completed_at DESC;

-- EXPLAIN (seeded data, no new indexes):
-- id | select_type | table | type   | possible_keys | key   | rows    | Extra
--  1 | SIMPLE      | c     | ALL    | NULL          | NULL  |  2,000  | Using where
--  1 | SIMPLE      | v     | ALL    | NULL          | NULL  |  3,000  | Using where; Using join buffer (hash join)
--  1 | SIMPLE      | sr    | ALL    | NULL          | NULL  | 149,830 | Using where; Using filesort

-- ── Query 3 — Monthly revenue per branch (head-office report) ───────────────
-- Preserve the existing semantics: 2026 onward, not only calendar year 2026.
-- Adding completed_at < '2027-01-01' would change which records are included.
-- This SELECT is unchanged; the migration below optimizes its access path.
SELECT branch_id,
       DATE_FORMAT(completed_at, '%Y-%m') AS month,
       COUNT(*)        AS jobs,
       SUM(cost)       AS revenue
FROM service_records
WHERE completed_at >= '2026-01-01'
GROUP BY branch_id, month
ORDER BY branch_id, month;

-- EXPLAIN (seeded data, no new indexes):
-- id | select_type | table           | type | possible_keys | key  | rows    | Extra
--  1 | SIMPLE      | service_records | ALL  | NULL          | NULL | 149,830 | Using where; Using temporary; Using filesort

-- Plan annotations:
-- ALL: full table scan; 149830 is an estimate, not actual rows examined.
-- Using where: discard records before 2026-01-01.
-- Using temporary: an internal table accumulates branch/month aggregates;
-- it may remain in memory or spill to disk.
-- Using filesort: explicitly order the grouped results by branch and month;
-- despite its name, this does not necessarily involve disk I/O.

-- Migration: 2026_09_03_000002_add_monthly_revenue_index.php
-- Adds service_records_completed_branch_cost_idx
-- on (completed_at, branch_id, cost).
-- The leading date supports the range filter. The remaining columns cover
-- the report, allowing it to read index entries without fetching full records.
-- Q2's (vehicle_id, completed_at) index is vehicle-first, unlike this report.
-- Expected benefit: cheaper reads, not elimination of yearly aggregation.
-- Every qualifying entry still contributes to COUNT(*) and SUM(cost). When a
-- large share of the table qualifies, rows examined may improve only modestly.
-- The optimizer may choose a range scan, full covering-index scan, or table
-- scan based on costs; measure the chosen plan rather than forcing an index.
-- Temporary aggregation and filesort may remain: date-first index order does
-- not match GROUP BY branch_id, DATE_FORMAT(completed_at, '%Y-%m').

-- Measurement (same dataset and predicate before/after):
-- 1. Before migration, prepend EXPLAIN / EXPLAIN ANALYZE to the SELECT above.
-- 2. Apply the migration and repeat the exact SELECT without index hints.
-- If already migrated, simulate the pre-Q3 baseline by replacing its FROM with:
-- FROM service_records IGNORE INDEX (service_records_completed_branch_cost_idx)
-- This leaves existing Q2 indexes available; label this baseline "Q3 index
-- ignored". Compare returned branch/month groups, jobs, and revenue as well.
-- Record actual plans, database version, estimated rows, iterator rows/loops,
-- timings, and statement-level ROWS_EXAMINED in the PR. Obtain ROWS_EXAMINED
-- from Performance Schema or the slow-query log, not EXPLAIN's estimates.
-- After-plan and measured before/after numbers: pending seeded MySQL execution.

-- Day 7 real fix: pre-aggregation / monthly summary table.
-- Store (branch_id, month_start, jobs, revenue) with a unique key on
-- (branch_id, month_start). A single year across five branches needs at most
-- 60 summary rows instead of re-aggregating that year's service records on
-- every request. The current open-ended report can include multiple years.
-- Define refresh/freshness rules and handle late arrivals, edits, deletions,
-- idempotent updates, backfills, and reconciliation with the source records.
-- Documented here only; the summary table and its maintenance belong to Day 7.
