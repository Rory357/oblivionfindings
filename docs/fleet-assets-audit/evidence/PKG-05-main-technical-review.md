# PKG-05 — Main technical review

Owner: MAIN ASTRA. Revision: 2. Updated: 2026-09-27.

## Current decision — Approved for integration

Exact corrected candidate **`49e0be5b1c1716aeb4e681529bb71fdce2a7abc0`**, actual base **`ba5bff2e8b6c22796369443f1cdac918039950dd`**, same branch/owner/checkout. Main reviewed the entire correction diff againsta285d4fe7, the affected current source paths, new regressions and final build/browser packet. **T05-01, T05-02 and T05-03 are addressed for this candidate.** The previous review below is preserved as history.

- Linked Fleet source pages now render a current-authority Transport handoff and suppress incompatible legacy actions. Unlinked bookings retain their previous actions. The backend independently scopes the handoff URL through accessible Transport requests/bookings.
- The bounded time-only command obtains untouched fields from locked canonical booking/request records, preserving notes, purpose, passengers, driver and key arrangements. Planner uses canonical booking fields where one exists. Version, UUID/fingerprint, readiness and conflict checks remain in place; reviewed Undo uses current records.
- Calendar moves use canonical instant duration, deliberate Auckland gap/fold resolution and explicit offsets. The shared time-grid callback distinguishes move from resize through an optional backward-compatible argument. Current record instants reach the corrected helper; Undo compares instants rather than lossy wall strings.
- Main independently reran **38 tests across3 frontend files**,4.13s, start17:17:10, including the three-zone DST cases. Scoped source/config diff checks pass, the checkout is clean at49e0be5b1, and the base's `tests/TestCase.php` blob remains identical. [Approved source hashes](PKG-05-main-approved-source-hashes.json) bind the application/test/config scope.
- Main inspected the owner's three targeted backend result logs (**71,9,14 assertions**) and their actual test cases, plus final build/browser results. These are owner executions, not Main reruns. The packet distinguishes staged65-test/1,317-assertion backend evidence from a fresh full rerun and explicitly excludes two concurrent subprocess cases. Main does not claim whole-repository TypeScript/CI, concurrency certification or deployment.

Main independently verified local HEAD and remote `refs/heads/main` both atba5bff2e8 immediately before releasing the slot. **PKG-05 owns the next exclusive integration/publication slot.** Its existing Designer may fast-forward local main to this exact candidate and perform the already user-authorised ordinary GitHub-main push, preserving all dirty Main programme files and unrelated artifacts. No force push, reset/clean, broad stash or inclusion of unapproved sibling source. If the base advances or source changes, pause integration for reconciliation rather than silently expanding this approval. Report exact local/remote results and integrated checks for Main's publication verification.

PKG-04 and06A remain Changes requested;06B is preparing. PKG-03 remains local-only and has no overlapping main slot. Operational migrations/deployment and Stephan's final package acceptance remain separate. No further user confirmation is required for the already-authorised publication within this exact gate.

## Historical first review

Decision: **Changes requested — not approved for integration.**

Candidate: `a285d4fe7dd1139e02a9bfb48c227946b754e199`, branch `codex/pkg-05-transport-workspace`, checkout `C:/Users/steph/.codex/worktrees/b9b9/oblivionfindings`.

## Findings

### T05-01 — P1: existing Fleet booking actions fail for linked Transport bookings

`app/Services/Fleet/TransportRequestService.php:282–283` now requires a UUID retry key and expected booking version for every linked booking decision/custody command. The existing canonical booking page still posts Approve without a body (`resources/js/pages/fleet-assets/bookings/show.tsx:459–462`), Reject with only its reason, Cancel without a body, and checkout/return forms without either required field (`:92–97`, `:510`, `:574`, `:676`, `:713`). `VehicleBookingController` invokes this new guard for all five operations, including ordinary Inertia requests. An otherwise authorised user opening a linked booking from the Fleet register/source link gets validation failures instead of approval, rejection, checkout, return or cancellation. The new Transport workspace working does not repair these existing entry points.

Preserve version/retry/readiness safeguards. Connect the existing source page to the shared current-record command/wizard flow, or provide an explicit authorised handoff to it; do not leave enabled actions that cannot satisfy the new contract. Verify the actual Fleet source-page actions for a linked booking, including stale/repeated requests and current key requirements, plus an unlinked-booking regression. This finding is a direct source-path comparison, not a claimed live mutation by Main.

### T05-02 — P2: a time-only reschedule clears canonical booking notes

The new payload in `resources/js/components/fleet-assets/transport/reschedule-dialog.tsx:62–91` submits a full booking update but omits `notes`. `VehicleBookingController.php:704–710` explicitly fills omitted notes with null. Thus a valid linked booking containing notes loses them when the user reviews a time move, and reviewed Undo also omits the original notes. The new planner payload has the same omission. Other editable booking fields are taken from the request projection rather than necessarily the current canonical booking, so the correction should check all unrelated fields, not merely add a hard-coded note value.

Build the time proposal from the authorised current source record or use a bounded source-owned time-change command that preserves untouched fields. Cover a linked booking with non-empty notes and distinguishable original booking fields; move and reviewed Undo must change only the reviewed fields while retaining required approval/version behaviour. Main traced the payload and controller; no destructive reproduction was run against a live database.

### T05-03 — P2: Month dragging changes duration across Auckland DST in another browser timezone

`resources/js/components/fleet-assets/transport/calendar.tsx` converts canonical instants to Auckland wall strings before decorating them. `calendar-actions.ts:36–40` then derives duration from those browser-local Date objects. The result depends on the browser timezone rather than the original source instants.

Main executed the actual submitted helper through [a read-only probe](PKG-05-main-calendar-duration-probe.mjs). The canonical booking `2027-04-04T01:30:00+13:00` → `2027-04-04T03:30:00+12:00` lasts 180 minutes. Dragging it to 5 April yields **01:30–03:30 in a UTC browser (120 minutes)** but **01:30–04:30 in an Auckland browser (180 minutes)**. Actual helper SHA256: `e574e1cd09933d31dca20de3f1b6c60e2fead63dfbc87c738f784ee5e30e3c7a`. The source booking instants are available on the current record; retain their actual duration independently of browser-local DST, and retain explicit handling of ambiguous/nonexistent Auckland wall times. Add regression coverage across browser zones and clock changes, not just ordinary October dates.

## Base, scope and independent checks

- The requested comparison base `d5eff35df770fd2dce582d853d43f2baa4a2de53` is an ancestor, but the actual merge parents are Transport source `f1330fb0d` and **`ba5bff2e8b6c22796369443f1cdac918039950dd`**. The latter is the subsequent Overview presentation correction. Main separately inspected that ancestry: its two dashboard files account for inherited differences, not new Transport edits. There are47 application/test paths against d5eff35df and45 against the actual merged parent ba5bff2e8. Correct the final packet's source/base mapping.
- `tests/TestCase.php` has the identical Git blob `38496aeb7b6621f62d820791717f58bb054beb7a` in the candidate and ba5bff2e8. The reported timeout conflict resolution preserves the actual base implementation.
- Main independently ran the three focused frontend files: **34 tests passed**, Vitest4.1.5, 4.48s, local start16:36:13. The initial sandbox attempt failed before test startup because it could not read the external checkout configuration; the permitted retry passed. No application source was edited.
- Application-scoped `git diff --check` passed. The whole packet reports whitespace in historical frozen preview/evidence files; Main did not rewrite frozen material to silence that output.
- The DST probe above reproduces a failure outside the existing34 tests. The source-path findings above are not covered by that passing frontend suite.
- Main inspected canonical scope/ownership, linked request/journey/custody mutations, source booking integration, the additive migration, presenter/export paths and the new calendar/modal/planner flows. This is a substantive pre-integration review with blocking findings, not full-system certification or a complete rerun of Designer QA.
- At the end of this source review HEAD remained exact a285d4fe7 and no application/config/migration/test differences from that commit were observed. Only the owner's verification evidence was changing. Its final production build and browser correction checks were still pending in the submitted handoff; Main does not claim they passed. Earlier backend62 distinct tests/1,223 assertions and later170-assertion regression remain owner-supplied evidence, with the two concurrent subprocess tests excluded.

## Disposition

The same Transport owner corrects these issues in its current checkout, reruns affected source-page/Transport/DST checks and supplies the exact resulting commit/base plus final QA. No new implementer, guide edit, policy expansion or Main application write is released. Preserve single-organisation roles, approved sites, canonical ownership and privacy. Main's renewed **Approved for integration** remains required before local-main/GitHub publication under Revision10 §14F; the user's request to publish is retained and does not require asking again once the gate is satisfied.
