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
-- Q2 original (preserved for comparison):
SELECT v.plate_number, sr.service_type, sr.cost, sr.completed_at
FROM service_records sr
JOIN vehicles v   ON v.id = sr.vehicle_id
JOIN customers c  ON c.id = v.customer_id
WHERE c.email = 'maria.santos@example.com'
  AND YEAR(sr.completed_at) = 2026
ORDER BY sr.completed_at DESC;

-- EXPLAIN (seeded data, no new indexes):
-- id | select_type | table | type   | possible_keys | key   | rows    | Extra
--  1 | SIMPLE      | c     | ALL    | NULL          | NULL  |  2,000  | Using where
--  1 | SIMPLE      | v     | ALL    | NULL          | NULL  |  3,000  | Using where; Using join buffer (hash join)
--  1 | SIMPLE      | sr    | ALL    | NULL          | NULL  | 149,830 | Using where; Using filesort

-- YEAR(completed_at) prevents a normal date index from directly bounding the
-- requested year. Comparing the bare column to a half-open range is sargable:
-- include every time in 2026 and exclude 2027-01-01, regardless of precision.
-- Q2 optimized:
SELECT v.plate_number, sr.service_type, sr.cost, sr.completed_at
FROM service_records sr
JOIN vehicles v ON v.id = sr.vehicle_id
JOIN customers c ON c.id = v.customer_id
WHERE c.email = 'maria.santos@example.com'
  AND sr.completed_at >= '2026-01-01 00:00:00'
  AND sr.completed_at <  '2027-01-01 00:00:00'
ORDER BY sr.completed_at DESC;

-- ── Query 3 — Monthly revenue per branch (head-office report) ───────────────
-- Preserve the existing date range: 2026 onward, not only calendar year 2026.
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

-- ALL: full table scan; rows is an estimate. Using where: apply the date filter.
-- Using temporary: accumulate branch/month totals in an internal table.
-- Using filesort: sort those totals. Neither operation necessarily uses disk.

-- Migration: 2026_09_03_000002_add_monthly_revenue_index.php
-- Index (completed_at, branch_id, cost): date-first filtering plus covering
-- reads. Every qualifying record still needs aggregation; temporary tables
-- and sorting may remain because index order does not match branch/month order.

-- Compare EXPLAIN / EXPLAIN ANALYZE before and after on unchanged data.
-- Record measured ROWS_EXAMINED from Performance Schema or the slow-query log
-- in the PR, separately from EXPLAIN estimates. MySQL measurements are pending.

-- Day 7 real fix: pre-aggregate jobs/revenue in a monthly summary table keyed
-- by (branch_id, month_start), avoiding repeated report-time aggregation.
-- Build its refresh and correction handling on Day 7, not in this migration.
