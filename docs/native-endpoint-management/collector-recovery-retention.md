# Collector enrolment recovery retention

This deployment serves one organisation across approved Sites. Recovery continues to enforce the original issuer, Site, collector, certificate and signing authority checks.

A consumed enrolment retains its sealed identity response until `expires_at`: consumption can commit before a client receives the reply, so consumption alone must never erase recovery material. At expiry (`expires_at <=` the run's fixed UTC cutoff), `monitoring:prune-enrolment-recovery` clears only `recovery_response` and `recovery_identity_fingerprint`. Enrolment history, token hashes, original collector identity, keys, certificates and revocation records remain intact. Revocation and changed authority continue to deny recovery through the existing request checks.

The scheduler runs every five minutes without overlapping. Default runs process at most ten batches of 100 rows; operators can use `--batch-size=1..1000` and `--max-batches=1..100`. Further expired rows wait for the next run. Eligibility is rechecked in the atomic update, which acquires enrolment row locks also used by issuance and recovery. A concurrent expiry extension is respected. No user-controlled request invokes pruning and no Site permission or identity decision is relaxed.

Pruning removes the application database's live response material; backups and database storage reclamation retain their existing operational retention policies.
