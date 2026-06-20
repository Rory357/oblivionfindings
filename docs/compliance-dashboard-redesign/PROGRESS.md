# Compliance Dashboard Redesign — PROGRESS (Claude Code build)

Self-paced `/loop` build. Redesign the existing `/compliance` dashboard to the H&S gold
standard (hero kit + KPI cards + "What's due" assurance rail + Control-Room triage + trends +
4 Add-Client-idiom modal wizards), reusing existing governance/control-room backends (no forks).

Design drop: `.design-drops/compliance-page/` (HANDOFF.md, BACKEND_AUDIT.md, GAP_ANALYSIS.md,
`Compliance Dashboard.dc.html` mockup, screens/). NZ-only (NZD/en-NZ), web-only, modal-first.

Worktree: `.claude/worktrees/frosty-colden-06ae1c` (node_modules junction + vendor robocopy + .env set up).

---

## §0 — LOCKED decisions & verified contracts (single source of truth)

### Existing page (to replace, in place)
- Page: `resources/js/pages/compliance/index.tsx` — `PageHero` + 6 bespoke `KpiCard` + `severityColors`
  map + **raw hex chart fills `#ef4444`/`#dc2626`** (ESLint `no-restricted-syntax` violations) +
  control-room section + 3 charts. Read-only, no row interactions, no states. **Rebuild on hs-hero-kit.**
- Controller: `app/Http/Controllers/Compliance/ComplianceDashboardController.php` — inlines all KPI
  queries (`ClientIncident`, `ClientControlledDrugDiscrepancy`, `ClientMedicationAdministration`,
  `ClientBreakGlassAccess`, `ClientSupportPlan`, `AuditLog`, `ControlRoomAlert`). Lift to a service.
- Route: **mis-registered in `routes/medications.php:51-53`** — `GET /compliance` name `compliance.index`
  gate `compliance.view`. Relocate to new `routes/compliance.php`.

### Route loading
- `bootstrap/app.php` → `withRouting(web: routes/web.php …)`. `routes/web.php` `require`s every module
  file (`control-room.php:195`, `medications.php:198`, `governance.php:210`, …). **Relocation =** create
  `routes/compliance.php`, add `require __DIR__.'/compliance.php';` to web.php, delete the medications.php block.

### Governance compliance backend (REUSE — do NOT duplicate)
- Controller `app/Domain/Governance/Http/Controllers/ComplianceController.php`; service
  `app/Domain/Governance/Services/ComplianceEngineService.php`; models under `app/Domain/Governance/Models/`.
- **Endpoints (the 4 wizard targets):**
  | Wizard | Route name | Method · URL | Gate | Validated fields |
  |---|---|---|---|---|
  | Log obligation | `governance.compliance.store` | POST `/governance/compliance` | `governance.compliance.manage` | framework(req str), obligation_reference(null ≤50), title(req ≤255), description(req), requirements(null), due_date(null date), owner_id(null exists), priority(null low/medium/high/critical) |
  | Record evidence | `governance.compliance.evidence.upload` | POST `/governance/compliance/{obligation}/evidence` | `governance.compliance.manage` | evidence_type(req: document/audit_report/certification/system_export/attestation), title(req ≤255), description(null), **file(req file max 10240KB)**, valid_until(null date after:today). **obligation = ROUTE PARAM.** |
  | Complete obligation | `governance.compliance.complete` | POST `/governance/compliance/{obligation}/complete` | `governance.compliance.manage` | evidence_ids(null array of compliance_evidence ids) |
  | Log notifiable | `governance.compliance.notifiable-incident.store` | POST `/governance/compliance/notifiable-incident` | `governance.compliance.manage`(`notifyIncident`) | incident_type(req: death/serious_harm/serious_injury/health_safety/privacy_breach), notification_authority(req: worksafe/health_nz/privacy_commissioner/charities_services), title(req), description(req), severity(req: critical/high/medium), occurred_at(req date), discovered_at(null date), related_incident_id(null int) |
- **Frameworks (10, real keys → labels from `ComplianceObligation::getFrameworkLabel()`):** charities=Charities
  Services · nga_paerewa=Ngā Paerewa NZS 8134:2021 · hdsa_safety=Health and Disability Services (Safety)
  Act · privacy_act=Privacy Act 2020 · hip_code=Health Information Privacy Code · hswa=Health and Safety
  at Work Act 2015 · employment=Employment Relations Act · funding_moh=MoH/Health NZ Funding ·
  funding_msd=MSD Funding · funding_acc=ACC Funding.
- `ComplianceObligation` status (auto-derived in `boot()::saving` from due_date): not_due / due_soon /
  overdue / complete (+ exempt). Scopes: byFramework, overdue, dueSoon($days=30), forOwner. `markComplete`,
  `signOff`, `evidence()`, `reminders()`, `owner()`.

### ⚠️ Confirmed GAPS to fix (audit findings)
1. **`priority` + `requirements` silently dropped.** `StoreComplianceObligationRequest` accepts them but
   (a) `compliance_obligations` has **no such columns** (migration `2026_02_06_100060`), (b) not in
   `$fillable`, (c) `ComplianceEngineService::createObligation()` doesn't pass them. → **Add migration**
   (`priority` string default 'medium', `requirements` text null) + fillable + thread through service
   signature + controller. Mockup's Log-obligation wizard shows priority → must persist.
2. **Modal/redirect mismatch.** `store`→redirects `governance.compliance.show`; `notifiable`→`index`;
   `complete`/`evidence`→`back()`. A modal-first `preserveState` wizard on `/compliance` would navigate
   away. → Make governance write actions **`_modal`-aware**: when `$request->boolean('_modal')`, `back()`
   with flash instead of redirecting to show/index. Governance pages don't send `_modal` ⇒ unaffected.
   (Mirrors the add-client `_modal:true` convention.)
3. **Permission split.** `compliance.view` (RbacSeeder:332, module Compliance) gates the page;
   `governance.compliance.manage` (GovernancePermissionsSeeder) gates the wizards — **different role sets.**
   → Gate wizard buttons on `auth.permissions.compliance.governanceManage`; server already enforces.
4. **`frequency` hardcoded `'annual'`** in `createObligation`. Mockup "Schedule" step picks a frequency
   (monthly/quarterly/annual/ad_hoc/event_driven) → thread `frequency` through too (column exists).

### Control Room (REUSE — convenience triage only, no parallel store)
- Detail `control-room.alerts.show` GET `/control-room/alerts/{alert}`. Triage POSTs (all exist):
  `.acknowledge` `.confirm` `.dismiss` `.triage` `.resolve` `.close` `.note` `.assign-to-me`
  (`routes/control-room.php`). Gate ≈ `controlRoom.alerts.manage`. Front-end gate:
  `auth.permissions.controlRoom.alertsManage` (already exposed in HandleInertiaRequests).
- `ControlRoomAlert` serialized shape on the page: id, alert_type, severity, status, source, triggered_at.

### "What's due / assurance" rail data sources
- **Obligations due/overdue:** `ComplianceObligation::dueSoon(30)` ∪ `overdue()` with owner/framework/due_date.
- **Care-plan reviews due:** `ClientSupportPlan` where `next_review_at <= now()+30d` (overdue `< now()`);
  `next_review_at` is a `date` cast; `client()` belongsTo `Client`. Return rows (client name, plan id, date).
- **Registers due:** no single source today → DEFER (documented follow-up); rail ships obligations + reviews
  (both real data) — no fake rows (per hide-unbuilt-actions).

### Shared UI kit (REUSE — import-ready)
- Hero `@/pages/health-safety/components/hs-hero-kit`: `HeroShell({children,footer})`, `HeroStatusPill`,
  `HeroMedallion({icon})`, `HeroCluster({title,icon,children})`, `HeroClusterTile({href?,label,value,caption,
  tone,delta?,deltaTone?})`, `HeroComplianceBadges({items?|canonical counts})` (use `items` override —
  compliance story, not the H&S canonical row), `HeroSegmented({label,items,value,onChange,ariaLabel,
  variant:'pill'|'segmented'})`, `HeroSummaryStrip`, `HeroSummaryMetric`, `fmt`, type `Tone`
  (success/warning/critical/neutral), type `HeroComplianceBadge={icon,tone,label}`.
- `@/components/rostering`: `ShiftContextMenu({ctx:ShiftCtxState,onClose})` where `ShiftCtxState={x,y,tag,
  tagBg?,tagColor?,meta,items:ShiftCtxItem[]}`, `ShiftCtxItem={sep:true}|{icon,label,sub?,kbd?,tone?:'primary'|
  'critical',onClick}`; `EntityFilter({label,allLabel,items:{id,name,description?}[],value:number|null,onChange,
  onDark?,pluralLabel?})`; `TabStrip`.
- `@/components/ui/status-badge`: `StatusBadge({variant?:success|warning|critical|info|neutral, status?, label?,
  size?})`. **Delete `severityColors`; use this for every status/severity.**
- States: `EmptyState`/`EmptyList`/`EmptyError` (`@/components/ui/empty-state`), `ErrorState({title,message,
  onRetry})`, `LoadingState({message})`, `SkeletonTable({rows,columns})`.
- Charts: `@/pages/health-safety/analytics-charts` → `TOKEN` map (`var(--status-*)`/`var(--primary)`/
  `var(--chart-*)`/`var(--border)`), `ChartCard({title,subtitle?,aria,action?,children,table?})`,
  `SingleAreaChart`, `severityFill(s)`. **No raw hex — kill `#ef4444`/`#dc2626`.**
- Wizard idiom = `resources/js/components/clients/add-client-dialog.tsx` + `@/components/wizard/primitives`
  (`Field`,`FieldErr`,`Segmented`,`ChipMulti`,`SelectInput`,`StepHead`,`SubHead`,`InfoCard`,`TilePicker`,
  `Ring`,`WIZARD_RAIL_CLASS`,`WIZARD_PROGRESS_*`,`WIZARD_FOOTER_CLASS`). Shell contract: `Dialog` w/
  `[&>button]:hidden`, `h-[min(92vh,860px)]`, 248px stepper rail (icon/label/blurb + active/complete),
  completeness meter, header `Step X of N · Label`, 3px top progress bar, scroll-contained body, footer
  Back/Cancel/Continue → review adds **Save & add another** + Create, per-step validation jumps to first
  failing step, **SuccessPane** (status-success ring + Check + PartyPopper, title, blurb, Add another / Go-to).
- Kebab+right-click share ONE items array (pattern: `operations/clients/index.tsx` `ClientKebab`+`ClientContextMenu`).

### Inertia auth share
- `HandleInertiaRequests::buildUserPermissions()` returns `auth.permissions.<group>.<action>` booleans
  (has `controlRoom`, `medications`, `hazards`, … blocks). **Add a `compliance` block:**
  `{ view: compliance.view, governanceView: governance.compliance.view, governanceManage: governance.compliance.manage }`.

---

## §Steps

- [x] **1. Backend foundation** ✅ (syntax-clean + migration applied to dev DB) — migration (`priority`+`requirements` on compliance_obligations); thread
  priority/requirements/frequency through `StoreComplianceObligationRequest`→`ComplianceEngineService::
  createObligation`→model fillable; `_modal`-aware governance write actions; new
  `app/Services/Compliance/ComplianceMetricsService.php` (exceptionKpis/whatsDue/controlRoomSummary/trends,
  period param, scopeOrg); relocate route to `routes/compliance.php`; expose `compliance` perms in Inertia;
  controller returns new props (period, whatsDue rows, frameworks, owners, obligations list, manage flags).
- [x] **2. Hero + KPI cards** ✅ — HeroShell + hs-hero-kit (eyebrow pill, medallion, 2 clusters, compliance
  badges via `items`, period 14d/30d/90d → `router.get('/compliance',{period})`, footer quick-action buttons
  gated on `can.manage`). 6 KPI stat cards: value + token sparkline (`Spark`) + StatusBadge + drill +
  right-click(`ShiftContextMenu`)/`Kebab`. Killed PageHero/KpiCard/severityColors/hex.
- [x] **3. What's due rail** ✅ — obligation + care-plan-review rows (per-type icon/title/framework meta/due
  colour/StatusBadge/Kebab + right-click); All/Obligations/Reviews tab filter; per-type actions; EmptyState.
- [x] **4. Control Room section** ✅ — token stat tiles + StatusBadge alert list + token AreaChart (14d);
  convenience acknowledge/resolve (existing `/control-room/alerts/{id}/…` endpoints, gated on `can.triage`)
  + deep-links; "owned by Control Room · convenience triage only" label.
- [x] **5. Trends** ✅ — ChartCard + TOKEN charts (incidents-by-severity bars w/ `severityFill` Cells,
  MAR-outcomes 4-line, CD-discrepancy area). No hex.
- [x] **6. Wizard shell + Log obligation** ✅ — full 4-step (Obligation TilePicker → Details → Schedule
  Segmented freq/priority + owner → Review → SuccessPane) on wizard/primitives, add-client parity, `_modal:true`.
- [x] **7. Record evidence (premium drag-drop upload) + Complete obligation + Log notifiable incident** ✅ —
  all on the same WizardShell. Evidence modal: obligation picker + **premium `DocumentDropzone`** (drag-drop,
  type allowlist + 10MB guard, image preview, replace/remove) + evidence_type TilePicker + title/valid_until/
  notes; `forceFormData`. Complete: picker + confirm + cross-link to evidence. Notifiable: 5 types/4 NZ
  authorities/severity, occurred/discovered, linked-incident.
- [x] **8. Nav entry + cross-links** ✅ (partial) — `/compliance` nav entry **already exists** (app-sidebar
  H&S sub-panel → "Compliance & Risk" → Compliance, gated `can.compliance.view`; pre-existing, points at the
  rebuilt page). Outbound cross-links DONE (register `/governance/compliance/{id}`, calendar, Control Room,
  clients `?tab=care_plans`, incidents, reports). **Governance-dashboard compliance card DEFERRED** — inbound
  discoverability already covered by the nav; avoids adding unverifiable PHP/React surface in the worktree.
- [x] **9. Quality gates + cleanup** ✅ — tsc **0 errors** (whole app, after `wayfinder:generate`), vite
  **build clean** (exit 0), `php -l` clean on all PHP, **pint** formatted. 5 PHP feature tests WRITTEN
  (3 in ComplianceDashboardTest: all-metrics-props smoke, period normalises, surfaces-due-obligation; 2 in
  GovernanceComplianceTest: `_modal` priority/requirements/frequency persistence, manager wizard-reference-data)
  — **see ⚠️ below: cannot run in this worktree.** Then merge/deploy/Chrome-verify (user).

## ⚠️ Worktree junction blocks PHP runtime verification (root-caused)
The vendor dir is a **junction to the parent repo's `vendor/`**. Composer's PSR-4 map (baked into the shared
autoload files) resolves `App\*` to **`<parent>/app/`**, NOT this worktree — confirmed via reflection:
`ReflectionClass(ComplianceDashboardController)->getFileName()` = `C:\Users\steph\Herd\oblivionfindings\app\…`
(parent), and `class_exists(App\Services\Compliance\ComplianceMetricsService)` = **false**. So `php artisan test`
/ any kernel boot here runs the **parent's old `app/` code + a mismatched migration state**, ignoring every PHP
change in this worktree. The 5 "failures" seen (missing `whatsDue`/`can` props, `Unknown column requirements`)
were **entirely this artifact** — the parent's pre-rewrite controller has only `kpis/controlRoom/charts`, and the
parent migration set lacks `priority`/`requirements`. The on-disk worktree PHP is correct (lint + pint + careful
read). **Frontend IS verified** (Vite/tsc use worktree files directly → real signal). **PHP tests must be run
from the parent after merge** (`pest --filter=Compliance` + `--filter=GovernanceCompliance`). This matches the
documented repo gotcha (see memory `reference_worktree_vendor` / prior loops).

## ✅ Verification summary (what IS proven here)
- **tsc --noEmit: 0 errors** across the app (Wayfinder modules generated locally; my 4 wizards + index.tsx clean).
- **vite `npm run build`: exit 0** (production bundle built, Wayfinder vite plugin ran).
- **php -l: clean** on controller(s), request, model, 2 services, migration, 3 route files, 2 test files.
- **pint: clean/auto-formatted** on all touched PHP.
- Backend contracts re-verified by reading: store() threads priority/requirements/frequency + `_modal` back();
  model scopes (`overdue`/`dueSoon`) + columns exist; routes single top-level `/compliance`; HeroClusterTile
  `href?`; `compliance.calendar` route exists; controller props ⇆ index.tsx Props match 1:1.
- Migration hardened: independent guarded column adds + `SHOW INDEX` guard (idempotent; safe under the per-column
  `ALTER` behavior of this Laravel build). MAR-trend query made portable (`CASE WHEN … = 'x'`, single-quoted).

## §Adversarial review pass (2 parallel agents, static — worktree can't run PHP)
Both agents confirmed the core contracts hold (all 10 controller props ⇆ index.tsx Props match;
all 4 wizard endpoints + field names + enum values match server validation; WizardShell add-client
idiom correct; `set()` casts sound; no `.find()` crashes; tone 'info'→'neutral' cast correct; empty
manage-arrays handled). **No HIGH bugs.** 5 confirmed findings — ALL FIXED (commit after `5d163e61`):
- **MED** `HandleInertiaRequests` — my added `compliance` perms block was a **duplicate array key** (PHP
  keeps the last → my `governanceView`/`governanceManage` silently dropped) AND redundant (page uses
  controller `can.manage`/`can.triage` props; `governance.compliance.{view,manage}` already exposed). → **Removed** my block (net no change vs main).
- **MED** index.tsx "what's due" `StatusBadge` rendered an **empty pill** for `overdue`/`complete` rows
  (children `undefined`). → labels for every status + humanised fallback.
- **LOW** record-evidence `URL.createObjectURL` never revoked (blob leak). → `useEffect` revoke cleanup.
- **LOW** complete-obligation `form.post` had no `onError` (silent fail). → added error toast for parity.
- **LOW** controller `relatedIncidents` mapped non-existent `$i->reference` (dead branch; ClientIncident
  has no such column). → dropped to `title ?? "Incident #{id}"`.
Re-verified after fixes: **tsc 0, php -l clean, pint clean.** Non-findings (verified clean): authz/IDOR
(single-org app; consistent with canonical ControlRoom no-scope), metrics queries/columns/scopes, store
path threading, migration idempotency, permission keys, Carbon usage, empty-string→null middleware.

## §Notes / status log
- Audit done by direct reads (two sub-agent workflows hit transient rate-limit then a hard session limit
  resetting 4:50pm Pacific/Auckland — pivoted to self-serve reads). Workflows can be retried after reset for
  an adversarial multi-agent review pass (Step 9).
