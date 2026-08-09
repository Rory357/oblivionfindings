# S10 combined provider release evidence

## Purpose and boundary

Use this gate only after the reviewed release is deployed and both sustained
child exercises are ready. It binds the UniFi/Milesight common provider API
evidence and the separate native Queclink TCP-listener evidence to the same
exact deployed revision and value-free environment reference. It does not send
a provider request itself, replay a tracker frame, or create Device data.

Queclink remains native listener intake. The combined artifact records
`provider_api_contracts: [unifi, milesight]` and
`queclink_transport: native_tcp`; it does not create or claim a Queclink
provider API capability.

## Protected deployed-release authority

The protected deployment control plane must install exactly
`/etc/oblivion/security-devices-s10-release-authority.json`. There is no CLI or
environment override for this path. The file must be a root-owned regular file,
not a symlink, with no group or other write permission. Its path identity must
remain stable while read.

The duplicate-free exact JSON schema is:

```json
{
  "schema_version": 1,
  "evidence_class": "security_devices_s10_release_authority_v1",
  "authority_reference": "AUTHORITY-00000000000000000000000000000000",
  "release_revision": "<40 lowercase hex>",
  "environment_reference_sha256": "<64 lowercase hex>",
  "not_before": "YYYY-MM-DDTHH:MM:SSZ",
  "not_after": "YYYY-MM-DDTHH:MM:SSZ"
}
```

The validity window must be positive and no longer than 24 hours. The authority
reference and environment reference are opaque; do not derive them from a Site,
Device, hostname, endpoint, credential, tracker identity or customer value.
Provision a new record for each approved deployed release exercise.

The gate verifies the authority, exact Git top-level source checkout, clean
tracked/non-ignored worktree, and matching `HEAD` and `origin/main` before and
after each sustained child gate.
Every check must retain the same authority-file hash, revision, environment
reference and authority reference. A missing, expired, replaced or writable
authority, dirty checkout, missing Git metadata, changed revision or changed
identity fails closed. An artifact-only deployment without verifiable checkout
metadata cannot use a caller-supplied revision as a substitute.

Git inspection uses the fixed root-owned `/usr/bin/git`, disables ambient
filesystem-monitor and untracked-cache configuration, and runs both children
through fixed root-owned `/usr/bin/bash` in privileged, non-profile mode with
`--noprofile --norc -p`, a bounded system `PATH`, and the resolved root-owned
`/usr/bin/php8.4`. One shared child environment preserves the Laravel
application, database and secret variables needed by the read-only commands,
but removes every ambient `GIT_*` selector or configuration override plus Bash
startup/exported-function and PHP configuration-injection variables. A
caller-supplied Git index, `BASH_ENV`, exported command function, `PHPRC`, or
`PHP_INI_SCAN_DIR` therefore cannot replace the checkout identity, child
contract or PHP runtime. The Git check binds reviewed source; it does not
pretend that ignored/generated dependencies, built assets or runtime
configuration are Git source. Those remain separately governed by
deployment/runtime attestation and V10 release packaging.

## Execute

Create an approved private output directory outside the application checkout.
Run from the deployed release root:

```bash
php scripts/monitoring/verify-s10-release-evidence.php \
  --output-directory=/var/lib/oblivion-evidence/s10 \
  --protocol-samples=15 \
  --queclink-samples=5 \
  --interval-seconds=60 \
  --window-minutes=60 \
  --max-frame-age=900
```

The gate runs these existing read-only sustained contracts from that same
checkout:

1. `verify-protocol-policy-evidence.sh` for the complete protocol/policy matrix
   plus UniFi and Milesight mapped-Site execution;
2. `verify-queclink-native-listener-evidence.sh` for stable canonical tracker
   roster, active listener and authentic persisted-frame advancement.

Each child must exit successfully and emit one exact duplicate-free JSON object.
The combined gate verifies its requested sample, interval, evidence-window and
frame-age parameters; exact UTC chronology; minimum observation duration; and
the Queclink every-tracker fresh count. Release mode requires at least fifteen
protocol samples, at least five Queclink samples, exact one-minute sampling, the
exact preceding-hour provider window and a maximum fifteen-minute tracker-frame
age; caller options cannot relax those boundaries. It hashes but does not copy
either child payload into the combined artifact.

## Result and retention

Success creates one collision-safe
`security-devices-s10-release-evidence-<timestamp>-<random>.json` file using
exclusive creation, flush and filesystem sync. The value-free artifact contains
the exact release revision, environment and authority references, both child
SHA-256 values, bounded time/count summaries, the two provider labels, native
Queclink transport label and explicit release-provenance result. It contains no
Site, Device, tracker, target, endpoint, credential, provider response, frame,
payload or observation value.

Exclusive creation prevents accidental overwrite; it does not prove immutable
retention, and the artifact records `worm_receipt_verified: false`. Move the
completed file through the approved release-evidence process and retain the
storage receipt separately. Attach the combined artifact to the
same release record as supervised process timestamps, approved target/provider
audit references and final desktop evidence. This artifact closes only the S10
deployed common-contract gate; it does not independently close A07, V10 or the
overall goal.

On failure, do not edit timestamps, provider cursors, connection-test state,
tracker frames, the authority file or the checkout to make the gate pass. Repair
the deployed runtime or repeat the approved real exercise under a fresh bounded
authority window.
