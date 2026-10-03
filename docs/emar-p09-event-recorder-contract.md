# P09 medication event recorder contract

One organisation across approved Sites. The ledger has one chain per Site; no organisation partition or transport scope is introduced.

## Producer integration (Main owns serial integration)

Call `app(MedicationEventRecorder::class)->append(new MedicationEventData(...))` as the **last database operation** inside the domain's `DB::transaction($command, 5)`. The caller first locks permission/ownership evidence, domain rows and idempotency receipts, performs all domain changes, then appends. A failed append must propagate out of the transaction and through the existing save-error response; never catch it to allow a clinical write to commit. Offline replay uses its existing retry contract. Do not append after commit or through a model observer.

Required: canonical `siteId`, event `kind`, `subjectType`, `subjectId`, actor ID (nullable for an explicitly automated event), occurred-at instant, concise summary, structured facts and optional canonical person ID. Controlled medicine facts must set `controlled=true`. Facts must not contain PINs, IP addresses, device fingerprints or unrelated private data. Corrections append an event with `correctsEventId`; stored events are never updated or deleted.

For one command affecting several Sites, use `appendMany` once, after all other work. It acquires the Site head locks in numeric Site order before appending, preserving input order inside each Site. Never append once, take another domain lock, and append again. Retry the **whole outer command** on deadlock, with all reads and authorization repeated. P09 does not retry only the recorder or commit a nested transaction independently.

The first append initializes the head with an atomic insert-if-absent, then reads it with `FOR UPDATE`. Sequence and previous fingerprint are derived while holding that lock. A unique `(site_id, sequence)` constraint prevents duplicate chain places. SHA-256 covers canonical JSON (sorted object keys, preserved list order), UTC microsecond timestamps, all facts, and the previous fingerprint. Per-person canonical Site membership is checked before taking head locks.

## Package seams

- P03: assessment, agreement, support-category revision, withdrawal/reassessment.
- P04: order version/check/rejection/cessation, phone confirmation, reconciliation, covert authorisation.
- P05: review/outcome/date and recommendation decisions.
- P06: stock receipt/movement/count, pharmacy lifecycle.
- P07a/b: witnessed count/register entry/void/correction, discrepancy, loss/destruction, override decisions.
- P08a: follow-up/effect/refusal, reassignment/carry-over, handover sign-off.
- P08b: error account/lifecycle/action/disclosure and confirmed SAC close.
- P10: emergency grant/extension/expiry/review and downtime reconciliation.
- P11: saved/kept settings and on-call changes; organisation-wide changes append to every affected approved Site with `appendMany`.
- P01/P02: dose, re-offer/correction and identifiable export. Keep the profile day-grid PDF/CSV endpoints and purpose-dialog seam.

P09 owns the recorder, chain reader/verifier, reports hub, builder domain, export purposes, CSV guard, records/reporting settings definitions and governance mapping. Main wires the above producers serially. Existing historical audit records remain accessible but must be labelled as historical backing records, never represented as chain-verified events.

## Verification

Synthetic tests must prove append order, independent Site heads, tamper detection, canonical person ownership, transaction-required failure, full rollback on insert failure, several concurrent writers at one Site, and whole-command deadlock retry. Heavy verification runs only in Main's assigned slot under the shared heavy lock. No live medication data or retention cleanup is part of this implementation.
