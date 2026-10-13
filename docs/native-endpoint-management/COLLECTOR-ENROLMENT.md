# Recoverable site-collector enrolment

Development implementation, 8 October 2026. One operating organisation per deployment, with approved Sites, networks, devices and capabilities. This is an installation prerequisite for Windows/Linux site collectors, not a completed Windows collector host or combined installer.

## Problem and implemented behaviour

Previously the collector generated a fresh signing key for each enrolment attempt. The central service consumed the one-time token before the collector saved its returned identity. A lost reply could therefore leave a registered collector that its installation could not recover.

The new opt-in enrol-recoverable command saves a pending signing identity before contacting the server. A retry must use the same server origin, TLS public-key pin, Site, collector UUID and token. Central returns the same encrypted identity to that key while the original grant remains valid. It neither creates a second collector nor issues another certificate.

The legacy enrol endpoint and command remain available; a consumed legacy token cannot be upgraded into a recovery grant. Certificate parsing in both clients now requests short subject names explicitly, matching the existing CN comparison. This corrects rejection of valid certificates while retaining exact collector-name, private-key, fingerprint and validity checks.

## Protocol and authority

POST /api/monitoring/collectors/enrol/recoverable requires HTTPS, a bearer enrolment token and an exact bounded JSON object containing collector_id, collector_public_key and proof. Duplicate fields, unexpected fields, noncanonical Base64, oversized bodies and invalid key proofs are rejected. The route is rate-limited.

The Ed25519 proof covers a separate versioned domain, collector UUID, token hash and signing public key. The server transaction locks the original enrolment, rechecks the issuer's current internal-account approval, permissions and Site access, and requires an active Site. Recovery also requires the original non-revoked collector and canonical device/Site assignment. Certificate replacement or a changed central signing authority invalidates recovery.

The response identity is sealed to the requesting public key through the standard Sodium Ed25519-to-Curve25519 conversion. The database retains the sealed response and identity fingerprint, hidden from normal model serialization. It does not retain the plaintext private certificate key in those fields. Recovery cannot extend the original grant's expiry. Expired sealed-response pruning is not implemented yet.

## Local commit

The caller must first establish a private service-owned directory. The client checks ordinary bounded single-link files, uses an exclusive enrolment lock and enforces restrictive POSIX modes. Windows directory ownership, ACL and reparse protection must be established by the native installation layer; PHP mode checks do not provide that assurance.

The pending record contains the original signing secret and a hash of the enrolment token. The raw token is read through bounded input or the existing process environment mechanism, never a command-line value, and is not written to the pending or final identity.

The client decrypts and validates the response before writing final certificate material. Each private file is staged and flushed, then committed without replacing an existing final path. Existing partial certificate files must match the accepted bytes. The final identity is written after the certificate files and initial checkpoint. Pending-key cleanup follows that commit.

A retry after local completion validates the existing identity and matching pending record without calling central again. That only confirms local completion during the original grant's validity; it is not evidence that the collector remains authorised or reachable centrally.

These are bounded recovery cases, not a promise of recovery from every power-loss point. An interrupted hardlink commit can leave an extra staging link and will require reviewed cleanup. Unexpected files, conflicting identity material or progressed checkpoint state fail closed. Do not delete existing state to force a new enrolment.

## Operator interface

For an approved collector build against a deployment with the migration and route installed:

~~~text
oblivion-collector enrol-recoverable
  --identity=<private-state>/collector.identity.json
  --collector-id=<approved-uuid>
  --site-id=<approved-site-id>
  --central-url=<configured-https-origin>
  --tls-public-key-pin=<approved-sha256-pin>
  --state-directory=<private-state>
  --token-fd=<inherited-token-descriptor>
~~~

Arguments are shown across lines for readability; use the target shell's normal continuation syntax. The existing OBLIVION_COLLECTOR_ENROLMENT_TOKEN mechanism remains available. Keep secret values out of shell history, logs and installer arguments.

The saved identity remains compatible with the existing collector run, doctor and transport-verification commands. Runtime still requires central signed configuration, approved scope, certificate authentication, replay protection and real deployment verification. Successful enrolment alone must not be displayed as a working monitoring service.

## Evidence and remaining work

- Collector tests cover lost replies, stable keys/proofs, destination changes, invalid sealed responses, certificate binding, partial writes, existing-state preservation, concurrent attempts, completed local retries and CLI output.
- Laravel feature checks cover one-time consumption, exact encrypted replay, current permission/Site changes, revocation, expiry, changed certificate/signing authority, strict input and refusal to recover consumed legacy enrolment.
- The interoperability feature drives the actual collector client through the actual Laravel controller with a generated test certificate and a deliberately lost first reply. It is an in-process contract test, not real TLS/proxy or installed service acceptance.
- Windows Site/Both service activation and protected native enrolment now exist; the authenticated collector profile API exports deployment/Site/UUID-bound material. Native setup uses --confirm-current and --expected-server-key to recheck the original grant and authority before activation. See COMBINED-INSTALLATION.md. The guided Site-profile download/review UI and PHP Windows/Linux profile consumer now exist. Graphical Windows setup now exists; complete signed packages, repair/update/uninstall and signed installed acceptance remain unfinished. Existing Linux service tooling is not newly certified by these tests.
- No production identity, certificate, provider credential or customer package is created by the tests. The full Security & Devices release gate remains open.

Final verification: standalone collector suite 57 cases / 447 assertions; Laravel recovery feature suite 15 cases / 74 assertions, including the actual client/controller contract. Both passed on 8 October 2026 in isolated development tests.


## Expired recovery response cleanup

The bounded scheduled pruning implementation now clears expired sealed replies and their recovery fingerprints while preserving enrolment history and collector identity. Consumption alone does not qualify for cleanup because a reply can be lost after commit. See collector-recovery-retention.md for the exact command, limits and concurrent-expiry protection. The expanded feature suite passes 18 cases / 104 assertions in isolated development; no production cleanup was executed.
