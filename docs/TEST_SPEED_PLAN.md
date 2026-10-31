# Faster tests

Agreed 31 Oct 2026 (Rory). Steps 1–4 are built on `claude/test-speed-local`;
step 5 (CI) waits for Stephan's `claude/test-speed-d69351`, which changes the
same CI files.

## Why the tests were slow

Measured on Rory's Windows machine (MySQL 8.0.44, default durable settings):

| What | Before |
|---|---|
| One small test file | **4–6 minutes**, of which the tests took seconds |
| ↳ building that process's database | 217–289s: 26s to import `database/schema/mysql-schema.sql`, then ~190–260s for the **462 migrations the dump didn't have** (it held 905 of 1,367; last refreshed 5 Aug) |
| ↳ dropping it at exit | 20–50s |
| A test that seeds nothing | ~0.2s |
| A test that runs `RbacSeeder` first | ~1.35s: **9,295 tests in 917 files** re-seed the same roles and permissions before every test |

In CI every 12-file batch is a fresh process, so each shard built and dropped
its database ~21 times.

## What changed

### 1. A test database that is kept, not rebuilt (`tests/Support/KeptTestDatabase.php`)

- Each checkout gets numbered databases, `oblivion_findings_codex_test_k<checkout>_<n>`,
  one per parallel worker. A run claims a free one with a MySQL named lock,
  which the server releases when the process exits or dies, so two runs never
  share one, even from different worktrees.
- A meta database (`oblivion_findings_codex_test_meta`) records what each slot
  holds: its migrations (name and content hash) and its clean state taken
  straight after migrating. That state covers, per table, the columns,
  indexes, foreign keys, triggers, a checksum of its rows, its `SHOW CREATE
  TABLE`, and the rows of the ~13 tables that have any.
- At start a run compares (~1.5s): unchanged → reuse; new migration files →
  run only those; an edited or removed migration (a branch switch) → rebuild
  from the dump (~70s).
- **Leaks are repaired, not inherited.** After each test the run notices
  changes that outlived it: a commit of the test's transaction, any write
  outside a transaction, a schema change (MySQL silently commits the open
  transaction on DDL), `DatabaseTruncation`, or `CommittedFixtureCleanup`
  reporting rows it could not put back. Before the next test, only the tables
  that differ are put back: rows rewritten, and changed, added or dropped
  tables recreated from the recorded definition with their triggers (a few
  seconds). Each repair and rebuild is logged to
  `storage/logs/test-database.log` (and shown on screen in a serial run;
  `composer test:fast` lists its run's lines at the end, because the parallel
  runner aborts when a worker writes to STDERR):
  `[tests] Tests\Feature\Finance\PaymentAllocationIntegrityTest > "…" committed changes; Repaired test database …: a test left schema changes in fin_payment_run_items.`
  Before this, later tests in the same process ran on those rows: run
  serially, `tests/Feature/Finance` failed 179 tests on `main` with duplicate
  `fin_accounts` keys left by `BankReconciliationAggregateTest`. CI hid it by
  spreading neighbouring files across shards. It now passes, 473 of 473.
- `OwnedTestDatabase::isOwnedBy()` (the guard every committing test runs before
  `DB::commit()`) accepts a kept slot only when the server confirms that the
  session holding the slot's lock also holds `<slot>:<pid>` for that PID. That
  is the same guarantee as "named after my PID", and child processes can check
  it too.
- Unchanged: runs that name their own `DB_DATABASE` (the eMAR and workforce
  proof wrappers), a non-numeric `TEST_TOKEN` (the concurrency proofs), or
  `TEST_DB_KEEP=false` get today's throwaway `<base>_<pid|token>` database,
  dropped at exit.
- Switches: `TEST_DB_KEEP=false` (throwaway, as before), `TEST_DB_REBUILD=1`
  (rebuild the slot now).

### 1b. The schema dump brought forward (`composer test:prepare`)

- The dump now holds all 1,367 migrations, and its rows are exactly what every
  test database used to start with (the rows the 462 catch-up migrations
  wrote). This was checked: a database loaded from the new dump fingerprints
  identically to the old dump plus the 462 migrations, triggers included.
- `composer test:prepare` builds on the current dump in a scratch database
  (`…_test_snapshot`), runs the newer migrations, and dumps the structure, then
  the rows, then the triggers (so none fire while the rows load), without
  `DEFINER` clauses. `--from-scratch` keeps the old full-migrate, structure-only
  build. The command now **refuses any database whose name lacks `test`**:
  without a `.env.testing`, `--env=testing` read `.env`, and the old
  `composer test:prepare` would have wiped the developer's own database.
- `tests/Unit/Database/SchemaSnapshotFreshnessTest.php` fails once the dump
  falls more than 50 migrations behind.
- `Tests\TestCase` finds the `mysql` client on PATH: Linux CI used to fall back
  to the PHP loader, which by design (`PortableSchemaTriggerSafetyTest`) cannot
  load the triggers the dump now holds. Without the client, the slot is built
  by migrating from scratch instead.

### 2. Permission seeders replayed (`tests/Support/SeedReplay.php`)

- The first `seed(RbacSeeder::class)` in a process runs the real seeder inside a
  savepoint and records the tables it read and the rows it added and removed,
  then rolls back and applies that recording. Later calls that find the same
  starting rows apply the recording with a few bulk statements; any other
  starting state (users created before seeding, say) records again or runs the
  real seeder.
- Covers `RbacSeeder`, `SecurityDevicesPermissionsSeeder`,
  `SeedHrPermissionsSeeder`, `ClinicalPermissionsSeeder` and
  `GovernancePermissionsSeeder`: database in, database out, no clock or config.
- `tests/Feature/Testing/SeedReplayTest.php` proves every table ends exactly as
  the real seeder leaves it. `TEST_SEED_REPLAY=false` turns replay off.
- Seeding tests: **1.35s → 0.45s each**.

### 3. `composer test:fast`

Runs the suite (or `composer test:fast -- tests/Feature/Hr`) in parallel, each
worker on its own kept database: one worker per two cores, at most 8, and no
more than MySQL's `table_open_cache` holds databases for (3 on MySQL's default
of 4,000; see the MySQL settings below). `TEST_PROCESSES` or `--processes=<n>`
overrides it. Each worker is replaced after 20 files (`--max-batch-size`), on
the same database: one long-lived worker ran out of its 1 GB after ~2,000
tests, which is also why CI runs fresh processes. Several paths run through a
generated configuration, because the parallel runner takes one. `--without-databases` is passed, and
`Tests\TestCase` sets it too, so Laravel never creates databases of its own on
top.

### 4. `composer test:changed`

Runs the tests your changes touch since the branch left `origin/main`
(committed or not, plus new files): changed test files, tests that name a
changed class under `app/` or `database/`, and the `tests/Feature/<Module>` and
`tests/Unit/<Module>` folders of any `app/Domain/<Module>` you touched.
`--dry-run` lists them, `--base=<ref>` changes the comparison, and `--serial`
keeps them in one process (more than 15 files run in parallel).

## Results

Measured on Rory's machine:

| | Before | After |
|---|---|---|
| One small file | 348s | **5s** (67s the first time, building the slot) |
| A seeding test | 1.35s | 0.45s |
| `tests/Feature/Finance` (98 files), one process | 320s, 179 of 473 failing | 330s, 473 of 473 passing |
| `tests/Feature/Finance`, `composer test:fast` (3 workers, default MySQL) | — | 199s, 473 passing |
| **Whole suite**, `composer test:fast` (8 workers, MySQL settings below) | could not finish in one process (out of memory after ~2,000 tests) | **76 min**: 25,032 passed, 107 failed, 218 skipped |

Every one of the 107 failures was run again with both changes switched off
(`TEST_DB_KEEP=false TEST_SEED_REPLAY=false`, today's behaviour). Seventeen
came from these changes, all from tests that recognised only a
`<base>_<pid>` database name or read the PDO loader's source, and are fixed.
The rest fail the same way without them, for example
`ChatBrowserPushTransportTest` (OpenSSL key creation on Windows), the
governance meeting calendar, `RestrictedIndependentAuthoritySeedingTest`
(the demo admin holds `referrals.settings.governance`) and the restraint
alert-bridge concurrency tests (lock waits). Without these changes the same 52
files fail 290 tests in a parallel run, against 101 with them, because leaked
rows are no longer inherited.

What remains slow is the tests themselves: the HealthSafety chemical-exposure
tests take 30–150s each with or without these changes, and the committing
integrity tests checksum every table through `CommittedFixtureCleanup`.

## MySQL settings for parallel runs

The schema has 1,223 tables, so 8 workers use ~9,800 tables at once. MySQL's
defaults cache 4,000 open tables and 2,000 definitions: on Rory's machine a
parallel run spent most of its time reopening table files (5.8 million cache
misses, CPU ~10% busy). Stephan's CI branch raises both caches for the same
reason. Rory's local MySQL also runs with full durability (binary log on,
`innodb_flush_log_at_trx_commit=1`, a 128 MB buffer pool), which slows every
commit and schema change.

On a machine that only holds dev and test data, these lines in `my.ini` (then
restart MySQL) fix both; the durability lines trade crash-safety of the last
second of writes for speed:

```ini
[mysqld]
table_open_cache=20000
table_definition_cache=20000
open_files_limit=65535
skip-log-bin
innodb_flush_log_at_trx_commit=2
innodb_buffer_pool_size=4G
```

Until the cache is raised, `composer test:fast` runs 3 workers and says why.

## 5. CI (not started)

On top of Stephan's branch once it lands: CI already gains from steps 1 and 2
(batches of one machine now reuse a slot instead of rebuilding it), but each
fresh machine still builds once. Next: cache the built database or dump per
migration set, then reconsider the shard count.
