# Site Profile Corrective Capability Ledger

**Baseline:** `b5b5df463ce788fbbf988c74f5142b7fcbb52628`
**Corrective starting point:** `b22bab7fdaea1b4e5fbf880599dca8844d2745ed`
**Approved specification:** `docs/superpowers/specs/2026-07-22-site-profile-corrective-redesign-design.md`
**Rule:** Deduplicate implementation and data ownership, never user capability. `Removed` is not an allowed outcome.

## Ledger convention

- **Decision** is the approved pre-implementation treatment: `Restore`, `Canonical replacement`, or `Improve`.
- **Closure** begins `Open`. Before completion every row becomes `Restored`, `Canonical replacement`, `Improved`, or `Blocked` with exact evidence.
- Endpoints name the read/mutation contracts that must remain reachable from Site Profile. Record policies and direct-object checks apply in addition to named permissions.
- A summary/link card cannot close a row when the baseline supplied a register, editor, embedded workspace, or action.

## Composition, navigation, payload, and states

| ID | Capability/control | Decision | Endpoint/permission contract | Closure | Evidence |
| --- | --- | --- | --- | --- | --- |
| C-01 | Back link, Site ID, name, status, type, region, address and identity chips | Improve | `sites.show`; `sites.viewAny` + Site policy | Open | Baseline and Client hero |
| C-02 | Site brand colour, organisation-primary fallback and readable foreground | Improve | shell brand payload | Open | Spec 3.1 |
| C-03 | High-risk, high-needs, inactive/archived and readiness badges | Improve | `sites.update` when actionable | Open | Baseline hero |
| C-04 | Permission-shaped Add/Log, Edit and More actions | Improve | `clients.create`, `calendar.create`, `hazards.create`, `sites.update` | Open | Spec 3.1 |
| C-05 | Readiness, needs-attention and type-aware occupancy stats | Improve | permission-shaped shell counts | Open | Spec 3.1 |
| C-06 | Operational strip for next inspection/event/coverage | Improve | visible module permissions | Open | Spec 3.1 |
| C-07 | Safety strip with actionable risk, without replacing registers | Improve | `hazards.view` | Open | Spec 3.1 |
| C-08 | Four type-aware compact indicators | Improve | no locked counts | Open | Spec 3.1/3.3 |
| C-09 | `GroupPillRail` for five groups | Improve | visible-tab permissions | Open | Existing branch |
| C-10 | Needs-attention ribbon directly below hero | Improve | bounded permission-filtered attention | Open | Client `AlertRibbon` |
| C-11 | No recent-sites strip | Improve | none | Open | Explicit requirement |
| C-12 | Tier-two tabs, active state, search, keyboard shortcut and pin persistence | Improve | own `user_ui_preferences` row | Open | Existing branch |
| C-13 | Deep links and safe unknown/unauthorized normalization | Improve | Site/module permissions | Open | Existing branch |
| C-14 | One deferred payload per tab | Improve | exact `X-Inertia-Partial-Data` prop | Open | Spec 6.2 |
| C-15 | Labelled skeleton, inline error and one explicit Retry | Improve | exact tab prop | Open | Existing exception shield |
| C-16 | Mutation refresh preserves tab/group/scroll/unrelated data | Improve | affected tab + changed shell props | Open | Spec 6.3 |
| C-17 | Distinct empty/loading/locked/error states | Improve | no protected row/count leakage | Open | Spec 7 |
| C-18 | Validation keeps dialog/input and focuses first invalid field | Improve | owning FormRequest/policy | Open | Spec 7 |
| C-19 | Success announcement, visible focus, focus trap and Escape | Improve | shared dialog/toast primitives | Open | Spec 7/8.3 |
| C-20 | Dark mode, narrow web, mobile cards and no document overflow | Improve | none | Open | Spec 3.3/8.3 |

## Overview and readiness

| ID | Capability/control | Decision | Endpoint/permission contract | Closure | Evidence |
| --- | --- | --- | --- | --- | --- |
| O-01 | Readiness banner, score and complete critical/recommended checklist | Restore | eager readiness presenter; `sites.viewAny` | Open | `SiteReadinessPanel` |
| O-02 | Fix phone/email | Restore | `sites.contact-info.update`; `sites.update` + policy | Open | Baseline action |
| O-03 | Assign Site lead, manager, after-hours and emergency contacts | Restore | `sites.contacts.store/update/destroy`; `sites.update` | Open | Baseline dialogs |
| O-04 | Emergency plan and medication-storage location fixes | Restore | `sites.safety.update` and plan pins; `sites.update` | Open | Baseline action |
| O-05 | Review hazards, upload document, configure rooms/checklist/geofence | Canonical replacement | owning tab intent with Site context | Open | Baseline actions |
| O-06 | Type-aware occupancy and rooms/resources/zones terminology | Restore | Site inventory counts | Open | Baseline stats |
| O-07 | Contact information and phone/email actions | Restore | `tel:`/`mailto:` + contact dialogs | Open | Contact rows |
| O-08 | Address, access instructions and map | Restore | `sites.location.update`/`sites.geocode.search`; `sites.update` | Open | Overview map |
| O-09 | Geofence view/add/edit/delete | Restore | `sites.geofence.store/update/destroy`; `assets.geofences.manage` | Open | Geofence dialog |
| O-10 | High-risk/high-needs metadata, notes and review date | Restore | `sites.safety.update`; `sites.update` | Open | Safety dialog |
| O-11 | Emergency and medication-storage locations | Restore | safety update + plan pins | Open | Baseline Safety card |
| O-12 | Service contexts and status | Restore | Site relation; manage permission | Open | Baseline Services card |
| O-13 | Multi-note log: add, author/time and confirmed delete | Restore | `sites.notes.store/destroy`; `sites.update` | Open | Site note dialog |
| O-14 | Site lines edit | Restore | Site update contract | Open | `EditSiteLineDialog` |
| O-15 | Recent activity and contextual edit controls | Restore | permission-shaped activity | Open | Baseline Overview |

## People

| ID | Capability/control | Decision | Endpoint/permission contract | Closure | Evidence |
| --- | --- | --- | --- | --- | --- |
| P-01 | Resident/attendee/client counts and full cards | Restore | `clients.viewAny\|clients.viewAssigned` | Open | Baseline `ClientsTab` |
| P-02 | Photo/name/status/DOB-age/gender/risk/safeguarding/start/funding | Restore | Client policy and assigned scope | Open | Baseline `ClientCard` |
| P-03 | Service context, key worker and room/space placement | Restore | placement presenter/policies | Open | Baseline cards |
| P-04 | Full Client profile navigation | Restore | `clients.show`; Client policy | Open | Baseline link |
| P-05 | Create Client with Site defaults | Canonical replacement | shared `AddClientDialog` -> `clients.store`; `clients.create` | Open | Existing branch |
| P-06 | Link existing unplaced Client | Restore | `sites.clients.link`; `clients.assignments.update` | Open | `LinkClientDialog` |
| P-07 | Room/service/key-worker assignment | Restore | placement service + room endpoint | Open | Existing branch |
| P-08 | Confirmed unlink and placement effects | Restore | `sites.clients.unlink`; assignments permission | Open | Existing branch |
| P-09 | Typed contact register and primary treatment | Restore | `sites.contacts.*`; Site update policy | Open | Baseline Contacts |
| P-10 | Contact show/add/edit/delete | Restore | Site-owned contact dialogs | Open | `contacts/_dialogs.tsx` |
| P-11 | Contact phone/email actions | Restore | `tel:`/`mailto:` | Open | Baseline cards |
| P-12 | Full staff-requirements register | Restore | `sites.staff_requirements.store/update/destroy`; `staff.viewAny` + `sites.update` | Open | Baseline tab |
| P-13 | Requirement presets/category/description/certification/expiry | Restore | same endpoints | Open | Baseline dialog |
| P-14 | Full shift coverage requirements | Restore | `sites.coverage_requirements.store/update/destroy`; `rostering.viewAny` + `sites.update` | Open | Baseline tab |
| P-15 | Day/time/minimum/roles/overstaffing/type/service/client/notes | Restore | same endpoints | Open | Baseline form |
| P-16 | Coverage health/alerts and Rostering impact | Restore | `ShiftCoverageService` + Rostering route | Open | Baseline preview |

## Safety

| ID | Capability/control | Decision | Endpoint/permission contract | Closure | Evidence |
| --- | --- | --- | --- | --- | --- |
| S-01 | Full hazard register: reference/rating/status/dates/owner/actions | Restore | `sites.hazards.index/show/update/assign/close/status/review`; `hazards.view/manage` | Open | Baseline Hazards |
| S-02 | Report hazard with Site prefilled | Canonical replacement | `sites.hazards.create/store`; `hazards.create` | Open | Site H&S owner |
| S-03 | Hazard media and corrective actions | Canonical replacement | `sites.hazards.media(.show)/actions.store/complete` | Open | Hazard owner |
| S-04 | Applicable procedures | Restore | canonical procedure presenter; `hazards.view` | Open | Baseline panel |
| S-05 | Chemicals, SDS and segregation cues | Restore | canonical substances workflows; H&S permissions | Open | Baseline panel |
| S-06 | Full risk-assessment register, hazard linkage, risk/review | Restore | `health-safety.risk-assessments.index/show`; `hazards.view` | Open | `RaRegisterSection` |
| S-07 | RA create/update/activate/review/residual/supersede/archive | Canonical replacement | corresponding H&S endpoints; `hazards.manage` | Open | H&S owner |
| S-08 | RA attachments | Canonical replacement | `attachments.store/download/destroy` | Open | H&S owner |
| S-09 | Inspection schedules/due state/assigned person | Restore | `sites.inspections.index/store/destroy`; checklist permissions | Open | Baseline tab |
| S-10 | Inspection results/history/pass-fail/findings/completer | Restore | `sites.inspections.complete`; `checklists.run` | Open | Baseline records |
| S-11 | Drill cadence/due/history/outcome/findings | Restore | `health-safety.drills.index/show`; `hazards.view` | Open | Baseline Drills |
| S-12 | Drill create/update/start/complete/cancel/participants/findings/files | Canonical replacement | corresponding drill endpoints; H&S writes | Open | H&S owner |
| S-13 | First-aid person/injury/treatment/outcome/ambulance records | Restore | `health-safety.first-aid.index/show`; `hazards.view` | Open | Baseline First Aid |
| S-14 | First-aid create/update/link/follow-ups/files/delete/export | Canonical replacement | corresponding first-aid endpoints | Open | H&S owner |
| S-15 | PPE counts/type/category/serial/condition/status/inspection/expiry | Restore | `health-safety.ppe.index`; `hazards.view` | Open | Baseline PPE |
| S-16 | PPE types/inventory/allocations/ack/return/inspect/condemn/dispose/files | Canonical replacement | corresponding PPE endpoints; manage/ownership | Open | H&S owner |
| S-17 | Complete published Emergency Plan | Restore | `sites.emergency-plan.show`; Site policy | Open | Baseline tab |
| S-18 | Organisation, contacts, procedures, notes, footer and legend | Restore | emergency presenter | Open | Emergency page |
| S-19 | Published plan, emergency layer and all emergency/device pins | Restore | type-plan presenter/policies | Open | Baseline preview |
| S-20 | Paper selection, preview, print and PDF | Restore | `sites.emergency-plan.download` | Open | Baseline controls |
| S-21 | Manage plan and emergency-mode builder | Restore | `sites.emergency-plan.update` + `sites.plan.*`; `sites.update` | Open | Existing builder |

## Operations

| ID | Capability/control | Decision | Endpoint/permission contract | Closure | Evidence |
| --- | --- | --- | --- | --- | --- |
| OP-01 | Complete embedded `SiteCalendar` | Restore | `sites.calendar.index/events`; `calendar.view` | Open | Baseline embed |
| OP-02 | Month/week/day/agenda/timeline, sources and filters | Restore | calendar feed | Open | `SiteCalendar.tsx` |
| OP-03 | Event create/update/delete | Canonical replacement | `sites.calendar.store/update/destroy`; create/manage perms | Open | Calendar owner |
| OP-04 | Approve/reject and exception detail | Canonical replacement | `sites.calendar.approve/reject/exception`; approve perm | Open | Calendar owner |
| OP-05 | Complete embedded `ChecklistsWorkspace` | Restore | `sites.checklists.index`; `checklists.view` | Open | Baseline embed |
| OP-06 | Overview/Due now/Runs/Schedule/Assignments/Library/Reports | Restore | canonical checklist data | Open | Workspace panes |
| OP-07 | Assign/remove/create run | Canonical replacement | `sites.checklists.assign/removeAssignment/createRun` | Open | Workspace |
| OP-08 | Run response/complete/reschedule/reassign/skip/restore | Canonical replacement | corresponding `sites.checklists.*` endpoints | Open | Run modal |
| OP-09 | Template create/update/delete and builder | Canonical replacement | `sites.checklists.templates.*` | Open | Builder |
| OP-10 | Complete embedded Meal Planner | Restore | `sites.meals.view`; type eligibility | Open | Baseline embed |
| OP-11 | Planner grid and week summary | Restore | `sites.meals.bootstrap/plan.index/weekSummary` | Open | Planner |
| OP-12 | Plan create/update/delete/serve/unserve/clear/copy/conflicts | Canonical replacement | corresponding `sites.meals.plan.*` | Open | Planner dialogs |
| OP-13 | Recipes, products and dietary tags | Restore | existing library endpoints/manage perms | Open | Recipes/library dialogs |
| OP-14 | Shopping lists: view/generate/update/items/receive/delete | Restore | corresponding `sites.meals.shopping.*` | Open | Shopping panel |
| OP-15 | Inventory: view/items/adjust/stocktake/movements | Restore | corresponding `sites.meals.inventory.*` | Open | Inventory panel |
| OP-16 | Templates: view/create/update/delete/apply | Restore | corresponding `sites.meals.templates.*` | Open | Templates panel |
| OP-17 | Settings/resident dietary/takeaway/overrides/spend | Restore | existing Meal Planner endpoints/dialogs | Open | Planner controls |
| OP-18 | Full Site-linked asset inventory | Restore | `fleet-assets.assets.index/show`; asset view perms | Open | Baseline Assets |
| OP-19 | Owner/assignment/tag/category/status/risk/location/service cues | Restore | canonical Asset scopes | Open | Baseline cards |
| OP-20 | Asset create/edit/inspect/maintain/docs/owner/assign/geofence | Canonical replacement | `fleet-assets.assets.*`/`assets.*` | Open | Asset owner |
| OP-21 | Full Site Fleet work surface/charts | Restore | `fleet-assets.dashboard/vehicles.index`; `fleet.viewAny` | Open | Baseline Fleet |
| OP-22 | Vehicle status/telemetry consent/WOF/registration/detail | Restore | `fleet-assets.vehicles.show/update` | Open | Baseline vehicle rows |
| OP-23 | Bookings checkout/return/cancel/approve/reject | Restore | `fleet-assets.bookings.*` | Open | Baseline bookings |
| OP-24 | Outings start/complete/cancel/resident return | Restore | `fleet-assets.outings.*` | Open | Baseline outings |
| OP-25 | Fleet stats and compliance dates | Restore | canonical Fleet presenter | Open | Baseline charts |
| OP-26 | Full Hardware register and filters | Restore | `sites.hardware.index`; hardware/device view | Open | Hardware page |
| OP-27 | Online/offline/degraded detail and integrations | Restore | `DeviceRegistryService` | Open | Hardware status |
| OP-28 | Device room assignment and room management | Restore | `sites.hardware.assignRoom/manageRooms`; manage perm | Open | Hardware page |
| OP-29 | Device pin/unpin | Restore | `sites.hardware.pin/unpin`; manage perm | Open | Plan integration |
| OP-30 | Full type-aware Floor Plan and Rooms/Resources/Zones | Restore | `sites.plan.show` | Open | Baseline plan |
| OP-31 | Published/draft thumbnails/version/notes/status | Restore | `SiteTypePlanService` | Open | Plan page |
| OP-32 | Full builder canvas/tools/inspector/doors/geometry | Restore | `SiteTypePlanBuilderDialog` | Open | Plan tooling |
| OP-33 | Draft store/update/duplicate/discard/publish | Restore | `sites.plan.draft.store/update/destroy/duplicate/publish` | Open | Plan routes |
| OP-34 | Pin create/update/delete and emergency layer | Restore | `sites.plan.pins.store/update/destroy` | Open | Plan routes |
| OP-35 | Room add/show/edit/delete/seed/reorder/restore/door card | Restore | `sites.rooms.index/store/update/destroy/seed-defaults/reorder/restore/door-card` | Open | Rooms |
| OP-36 | Room-client assign/unassign and asset attach/detach | Restore | `sites.rooms.assign/assets.attach/assets.detach` | Open | Rooms |
| OP-37 | Head-office resources and facility zones | Restore | `sites.resources.*`/`sites.zones.*`; `sites.update` | Open | Type-specific baseline |

## Admin

| ID | Capability/control | Decision | Endpoint/permission contract | Closure | Evidence |
| --- | --- | --- | --- | --- | --- |
| A-01 | Complete document register | Restore | `sites.documents.index`; Site policy | Open | Baseline Documents |
| A-02 | Category/folder/file/version/effective/expiry/uploader/size | Restore | document presenter | Open | Baseline cards |
| A-03 | Download | Restore | `sites.documents.download` | Open | Baseline action |
| A-04 | Folder/upload/edit/delete | Restore | `sites.document-folders.store`/`sites.documents.store/update/destroy`; `sites.update` | Open | Site documents |
| A-05 | Authorised Site financial summary/dashboard | Restore | `finance.sites.financial-dashboard`; `finance.dashboard` | Open | Spec |
| A-06 | Complete house ledger balance/entries/categories/references/notes | Restore | `sites.ledger.index`; `sites.ledger.view` | Open | Baseline ledger |
| A-07 | Ledger add and attachment download | Restore | `sites.ledger.store/entries.store/entries.download/attachment`; create perm | Open | Ledger routes |
| A-08 | Ledger reconcile | Restore | `sites.ledger.reconcile`; manage perm | Open | Ledger route |
| A-09 | Full Site vendor register | Restore | `sites.vendors.index/global`; `vendors.view` | Open | Baseline Vendors |
| A-10 | Vendor add/show/edit/delete/flags | Restore | `sites.vendors.store/update/destroy/flags`; `vendors.manage` | Open | Vendor dialogs |
| A-11 | Full credential metadata/register and TOTP state | Restore | `sites.credentials.index`; `credentials.view` | Open | Baseline Credentials |
| A-12 | Credential add/show/edit/delete/rotate/reauth | Canonical replacement | corresponding credential endpoints; manage perm | Open | Credential dialogs |
| A-13 | Separately authorised audited reveal/copy | Canonical replacement | `sites.credentials.reveal/copy`; reveal perm | Open | Credential owner |
| A-14 | TOTP live code/removal | Canonical replacement | `sites.credentials.totp.code/remove` | Open | Credential owner |
| A-15 | Credential/Vendor audit context | Restore | `sites.credentials.audit`/`sites.vendors.audit` | Open | Audit dialogs |
| A-16 | No credential secret in profile payload | Improve | separate audited actions + policy | Open | Privacy gate |
| A-17 | Complete Site service-context register | Restore | Site relation; `sites.viewAny` | Open | Baseline Services |
| A-18 | Service status/type/description and management | Restore | `settings.service_contexts`; manage perm | Open | Baseline actions |

## Dialog and destructive-action host

| ID | Dialog/control intent | Decision | Canonical owner/endpoint | Closure | Evidence |
| --- | --- | --- | --- | --- | --- |
| D-01 | Edit Site | Restore | `sites.edit/update` | Open | Hero |
| D-02 | Edit contact info/location/access/Site safety | Restore | `sites.contact-info.update/location.update/safety.update` | Open | Overview dialogs |
| D-03 | Add/delete Site note | Restore | `sites.notes.store/destroy` | Open | Site owner |
| D-04 | Edit Site line | Restore | Site update contract | Open | Site owner |
| D-05 | Geofence add/edit/delete | Restore | `sites.geofence.*` | Open | Site owner |
| D-06 | Create Client | Canonical replacement | shared Add Client wizard/`clients.store` | Open | Client owner |
| D-07 | Link/unlink Client and assign room | Restore | placement/room endpoints | Open | Site placement |
| D-08 | Contact add/show/edit/delete | Restore | `sites.contacts.*` | Open | Site owner |
| D-09 | Room add/show/edit/delete/assign/unassign | Restore | `sites.rooms.*` | Open | Site owner |
| D-10 | Plan builder focused/full/emergency/device modes | Restore | `sites.plan.*`/`sites.hardware.pin` | Open | Canonical builder |
| D-11 | Document folder/upload/edit/delete | Restore | Site document endpoints | Open | Site owner |
| D-12 | Vendor add/show/edit/delete | Restore | vendor dialogs/endpoints | Open | Vendor owner |
| D-13 | Credential add/show/edit/delete/TOTP/reveal/audit | Canonical replacement | credential dialogs/endpoints | Open | Credential owner |
| D-14 | Staff requirement add/edit/delete | Restore | `sites.staff_requirements.*` | Open | Site compliance |
| D-15 | Coverage requirement add/edit/delete | Restore | `sites.coverage_requirements.*` | Open | Site compliance |
| D-16 | H&S create/manage actions | Canonical replacement | owning H&S workflow with `site_id` | Open | H&S owner |
| D-17 | Calendar event workflows | Canonical replacement | embedded `SiteCalendar` | Open | Calendar owner |
| D-18 | Checklist run/template/assignment workflows | Canonical replacement | embedded `ChecklistsWorkspace` | Open | Checklists owner |
| D-19 | Meal Planner dialogs/actions | Canonical replacement | embedded planner | Open | Catering owner |
| D-20 | Asset/Fleet/Hardware mutations | Canonical replacement | owning module with Site context | Open | Module owners |
| D-21 | Ledger add/reconcile | Restore | `sites.ledger.*` | Open | Ledger owner |
| D-22 | Accessible destructive confirmation; no browser alert/confirm | Improve | `ConfirmAction`/`ConfirmDialog` | Open | Spec 5.3 |

## Closure gate

The goal cannot be marked complete while any row remains `Open`. A `Blocked` row must name the exact dependency, affected user capability, evidence gathered, and safest next executable action.
