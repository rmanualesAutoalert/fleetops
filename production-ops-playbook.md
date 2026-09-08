# Production Operations Playbook: Slow Head-Office Report

Use this runbook when someone reports that the head-office service report feels slow. Preserve timestamps, request IDs, query evidence, and command output in the incident record.

## 1. Establish the incident

1. Record the affected environment, deployment version, route, filters, user-visible latency, and time range.
2. Obtain the response's `X-Request-ID`. Do not ask for cookies, session IDs, or authorization headers.
3. Find the matching `report.request.completed` JSON event and record its `status`, `duration_ms`, and `outcome`.
4. Reproduce once with the same safe filters. Do not repeatedly load-test production during an incident.

## 2. Check readiness and summary freshness

1. Request `GET /healthz` from inside the production network.
2. A `503` identifies whether database, Redis, or the Redis-backed queue is unavailable. Escalate that dependency before tuning SQL.
3. Check the summary snapshot:

   ```sql
   SELECT MAX(summary_date) AS latest_business_date,
          MAX(updated_at) AS last_rebuilt_at,
          COUNT(*) AS summary_rows
   FROM daily_branch_summaries;
   ```

4. Inspect the `reports` Redis queue depth, failed jobs, worker process, and scheduler process.
5. If the summary is stale, restore the scheduler/worker first, then run `php artisan reports:regenerate-daily-branch-summaries`. The command dispatches work; wait for the job's completion log before declaring recovery.

## 3. Use the slow-query log

1. Search the database slow-query log for the incident window and request duration.
2. Capture query time, lock time, rows examined, rows sent, and the normalized SQL.
3. The HTTP report query must read `daily_branch_summaries`. A request-time query against `service_records` is an architectural regression.
4. Keep parameter values free of personal or secret data when adding evidence to the incident.

## 4. Explain the observed query

1. Run `EXPLAIN` with representative bindings against the same schema and similar data volume.
2. Record table access type, possible/chosen key, estimated rows, temporary-table use, and filesort use.
3. Use `EXPLAIN ANALYZE` only on a replica or after explicit production approval: it executes the query.
4. Compare estimates with measured rows examined. Estimates alone are not production measurements.

## 5. Select the fix class

| Evidence | Fix class |
| --- | --- |
| HTTP report scans `service_records` | Restore the summary-table read path; do not hide it with a larger timeout |
| Summary read has no useful key | Align its index with date filters and ordering |
| Summary is stale and queue is backed up | Restore or scale the `reports` worker; inspect retries and timeouts |
| Summary is stale and no job was queued | Restore the scheduler or its distributed lock/cache dependency |
| Failed rebuild job | Inspect the sanitized exception, fix data/timeout/connectivity, then retry once |
| High lock time | Shorten the conflicting transaction or change the rebuild/write strategy |
| Excessive rows returned | Add/repair filters or pagination; do not fetch an unbounded report |
| Plan changed as data grew | Refresh statistics and reassess query/index shape using measured evidence |

Do not add an index reflexively. Confirm that its leading columns match the filter and ordering, and account for write/storage cost.

## 6. Verify correctness and recovery

1. For a known branch/date, independently compare source and summary values:

   ```sql
   SELECT COUNT(*) AS jobs, SUM(cost) AS revenue
   FROM service_records
   WHERE branch_id = :branch_id
     AND completed_at >= :day_start
     AND completed_at < :next_day_start;

   SELECT jobs, revenue
   FROM daily_branch_summaries
   WHERE branch_id = :branch_id
     AND summary_date = :summary_date;
   ```

2. Require exact job equality and exact decimal revenue equality.
3. Repeat the original HTTP request and compare duration, status, and rows examined.
4. Confirm the HTTP request emitted no query against `service_records`.
5. Monitor at least one scheduled regeneration before closing the incident.

## 7. Escalation and rollback

Stop and escalate when the database is unhealthy, rebuilding risks exceeding the maintenance window, source/summary values still differ after a successful rebuild, or a schema rollback could discard data.

The safe service fallback is the last complete summary snapshot. Never replace it with a live aggregation of the 150,000-row source table. Because regeneration deletes and inserts within one transaction, a failed rebuild rolls back to the previous complete snapshot.

## Deployment checklist

1. Back up according to the production database policy.
2. Deploy code and run `php artisan migrate --force`.
3. Configure `QUEUE_CONNECTION=redis`, `REDIS_QUEUE=reports`, and a `REDIS_QUEUE_RETRY_AFTER` value greater than the job/worker timeout (the example uses 360 seconds for a 300-second timeout).
4. Restart application processes so cached configuration is refreshed.
5. Start or reload a worker that consumes `reports`.
6. Confirm `php artisan schedule:run` is invoked every minute, or run `php artisan schedule:work` under a process supervisor.
7. Dispatch the initial summary rebuild.
8. Wait for `daily_branch_summaries.regenerated` and verify a known branch/date against the source query.
9. Call `/healthz` and require HTTP 200.
10. Exercise the authenticated report and verify its structured log by request ID.

## Pagination choice

Cursor pagination is the default for the large service-record feed. It seeks after the last `(completed_at, id)` pair, performs well on deep traversal, and is resistant to newly inserted rows shifting later pages. Its token is opaque and it does not support jumping to an arbitrary numbered page.

Offset pagination is appropriate for small, relatively stable result sets where users require page numbers, arbitrary page jumps, or exact total pages. Deep offsets become increasingly expensive, and concurrent inserts or deletes can cause duplicates or omissions between requests.
