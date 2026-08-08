# Protocol and monitor-policy release acceptance

## Purpose

Use this read-only gate after the central runtime and any approved remote path
are deployed. It proves only evidence already produced by real targets and
reviewed policy drills. It never sends a probe, changes a policy, reveals a
target, or substitutes fixture data for production evidence.

## Prepare reviewed evidence

Within the bounded evidence window, retain at least one fresh real observation
for ICMP, TCP, DNS, plain HTTP, HTTPS, TLS, SNMPv3, read-only SSH inventory and
read-only WinRM inventory. Send a real approved SNMP trap, syslog message and
flow datagram through their supervised listeners and consumers. Every provider
whose registered manifest declares operational observations must have a fresh
canonical provider monitor observation; currently this applies to UniFi and
Milesight. Never create a cloud capability for a provider whose official
contract does not support it.

Use dedicated non-customer-impacting monitors for the policy drill. Exercise a
confirmed failure and recovery, all three hysteresis regions, an approved
maintenance suppression, a same-Site dependency suppression, a derived stale
state, an explicit unknown observation, and a numeric baseline deviation.
Keep active coverage expectations free of missing, paused, failed-collection or
invalid-scope results. The verifier also executes Device, Site and estate
roll-ups read-only. Do not weaken thresholds or leave a customer monitor stale
to satisfy this gate.

## Sustained value-free verifier

Run from the deployed release. The default is fifteen samples one minute apart
over evidence from the preceding hour:

```bash
bash scripts/monitoring/verify-protocol-policy-evidence.sh \
  --application-path=/var/www/oblivionfindings \
  --samples=15 \
  --interval-seconds=60 \
  --window-minutes=60
```

The wrapper refuses fewer than five samples or intervals below one minute. Every sample must retain the complete direct-protocol and policy matrix plus both currently required provider rows, UniFi and Milesight. It pins the sorted evidence-key matrix for the whole observation period, so a disappearing provider or changing evidence roster fails acceptance rather than being hidden by the remaining verified rows. It
also requires an opaque cursor derived from persisted monitor observations, listener deliveries, provider completions and dependency/maintenance suppressions to advance at least once. Merely rerunning the report advances its report timestamp but not this cursor, so one old in-window evidence set cannot satisfy every sustained sample. The cursor and its Site/Device-free inputs are never printed by the wrapper. It emits only aggregate timestamps and counts. The underlying read-only report can
be inspected without displaying Site, Device, target, credential, provider
response, event payload or observation values:

```bash
php artisan monitoring:protocol-policy-evidence --window-minutes=60 --json
```

Any `not_verified` row is an acceptance failure, not permission to seed or edit
production data. Record the missing named capability, complete the reviewed
real protocol or policy exercise, and rerun the full observation period.

## External evidence still required

Retain supervised process timestamps, provider request/audit references,
approved target-side logs, the value-free verifier result and operator sign-off.
For maintenance, dependency, hysteresis, stale and unknown exercises, retain the
expected before/during/after state and proof that ticket or notification storms
were not created. Do not retain secrets, IP addresses, URLs, Site names, Device
names, raw telemetry or provider response bodies in the release evidence.
