# Lifecycle trigger deployment boundary

## Scope

Migrations `2026_08_06_000041_retain_device_relationship_history` and
`2026_08_06_000047_enforce_monitoring_evidence_lifecycle` install three and
thirteen MySQL triggers respectively. MySQL makes each DDL statement atomic,
but it does not make the sequence of table changes and sixteen trigger
statements one transaction. A failed trigger creation can therefore leave a
partially applied schema even though Laravel has not recorded the migration.

`scripts/deploy-server.sh` fails closed around that boundary. It uses the same
configured database connection as `artisan migrate`; it does not read a root
password, grant `SUPER`, change a global variable, or print the database,
account, host, grants, trigger bodies, or definer.

## One-time database administration

The deployment connection requires effective schema-level `ALTER`, `CREATE`,
`INDEX`, `INSERT`, `REFERENCES`, `SELECT`, and `TRIGGER` privileges. A table-only
grant is insufficient because migration 000047 creates new event tables before
installing their triggers. The verifier rejects global `ALL`, `SUPER`,
`SET_USER_ID`, `SYSTEM_VARIABLES_ADMIN`, and `BINLOG_ADMIN`; none is required by
these migrations.

When binary logging is enabled, MySQL must report
`log_bin_trust_function_creators=ON` before the unprivileged migration account
can create these triggers. Configure that setting persistently through the
approved database parameter/configuration system. Do not put an administrator
credential in the application environment and do not make the deploy script
run `SET GLOBAL`. When binary logging is disabled, the trust variable is not a
trigger-creation prerequisite; the verifier records `not_required` rather than
reporting a false failure.

The migrations omit an explicit `DEFINER`, so MySQL binds every trigger to the
account that creates it. Keep that migration account present with the
schema-level `TRIGGER` and `SELECT` privileges and the `INSERT` privilege needed
by the audit triggers. Changing the configured migration identity requires a
reviewed trigger recreation; the postflight refuses a different definer.

## Automated preflight

After Composer makes Artisan available and before any migration runs, deploy
executes:

```bash
php artisan database:verify-lifecycle-triggers preflight --json
```

The command is read-only. It requires MySQL 8.0.19 or later in the reviewed 8.x
family, applies the binary logging/trust truth table, and resolves the configured
account's effective grants with `SHOW GRANTS FOR CURRENT_USER() USING` its
currently enabled roles. It does not inspect the grant rows of another account.
Applicable schema partial-`REVOKE` rows are subtracted from effective grants,
and safely escaped schema identifiers must decode to the exact configured
database. The command validates the two migration-history states, rejects migration
000047 appearing before 000041, and checks all visible triggers on the six
owned tables against the source roster already expected for applied
migrations. It also rejects:

- any migration-000041 columns or replacement unique index while 000041 is
  still pending;
- either migration-000047 event table while 000047 is still pending;
- missing post-migration columns, indexes, restrictive foreign keys, event
  tables, or event-table foreign keys when a migration is recorded as applied;
- a non-unique, reordered, missing, or duplicate-active relationship index, a
  surviving obsolete relationship index, or a changed virtual guard expression;
- a non-InnoDB owned table; and
- inconsistent snapshot, metric-series pointer, or retention-tombstone data
  before migration 000047.

An entirely empty database is allowed: the prerequisite tables can be created
by earlier migrations in the same run. Any marker belonging specifically to an
unrecorded goal migration is treated as partial DDL and requires reviewed
forward repair. Do not retry blindly and do not auto-drop a trigger or evidence
table.

## Isolated migration and exact postflight

Immediately before migration, deployment enters Laravel maintenance mode, waits
the bounded web-drain interval, records the exact application queue-worker PIDs,
signals `queue:restart`, and waits for every pre-migration worker to finish its
current job and exit. Replacement workers see maintenance mode and do not reserve
new jobs. `DEPLOY_WEB_DRAIN_SECONDS` defaults to 5 and is bounded at 60;
`DEPLOY_WRITER_DRAIN_TIMEOUT_SECONDS` defaults to 390 and is bounded at 900. A
drain timeout fails before DDL and leaves the application down.

Deployment then runs:

```bash
php artisan migrate --force --isolated=75
php artisan database:verify-lifecycle-triggers postflight --json
```

Exit code 75 makes failure to acquire Laravel's shared migration lock fatal
instead of reporting a successful deploy that ran no migration. The production
cache used for the isolation lock must be shared by every deployment host.

Postflight runs before Supervisor installation or runtime restart. It requires
both migration rows, all essential schema postconditions, and exact equality
across every trigger on the six owned tables: sixteen names, owning table,
`BEFORE`/`AFTER` timing, `INSERT`/`UPDATE`/`DELETE` event, canonical source-body
SHA-256, and the configured migration account as definer. Missing, extra,
renamed, changed-body, moved, or differently defined triggers fail deployment.

The JSON report is value-free and contains only status, phase, UTC check time,
binary-log/trust state, migration state, aggregate trigger counts, and safe
failure codes. Retain that output with the release revision and migration log;
never add database configuration or `SHOW GRANTS` output to release evidence.

After the reviewed SSR, monitoring Supervisor, geocoder, and Queclink validations
complete, deployment restarts queues, boots the application console, repeats the
exact postflight, and only then runs `php artisan up`. An EXIT trap never calls
`up`; any failure after maintenance begins reports that the application remains
down for reviewed forward recovery.

## Partial-DDL response

Stop writers and keep the application unavailable for the affected release.
Record the migration log, the value-free verifier output, and the database
recovery point. Inspect schema and trigger metadata with the approved migration
connection, compare it to this exact release revision, and perform a reviewed
forward repair. MySQL cannot roll the whole multi-statement migration back as
one transaction. Do not run either lossy `down()` method, drop retained evidence,
or weaken the trigger roster to make a retry pass.
