# W11 canonical inbound browser verification — 11 September 2026

Partial W11/F03/B01/B02/E05/E10 acceptance only. Whole W11 and the goal remain incomplete.

## Environment and actual journeys

Owned runtime token `7b119260c4a34368`, schema `oblivion_it_draft_browser_7b119260c4a34368`, bootstrap96470 terminal0, PHP23520. Reviewed fingerprint `e00c1ace7cb06fa2316df3ca4cd297cd50076cecbb321fe922ec5270ed5b8e97`; guarded MailboxFixtures enabled. Current checkout, Build41 app `app-kNtA0mJt.js`, manifest `825aa72ccee696cfd66aead2b85c8ffaa722e8d801081fc70a6c848edb87a4d1`. Actual in-app viewport1235×856, never resized. Screenshots rendered in the task show the actual Microsoft retry state and requester ticket8 desktop layout. No disk screenshot path is claimed.

Normal settings-operator sign-in, Settings → Support mailbox: Microsoft Poll processed31 synthetic messages and reported one pending acknowledgement, two quarantines and Needs attention after the injected503. The retry respected the displayed cooldown. Google Poll completed with zero pending processing/acknowledgements and two quarantines. Microsoft Reload current state and Poll were activated using Enter after the real cooldown; the outstanding acknowledgement completed without rereading/reprocessing the message. Global quarantine count4 remained visible.

Normal logout/sign-in as the requester, Service desk → Microsoft IT-000008 using Enter: original report and exactly one public incoming reply displayed despite changed subject and duplicate transport delivery. No internal-note choice was exposed. Classification & ownership opened a read-only classification, site and assignee view. Service desk → Google IT-000035 showed the same single-reply behavior on its original ticket. The requester list showed Awaiting IT and the actual requester last-public-reply time for each. Both tickets displayed the existing approved-site and measured-clock projections; this is not operational SLA-policy approval.

Requester direct navigation to `/settings/it-mailbox` returned403 Forbidden, and another requester's unapproved-site `/it/tickets/3` returned404 Not Found, with no record content. Only owned tabs18/19/20 were closed; pre-existing user tabs1–3 were untouched. This run did not collect a console-log result, so console-clean acceptance is not asserted.

## Persisted reconciliation

Read-only `w11-canonical-browser-reconciliation-verified.json` proves54 new tickets,54 committed email ticket.create commands, two committed email ticket.comment commands,54 `it.ticket.created` audit entries and two `it.ticket.comment.added` entries. Each provider has28 processed messages across27 distinct tickets, one duplicate and two quarantines; all31 are acknowledged. Microsoft required32 acknowledgement attempts because its first response was lost; Google required31. Every provider message was read exactly once.

Each provider quarantined one message_id_collision and one sender_inactive; retained body previews are empty. Tickets8/35 each have one email comment, source=email, version4 and next_response_party=it. The first reconciliation file's audit array was empty because the helper queried the PHP class instead of the registered `it_ticket` morph alias; the corrected read-only query produced the counts above. The earlier file is retained as diagnostic evidence, not a missing-audit application defect.

## Open scope and cleanup

Full E10 still requires merged and settled browser journeys, bounded protected attachments, header/sender ambiguity, loops/bounces, governed quarantine recovery/retention and legacy-backfill proof. The existing authorized seven-day requester/responsible-agent reopening was tested in the backend; outside-window related-request recovery remains implementation work. Live-provider DP07 remains external. No real provider calls, communications, working-DB migration/reset, deployment or protected design-file changes.

Exact-token cleanup97675 terminal0; w11-canonical-browser-cleanup.txt reports exact schema/directory removal. Independent read-only w11-canonical-browser-cleanup-postflight.json confirms both absent. No active owned runtime remains.
