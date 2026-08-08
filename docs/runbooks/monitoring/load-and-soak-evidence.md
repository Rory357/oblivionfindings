# Monitoring load and soak evidence

## Local synthetic fixture boundary

`tests/Performance/Monitoring/MonitoringLoadTest.php` is a bounded local
regression fixture. Its `full_scale` profile describes generated dataset size;
it does not mean a deployed runtime, live dependency, sustained load, or soak
observation was exercised.

Setting `MONITORING_WRITE_EVIDENCE=1` writes a collision-safe local JSON result
with a unique `artifact_id`. Every such artifact is classified as
`local_synthetic_fixture`, `test_process_only`, and `v09_release_evidence:
false`. These artifacts are prerequisite regression evidence only and cannot
close V09.

The v2 release verifier also has `--test-authority` solely for exercising strict
JSON and Ed25519 contract mechanics. Its output status is
`contract_valid_test_authority`, its authority is `test_only`, and both
`release_provenance_verified` and `v09_release_evidence` remain false. Omitting
the flag with a locally chosen key is not release evidence: only the protected
platform runner is authorised to inject the pinned release-authority key hash.

## Required deployed evidence

V09 load/soak evidence must come from an isolated deployed runtime and record a
unique run, exact release, sustained load profile, achieved throughput, exact
supervised process roster, dependency state, telemetry measurement provenance,
latency/error/queue samples, and post-load recovery. This gate is one part of
V09 only. It does not close restore, outage, provider-failure,
credential-rotation, runbook, browser, or final release acceptance.

## Independent platform authority

The source record is never trusted merely because it says `production` or
`isolated_deployed_release`. A protected platform service must sign a separate
`monitoring_load_soak_platform_attestation_v1` object using Ed25519. The private
key remains outside the application host. The verifier reads the public key from
a separate regular file and accepts it only when the SHA-256 of the decoded
32-byte public key equals the value injected out of band as
`MONITORING_LOAD_SOAK_ATTESTATION_PUBLIC_KEY_SHA256` by the protected release
runner. The evidence, attestation, public-key file and key pin are independent
inputs; no key identity declared inside the source is trusted.

The detached signature covers a canonical object containing:

- the complete source-file SHA-256;
- run UUID, exact 40-character release revision and opaque environment SHA-256;
- runtime class, approved load-profile SHA-256 and measurement-contract SHA-256;
- Supervisor observation generation; and
- whole-second UTC issue and expiry times.

The attestation must be issued no earlier than the completed source record, no
later than verifier time plus the 60-second clock allowance, and must remain
unexpired. A changed source byte, wrong pinned key, changed release/profile,
different Supervisor generation, expired statement or invalid signature fails.

## Version 2 source contract

The governed source is an exact `deployed_monitoring_load_soak_v2` JSON object.
Duplicate object keys are forbidden at every depth, including equivalent
escaped names such as `run_id` and `run_\u0069d`. The parser rejects duplicates
before normal JSON decoding so ignored values cannot hide targets, credentials
or payloads. Do not include a hostname, URL, Site, Device, credential reference,
payload, customer data or operator narrative.

All source timestamps use `YYYY-MM-DDTHH:MM:SSZ`. Fractional seconds are rejected;
duration, sampling and recovery therefore use the same exact
whole-second arithmetic. The chronology is:

```text
started_at < ended_at <= recovered_at <= created_at <= verified_at + 60 seconds
```

The observed duration must meet both the non-relaxable 3,600-second floor and
the preapproved duration.

### Preapproved value-free load and measurement contracts

Before `started_at`, the reviewer approves the objective policy and the exact
hashes of both contracts.

The load profile contains only bounded, non-target dimensions:

- `generator_mode: constant_rate`;
- concurrency from 1 through 10,000;
- positive scheduled rate no lower than the approved minimum throughput;
- event-class count from 1 through 64;
- opaque event-mix and target-scope SHA-256 values; and
- `profile_sha256`, recalculated from those dimensions in the documented field
  order and equal to `approved_load_profile_sha256`.

The measurement contract fixes `source_kind: platform_telemetry`, an opaque
source SHA-256 and metric-set SHA-256. Its canonical `contract_sha256` must equal
the preapproved measurement-contract hash. Every interval and recovery
observation supplies the same source/metric hashes, a positive raw measurement
sample count, an opaque observation SHA-256, and exact measurement-window start
and end times. This binds the aggregate p95, p99, error and queue values to a
reviewed telemetry definition without retaining metric values or endpoints in
the verification artifact.

### Exact supervised runtime roster

The source has one positive `supervisor_observation_generation` and exactly these worker roles:

```text
checks, commands, discovery, events, maintenance, orchestration, provider, topology
```

It also has exactly these listener roles:

```text
flow, snmp_traps, syslog
```

Each of the eleven roles maps to a distinct opaque SHA-256 runtime reference.
Every sample and recovery record must use the same Supervisor generation and
must mark every exact role `available`. Aggregate counts, unknown roles,
duplicated runtime references, or a substituted default worker fail. MySQL,
Redis, time-series, private object storage and secret manager must all be
`available` in every observation.

### Generator-scoped counters and continuous samples

The generator counter is bound to the exact `run_id`, starts at
`baseline_processed_events: 0`, and ends at `end_processed_events` equal to its
successful-event total. Attempted events equal successful plus failed events,
the attempted total remains within 0.1 percent or one event of the preapproved
constant-rate schedule, the producer SHA-256 is present, aggregate throughput
and error rate satisfy the approved policy, and generator exit is zero.

The first measurement window begins exactly at `started_at`; every later window
begins at the preceding observation; and the final observation is exactly
`ended_at`. Samples are a JSON list, strictly ordered and no more than the
approved interval apart. Every generator-scoped counter delta must meet minimum
throughput, with no inherited first-sample total. Processed totals never exceed
the generator end total and finish exactly on it.

Every sample must also satisfy the approved p95, p99, error and aggregate queue
limits. Recovery uses the same roster and telemetry contract, occurs within the
approved maximum after `ended_at`, reconciles the generator end total and
reports zero queue depth.

The non-relaxable policy ceilings remain:

- samples at most 60 seconds apart;
- p95 at most 2,000 ms and p99 at most 5,000 ms;
- error rate at most 1 percent;
- aggregate queue depth at most 1,000; and
- zero-backlog recovery within 300 seconds.

## Execute the release gate

Run from the exact deployed release checkout under the protected release runner.
The runner supplies the pinned key hash; do not type, copy or derive the pin from
the source or attestation being verified.

```text
php scripts/monitoring/verify-load-soak-evidence.php \
  --evidence=<governed-source-json> \
  --attestation=<platform-attestation-json> \
  --public-key=<release-authority-public-key> \
  --output-directory=<approved-evidence-directory>
```

Release mode exits zero with `status: passed` only when the source contract and
independently pinned Ed25519 attestation both pass. The output binds the source,
attestation, authority key, run, release, environment, profile, measurement
contract and Supervisor generation without emitting targets, credentials,
payloads, Sites, Devices, process references or measurement values.

The verifier uses exclusive file creation, flush and filesystem sync. This is
collision-safe publication, not proof of immutability. It records
`output_storage_semantics: collision_safe_exclusive_create` and
`worm_receipt_verified: false`. If release policy requires immutable retention,
move the closed artifact through the approved evidence workflow into WORM or
retention-locked storage and retain that platform's separate receipt. Do not
relabel the local file as immutable.

Retain the governed source, platform attestation, key authority record, output
hashes, generator/runtime audit and any WORM receipt together. A passing artifact
proves this bounded load/soak gate only and must never be presented as V09 or overall release completion by itself.
