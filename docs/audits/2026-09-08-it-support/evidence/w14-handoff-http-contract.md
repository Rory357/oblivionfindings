# W14 Control Room handoff HTTP contract and UI continuation

The canonical coordinator is `ItControlRoomHandoffService`. These browser endpoints are implemented; see `w14-handoff-monitoring-results.md` for actual test status. No operator form is mounted yet.

## Endpoints

- `GET /it/control-room/alerts/{alert}/handoff`: required `viewer_user_id`; optional `search` up to120 characters. Returns `data` with actor, alert, alert_version, has_existing_work, existing_work and candidates. Both result lists are bounded25. Only permitted ticket details are exposed; hidden existing work is not a licence to create a duplicate.
- `POST /it/control-room/alerts/{alert}/handoff`: viewer_user_id, request_uuid, alert_version, action (`create` or `link`) and reason. Create requires explicit title, description, category, impact and urgency; optional it_service_id uses canonical intake validation. Link requires ticket_id and ticket_version. Canonical Site comes from the authorized alert, never a submitted Site. Fields are selected explicitly before the coordinator validates them under lock.
- `GET /it/control-room/alerts/{alert}/handoff/commands/{requestUuid}`: viewer_user_id. Reauthorizes before reading a saved receipt. An unknown UUID returns `status: unconfirmed` with the exact actor/alert/UUID tuple; it does not establish that no later commit is possible.
- `POST /it/control-room/alerts/{alert}/handoff/commands/{requestUuid}/cancel`: viewer_user_id. If no commit exists, saves an audited cancellation tombstone. A late original submission cannot create work. If already committed, returns the actual historical committed outcome.

All operations require current approval, it.view, it.manage, controlRoom.alerts.manage, owning Control Room content access and the actor's approved Site. Selected/returned tickets require current work access. Existing canonical roles and Site records remain the boundary. No new operational policy or permission grant is introduced.

## Results and failures

Committed responses carry `status: committed` and `data` containing the actor/alert/UUID tuple, replayed, changed, outcome (`created`, `linked`, `existing`) and the canonical permitted ticket. Cancelled results carry a real cancelled_at timestamp. The TypeScript reader accepts only matching identities, known states and a matching canonical same-origin ticket destination.

Field or source-version validation returns422. A stale selected ticket or conflicting request fingerprint returns409. Access loss returns403/404. Approval revocation returns403 for the request that terminates the session and401 for subsequent signed-out requests. Session/CSRF failures remain distinct from confirmed outcomes. Unexpected errors return a safe unknown-outcome message; no success, exception bindings, submitted text or redirect old-input buffer. Responses use no-store/private through the existing IT private-response guard.

## Next UI slice

Use the existing Control Room alert workspace and approved WizardShell/components, with explicit create/link choice, selected record review, technical fields, reason and inline validation. Source payloads must not populate the technical text automatically. Add a permission-safe capability projection; separate the ability to start a new handoff on an active alert from the ability to recover an older command after the alert changes.

Retain only actor/alert/UUID recovery identity in session storage. Freeze the submitted intent in memory; retries use the same UUID and unchanged body. Reload recovery must check the receipt or cancel it before a new submission. Timeouts and Stop waiting are unknown outcomes, not cancellation. Stale records require explicit refresh/review; access/session changes conceal the private buffer. Dirty close/navigation, focus restoration and keyboard error focus must follow approved patterns.

Mount the interaction in `LinkedSection` of `resources/js/components/control-room/alert-workspace-dialog.tsx`; the owning payload is `app/Services/ControlRoom/AlertWorkspaceService.php`. Respect the parent workspace's dialog/focus lifecycle and avoid stacked incompatible modal shells. Keep the existing canonical linked IT row and source evidence presentation.

Build once source/UI tests pass, then use the owned isolated desktop browser runtime with current asset/check-out proof. Verify create, link existing, concurrent automatic intake, replay/lost response, cancellation, stale target/source, revoked access, keyboard use and recovery. Do not resize the user's browser or introduce mobile-specific work. Record browser evidence separately from the API and actual-worker tests.
