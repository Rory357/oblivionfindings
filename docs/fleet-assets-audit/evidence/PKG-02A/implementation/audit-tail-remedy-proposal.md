# Audit append contention: proposed remedy for Main review

Status: proposal only, 21 September 2026. No application migration or audit-service change has been applied.

The actual canonical append diagnostic held an X,GAP lock in `device_command_audit_request_hash_unique` before request 2's first hash while appending to request 1. A computed lower hash for request 2 then failed INSERT with MySQL 1205. The subsequent EXPLAIN selected a PRIMARY backward scan; it is not asserted to be the earlier plan. Raw evidence: `.pkg02a-address-guard-diagnostic.log`.

A disposable-schema prototype added `(device_command_request_id,id)` and forced the first reader to that index. Independent append succeeded with an existing tail. With empty adjacent tails, its range/gap locks still blocked the second request's first append (1205). Raw evidence: `.pkg02a-address-guard-corrected.log`. The index alone is therefore not proposed as the remedy.

## Minimal proposed schema and query change

Add nullable unsigned bigint `audit_tail_event_id` to `device_command_requests`. It is internal audit metadata: hidden, not fillable, protected from ordinary model edits and explicitly rejected by generic request input. It is not added to the signed command payload, and no existing event/hash is rewritten.

Under a quiesced audit-writer rollout, backfill this pointer from each request's existing `MAX(device_command_audit_events.id)`, preserving the exact predecessor definition used by the current service. Leave null only when there is no event. No new index or cyclic foreign key is needed; tail reads use the existing unique event primary key. Migration tests must prove legacy hashes/previous_hash and command signatures are byte-for-byte unchanged.

Change `DeviceCommandAuditService::append` inside its existing transaction:

1. Current-lock the exact canonical request primary key (`FOR UPDATE`). Validate identity from that row.
2. If its pointer is non-null, current-read the exact audit primary key and verify its request ID. A missing/mismatched pointer fails closed. Never range-read an absent tail, skip a locked event, or use a stale snapshot predecessor.
3. Compute and insert the event using the unchanged canonical hash algorithm.
4. Update only the locked request's internal pointer with a narrow query-builder write, in the same transaction. Do not mutate lifecycle state, timestamps, signed fields, or the caller's immutable terminal model. Ordinary model mutation of the pointer remains forbidden.

The request row is the mutex for existing and first appends. Exact primary-key current reads do not take a neighbouring request's tail range. Pointer, event and any enclosing lifecycle writes roll back together. A caller's outer transaction retains the mutex through commit.

## Lock order and rollout requirements

Required order is request → existing context/attempt locks held by the caller → exact audit tail/event insert → pointer update. Audit append itself takes request first; all existing append call sites must be checked for an attempt→request inversion before accepting this design. Standalone evidence export and notification appends also acquire request first. An unresolved inverse order blocks implementation/freeze rather than being hidden by retries.

For future deployment: stop and drain all governed command/audit writers (HTTP intake/actions, workers, provider claims/results, evidence exports), apply/backfill the additive migration, deploy the corrected append service to every writer, then resume. Mixed old/new writers are not supported: an old append would leave a stale pointer. This is a documented deployment prerequisite, not permission to deploy, stop operational services, or run an operational backfill.

Rollback should retain the additive pointer and audit records. Under quiesced writers, rolling code back preserves hashes but restores the known contention bug; re-upgrade requires rebuilding and verifying pointers while writers remain stopped. Migration down should refuse while audit evidence exists rather than drop required metadata under running code. No destructive operational rollback is proposed.

## Required isolated proof before acceptance

Adjacent and non-adjacent requests with existing and empty tails; two simultaneous first appends; same-request chain serialization; snapshot established before another append; failure between event insert and pointer update; enclosing transaction rollback; unchanged generic signing/hash reconstruction; terminal-request evidence export; migration backfill and empty/retained rollback; actual independent-client queue overlap and relevant writer exclusion. Keep the original failed logs and distinguish the diagnostic assertions from functional acceptance tests.
