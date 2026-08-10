# Configuration, firmware and capacity-history release acceptance

This is the bounded A10 acceptance gate for real production configuration and history evidence. It is read-only. It does not collect a snapshot, create an evidence file, copy configuration, expose a target, change retention, or repair a restore.

A local database, factory, mock store, preview, Dusk run, PHPUnit process, relabelled environment, or locally authored fixture is regression evidence only. It must never be labelled as production proof. The gate permits store reads only after exact process-scoped restore endpoints, concrete stores, private roots, the fixed protected release authority and its exact clean checkout have passed the preflight.

## What one green gate proves

The gate proves all of these linked facts without printing a Site, Device, target, hostname, configuration value, firmware value, metric value, object path, endpoint or external key:

1. The process uses the exact restored MySQL DSN and exact restored InfluxDB URL, token, organisation and bucket supplied to this process. The resolved stores are the production `LaravelSnapshotStore` and `InfluxDbTimeSeriesStore`, not fakes.
2. The `monitoring-restore` disk is private, non-serving and fail-closed. Its exact process-scoped local root, or exact object bucket, endpoint and root, is outside the release checkout. The fixed restore sentinel exists with its exact content.
3. The exact verified restore reconciliation artifact has zero continuity/dependency gaps, met RPO/RTO, uses the same signed backup generation and recovery point, and is bound by its exact SHA-256.
4. Two immutable MySQL snapshot IDs and UUIDs belong to the same canonical Site and Device. The later snapshot directly follows the baseline inside the signed observation window.
5. Both restored payloads are successful native read-only SSH or WinRM inventory captures. Their raw bytes, canonical configuration documents, private storage paths and Site/Device target identity match keyed HMAC commitments in the independently signed production attestation.
6. The later configuration differs. The gate recomputes the structural diff from both restored payloads using the application algorithm and requires the stored bounded diff to match exactly, including truncation.
7. Both restored firmware values match the immutable MySQL rows and signed keyed-HMAC commitments. The separately signed browser companion links the exact changed firmware commitment.
8. One immutable pointer-event and series prove capacity history advanced, and the restored InfluxDB scope returns the exact old and new boundary points with matching identities and finite values.
9. A separate browser attester signs the same release, backup generation, changed snapshot ID/UUID, content, diff, firmware and capacity commitments. Both `1440 x 900` and `1280 x 800` records include immutable capture and network-trace hashes rather than untrusted passed strings.

## Required isolated process

Before invoking A10, install the release authority only at
`/etc/oblivion/monitoring-configuration-history-release-authority.json`. There
is no CLI or environment override. It must be a stable root-owned regular
non-symlink file, not group- or other-writable, and valid for no more than 24
hours. Its duplicate-key-free exact schema contains `schema_version=1`,
`evidence_class=monitoring_configuration_history_release_authority_v1`, opaque
`AUTHORITY-` and `ACL-` references, the exact 40-hex release revision, the
restored-environment SHA-256 reference, distinct production and browser Ed25519
public keys, SHA-256 of the secret HMAC key, and exact UTC validity bounds. The
private signing keys remain on their independent review systems.

Use the same isolated restore process that passed `monitoring:reconcile-restore --assert-process-config`. Before invoking A10, export only these runtime values without printing them:

- `MONITORING_RESTORE_MYSQL_DSN` and matching `DB_URL`.
- `MONITORING_RESTORE_INFLUX_URL` and matching `MONITORING_TIMESERIES_URL`.
- `MONITORING_RESTORE_INFLUX_TOKEN`, `MONITORING_RESTORE_INFLUX_ORG`, and `MONITORING_RESTORE_INFLUX_BUCKET`, each exactly matching the configured restored Influx scope.
- `MONITORING_RESTORE_FILESYSTEM_DRIVER` and `MONITORING_RESTORE_FILESYSTEM_ROOT`. For S3/MinIO, also set the exact `MONITORING_RESTORE_OBJECT_BUCKET` and `MONITORING_RESTORE_OBJECT_ENDPOINT`.
- `MONITORING_A10_EVIDENCE_DIRECTORY`, an absolute private evidence directory outside the checkout. On POSIX the object/evidence roots and all three input files must have no group/other permissions. On Windows protect both roots from inherited ACL changes and set `MONITORING_A10_WINDOWS_ACL_ALLOWED_IDENTITIES` to the exact pipe-delimited service-account, SYSTEM and approved administrative identities; every effective allow rule must be in that allowlist. Input files must inherit only those rules. The independent attester binds the authority's opaque ACL review reference.
- `MONITORING_A10_EVIDENCE_HMAC_KEY`, base64 for at least 32 random bytes held in the approved secret store. Its raw-key SHA-256 must match the fixed authority; a caller-selected replacement cannot validate commitments.

The gate explicitly rejects PHPUnit, local relabelling, fake stores, a stale config cache, mismatched process values, a default in-checkout restore root, evidence outside the private evidence directory, an absent/expired/replaced authority, a caller-selected signer/HMAC/revision, or a checkout whose clean `HEAD` and `origin/main` do not both equal the authority revision before any monitoring store method runs.

## Commitments and attestations

Do not use bare SHA-256 for targets, firmware versions, storage paths or external keys; those values may have low entropy and be dictionary-reversible. Every field ending in `_hmac_sha256` is lowercase `HMAC-SHA-256` using the evidence HMAC key and the exact value described by its name. Content HMACs cover exact restored JSON bytes. Configuration HMACs cover compact JSON with unescaped slashes. The diff HMAC covers the exact compact recomputed diff JSON. Target identity covers `site:<id>|device:<id>`.

Both documents use schema version 2. Duplicate JSON object keys are rejected recursively, including keys whose escape sequences decode to the same name; normal last-key-wins parsing is never accepted as evidence. Recursively sort every JSON object by key while preserving list order, remove the `attestation` member, encode compact JSON with unescaped slashes, prefix the exact context plus a newline, then create an Ed25519 detached signature:

- Production context: `oblivion-a10-production-manifest-v2`
- Browser context: `oblivion-a10-browser-evidence-v2`

Base64-encode the 64-byte signature. Set `attestation.key_reference` to `ATTEST-` plus the first 32 lowercase hexadecimal characters of SHA-256 over the raw public key. The production and browser documents must be signed by their distinct pinned keys. Any tampering, extra field or wrong signer fails closed.

The production manifest must contain exactly:

- Production/real-host/non-fixture classification flags and the current release revision.
- Opaque `TARGET-`, `RUN-` and `ACL-` references.
- The reviewed observation times.
- Baseline and changed snapshot IDs plus UUIDs, capacity-series ID and pointer-event ID.
- HMAC commitments for baseline/changed content, configuration, storage path and firmware, plus diff, capacity external key and target identity.
- `restore.backup_generation_reference`, `restore.recovery_point_at_utc`, SHA-256 of the exact verified restore reconciliation artifact, and the protected restored-environment reference.
- Signed opaque `CHG-`, `OP-` and `RV-` references and an approved decision.
- The production attestation envelope.

The browser companion must contain exactly the restored environment classification, current release, protected restored-environment reference, same backup/ACL references, changed snapshot ID/UUID, capacity-series ID, changed content/diff/firmware/capacity HMAC commitments, the configuration/firmware route contract, and both viewport objects. Each viewport object contains `status=passed`, an opaque `CAPTURE-` reference, SHA-256 of the retained capture, and SHA-256 of the retained network trace. The browser attestation binds all of it.

Keep the production manifest, the exact verified restore reconciliation JSON and its adjacent exact-name `.sha256` sidecar, and the browser companion inside the private evidence directory. They must be regular non-symlink files no larger than 64 KiB; never commit them. The gate recomputes the restore JSON hash, verifies the sidecar names that exact JSON file, and accepts only a verified V3 restore artifact carrying the protected restore-authority reference/hash and immutable backup-manifest hash, whose clean `HEAD == origin/main` revision and restored-environment reference match both independently signed documents. Preserve the protected authority, signed source documents and capture/trace artifacts in immutable evidence storage.

## Production observation and restored browser prerequisites

1. Run the supervised native read-only SSH or WinRM worker against one approved reachable production host. Capture a baseline, make one approved reversible configuration change through the normal workflow, capture the successor, and allow a real capacity series to advance.
2. Complete the approved backup and the full isolated restore. Retain the exact green reconciliation artifact produced by [Monitoring storage restore](storage-restore.md); do not recreate its JSON manually.
3. Only after the restore reconciliation artifact's `verification_completed_at_utc` has passed, run D07 from [IT and Security desktop release acceptance](../it-security-desktop-release-acceptance.md) against that same restored release. The browser companion's signed `verified_at_utc` must be at or after that completion time. At both viewports inspect the configuration history/diff, firmware history and capacity history, plus page source, Inertia props, Fetch/XHR payloads, console and request targets for leaked target or configuration data.
4. Have the independent production reviewer and separate browser reviewer create and sign their exact schema-v2 documents. The browser reviewer must retain the capture and network-trace files whose hashes are signed.

## Execute

Run from the active restored release as the bounded service account:

```powershell
php artisan monitoring:configuration-history-evidence `
  --manifest=C:\private\a10\production-manifest-v2.json `
  --restore-evidence=C:\private\a10\reconciliation-exact.json `
  --browser-evidence=C:\private\a10\browser-companion-v2.json `
  --json
```

The command exits `0` only when every value-free check is `verified`. It repeats the protected authority, exact checkout, endpoint, store and private-root preflight after all restored reads and before emitting success, so expiry or replacement during verification fails closed. It prints only the check states, counts, a timestamp and an evidence fingerprint. Dependency and payload failures become `not_verified`; exception values are never emitted.

Retain the three exact inputs, stdout, signatures, capture/trace artifacts and approval record together. A failed or unsigned document cannot be repaired in place; preserve it, correct the source workflow and issue a new independently signed evidence set.

This gate does not by itself close A05, V09, V10, deployment, or the overall release.
