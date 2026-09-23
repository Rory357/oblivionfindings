# Main notified — v12

User authorisation: “ok let main know”.

Sent successfully on 22 September 2026 to task “Follow Revision 10 approval gates”, 01a0b8c5-186f-7681-83fc-40229d94ef87, through send_message_to_thread. This records delivery, not Main's review or implementation approval. Frozen v12 files and manifest remain unchanged.

## Message delivered

The user has now explicitly authorised the PKG-02B handoff: “ok let main know”. The earlier hold on notifying Main is lifted. Please register and review the latest vehicle-profile mockup, v12, under your existing Revision 10 gates. This message hands over the design candidate; it does not claim production implementation approval or authorise a merge/deployment.

Latest preview: http://127.0.0.1:4347/PKG-02B/v12/#/fleet-assets/vehicles/14/compliance
Designer task: 01a0c2bb-fcff-7cb1-8bab-882d84477c6c
Worktree: C:/Users/steph/.codex/worktrees/5b0a/oblivionfindings
Branch: codex/pkg-02b-vehicle-profile-design
Baseline: 5307692ec59be84f3503c06354419b7da95be805
Frozen candidate: df987809c76e76d586f2ed62033f1f7748bb50b6ddfab9a7e36d5237fbdad99a

Read these in the Designer worktree:
- docs/fleet-assets-audit/previews/PKG-02B/v12/README.md
- docs/fleet-assets-audit/evidence/PKG-02B/v12/manifest.json
- docs/fleet-assets-audit/evidence/PKG-02B/v12/AUDIT.md and its screenshots
- docs/fleet-assets-audit/evidence/PKG-02B/v7/GAP-AUDIT.md
- docs/fleet-assets-audit/evidence/PKG-02B/v5/GV500CG-RESEARCH.md
- docs/fleet-assets-audit/evidence/PKG-02B/v6/SPEED-LIMIT-SOURCES.md
- DESIGN.md, design_styles/POPUP_STYLE_GUIDE.md and design_styles/MAP_GEOFENCING_STYLE_GUIDE.md.

The files labelled “pending / do not send” are frozen historical notes from before today's instruction. Do not modify the frozen package to change that wording. The user authorised notification now.

Important requirements the user specifically asked to pass to Main:
1. Universal geofencing: one shared boundary registry for sites, houses, clients, vehicles and any future map. Existing geofences can be selected and new ones created from those profiles. Keep canonical geometry/version/ownership separate from assignments, schedules, exceptions and monitoring authority. Selecting a boundary does not activate monitoring. Reuse the inspected Client Location interactions, greyscale base maps, coloured overlays, motion/state icons, compact map/vehicle context menus and keyboard/touch alternatives.
2. Universal, customisable vehicle checklist library: typed questions, ordering, required uploads/evidence, assignment/scope, publication and retirement. Submitted checks retain their original template version and source evidence. Coordinate with existing Maintenance checklist ownership instead of creating a competing library.
3. Searchable reusable catalogues with Add custom/new for service types, month-based intervals and other configurable terms. Production needs stable IDs, duplicate prevention, permissions and persistent history. Unique VIN/document references remain identifiers; workflow states remain controlled.
4. Vehicle bookings need explicit Approval required / Approval not required, with authority and reason/evidence for bypassing approval. Neither choice bypasses restrictions, booking conflicts, driver/custody checks or return inspection.
5. GV500CG is the confirmed tracker. Its OBD connection is power-only: do not promise direct ECU VIN, dashboard odometer, RPM, fuel or diagnostic-code extraction. Gate actual features by exact model/firmware/protocol. External diagnostic faults can still create vehicle alerts with separate source provenance. Keep vehicle supply voltage distinct from backup battery.
6. Calibrated tracker distance can support service/RUC planning, with manual dashboard observations, cross-check warnings and reconciliation retained. Handle gaps, stale reports, device swaps and resets; never silently overwrite observed mileage. WoF/registration dates remain evidence-owned.
7. Overspeed, suspected collision, vehicle faults and eligible power/voltage/towing/report-loss/geofence signals must create Control Room alerts. Preserve source/time/location/trip/correlation, delivery receipts, acknowledgement/escalation and triage. Control Room decides whether to create or link Maintenance work. Alert closure, work completion, authorised vehicle release and Finance approval are distinct. A detected impact is a suspected collision, not a confirmed accident.
8. Driver and per-trip insights need transparent scoring, coverage/minimum samples, verified driver segments, review/dispute/recalculation history and versioned policy. Overspeed alerts flow independently of score eligibility. Provider road limits require licensing, NZ coverage and road matching; OSM tags/Google are not a guaranteed real-time limit feed. Unknown limits stay unknown; manual temporary limits need evidence, effective dates and expiry.
9. Retain five calendar views and context-sensitive right-click actions, prefilled dates, source ownership and conflict checks. Use shared Tasks/Calendar for reminders; closing a reminder does not complete its obligation. Trip history uses compact route/inspector layouts, timeline List/Card/Table, and branded multi-day PDF/Excel exports with map images and source/coverage information.

Latest v11/v12 changes:
- List/Cards across compliance, schedules, checks/templates, documents, mileage, reminders, Maintenance history and Finance; readable narrow layouts and preserved actions.
- Expiry sits beside document renewal timing/owner/backup. Contextual uploads prefill type/reference; existing details/expiry can be edited without reuploading.
- Replacement retains original files and transfers the same renewal reminder rather than duplicating or orphaning it; pause/archive/source/calendar actions are connected.
- Compliance rows expose direct date-and-evidence editing with conditional requirements and correct Not applicable behaviour.
- Work completion now accepts completion/retest attachments and links them to the work order; restriction release remains independent.

Verification: TypeScript and Vite pass; focused document workflow regressions pass; browser checks cover date validation, calendar links, replacement/history, reminder reuse, view-only controls, draft recovery, 390px form and work-completion evidence handoff. Zero console errors. All 109 v11 manifest files were verified unchanged. Existing Leaflet import/large-bundle warnings remain. These are synthetic in-memory workflows, not verified production delivery, persistence, device integration or Finance posting. Native OS file-picker transfer and unchanged export engines were not rerun in v12. No production files, commits, pushes or PRs were made in this mockup pass.

The preview server can be restarted from the Designer worktree with:
C:/Users/steph/.hermes/node/node.exe --use-system-ca docs/fleet-assets-audit/previews/PKG-02B/v12/serve.mjs

Please carry these requirements into the package/ownership register and assess the candidate at the appropriate design gate. Keep this a single-organisation application: roles, approved sites, canonical ownership and privacy are the access boundaries.
