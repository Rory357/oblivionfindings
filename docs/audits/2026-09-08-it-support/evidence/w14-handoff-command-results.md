# W14 Control Room handoff — command and access foundation

Follow-up: automatic coordination, actual-worker corrections, guarded HTTP controls and their current evidence are in [handoff integration results](w14-handoff-monitoring-results.md). The original foundation record below is historical; its statements about absent routes and unimplemented automatic coordination no longer describe the current implementation.

12 September 2026. W14 / F06 / B04 / E13; source privacy also supports W01. **Implemented and backend-verified for the cases below. The complete operator workflow is not implemented or browser-verified.** No route or UI action exposes the new command yet.

## Implementation

`ItControlRoomHandoffService` coordinates the existing human intake service, canonical source-alert links and `ItTicketCommandReceipt` records. It provides permission-scoped preview/search, create or link-existing commands, source/target version checks, current actor and authorization evidence, mandatory activity/audit, fingerprinted replay, lost-response lookup and cancellation tombstones. Commands serialize against the existing actor and alert records. Existing linked work is reused; choosing a different destination is rejected rather than silently replacing it. Operational alert state remains unchanged.

Creation requires explicit category, impact and urgency; optional service selection passes through canonical intake validation. Priority, SLA, routing, approval and notification preparation remain owned by the existing intake services. Human titles/details are supplied explicitly; operational payloads are not automatically copied. Human links retain their actor provenance. Activity projections use fixed safe wording rather than disclose the source ID or handoff reason to all IT viewers.

Confirmed access gap: the old IT alert-link and context checks used Site scope without Control Room's controlled-medication content boundary. They now reuse that owning boundary. Canonical manage-only alert readers can open a permitted source; controlled content remains hidden without the additional owning capability. Snapshot alert checks use the full owning read decision. This is access hardening, not a new general permission grant.

Changed files (exact hashes: `w14-handoff-command-source-hashes.json`):

- `app/Domain/It/Services/ItControlRoomHandoffService.php`
- `app/Domain/It/Services/ItTicketLinkService.php`
- `app/Domain/It/Presenters/ItTicketActivityPresenter.php`
- `app/Domain/It/Presenters/ItTicketContextPresenter.php`
- `app/Domain/Monitoring/Presenters/MonitoringIncidentEvidencePresenter.php`
- `tests/Feature/It/ItControlRoomHandoffTest.php`
- `tests/Feature/It/ItIngressContextAccessTest.php`

## Actual verification

Initial run67709/token `it_f7e57a5098934db3`: **31passed /1failed /270assertions /187.73s**, wrapper terminal1. All14 new handoff cases passed. The existing Site-only context fixture used a random alert type; it produced medication content and was correctly concealed by the new owning privacy check. Made both Site-only fixture pairs explicitly nonclinical. No production privacy check was relaxed. All14 isolation postflight checks passed and exact schema was absent.

Final run49886/token `it_9af36c52d8474229`: **64passed /482assertions /192.11s**, wrapper terminal0, all14 isolation postflight checks passed and exact schema absent. Log: `w14-handoff-command-final-tests.txt`. Includes18 handoff cases plus ingress/context/Fleet regressions: canonical human creation, classification/priority, real actor link, existing-work reuse, replay/conflicting intent, cancellation/late arrival, stale source and target, audit rollback/retry, Site/grant/privacy drift, controlled-content denial and explicit grant, manage-only source navigation projection, invalid/settled/merged targets and original-actor validation.

Final formatting and source whitespace checks passed. All7 source hashes match the tested files. All10 protected design hashes match (`w14-handoff-command-design-check.json`). No active test/import remains. No browser or build run occurred in this slice; frontend sources/assets were unchanged. Previous browser screenshots do not establish the new permission projection's browser acceptance.

## Precise continuation

Before exposing this command through routes or controls:

1. Coordinate automatic native/Fleet delivery with a prior human selection under the same canonical source lock. Current automatic consumers only recognize system-created monitoring work; they must not create a competing ticket after a human handoff. Preserve real human provenance and canonical episode/recovery evidence, including recovery-first delivery and later episodes. Do not label a human handoff as a fabricated monitoring creation.
2. Make `MonitoringIncidentEvidencePresenter::forAlert` discover legitimate human handoff links as well as automatic links, with current source/ticket privacy. Its current query is still system-principal-only. Review bounded discovery and preferred existing-work selection when an alert has historical settled links.
3. Finish typed HTTP contracts and approved alert-workspace create/link/review/recovery controls. Verify invalid/missing receipt metadata fails closed; do not equate an unknown result with failure or success. Keep local cancellation distinct from a committed handoff.
4. Verify actual competing operators and automatic delivery, current restricted roles and source access, then real desktop keyboard/create/link/error/retry/cancellation journeys with current assets and isolated communications/database.

Working database migrations17–29 remain unapplied and untouched. Production AI remains disabled. No deployment, real communications or provider changes. Public push remains pending explicit destination approval. Full W14, remaining W00–W27 work, E01–E23 and release acceptance remain incomplete.
