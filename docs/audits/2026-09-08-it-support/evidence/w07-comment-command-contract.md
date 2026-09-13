# W07 comment command transport and conversation projection

Source contract, 2026-09-09. The bounded PHP command/projection and real concurrency checks below are verified. Root owns the browser composer/desktop verification. Production draft persistence remains disabled pending its separate retention decision. No provider operation is authorized by this contract.

## Exact browser intent

`POST /it/tickets/{ticket}/comments` accepts original `actor_user_id`, `request_uuid`, `expected_version`, `body`, `is_internal`, optional canonical `attachments[]`, and optional complete `draft_uuid`/`draft_revision`/`draft_actor_user_id`. Freeze this exact intent before any asynchronous work. A retry keeps all text, audience, IDs, versions, filenames and file bytes unchanged.

Same actor/operation/UUID plus the same keyed fingerprint returns the original commit. Changed intent conflicts. Public/internal audience shares one receipt namespace, so switching audience cannot create a second reply with the same UUID. Current ticket visibility, approval and internal work capability are rechecked before any receipt outcome. A stale version without an existing receipt cannot write. Settled or merged tickets reject a new reply.

Successful first commit returns201; exact replay returns200:

```json
{"status":"committed","data":{"id":24,"canonical_ticket_id":24,"comment_id":31,"viewer_user_id":230,"request_uuid":"<original UUID>","is_internal":false,"lock_version":6,"replayed":false,"delivery":{"requested":true,"attempt_statuses":{"queued":1}}}}
```

The committed version is strictly greater than the original expected version, not necessarily exactly+1: canonical first response and waiting transitions can also advance the aggregate. Recovery returns the originally committed version even after later changes. Optional `data.draft` is exactly `{draft_uuid,submitted_revision,revision:submitted_revision+1,state:"consumed"}`. Only this matching proof can clear that submitted generation. No receipt or ACK contains body, filenames, recipients, secrets or draft text.

`delivery.attempt_statuses` is always an object, including `{}` when no delivery was requested. Values count current leaf attempts from the canonical outbox, with keys from `queued,sending,accepted,delivered,failed,bounced,retried`. An internal note has no delivery intent. A scheduling failure preserves the committed reply and queued outbox. Accepted does not mean delivered.

## Recovery and explicit cancellation

`GET /it/tickets/{ticket}/comment-commands/{uuid}?actor_user_id={original}` returns the same committed identity after current authorization, or the cancelled identity below. An unconfirmed404 is not proof that no request is still in flight.

`POST /it/tickets/{ticket}/comment-commands/{uuid}/cancel` takes original `actor_user_id` and required `is_internal`. It locks Ticket→current User→receipt, matching the writer. If a reply won, it returns its committed envelope. Otherwise it writes a permanent cancelled tombstone in the same receipt namespace:

```json
{"status":"cancelled","data":{"id":24,"viewer_user_id":230,"request_uuid":"<original UUID>","is_internal":false,"cancelled_at":"2026-09-09T19:00:00+12:00","replayed":false}}
```

Cancellation, repeated cancellation, cancellation recovery and a delayed matching reply POST all return200 with this exact cancelled identity. Repeated outcomes set `replayed:true`. A delayed original request cannot write after the tombstone. Another audience conflicts; another actor has a separate namespace and cannot cancel the original actor's command. Only exact committed/cancelled proof may clear the browser's held UUID. Cancelling this command does not consume/discard a draft, remove its saved files, cancel an already committed comment or authorize a new ticket version. There is no automatic timeout cancellation.

After a governed merge, committed `data.id` and receipt hash/version stay bound to the original ticket. `canonical_ticket_id` identifies the current comment parent only after verifying the actual merge chain and both original/current survivor audiences, including internal work access. A caller may derive `/it/tickets/{canonical_ticket_id}` locally. No survivor access means no receipt data. This is narrow receipt recovery; full merge conversation, ownership, SLA and delivery behavior remains W09.

## Current public read projection

Hub and show/drawer expose `conversation_ready:boolean`, determined by the additive schema. Show ticket, technician list rows and requester rows expose:

```text
conversation = {
  last_public: null | {comment_id:number, at:string|null, speaker_side:'it'|'requester'|'observer'|null},
  next_response_party: 'it'|'requester'|null,
  state: 'awaiting_it'|'awaiting_requester'|'unknown'|'settled'
}
```

Visible `comments[]` additionally have nullable `speaker_side` and `source_channel`. Historical nulls remain unknown; current permissions are not used to invent old roles. Comment order is `(created_at,id)`. Existing requester/internal filtering remains server-side. `author.is_requester` is a legacy current-identity compatibility field; captured `speaker_side` is the provenance for new conversation behavior.

`summary.tickets` has `conversation_ready`, `awaiting_it:number|null` and the identical `views.awaiting_it`. `overview` has `conversation_ready` and `awaiting_it_lane[]`; lane `created_age` means ticket age, not inferred time waiting for a response. The new `awaiting_it` view, summary and lane use the same `ItTicket::awaitingIt` scope over all open states, excluding merged/settled/unknown responsibility and retaining the current approved-Site/participant scope. Vendor waiting is a separate fact and does not erase public conversation responsibility. The existing `awaiting_reply` URL/saved filter remains **Awaiting first reply**. Historical first-response evidence is not changed.

Canonical new intake starts with IT responsibility when the schema exists. Both typed and legacy canonical browser comments capture current speaker evidence once the schema exists; older Herd schema retains legacy behavior without SQL against missing columns. Missing conversation schema produces nullable counts, false readiness, no new lane and an explicit503 for the new view rather than a fabricated zero.

## Verification state and remaining gates

- First run:39 Passed and3 fixture dataset Errors in PHPUnit events, no PHP fatal; exact schema `it_a66884bc91054d99` independently absent. Dataset nesting fixed.
- Second run:42 Passed and1 observer-fixture QueryException in PHPUnit events, no PHP fatal; exact schema `it_76e2340b15fe4eb1` independently absent. Fixture now uses canonical permission `key` column. Console summaries were swallowed by an output buffer; neither exit2 run is claimed successful.
- Third run (`w07-conversation-cancellation-projection-tests.txt`):50 Passed and1 failed pre-migration compatibility test, normal PHPUnit finish/no PHP fatal; exact schema `it_9781854c55014e96` independently absent. The current command/cancellation/merged-receipt cases passed, but this group is not green.
- Fourth run (`w07-conversation-command-feature-verified-tests.txt`, filename does not imply success):50 Passed and1 failed pre-migration compatibility test,1117 assertions, normal PHPUnit finish; exact schema `it_7a3281a752444e11` independently absent. The mock fallback was corrected, then the remaining fixture issue was traced to facade partialMock constructing an uninitialized schema builder. The test now proxies the actual initialized builder and explicitly asserts its fixture prerequisites. A targeted rerun is in progress; no compatibility pass is claimed yet.
- Root closed the previous isolated browser and released the migration freeze. Migration000008 now refuses rollback for cancellation-only comment receipts as well as committed evidence. Its final SHA256 is `654cc8491054d4b334f82d2d2f38e6e4aae98a85c593f6d24fc976e6386c718b`; syntax check passed. The cancellation-only rollback regression is in the targeted run. No working Herd migration was applied.
- Targeted follow-up (`w07-compatibility-rollback-focused-tests.txt`):2 passed/27 assertions, Pest and wrapper exit0; exact schema `it_aa29341849d8436a` independently absent. This proves the corrected pre-migration compatibility fixture and cancellation-only rollback protection. Together with the unchanged50 passing cases above, all52 distinct focused cases have passed; the earlier failed grouped runs remain recorded as failures.
- Actual standalone multi-worker/postcommit verification (`w07-comment-command-concurrency-tests.txt`):1 passed/250 assertions, Pest and wrapper exit0; exact schema `it_f896dffa6c264ec7` independently absent. Real workers prove one reply/receipt/first response/outbox/draft consumption for the same command; reply versus resolve yields one commit/one stale result; reply versus explicit cancellation yields one consistent terminal outcome with either consumed or untouched draft. Real outer postcommit exceptions preserve committed files; the typed command proves its existing receipt on a fresh connection. Existing creation/version/saved-filter/draft/file races in the same standalone test also passed. Diagnostic process elapsed193 seconds includes setup and is not a fabricated PHPUnit suite duration.
- Shared runtime sources are frozen for root's next browser environment; seven new `ItTicketWatcherTest.php` cases are preparation only until the coordinated integration checkpoint.
- Full W07 watcher add/remove, scanner/outage/quarantine policy and browser acceptance remain open. W09 merge semantics and W11–W12 provider adapters remain their governed dependency packages. Do not mark W07 complete from this transport slice.
