# Compliance Dashboard Redesign — PROMPT

> One prompt for the whole job. Paste to the build (design) agent. Follows our `*_FIX_PROMPT.md` loop: work in small verifiable passes; after each pass run the app, screenshot `/compliance`, and diff against the gold-standard pages before continuing. **Do the audit in §A first**, then build §B–§G, then write the handover docs in §H (REQUIRED).

---

## 0. Mission

Redesign the org-wide **Compliance Dashboard at `/compliance`** so it is visually and behaviourally **standardised with the rest of the app** and *workflow-complete*. Today it is a read-only KPI/chart wall (`resources/js/pages/compliance/index.tsx`) using the basic `PageHero`, hand-rolled `KpiCard`s, hand-rolled severity colours, **raw hex in the Recharts** (`#ef4444`, `#dc2626` — an ESLint `no-restricted-syntax` violation), no modals, no right-click, no empty/loading states. Bring it to parity with our three reference pages — **`/health-safety`**, **`/incidents`**, **`/health-safety/analytics`** — and make every create/edit/record flow a **modal in the exact "Add Client" idiom** (see §2.2 / §D). Result should read as the compliance *command centre*: exceptions, registers due, evidence and audit assurance at a glance, with every action one click (or one right-click) away.

This is a **web-only desktop app** (no phone frames) and an **NZ-only** product (NZD / `en-NZ`; do not switch to GBP/US).

## 1. Non-negotiables

1. **Reuse the kit — never hand-roll a primitive we already have.** Every hero, stat, modal, badge, status colour, context menu, empty/loading state and toast comes from §2. No new bespoke widgets. **No raw hex / oklch / `border-l-*`** — semantic tokens only (ESLint blocks raw colour; see `docs/DESIGN_TOKENS.md` + `docs/POPUP_STYLE_GUIDE.md`). The current page's `severityColors` map and `#ef4444`/`#dc2626` chart fills **must go**.
2. **Modal consistency = the Add Client wizard.** Every information-gathering / create / edit / record / respond flow becomes a **wizard dialog built in the Add-Client idiom** (§2.2, §D): full-height split shell, stepper rail, completeness meter, top progress bar, scroll-contained body, review step, success pane, "Save & add another". Reference to copy: `resources/js/components/clients/add-client-dialog.tsx`. **No inline collapsible forms, no full-page create routes** as the primary action.
3. **Right-click everywhere.** Every list/row (Control Room alerts, obligations due, register rows, KPI drill rows) gets a **right-click context menu** + a matching kebab, sharing one action list (§2.3, §E). Left-click a row → detail modal/drill; right-click → quick actions.
4. **Single source of truth.** Don't fork data owned by another module. Control Room alerts are **canonically owned by Control Room** — deep-link to `/control-room`, don't build a parallel triage (acknowledge/resolve here, if added, are convenience that call the Control Room endpoints). The governance compliance **obligations register** (`/governance/compliance`) already owns obligations/evidence/notifiable-incidents — reuse its endpoints, don't re-implement.
5. **Verify each pass:** clean build, no TS errors, `npm run lint` clean (no `no-restricted-syntax` except the sanctioned on-dark hero-button disable copied from the kit), screenshot the changed surface, confirm it matches the reference page's hero / modal / menu.

---

## A. Audit & benchmark first (do this before building)

Study and interact with **`/health-safety`**, **`/incidents`**, **`/health-safety/analytics`** — they are the parity bar (hero kit, tab strip, right-click rows, detail modals, read-only analytics treatment).

Then **run your own audit pass** of `/compliance` and **everywhere it touches**, and record it in the handover (§H). Confirm/extend this map:

**The page (front end).** `resources/js/pages/compliance/index.tsx` → currently: basic `PageHero` (icon + 4 stats + 3 link buttons); 6 `KpiCard`s (hand-rolled `Card`, each a `<Link>` drill-out); a Control Room card (3 stat tiles + recent-alerts list + 14-day area chart); 3 Recharts blocks (incidents-by-severity bar, MAR-outcomes line, CD-discrepancy line). No modals, no right-click, no `StatusBadge`, no empty/loading/skeleton states, raw hex in charts.

**The page (back end).** Controller `app/Http/Controllers/Compliance/ComplianceDashboardController.php` (renders `compliance/index`, gate `compliance.view`). Route is **`/compliance`**, name `compliance.index` — **registered (oddly) in `routes/medications.php`**, flag this in the handover (Claude Code will relocate it — see §F). Data sources: `ClientIncident`, `ClientControlledDrugDiscrepancy`, `ClientMedicationAdministration`, `ClientBreakGlassAccess`, `ClientSupportPlan`, `AuditLog`, `ControlRoomAlert`.

**Everywhere it links / must stay consistent with** (left-click drill targets — verify each still resolves and is the right destination): `/incidents?tab=open`, `/medications?tab=controlled`, `/medications?tab=mar`, `/emar/emergency-access`, `/clients` (care-plan reviews), `/audit-logs`, `/control-room` (+ `/control-room/alerts/{id}`), `/reports`.

**Sibling compliance surfaces** (the dashboard is the roll-up; these are the registers it should reference/cross-link, and whose patterns it must not diverge from): `/governance/compliance` (obligations register — full CRUD: `store`, `complete`, `evidence.upload`, `notifiable-incident.store`, `calendar`), `/hr/compliance` (staff compliance matrix), `/sites/{site}/compliance`, `/fleet-assets/compliance`, `/privacy/dashboard`, `/emar/audit` + `/emar/errors` + `/emar/destructions`.

**Benchmark checklist** (mark Present / Partial / Missing, then close gaps in §B–§E): at-a-glance exception KPIs with trend + drill-down; "what's due" (registers/reviews/obligations) with dates and overdue flags; live alert feed with triage; evidence/audit assurance; one-click create (log obligation, record evidence, log notifiable incident); right-click quick actions; real empty/loading/skeleton states; export.

---

## 2. The shared kit you MUST reuse (exact imports)

**2.1 Hero** — `HeroShell` + the H&S hero kit from `@/pages/health-safety/components/hs-hero-kit` (`HeroShell`, `HeroStatusPill`, `HeroMedallion`, `HeroCluster`/`HeroClusterTile`, `HeroSummaryStrip`, `HeroSegmented`, and the NZ compliance badge/ribbon helpers). **Reference callers to copy: `resources/js/pages/incidents/index.tsx` (`HeroShell …`) and `resources/js/pages/health-safety/analytics.tsx`.** See `docs/GOVERNANCE_HERO_GUIDE.md` + `docs/HEALTH_SAFETY_HERO_CONSISTENCY_GAP_ANALYSIS.md`. Replace the basic `PageHero` import. Hero shows: title + period; the headline exception stats (open incidents, CD discrepancies, MAR exceptions today, overdue obligations, control-room critical); status pills/badges that drill down; quick-action buttons that open the wizards in §D.

**2.2 Modals / wizards (the Add Client idiom — this is the consistency target)** — build every create/edit/record flow like `resources/js/components/clients/add-client-dialog.tsx`. That file is the reference for: shadcn `Dialog`/`DialogContent` shell (`[&>button]:hidden`, custom `maxWidth`), full-height split (`h-[min(92vh,860px)]`) = **left stepper rail** (steps with icon/label/blurb, active/complete states) + **completeness meter** at the rail foot; **main column** = header ("Step X of N · Label") + close, **top progress bar**, scroll-contained body, footer (Back / Cancel / Continue → on review: **Save & add another** + Create); per-step **client-side validation** that jumps to the first failing step; Inertia `useForm` (`preserveScroll`/`preserveState`, `onSuccess` → **SuccessPane** with "Add another" / "Go to …"); edit-mode reuse. Field primitives from `@/components/wizard/primitives`: `Field`, `FieldErr`, `Segmented`, `ChipMulti`, `SelectInput`, `StepHead`, `SubHead`, `InfoCard`, `TilePicker`, `Ring`. (Shared wizard scaffolds also exist — `@/components/wizard/shell` `WizardShell`/`WizardStep`, and `@/components/meds/wizard-shell` `MedsWizardDialog` — but match the Add-Client *look and behaviour*.) Base shadcn in `@/components/ui/`: `dialog`, `sheet`, `popover`, `dropdown-menu`, `alert-dialog`, `command`.

**2.3 Right-click menus + kebabs** — `ShiftContextMenu` (`ShiftCtxItem`, `ShiftCtxState`) from `@/components/rostering`: portal-rendered, viewport-flipping, Esc/outside-click/scroll close, icon + label + `kbd` + tone. **Reference callers to copy: `incidents/index.tsx` and `analytics.tsx` (`onRowCtx(e, row)` → `{ctx ? <ShiftContextMenu ctx={ctx} onClose={…}/> : null}`).** The Add-Client page proves the **shared-action-list** pattern (one `MenuItem[]` feeds both the right-click menu and the kebab) — see `ClientContextMenu` + `ClientKebab` in `resources/js/pages/operations/clients/index.tsx`. Wire rows with `onContextMenu={(e) => onRowCtx(e, row)}`.

**2.4 Cards / states / badges** — **`@/components/ui/status-badge` (`StatusBadge`, `StatusVariant`) everywhere instead of mapping status/severity colours by hand** (kills `severityColors`). `@/components/ui/badge`, `card`, `table`, `empty-state`, `error-state`, `loading-state`, `skeleton-card`, `skeleton-table`. KPI tiles become hero stats / shared stat cards, not bespoke `KpiCard`.

**2.5 Tokens & flourishes** — tokens only (`resources/css/app.css`): `--status-{success,warning,critical,info,neutral}` (+`-bg`/`-foreground`), `--shadow-hero`/`--shadow-float`, `--live` (teal). Tailwind v4 utilities (`bg-status-critical-bg`, `text-status-warning`). **Charts must read their colours from CSS variables / token classes — no hex.** `cn()` from `@/lib/utils`. Toasts: **sonner** (`toast.success/error/info`) on every action. Animations: `tailwindcss-animate` with `motion-reduce:*` guards.

---

## B. Hero rethink

Replace the basic `PageHero` with `HeroShell` (§2.1), matching `/incidents`. Contents: title + selectable period (e.g. 14d/30d/90d via `HeroSegmented`, driving `router.get`); headline **exception medallion/stats** (open incidents, CD discrepancies, MAR exceptions today, **overdue obligations**, control-room critical) each with a drill-down; **status pills** for assurance ("X obligations due ≤30d", "Y reviews due", "Z break-glass uses 30d"); **quick-action buttons** opening the §D wizards (Log obligation / Record evidence / Log notifiable incident) and a Reports/Export action. Keep it data-driven; no raw colour.

## C. Body layout (sections)

Rebuild the body as standardised, drillable, stateful sections (every list gets a real **empty state**, **loading skeleton** and **error state**):

1. **Exception KPIs** — the 6 metrics as hero/shared stat cards (not `KpiCard`), each: value + trend sparkline + `StatusBadge` tone + left-click drill + **right-click** quick actions. Reuse the existing drill targets (audited in §A).
2. **What's due / assurance rail** — obligations due & overdue (from `/governance/compliance`), care-plan reviews due (`ClientSupportPlan.next_review_at`), other registers due; each row left-click → detail, right-click → Complete / Record evidence / Open register. This is the biggest gap today.
3. **Control Room alerts** — keep, but restyle: stat tiles via tokens, recent-alerts list with `StatusBadge` (not `severityColors`), 14-day trend chart with **token colours**. Rows: left-click → `/control-room/alerts/{id}`; right-click → Acknowledge / Resolve / Open (convenience actions **call Control Room endpoints**; don't fork triage).
4. **Trends** — incidents-by-severity, MAR-outcomes, CD-discrepancy charts: same data, **token colours**, consistent card chrome, empty states when no data.

## D. Modal workflows (Add-Client idiom — REQUIRED)

The dashboard is read-only today; give it the standard create/record flows as **wizard dialogs** (§2.2), reusing existing endpoints where they exist (confirm in the handover, spec any net-new before building):

- **Log compliance obligation** → POST `governance.compliance.store` (reuse the register's create; mirror `Governance/Compliance/Create.tsx` fields into the Add-Client wizard shell).
- **Record evidence** (against an obligation) → POST `governance.compliance.evidence.upload`.
- **Log notifiable incident** → POST `governance.compliance.notifiable-incident.store`.
- **Complete obligation** (from a due row) → POST `governance.compliance.{obligation}.complete` (small confirm wizard / `alert-dialog`).
- Each: stepper rail + completeness + review + success pane + "Save & add another"; `StatusBadge` for status; sonner toast on result; jump-to-first-error validation mirroring the server request. **No navigate-away.**

If a needed flow has no endpoint, **do not invent silently** — write a short spec in the handover (§H) and flag for confirmation.

## E. Right-click + detail (REQUIRED)

Every row in §C: left-click → detail modal/drill; **right-click → `ShiftContextMenu`** (§2.3) with a shared action list also exposed as a kebab. Actions are contextual (e.g. obligation row → Complete / Record evidence / Open in register / Open calendar; alert row → Acknowledge / Resolve / Open; KPI → Open filtered list / Export). Keyboard accessible; portal-positioned; Esc/outside-click closes.

## F. Cross-module + governance (leave the hooks; Claude Code finishes the backend)

This dashboard should be reachable and consistent from governance, **but the governance wiring is Claude Code's job, not yours.** Your part: (a) keep/添加 the cross-links — on `/compliance` add a clear CTA into the governance **obligations register** (`/governance/compliance`) and its **calendar** (`/governance/compliance/calendar`); (b) build the page so its KPI computation can be lifted into a shared service (don't bury the queries in the page); (c) **document in the handover** (§H / `BACKEND_AUDIT.md`) every backend need: the misplaced `/compliance` route in `routes/medications.php` (should move), a proposed `ComplianceMetricsService` so the governance dashboard and this page share one source of truth, the governance dashboard card + nav entry, and any new endpoints your §D wizards require. (Chane has a separate Claude Code plan for the governance surfacing — `COMPLIANCE_GOVERNANCE_SURFACING_PLAN.md`; align your `BACKEND_AUDIT.md` to it.)

## G. Definition of done

- [ ] `/compliance` hero is `HeroShell` + hs-hero-kit (period selector, exception medallions, drill-down status pills, quick-action buttons) — visually indistinguishable in quality from `/incidents` & `/health-safety/analytics`.
- [ ] **Zero** bespoke primitives: no `KpiCard`, no `severityColors`, no raw hex/oklch (charts use tokens); `StatusBadge` used for every status/severity.
- [ ] Every list row supports **left-click → detail/drill** and **right-click → `ShiftContextMenu`** (shared with a kebab); keyboard accessible.
- [ ] **Every workflow is a modal in the Add-Client idiom** (stepper rail + completeness + review + success pane + Save-&-add-another): Log obligation, Record evidence, Log notifiable incident, Complete — none navigates to a full page.
- [ ] Every list has a real **empty / loading-skeleton / error** state.
- [ ] "What's due / assurance" rail added (obligations + reviews + registers due, with overdue flags) and cross-links to `/governance/compliance` (+ calendar).
- [ ] Control Room section deep-links to `/control-room` and calls its endpoints for any convenience triage (no parallel triage store).
- [ ] NZD / `en-NZ` everywhere; web-only (no phone frames); sonner toast on every action.
- [ ] Handover docs written per §H (incl. `BACKEND_AUDIT.md`).
- [ ] `npm run lint` + typecheck clean; screenshots of every surface diffed against the reference pages.

## H. Your audit + handover docs (REQUIRED — do this as you go)

Run your own audit first, then record all backend + cross-module work in the repo's handover convention so engineering can verify it. Create **`.design-drops/compliance-dashboard-redesign/`** mirroring `.design-drops/health-safety-events-redesign/` and `.design-drops/incidents-redesign/`:

- `HANDOFF.md` — what changed, screen-by-screen; the component/import map; before/after.
- `COMPLIANCE_DASHBOARD_GAP_ANALYSIS.md` — Present/Partial/Missing vs §A checklist + the reference pages.
- `BACKEND_AUDIT.md` — **every** backend change/need: the `/compliance` route relocation out of `routes/medications.php`; the proposed shared `ComplianceMetricsService`; the governance dashboard card + nav entry (defer to `COMPLIANCE_GOVERNANCE_SURFACING_PLAN.md`); each §D endpoint (existing vs net-new, with validation + any migration — or "no migration required"); the Control-Room convenience-action endpoints reused. List **cross-module gaps** and anything deferred.

Also add a `PROGRESS.md` running checklist (mirror the other redesign drops). If a cross-module register or endpoint is ambiguous, flag it rather than inventing it.

## Guardrails — do NOT
- ❌ Don't introduce a second hero / row / menu / wizard primitive — compose the kits in §2.
- ❌ Don't keep any navigate-away workflow or full-page create form as the primary action.
- ❌ Don't add raw colour (hex/oklch/`border-l-*`), GBP/US formatting, or mobile-app framing.
- ❌ Don't fork Control Room triage or re-implement the governance obligations register — deep-link / reuse endpoints.
- ❌ Don't do the governance backend wiring yourself — document it for Claude Code (§F/§H).

## Suggested order
1. §A audit (front + back) → write the gap analysis & backend-audit skeletons.
2. Hero → `HeroShell` (copy `/incidents`); kill `PageHero`, `KpiCard`, `severityColors`, hex charts.
3. Body sections (§C) with `StatusBadge` + empty/loading/error states.
4. Right-click + detail (§E) on every row.
5. Modal workflows (§D) in the Add-Client idiom, reusing governance compliance endpoints.
6. Cross-links + leave service/governance hooks (§F); reconcile numbers; lint/types; screenshot each surface; finish handover docs (§H).
