# eMAR Audit Trail — DESIGN REVIEW handoff (read me first)

> **If you were asked to "check the /emar/audit page design", start here.**
> This page (`resources/js/pages/emar/AuditLog.tsx` + `resources/js/components/emar/medication-event-drawer.tsx`)
> was rebuilt to a supplied high-fidelity design. This file tells you what the design
> intends, what was actually built, the deliberate deviations, and a concrete fidelity
> checklist — so you can review the **visual/design** layer (the functional/backend layer
> is already tested and shipped).

Live page (logged-in, demo admin): **https://oblivionfindings.com/emar/audit**
Drive it with the Claude-in-Chrome MCP (the user logs in; do not enter credentials yourself).

## Design reference (the source of truth for the look)

Supplied by the user as a zip; extracted alongside it. Paths (user's machine — re-attach if missing):
- `C:\Users\steph\Downloads\Emar all Pages design\Audit Trail Emar.zip`
- Extracted: `…\Audit Trail Emar_extracted\design_handoff_emar_audit_trail\`
  - `README.md` — the build spec (screens/views, tokens, component mapping). **The authoritative design doc.**
  - `HANDOFF.md` — design rationale + the backend gaps (all now built — see below).
  - `Audit Trail.dc.html` — the interactive HTML prototype (open in a browser to see intended look/behaviour). It paints with inline styles + hand-drawn SVG; it is a **reference, not production code**.

If the zip isn't available, the design spec is summarised below well enough to review against.

## What was built (files)

- `resources/js/pages/emar/AuditLog.tsx` — the page (hero, view tabs, category tabs, filters, Timeline/Table/Gaps views).
- `resources/js/components/emar/medication-event-drawer.tsx` — the modal traceability drawer (5 sections + scroll-spy rail + integrity panel + footer actions).
- `resources/js/components/meds/day-picker-chip.tsx` — the shared hero day-stepper calendar chip (also used by /meds/today, /emar charts & rounds).
- Backend: `app/Http/Controllers/Emar/AuditLogController.php`, `MedicationAuditEventController.php`, `app/Services/Emar/{MarOmissionService,MedicationAuditIntegrityService}.php`.

Reused components (design "component mapping"): `PageHero` (`category="ops"`), `TabStrip` (rostering), `EntityFilter`, `Select` primitive, `MedsWizardDialog` chrome constants (`WIZARD_RAIL_CLASS`/`WIZARD_FOOTER_CLASS`), `SummaryRow`, lucide-react icons. Tokens only — no raw hex (the page has an `eslint-disable no-restricted-syntax` header for its custom bordered rows; colours must stay semantic tokens).

## Design intent — quick spec (from README §A–E)

- **Shell:** hero → controls row (view tabs + "Showing N of total" + Clear) → one `Card` (filter bar + active view). No KPI strip, no designer-note strip.
- **Hero** (`PageHero`, ops gradient + 3 orbs): `History` icon well; eyebrow with animated green ping dot + "live · refreshed HH:MM"; title "Kia ora {name} — every medication action across **{site, underlined}**"; description; **meta row** (Clock window · ShieldCheck immutable · Users "{n} actions · {n} staff · {n} sites"); **badges** as SOLID token pairs (not opacity-over-gradient); actions **Export audit pack** (solid) + **Print MAR & CD register** (outline); **stats** Total/This week/This month/Open gaps (last amber); **footer** = day-stepper (`‹ prev | calendar pill | next ›` + Back to today) on the left, white search pill + All-sites filter on the right.
- **View tabs:** Timeline (`Activity`) · Table (`Table`) · Compliance gaps (`ShieldAlert`, critical, badge = gap count).
- **Category tabs:** All / Doses / Controlled / Clinical / Stock / Errors with live counts.
- **Filters:** Client · Staff member · Date range · Source — use the `Select` primitive.
- **Timeline:** grouped by day (day header + count); event row = circular tone icon node, tone chip + tabular time + source pill + flag chips, natural-language title, actor avatar "name · role" (or amber "Not attributed"), site, "View record ›"; **gap rows use a dashed critical border**.
- **Table:** dense, min-width ~920px; Time · Event · Client · Outcome · Performed by · Witness · Source.
- **Gaps:** intro "why this matters" banner (CQC/NICE SC1) + grid of gap cards with CTAs.
- **Drawer** (mirrors Add-Client wizard chrome): left rail (event icon tile + label + "{source} · {id}", section nav with active `bg-primary/10`, pinned bottom "immutable" badge); body = 5 anchored sections (**What happened · People & sign-off · Before → after · Device & integrity · Linked records**) with smooth scroll-spy nav (container `scrollTo`, never `scrollIntoView`); sticky footer (Flag + Export left; Resolve-gap / Open-in-MAR right).
- **Tokens:** primary `oklch(0.511 0.262 277)`; status pairs success/warning/critical/info; cards radius ~18px; **Instrument Sans** UI + **Geist Mono** for timestamps + integrity hash.

## Deliberate deviations from the design (intentional — don't "fix" without asking)

1. **"Tamper-evident" → "Append-only".** The design's eyebrow said "Tamper-evident audit trail" + a "cryptographically sealed" integrity claim. We chose the **honest integrity panel**: real device/IP/edit-history from the existing `AuditLog` + a read-time SHA-256 **content fingerprint** (labelled "not a sealed hash-chain"). No hash-chain was built (the user explicitly chose this). So the eyebrow reads "Append-only audit trail".
2. **Hero badges: 2, not 3.** Design showed three badges incl. "1 review overdue". We ship the two backed by real data (unexplained gaps, CD entries missing witness). "Review overdue" was omitted rather than invented.
3. **Drawer rail = custom Dialog, not the wizard stepper.** `MedsWizardDialog` is stepper-semantic ("Step 1 of 5", progress bar) — wrong for a read-only record. We reused its chrome *classes* but built a real section navigator. Rail width is 248px (the shared `WIZARD_RAIL_CLASS`), design said 256px.
4. **Section-flash on nav click.** Most events are short and fit without a scrollbar, so the design's "scroll to section" did nothing. Added: clicking a rail item flashes the target section (primary tint + heading, ~1.3s) and scrolls only when there's room.
5. **Day-stepper semantics.** It sets the **anchor (end) date** of the audit window (client-side); with the range select it means "the {range} days ending {anchor}", default today.
6. **Full-width vs 1240px.** The README said a centred 1240px column; this app's house convention is **full-width** (see memory `feedback_full_width_layout`). The page uses full-width `p-6`. Reconcile against the app convention, not the design's 1240px, unless the user wants the cap.

## Fidelity review checklist (what to scrutinise on the live page)

- [ ] Hero gradient/orbs/spacing, icon well, eyebrow ping dot, underlined site in title, greeting present.
- [ ] Meta row renders 3 items; badges are **solid** token pairs (white text on status colour), not translucent.
- [ ] Stats: 4 values, "Open gaps" tinted amber. (Note: "This week" is worker-tz anchored — should match the timeline's today.)
- [ ] Footer day-stepper: prev/next labels, calendar pill opens a month popover that is **not clipped** by the hero (it portals), "Back to today" appears only when not today; search pill + All-sites on the right.
- [ ] View tabs + category tabs counts look right; "Clear" appears only when a filter is active.
- [ ] Filters are the app `Select` (not native `<select>`).
- [ ] Timeline rows: icon node colour by tone, chips, NL sentence, actor/site line, **gap rows dashed-critical**.
- [ ] Table view dense + horizontally scrollable; Witness column shows green name / red "Required — missing".
- [ ] Gaps view: critical intro banner + gap cards.
- [ ] Drawer: rail nav highlights + flashes on click; sections render; **Device & integrity** shows real device/IP/recorded-at/append-only + a mono SHA-256 fingerprint for backed events, and a "Derived…" note for omissions; before→after only appears for medication-change events; footer actions correct (Flag/Export only for backed events; Resolve-gap when a flag is present else Open-in-MAR).
- [ ] Typography: timestamps + fingerprint in Geist Mono; UI in Instrument Sans.
- [ ] No raw-hex colours; dark-mode/responsive sanity; basic a11y (focus rings, aria on icon-only buttons).

## Status / context

- Shipped to `main` and deployed; functionally verified live end-to-end (day-stepper, both drawer variants, integrity real data, flag→MedicationError, omission count, worker-tz stats). See memory `project_emar_audit_trail_upgrade` and `docs/emar-redesign/audit-plan.md`.
- Gates: `npm run types`, `npm run lint`, `php vendor/bin/pint --dirty`, `php artisan test --filter=AuditTrailTest` (15 tests). ⚠️ Build gotcha: Herd CLI `memory_limit` (128M) is too low for `php artisan wayfinder:generate` during `npm run build` — run the build with a php.ini that sets `memory_limit=1024M` (e.g. via `$env:PHPRC`).
- This review is about the **visual/design** layer only; the data/behaviour is done.
