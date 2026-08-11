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
remain stable while read. The verifier requires device, inode, mode, owner,
size and modification time to remain exact before opening, through the
completed read and at the fixed path afterward.

The duplicate-free exact JSON schema is:

```json
{
  "schema_version": 1,
  "evidence_class": "security_devices_s10_release_authority_v1",
  "authority_reference": "AUTHORITY-00000000000000000000000000000000",
  "release_revision": "<40 lowercase hex>",
  "environment_reference_sha256": "<64 lowercase hex>",
  "runtime_environment_sha256": "<SHA-256 of the protected runtime JSON>",
  "not_before": "YYYY-MM-DDTHH:MM:SSZ",
  "not_after": "YYYY-MM-DDTHH:MM:SSZ"
}
```

The validity window must be positive and no longer than 24 hours. The authority
reference and environment reference are opaque; do not derive them from a Site,
Device, hostname, endpoint, credential, tracker identity or customer value.
Provision a new record for each approved deployed release exercise.

The runtime hash must match the exact root-owned
`/etc/oblivion/security-devices-s10-release-runtime.json` file. This second
file is a duplicate-free JSON object containing only the environment variables
required by the deployed read-only Artisan gates. It must be a stable regular
non-symlink file owned by root with mode `0600`. It requires
`APP_ENV=production`, disabled debug, `DB_CONNECTION=mysql`,
`MONITORING_COLLECTOR_REPLAY_STORE=redis` and a non-empty application key. It
may contain the protected database/application values needed by the release,
but those values are never printed or copied to the artifact. Do not use a
workstation `.env`, caller shell variables or an evidence fixture database.
The orchestrator also sets `APP_CONFIG_CACHE` to the fixed verified-absent
`/run/oblivion-s10-release-no-config-cache.php` path before each child. If a
file or symlink exists there, the run fails. Laravel therefore cannot prefer a
caller-prepared ignored `bootstrap/cache/config.php` over the protected values.

The gate verifies the authority, exact Git top-level source checkout, clean
tracked/non-ignored worktree, and matching `HEAD` and `origin/main` before and
after each sustained child gate.
Every check must retain the same authority-file hash, revision, environment
reference and authority reference. A missing, expired, replaced or writable
authority, dirty checkout, missing Git metadata, changed revision or changed
identity fails closed. An artifact-only deployment without verifiable checkout
metadata cannot use a caller-supplied revision as a substitute.

The orchestrator bootstraps only its six exact tracked support sources and
never executes the ignored Composer autoloader before the checkout decision.
Git and child-process supervision use a bounded shell-free native process runner.
Git inspection uses the fixed root-owned `/usr/bin/git`, disables ambient
filesystem-monitor and untracked-cache configuration, and runs both children
through fixed root-owned `/usr/bin/bash` in privileged, non-profile mode with
`--noprofile --norc -p`, a bounded system `PATH`, and the resolved root-owned
`/usr/bin/php8.4`. Git receives only a minimal fixed environment. Each
sustained child receives only the exact authority-hashed root-private runtime
JSON plus the fixed system path/PHP binding; no caller environment is inherited.
Git selectors, Bash startup/exported functions, PHP configuration,
dynamic-loader, proxy and CA injection keys are forbidden in that protected
file. A caller-supplied database, Git index, `BASH_ENV`, exported command
function, `PHPRC`, proxy or loader therefore cannot replace the checkout, data
source, child contract or PHP runtime. The protected environment is re-read
immediately before each child, the fixed cache-bypass path is rechecked, and
the environment hash remains pinned across all four
authority snapshots. The Git check binds reviewed source; it does not
pretend that ignored/generated dependencies, built assets or runtime
configuration are Git source. Those remain separately governed by
deployment/runtime attestation and V10 release packaging.

Each sustained child is read through a stable non-symlink file handle, matched
byte-for-byte to its exact `HEAD` Git blob, and those captured bytes—not the
checkout path—are supplied to the protected Bash process through standard
input. A child script temporarily replaced between the clean pre/post Git
snapshots therefore cannot execute and then hide by restoring the tracked file.

## Execute

Create an approved private output directory outside the application checkout.
It must be owned by the application service account and have exact POSIX mode
`0700`; a merely writable shared or temporary directory is ineligible. Run from
the deployed release root:

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
`security-devices-s10-release-evidence-<timestamp>-<random>.json` file and its
matching `.sha256` sidecar. Both use exclusive creation, exact mode `0600`, the
service-account owner, complete-write checks, flush and filesystem sync; a
collision never removes or overwrites an existing file, and a sidecar failure
removes only the newly created artifact. The value-free artifact contains
the exact release revision, environment, protected-runtime hash and authority references, both child
SHA-256 values, bounded time/count summaries, the two provider labels, native
Queclink transport label and explicit release-provenance result. It contains no
Site, Device, tracker, target, endpoint, credential, provider response, frame,
payload or observation value.

Exclusive creation and the checksum pair prevent accidental overwrite and make
later change detectable; they do not prove immutable retention, and the
artifact records `worm_receipt_verified: false`. After the final authority and
checkout checks, the gate reopens both output files and requires their exact
bytes, private owner/mode and stable identities before emitting success. Independently validate the
sidecar, move both files through the approved release-evidence process and
retain the storage receipt separately. Attach the combined artifact to the
same release record as supervised process timestamps, approved target/provider
audit references and final desktop evidence. This artifact closes only the S10
deployed common-contract gate; it does not independently close A07, V10 or the
overall goal.

Immediately before reopening the outputs, the gate revalidates the protected
authority and clean checkout. The final authority must still be current and
byte-identical, and `HEAD` must still equal `origin/main` with no tracked or
untracked source change. It revalidates the protected authority and clean
checkout before reopening the outputs, making the exact-byte/private-identity
check the terminal gate before stdout success. A replacement, expiry, checkout
change or output replacement removes the newly published pair and fails closed.

On failure, do not edit timestamps, provider cursors, connection-test state,
tracker frames, the authority file or the checkout to make the gate pass. Repair
the deployed runtime or repeat the approved real exercise under a fresh bounded
authority window.
