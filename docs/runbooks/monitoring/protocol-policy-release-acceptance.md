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

Every enabled provider monitor must independently resolve through the canonical
Device provenance contract to exactly one active mapped Site. An unresolved,
ambiguous or out-of-mapping monitor is an acceptance failure even when another
fresh monitor already covers that Site. The sustained verifier binds each
provider monitor to that resolved Site inside its opaque roster fingerprint, so
moving a monitor between mapped Sites invalidates the observation period without
printing either identity.

For both UniFi and Milesight, run the real provider connection test inside the
same bounded evidence window. Every active mapped Site must then complete a
fresh observation-capability pull after it started, without a partial page,
retry deferral or capability exception. A retained `connected` flag, an old
credential test, or a cursor timestamp from a partial/rate-limited execution is
not provider acceptance. Queclink is proved separately with the native-listener
runbook and authentic tracker frames; it must not be added to this cloud/API
provider matrix.

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

The wrapper refuses fewer than five samples or intervals below one minute. Every sample must retain the complete direct-protocol and policy matrix plus both currently required provider rows, UniFi and Milesight. It pins an application-keyed fingerprint of the actual enabled monitor, profile, coverage, dependency, maintenance and provider-mapping roster for the whole observation period, so replacing or reconfiguring evidence cannot be hidden behind unchanged row names or counts. Each direct protocol, listener and provider row also has its own opaque roster and oldest/newest persisted-evidence window. At the final sample, that row's oldest evidence must be newer than its initial newest evidence. This proves every configured monitor, every mapped provider-Site execution and every listener advanced; one busy monitor can no longer mask idle peers. The underlying cursor remains derived only from persisted execution evidence, and merely rerunning the report cannot advance any of these windows. No Site, Device, target or provider identity used to form the keyed fingerprints is printed by the wrapper. It emits only aggregate timestamps and counts. The underlying read-only report can
be inspected without displaying Site, Device, target, credential, provider
response, event payload or observation values:

Maintenance evaluation and roster pinning use the same recurrence-aware bounded
window set. A recurring window remains pinned when its first occurrence ended
before the evidence period, including an indefinite recurrence, and any change
to that window invalidates the sustained observation fingerprint.

```bash
php artisan monitoring:protocol-policy-evidence --window-minutes=60 --json
```

Any `not_verified` row is an acceptance failure, not permission to seed or edit
production data. Record the missing named capability, complete the reviewed
real protocol or policy exercise, and rerun the full observation period.

## Protected A07/A08 release companion

The six-field child result is not A07/A08 release evidence. It deliberately
contains no release revision, environment, authority, target-side reference or
reviewer signature. Do not attach a child run from an arbitrary checkout to a
different release or replace the before/during/after review with narrative
sign-off.

First run the protected S10 combined gate from the same release as documented
in `s10-release-evidence.md`. Its exact artifact proves that this sustained
protocol/policy child executed inside the protected clean revision/environment
boundary; the Queclink portion remains a separate native-TCP S10 contract and
does not become a cloud provider capability.

For A07/A08, the release owner separately installs the exact duplicate-free
authority at
`/etc/oblivion/monitoring-protocol-policy-release-authority.json`. It must be a
stable regular non-symlink file owned by root, not group/other writable, valid
for no more than 24 hours, and contain only `schema_version=1`,
`evidence_class=monitoring_protocol_policy_release_authority_v1`, one opaque
`AUTHORITY-` reference, the exact reviewed 40-hex `origin/main` revision, one
opaque environment SHA-256, the independently approved Ed25519 public-key
SHA-256 and exact UTC validity bounds. There is no path, key, revision or
environment override.

An independent reviewer signs one exact duplicate-free
`monitoring_protocol_policy_release_evidence_v1` JSON document. It binds that
authority, environment and revision plus the byte SHA-256 of the exact S10
combined artifact. It contains the exact 14 protocol/provider attestations,
nine policy attestations and six transition drills required by this runbook:

- each direct protocol, supervised listener, read-only inventory protocol,
  UniFi and Milesight row has a bounded observation time plus opaque target-side
  and runtime references;
- every policy row has one bounded opaque reviewed-evidence reference; and
- confirmation, dependency, maintenance, hysteresis, stale/unknown and baseline
  transitions have ordered before/during/after references with zero ticket and
  notification storm counts.

The same signed document binds opaque supervised-process, provider-audit,
target-side-log and operator-signoff references and confirms that no target,
credential or payload was retained. All evidence times must remain within the
same preceding-hour/sustained-run interval and the protected authority window.
Missing, duplicate, stale, reordered, cross-environment or weak evidence fails
closed even when another protocol or policy row is healthy.

Keep the S10 artifact, signed A07/A08 document, detached signature and public
key in a private external directory outside the checkout. From the exact
deployed release run:

```bash
/usr/bin/php8.4 scripts/monitoring/verify-protocol-policy-release-evidence.php \
  --s10-release-evidence=/private/evidence/security-devices-s10-release-evidence.json \
  --evidence=/private/evidence/protocol-policy-review.json \
  --signature=/private/evidence/protocol-policy-review.sig \
  --public-key=/private/evidence/protocol-policy-review.pub
```

The verifier accepts only stable regular external files that are not
group/other writable, a clean exact `HEAD == origin/main`, the unchanged
protected authority before and after verification, an exact S10 combined
artifact with at least 15 one-minute protocol samples over the preceding-hour
window, the independently pinned signature and the complete exact matrices. It
emits only bounded counts, opaque hashes and timestamps. This makes the release
evidence coherent; local fixtures, a standalone child result or the verifier
contract does not itself close A07 or A08 without the real targets, listener
traffic, provider executions, policy drills, protected deployed S10 artifact
and retained independent review.

## External evidence still required

Retain supervised process timestamps, provider request/audit references,
approved target-side logs, the value-free verifier result and operator sign-off.
For maintenance, dependency, hysteresis, stale and unknown exercises, retain the
expected before/during/after state and proof that ticket or notification storms
were not created. Do not retain secrets, IP addresses, URLs, Site names, Device
names, raw telemetry or provider response bodies in the release evidence.
