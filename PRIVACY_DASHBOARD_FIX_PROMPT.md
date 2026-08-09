# Privacy Dashboard — Gold-Standard Redesign + Standardisation Prompt

**Paste this whole file into Claude Design.** Goal: rebuild `/privacy/dashboard` into a **Privacy
command centre** that matches the Health & Safety **gold standard** — same hero, right-click and
detail-as-modal idioms as `/health-safety`, `/incidents` and `/health-safety/analytics` — and make
every **workflow (new request / log breach / new legal hold / new retention policy / new DPIA / every
lifecycle action) follow the Client page "Add client" modal UX** exactly. Then make the Privacy module
consistent across every surface the dashboard touches.

**Before you build anything: run your own audit pass (see §7), then record all backend work and any
remaining frontend/QA work in handover files (see §6). Do not silently change backend code — capture
it for the engineer in the handover.**

---

## 0. Read these first (do not skip — match them, don't reinvent)

**Gold-standard PAGES to mirror exactly (page chrome):**
- `resources/js/pages/health-safety/events/index.tsx` ← the closest analogue. Copy its structure
  (hero → tabs → filter bar → right-click rows → **detail-as-modal** via `?…=id` partial reload).
- `resources/js/pages/incidents/index.tsx` ← sibling register, identical chrome (`HeroShell` +
  `WorkflowRibbon` + `ShiftContextMenu`).
- `resources/js/pages/health-safety/analytics.tsx` ← hero + `HeroSegmented` period/lens filter and
  KPI strip reference (this is the closest analogue for a *dashboard* surface).

**Gold-standard MODAL/WORKFLOW to mirror exactly (every create / edit / log / lifecycle flow):**
- `resources/js/components/clients/add-client-dialog.tsx` ← **the workflow reference.** Match its UX:
  full-height modal, left **stepper rail** with per-step icons + blurbs, a **completeness meter**
  (`Ring`), top progress bar, scroll-contained body, custom footer (Back / Cancel / Continue, and on
  the review step **"Save & add another"** via the `stay` pattern + a primary **Create**), client-side
  per-step validation that **jumps to the first failing step**, and a `WizardSuccessPane` afterwards.
  It is built on the shared wizard primitives below — compose those, styled to read identically.
- Header rule already in that file: bespoke modal surface, **semantic design tokens only, never
  hardcoded hex** (see `docs/DESIGN_TOKENS.md` and `docs/POPUP_STYLE_GUIDE.md`).

**Shared kits you MUST compose (never hand-roll a primitive these already provide):**
- Hero: `@/pages/health-safety/components/hs-hero-kit`
  → `HeroShell, HeroStatusPill, HeroMedallion, HeroCluster, HeroClusterTile, HeroComplianceBadges,
  HeroSegmented, HeroSummaryStrip, HeroSummaryMetric, fmt, type Tone, DOT_CLASS`
- Rows: `@/pages/health-safety/components/register-row-kit`
  → `RegisterTableHeader, FlagBadge, TONE_BG, TONE_DOT, titleCase, initials, entityTone`
- Right-click + tabs + filters: `@/components/rostering`
  → `ShiftContextMenu, type ShiftCtxItem, type ShiftCtxState, TabStrip, type RosterTabItem,
  type RosterTabTone, EntityFilter, type EntityFilterOption`
- Wizard shell: `@/components/wizard/shell`
  → `WizardShell, type WizardStep, WizardStepPane, WizardSuccessPane, ReviewCard, ReviewRow`
- Wizard primitives (the Add-client family): `@/components/wizard/primitives`
  → `Field, FieldErr, SubHead, StepHead, InfoCard, SelectInput, Segmented, ChipMulti, TilePicker,
  Ring, type IconType`
- Detail-as-modal reference: `@/components/health-safety/event-detail-dialog` (`EventDetailDialog`)
  and `@/pages/health-safety/components/hs-detail-dialog` — build privacy detail dialogs on the same
  chrome.
- Pagination: `@/components/ui/laravel-pagination` → `LaravelPagination`.

**The non-negotiable house rules (from the gold-standard file headers):**
- **Semantic tokens only.** No raw oklch / hex / `border-l-red-600` / `bg-amber-500`. Use
  `status-success / status-warning / status-critical / status-info`, `primary`, `muted`, and the
  `TONE_BG` / `TONE_DOT` / `DOT_CLASS` maps.
- App-primary gradient only on the hero (no per-module brand tint).
- **NZ-only.** This is **Privacy Act 2020** — Office of the Privacy Commissioner (**OPC**), breach
  notification via **NotifyUs** on the **serious-harm** threshold "as soon as practicable", the **13
  Information Privacy Principles (IPPs)**, **IPP 6** access requests (statutory **20 working days**),
  NHI for health identifiers, en-NZ dates, NZD. **Do NOT use GDPR / "DSAR" / UK-ICO / 72-hour / €
  framing.** Keep the existing "DSR / Privacy request" + "OPC notification" language.
- **Web-only.** No phone frames, no mobile-app treatments. This is a desktop web app.

---

## 1. What this is, and the surfaces (CONFIRMED by audit — re-verify in §7)

The Privacy dashboard is the hub for the whole privacy/data-protection module. One controller drives
it: `app/Http/Controllers/PrivacyDashboardController.php` (`index` only) →
`resources/js/pages/privacy/dashboard.tsx`. Route: `routes/privacy.php`
(`GET /privacy/dashboard` → `privacy.dashboard`, ~line 133). Sidebar: `app-sidebar.tsx` line ~1309
("Privacy" → `/privacy/dashboard`). It aggregates **five domains**, each with its own controller,
list page, **navigate-away create page**, and lifecycle actions:

| Domain | List / create / lifecycle (route names in `routes/privacy.php`) | Status today |
|---|---|---|
| **Privacy requests (DSR)** `DataSubjectRequestController` | `requests.index`, **`requests.create` (page)**, `requests.store`, `requests.show`, `requests.update`, `requests.verify-identity`, `requests.extend`, `requests.complete`, `requests.refuse`, `requests.export` | Create is a **full-page** form (`requests/create.tsx`, generic `PageHero`). Rich lifecycle, **none reachable from the dashboard**. |
| **Data breaches** `DataBreachController` | `breaches.index`, **`breaches.create` (page)**, `breaches.store`, `breaches.show`, `breaches.update`, `breaches.notify-opc`, `breaches.notify-subjects`, `breaches.resolve` | Create is a **full page**. OPC/subject-notification + resolve actions **not on the dashboard**. |
| **Legal holds** `LegalHoldController` | `legal-holds.index`, **`legal-holds.create` (page)**, `legal-holds.store`, `legal-holds.edit`, `legal-holds.update`, `legal-holds.release` | Create/edit are **full pages**. Release **not on the dashboard**. |
| **Retention policies** `DataRetentionPolicyController` | `retention.index`, **`retention.create` (page)**, `retention.store`, `retention.edit`, `retention.update`, `retention.review` | Create/edit/review are **full pages**. The dashboard shows counts only. |
| **DPIA / PIA** `DPIAController` | `dpia.index`, **`dpia.create` (page)**, `dpia.store`, `dpia.show`, `dpia.edit`, `dpia.update`, `dpia.approve`, `dpia.review` | Create/edit are **full pages**. Approve/review **not on the dashboard**. |
| **Deletion logs** `DataDeletionLogController` | `deletion-logs.index`, `deletion.execute` | **Not surfaced on the dashboard at all** (gap). |
| **Compliance reports** `PrivacyReportController` | `reports.index`, `reports.compliance`, `reports.export` | **Not surfaced on the dashboard at all** (gap). |

Every create/edit above is a **navigate-away page** today. The redesign turns them into **Add-client-
style wizard modals** launched from the dashboard, and exposes the lifecycle actions via **right-click**
and **detail-as-modal** — reusing the endpoints that already exist.

---

## 2. Audit — gaps & issues to fix

Numbered so you can work through them. Severity: 🔴 breaks consistency / feature gap, 🟠 polish.

**Dashboard page `privacy/dashboard.tsx`**
1. 🔴 Uses the generic `PageHero`, **not** `HeroShell` + hs-hero-kit. No status pill, no medallion,
   no Live / Needs-attention `HeroCluster`s, **no NZ `HeroComplianceBadges`**, no hero footer filter.
   → Rebuild the hero to match Analytics/Incidents.
2. 🔴 **No right-click anywhere.** The four quick-link cards and the "Recent requests" rows are plain
   `<Link>`s — no context menu, no quick actions. → Add `ShiftContextMenu` on the hero (banner quick
   actions) **and** on every Recent-request row.
3. 🔴 **No workflows on the page.** Every create is a navigate-away page; the dashboard can't start a
   request, log a breach, open a hold, add a policy or a DPIA. → Add **Add-client-style wizard modals**
   for all five, launched from the hero (right-click + cluster tiles + a primary CTA).
4. 🔴 **No tabs / no segmented filter.** → Add a `TabStrip` across the privacy domains (Overview /
   Requests / Breaches / Legal holds / Retention / DPIA / Deletion logs) with server `tabCounts`, and a
   `HeroSegmented` **period** control in the hero footer (This month / Quarter / Year / All) driving
   `router.get`.
5. 🔴 **"Recent requests" rows are not interactive beyond navigate-away.** No detail view, no lifecycle
   actions, not keyboard accessible. → Left-click opens a **detail-as-modal**; right-click opens the
   context menu; rows get `tabIndex={0}` + Enter/Space, focus ring, `hover:bg-muted/45` (copy the
   Events row exactly).
6. 🔴 **Overdue / breach / hold / DPIA numbers are dead ends.** "X overdue", "requiring OPC
   notification", "high risk" are shown but **not clickable into a filtered worklist or action**. →
   Wire each `HeroClusterTile` to the matching tab/filter; make "requiring OPC notification" and
   "overdue" open the relevant detail/action modal.
7. 🔴 **Deletion logs + Compliance reports are absent.** → Surface them (a cluster tile + a tab, and an
   **Export compliance report** action — `reports.export` — in the hero right-click menu).
8. 🟠 **`recentRequests: any[]`** and loose `request.status`/`request_type` strings. → Define proper
   row/prop types; render status/type via `FlagBadge` / `TONE_BG`, not the bespoke `getStatusColor`
   switch (which mixes ad-hoc colours).
9. 🟠 Quick-link cards duplicate the hero clusters. → Collapse into one source of truth: the hero
   clusters are the navigation; below the hero show the worklist table + a compact compliance strip.

**Other privacy surfaces (consistency)**
10. 🔴 **Five navigate-away create pages** (`requests/create.tsx`, `breaches/create.tsx`,
    `legal-holds/create.tsx`, `retention/create.tsx`, `pia/create.tsx`) each use the generic `PageHero`
    + a monolithic `useForm`. → Replace each with the **same** Add-client-style `WizardShell` modal
    (§3.6/§3.7); keep the old routes as deep-link fallbacks that open the modal, or redirect.
11. 🟠 **List pages** (`requests.tsx`, `breaches.tsx`, `legal-holds.tsx`, `retention.tsx`, `dpia.tsx`,
    `deletion-logs.tsx`) likely use mixed hero/row idioms. → Align them to `HeroShell` +
    `RegisterTableHeader` + right-click rows + detail-as-modal in the same pass (record any that are
    out of scope in the handover).
12. 🟠 **FE/BE status drift risk.** The page's `getStatusColor` knows `received, under_review,
    in_progress, completed, rejected`; the controller's "pending" set also includes
    `identity_verification`, and lifecycle adds `withdrawn`/`refused`. → One canonical status→tone map,
    FE = BE.

**Backend `PrivacyDashboardController` (record for the engineer — see §6)**
13. 🔴 `index()` returns six flat stat blocks only — **no `hero` cluster/badge block, no `tabCounts`,
    no `can.manage`, no paginated worklist, no `detail`**. → Extend it to feed the new hero + tabs +
    worklist + detail-as-modal (see §6).
14. 🔴 **No `detail` endpoint for the dashboard.** Right-click "View / Verify identity / Extend /
    Complete / Refuse" and the detail-as-modal have nothing to partial-reload. → Add `detail` loading
    when `?request=` / `?breach=` / `?hold=` / `?dpia=` is present (eager-load relations + audit
    history), exactly like Events' `openEvent`.
15. 🟠 **Model parity:** `DataSubjectRequest` + `DataBreachLog` already `SoftDeletes` + audit;
    **`LegalHold`, `DataRetentionPolicy`, `PrivacyImpactAssessment` do not** (confirm in §7). → If
    right-click Delete/soft-delete is wanted on those, record the trait/migration in the handover —
    don't add silently.

---

## 3. Target spec — Privacy command centre `/privacy/dashboard`

Structure it like a cross between `analytics.tsx` (dashboard hero + KPI strip + segmented period) and
`events/index.tsx` (tabs + right-click rows + detail-as-modal).

### 3.1 Hero (`HeroShell`) — with right-click
- `HeroMedallion icon={Shield}`, `HeroStatusPill` ("Privacy & data protection · Privacy Act 2020 ·
  synced…"), `h1` "Privacy Dashboard", one-line description.
- **Two `HeroCluster`s** of `HeroClusterTile`s, each tile linking to the matching tab/filter:
  - *Live · this period* → New requests, In progress, Completed (this month), Breaches logged,
    DPIAs in review.
  - *Needs attention* → **Requests overdue** (statutory 20 working days), **Breaches requiring OPC
    notification**, **Breaches requiring subject notification**, **Active legal holds**, **High-risk
    DPIAs**, **Retention reviews due**.
- **`HeroComplianceBadges`** NZ chip row — feed it counts/booleans from the controller (never
  pre-formatted strings): e.g. *Privacy Act 2020*, *OPC-notifiable breaches open*, *Overdue access
  requests*, *Active legal holds*, *Retention policies active*.
- **Hero footer = controls** (`HeroShell footer={…}`): `HeroSegmented` **period** pills (This month /
  Quarter / Year / All) · optional `EntityFilter` (Site / Assigned-to) · right-aligned search · Clear.
  All drive server requests via `router.get`.
- **Right-click on the hero** (`onContextMenu` → `ShiftContextMenu`): *New privacy request*, *Log data
  breach*, *New legal hold*, *New retention policy*, *New DPIA*, separator, *Export compliance report*
  (`reports.export`), *Go to Requests / Breaches / Retention*.

### 3.2 Tabs
`TabStrip` with `RosterTabItem[]` across the domains (Overview / Requests / Breaches / Legal holds /
Retention / DPIA / Deletion logs), each with a `badge` from server `tabCounts`. Changing tab does
`router.get(… preserveScroll, preserveState)` and swaps the worklist below.

### 3.3 Worklist table + rows (the body under the hero)
- `RegisterTableHeader` with hint **"Right-click a row for actions"** + `MousePointer2`.
- Default (Overview / Requests tab) = the privacy-request worklist. Columns: Reference · Type
  (tone badge) · Subject · Status (tone via canonical map) · Due (en-NZ; **overdue** in
  `status-critical`) · Assigned to. Other tabs swap in the matching entity's columns.
- Each `<tr>`: `onClick → openDetail(id)` (detail modal), `onContextMenu → openRowCtx`, `tabIndex={0}`,
  Enter/Space to open, focus ring, `hover:bg-muted/45` — copy the Events row exactly.
- All status/severity/type tone via `TONE_BG` / `TONE_DOT` / `FlagBadge`. No raw colours, no bespoke
  `getStatusColor`.
- `LaravelPagination` under the table.

### 3.4 Row right-click menu (`ShiftContextMenu`)
Build `ShiftCtxItem[]` contextually (mirror Events' `openRowCtx`), gated on `can.manage`. For a
**privacy request** row:
- **View request** (detail modal) · **Verify identity** · **Extend deadline** · **Mark complete** ·
  **Refuse request** (critical tone) · **Export data package** (`requests.export`) · separator ·
  **Copy link** · **Open full page**.

For a **breach** row: *View · Notify OPC · Notify subjects · Resolve · Copy link*. For a **legal
hold**: *View · Edit · Release*. For a **DPIA**: *View · Edit · Approve · Send for review*. For a
**retention policy**: *View · Edit · Run review*. Each mutating item opens the relevant **modal** (or
detail-modal pre-opened on that action) — never a bare navigate-away.

### 3.5 Detail-as-modal
- Add `detail` props (Inertia partial reload, `only: ['detail']`, opened by `?request=` / `?breach=` /
  `?hold=` / `?dpia=` params; closing drops the param so `detail` returns null) — exactly like Events'
  `openEvent`.
- Build a `PrivacyRequestDetailDialog` (and siblings) on `EventDetailDialog` / `hs-detail-dialog`
  chrome: sections Overview / Subject & verification / Timeline & deadline / History (audit trail),
  with an **Options footer bar** carrying the lifecycle actions (Verify / Extend / Complete / Refuse /
  Export). Support an `initialAction` so a context-menu action opens the modal straight onto that step.

### 3.6 Workflow modals — **every one follows the Add-client UX** (§3.7)
All POST to the **existing** endpoints and refresh in place (`preserveScroll`, partial reload):
- **New privacy request** → wizard → `POST privacy.requests.store` (with `stay` for "Save & add another").
- **Log data breach** → wizard → `POST privacy.breaches.store`.
- **New legal hold** → wizard → `POST privacy.legal-holds.store`.
- **New retention policy** → wizard → `POST privacy.retention.store`.
- **New DPIA** → wizard → `POST privacy.dpia.store`.
- **Edit** variants → the same wizard pre-filled → the existing `PUT … update` routes.
- **Lifecycle actions** (verify-identity / extend / complete / refuse / notify-opc / notify-subjects /
  resolve / release / approve / review / execute deletion) → small **action modals** on the same modal
  chrome, hitting the existing POST routes.

### 3.7 The create/edit wizard = Add-client modal UX (non-negotiable)
Build on `WizardShell` + the wizard primitives, **styled to read identically to
`add-client-dialog.tsx`**: left stepper rail (icon + label + blurb per step), completeness meter
(`Ring`), top progress bar, per-step client-side validation that jumps to the first failing step on
submit, footer with Back / Cancel / Continue and — on the review step — **"Save & add another"**
(secondary, `stay`) + a primary **Create** / **Submit**, then a `WizardSuccessPane`. Example for the
**New privacy request** wizard (map the existing `requests.store` fields):
1. **Request** — Type (`TilePicker`/`SelectInput`: access (IPP 6) / correction (IPP 7) / deletion /
   portability / objection), received date.
2. **Data subject** — Subject name, email, relationship to a client (`SelectInput`), identity-
   verification method.
3. **Scope & assignment** — Details (`Textarea`), assigned-to (`SelectInput` from staff), statutory
   due date (auto **+20 working days**, editable with reason).
4. **Review & submit** — `ReviewCard` / `ReviewRow` summary, then Save & add another / Create request.
Apply the same shape to breach / hold / retention / DPIA wizards (steps drawn from each `store`'s
fields). Keep field sets, labels and enums identical to the controllers (FE = BE).

---

## 4. Reconcile the create paths (single source of truth)

Each domain currently has **one navigate-away create page** plus the dashboard. Make the dashboard the
launcher and have the create routes resolve to the **same** modal:
- Dashboard CTA / hero right-click / cluster tile → opens the wizard modal in place.
- The existing `…/create` routes either (a) redirect to the dashboard with the modal auto-opened
  (`?new=request`), or (b) render a thin page that mounts the same wizard component. **No divergent
  field lists** between the modal and the old page. Pick one and apply it to all five domains.

---

## 5. Other surfaces — parity & gaps

- **List pages** (`requests.tsx`, `breaches.tsx`, `legal-holds.tsx`, `retention.tsx`, `dpia.tsx`,
  `deletion-logs.tsx`): bring each to `HeroShell` + `RegisterTableHeader` + right-click rows +
  detail-as-modal + the same wizard for create/edit. Anything you can't finish this pass → record in
  the handover, don't leave a half-migrated page.
- **Client linkage:** privacy requests/breaches reference a `client` relation. Where present, surface a
  read-only "Privacy" affordance on the client profile (compact rows → open the same detail dialog in
  read-only mode). If the linkage/field is missing, **record it in the backend handover — don't add the
  column silently.**
- **Compliance report:** wire **Export compliance report** (`reports.export`) into the hero menu and a
  cluster tile; keep its counts consistent with the hero clusters.
- **Deletion logs:** surface as a tab + cluster tile; the **Execute deletion** action (`deletion.execute`)
  is a critical-tone confirm modal on the same chrome.

---

## 6. Backend changes → **record in handover files, do not silently apply**

Create/append two handover docs in the repo root and write everything an engineer needs there:
- **`PRIVACY_DASHBOARD_BACKEND_HANDOVER.md`** — all server-side work, with file/line refs, proposed
  signatures, validation, and migration notes.
- **`PRIVACY_DASHBOARD_HANDOVER.md`** — remaining frontend/QA/follow-ups, screenshot checklist, and
  anything deferred.

Backend items to specify in the handover (re-audit first, §7):
- `PrivacyDashboardController@index`: add a **`hero` block** (Live + Needs-attention cluster counts,
  NZ `HeroComplianceBadges` counts/booleans), **`tabCounts`**, **`can: { manage }`**, a **paginated
  worklist** for the active tab (`paginate(25)->withQueryString()` + period/search/site filters), and
  **`detail`** loaded only when `?request=` / `?breach=` / `?hold=` / `?dpia=` is present (eager-load
  relations + audit history). Keep the existing six stat blocks or fold them into the hero.
- Confirm the existing lifecycle endpoints (verify-identity, extend, complete, refuse, notify-opc,
  notify-subjects, resolve, release, approve, review, execute) accept the params the modals will send;
  note any missing **`destroy`/soft-delete** routes if right-click Delete is wanted.
- **Model parity:** add `SoftDeletes` + audit to `LegalHold`, `DataRetentionPolicy`,
  `PrivacyImpactAssessment` **only if** Delete is in scope — propose the migration, don't apply.
- **Status canon:** one canonical request-status set + tone map shared FE↔BE (covering
  `identity_verification`, `withdrawn`, `refused`).
- **Statutory due-date rule:** confirm/define the **+20 working day** IPP 6 deadline calc server-side
  (the wizard mirrors it client-side for display only).
- **Client linkage** for the read-only client-profile panel (prerequisite, not in scope to apply here).

---

## 7. Claude Design — run your OWN audit pass FIRST

Before writing code, independently re-audit and confirm/correct everything above:
1. Re-read `privacy/dashboard.tsx`, `PrivacyDashboardController.php`, `routes/privacy.php`, every
   privacy controller in §1, and each create page in §5.
2. Diff the gold-standard imports/exports in §0 against the current repo (kits move occasionally).
3. Confirm the navigate-away create pages, the missing dashboard `detail`/`hero`/`tabCounts`, the
   absent deletion-logs/reports surfacing, and the model soft-delete/audit parity in §2.15.
4. Grep for any other Privacy touch points not listed here (sidebar, client profile, notifications,
   scheduled retention jobs) and add them.
Write your findings (confirmed / corrected / newly found) at the top of `PRIVACY_DASHBOARD_HANDOVER.md`,
then build.

---

## 8. Definition of done (acceptance criteria)
- [ ] `/privacy/dashboard` hero is `HeroShell` + hs-hero-kit, with NZ `HeroComplianceBadges`, two
      clusters wired to tabs/filters, `HeroSegmented` period control, and **right-click quick actions**
      on the hero.
- [ ] `TabStrip` with live server `tabCounts`; the worklist below uses `RegisterTableHeader` +
      `LaravelPagination`; period/search/site filters drive `router.get`.
- [ ] Every worklist row supports **left-click → detail modal** and **right-click → `ShiftContextMenu`**
      with the real lifecycle actions; keyboard accessible; semantic tokens only (zero raw
      hex/oklch/`border-l-*`); no bespoke `getStatusColor`.
- [ ] **Every workflow is a modal built on the Add-client UX** (`WizardShell` styled like
      `add-client-dialog.tsx`): New request / Log breach / New hold / New policy / New DPIA, all
      edits, and every lifecycle action — **none navigates away**; "Save & add another" works via `stay`.
- [ ] Deletion logs **and** Compliance reports are surfaced (tab/tile + export action).
- [ ] The five navigate-away create pages resolve to the **same** modal (no divergent field sets).
- [ ] Proper TypeScript types (no `any[]`); FE option/status sets equal the BE enums.
- [ ] `PRIVACY_DASHBOARD_BACKEND_HANDOVER.md` + `PRIVACY_DASHBOARD_HANDOVER.md` written, with the §6
      backend work and your §7 audit findings; **no backend changed silently.**
- [ ] `npm run lint` + typecheck clean; only the sanctioned on-dark hero `eslint-disable` comments
      (copy them from the kit), no other `no-restricted-syntax` violations.

## 9. Guardrails — do NOT
- ❌ Don't introduce a second hero/row/menu/wizard primitive — compose the kits in §0.
- ❌ Don't keep any navigate-away create/edit page as the primary workflow.
- ❌ Don't leave the quick-link cards as dead-end `<Link>`s with no actions.
- ❌ Don't silently add backend routes/columns/migrations/soft-deletes — record them in the handover.
- ❌ Don't add raw colours, or GDPR/"DSAR"/UK-ICO/72-hour/€ framing — this is **NZ Privacy Act 2020 /
  OPC / IPPs / 20 working days**.
- ❌ Don't add phone frames or mobile-app treatments — web-only.

## 10. Suggested order
1. §7 audit pass → write findings into `PRIVACY_DASHBOARD_HANDOVER.md`; draft
   `PRIVACY_DASHBOARD_BACKEND_HANDOVER.md`.
2. Page chrome: hero (clusters + NZ badges + segmented footer) → tabs → typed worklist + right-click rows.
3. Detail-as-modal (`PrivacyRequestDetailDialog` + siblings) with the Options footer lifecycle bar.
4. The Add-client-style create/edit wizards; wire "Save & add another" (`stay`) + reconcile the five
   create paths (§4).
5. Lifecycle action modals (verify / extend / complete / refuse / notify-opc / notify-subjects /
   resolve / release / approve / review / execute).
6. Surface deletion-logs + compliance report; list-page parity (§5).
7. Lint/types, screenshot each surface, finalise both handover docs.
