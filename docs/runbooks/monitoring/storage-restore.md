# Monitoring storage restore

## Trigger and customer-visible symptoms

Trigger on `monitoring_timeseries_unavailable`, `monitoring_snapshot_store_unavailable`, failed retention/downsample work, restore rehearsal failure, or a disaster-recovery declaration. Users may see current state without retained trends, unavailable p95/forecast evidence, or configuration snapshot metadata whose payload cannot be downloaded.

## Distinguish the failure

- Time-series failure: current MySQL summaries may remain, but raw/hourly/daily queries fail.
- Snapshot object-store failure: metadata remains while governed downloads/hash checks fail.
- Secret-manager failure: restored credential references remain encrypted in MySQL, but health, exact-reference testing, or lease containment cannot be proven.
- MySQL/Redis runtime failure: queues, inbox/outbox, checkpoints, and current state are affected broadly.
- Site/collector/device failure: external stores remain healthy and only a scoped collection path is affected.

## Safe read-only diagnosis

```powershell
pwsh scripts/monitoring/verify-restore.ps1 `
    -ObjectDisk monitoring-restore `
    -BackupGeneration 'backup-20260808T010000Z' `
    -RecoveryPointUtc '2026-08-08T01:00:00Z' `
    -RecoveryStartedAtUtc '2026-08-08T01:10:00Z' `
    -MaximumRpoMinutes 15 `
    -MaximumRtoMinutes 60
```

Use the immutable backup manifest's safe generation identifier and UTC recovery-point time. Record `RecoveryStartedAtUtc` when the restore exercise begins, before restoring any dependency, and use the approved RPO/RTO limits. The script rejects missing, non-UTC, reversed, or future timestamps. It calculates RPO from recovery point to recovery start and RTO from recovery start through completed dependency reconciliation, then records both values and limits in the value-free artifact. The script fails closed when either calculated objective exceeds its approved maximum.

Use isolated restored endpoints. The verifier reads `MONITORING_RESTORE_MYSQL_DSN`, `MONITORING_RESTORE_REDIS_URL`, `MONITORING_RESTORE_INFLUX_URL`, and `MONITORING_RESTORE_VAULT_URL` directly from its process environment; never repeat their values as parameters to a child `pwsh` process. Configure the `monitoring-restore` filesystem disk only through the `MONITORING_RESTORE_FILESYSTEM_*` and `MONITORING_RESTORE_OBJECT_*` environment variables, using a separate private bucket/root and rehearsal-only access key. The verifier rejects every substitute disk name, then requires this dedicated disk to remain private, non-serving and fail-closed. Inject the restored Vault token through the process secret store as `MONITORING_VAULT_TOKEN`; do not pass it as a command argument. The script refuses non-local/private-looking MySQL, Redis, InfluxDB, and Vault hosts unless `-AllowProductionReadOnly` is explicitly supplied. Before any restored store is read, it points Laravel at a unique nonexistent config-cache path and runs a value-free configuration-only preflight that requires the booted MySQL, Redis, InfluxDB, snapshot-disk and Vault settings to exactly match the process-scoped rehearsal values. This prevents a deployed or copied `bootstrap/cache/config.php` from silently directing the rehearsal at stale stores. It then runs migrations in pretend mode and writes a value-free reconciliation report. Never print DSNs, tokens, snapshot paths, secret-manager references, lease identifiers, or payloads.

## Containment that preserves evidence

Stop retention deletion and downsampling against the affected store while keeping MySQL metadata, tombstones, inbox/outbox, and audit records. Make the affected storage state unavailable; do not fall back to fabricated history. Preserve immutable backup generations and object versions.

## Recovery and reconciliation

Restore MySQL, Redis, InfluxDB, the private snapshot disk, and the supported HashiCorp Vault endpoint into an isolated environment. Validate migration compatibility, then require `outbox_gap=0`, `inbox_checkpoint_gap=0`, `orphan_series=0`, `timeseries_pointer_gap=0`, `snapshot_hash_mismatch=0`, `topology_pointer_gap=0`, `collector_sequence_regression=0`, `stale_unpublished_delivery=0`, `published_projection_gap=0`, `provider_cursor_scope_gap=0`, `provider_cursor_stall=0`, `credential_reference_recovery_gap=0`, `credential_lease_recovery_gap=0`, `redis_unavailable=0`, `timeseries_unavailable=0`, `snapshot_store_unavailable=0`, and `secret_manager_unavailable=0`. `orphan_series` remains the relational Site/Device/Monitor integrity count. `timeseries_pointer_gap` performs one bounded read-only existence query, limited to one result within each retained series' stored first/last-point bounds, and returns only the count of MySQL pointers missing from the restored InfluxDB scope. The dependency probes perform only a Redis ping, the InfluxDB health request, an exact fixed-content read against a non-secret private-disk sentinel, and HashiCorp Vault's documented read-only [`HEAD /v1/sys/health`](https://developer.hashicorp.com/vault/api-docs/system/health) check with healthy-standby flags. The source application creates that fixed, non-secret sentinel idempotently only after a configuration snapshot payload has been written and read back successfully, and hash-safely compares the sentinel's exact content before committing snapshot metadata. An existing mismatch is a storage-integrity failure and is never silently overwritten. The snapshot dependency is healthy only when the sentinel was included in the approved backup generation and the exact fixed content survives the restore; an absent or mismatched sentinel fails the rehearsal even when the endpoint returned normally. Never create or repair the sentinel during the read-only verifier. The credential-reference check decrypts references inside the restored process, validates their keyed fingerprints and lifecycle shape, and returns only a count. The lease-recovery check returns only a count for expired/inconsistent active grants or terminal grants that still retain a recoverable lease identifier. Delivery and provider-cursor checks allow 15 minutes for normal in-flight processing; an older non-zero count is a recovery blocker, not a reason to delete evidence, reset a cursor, or erase a pending lease identifier before containment.

The health endpoint cannot prove that one exact restored reference can still issue material. After the read-only report is zero and change approval is recorded, use **Settings & audit > Credential references > Test** on one non-production restored reference. That existing governed action performs one short-lived issue/material-consumption/immediate-revoke cycle and records only safe audit state in the isolated restored MySQL copy. Require a passed result, no outstanding prior lease, and no identifier or material in logs. This step is intentionally not automated by the read-only script because the common lease interface has no side-effect-free exact-reference API. A different secret-manager provider needs an approved read-only health contract before it can be added; do not infer an endpoint.

After approval, repoint one controlled environment, resume reads, then orchestration/provider workers, event/check consumers, remaining workers, and downsample/retention last.

## Validation

Confirm current summaries match retained series, snapshot payload hashes match metadata, latest topology counts match nodes/edges, collector sequences are monotonic, provider cursors still belong to active declared Site/capability scopes, every old published envelope has a completed inbox projection, credential references decrypt and match their fingerprints, active leases are current and containable, terminal leases retain no recoverable identifier, legal holds remain active, deletion tombstones contain no deleted values, and a representative Site can read authorised history/snapshots while a denied Site cannot.

## Escalation, repair rule, and closure evidence

The incident commander owns restore order; database, Redis, time-series, object-store, secret-manager, security/privacy, and runtime owners approve their layers. Prefer forward reconciliation. Never roll forward a store with unresolved gaps; revert to the last intact immutable backup generation. Close with the successful process-configuration preflight, backup generation/time, pretend migration output, report path and zero counts, the separately approved exact-reference issue/revoke result, sample authorisation proof, service resume order, and recovery point/time objectives achieved.
