# PKG-04 — Main technical review

Owner: MAIN ASTRA. Revision: 2. Updated: 2026-09-27.

Decision: **Approved for integration — exact79ea01a561f7d2fa5affa592dc096f516d663635 on publisheda6b9fae6a73516548b02e64c41cf1fc94ce43f01.**

## Renewed exact-source decision

Both original findings below are closed by the same Astra/xhigh owner. Main reviewed correction6bf5738e5, reconciliationca0554a57 with published Transport, final test/evidencea5ca47609 and the tooling-base-only successor79ea01a56. Main independently confirmed no application/test/dependency/build-source differences betweena5ca47609 and79ea01a56; the successor retains the approved a6b9 lint correction and evidence. All36 application/test manifest hashes match the current source; application-scoped whitespace checks pass. Remaining untracked files are local frozen/mockup/runtime evidence, not additional staged application changes.

- **T04-01 closed:** the aggregate controller adds the canonical vehicle namespace after private busy-ID redaction. Individual source IDs and record links remain unchanged. The added backend regression checks two same-date WoF/check reminders, uniqueness and repeat-read stability plus unchanged individual calendars. Existing stricter foreign-reminder denial remains. This is reviewed source and owner-executed backend evidence, not a fresh Main backend run.
- **T04-02 closed:** canonical elapsed duration drives pure-move proposals; Auckland start occurrences resolve explicitly, nonexistent starts are rejected and both resulting offsets reach the existing review wizard. Resize remains distinct. The shared callback preserves Transport's fourth move/resize parameter and carries Fleet wall-time intent in the fifth. Main reviewed both consumers and the canonical booking source return-link reconciliation.
- Main independently executed the actual corrected callback and its actual helpers in [the new probe](PKG-04-main-corrected-calendar-probe.mjs): all eight from/onto spring/autumn cases in UTC/Auckland preserve elapsed duration. The original autumn reproduction now proposes01:30–04:30 and180minutes. Main separately passed **31 frontend tests in four files**,1.25s, local start17:46:47, covering Fleet/Transport time proposals, repeated/skipped hours and protected presses. No warnings were emitted in that run.
- The owner's final broader verification is **102 frontend tests/17 files**, **59 backend tests/1,430 assertions**, clean full types/scoped lint/PHP syntax,5m58 build and final actual-app browser evidence, including14 DST cases and saved/reviewed Undo. Main inspected the retained logs and reconciled source; it did not repeat the full build/browser/backend suite. The initial browser fixture timeout remains historical and the unchanged-app rerun is separately recorded.
- The imported Transport allocation test had omitted the required canonical client ID. Main independently reviewed the bounded test-only fix against49: supply that ID, add explicit missing-client denial, retain foreign-room denial and the full retry/reassignment assertions. Published application authorization is unchanged. This also explains the one Transport failure reported by PKG-03 on49; it is not a PKG-03 application regression and does not require a competing Transport fixture patch.

Main independently verified current local/remote main ata6b9fae6a and released the completed PKG-05 tooling slot in its publication record. **The existing PKG-04 Designer now has the exclusive next serial main/publication slot** for exact79ea01a56. It executes ordinary fast-forward/push under Stephan's verified existing request, captures/protects every dirty Main path, then returns exact refs/tree/source and integrated checks for Main publication verification. No reset/clean/stash/broad staging, operational migration or deployment. Any source/base movement returns for review.06A is awaiting final base reconciliation,06B/07 preparation and03 local-only review; none has an overlapping main slot. Sol remains archived, and read-only design rules/frozen versions remain protected. User acceptance and hosted CI conclusions are separate.

## Historical first review — findings and reproduction

Reviewed candidate: `25b1a8f18728fb908a39de1f1dc63a34c10e4950`, branch `codex/pkg04-fleet-completion`, checkout `C:/Users/steph/.codex/worktrees/1eb2/oblivionfindings`. Actual integrated base: `ba5bff2e8b6c22796369443f1cdac918039950dd`.

## Findings

### T04-01 — P2: aggregate calendar reuses per-vehicle reminder IDs

`VehicleCalendarController::fleetEvents()` (`app/Http/Controllers/FleetAssets/VehicleCalendarController.php:54–70`) appends each vehicle's calendar records and adds `vehicleId`, but only rewrites busy IDs. `VehicleCalendarService.php:543,554` emits `compliance:<kind>` and `check-due` per vehicle. Thus two vehicles with a WoF expiry or check reminder on the same day produce identical event IDs in the aggregate feed.

`fleet-calendar.tsx:302–324` preserves those IDs through `decorate()`. Shared calendar views use `key={e.id}` for sibling entries (`resources/js/pages/sites/calendar/_parts.tsx:1303,1609,1734,1813,1910,2096`). Multiple vehicles' reminders therefore collide in React reconciliation when filtering, refreshing or changing calendar scope. The separate `eventByKey` lookup uses both ID and vehicle ID, but does not repair the render keys. This is a direct source-contract finding; Main has not claimed a particular live missing-row reproduction.

Namespace aggregate event identities by vehicle, retaining canonical `recordId`, source links and the busy projection's privacy guarantees. Verify two vehicles with the same compliance kind and due date, and the same check-due date, across unfiltered/filtered/refresh states. Do not expose hidden booking IDs to solve uniqueness.

The owner independently confirmed this finding and began a bounded projection/test correction while Main finished the review. Accordingly, 25b1a8f18 is now historical reviewed source; the corrected candidate still needs exact-source reconciliation.

### T04-02 — P2: Month moves shorten or extend a booking across Auckland DST

`resources/js/pages/fleet-assets/vehicles/fleet-calendar.tsx:351–359` converts both canonical instants to Auckland wall strings, subtracts them as UTC wall-clock values, and calls `addLocalMinutes` with that difference. This loses the elapsed duration when the original booking spans a clock change, and adding a wall duration also needs care when the target crosses one.

Main executed the **actual submitted `move` callback**, extracted by TypeScript AST, together with its actual `toDatetimeLocal`, `keyDate` and `addLocalMinutes` helpers. The canonical booking `2027-04-04T01:30:00+13:00` → `2027-04-04T03:30:00+12:00` lasts **180 minutes**. Dragging it to 5 April proposes **01:30–03:30 (120 minutes)** in both UTC and Auckland browser timezones, rather than retaining three hours to04:30. [Read-only probe](PKG-04-main-calendar-probe.mjs), [recorded output and source hashes](PKG-04-main-calendar-probe.json).

Use canonical source instants for elapsed duration and a deliberate Auckland conversion for the proposed start/end. Preserve explicit ambiguity/nonexistent-time handling and the reviewed-save gate. Verify moves from and onto both clock-change periods, across browser timezones, and the reviewed Undo. This finding concerns a pure move, not an intentional resize. Main did not mutate a live booking.

## Independent verification and limits

- At initial inspection HEAD matched25b1a8f18, the application/test paths were clean, and **all35 submitted source hashes matched**. The application-scoped diff check againstba5bff2e8 passed.
- Main independently ran seven focused frontend files: **19 tests passed**, Vitest4.1.5, 5.63s, start17:03:31. These cover Fleet register/map/evidence/calendar time/export, booking time and protected calendar press. The index test emitted mocked-link `preserveState`/`preserveScroll` and `act` warnings; this is not a zero-warning result.
- The owner supplies a broader79-test/15-file frontend pass,44 backend tests/796 assertions, full TypeScript/lint/build and actual Laravel browser evidence. Main inspected their final packet and logs but did not rerun the whole backend suite or production build. The passing focused tests do not cover the two findings above.
- Main traced aggregate scope/privacy, busy redaction, permitted-position projection, map output escaping, stale/failure handling, canonical source loading, booking update field preservation, time proposals and shared protected gestures. The current-main Overview position method and stricter foreign-reminder privacy are retained. This review is substantive and bounded, not a whole-system certification.
- Base hosted CI is recorded as failing in the owner's publication packet; Main has not independently reassessed that run here and does not claim green CI, deployment, load validation or operational acceptance.

## Disposition

The same Astra Extra High Designer corrects both findings, supplies an exact new commit/base with affected regression/browser evidence, and retains application custody. Sol remains archived; no new worker, guide change, policy expansion or Main application write. Main's renewed **Approved for integration** and a serial publication slot remain required before the already user-authorised local-main/GitHub publication. Preserve Main's dirty programme records and all unrelated work. PKG-03's separately authorised local-only work must not be swept into that remote push.
