# Transport v3 — clarity and interaction review

Status: **Ready for Stephan’s exact design review.** This is a synthetic desktop preview, not application implementation.

Start with the [revised Charlie journey](http://127.0.0.1:4397/#/fleet-assets/transports/journeys/J-609?from=journeys). Other views: [Requests & approvals](http://127.0.0.1:4397/#/fleet-assets/transports/requests), [Planner](http://127.0.0.1:4397/#/fleet-assets/transports/planner), [Journeys](http://127.0.0.1:4397/#/fleet-assets/transports/journeys), [Returns & handovers](http://127.0.0.1:4397/#/fleet-assets/transports/returns), [Overview](http://127.0.0.1:4397/#/fleet-assets/transports/overview).

The preview uses the same isolated PKG-05 checkout and existing Astra/xhigh chat. All changes are inside this versioned preview directory. Original v1, audit-v1 and v2 artifacts and servers remain preserved. Reload resets synthetic records.

## What the latest feedback uncovered

The previous preview had important usability gaps despite passing its prior scenario checks:

- Banners on four workspace views and record pages lacked the useful meter row required by the shared PageHeader pattern.
- Table menus used `onSelect` where the shared menu expects `onClick`. Their visible menu labels did not make them functional. Right-click was absent.
- Planner cards led to other pages instead of providing an interactive allocation workspace.
- Journey completion was technically reachable, but the visual hierarchy still buried the passenger workflow among large source-record sections. Its next owner could incorrectly remain the driver while a medication handoff needed another authorised worker.
- Returns combined vehicle arrival, physical item receipt, optional shift handover and exception reconciliation without explaining their separate roles.

V3 addresses those findings directly. The prior passing checks are retained as historical evidence, not proof that v2’s design was sufficiently clear.

## Revised behavior

**Useful banners.** Each workspace now has scoped counts that open the corresponding queue. Journey and return detail banners show current state, transport window, vehicle or item status, and the person responsible for the next step. Counts follow site/date/access scope; unavailable sources show unavailable values. Banner links lead to a real section or source record.

**Working menus.** Requests, journeys and return rows have matching right-click and visible ••• actions, supplied by the same typed action builder. Record banners also have both menus; workspace banners offer relevant workspace actions. Shift+F10 opens the focused banner’s menu. Mutations follow the selected role, assignment, site, source state and lifecycle. Explicit form targets ensure acting on a row edits that row rather than a previously opened record.

**Interactive Planner.** Select a request on the left. The selected pane either shows the required assessment/information step or exposes searchable vehicle, driver, escort and equipment selectors. The time window can be expanded and changed. Check availability, then save the proposal. Changing a resource or time invalidates the check. Saving creates one proposed Fleet booking; subsequent changes retain its exact identity and require another source decision. Unsaved selection changes are protected when switching requests, and cancellation actually clears the draft. Conflicts and missing readiness configuration do not produce a successful save.

**Journeys.** The next action and responsible person are first, followed by a compact progression, route/actual times and a four-item completion checklist. The Charlie example identifies Sara’s medication-source handoff; after its simulated resolution, Nia becomes the next person and **Complete journey** becomes available. Vehicle return details are a clear related section. Linked records, history and evidence are secondary expandable sections. Cancelled bookings no longer show preparation prompts.

**Returns & handovers.** The queue explains the process and separates Due back, Items to receive, Handovers to review, Exceptions and Completed returns. Each detail page has three distinct panels: vehicle return, keys/equipment, and optional shift handover. The incoming receiver, missing items and exception owner are visible. Reconciliation is not offered while items are still physically missing. Later receipts preserve the original partial receipt and any original dispute.

## How Returns works

1. The assigned driver records actual vehicle return, odometer, fuel/charge, condition and the receiving worker. The expected return time alone proves nothing was received.
2. The return records which keys/equipment were actually received. If something is missing, the receiving worker adds a later receipt when it arrives. The original receipt remains intact, and the assigned exception owner records reconciliation.
3. A shift handover is optional. Create it only when another shift takes custody or responsibility. Its named incoming worker acknowledges it or records a dispute.

Passenger accountability and required medication-source obligations remain on the journey. A vehicle receipt or acknowledged shift handover cannot clear them automatically. Passenger completion does not silently release a Maintenance restriction or erase an equipment exception.

## Review paths

- **Requests:** right-click Alex as Mia to assess or edit the request. Switch to Rory for Taylor’s pending booking approval. The source booking decision remains separate from request assessment and Finance approval.
- **Planner:** Elliot starts ready for allocation. Choose a suitable vehicle and driver, check availability, then save. Choose Alex to see assessment first. Use Preview scenarios to inspect unknown, expired or conflicting readiness.
- **Charlie journey:** start as Mia to see who is needed. Switch to Sara and open the bounded medication handoff; its checkbox explicitly simulates a source result. Switch to Nia to complete the passenger journey.
- **Casey return:** the equipment is missing and the original handover was disputed. Ben can record the later physical receipt. Mia can then reconcile the exception. The original dispute remains visible.
- **Morgan booking:** Nia can complete a check, collect keys/equipment, depart, return, confirm the passenger and complete the journey. No shift handover is required by that normal flow.

The role selector and scenario controls are design tools, not proposed production controls. All records and action results are synthetic.

## Audit and verification

Three focused improvement passes covered (1) banners, menus and next-action ownership, (2) interactive allocation and draft/readiness recovery, and (3) returns separation, custody integrity, access states and desktop layout. A final connected driver-flow check covers preparation through completion and cancellation.

- **77 passing browser assertions:** 22 interaction/planning, 16 returns/recovery, 32 layout/access/runtime, and 7 connected-flow assertions. Individual results and full logs are included in `verification.json` and `verify-*.log`.
- **34 passing synthetic domain assertions:** including atomic rejection, scope, stale versions, unknown/conflicting readiness, independent source approval, immutable retry, medication guards, original-dispute preservation, later receipt/reconciliation, cancellation, approved vehicle site and assigned escort boundaries.
- **Zero TypeScript errors** in the preview or imported shared sources. The browser sweep captured no runtime page errors and only local static GET requests.
- Desktop widths **1024, 1280, 1440 and 1920** were checked. Journey actions remain above the fold; no page-wide horizontal overflow was observed. Completion was at y=392.75, height 42 at 1440×1000. The journey route/header overlap found during the first visual pass was corrected and remeasured.
- **175 prior frozen files checked unchanged:** v1 62, audit-v1 21, v2 92. `preservation.json` records zero mismatches.

Use `final-*.png` for the current reviewed layouts. `journey-review.png` and `planner-review.png` intentionally preserve early visual-review states, including the layout issue corrected before the final screenshots. `verified-*.png` captures submitted synthetic outcomes. Initial test-harness failures concerned picker scope, fixture names/receipt IDs, and waiting for a destination page; final logs contain the corrected outcome assertions.

These are preview checks. They do not certify the backend, live concurrency, production permissions, whole-application security or repository CI. Actual browser zoom remains unverified; window resizing is not claimed as zoom evidence.

## Remaining implementation gaps

1. **Scoped staff checkout/return authority.** Existing `VehicleBookingController` checkout/return actions require Fleet management authority. Driver assignment alone does not grant it. The preview demonstrates a proposed narrowly scoped staff custody handoff; turning that scenario off blocks it. An approved source/permission adapter is still needed.
2. **Canonical record linkage.** `ClientTransportBooking` owns demand, `FleetVehicleBooking` owns reservation/readiness, and `ResidentTransportJourneyService` owns the actual trip. Richer assessment/support/ownership fields and their durable linkage still require an approved mapping. This design does not create a second booking engine.
3. **Returned projection.** The visible returned-but-not-completed stage derives from observations. It is not approval to add a new journey status or bypass the canonical completion service’s medication/evidence guards.
4. **Durable custody and reconciliation.** Original/late receipts, actual parties, site exceptions and append-only decisions need exact canonical key-log/handover contracts. The preview does not persist a new custody store.
5. **Configuration and source integration.** Eligibility, buffers, required equipment/checks, approval routes, escalation ownership, live availability, server idempotency/versioning, uploads and notifications require their source contracts and real integration testing. The readiness check here is a controlled simulation tied to the visible proposal. No universal default approver or operating policy is selected.

Outings, Client/Site, My Day, vehicle profile/calendar, Maintenance and medication logistics remain bounded links to their existing owners. Their dialogs demonstrate context without claiming those full modules work in this preview.

## Source ownership and exact candidate

The build reuses actual repository PageHeader/meters/rail/search/buttons, EntityTable/menu/cells, status badges, dialogs, searchable selectors, WizardShell/review cards, evidence uploader primitives and shared date/time controls. Five preview TypeScript modules compose them; the build records 33 source inputs. No shared component, application route, model, schema, design guide or sibling task was edited.

Checkout baseline: `4ea64c547ed85a5b7504e59599db351f6eba7deb`. Actual metadata verifies `gpt-6-astra/xhigh`. Canonical Revision10 hash remains `c4837ab675f9dffdb6a8597636f49d5761da114e6c155dc08e6bb8a209d63fd0`.

Main’s read-only rules revision31 and register revision100 report PKG-02B completion at the same published `f7d517359da6ffdf90de2f259111fe5e8a1133f2` evaluated in v2, while retaining failed hosted CI and open operating acceptance. That update does not release Transport implementation. The PKG-05 correction brief, protected design references and canonical source hashes remain unchanged. No fresh remote publication, fetch, rebase, deployment or green-CI claim is made.

`FREEZE.txt`, `artifact-manifest.json`, `build-inputs.json`, `context-manifest.json`, `canonical-source-hashes.json` and `verification.json` identify this exact candidate, its served bundle, sources and evidence. Preserve it; create a new version for later corrections. Per the PKG-05 brief: **“Stop at explicit approval of the exact mockup.”** No application implementation or operational action is included.
