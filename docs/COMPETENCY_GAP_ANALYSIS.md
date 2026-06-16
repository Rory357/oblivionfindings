# /emar/competency — cross-module parity gap analysis

Single source of truth for the Staff Competency parity loop. Scope is **only**
`/emar/competency`, its tabs, and its modals. The page is staff-centric (not
client-centric): filters are **Role / Status**, and the cross-module "View
client" jump becomes **"View staff member" → `/staff/{id}` (route `staff.show`)**.

Reuse, never hand-roll: `ShiftContextMenu`/`ShiftCtxItem`/`ShiftCtxState`
(`@/components/rostering`), the PRN `prn-detail-dialog.tsx` Options-bar idiom,
the `/emar/controlled` stacked alert strip, the meds/today + PRN footer search
pill, `MedsWizardDialog`/`SummaryRow`. Tokens only — never raw `oklch()`.

Files in scope:
- `resources/js/pages/emar/Competency.tsx`
- `resources/js/pages/emar/_competency-dialogs.tsx`
- `app/Http/Controllers/Emar/EmarController.php` → `competency()` (line ~2073) — **theming `site_id` only; faceting is client-side. No new params.**

Do NOT regress: the 5-step `AssessmentWizardDialog` or the `CoverageMatrix`.

---

## A. Right-click context menu (everywhere) + "View staff member"
- [x] A1 — Added `ShiftCtxState` state + `<ShiftContextMenu>` render to `Competency.tsx`; `openAssessmentCtx`/`openUnassessedCtx` mirror PRN's `openRowCtx`. Header tag = `ctxStatusTag` (In date / Expiring / Expired / Supervised / Failed, token CSS vars); meta = `staff · role · expires {date}`.
- [x] A2 — `onContextMenu` on **AssessmentTable** rows. Items: View assessment (primary) · Renew / reassess · Edit · sep · View staff member · sep · Delete (critical).
- [x] A3 — `onContextMenu` on the **Expiring** cards (assessment-row items).
- [x] A4 — `onContextMenu` on the **Unassessed** rows (Start assessment · View staff member).
- [x] A5 — `onContextMenu` on the **Coverage matrix** rows (assessment-row items).

## B. Detail modal — Options bar + "View staff member"
- [x] B1 — `ViewAssessmentDialog` footer is now the standard Options bar (Close left; Renew / reassess · Edit · View staff member right), mirroring `prn-detail-dialog.tsx`. Renew/Edit swap the modal to the wizard in place.
- [x] B2 — **View staff member** in the footer → `router.visit('/staff/${user_id}')` (default + parent-provided `onViewStaff`).
- [x] B3 — Modal now lists the **observed rounds** (resident · med type · CD · outcome) in addition to 12 areas, score, permission chips, dev notes + assessor comments. Declarations not persisted → `TODO(G1)` noted in code + below.

## C. "View staff member" jump (consistency)
- [x] C1 — "View staff member" (→ `/staff/{id}`) wired in **both** the context menu (A) and the detail modal footer (B). Uses `staff.show`, never the client care page.

## D. Stacked alert strip
- [x] D1 — Replaced the single expired banner with a stacked, dismissible (per-session `sessionStorage`) alert strip mirroring `/emar/controlled` (`CompAlert` + `AlertRow` + `readDismissedAlerts`/`persistDismissedAlerts`): N expired (critical → Expired tab), N expiring ≤30d (warning → Expiring tab), N unassessed (warning → Unassessed tab). Each: icon + count message + "Review" → `setActiveTab` + dismiss ✕. Counts from KPIs (standing oversight signal, filter-independent).

## E. Click-to-open parity + search-pill polish
- [x] E1 — **Expiring** cards click-to-open the detail modal (`cursor-pointer` + hover + `role="button"`/`tabIndex`/`onKeyDown`; `stopPropagation` on the Schedule-reassessment button).
- [x] E2 — **Unassessed** rows click-to-open Start assessment (the row's primary action; keyboard-focusable; `stopPropagation` on the inline button).
- [x] E3 — **Coverage matrix** rows confirmed row-clickable; added `tabIndex`/`onKeyDown` for keyboard parity.
- [x] E4 — Footer search pill aligned to the shared meds/today / PRN pill (relative wrapper, absolute Search icon, `rounded-full bg-primary-foreground` input + clear-✕). NO day-stepper (Expiring/Expired tabs are the time dimension).

## Backend (front-end only — no new params)
- [x] BK1 — Confirmed `competency()` payload (`serializeCompetency`, EmarController ~line 2121) carries the 12 areas ✓, `observed_rounds` ✓, outcome/permissions ✓, `assessor_comments` ✓. **Declarations are NOT persisted** (wizard `assessorDeclared`/`staffDeclared` are local-only) → `TODO(G1)`: persist declaration timestamps + signatures if required. No new params / migration this loop.

---

## §6 EXIT criteria — ✅ ALL MET
- [x] Every box above is `[x]` (A1–A5, B1–B3, C1, D1, E1–E4, BK1).
- [x] `npm run types` clean (0 app errors), `eslint` clean for both touched files, `npm run build` succeeds (✓ 3m 25s).
- [x] Every surface (AssessmentTable, Expiring, Unassessed, Coverage) has a right-click menu and row/card-click → detail (Unassessed → Start assessment, no detail exists).
- [x] Detail modal has the standard Options bar with View staff member (→ `/staff/{id}`).
- [x] Stacked, dismissible alert strip present.
- [x] All actions happen in-page via modals with Inertia partial reloads.
- [x] `tests/Feature/Emar/CompetencyTest.php` — 4 passed / 50 assertions (incl. new staff-jump + enriched-detail payload contract test).

**LOOP COMPLETE 2026-06-16.** Only `TODO(G1)` remains (declarations not persisted — needs a backend column; out of scope for this front-end loop). Browser pixel-parity vs the `.dc.html` prototype on a live env is a user/browser step.

## TODO(Gx) carried forward
- `TODO(G1)` — Sign-off declarations (assessor + staff acknowledgement) are captured in the wizard but not persisted, so the detail modal cannot show a real declaration audit line. Needs a backend column/relation; out of scope for this front-end loop.
