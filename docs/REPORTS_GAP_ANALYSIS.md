# eMAR Reports — cross-module consistency gap analysis

Single source of truth for the `/emar/reports` consistency loop. `/emar/reports` is an
**analytics / reporting surface** (charts, KPI panels, per-panel CSV export, export packs,
a read-only client drill-in) — NOT a register or work queue. Do **not** add create/edit/delete
affordances. Scope is ONLY `/emar/reports`, its tabs/panels, and its drill dialog.

- **Live page:** `resources/js/pages/emar/Reports.tsx`
- **Controller:** `app/Http/Controllers/Emar/EmarReportController.php` → `index()` (already validates +
  applies `date_from`, `date_to`, `client_id`, `site_id`, `care_level`, `report_type` — verified
  L25–59). Gaps A–D are **front-end only**; no new params, migrations, or CRUD endpoints.
- **Design ref:** `.design-drops/emar-redesign/Emar_Reports/design_handoff_emar_reports/`
  (`README.md` + `eMAR Reports.dc.html`). Where it conflicts with the cross-module standard,
  the **cross-module standard wins** for filter controls and client-jump.

Cross-module idioms mirrored (copy, don't invent): `EntityFilter` (`@/components/rostering`),
PRN/`meds-today` hero-footer search pill, `router.visit('/operations/clients/{id}/care')`,
`ShiftContextMenu` (read-only items), `MedsWizardDialog` drill chrome.

---

## A. Convert the raw `<select>` filter bar → `EntityFilter`, consolidate into the hero footer
- [x] **A1** Client raw `<select>` → `EntityFilter` (items=`clients`, value=`filters.client_id`). `Reports.tsx` hero footer.
- [x] **A2** Care-level raw `<select>` → `EntityFilter` (`careLevelOptions` string→index map; `careLevelValue`/`onCareLevel` map back to the `care_level` string). `Reports.tsx`.
- [x] **A3** Both filters moved into the hero-footer right group beside Site + search; the separate card-style "Filters" strip deleted. One standardised dark control row. `Reports.tsx`.
- [x] **A4** "Clear filters" pill (onDark outline) lives in the footer, clears client+care_level+site; `reload()` query-param wiring preserved (`client_id`, `care_level`, `site_id`, date window). `Reports.tsx`.
- [x] **A5** Date-range presets (7/30/90/This month/Custom from→to) left exactly as-is — no day-stepper. `Reports.tsx`.

## B. Drill dialog — add "View client"
- [x] **B1** `DrillDialog` footer now Close · **View client** (`router.visit('/operations/clients/{id}/care')`) · Open MAR chart (kept). `Reports.tsx`.
- [x] **B2** Summary already surfaces every `clientBreakdown` row field (total/given/refused/withheld/missed/compliance); top reason codes aren't on the payload → `TODO(G-reasons)` stub added in `DrillDialog`. `Reports.tsx`.

## C. Read-only right-click on client-breakdown rows
- [x] **C1** Administration rows `onContextMenu` → `ShiftContextMenu` (`openRowCtx`): View breakdown (drill) · View client · Open on MAR chart · sep · Export this client's CSV (`clientExportUrl`). Read-only, no mutations; compliance-banded tag. `Reports.tsx`.

## D. Search-pill polish
- [x] **D1** Footer search rebuilt as the shared meds/today / PRN pill: absolute Search icon, rounded-full `bg-primary-foreground` input, clear-✕ button when typed. Semantic tokens only. `Reports.tsx`.

---

## Verify (every pass)
`npm run types` + `npm run lint` clean for touched files; `npm run build` succeeds. Compare the
`.dc.html` prototype beside the live page; confirm no raw `<select>` remains and the filter
controls match PRN/Reviews. Exercise: Client/Care-level/Site EntityFilters re-query; date presets
+ custom range still work; drill "View client" lands on the care page and "Open MAR" still works;
charts + exports unchanged.

## Loop exit (§6) — ✅ MET
Every box `[x]`; gates green this pass — `npm run types` **0 errors**, `npm run lint`
(`resources/js/pages/emar/Reports.tsx`) **clean**, `npm run build` **✓ built**. No raw `<select>`
remains on the page (grep-verified). Client/Care-level are EntityFilters consolidated into the hero
footer beside Site + search; drill dialog offers View client + Open MAR; date-range presets
unchanged; charts/panels/exports untouched.

Deferred to the user (browser/live, consistent with prior eMAR loops): interactive verification on
oblivionfindings.com — Client/Care-level/Site EntityFilters re-query the panels, date presets +
custom range, drill "View client" lands on the care page, right-click menu actions, and pixel parity
vs the `.dc.html` prototype. A full-page vitest render is intentionally **not** added: `Reports.tsx`
pulls `AppLayout`/`PageHero`/Inertia globals + recharts `ResponsiveContainer` (zero-width in jsdom),
so a page render test would be brittle and fail for reasons unrelated to these changes.

---

## Deferred / out-of-scope `TODO(Gx)` (NOT part of A–D)
- **TODO(G-reasons):** drill-in top reasons (refusal/withhold/missed reason codes per client) — the
  `clientBreakdown` payload carries only counts + compliance, not per-client reason codes. Would
  need a backend payload addition (out of this front-end-only scope).
- **G3 (design handoff, backend):** Stock & Staff-Competency tabs are point-in-time / org-wide and
  ignore client/care-level/date filters. Design handoff suggests disabling the inapplicable filters
  and labelling "as at today". Backend-shaped; out of A–D scope. Noted for a future pass.
- **Custom reports / Build / Schedule modals (design handoff):** explicitly OUT of scope — this loop
  must not add create/edit/delete affordances to a reporting surface.
