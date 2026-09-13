# W14 monitoring technical projection

Scope: W14 / F06 / B04 / E13, with W01 source privacy. This is a prerequisite slice, not completion of W14 or its browser acceptance.

## Confirmed defect and implementation

The existing monitoring listener copied arbitrary `DeviceEvent.payload.message` into the IT ticket description and activity. Snapshot capture attempted credential-pattern redaction, which cannot reliably remove unlabelled credentials, URL credentials, personal information or structured diagnostics. The activity presenter returned the complete historical payload to a technician even without Device/Control Room access.

- `MonitoringTechnicalSummary` supplies fixed technical status wording. New monitoring descriptions, activity and immutable snapshot messages no longer copy provider free text.
- `ItTicketActivityPresenter` projects only safe wording for all three monitoring event types, for both detail and hub activity. Source identifiers/details remain in the existing context presenter, behind current record and source permissions.
- `MonitoringIncidentEvidencePresenter` replaces the message in its read projection, including historical snapshots. Original stored evidence and checksum remain unchanged and integrity checks still apply.
- The five IT controllers that present ticket descriptions use the same projection. For a system ticket whose description exactly matches its historical creation diagnostic (after trimming), it returns safe outage wording. It preserves subsequent different human-authored text and ordinary system tickets. This does not rewrite stored records or claim to scrub all arbitrary human content.
- Ticket search uses reference, title and requester; it does not search these concealed diagnostic descriptions.

## Validation

Formatting passed for the 11 changed/new PHP files. `git diff --check` passed; no DESIGN.md/design_styles diff.

Initial isolated run: runner75142 / token `it_fc88741d000b442d`, log `w14-monitoring-safe-projection-tests.txt`: **28 passed, 2 failed / 225 assertions / 192.52s**, terminal1. All 14 postflight checks passed, including exact schema absence. Failures were the new historical-projection HTTP fixture attempting the SSR renderer (blocked by `Http::preventStrayRequests`) and an existing mail fixture expecting `sensitive_work` instead of the current reference resolver's earlier, privacy-safe `sender_unauthorized` denial. The fixture now disables SSR. The mail assertion now explicitly proves the actor cannot view the sensitive ticket and expects the generic denial; no production authorization was changed. Historical JSON evidence comparisons use semantic array equality because MySQL can reorder object keys.

Corrected run: runner68087 / token `it_0ec2a5083f024dec`, log `w14-monitoring-safe-projection-recheck.txt`: **30 passed / 265 assertions / 187.96s**, terminal0. All 14 postflight checks passed, including exact schema absence. Selected suites: ItControlRoomMonitoringContextTest, ItMonitoringTicketIntegrationTest and ItIngressContextAccessTest. Added cases cover unlabelled/multiline/URL/structured diagnostics and historical description/activity/snapshot projections for technicians with and without source access, including the real HTTP ticket response. Historical evidence remained unchanged with a valid checksum; later human text and ordinary system descriptions remained available. No source or authorization production changes were needed after the initial run.

All 12 changed/new source and test hashes are recorded in `w14-monitoring-safe-projection-source-hashes.json`. Initial and recheck formatting logs are retained. This backend slice is locally Verified; full W14 remains In progress.

## Remaining scope

No browser claim, no live migration, no provider communication, no production AI. Durable downstream IT intent/outcomes, direct nonurgent intake, episode/replay semantics, urgent create/link actions and real desktop browser journeys remain the next W14 work. The existing Control Room outbox `sent` state still does not prove its separately queued IT listener completed.
