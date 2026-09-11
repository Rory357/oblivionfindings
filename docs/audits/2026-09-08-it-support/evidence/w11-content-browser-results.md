# W11 complete message content — actual desktop browser verification

11 September 2026. Bounded W11/E10 provider parsing and recovery slice; full W11 remains incomplete. Isolated runtime token `8e615cc094d948ef`, fingerprint `5ca6b0a7ec2ac1400fc37ef379a85d72c207e38692bed99253be52f72e0e19d9`, MailboxFixtures enabled. Bootstrap8557 terminal0; exact owned PHP31576. No real provider traffic or communications. Normal settings/requester login through UI; owned in-app tab22 only.

## Observed journeys

- Microsoft Poll mailbox now activated with Enter. Thirty-eight discovered messages settled; UI showed Needs attention, processing0, acknowledgement1 and quarantined8 after the synthetic503 acknowledgement failure. Poll was disabled during the actual retry delay. The status explicitly distinguished a requested poll from proof of processing.
- Google tab selected and poll activated with Enter. UI showed Last poll completed, processing0, acknowledgement0 and quarantined8; global quarantine16 and remaining Microsoft acknowledgement1.
- After the real cooldown, Reload current state enabled Microsoft polling. Enter retried it; Last poll completed, processing0, acknowledgement0 and quarantine8. No clock bypass.
- Normal settings logout and restricted requester login. Service desk showed the permitted imported request; its observed link `W11 synthetic google request 37 · IT-000063` opened with Enter. Original report displayed both lines: `Complete external report.` and `Verified full content.` DOM confirmed the exact newline, no short HTML alternative and no provider preview. Public reply composer was present; internal work actions absent. This slice does not repeat the previous runtime's direct403/404 checks.
- Read-only DOM: URL `http://127.0.0.1:8766/it/tickets/63`, viewport1235x856, document width1220, no horizontal page overflow. Screenshot visually inspected in the tool; no separate disk screenshot claimed. Browser was never resized. Build41 assets `app-kNtA0mJt.js` and `app-CE9g3ZyJ.css` matched the manifest. No frontend edits/rebuild in this slice; no console-clean claim.

## Persisted reconciliation

`w11-content-browser-reconciliation.json` comes from the guarded read-only owned-schema helper after recovery. It records56 synthetic tickets,56 committed canonical creation commands, two committed canonical comments and matching56creation/two comment audit entries. Each provider has29processed records (28new tickets plus one reply), one duplicate and eight quarantines; every record acknowledged. Quarantines include identity collision, inactive sender, duplicate headers, ambiguous sender, two automatic messages, a delivery report and missing content, with zero retained body previews.

Every transport message was read exactly once (38 per provider); Gmail external full-body endpoint read once. Microsoft acknowledgement retry does not reprocess its accepted message. `w11-content-checkpoint.cjs` asserts these counts and all ten protected design hashes; terminal0. Backend final suite173tests/1007assertions191s and isolation proof are in `w11-content-results.md`.

## Lifecycle / remaining work

Owned tab22 closed; user tabs untouched. Exact-token cleanup4414 terminal0. Independent guarded PHP postflight terminal0 confirmed exact schema and owned directory absent, with no database mutations. Cleanup log confirms no other databases affected and no Herd environment changes. No active test/build/browser runtime/import/cleanup remains. Working database and migrations17/18 untouched.

Private email file ingestion, bounded HTTP downloads/staging, outer-inbox transaction attachment cleanup, quarantine review/retry/retention, related-request recovery, legacy backfill and final merged/settled/concurrency browser criteria remain unfinished. Automatic-message classification is verified here; complete outbound loop/header handling is not yet complete. Real provider readiness remains external.
