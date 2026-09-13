# W14 operator handoff — focused current-code findings

12 September 2026. Preparation for the next dependency-ready implementation slice, not implementation or acceptance evidence. W14 / B04 / E13; DP05 retains operational response in Control Room.

The current `routes/control-room.php`, Control Room controllers and alert workspace contain no operator IT create/link-existing endpoint. `MonitoringIncidentEvidencePresenter::forAlert` discovers only links bearing the automatic monitoring principal and operation. `alert-workspace-dialog.tsx` renders that automatic relationship as an existing canonical destination. This confirms the operator handoff remains incomplete even though automatic Fleet/native intake and navigation now work.

Canonical services to extend:

- `ItTicketIntakeService` owns human creation, actor/Site/requester validation, priority, routing, SLA, audit and durable creation receipts. Its current input has no alert handoff. Preserve that owner rather than independently inserting a human ticket.
- `ItTicketLinkService::link` already supports human `source_alert` relationships, rechecks ticket work access and source access, and persists the real actor. Its present alert predicate uses `controlRoom.alerts.view` plus Site scope; the owning `ControlRoomAlertAccessService` also distinguishes read/manage grants and controlled-medication content. A handoff must use the complete owning access decision; a bare Site match is insufficient.
- Existing `ItTicketCommandReceipt` and related-work commands demonstrate fingerprinted replay, current authorization before receipt disclosure, version conflict, historical outcomes and cancellation tombstones. Extend those contracts for handoff; do not add another command store.
- Existing automatic source links and sealed evidence retain their actual system provenance. Human handoff must have explicit actor provenance, become visible through the canonical presenters, and must not pretend it was generated from a monitoring observation.

Required implementation and verification for this slice:

1. Permission-safe preview and bounded search of eligible existing IT work. Source/target must remain in the actor's approved Site and privacy boundaries. No source payload, coordinates, people/trips or hidden ticket metadata copied into suggestions.
2. Explicit create versus link-existing interaction in the approved alert workspace pattern, with real title/details/technical justification where needed. Recheck both records on commit; keep the operational alert state unchanged.
3. One atomic handoff result across intake/link, audit, activity and receipt. Serialize competing operators and automatic delivery at the canonical source so choosing existing work cannot race into duplicate work. Replays and cancellation must not silently reapply historical intent.
4. Lost-response recovery, retry, stale selection/version, validation and permission drift must have usable UI states. Return a real canonical ticket destination only after a committed authorized outcome. No success for failed or unknown results.
5. Focused access/lifecycle/failure tests, actual competing-worker coverage for the shared source, then real desktop browser create/link, keyboard, restricted role, denial and recovery journeys against fresh assets. All database and notification isolation rules remain mandatory.

No endpoints or UI controls have been added by this inspection. Final source-capability, delivery-failure/retry UI and full W14/E13/release acceptance remain open independently.
