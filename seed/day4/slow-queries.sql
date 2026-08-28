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

-- ── Query 3 — Monthly revenue per branch (head-office report) ───────────────
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
