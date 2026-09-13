# IT attachment storage recovery

This runbook covers explicit rollback-cleanup intentions, temporary copies of accepted email attachments and provably abandoned mailbox copies on the canonical private attachment disk. It does not authorize deletion of unknown files, choose retention, activate a scanner, or perform a production rollout. Use the organisation's approved operator access and environment-change process. Local implementation and isolated-test evidence are recorded under `docs/audits/2026-09-08-it-support/evidence/`; a production worker and restore rehearsal require their own evidence.

## Before a retry

Confirm the checkout/release and target environment, the private disk, migration 000010 and `it_automation_runs` readiness. Accepted email-copy cleanup additionally requires migration 000019; without its staging column, the command retains its direct rollback-only behavior. Interrupted mailbox-copy recovery requires migration 000021. Until that upgrade, new mailbox file reservations fail before copying, and existing unclassified reservations remain protected. Do not copy credentials, original file names, paths, bodies or provider responses into an operational report. Use the automation key, run ID and controlled intent or receipt identifier for correlation.

`Setup → Operations → Automation health` shows current scheduler freshness separately from storage-record readiness. The attachment section distinguishes explicit pending cleanup, unclassified reservations and records needing reconciliation. Global cleanup counts and batch details require both existing `it.manage` and `audit.viewAny`; no additional grant is made. Missing or withheld evidence is not zero. Ordinary Setup access can see nonprivate readiness/freshness, without a file or ticket drilldown.

## Retry confirmed cleanup

An authorized operator can run one bounded pass in the approved environment:

```console
php artisan it:retry-attachment-cleanup --limit=100
```

The accepted range is 1–1000. The command first records its run, then selects one shared batch across direct `cleanup_pending` intentions, inbound `cleanup_pending` copies whose receipt is processed or duplicate, and mailbox reservations with recorded writer bindings whose original capability is no longer active. Never-attempted files precede older retries; each selected file consumes one slot, including failed/deferred classification. It never scans the disk or promotes a reservation merely because it is old. Each item and its canonical owner are locked and rechecked before storage operations.

Migration000021 records immutable inbound receipt/source and mailbox claim/version bindings on newly reserved canonical email copies. Older reservations without this evidence stay unclassified. Recovery takes the mailbox writer lock before the original receipt/source and intention locks. A matching live claim is deferred. An expired matching claim is explicitly revoked under that lock before cleanup is authorized, so a surviving process cannot resume with its old token. A committed canonical attachment, changed/missing source binding or conflicting path prevents deletion. A required audit and `cleanup_pending` transition commit before the existing deletion path runs. Audit failure rolls back classification and preserves retry state. This is writer recovery, not a retention policy or permission to remove quarantined source files.

For accepted email attachments, only the receipt-owned temporary copy is removed. The canonical ticket/comment attachment and its scan/source evidence remain. Cleanup can continue after mailbox polling stops or its connection is removed. Quarantined content is retained. A cleanup flag on a missing or unaccepted receipt contributes to `reconciliation_required`, rather than authorizing deletion. This is mechanical recovery, not a quarantine retention policy.

- Exit 0 means the bounded batch finished. Additional pending records may remain; inspect the returned `remaining` counts. This is not proof that all storage is healthy, backed up or retention-compliant.
- Exit 1 means failed/deferred cleanup, reconciliation, unavailable readiness/evidence or a failed terminal run record. Already confirmed deletions stay recorded separately from failures. Retryable intentions survive a false/throwing deletion. If storage deletion succeeded but audit/DB completion failed, a later confirmed-absence check can finish the same intention once. A failed inbound completion audit is counted as a failed item; other selected items continue, and retry timing is retained separately where current accepted ownership and database availability allow it.
- Exit 2 means the limit was invalid and no run/cleanup began.

Preserve the JSON counts and fixed error code. Null counts mean the result was unavailable; do not replace them with zero. If run recording fails, no cleanup begins. If terminal recording fails after cleanup, retain observed counts and treat the automation outcome as unconfirmed until the same canonical records can be read.

## Scheduler and stopped worker

The canonical catalogue schedules `it.retry-attachment-cleanup` every five minutes, batch 100, Auckland timezone, on one server, with a 10-minute overlap mutex. The original caller already attempts cleanup after proven rollback, and mailbox polling attempts accepted-copy cleanup before acknowledgement. The shared scheduled retry recovers remaining copies independently of those callers. It is not a retention policy.

The command records its own outcome; scheduler start/finish listeners exclude this key to prevent a second successful run hiding partial failure. Skipped scheduler attempts remain explicit. Freshness uses the existing 300-second grace, last successful completion and current failure/running state. An earlier successful row does not keep a stopped worker green indefinitely.

A mutex expiring after 10 minutes does **not** prove its previous worker stopped. Correctness still depends on immutable intent identity, conservative states and per-intent database locks. Inspect the affected worker and lock owner through approved tooling before operational intervention. Do not clear unrelated scheduler/cache state or kill an arbitrary database worker. A hard-killed attempt may remain running until freshness becomes stale; record that evidence separately from a successful retry. Restore the existing scheduler/worker facility, run one bounded pass, then verify advancing run timestamps and exactly-once completion evidence.

## Unknown or conflicting evidence

`reserved` can mean an active writer, an uncertain commit, or interrupted preparation. `reconciliation_required` means automatic deletion is not justified. A missing ticket/comment/receipt, a timeout, a stale clock or file age is never rollback proof. Do not change these rows directly to pending or delete their paths.

Canonical reconciliation must use a fresh primary read and the original immutable bindings. A committed attachment keeps its bytes. Only the recorded mailbox-writer path described above can classify an interrupted reservation automatically; missing or unclassified legacy ownership remains protected. A still-open/nested transaction may defer cleanup to avoid self-deadlock. Removed historical actors do not block mechanical cleanup audit: system attribution retains their ID only as provenance. A new operator action that authorizes unknown-file removal is outside this runbook and remains an explicit implementation/operational decision.

## Migration, rollback and restore evidence

Apply only the reviewed additive migration through the approved deployment path. Submissions without files remain usable when 000010 is absent; file submissions fail with an actionable validation message before writing bytes. Never fall back to untracked storage. Once populated, the intention table refuses rollback; preserve evidence and use a reviewed forward repair rather than dropping it.

For W26 acceptance, restore one consistent isolated backup generation containing canonical tickets/comments, attachments, receipts, storage intentions, audit/run evidence and the corresponding private objects. Reconcile identifiers, links and expected object counts; prove restricted users still cannot download a copied protected URL. Retry an explicitly pending fixture twice and confirm one completion audit, no recreated object and no duplicate canonical record. Preserve unknown fixtures unchanged. A restored table or a green UI badge alone does not prove the matching private objects or key custody were recovered.

Record the actual restored environment, backup generation, observed RPO/RTO, exact affected fixture IDs and safe outcomes. Retention duration, backup ownership, encryption/key custody, scanner/quarantine and real provider acceptance remain governed operational dependencies; none is inferred from this cleanup command.
