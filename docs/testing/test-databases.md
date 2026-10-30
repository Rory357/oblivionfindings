# How tests get their database

Every Pest process gets its own MySQL database, `<DB_DATABASE>_<pid>` (for
example `oblivion_findings_codex_test_12345`), built when its first test
starts and dropped when it exits (`Tests\TestCase::configureIsolatedTestingDatabase`,
`Tests\Support\IsolatedDatabaseCleanup`). A process killed before it could
drop its database leaves it behind; the next process's dead-PID prune removes
it. About 45 test files and several worker scripts check that their database
is named after their own PID before they commit fixtures, so this naming
stays.

## The build

1. Load `database/schema/mysql-schema.sql`, through the `mysql` client when
   it is installed (`C:\Program Files\MySQL\MySQL Server 8.0\bin` on Windows,
   `PATH` elsewhere, or `MYSQL_CLIENT_BIN`), otherwise through PDO
   (`Tests\Support\MysqlStatementSplitter` splits the file the way the client
   does). `MYSQL_TEST_SCHEMA_LOADER=pdo` forces the PDO path.
2. Run the migrations that are newer than the snapshot.

Step 2 is what made tests slow: on 31 Oct 2026 the snapshot was from
4 Aug and every process replayed 462 migrations (274 s on the shared PC).

## Keeping the snapshot fresh

```bash
bash ~/.claude/heavy-lock.sh "refresh-dump" -- php scripts/testing/refresh-schema-dump.php
```

The script builds a reference database exactly as a test process does (old
snapshot, then every pending migration, with phpunit.xml's environment),
dumps it with mysqldump (structure, the rows migrations insert, triggers;
no `DEFINER`s), and loads the new file twice, through the `mysql` client and
through PDO. It replaces the snapshot only when both copies match the
reference in schema, every table's checksum and every auto-increment
counter. It takes about as long as one old-style build.

Refresh it on main after a batch of migrations lands, as its own commit.
Don't use `php artisan schema:dump`: it keeps only the tables and the
`migrations` rows, so tests would start without the permissions, roles and
other rows that migrations insert. `TestSchemaSnapshotBoundaryTest` fails if
that happens.

Branches with new migrations still work: the build runs them after the
snapshot, as before.

## Measured (31 Oct 2026)

| | Before (4 Aug snapshot) | After (31 Oct snapshot) |
| --- | --- | --- |
| Local build (shared PC) | 38 s load + 274 s migrations | see the merge request |
| GitHub build (per process) | 100–115 s | see the merge request |

## Why there is no database pool

Reusing a ready database between processes was built and measured, then left
out. A process's database must carry its own PID (the ownership checks
above), so a ready copy has to be moved into it (`RENAME TABLE` for ~1,200
tables, plus re-creating its triggers) and back out at exit. On the shared
PC each move took 38–43 s, so two moves cost more than one build from the
fresh snapshot. The cost is MySQL's data-dictionary work per table, not disk:
a shared tablespace file made no real difference (create 34 s instead of
58 s, move 38 s, drop 17 s instead of 21 s).

A pool with fixed database names would be fast (a check of every table's
checksum takes under 2 s), but it means changing the ownership checks in
those ~45 test files and worker scripts. That is a decision for MASTER and
Stephan, not part of this change.
