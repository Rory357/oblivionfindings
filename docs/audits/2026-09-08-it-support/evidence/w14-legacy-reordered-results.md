# W14 legacy recovery before offline delivery — 12 September 2026

Mappings: W14 / F06 / B04 / E13. Implemented and locally Verified for this acceptance case. Full W14 remains incomplete.

- Reuses MonitoringWorkRouting on the existing canonical Signal. A persisted legacy fault/recovery pair can route directly to verification work without creating a stale Control Room alarm. Active legacy faults retain existing operational routing.
- The version2 routing decision pins the source event, recovery event and bounded recovery identity. Current canonical pairing is rechecked before delayed IT delivery; altered recovery evidence is denied. Frozen severity/rule assessment is retained rather than silently reprioritizing pending work when configuration changes.
- Existing direct link and sealed snapshot services accept the verified canonical legacy pair without fabricating Monitor observations or an Alert. Direct legacy work deduplicates by its actual source event, including settled-ticket replay. No new records, queues or migrations.
- Tests cover recovery/source delivery in reverse order, duplicate delivery, no operational alert, one open recovered ticket, valid null-alert sealed evidence, settled replay and altered recovery evidence. Existing native, privacy, delivery and ticket suites are included in run4381/tokenit_6d75b3da880f4b4e: **79 passed /564 assertions /195.33s /terminal0**, all14 isolation cleanup checks/exact schema absent.
- Guarded browser fixture adds legacy_reordered alongside the existing native and legacy_recovered cases. Browser bootstrap16923/rund55d7bab1f174dbf is active, fingerprint43ef998ae39f510c625cc2c70f9153e8fe20e452bfc8a210023ec778b6f243b6. Actual browser acceptance remains pending. No working database or provider changes.

Changed files: MonitoringWorkRouting, SignalProcessingService, ItTicketLinkService, MonitoringIncidentEvidenceService, CreateOrUpdateMonitoringTicket, MonitoringRecoveryPipelineTest and w14-monitoring-browser-fixture.php.

Browser closure supersedes the preceding pending notes: bootstrap16923 completed terminal0. Desktop cover-role (Device/IT without Control Room) and audit-role (IT without Device) journeys passed for direct recovered work, sealed source evidence, keyboard Device navigation, source403 and Back recovery. Exact cleanup19953 terminal0; independent schema/root absence verified. Owned tab6 closed. All seven source hashes and ten design baselines match. Details: w14-legacy-reordered-browser-results.md. No owned runtime/schema remains.

Next: Fleet IT intake, operator create/link-existing handoff, delivery retry UI and remaining source-capability/E13 cases. No full W14 or release acceptance is claimed.
