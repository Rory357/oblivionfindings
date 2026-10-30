# Medication Reviews (`/emar/reviews`) — Cross-Module Parity Gap Analysis

**Single source of truth for the `/loop` pass on `/emar/reviews`.** Tick `[x]` only when
typecheck + lint + build are green for the touched files. Scope is **only** `/emar/reviews`,
its tabs, and its modals — do not regress the wizards, the deprescribing pipeline, or the
cycle-chip filtering.

- **Page:** [resources/js/pages/emar/Reviews.tsx](../resources/js/pages/emar/Reviews.tsx)
- **Dialogs:** [resources/js/pages/emar/_review-dialogs.tsx](../resources/js/pages/emar/_review-dialogs.tsx)
- **Backend:** `app/Http/Controllers/Emar/EmarController.php` → `reviews()` (line ~1978), `serializeReview()`
- **Audit source:** `REVIEWS_AUDIT.md` (repo root) + design handoff `.design-drops/emar-redesign/Medications_review/`
- **Idioms mirrored:** PRN (`PrnRecords.tsx` `openRowCtx`), `prn-detail-dialog.tsx` Options bar,
  `ShiftContextMenu` (`@/components/rostering`), `/emar/controlled` alert banner, `EntityFilter onDark`.

---

## A. Right-click context menu + clickable list rows

- [x] **A1 — Context menu on every review list.** `openRowCtx`/`openCardCtx` (mirroring PRN's
  `openRowCtx`) wired via `onContextMenu` on `ReviewTable` rows, the overview "Overdue & due now"
  list, both `OverviewList` lists, and the deprescribing kanban cards. Items: View detail (primary),
  Conduct + Reschedule (scheduled only), sep, View client, Open on MAR, sep, stage-advance
  (kanban only). Header tag = status pill; meta = resident · type · due date. `ShiftContextMenu`
  rendered at page root. _Files: `Reviews.tsx`._
- [x] **A2 — `ReviewTable` rows click-to-open.** Rows are `cursor-pointer` + hover/focus +
  keyboard-focusable (`role=button`, `tabIndex`, Enter/Space) → `onView`; inline action buttons
  `stopPropagation`. Inline overdue list rows made click-to-open the same way. _Files: `Reviews.tsx`._

## B. Detail modal — Options bar + "View client"

- [x] **B1 — Options action bar.** `ReviewDetailDialog` now has the standard footer Options bar
  (mirrors `prn-detail-dialog.tsx`): Close · Conduct review · Reschedule (scheduled only) · View
  client · Open on MAR. Conduct/Reschedule open the wizards in place via the page modal switch;
  View client / MAR `router.visit` off-page. _Files: `_review-dialogs.tsx`, `Reviews.tsx` (modal wiring)._
- [x] **B2 — Full review surfaced.** Detail now shows Reviewer/Role/Type/Trigger/Scheduled/Completed/
  DBI/Falls/Next review, clinical summary, free-text recommendations, the per-drug
  recommendations list (action tag + drug + rationale + GP status + deprescribing stage), and the
  whānau panel. All from the existing payload. _Files: `_review-dialogs.tsx`._

## C. "View client" jump

- [x] **C1 — Wired in both surfaces.** Shared `viewClient(id)` helper → `/operations/clients/{id}/care`
  used by the context menu (A1) and the detail footer (B1); `openMar(url)` for the MAR jump.
  Conduct/Reschedule/Schedule remain in-place modals. _Files: `Reviews.tsx`, `_review-dialogs.tsx`._

## D. Stacked alert strip

- [x] **D1 — Stacked, dismissible alerts.** Single overdue banner replaced with a stacked strip
  (mirrors `/emar/controlled` styling) built from `kpis`: overdue (critical → Due), due_30 (warning →
  Scheduled), awaiting_gp (info → Deprescribing). Each: tone-keyed icon + count + message + Review
  button (sets `activeTab`) + dismiss `X` (`dismissed` Set). _Files: `Reviews.tsx`._

## E. Client filter (optional parity)

- [x] **E1 — Client `EntityFilter`.** Client filter (`allLabel="All clients"`, `onDark`) added to the
  hero footer beside Site + Reviewer, built from `clientItems` (mapped from the `clients` prop);
  `clientFilter` folded into the client-side `visible` memo. No day-stepper. _Files: `Reviews.tsx`._

## Backend (minimal — no speculative migrations)

- [x] **BK1 — Detail enrichment.** `serializeReview()` already returned `recommendations`,
  `drug_burden_index`, `whanau_*`, `next_review_date`, and `actions` (with `gp_status`/`stage`) — no
  enrichment needed for the detail body. Added `mar_url` (`EmarUrl::mar($client_id)`, null when no
  client) so the context menu + detail "Open on MAR" have a target. Client filter (E1) stays
  client-side (no round-trip). _Files: `EmarController.php`, `_review-dialogs.tsx` (type),
  `ReviewsTest.php` (coverage)._

---

## Exit criteria (§6) — ✅ MET

- [x] Every box above is `[x]`.
- [x] `npm run types` (full project, exit 0) / `npm run lint` (touched files, exit 0) /
  `npm run build` (vite build ✓, incl. Wayfinder PHP step) all pass. `php -l` clean on the
  controller. `tests/Feature/Emar/ReviewsTest.php` = 4 passed / 41 assertions.
- [x] Every review list (overview "Overdue & due now" + both `OverviewList`s, `ReviewTable`,
  deprescribing kanban) has a right-click menu; the lists with rows are row-click → detail.
- [x] The detail modal has the standard Options bar with View client.
- [x] The stacked, dismissible alert strip is present.
- [x] All review actions happen in-page via modals; off-page nav is View client / Open on MAR only.

**No remaining `TODO(Gx)` items.** The detail payload already carried every field the modal needs;
the only backend addition was the `mar_url` deep-link.

## Change log

- **2026-06-16** — A–E + BK1 all closed in one pass. Frontend: `Reviews.tsx` (context menus via
  `openRowCtx`/`openCardCtx` + `ShiftContextMenu`, clickable `ReviewTable`/overdue rows, stacked
  dismissible alert strip, Client `EntityFilter`), `_review-dialogs.tsx` (`ReviewDetailDialog`
  Options bar + enriched body + `mar_url` type). Backend: `EmarController@serializeReview` adds
  `mar_url`. Tests: `ReviewsTest::test_review_payload_includes_mar_deeplink`. Verified
  types/lint/build/php-l/feature-tests all green.
