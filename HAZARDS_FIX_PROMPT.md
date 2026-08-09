# Hazards Module — Feature-Complete + Standardisation Prompt

**Paste this whole file into Claude Design.** Goal: bring the Hazards module up to the
Health & Safety **gold standard** (same hero, right-click and modal idioms as
`/health-safety/events` and `/health-safety/analytics`), make it feature-complete, and make
it consistent across all three surfaces it appears on.

---

## 0. Read these first (do not skip — match them, don't reinvent)

**Gold-standard pages to mirror exactly:**
- `resources/js/pages/health-safety/events/index.tsx` ← the closest analogue. Copy its structure.
- `resources/js/pages/health-safety/corrective-actions/index.tsx` ← sibling register, same chrome.
- `resources/js/pages/health-safety/analytics.tsx` ← hero + segmented filter reference.

**Shared kits you MUST compose (never hand-roll a primitive these already provide):**
- Hero: `resources/js/pages/health-safety/components/hs-hero-kit.tsx`
  → `HeroShell, HeroStatusPill, HeroMedallion, HeroCluster, HeroClusterTile, HeroComplianceBadges, HeroSegmented, HeroSummaryStrip, fmt, type Tone`
- Rows: `resources/js/pages/health-safety/components/register-row-kit.tsx`
  → `RegisterTableHeader, FlagBadge, TONE_BG, TONE_DOT, titleCase, initials, entityTone`
- Right-click + tabs + filters: `@/components/rostering`
  → `ShiftContextMenu, type ShiftCtxItem, type ShiftCtxState, TabStrip, type RosterTabItem, EntityFilter`
- Modals/wizards: `@/components/wizard/shell` → `WizardShell, type WizardStep, ReviewCard, ReviewRow`
- Workflow ribbon: `resources/js/pages/health-safety/components/workflow-ribbon.tsx` → `WorkflowRibbon`
- Detail-as-modal reference: `@/components/health-safety/event-detail-dialog` (`EventDetailDialog`)

**The non-negotiable house rules (from the gold-standard file headers):**
- Semantic tokens only. **No raw oklch / hex / `border-l-red-600` / `bg-amber-500`.** Use
  `status-success / status-warning / status-critical / status-info`, `primary`, `muted`, and the
  `TONE_BG` / `TONE_DOT` maps.
- App-primary gradient only on the hero (no per-site brand tint).
- **NZ-only.** LTIFR / TRIFR (never TRIR), WorkSafe-notifiable, Ngā Paerewa NZS 8134:2021,
  Hazardous Substances Regs 2017, ACC. en-NZ dates, NZD. Do not "fix" to GBP/US.
- **Web-only.** No phone frames, no mobile-app treatments.

---

## 1. What this is, and the three surfaces (CONFIRMED by audit)

Hazards are **`SiteHazard`** records — physical / environmental hazards at a location
(`site_id`, **no `client_id`**). One controller drives everything:
`app/Http/Controllers/Sites/SiteHazardController.php`.

| Surface | Route / file | Status today |
|---|---|---|
| **Global register** `/compliance/hazards` | `globalIndex()` → `resources/js/pages/compliance/hazards/index.tsx` | **Off-pattern. Primary target.** |
| **Location tab** (Site page → Hazards) | `index()` → `resources/js/pages/sites/hazards/index.tsx` + `sites/show.tsx` tab | **Off-pattern + thin placeholder. Bring to parity.** |
| **Client profile** | — | **Not present today.** Add read-only panel (see §6). |

So, to confirm what you asked: **location tabs = yes, already wired** (Site show page registers a
`Hazards` tab that links to `/sites/{id}/hazards`); **client profile = not yet** — hazards are
site-scoped, so they'll be surfaced as read-only "hazards at this person's home" context.

---

## 2. Audit — gaps & issues to fix

Numbered so you can work through them. Severity: 🔴 breaks consistency / feature gap, 🟠 polish.

**Global register `compliance/hazards/index.tsx`**
1. 🔴 Uses the generic `PageHero` instead of `HeroShell` + `hs-hero-kit`. No eyebrow status pill,
   no medallion, no Live / Needs-attention `HeroCluster`s, **no NZ `HeroComplianceBadges`**, no
   `WorkflowRibbon`. → Rebuild the hero to match Events.
2. 🔴 **No right-click anywhere.** Rows only expose a three-dot `DropdownMenu`. → Add
   `ShiftContextMenu` on every row, AND right-click options on the hero banner (you asked for this).
3. 🔴 **No tabs.** → Add a `TabStrip` (All / Open / In progress / Overdue / Critical / Closed) with
   server-side counts, like Events' `tabCounts`.
4. 🔴 **Client-side filtering of up to 500 rows** (`globalIndex` does `.limit(500)->get()`), all
   filtering in React. → Move to server-side `paginate(20)` + server filters + `LaravelPagination`,
   exactly like Events.
5. 🔴 Row click **navigates away** to the full page `/hazards/{id}`. → Open a **detail-as-modal**
   (Inertia partial reload `only: ['detail']`, `preserveState`, `preserveScroll`), like
   `openEvent()` in Events. Keep "Open full page" as a context-menu fallback.
6. 🔴 **"Log Hazard" is not really a modal workflow** — the dialog just picks a site then
   `router.visit('/sites/{id}/hazards/create')` (full page). → Replace with a real **create modal
   wizard** (`WizardShell`) that POSTs to `/sites/{site}/hazards` and refreshes in place.
7. 🔴 **Assign / Close just navigate** to the hazard page. → Make them **action modals** (see §4.6).
8. 🔴 Hand-rolled colour maps use raw tints (`border-l-red-600`, `border-l-orange-500`,
   `bg-amber-500`, `bg-emerald-500`). → Replace with semantic tokens / `TONE_BG` / `TONE_DOT`.
9. 🟠 Export CSV is client-only. Fine to keep, but also add the shared **Board reports / register
   export** popover from the hero (mirror Events' `BOARD_REPORTS`) for consistency.

**Lifecycle (feature gap)**
10. 🔴 The model supports `open → in_progress → mitigated → closed`
   (`app/Models/SiteHazard.php`), but the controller only ever sets `open` (on create) and
   `closed` (on close). There is **no way to move a hazard to `in_progress` or `mitigated`**. →
   Add the missing transitions (see §4.6 + §7) so the lifecycle the UI already paints is real.

**Location tab `sites/hazards/index.tsx`**
11. 🔴 Same off-pattern build as the global page (generic `PageHero`, three-dot menu, no
   right-click, no detail-modal). → Rebuild to share the same chrome so the module reads as one
   product.
12. 🟠 On `sites/show.tsx` the **Hazards tab is just a placeholder card** ("View All Hazards") that
   links out. → Embed a compact gold-standard register (or at minimum a styled summary with
   right-click + the detail modal), consistent with the other Site tabs.

**Repo hygiene**
13. 🟠 Both `resources/js/pages/sites/show.tsx` **and** `sites/Show.tsx` exist (case collision —
   they clash on case-insensitive filesystems). → Confirm which is live, delete the other.

---

## 3. Target spec — Global register `/compliance/hazards`

Structure it **exactly** like `events/index.tsx`. Concretely:

### 3.1 Hero (`HeroShell`) — with right-click
- `WorkflowRibbon` at the top (pick the hazards stage).
- `HeroMedallion icon={ShieldAlert}`, `HeroStatusPill` ("Hazard register · synced…"), `h1`
  "Homes & Sites Hazards", one-line description.
- Top-right: **Board reports** `Popover` CTA (same five reports as Events).
- **Two `HeroCluster`s** of `HeroClusterTile`s, each tile linking to the matching tab:
  - *Live · open register* → Open, In progress, Overdue, Critical-open.
  - *Needs attention* → Due soon (7d), Unassigned, Awaiting verification/mitigation, Closed (period).
- **`HeroComplianceBadges`** NZ chip row (WorkSafe-notifiable awaiting, SDS expiring, Ngā Paerewa,
  fire drills, first-aid) — feed it counts/booleans from the controller, never pre-formatted strings.
- **Hero footer = the filter bar** (`HeroShell footer={…}`), mirroring Events:
  `HeroSegmented` period pills · `EntityFilter` Site · selects for Site-type / Severity / Risk /
  Assignee / Due-state · right-aligned search · Clear. All drive server requests via `router.get`.
- **Right-click on the hero** (`onContextMenu`): open a `ShiftContextMenu` with quick actions —
  *Log hazard*, *Export CSV*, *Board reports →*, *Go to site register*. (Same `ShiftCtxItem[]` +
  `ShiftCtxState` machinery as the rows.)

### 3.2 Tabs
`TabStrip` with `RosterTabItem[]`: All / Open / In progress / Overdue / Critical / Closed, each
with a `badge` from server `tabCounts`. Changing tab does `router.get(... preserveScroll)`.

### 3.3 Table + rows
- `RegisterTableHeader` with hint **"Right-click a row for the full lifecycle"** + `MousePointer2`.
- Columns: Ref / When · Hazard (type + description preview, tone dot) · Site · Severity · Risk ·
  Status · Flags (Overdue, Unassigned, Due-soon, Awaiting-mitigation).
- Each `<tr>`: `onClick → openHazard(id)` (modal), `onContextMenu → openRowCtx`, `tabIndex={0}`,
  Enter/Space to open, `hover:bg-muted/45` focus ring — copy the Events row exactly.
- Severity/risk/status tone via `TONE_BG` / `TONE_DOT` + `FlagBadge`. No raw colours.

### 3.4 Row right-click menu (`ShiftContextMenu`)
Build `ShiftCtxItem[]` contextually (mirror Events' `openRowCtx`):
- **View hazard** (opens detail modal) · **Assign / Reassign** · **Start progress** (open→in_progress)
  · **Mark mitigated** · **Add corrective action** · **Record review** · **Close hazard** (critical
  tone) · separator · **Copy link** · **Open full page** (`/hazards/{id}` fallback).
- Gate each item on `can.manage` and current status. Each item that mutates opens the relevant
  **modal** (below), never a bare navigation.

### 3.5 Detail-as-modal
- Add a `detail: HazardDetail | null` prop (Inertia partial reload, `only: ['detail']`).
- Build a `HazardDetailDialog` on `WizardShell` chrome (follow `EventDetailDialog`): sections
  Overview / Risk / Corrective actions / History, and an **Options footer bar** with the lifecycle
  actions. Support `initialAction` so a context-menu action (e.g. "Close") opens the modal straight
  onto that step. Closing drops the `?hazard=` param so `detail` returns null.

### 3.6 Workflow modals (every workflow is a modal — no navigate-away)
All POST to **existing** endpoints and refresh in place (`preserveScroll`, partial reload):
- **Log hazard** → `WizardShell` create wizard → `POST /sites/{site}/hazards`. Steps: Site & type
  (with `recommendedHazards` quick-add chips) → Severity × Likelihood (show the **live risk rating**
  from `SiteHazardRiskCalculator`) → Description / immediate action / photos → Assign & due date →
  Review (`ReviewCard`/`ReviewRow`).
- **Assign** → `POST /hazards/{hazard}/assign` (`assigned_to_user_id`).
- **Close** → `POST /hazards/{hazard}/close` (`resolution_summary` required + `resolution_evidence`).
- **Start progress / Mark mitigated** → status transition endpoint (see §7) — **needs to be added**.
- **Add corrective action** → `SiteHazardAction` (see §7).

---

## 4. Location tab parity (`sites/hazards/*` + `sites/show.tsx`)

- Rebuild `resources/js/pages/sites/hazards/index.tsx` on the **same kits** (HeroShell scoped to one
  site, TabStrip, right-click rows, detail modal, create wizard). It already gets a paginated
  `hazards` + `recommendedHazards` from `index()`, so server-side is already there — just adopt the
  chrome and the modal workflows.
- Convert `sites/hazards/create.tsx` and `sites/hazards/show.tsx` into the **modal** flows above so
  nothing in the module is a full-page form. (Keep the routes working as deep-link fallbacks.)
- In `sites/show.tsx`, replace the placeholder Hazards tab card with an **embedded compact register**
  (top N open hazards, right-click + click→modal, "View all" + "Log hazard" that opens the create
  modal), styled identically to the page's other tabs.
- Resolve the `show.tsx` / `Show.tsx` duplication (gap 13).

---

## 5. Client profile — read-only "Hazards at this home" (your option 1)

Decision: surface hazards inside the **existing Risk Management tab**
(`resources/js/pages/operations/clients/tabs/risk-management.tsx`) as a read-only section —
**not** a new tab, **not** a management surface.

- New section **"Site / environmental hazards"** under the client's existing risk content, scoped to
  the client's **current home/site**. (Resolve the client→site link server-side; if the client has
  no current site, show an empty state.)
- Show open/overdue hazards as compact rows: severity dot, type, risk, status, due. **Click / right-
  click → the same `HazardDetailDialog` (read-only mode)**; right-click also offers "Open in
  register". No create/assign/close here.
- Make it visually obvious these are **home-level, shared** records (e.g. subheading "Hazards logged
  at {home name}") so it never reads as if the hazard belongs to the person.
- All mutation deep-links to `/sites/{id}/hazards` or `/compliance/hazards` — single source of truth.

---

## 6. Backend changes (`SiteHazardController` + routes + model)

- **`globalIndex`**: switch from `.limit(500)->get()` to `paginate(20)`; add server-side filtering
  for the new tabs; return `tabCounts`, a `hero` block (the cluster + NZ-badge counts), and a
  `detail` prop (loaded only when `?hazard=` is present, eager-loading `actions`, `reportedBy`,
  `assignedTo`, `site`). Add `can: { manage }`.
- **Status transitions**: add `POST /hazards/{hazard}/status` (or explicit `start` / `mitigate`
  routes) writing `status`, `status_changed_at`, `status_changed_by_user_id`. The model already has
  these columns and `open/in_progress/mitigated/closed` scopes — wire the UI to them.
- **Corrective actions**: confirm/extend a `SiteHazardAction` store endpoint so "Add corrective
  action" works from the modal (mirror the Events corrective-action flow).
- Keep `store`, `update`, `assign`, `close` signatures; just make their redirects friendly to
  in-place partial reloads (they already `redirect()->back()`).
- Routes live in `routes/sites.php` (global at `compliance.hazards`, line ~491; site + item routes
  ~127 and ~449). Add the new transition/action routes beside them under the same permission
  middleware (`hazards.manage`).

---

## 7. Definition of done (acceptance criteria)

- [ ] `/compliance/hazards` hero is `HeroShell` + hs-hero-kit, with NZ `HeroComplianceBadges`,
      two clusters, `WorkflowRibbon`, and **right-click quick actions**.
- [ ] `TabStrip` with live server counts; server-side `paginate(20)` + `LaravelPagination`;
      filters live in the hero footer and drive `router.get`.
- [ ] Every row supports **left-click → detail modal** and **right-click → ShiftContextMenu**;
      keyboard accessible; semantic tokens only (zero raw hex/oklch/`border-l-*` colour literals).
- [ ] **Every workflow is a modal**: Log, Assign, Start progress, Mark mitigated, Add corrective
      action, Record review, Close — none navigates to a full page.
- [ ] Full lifecycle `open → in_progress → mitigated → closed` is reachable from the UI and persisted.
- [ ] `sites/hazards/*` and the Site-page Hazards tab use the **identical** chrome + modals.
- [ ] Client Risk Management tab shows a **read-only** "hazards at this home" section scoped to the
      client's site, deep-linking out for any action.
- [ ] `sites/show.tsx` / `Show.tsx` duplication resolved.
- [ ] `npm run lint` + typecheck clean; no `no-restricted-syntax` violations except the sanctioned
      on-dark hero buttons (copy the existing eslint-disable comments from the kit).

## 8. Guardrails — do NOT
- ❌ Don't introduce a second hero/row/menu primitive — compose the kits in §1.
- ❌ Don't keep any navigate-away workflow or the three-dot `DropdownMenu` as the primary action.
- ❌ Don't add `client_id` to hazards or make the client panel writable.
- ❌ Don't add raw colours, GBP/US formatting, TRIR, or mobile-app framing.
- ❌ Don't fork a parallel filtering engine — server-side like Events.

## 9. Suggested order
1. Backend: `globalIndex` (paginate + tabCounts + hero + detail + can) and status/action routes.
2. Global page: hero → tabs → table+right-click → detail modal → workflow modals.
3. Location tab + Site-page embed parity.
4. Client Risk Management read-only panel.
5. Cleanup (`Show.tsx`), lint/types, screenshot each surface.
