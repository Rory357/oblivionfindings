# Support mailbox quarantine recovery

Scope: the canonical Microsoft/Gmail inbound ledger for this organisation. Production provider/scanner access and retention policy still require DP07 operational ownership. This runbook grants no live configuration or communication authority.

## Review and request a retry

1. Open **Settings → Support mailbox** with the existing `integrations.manage_secrets` permission. Select the connected provider and load **Quarantine review**. The list exposes bounded decision metadata, not sender details, message content, ticket references, provider IDs or files. A different mailbox’s records are unavailable through copied record URLs.
2. Correct the sender’s approval, active employment, approved site or ticket responsibility through the existing authorised administration workflow. A retry does not grant any of those permissions. Use an authorised mailbox review to investigate message content; this screen does not grant mailbox-content access.
3. **Request retry**, review the confirmation, and confirm. Cancel before confirmation makes no change. The server records one versioned request and required audit event before returning “requested”. An active mailbox operation, provider cooldown, missing read acknowledgement or stale record prevents the request.
4. Run the existing mailbox poll when available, or let its existing scheduled worker run. A retry reads the same provider message even when it is already marked read. Its full identity and content fingerprint must match the original receipt. The canonical ingestor rechecks the current sender, site, ticket and file permissions. It can remain quarantined; no ticket is promised by a retry request.
5. Reload quarantine records to see the persisted outcome. Previously retried records remain in the bounded list, including completed requests. An interrupted request has an unknown outcome until reloaded. **Stop waiting** only cancels the browser wait; it cannot undo a request already committed or a poll already running. Do not reset receipt status, identity claims or acknowledgement fields by hand.

## Decisions which cannot be manually overridden

Only sender/account/site/responsibility failures with proven canonical identity and safe file preparation can be requested again. Changed identity/content, ambiguous references, automatic mail, malformed headers/content, infected/rejected files, unproven legacy records and other security decisions do not offer automatic release. Keep them for authorised reconciliation. A sender correction is not a reason to label an infected file clean.

Lost access conceals loaded metadata. A changed/disconnected mailbox requires reloading its current saved connection. Retry requests and outcomes use required audit events `settings.it_mailbox.quarantine_retry_requested` and `it.inbound_email.quarantine_retry_completed`. Audit failure rolls back the associated receipt change and canonical ticket command; the original retry remains recoverable. Transport failures continue through the existing poll delay and acknowledgement recovery.

## Storage and rollout limits

Migration `2026_09_11_000020_add_inbound_quarantine_review.php` adds version and pending-retry metadata to the existing ledger. It is not applied to the working database by this implementation task. The review endpoint reports unavailable before that upgrade. Rollback refuses to remove recorded review evidence. No duplicate quarantine or ticket store is introduced.

Quarantine files and records are retained. No purge interval, scanner executable, exception policy or production deletion has been selected. DP07 must supply approved retention, scanner ownership and restoration/key-custody procedures before operational release. Accepted-message temporary copy cleanup remains the separate existing bounded recovery command documented in `it-attachment-storage-recovery.md`; it must not purge quarantine evidence.
