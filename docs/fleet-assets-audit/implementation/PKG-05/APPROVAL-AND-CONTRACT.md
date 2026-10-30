# PKG-05 implementation — approved v6

User decision in this same task: **“approved please implement all of this”**. This approves the exact frozen v6 design and authorises implementation of the complete Transport workspace. It supersedes the earlier design-only stop for this package; it does not authorise deployment, production data changes, new clinical/operating authority, edits to protected guides or unrelated package work.

Later in the same task, the user explicitly requested notification to Main and merging to local main and GitHub main. That publication authority is retained after Main's exact-code technical approval; no additional user confirmation is required. The user also requested calendar right-click/drag and modal-rule corrections before completion. See `INTEGRATION-AND-CORRECTIONS.md` for the current source/base mapping, correction history and final evidence.

Approved candidate: previews/PKG-05/v6, runtime SHA256 `21c6f2a28a60bfdffd933917db20a0705d89b48ce9c263ef2dda45aee2c606bb`, manifest SHA256 `8bc1e7f92e9aba1df4911313f091943100a584ba1e92c3dfdd6cfa54606c21c9`, URL http://127.0.0.1:4400/#/fleet-assets/transports/overview. Preserve all six frozen previews.

Same Designer owns this implementation. Model verified gpt-6-astra/xhigh. Isolated checkout b9b9; branch `codex/pkg-05-transport-workspace` starts from fetched published main `f7d517359da6ffdf90de2f259111fe5e8a1133f2`. No other task/worker or message is needed for ordinary implementation choices. Main's protected dirty checkout remains untouched. Existing final technical-review/integration gates remain separate from this build authority.

## Scope and source contracts

1. **Demand:** extend canonical ClientTransportBooking additively with assessed operational transport details, return window, lifecycle/version, explicit Fleet booking link, assigned escort/equipment and key arrangement. Keep legacy status compatibility. New source events provide durable history, idempotency and version checks. Do not turn free-text legacy vehicle names into proven allocations.
2. **Allocation:** use FleetVehicleBooking and VehicleReadinessService. Atomically attach the demand when the existing booking store/update path succeeds. Validate permitted Client/Site/vehicle/staff objects before payload-specific errors, assessed seating/accessibility/support requirements, full-window overlap, active checkout and current versions. Preserve source approval routes; unknown eligibility/configuration cannot become ready. One booking identity is retained when changing a plan.
3. **Journey:** retain FleetResidentTransport and ResidentTransportJourneyService, with an explicit request link. Reconcile legacy requester-versus-driver comparisons using driver_user_id with the existing user_id fallback. Arrival, passenger accounting and completion are separate observations. Medication checks remain inside the existing source service; no new dose/administration screen or bypass.
4. **Returns:** preserve Fleet booking return, immutable return observations, actual giver/receiver and separately recorded item receipt/storage. Reuse FleetKeyLog and SiteRoom for key custody/location. Reuse FleetShiftHandover for optional shifts, preserving named acknowledgement/dispute and owned unresolved work. Arrival/completion is not proof of keys or vehicle release. Retain source Maintenance concern reporting.
5. **Workspace:** server-scoped Overview, Requests & approvals, guided Planner, Calendar, Journeys and Returns & handovers. Use the approved components, layout, menus, modal/full-record relationship, searchable choices, records/history and branded PDF exports. Reuse the existing shared calendar and one Fleet booking feed. Graphs derive from server records and period scope; no fixtures, role switcher, fixed date or simulated availability in production.
6. **Connected sources:** keep Client/Site/My Day entry/return paths, Outings and medication logistics discoverable. Vehicle history links refer to explicit source identity rather than inferred overlapping timestamps. File uploads use the existing private/scanned source file service and preserve history.

## Access and compatibility

One operating organisation. Existing approved Sites, Client policy, Fleet booking access and journey scope bound every list, count, source record, option, mutation and export. Transport management uses existing fleet.manage; booking decisions use fleet.bookings.approve/fleet.manage and current source independence rules; journey observations retain assigned-driver/current-source management authority. This approval does not invent scoped frontline key-management grants. Where current source authority is missing, show the responsible source action and a clear reason.

Existing source URLs continue to work; the workspace is an added composition. Existing unlinked journeys remain visible in their permitted scope. Existing unassessed requests start with Needs assessment. Do not infer explicit key locations, equipment receipts, request/journey links or approved staffing policy from old free text.

Additive migrations only. Rollback must retain operational event/link/receipt evidence once used; no cascade deletion of historical records or destructive backfill. Run migrations and fixture resets only in dedicated disposable test storage. Frozen previews are not importable application data.

## Work and verification sequence

- Document source mapping, verify baseline and isolated dependencies.
- Add demand/link/event contracts and narrow source integration with permission/concurrency/idempotency tests.
- Add scoped presenter, options, commands, source-linked records/history and export endpoints.
- Implement the approved visual workspace and persistent interactions using those contracts.
- Verify Client/Site/My Day/vehicle source returns, role and object denial, stale/concurrent updates, booking/journey/custody separation, uploads/export and recovery.
- Compare rendered production frontend with v6 at desktop widths and actual browser zoom where available. Run scoped backend, frontend, formatting/build and architecture checks; classify baseline failures honestly.
- Prepare the complete exact-code review packet before integration. Do not describe an incomplete shell or preview as the finished application.
