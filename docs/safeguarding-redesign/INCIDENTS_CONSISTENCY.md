# Safeguarding ↔ Incidents consistency log (§7 hard gate)

> Safeguarding is the **near-twin** of the shipped Incidents redesign and must read as one product.
> On every UI step, open the Incidents equivalent side-by-side, match it 1:1, adopt shared primitives
> rather than forking. Inconsistencies found **in Incidents itself** are logged here with file+line —
> **do not refactor Incidents** beyond genuinely-shared additive primitives (list those separately).

## Incidents reference surfaces (post-redesign, on `feat/safeguarding-redesign` base @ e5d65f54)
- List: `resources/js/pages/incidents/index.tsx` (+ `.design-drops/incidents-redesign/Incidents.dc.html`)
- Detail modal: `resources/js/components/incidents/*` (to confirm in Step 4)
- Hero kit: `resources/js/pages/health-safety/components/hs-hero-kit.tsx`
- Tabs/filters: `@/components/rostering` (`TabStrip`, `RosterTabItem`, `EntityFilter`, `MultiEntityFilter`)
- Right-click rows: `ShiftContextMenu` (`@/components/rostering`); `openRowCtx` copied from `pages/emar/PrnRecords.tsx:193`
- Wizard chrome: `@/components/wizard/shell` + `@/components/wizard/primitives`; Add-Client contract `resources/js/components/clients/add-client-dialog.tsx`

## Per-step comparison
### Step 1 (schema & enum) — no UI surface
Backend-only; nothing to compare. Incidents-parity gate applies from Step 3 (list) onward.

### Step 2 (lifecycle guards + triage) — no UI surface
Backend-only. The service+controller+gate-reasons pattern mirrors how Incidents enforces its lifecycle;
UI parity verified in Steps 4–5. Nothing to log.

### Step 3 (list page) — matched 1:1, adopted as-is
`safeguarding/index.tsx` was authored directly from `incidents/index.tsx`, reusing the **same** primitives
(no forks):
- `HeroShell` + `HeroMedallion` + `HeroStatusPill` + `HeroCluster`/`HeroClusterTile` + `HeroSegmented`
  from `@/pages/health-safety/components/hs-hero-kit` — identical composition/spacing/footer band.
- `TabStrip`/`RosterTabItem` + `EntityFilter` (onDark) + `ShiftContextMenu`/`ShiftCtxItem`/`ShiftCtxState`
  from `@/components/rostering` — identical tab chip/badge API and the same `openRowCtx` right-click
  mechanism + row click → open.
- Same table anatomy (When/ref · subject avatar · severity pill · stage pill · assigned · flags),
  same empty-state + `LaravelPagination` pattern, same `Card`/`CardContent` wrapper.
Intentional safeguarding deltas (per spec, not divergences): `ShieldAlert` medallion vs `AlertTriangle`;
"need-to-know" eyebrow; **counts-only** clusters (no compliance badges); Subject column is redactable
(Restricted hatched row) vs Incidents' Client; 8 safeguarding stages; reviews/monitoring worklist
instead of follow-ups; external-referral banner.
**No inconsistencies found in Incidents.** No shared primitives modified (pure reuse).

### Step 4a (detail modal, read-only) — matched 1:1
`components/safeguarding/concern-dialog.tsx` authored from `incident-detail-dialog.tsx`, reusing the same
`WizardShell` + `ReviewCard`/`ReviewRow` (`@/components/wizard/shell`) + `InfoCard` (`@/components/wizard/primitives`)
chrome: same rail section-switcher, same `footerStart` (severity + status pills) / `footerEnd` ("Open full page"),
same detail-over-list mechanism (`router.get('/safeguarding', {concern:id}, {only:['detail']})`; close drops the
param), same derived-Timeline `<ol>` treatment, same `LinkedRow` idiom. Controller `buildConcernDetail()` mirrors
`IncidentController::buildIncidentDetail()`. Safeguarding deltas (per spec): 7 sections incl. the lifecycle stage
tracker + Risk/External-reports; **Restricted locked state** + "Viewing is logged" audit cue (need-to-know);
`show.tsx` retired to a thin `concern.tsx` shell (kept off viewAny for reporter/assignee deep links) vs Incidents
which deferred its show retirement. **No inconsistencies found; no shared primitives modified.**

### Step 4b (detail Options-bar action panes) — matched 1:1
Same `IncidentDetailDialog` pattern: the gated Options bar lives in `footerEnd` (suppressed while a pane
owns the body), action panes are `useForm` forms on the shared `Field`/`SelectInput`/`StepHead`/`Textarea`
primitives, and every submit uses the `back()` + `flash.error` guard so the detail-over-list refreshes in
place (same `onSuccessGuard` idiom as `ActionPane`/`EditPane`). Safeguarding deltas: permission-aware hide
+ lifecycle disable-with-reason on each button (incidents mostly conditionally-renders); five panes
(assign/risk/investigation/referral/action) + direct mark-informed. **No inconsistencies found; no shared
primitives modified.**

### Step 5 (Triage + gated Close panes) — matched 1:1
Same `ActionPane`-on-`WizardShell` idiom + `useForm` + `back()`/flash-guard. Triage uses the shared
`TilePicker` (substantiate + path) + `Segmented` (risk) + `SelectInput` (lead) + `InfoCard` path notes;
Close uses a computed checklist + `InfoCard` warnings + `Field`/`Textarea`/`Input`. These two surfaces are
Safeguarding-specific (Incidents has review/close/reopen instead) but built entirely on the same wizard
primitives, so they read as one product. **No inconsistencies found; no shared primitives modified.**

### Step 6 (raise wizard) — matched 1:1
`raise-wizard.tsx` authored from `incident-report-dialog.tsx`: same `WizardShell` + `WizardStepPane` +
`WizardSuccessPane`, completeness `Ring` in `footerStart`, Back/Next/Submit `footerEnd`, per-step gating,
`useForm`+`transform`+`preserveState`+`flash`-id success, shared primitives (TilePicker/Segmented/
SelectInput/Field/StepHead/InfoCard). Same retire-to-redirect pattern as Incidents (create→list with the
wizard open). Safeguarding deltas: 6 safeguarding steps, blame-free protective copy, NZ referral step.
**Note (not an inconsistency to fix):** Incidents' `IncidentReportDialog` reads `flash.created_incident_id`
but that key isn't shared in `HandleInertiaRequests`, so its "Open incident" button is effectively dead.
Safeguarding adds `created_concern_id` to the shared flash so "Open concern" works (logged below). Left
Incidents as-is per §7.

### Step 7a (evidence) — matched 1:1
`SafeguardingAttachmentController` mirrors `IncidentController::uploadAttachment/downloadAttachment/
removeAttachment` (validate file max:10240, `$file->store(...,'public')`, `Storage::disk->download`,
`back()`); the detail Evidence section mirrors the Incidents `PhotosSection`/`UploadForm`/`AttachmentRow`
idiom. Safeguarding deltas: per-file `notes` + `is_sensitive` with **sensitivity-gated download**
(`viewSensitive`) + redaction-aware `locked` serialization (Incidents used `portal_visible` instead).
**No inconsistencies found; no shared primitives modified.**

### Step 7b (auto-advance + reminders) — backend, pattern-consistent
`SafeguardingInvestigationObserver` follows the existing observer idiom (registered in AppServiceProvider
beside `SafeguardingConcernObserver`); `safeguarding:review-reminders` mirrors the H&S/governance scheduled
commands in `routes/console.php` (`->timezone('Pacific/Auckland')->dailyAt(...)`). No UI surface; nothing to
compare. No inconsistencies; no shared primitives modified.

### Step 8 (cross-module) — additive incident-side wiring
X1 surfaces spawned concerns on the incident detail via the same `LinkedSection`/`LinkedRow` idiom (a new
relation + a payload key + render rows) — additive, no Incidents behaviour changed; need-to-know via
`can_view`. X3 (terminal state-sync) + the authority currency are safeguarding-side. X2's operator card is
left to the Control Room redesign drop. No primitives forked.

## Inconsistencies found in Incidents (do NOT refactor — log only)
- `IncidentReportDialog` "Open incident" reads `flash.created_incident_id`, never shared by
  `HandleInertiaRequests` → button never appears. Not fixed (would touch Incidents); Safeguarding shares
  its own `created_concern_id` instead.
- _(none yet)_

## Shared primitives edited (additive, both modules use)
- Step 6 — `app/Http/Middleware/HandleInertiaRequests.php`: +1 flash key `created_concern_id` (additive,
  alongside the existing `created_*_id` keys). No existing behaviour changed.
- Step 8 — `app/Models/ClientIncident.php` (+`safeguardingConcerns()` relation),
  `app/Http/Controllers/IncidentController.php` (`buildIncidentDetail` +`safeguarding_concerns` payload +
  eager-load), `resources/js/components/incidents/incident-detail-dialog.tsx` (+LinkedSection rows + type),
  `app/Providers/AppServiceProvider.php` (+`SafeguardingInvestigation` observer, Step 7b),
  `routes/console.php` (+`safeguarding:review-reminders` schedule, Step 7b). All additive; no existing
  behaviour changed. 132 incident tests still green.
