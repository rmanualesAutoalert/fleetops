# Changing a live appointments column: expand–contract

## Worked example and compatibility contract

Change `appointments.status` from the current MySQL ENUM (`booked`, `checked_in`,
`in_service`, `completed`, `cancelled`) to `VARCHAR(32)` using a shadow column
`status_v2`. This is a playbook, not an executable migration in this PR. The only
appointments schema changes implemented for the board add indexes.

Keep the public API's status strings unchanged during rollout. Do not introduce a
new value that the old ENUM cannot represent while rollback to old code remains
supported. Preserve case, nullability, character set, and collation intentionally.
Validate writes in application code; changing to VARCHAR removes the ENUM's
database-level allowed-value restriction unless a replacement constraint is added.

## 1. Preflight

- Record the exact production MySQL version, storage engine, table size, indexes,
  replicas, write rate, and longest transactions. Local SQLite tests do not prove
  production MySQL DDL behavior.
- Inventory every reader and writer: controllers, imports, scheduled jobs, queue
  workers, raw SQL, reports, exports, and integrations. Identify indexes containing
  `status`, particularly `appointments_branch_status_scheduled_idx`.
- Count each current status and detect unexpected/empty values; agree on an
  explicit conversion map. Rehearse on a production-sized copy.
- Confirm recoverable backups and point-in-time recovery with a restore exercise.
  Record owners, release flags, deployment order, and rollback decision authority.
- Define stop thresholds before starting (for example lock waits > 2 seconds,
  replica lag > 5 seconds, elevated write errors, or board p95 >= 200ms).
  These are proposed thresholds to tune to production's SLOs, not universal limits.

## 2. Expand the schema

Add a nullable `status_v2 VARCHAR(32)` without changing or dropping `status`.
Deploy this additive migration before any code references the new column.
Do not edit the historical create-table migration or change the existing ENUM in place.

On a compatible MySQL version, request the supported explicit online/instant
algorithm and fail if it is unavailable rather than silently accepting a table
copy. Verify syntax and restrictions for that exact version and operation.
Even online DDL needs metadata locks; use a short lock timeout and retry during
a quieter window instead of letting it queue behind a long transaction.

Build the replacement `(branch_id, status_v2, scheduled_at)` index as a separate
operation using the supported online method. Monitor disk space, I/O, locks and
replica lag. Do not promise that Laravel `Schema::table()` itself makes DDL online.
MySQL DDL may implicitly commit: wrapping migration code in a transaction is not
a rollback strategy for a partially applied schema change.

## 3. Deploy compatible dual writers

Continue reading `status`. All new code must set `status` and `status_v2` to the
same validated value in one SQL UPDATE/INSERT (or one short transaction if multiple
statements are unavoidable). Do not rely only on model events: bulk updates bypass
them. Update imports and background workers too.

During a rolling deploy old instances can still write only `status`. Wait until
all old web instances and workers are drained/restarted before final backfill and
parity checks. If old writers cannot be retired, design and rehearse a temporary
database synchronization mechanism before proceeding.

## 4. Backfill in resumable batches

Use a separate command/job, not a long-running deployment migration. Walk the
primary key in bounded ranges (start around 500–1000 rows), commit each batch,
persist a checkpoint, and throttle based on measured write load and replica lag.
Capture an upper ID watermark; dual writers handle later inserts.

For this identity conversion, use a database-side assignment such as:

```sql
UPDATE appointments
SET status_v2 = CAST(status AS CHAR)
WHERE id > :last_id AND id <= :batch_end
  AND status_v2 IS NULL;
```

Use bound parameters. The assignment reads the current value under the row lock;
do not SELECT a value into application memory and later overwrite a newer update.
Advance the checkpoint only after committing. Reruns skip completed rows, retry
deadlocks with bounded backoff, and stop/report unconvertible values instead of
silently truncating or substituting them.

The null guard does not repair mismatches caused by old writers. Once those
writers are retired, audit and repair mismatches separately with current-value
assignments in bounded transactions, then rerun the audit.

## 5. Verify and switch reads

- Require zero missing values and zero conversion mismatches after the watermark
  and concurrent-write checks. Compare per-status totals and sample real records.
- Test inserts, transitions, rollback code paths, imports and concurrent updates.
- Compare EXPLAIN plans and the board query budget/latency using the new index.
- Enable new-column reads behind a flag for a small cohort, then expand while
  observing errors, p95, lock waits and parity. Keep writing both columns.
- A temporary read fallback can ease rollout, but remove it before declaring
  parity complete; otherwise it can hide missing backfill data.

## 6. Rollback window

Before contraction, rollback means switching reads to `status` and redeploying
compatible code. Keep both columns and dual writes during the agreed observation
window (for example two releases). Keep values representable by the old ENUM.
If rolling back writers as well, treat new-column parity as invalid until audited
and repaired before another cutover.

## 7. Contract in a later release

Confirm all readers use `status_v2`, all old workers are retired, and rollback
owners accept ending compatibility. Deploy code that no longer references the
old column, then drop its obsolete index and column in a later schema operation.
Do not drop a column while any running release still writes it.

If a physical rename back to `status` is desired, treat it as another compatibility
rollout. Keeping the internal `status_v2` name while exposing API `status` avoids a
coordinated rename outage. Add final NOT NULL/check constraints only after verifying
data and rehearsing their locking behavior.

After dropping the old column, `migrate:rollback` cannot recreate its lost data.
Recovery is a forward repair or a planned restore/PITR operation with explicit
handling of writes made since the backup. Never describe contraction as instantly
reversible.

## Operational checklist

Record deployment IDs, DDL durations, batch checkpoints, mismatch counts,
replica lag, lock waits, query plans, and board p95 in the change ticket.
Pause backfill on threshold breaches; roll reads back on functional regressions.
Resume only after the underlying condition is understood and checkpoints verified.
