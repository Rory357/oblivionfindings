# Rostering → Clients/Care — production-readiness plan

> **⚠️ SUPERSEDED (2026-06-16).** The mobile `/operations/clients/{client}/care`
> page has been **retired** — this is a web-only app. The URL now 302-redirects to
> the full client profile (`operations.clients.show`); all former callers point at
> the profile (eMAR → `?tab=mar`, my-day "care plan" → `?tab=care_plans`), and the
> `ClientCareController` plus its PRN endpoint are removed. The "do not unify
> show/care" guidance below no longer applies. Retained for historical context only.
>
> Reference doc only. No code changes. Mirrors the structure of
> [`docs/job-board-readiness-plan.md`](job-board-readiness-plan.md) and
> [`docs/emar-meds-readiness-plan.md`](emar-meds-readiness-plan.md).

## Verdict

**Small targeted work.** The frontline care surface
(`/operations/clients/{client}/care`) is intentionally a mobile/card page
and that is correct — it's not the gap. The gap the audit note flagged is
the **handoff** between rostering and that page: a worker who taps a
rostered shift on `/my-day` or `/operations/shifts/{shift}` cannot reach the
client's safety ribbon, conditions, or PRN flow without leaving the shift
context. Combine that with **zero feature/HTTP tests on
`ClientCareController`** and a couple of small correctness bugs in
`recordPrn`, and the result is "Partial" rather than "Ready."

**No redesign required, no schema changes required.** The work is one
in-controller bug fix, one stale-shift attribution guard, two small care-view
entry points (shift detail and `/my-day` shift cards), a minimal safety summary
on the shift surface, a single feature test file, and a Playwright smoke spec.

---

## 1. Why the audit note was probably raised

The note: *"Rostering — Clients/Care: Partial — Admin/client ops broad;
frontline care page is mobile-card focused."*

What it almost certainly means after reading the code:

- **The admin client surface is broad.** `operations.clients.show` covers
  portal users, gallery, timeline, funding, service agreements, personal
  assets, transport, compliance, etc. (`resources/js/pages/operations/clients/show.tsx`,
  ~11k lines). The reviewer saw richness on the admin side.
- **The frontline care page is mobile/card.** `operations.clients.care`
  (`care.tsx:1-538`) is the deliberate consolidated worker landing — safety
  ribbon, PRN sheet, conditions, risks, contacts, deep-links. This is *good*
  and intended (`ClientCareController.php:18-30`). The reviewer wasn't
  flagging the design.
- **What the reviewer noticed but didn't articulate** is that
  `/operations/clients/{client}/care` is essentially **invisible from the
  rostering workflow**. A grep across the frontend shows the route is linked
  from exactly two places:
  - `resources/js/pages/operations/clients/index.tsx:225,251` ("Open care
    view" / "Care view" buttons on the clients list).
  - `resources/js/pages/my-day/index.tsx:841` (the meds-due list — the
    client *name* in a med row links to `/care`, but only when there is a
    med due).

  It is **not linked from**:
  - The shift detail page (`operations/shifts/show.tsx`) — the canonical
    place a worker lands when they tap a rostered shift. Workers see the
    client name (read-only) and tabs for medications/observations/forms,
    but no safety ribbon and no jump into the care view.
  - The `/my-day` shift cards (lines 659, 1131) — they link to
    `/my-roster#shift-{id}`, not `/operations/clients/{id}/care`.
  - The rostering grid manager view (`rostering/index.tsx`).
  - The admin client show page.
  - The sidebar.

So the "Partial" rating reflects a workflow seam, not a UI deficiency. A
worker cannot reliably get from a rostered shift to "what do I need to know
about this client right now" without backing out to `/operations/clients`
and re-finding them. That is exactly the gap the audit note is pointing at.

There are also two correctness bugs in `ClientCareController` and an
absence of tests that would block production for a healthcare module
regardless of the workflow gap (§3).

---

## 2. Acceptance criteria — "production-ready enough"

For the Rostering → Clients/Care workflow to clear the bar:

1. **One-tap traversal from a rostered shift to the care view.** From
   `operations/shifts/{shift}` (and from the `/my-day` shift card menu),
   the worker can reach `/operations/clients/{client}/care` in one click.
2. **Safety surfaces above the fold on the shift detail page.** A worker
   on `operations/shifts/{shift}` sees the canonical `ClientSafetyRibbon`
   for that shift's client before any tab content, so allergies / critical
   risks / care-critical flags are visible without navigation.
3. **Worker can record a PRN from the rostered-shift context.** Either via
   the existing care-view link (preferred) or a clearly labelled CTA on the
   shift detail. No new write paths — `EnhancedMarService::recordAdministration`
   stays the only path to a `medication_administration` row.
4. **`ClientCareController` has feature tests.** `show` (3 paths: assigned
   worker, manager, unauthorised) and `recordPrn` (5 paths: happy, validation,
   wrong-client medication, non-PRN medication, service failure mapping).
5. **PRN error mapping is field-accurate.** When
   `EnhancedMarService::recordAdministration` returns an over-limit /
   safety-block / stock failure, the error renders against the PRN sheet's
   medication field key (`client_medication_id`), not the worker's `reason`
   text. (`ClientCareController.php:208`.)
6. **Active-shift inference is bounded without breaking overnight care.**
   `activeShiftFor` will not match an unbounded stale shift. Prefer the
   current scheduled shift window plus a reasonable grace period; if the
   implementation uses a simple hour cap, make it configurable or explicitly
   test sleepover/overnight shifts so legitimate long shifts still link
   correctly. (`ClientCareController.php:272-289`.)
7. **One Playwright smoke spec** covers care page load + ribbon visible +
   PRN sheet opens, against a fixture client with one PRN med and one
   active risk. Path: `tests/e2e/operations-clients-care.spec.ts`.
8. **Empty states are visually correct.** A client with no PRN meds, no
   conditions, no risks, no contacts renders without empty cards stacking
   ungracefully. The "Follow-up needed" placeholder card stays — it's an
   intentional extension point — but isn't surfaced to permissions that
   shouldn't see follow-ups.
9. **No new migrations, no schema changes, no new permissions.** Existing
   `ClientPolicy::view` and the `medications.administer.record | clients.update`
   gate stay as-is.
10. **Existing tests continue to pass without modification:**
    `tests/Feature/Operations/ClientMedicalAdministrationIdempotencyTest.php`,
    `tests/Feature/MedicationControllerTest.php`,
    `tests/Feature/Rostering/*`, and the e2e specs under
    `tests/e2e/operations-rostering-*.spec.ts`.

---

## 3. Concrete gaps (verifiable from the codebase)

| # | Gap | Evidence | Class |
|---|-----|----------|-------|
| G1 | `ClientCareController` has zero HTTP / feature tests. The auth gate, validation, null-shift marker, cross-client guard, and service-error mapping are all untested. | `tests/**/ClientCare*` returns no files; `tests/Feature/Operations/` directory listing. | **Must Fix** |
| G2 | PRN error mapping always lands on the `reason` field, even for over-limit / stock / safety blocks. Worker sees an error like *"PRN over daily max"* attached to their reason text. | `app/Http/Controllers/Operations/ClientCareController.php:207-211`: `back()->withErrors(['reason' => $result['error']])`. | **Must Fix** |
| G3 | `activeShiftFor` matches the latest shift with `actual_starts_at IS NOT NULL` and `actual_ends_at IS NULL`, with no upper bound. A worker who forgot to clock out yesterday will have today's care-page PRN attributed to yesterday's shift. | `app/Http/Controllers/Operations/ClientCareController.php:272-289`. No current-window or grace-period bound around `actual_starts_at` / scheduled shift times. | **Must Fix** |
| G4 | The shift detail page does not link to or render the client safety ribbon. A worker tapping a rostered shift sees the client name only — no allergies, no critical risks, no jump into the care view. | `resources/js/pages/operations/shifts/show.tsx` does not import `ClientSafetyRibbon` or `ClientSafetyPayload`; no link to `/operations/clients/{id}/care`. | **Must Fix** |
| G5 | `/my-day` shift cards link to `/my-roster#shift-{id}` only. There is no path from the day-of-shift card to the client care surface, despite `/my-day` being the worker's main day-of-shift entry point. | `resources/js/pages/my-day/index.tsx:661,1133`. | **Must Fix** |
| G6 | No Playwright smoke spec for the care page. There are specs for rostering (publish, suggestions, performance, a11y) but `tests/e2e/operations-clients-care*.spec.ts` does not exist. | `tests/e2e/` directory listing. | **Must Fix** |
| G7 | The "Follow-up needed" card on the care page is a permanent placeholder shown to every viewer regardless of permission. Cosmetic, not a safety issue, but pollutes the worker view today and risks misleading them when the real follow-up queue ships. | `resources/js/pages/operations/clients/care.tsx:481-492`. | **Should Fix** |
| G8 | Manager-side: the rostering grid (`rostering/index.tsx`) does not link from a shift cell to the client care surface. Managers triaging coverage have no quick context jump to "what does this client need." | `resources/js/pages/operations/rostering/index.tsx:460-495` (`shifts.map`); no `/operations/clients/{id}/care` link. | **Defer** |
| G9 | The `clients.update` fallback inside the route guard for `POST /operations/clients/{client}/care/prn` allows admin users with no medication permission to record a PRN. Mirrors the same fallback in `WorkerMedsController` so it is *consistent*, but it should be documented and reviewed before production. | `app/Http/Controllers/Operations/ClientCareController.php:147-150`; matches the route definition's middleware on the meds path. | **Should Fix (review only)** |
| G10 | Audit row does not record the launch point for a PRN given via the care page when the worker IS clocked in for that client. The "[PRN from client page — no active shift]" marker exists for the no-shift case (controller line 187) but in-shift PRNs from `/care` look identical to in-shift PRNs from `/meds/today`. Reporting cannot distinguish the two. | `app/Http/Controllers/Operations/ClientCareController.php:183-191`. | **Defer** |
| G11 | Care page renders three risk-related boxes when a client has no risks AND no contacts AND no conditions: empty Conditions, empty Risks, no contacts card (correctly hidden), and the always-on Follow-up placeholder. Visually noisy on a fresh client. | `resources/js/pages/operations/clients/care.tsx:296-364, 367-431, 481-492`. | **Defer** |
| G12 | `replacementQueue` cards in the rostering grid show client first/last name verbatim to managers (`RosteringController.php:307-332`). For NZSL clients this is intentional manager visibility — flagged here only so it isn't confused with the job-board sensitivity already addressed in `docs/job-board-readiness-plan.md`. | `app/Http/Controllers/RosteringController.php:324`. | **Out of scope (manager surface, by design).** |

---

## 4. Classification

### Must Fix (blocks production for this workflow)

- **G1** — feature tests for `ClientCareController`.
- **G2** — PRN error mapping field accuracy.
- **G3** — `activeShiftFor` stale-shift attribution bound.
- **G4** — Safety ribbon + Care-view link on shift detail.
- **G5** — `/my-day` shift card secondary action that opens the care view.
- **G6** — Playwright smoke spec for care page.

### Should Fix (do before going wider, but not a hard blocker)

- **G7** — Permission-gate the "Follow-up needed" placeholder.
- **G9** — Permission audit / doc note on the `clients.update` PRN
  fallback. Almost certainly a no-op change; we just want it on the record.

### Defer (post-production)

- **G8** — Manager rostering-grid → care jump.
- **G10** — Per-launch-point audit breadcrumb on PRN.
- **G11** — Empty-state visual cleanup on care page.
- **G12** — Manager replacementQueue privacy is by design and overlaps
  with the job-board plan; no action here.

---

## 5. Smallest implementation approach for each Must Fix

### M1 — Feature tests for `ClientCareController` (G1)

- **New file:** `tests/Feature/Operations/ClientCareControllerTest.php`
  (Pest-style, follows the same convention as
  `tests/Feature/Operations/JobBoardControllerTest.php`).
- **Cases (11):**
  1. `show returns the care page for an assigned support worker`.
  2. `show returns the care page for a manager with clients.viewAny`.
  3. `show 403s for a user without view permission for the client`.
  4. `show payload includes safety, conditions, active_risks, prn_medications,
      emergency_contacts, can.record_prn, can.view_medical, links`.
  5. `recordPrn happy-path delegates to EnhancedMarService and redirects
      back with a success flash`.
  6. `recordPrn validation: missing reason returns 422`.
  7. `recordPrn refuses a medication that belongs to a different client (404)`.
  8. `recordPrn refuses a medication that is not PRN (422)`.
  9. `recordPrn appends the "[PRN from client page — no active shift]"
      marker when the worker has no clocked-in shift for this client`.
 10. `recordPrn maps a service failure (e.g. over-limit) onto
      client_medication_id, not the reason field` *(reflects the M2 fix
      below)*.
 11. `recordPrn does not link a PRN to a stale open shift, while still
      preserving a legitimate overnight/sleepover shift within the accepted
      window` *(reflects the M3 fix below)*.
- **Fixture pattern:** seed an org, a manager, a worker, a client; create a
  PRN `ClientMedication` and a non-PRN one, plus an active risk. Reuse the
  same factory helpers used in `MedicationControllerTest.php`.
- **No mocks of `EnhancedMarService`** for cases 5 / 9 — call through so we
  catch regressions in the integration surface. Mock only for case 10
  (force `['success' => false, 'error' => '...']`).

### M2 — PRN error field mapping (G2)

- **File:** `app/Http/Controllers/Operations/ClientCareController.php:207-211`.
- **Change:** when `recordAdministration` returns `success=false`, inspect
  `$result['error_field'] ?? null` and route the message to the correct
  field; default to `client_medication_id` (not `reason`) for unknown
  failures because the worker's free-text is the *least* likely cause of a
  service rejection. `client_medication_id` is the field name the shared PRN
  sheet already reads (`form.errors.client_medication_id`).
- **Service-side touch:** `EnhancedMarService::recordAdministration`
  returns an array. If `error_field` isn't already part of the contract,
  add a single optional key (defaults absent), no behavioural change to
  callers that don't read it. The eMAR plan already touches this service —
  coordinate by adding to the same plan's commit if it lands in the same
  release; otherwise this PR can add only the read side and default to
  `client_medication_id`.
- **Frontend:** `resources/js/components/prn-sheet.tsx` already surfaces
  Inertia field errors; verify the medication-field error renders. (The
  sheet is in scope of the eMAR plan; this PR should not touch it beyond
  confirming the existing error surface displays correctly.)
- **Likely test:** added as case 10 above.

### M3 — `activeShiftFor` stale-shift bound (G3)

- **File:** `app/Http/Controllers/Operations/ClientCareController.php:272-289`.
- **Change:** bound the query so a "forgot to clock out" shift from a prior
  day cannot match. Preferred implementation: require the open shift to be
  inside its scheduled shift window plus a reasonable grace period, using
  `starts_at` / `ends_at` when present and falling back to `actual_starts_at`.
  If the implementation uses a simple hour cap, keep it configurable and cover
  overnight/sleepover shifts in tests.
- **Rationale:** the current code is correct in the common case but unsafe
  at the edge — a 36-hour-old open shift attributing today's PRN dose is a
  silent audit error, exactly the failure mode you do not want in a meds
  audit trail. A hard 24-hour cap may be acceptable only if this service
  cannot produce longer sleepover/respite shifts; otherwise prefer scheduled
  window + grace.
- **Likely test:** add a case to the new `ClientCareControllerTest`:
  *"recordPrn falls through to no-shift marker when the only matching open
  shift is stale, but links to an active overnight/sleepover shift that is
  still within the accepted window."*

### M4 — Safety ribbon + Care-view link on shift detail (G4)

- **Backend file:** `app/Http/Controllers/ShiftController.php` — `show()`
  method (~line 153). Eager-load `client.medicalProfile` and `client.risks`,
  and include the client columns used by `ClientSafetyPayload`:
  `id,first_name,last_name,site_id,risk_level,safeguarding_flag`. The current
  load only selects `id,first_name,last_name,site_id`, so this detail matters.
  Add
  `'client_safety' => ClientSafetyPayload::forClient($shift->client)` to
  the Inertia payload. Also add a
  `'links' => ['client_care' => route('operations.clients.care', $shift->client)]`
  entry. **Read-only**, no behavioural change.
- **Frontend file:** `resources/js/pages/operations/shifts/show.tsx`.
  - Import `ClientSafetyRibbon`.
  - Render `<ClientSafetyRibbon safety={client_safety} />` near the top of
    the page header (above the existing tab strip).
  - Add a small `<Button asChild variant="outline" size="sm">` that links
    to `links.client_care` with text "Open client care view." Place it
    next to the existing client name display so the eye lands on it
    naturally.
- **Type updates:** extend the `Props` shape in `show.tsx:75-100` with
  `client_safety: ClientSafety | null` and `links: { client_care: string }`.
- **Likely tests:**
  - PHP feature test on `ShiftController::show` payload — assert the
    `client_safety` and `links.client_care` keys are present.
  - Existing browser test in `tests/Browser/Shifts/*` (if any) gets the
    ribbon rendering for free; no new browser test needed.

### M5 — `/my-day` shift-card Care action (G5)

- **Frontend file:** `resources/js/pages/my-day/index.tsx`.
- **Change:** add a small secondary action on each shift card that links to
  `/operations/clients/{client_id}/care` when the shift has a client. Keep the
  existing primary card link to `/my-roster#shift-{id}` so roster navigation
  remains unchanged.
- **Scope guard:** do not redesign the `/my-day` card. This is a link-level
  addition only.
- **Likely tests:** extend the care Playwright smoke spec or an existing
  `/my-day` smoke to assert the shift card exposes a Care action and that it
  opens the expected client care route.

### M6 — Playwright smoke spec (G6)

- **New file:** `tests/e2e/operations-clients-care.spec.ts`. Mirrors the
  pattern in `tests/e2e/operations-rostering-a11y.spec.ts`.
- **Cases (4):**
  1. Worker logs in, navigates to a seeded client's `/care` page, sees
     the safety ribbon and the "Give as-needed med" button.
  2. Worker opens the PRN sheet, picks a PRN medication, fills the reason,
     submits, and lands on a success flash.
  3. Page renders with no axe-core violations at default mobile viewport
     (375px wide) and at desktop (1280px).
  4. Worker opens `/my-day`, sees the Care action on a shift card with a
     client, and that action opens `/operations/clients/{client}/care`
     without changing the card's primary `/my-roster#shift-{id}` route.
- **Fixture:** seeded by an existing rostering / clients factory chain;
  no new factories required.

---

## 6. What explicitly should NOT be changed

- **Do not redesign `care.tsx`.** The mobile/card layout is the intended
  shape (`care.tsx:32-55` has the explicit comment). It is the *frontline*
  surface; if it sometimes loads on a desktop the existing `mx-auto
  max-w-3xl` keeps it readable, which is enough.
- **Do not unify `operations.clients.show` and `operations.clients.care`.**
  They serve different audiences (admin vs. frontline), with different
  permissions and different navigation. Merging them would introduce
  exactly the "which tab do I open?" problem the care page was built to
  solve.
- **Do not introduce a new write path for medication administration.**
  `EnhancedMarService::recordAdministration` stays the single point of
  entry. The care-page PRN path is a launch-point, not a parallel pathway
  (`care.tsx:43-46`, `ClientCareController.php:128-138`).
- **Do not modify `ClientPolicy::view` or `viewMedications`.** Both are
  already permission-correct and the PR-14 commentary in the controller
  documents the intent.
- **Do not change the route URL or the route name.**
  `operations.clients.care` and `operations.clients.care.prn` are linked
  from the clients index and the my-day meds-due list; renaming would
  cascade. (`routes/operations.php:137-142`.)
- **Do not add a database migration.** The plan deliberately stays inside
  existing tables. The "[PRN from client page]" marker stays a notes-field
  prefix; per-launch-point structured logging (G10) is deferred.
- **Do not extend `ClientSafetyPayload`.** It is already used by 5+ pages
  and any change ripples; the shift-detail surface should consume the
  existing shape.
- **Do not refactor the rostering grid (`rostering/index.tsx`).** The
  audit note's "Admin/client ops broad" framing is a *good* signal —
  managers have what they need. G8 is deferred precisely because a grid
  redesign is out of scope.
- **Do not change `routes/clients.php`.** The legacy `/clients/*` URL
  group is unrelated to this workflow.
- **Do not skip-hooks or amend** when committing the eventual change —
  follow the repo's standard commit policy from CLAUDE.md.

---

## 7. Verification checklist

### Backend feature tests

- [ ] `tests/Feature/Operations/ClientCareControllerTest.php` — 11 cases
      pass (M1).
- [ ] `tests/Feature/MedicationControllerTest.php` — unchanged, all green.
- [ ] `tests/Feature/Operations/ClientMedicalAdministrationIdempotencyTest.php`
      — unchanged, all green.
- [ ] `tests/Feature/Operations/ShiftSiteIsolationTest.php` and the
      surrounding shift tests — unchanged, all green (the M4 ribbon-on-
      shift change is additive payload).
- [ ] `tests/Feature/Rostering/*` — unchanged, all green.

### Frontend / e2e tests

- [ ] `tests/e2e/operations-clients-care.spec.ts` — 4 cases pass (M6).
- [ ] `tests/e2e/operations-rostering-a11y.spec.ts` — unchanged, still
      passes; rostering page is untouched.
- [ ] `tests/e2e/operations-rostering-publish.spec.ts`,
      `operations-rostering-suggestions.spec.ts`,
      `operations-rostering-performance.spec.ts` — unchanged, still pass.
- [ ] Existing `ClientShowTest`, `ClientSubpagesTest`, `ClientIndexTest`
      browser tests — unchanged, still green (we do not touch
      `clients/show.tsx` or `clients/index.tsx`).

### Permissions

- [ ] An assigned support worker can `GET /operations/clients/{id}/care`
      and submit `POST .../care/prn` for a PRN medication. (M1 case 1, 5.)
- [ ] An unassigned support worker cannot `GET .../care` and cannot
      `POST .../care/prn`. (M1 case 3.)
- [ ] A manager (`clients.viewAny`) sees the page and can record a PRN.
      (M1 case 2.)
- [ ] Permission decision is explicit: anyone allowed to view a shift may
      see that shift client's critical safety ribbon. This is appropriate for
      frontline safety, but should be called out in the PR description rather
      than treated as an accidental side effect of the shift view policy.
- [ ] The `clients.update` fallback for PRN recording is documented in
      the PR description (G9).

### Mobile / desktop rendering

- [ ] Care page at 375×667 mobile: identity card, safety ribbon, PRN
      button, "What you need to know", risks, contacts, deep-links all
      readable; no horizontal scroll.
- [ ] Care page at 1280×800 desktop: `max-w-3xl` keeps the column
      narrow but readable; no broken layouts.
- [ ] Shift detail page at 375 mobile: ribbon stacks above tabs without
      overflow.
- [ ] Shift detail page at 1280 desktop: ribbon spans the existing
      content column width; "Open client care view" button reachable.
- [ ] Empty-state client (no PRN meds, no risks, no contacts): page
      renders without console errors, the PRN button shows
      "No as-needed meds on their profile yet" copy, conditions
      "No health conditions recorded.", risks "No active risks recorded."

### Regression checks

- [ ] `/operations/clients/{id}/care` still renders for a worker after
      the M2 / M3 changes — happy path on a client with one PRN med, one
      risk, one contact, and a clocked-in shift.
- [ ] `/operations/shifts/{id}` continues to render its existing tabs
      (medications, observations, forms, notes) after the M4 ribbon
      addition — no tab is hidden or moved.
- [ ] `/my-day` continues to load, keeps the existing shift-card primary
      route to `/my-roster#shift-{id}`, and adds only the small Care action
      required by G5.
- [ ] `/operations/clients` continues to link to `/care` correctly for
      both the support_worker and admin/manager paths.
- [ ] `/meds/today` PRN path is unchanged (`WorkerMedsController::recordPrn`
      not touched).
- [ ] Audit timeline events for `medication_administration` look identical
      to current behaviour for in-shift care-page PRNs (G10 deferred).

---

## 8. Recommended implementation order (avoids churn)

The order below is chosen so that each step builds a regression net before
the next step changes behaviour, and so user-visible UI changes ride after
the controller fixes they depend on. Use red/green locally if helpful, but do
not merge skipped readiness tests.

1. **Draft M1 first — the feature test file.** It will expose the current
   failures for M2 field mapping and M3 stale-shift attribution. Keep those
   as real failing tests locally while applying M2/M3; do not commit or merge
   them as `->skip()` tests.
   *Files: `tests/Feature/Operations/ClientCareControllerTest.php` (new).*

2. **Apply M2 — PRN error mapping.** Tiny diff in
   `ClientCareController::recordPrn`. The M1 error-field test should now pass.
   *Files: `app/Http/Controllers/Operations/ClientCareController.php:207-211`,
   plus optional 1-line doc-comment update on
   `EnhancedMarService::recordAdministration` if `error_field` is added to
   the contract there.*

3. **Apply M3 — `activeShiftFor` bound.** Tiny diff. The M1 stale-shift and
   overnight/sleepover tests should now pass.
   *Files: `app/Http/Controllers/Operations/ClientCareController.php:272-289`.*

4. **Apply M4 — shift-detail ribbon and Care-view link.** Backend payload
   and the frontend component import + render. Largest single change in
   the plan but still small.
   *Files: `app/Http/Controllers/ShiftController.php` (~line 153),
   `resources/js/pages/operations/shifts/show.tsx` (~line 75 type, plus
   ribbon and link insertion in the page header).*

5. **Apply M5 — `/my-day` shift-card Care action.** Small link-level addition
   only; keep the existing shift-card primary route unchanged.
   *Files: `resources/js/pages/my-day/index.tsx` (two shift-card render paths).*

6. **Apply M6 — Playwright smoke spec.** Locks in the visible behaviour
   and gives ops a tablet-class smoke before the next release.
   *Files: `tests/e2e/operations-clients-care.spec.ts` (new).*

7. **Should Fix — G7 (permission-gate the Follow-up placeholder), G9
   (permission doc note).** These ride on top of 1–6 in any order and can
   land in a follow-up PR or in the same release if time allows.

8. **Defer G8, G10, G11, G12.** Document them on the issue tracker and
   move on.

End state: rostering takes a worker to `/operations/shifts/{id}`, the
shift surface shows the canonical safety ribbon and a one-tap link into
the care view, `/my-day` shift cards expose the same care-view handoff without
changing their primary roster navigation, the care view's PRN flow returns
errors against the right field and never attributes a dose to a stale shift,
and the whole path is exercised by both Pest feature tests and a Playwright
smoke spec — without touching the schema, redesigning a single page, or moving
a route.
