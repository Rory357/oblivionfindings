# PKG-05 v1 — Transport allocation and practical handover

**Status: frozen design candidate for Stephan’s exact approval. No implementation authorised by this artifact.**

[Open the clickable desktop mockup](http://127.0.0.1:4395/#/fleet-assets/transports). The blue strip identifies PKG-05 v1 and exposes the synthetic scenario selector. Reload resets the specimen. All people, dates, identifiers, eligibility assertions and observations are fictional.

## Review route

1. Open **Library visit · TR-1042** from the demand queue. Assess its time window and permitted support needs. The vehicle and driver remain unassigned.
2. **Allocate resources**: choose Koru, Nia as driver, Sara as escort and the requested kit. Review and link the one booking BK-208 and journey J-608. Open the linked booking.
3. **Prepare for departure**: submit the original illustrative check, confirm passenger preparation, identify the actual giver/receiver/site/time, confirm physical keys and equipment, then record checkout K-771. Source checks are referenced, not entered three times.
4. **Record return**: retain actual observations and receipt RC-311. Confirm source passenger return and received equipment separately. Prepare a shift handover; the incoming recipient acknowledges or disputes HO-87.
5. Open the journey and complete only after its source obligations are resolved. Return receipt, accepted shift custody, journey completion and independent vehicle release remain distinct.

Use **Request transport** to demonstrate demand before resources are known. The shared calendar/time picker, searchable selectors, review and temporary draft resume work. My Day, Site and Client provide bounded entry/return examples. **Record outcome** distinguishes client choice, requester cancellation and unavailable transport. Cancellation during a live checkout preserves the actual return action and unresolved obligations.

## Exact artifact and isolation

- Review identity: **PKG-05 v1**, with the definitive SHA-256 set in `artifact-manifest.json` and its digest in `FREEZE.txt`. Approve that named candidate, not a later edited preview.
- Checkout: `C:/Users/steph/.codex/worktrees/b9b9/oblivionfindings`, detached baseline `4ea64c547ed85a5b7504e59599db351f6eba7deb`. Remote main was checked at setup and matched this published seven-entry navigation baseline. No later publication or full CI result is inferred.
- Actual effective metadata was verified as `gpt-6-astra` / `xhigh`; this real chat was pinned. Exact provenance is in `context-manifest.json`.
- Canonical Revision 10 was verified at SHA-256 `C4837AB675F9DFFDB6A8597636F49D5761DA114E6C155DC08E6BB8A209D63FD0`. Current absolute-path Main context and read-only design-guide hashes are in the context manifest.
- All authored changes are under this PKG-05 preview directory. Application, shared component, controller, route, schema, guide and Main/sibling files were not edited. No database, live notification, medication, Finance, tracker, deployment, push or merge action occurred. No worker or additional agent session was created.
- The loopback server serves only static GET/HEAD requests on port 4395. The bundled assets are self-contained. Its response identifies PKG-05 v1 and the baseline. Keep the server available while reviewing; `node serve.mjs` restarts it from this folder if necessary.

## Canonical ownership and reuse

**Demand and client context.** `app/Models/ClientTransportBooking.php` and `app/Http/Controllers/Operations/ClientTransportBookingController.php` own current client transport requests and client-level authorization. Existing statuses are requested, confirmed, completed and cancelled; nullable vehicle/driver descriptions already allow unassigned demand. This preview’s assessment/awaiting-allocation/not-fulfilled display states and demand-to-booking linkage are proposed design behavior. Inspection did not establish an existing canonical request-to-`FleetVehicleBooking` foreign-key contract. A future approved implementation must resolve that additive linkage and outcome mapping; the preview is not proof those fields exist.

**Reservation, vehicle suitability and driver assignment.** `FleetVehicleBooking`, `VehicleBookingController`, `VehicleBookingAccessService` and `VehicleReadinessService` remain the source. Existing booking request keys, fingerprints, lock versions, current source assessments and explicit checkout/return transitions must be reused. The preview demonstrates stale/conflicting/lost-response UX with fixed identifiers; it does not implement concurrency. PKG-04 owns Fleet-wide register/calendar design; PKG-02B owns the vehicle profile. Their bounded context is linked, not redesigned.

**Actual passenger journey and medication logistics.** `ResidentTransportJourneyService`, `ResidentTransportJourneyScope`, `ResidentTransportController`, existing transport pages and `FleetOuting`/`FleetOutingResident` with `OutingController` retain ownership. Journey booking references, create/retry behavior, precheck evidence and unresolved completion obligations already exist. eMAR/clinical authority, medication-management and administration permissions, current source orders, presence/competency and controlled-witness safeguards stay in their owning services. This preview exposes no medication names, doses, prescribing or administration UI and sends nothing to a map/provider.

**Checks and evidence.** `FleetChecklistRun`, its versioned template/amendments, `ChecklistController` and `VehicleEvidenceController` are the canonical source. CHK-441 is one fictional submitted check referenced by the booking/journey/handover. The one checkbox is a placeholder for an approved check template, not an adopted operating checklist. FileDropzone/StagedFileCard are imported from published UI source; EV-91/EV-92 and all submission/version results are explicitly simulated.

**Practical custody and return.** `FleetKeyLog`, booking checkout/return observations and their site provenance retain source ownership. `FleetShiftHandover` and `HandoverController` own recipient acceptance/dispute. The design records actual parties and observations, then retains the outgoing history when the incoming recipient disputes. A return receipt never silently records acceptance, passenger return or operational release.

**Maintenance, restrictions and Finance.** The existing Maintenance report/work/restriction and readiness paths own failed checks/damage, repair, retest and release. M-318 is a fictional linked source reference with failed-response/retry/deduplication UX. Independent safety release and financial approval are separate source decisions. The preview does not adopt any authority, repair policy or financial default.

**Ownership, tasks and contextual entry.** Existing shared-task projection and Client/Site/My Day remain the destination for owned exceptions. This mockup demonstrates an accountable owner and illustrative target, without introducing another task queue, roster, care workspace or new task persistence.

**Navigation and desktop primitives.** `resources/js/lib/fleet-navigation.ts` is imported directly and supplies Overview, Fleet, Assets, Maintenance, Maps & boundaries, Reports and Settings. PageHeader/meters/rail, EntityTable/cards/context menu, status/button/input, WizardShell/review/success, Dialog/Popover/Command, FileDropzone, and the published Maintenance DateTimeField/calendar/clock picker are reused from this checkout. The app token stylesheet, including its existing date/time styles, is compiled read-only. `build-inputs.json` hashes the 33 reused JS/TS source inputs; `canonical-source-hashes.json` adds domain/CSS contract hashes. The surrounding shell and transport composition are preview-owned.

**Single operating organisation.** Authorization boundaries are roles/permissions, approved sites, source ownership, direct-object denial and privacy. Legacy organisation columns are untouched. Limited labels/search/counts and denied direct-record content are demonstrated with synthetic roles, not verified application authorization.

## Browser evidence

The following checks ran in a real headed Chromium browser against this exact local preview. Scenario checks use the supported reduced-motion preference for deterministic captures. A normal-motion request dialog was also inspected successfully. An earlier background animation run stalled; it was discarded, the owned QA browser was reset, and the final checks below passed. No application test-suite or live-service claim is made.

- `qa-normal.log`: complete assessment → allocation → original check → checkout → return receipt → recipient acknowledgement → journey completion; repeated actions resolve to the original fictional references.
- `qa-scenarios.log`: empty/loading/load failure and retry; denied direct record without passenger/destination/title leakage; limited passenger labels/search; stale allocation and explicit refresh; persistent allocation conflict; lost allocation response recovery; directory failure recovery; missing driver/escort/equipment; missing operating configuration.
- `qa-custody.log`: missing keys/equipment and unexpected holder/site block checkout; failed check links Maintenance after retry without duplicate; partial return and unresolved medication retain receipt but block completion; lost return response recovers RC-311; recipient dispute has an owned exception; local evidence failure/retry/version preserves the original.
- `qa-desktop.log`: 1280×800, 1440×1000 and 1920×1080 without page-level horizontal overflow; My Day/Site/Client return links; searchable picker by keyboard; Escape closes only the nested picker; forward/reverse focus trapped in wizard; close returns focus to Request transport; draft resume; shared date/time picker; distinct client-choice/requester/unavailable outcomes; clearing filters preserves limited context.
- `qa-outstanding.log`: cancellation after departure keeps the live return path and medication completion block; RC-311 remains linked.
- `qa-environment.log`: normal-motion dialog reaches opacity 1; no page errors in the probe. Ctrl+zoom was attempted but viewport/DPR did not change, so **actual browser zoom is not verified**. Desktop resize is not presented as zoom.
- `qa-console.log`: zero browser errors or warnings in the final QA browser. `qa-network.log`: static local GET requests only, no operational API calls. `qa-types.json`: zero scoped or imported TypeScript errors.

The retained `verify-*.js` files are browser verification recipes, not application tests or added production code. Logs show the actual assertions and fixture actions.

## Screenshot index

- `01-demand-1440.png`: approved-site demand queue and unassigned demand.
- `02-request-assessed-1440.png`: assessed demand awaiting resources.
- `03-allocation-review-1440.png`: one selected vehicle, separate driver/escort, equipment and one-link review.
- `04-checkout-review-1440.png`: actual custody parties/site/time and original check.
- `05-journey-in-use-1440.png`: active journey with original references and next return action.
- `06-return-review-1440.png`: observed receipt and separate source confirmations.
- `07-handover-acknowledged-1440.png`: actual outgoing holder and accepted incoming custody.
- `08-completed-history-1440.png`: connected history through guarded completion.
- `09-direct-record-denied.png`, `10-stale-allocation.png`, `11-allocation-conflict.png`, `12-search-recovered-selection.png`, `13-unconfigured-allocation.png`: privacy and allocation recovery.
- `14-maintenance-link-failed.png`, `15-disputed-handover.png`, `16-evidence-failure.png`, `17-evidence-version.png`: issue/evidence/custody recovery.
- `18-request-review.png`, `19-normal-motion-request.png`, `20-cancelled-demand-live-obligations.png`: request entry and outstanding-work preservation.
- `state-*.png`, `blocked-*.png`, `desktop-*.png`: remaining state and desktop-size evidence. `debug-allocation.png` is excluded from the frozen approval set.

All screenshot files are in `screenshots/`; exact per-file hashes are in the artifact manifest. The main demand, allocation, active journey and acknowledged handover screenshots were visually inspected, as were the request and narrower desktop captures.

## Limits and decisions before any implementation

This is one editable synthetic specimen plus bounded secondary queue/source examples. Creating a request replaces the active specimen with TR-1044; the mockup is not a general multi-record data store. Source links show scoped handoff explanations rather than running the real destination system. Draft state and staged files are temporary browser memory, and the draft controls are demonstrations rather than durable draft/version management. The scenario selector resets the active fixture. Request entry defaults and all named resources are illustrative.

The production demand-to-booking/journey linkage, precise assessment and not-fulfilled representation, cancellation coordination across source records, authenticated recipient transitions, concurrent/replayed command behavior, and permitted cross-site demand projection need an implementation contract after exact design approval. Existing source safeguards must remain authoritative.

Required vehicle/support checks, equipment content, staffing eligibility, capacity/accessibility assessment, booking buffers, approval authority, escalation owner/target rules and evidence type/retention/upload limits need approved operating configuration. “Suitable” and the sample authority row in the normal fixture are fictional source results; “No policy configured” demonstrates blocking rather than inventing defaults. There is no production-ready, full accessibility, screen-reader, actual zoom, mobile/tablet, offline, CI or deployment claim.

## Approval gate

Please approve **PKG-05 v1 — Transport allocation and practical handover**, identified by `FREEZE.txt` / `artifact-manifest.json`, or specify changes. The [PKG-05 Designer handoff](C:/Users/steph/Herd/oblivionfindings/docs/fleet-assets-audit/handoffs/PKG-05-DESIGNER.md) explicitly requires: **“Stop at explicit approval of the exact mockup.”** This candidate does not start implementation, close any earlier package, waive later gates or request a routine Main acknowledgement.
