# Production monitoring retention acceptance

This runbook records the A05 production acceptance evidence for native monitoring time-series retention. It uses the application's configured MySQL business store and configured InfluxDB time-series store. It is an evidence run of the real retention lifecycle, not a fixture generator or a dry run.

## Safety and evidence boundary

- Run only in the deployed `production` application with an approved change window and a current restore point.
- The command executes the normal raw-to-hourly and hourly-to-daily downsampler, then applies policy-governed metric retention. Eligible raw and hourly ranges will be deleted from InfluxDB only after the application dynamically verifies every occupied downstream bucket and its exact count, minimum, maximum, p50, p95 and stored value.
- Configuration-snapshot retention is excluded from this bounded command.
- The command refuses local, testing, SQLite, fake-store, reserved/documentation hostnames, non-TLS InfluxDB, incomplete endpoint and unhealthy InfluxDB configurations. Changing the application environment merely to bypass this guard invalidates the evidence.
- A production label is not sufficient. The command observes the live MySQL server UUID, host, port and schema plus the live InfluxDB HTTPS certificate and configured organisation/bucket scope. A separately controlled Ed25519 attestation must pin those commitments, the exact 40-character deployed release revision and the one-time run UUID. The trusted public key and release revision come only from the fixed protected release-authority file; process environment cannot substitute either trust input.
- Tests, local fixtures, seeded demonstrations and copied JSON are never production evidence. A release artifact is eligible only when `classification` is `production_real_endpoints`, `status` is `verified`, and `a05_release_evidence` is `true`.
- The artifact contains only times, counts, fixed endpoint types, status and safe error codes. It never contains Sites, Devices, monitors, series, policies, metric names, dimensions, values, payloads, endpoint addresses or credentials.

## Preconditions

Prepare representative real data before the approved window. The command fails closed unless one run can prove all of these cohorts:

1. At least one raw series has occupied buckets old enough for raw retention and can continue through an hourly target and a daily target.
2. At least one hourly series has occupied buckets old enough for hourly retention.
3. At least one deletion is governed by an active privacy-scoped retention policy.
4. At least one otherwise-due series is protected by an active policy legal hold or canonical Site, Device or series legal hold.
5. Each retained series with a MySQL pointer has data at that referenced range in InfluxDB. For the due legal-hold cohort, the full first-to-last half-open range must retain the same point count and payload commitment; proving only the first or last point is insufficient.
6. The downsampling window limits are sufficient to cover the prepared ranges in one invocation. Increase the configured bounded window count through the normal reviewed configuration process if necessary; do not alter data or evidence code during the acceptance run.
7. No `pending` or `delete_acknowledged` retention deletion intent is awaiting reconciliation. The command attempts recovery first, but any intent that cannot be completed makes the evidence fail.

Provision an absolute evidence directory outside the release checkout. It must already exist, be writable only by the service account (POSIX mode `0700`, or an equivalently restricted Windows ACL), and be on append-only or retention-locked storage. Do not place it in a public web, shared temporary or source-control directory.

Before the window, install the release-authority record at exactly `/etc/oblivion/monitoring-retention-release-authority.json`. There is no command-line or environment override for this path. It must be a root-owned regular file, stable throughout the read, not a symlink and not group- or other-writable. Its exact JSON keys are:

- `schema_version`: integer `1`;
- `evidence_class`: `monitoring_production_retention_release_authority_v1`;
- `release_revision`: the exact lowercase 40-character deployed revision;
- `valid_from_utc` and `valid_until_utc`: exact UTC-second timestamps defining a window no longer than 24 hours;
- `attestation_public_key_base64`: the independent authority's 32-byte Ed25519 public key in strict Base64;
- `key_reference`: `ATTEST-` followed by the first 32 hexadecimal characters of the SHA-256 commitment of the decoded public key.

Provision the record through the protected release-control plane. The deployed application service account needs read access only. A value in `MONITORING_A05_ATTESTATION_PUBLIC_KEY` is not a release trust anchor and is ignored by this gate; do not use caller environment to select or rotate the approval key.

Then have the independent release authority create the endpoint-attestation JSON. It must:

- be an ordinary file at an absolute path outside the release checkout, not a symlink, and no larger than 32 KiB;
- use schema `monitoring-production-retention-endpoint-attestation-v1` with no additional keys;
- contain the one-time run UUID, exact protected-authority release revision, UTC validity window of no more than 24 hours, the three observed endpoint commitments, key reference and detached signature;
- be signed with the private Ed25519 key corresponding to the public key in the protected release-authority record; never place the signing key on the application host.

Generate the endpoint commitments from the deployed runtime through the approved release process. Do not type endpoint addresses, database identity, certificates or commitments into application code, and do not reuse an attestation from another release, environment, scope or run.

## Execute

Run this as the deployed application service account from the active release:

```powershell
php artisan monitoring:record-production-retention-evidence --output-directory="D:\OblivionEvidence\monitoring-retention" --endpoint-attestation="D:\OblivionApprovals\a05-endpoints.json" --json
```

Use the platform-appropriate absolute private directory on non-Windows deployments. Do not pass endpoint settings or credentials on the command line; the command resolves only the deployed configuration.

The command is automatically registered from `app/Console/Commands`. It performs these gates in order:

1. Requires the production runtime, a real non-reserved MySQL connection, the concrete InfluxDB store, complete TLS endpoint configuration, an eligible output directory and an endpoint-attestation path.
2. Reads the fixed protected release-authority record, rejects an unprotected, unstable, expired or malformed record, and obtains the only eligible release revision and attestation public key from it. Before any endpoint probe or retention mutation, the command requires one clean source checkout whose `HEAD` and `origin/main` both equal the protected authority revision; it repeats that exact check after verification and before writing a release-eligible artifact. It then connects to the live endpoints and verifies the MySQL identity and InfluxDB TLS certificate/scope against the signed pins before requiring a healthy InfluxDB response.
3. Captures an opaque, in-memory count and payload commitment across the complete due legal-hold ranges. These record keys and payload commitments never enter the output.
4. Runs raw-to-hourly and hourly-to-daily downsampling synchronously against InfluxDB and persists coverage watermarks in MySQL.
5. Executes metric-only retention through durable deletion intents. The pending intent and its exact policy/range/rollup commitment are committed before external deletion; acknowledgement, pointer transition and the linked tombstone complete afterward. A later run safely reconciles an interrupted intent without deleting the range twice.
6. Blocks raw or hourly deletion unless the coverage watermark spans the entire deletion interval and every occupied source bucket has an exact aggregate match in the correct downstream series. Bucket presence alone does not pass.
7. Verifies completed intent-to-tombstone lineage, exact deleted-range absence, privacy-policy execution, unchanged full-range legal-hold count and payload commitment, a connected raw-to-hourly-to-daily execution chain, intact MySQL Site/Device/monitor relationships and pointer validity, and every remaining MySQL-to-InfluxDB series reference.
8. Validates that a `verified` report has non-empty execution semantics and zero integrity/unresolved-intent gaps, then exclusively creates a JSON artifact and matching `.sha256` sidecar. UUID filenames plus exclusive-create mode make collisions fail without overwriting prior evidence. Both writes are flushed and synced; if sidecar publication fails, the newly created artifact is removed and the existing sidecar is preserved.

## Accept or reject

Accept the run only when the command exits `0` and its value-free result has:

```json
{
  "status": "verified",
  "a05_release_evidence": true,
  "artifact_filename": "monitoring-retention-...json",
  "checksum_filename": "monitoring-retention-...json.sha256",
  "sha256": "...",
  "errors": []
}
```

Independently validate the sidecar in the evidence store, confirm the embedded endpoint attestation run UUID equals the artifact UUID and its release revision and key reference equal the fixed protected release-authority record, attach both immutable files to the release record, and record the deployed release identifier, operator, approval/change reference and observation window in the release system. Those operational identifiers deliberately stay outside the value-free application artifact.

Reject the run if the command exits non-zero, no artifact is produced, the checksum differs, the fixed release authority is absent/unprotected/expired/malformed, the endpoint attestation is absent/expired/mismatched/invalid, `a05_release_evidence` is false, any safe error code is present, the verified execution counts are empty, or an unresolved deletion intent remains. A failed artifact is diagnostic evidence only and cannot close A05.

## Failure and recovery

- Endpoint, protected-authority, attestation, health or output-directory failures occur before any downsampling or retention mutation. Correct the deployed configuration, protected authority/approval file or private evidence-store permissions and rerun with a new one-time attestation under a new approved window.
- Coverage, privacy, legal-hold, tombstone, pointer or reference errors fail the evidence result. Do not edit the JSON, its checksum, coverage rows, series pointers or tombstones. Investigate the named safe error code and retain the failed pair.
- If InfluxDB deletion succeeds but database finalisation is interrupted, leave the durable `pending` or `delete_acknowledged` intent intact. Restore database service, run the same normal retention path, and allow reconciliation to verify absence/presence, complete the pointer transition and create exactly one linked tombstone. Never manually mark an intent complete or fabricate a tombstone. The current evidence run remains failed if an intent is unresolved.
- A failure after retention begins may have completed normal policy-governed deletions. Completed intents, their linked tombstones and pointer transitions are the authoritative audit trail. Do not attempt to reverse them by deleting business records. Follow the approved time-series restore procedure, reconcile against the preserved MySQL lineage, and run acceptance again with a new artifact UUID and attestation.
- Artifact creation is exclusive. A collision or I/O failure never overwrites an existing pair. A partial newly created artifact is removed when its sidecar cannot be published. Resolve storage capacity/ACL/durability problems and rerun; never rename a failed or partial file into an accepted artifact.

This command proves one deployed execution only. Any required multi-day observation or independent restore/load evidence remains a separate release gate.
