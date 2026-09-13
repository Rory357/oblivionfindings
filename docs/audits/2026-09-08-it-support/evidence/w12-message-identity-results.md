# W12 submitted email identity and W11 reply/loop resolution

11 September 2026. Partial W11/W12, F03/F05, B01/B02, E05/E10/E11. Full packages and release gate remain open.

## Implementation

Migration000022 adds encrypted RFC Message-ID, a unique normalized identity hash and its recording time to the canonical `it_email_deliveries` record. No historical value is inferred from provider transport IDs. New fields are hidden from serialization and immutable after binding; rollback refuses to remove retained identities.

The existing MessageSending listener now asks ItEmailDeliveryService to bind the submitted identity to one recorded recipient delivery under a row lock. It checks the stable notification UUID, current recipient access and sending/accepted/delivered state, reuses an existing binding, and requires an audit without raw IDs or message content. The same ID is applied to the actual Symfony email before transport invocation. Audit failure rolls back binding and prevents mail acceptance. Production dispatch uses the existing durable outbox; full real-process interrupted-send and outer-transaction acceptance remain W12 checks, not claimed by the transactional feature tests.

The canonical inbound reference resolver now considers outgoing RFC identities alongside inbound ancestry and subject references, then applies the existing per-record access checks and merge traversal. Mixed contradictory identities quarantine. Opaque provider IDs never serve as RFC ancestry. The ingestor also quarantines a message whose own Message-ID matches an outgoing identity even when Auto-Submitted was stripped; a legitimate human reply with a new Message-ID and that parent remains eligible.

Changed production files: `database/migrations/2026_09_11_000022_record_it_outbound_message_identity.php`, `app/Models/ItEmailDelivery.php`, `app/Domain/It/Services/ItEmailDeliveryService.php`, `app/Listeners/It/RecordItEmailDelivery.php`, `app/Domain/It/ItTicketReferenceResolver.php`, `app/Domain/It/InboundEmailIngestor.php`. Tests: new `ItOutboundMessageIdentityTest.php`, adapted `ItOutboundLoopTransportTest.php`. Existing outbox/merge regression files also run.

## Backend verification

Run46954/tokenit_990d464505f94e9c exited0: **61tests/721assertions/199seconds**. `w12-message-identity-tests.txt` and token diagnostic JSONL. All14postflight checks passed and exact disposable schema absent. Pint passed. Coverage includes actual generated receipt identity, encrypted/hidden/immutable storage, provider-ID separation, stable re-preparation, guarded rollback, stripped-marker loop refusal, both-provider changed-subject/merged/denied/conflicting/opaque-ID replies, first ACK503 recovery with no duplicate work, audit failure, existing transport and outbox behavior. No new provider credentials or real mail was used.

## Browser checkpoint

Existing fingerprinted fixtures now support an `outbound_reply` phase in `w11-merge-browser-scenario.php`. The guarded helper performs read-only database checks and requires a unique accepted requester receipt from a real UI-created ticket titled `W12 TOKEN outbound reply verification`. It stages only synthetic inbox49–52: valid changed-subject reply, returned outgoing message with stripped marker, unauthorized sender and duplicate valid reply. Both providers inject first ACK49 failure. The fixture obtains the actual encrypted canonical RFC identity inside the owned application runtime; the staging report exposes only metadata/hash, not the plaintext ID. No ticket is created by this helper.

Helper syntax passed. Fingerprint `31754eb5fb9ddbce55474c2b54ce45dc612e4f64b988e3e0c4fd92fced4063f5`, current manifest `f2834ff231cc6ce5521e7838ac2dfd1c62a089bb29203bf4bd1e27fd1b2ce0dc`. Bootstrap48799 exited0 for token `377860ef3cd84316`, PHP31176. Live endpoint confirmed exact checkout/schema, array mail, sync queue, CSRF bypass false, fixtures true and current assets (`w12-message-identity-browser-runtime.json`). Owned in-app tab30; desktop never resized.

Requester `w06-requester@demo.test` used Get help, entered the required synthetic title, chose approved Site A and submitted the real form. The UI confirmed IT-000008. The guarded stage then proved one accepted local receipt with its recorded RFC identity, before making either inbox available. Evidence: `w12-message-identity-browser-staged.json`. No helper-created ticket or fabricated outgoing identity.

The settings operator polled Microsoft and Google through the real mailbox controls. Both showed Needs attention, one awaiting acknowledgement and two quarantines; polling was disabled until the actual cooldown elapsed. `w12-message-identity-browser-before-retry.json` proves eight tickets, two public email comments/commands/audits, two duplicates, two automatic-message quarantines and two unauthorized-sender quarantines. No extra ticket was created.

Browser review found generic quarantine guidance for the returned automated notification. Updated only the existing `ItInboundQuarantineReview` presenter copy: automated mail is explicitly excluded to prevent reply loops, with no retry control. Delivery reports also have specific explanatory copy. Reload latest records verified the automated-mail wording in the running browser; no payload/audience change. Pint --test passed (`w12-message-identity-copy-format.txt`). Delivery-report wording was not separately exercised in this browser scenario.

Reload current state and Poll mailbox now recovered each acknowledgement after cooldown. Both displayed Last poll completed and zero awaiting acknowledgement. Final persisted evidence `w12-message-identity-browser-final.json`: all8receipts acknowledged, exactly2public replies on ticket8 and no additional work; each provider read49–52once, ACK49twice and each other ACKonce. Quarantined records remain unlinked; duplicate records point to8. The original outgoing identity hash remains unchanged.

The requester reopened `/it/tickets/8` and saw exactly the Microsoft/Google replies and permitted requester controls. Actual inline screenshot and accessibility tree inspected; no saved screenshot file claimed. User-menu End focused Log out and Enter activated it. Unrelated requester `w06-other@demo.test` received rendered404 for ticket8, then returned to `/it` and saw only their permitted IT-000003. These are bounded role/keyboard/browser checks, not the whole module release gate.

Owned tab30 closed; user tab3 preserved. Cleanup82195 exited0; independent `w12-message-identity-browser-postflight.json` proves exact schema/root absence. Eleven source hashes recorded in `w12-message-identity-final-source-hashes.json`; the ten protected design hashes remained unchanged. No build, test, server, browser, import or cleanup remains active.

## Boundaries

Working database migrations17–22 remain unapplied. A deployment must apply the identity schema before enabling this code's outgoing/incoming mail processing; missing schema is not a supported fallback to fabricated ancestry. W12 provider selection/support identity, both content modes, delivery reconciliation and approved live acceptance remain open. Submitted RFC identity is proven locally; whether a configured provider preserves it requires approved provider acceptance. DP03 and DP04 answers remain pending; DP07 external operational dependencies remain unchanged. Production AI remains disabled, protected design files remain read-only, and desktop browser must never be resized.
