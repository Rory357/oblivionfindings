# Hazards Module — Gold-Standard Rebuild · PROGRESS

Branch: `claude/hardcore-zhukovsky-3609cb` (worktree). NZ-only, web-only, modal-first.
Design handoff: `Homes and Site Hazard.zip` → `design_handoff_hazards_module`.
Deep audit (7 agents): [AUDIT.md](AUDIT.md).

Brings the **Hazards module** (`SiteHazard`) to the H&S gold standard, matching
`/health-safety/events`: `HeroShell` hero, `TabStrip`, server-side pagination,
right-click + click rows, detail-as-modal, every workflow as a modal. Three
surfaces: global register `/compliance/hazards`, per-site register
`/sites/{id}/hazards`, client Risk-management read-only panel.

## Status: BUILD COMPLETE — automated verification green; live Chrome verify pending merge/deploy

| Gate | Result |
|---|---|
| `tsc --noEmit` | ✅ 0 errors |
| ESLint (changed files) | ✅ 0 errors, 0 warnings (sanctioned `eslint-disable` only) |
| `vite build` | ✅ built in 3m43s; `hazard-kit`/`hazard-detail-dialog`/`hazard-register` chunks emitted |
| `php artisan migrate` | ✅ clean |
| Feature tests (`HazardModuleTest`) | ✅ 9/9 green (lifecycle, store, detail, actions, review, close, export, gating) |
| Semantic tokens only | ✅ no raw hex/oklch/`border-l-*`/named-color utilities |
| Adversarial multi-agent review | ✅ 16 confirmed (8 rejected) + frontend re-run 2 low → **all fixed** except 1 deferred perf item |

## Post-review remediation (all confirmed findings fixed)

A 4-dimension adversarial review (backend / cross-module / frontend / fidelity, each finding independently verified) returned **16 confirmed + 8 correctly-rejected**; a re-run of the rate-limited frontend reviewer added **2 low**. Fixes applied:

- **HIGH — write-side IDOR.** `assign/transition/review/close/storeAction/completeAction/media` were gated only by route permission, not the per-site object check the read paths use. Added `$this->authorize('view', $hazard->site)` to all seven (resolving `$action->hazard->site` for completeAction). A scoped non-admin can no longer mutate a hazard at a site they can't see.
- **MED — observer stamped `closed_at` on `mitigated`** (corrupted `HsAnalyticsService::hazardClosedByMonth`). Now stamps closure only for `status === 'closed'`.
- **MED — reference-number generation** was count-based (collides with soft-deletes under the unique constraint) → now max-based over `withTrashed()`.
- **MED — per-site register** route lacked `hazards.view` → added.
- **MED — legacy `create.tsx`** white-screened (controller stopped passing `hazardTypes`) → page deleted; `create()` now redirects to `…/hazards?action=add` (opens the modal). Route kept as a working fallback.
- **LOW** — `close()` re-close guard; idempotent migration `down()`; modal Assign/Close gating decoupled from `canManage` (now `can.assign`/`can.close`, matching the row menu + routes); Board-reports gated on `governance.view` + the hero-menu item opens the (now controlled) popover; Overview "Due · overdue" annotation; re-selecting the same row-menu action now reopens its pane (`intentKey`); `reopened` status renders gracefully in the stepper.
- **NIT** — Record-review icon → `ClipboardCheck`; read-only notice icon → `Eye`; client "Open register" uses an explicit `homeSiteId` prop.
- **Deferred (1):** the client Risk-management hazard peek re-runs the full `ClientController::show()` on open/close (a pre-existing partial-reload characteristic of that page); wrapping its heavy props in `Inertia::optional` is the proper fix but risks regressions on a large shared controller — left as a follow-up.
- **Rejected (correctly):** `reopened` "is live" (unreachable), board-reports-list mismatch (matched the concrete spec), and 6 cosmetic non-defects.

Re-verified after remediation: **tsc 0 · ESLint 0 · 9/9 feature tests green.**

## Phases (all built)

- **Phase 0 — Repo hygiene.** No `sites/Show.tsx` collision (controller renders lowercase `sites/show`; only `show.tsx` tracked). Nothing to delete.
- **Phase 1 — Backend.**
  - Migration `2026_06_20_000001_enhance_hazards_module.php`: `site_hazards` +`location`,+`witnesses`,+`document_paths`; `site_hazard_actions` +`tenant_id`,+`reference_number`,+`action_type`,+`due_date` (idempotent `hasColumn` guards).
  - `SiteHazardController` rebuilt: `globalIndex()`/`index()` → `paginate(20)` + Events-shaped props (`tabCounts`, `hero`, `nzBadges`, `filters`, `sites`, `assignees`, `detail`, `can{manage,assign,close,create}`); shared `registerProps()`; `transition()` (start/mitigate + controls + residual via `SiteHazardRiskCalculator`); `review()`; `storeAction()`/`completeAction()`; `media()` (mime-inferred photo/doc); `export()` (CSV); slimmed `store()`/`close()` for in-place refresh + real uploads. Reuses the observer (no double-stamping of `status_changed_*`/`closed_*`).
  - `HazardDetailPresenter` (shared serializer for the modal — used by both `SiteHazardController` and `ClientController`, no duplication). `HazardComplianceSnapshot` (nzBadges, reuses NotifiableIncident/EmergencyDrill/SafetyDataSheet semantics).
  - Routes under `hazards.manage`/`hazards.assign`/`hazards.close`; deep-link fallbacks kept. `can.hazards.manage` added to `HandleInertiaRequests`.
  - Model: `SiteHazard` +scopes `dueSoon`/`unassignedOpen`/`criticalOpen`, +helpers `isDueSoon`/`isWorksafeNotifiable`; new `$fillable`/`$casts`.
- **Phase 2 — Global register** `compliance/hazards/index.tsx`: thin page over the shared `HazardRegister`.
- **Phase 3+4 — Detail-as-modal + workflows.** `hazard-detail-dialog.tsx` (twin of `event-detail-dialog`, on `WizardShell`): rail Overview/Risk/Corrective actions/Evidence/History; options bar gated by status + can; read-only mode. Panes: Assign, Start, Mitigate (control-hierarchy multiselect + residual), Add action, Complete action, Review, Close (gate + `resolution_evidence` upload via `FileDropzone`). `hazard-create-dialog.tsx`: 5-step `WizardShell` wizard (recommended chips, clickable matrix, photo upload via `forceFormData`). Evidence uses the premium `AttachmentUploader`.
- **Phase 5 — Site surface.** `sites/hazards/index.tsx` = same `HazardRegister`, scoped. `sites/show.tsx` Hazards tab → compact embed (top open hazards, deep-links to the scoped register; `?action=add` auto-opens create). No second register surface.
- **Phase 6 — Client panel.** Read-only "Site / environmental hazards" in `risk-management.tsx`, scoped to the client's home (`ClientController::show` → `homeHazards` + read-only `homeHazardDetail`). Click/right-click → read-only `HazardDetailDialog`; deep-links out. No mutation here.

## Key decisions (codebase-wins)
- **New `HazardRiskMatrix`** (in `hazard-kit.tsx`): the existing `risk-matrix.tsx` is a 5×5 numeric (likelihood×consequence) display-only matrix — it can't represent the hazard model's 4-severity × 5-likelihood categorical matrix nor the click-to-set-both the create wizard needs. The JS `RISK_MATRIX` mirrors `SiteHazardRiskCalculator::MATRIX` (server stays authoritative on save).
- **One shared `HazardRegister`** powers both the global and per-site surfaces (no duplicate ~600-line page).
- **`HazardDetailPresenter`** is the single detail serializer (controller + client panel).
- Real reference format kept (`HAZ-YYYY-####` from the observer; corrective actions `CA-####`), not the prototype's `HZ-` mock.
- `nga_paerewa_certified` / `first_aid_ok` left as honest `true` defaults (no backing data source exists; not fabricated). `worksafe_awaiting`/`sds_expiring`/`drills_*` are real counts.

## Reused (not duplicated)
hs-hero-kit (`HeroShell`/clusters/`HeroComplianceBadges`/`HeroSegmented`), register-row-kit (`TONE_BG`/`TONE_DOT`/`FlagBadge`/`RegisterTableHeader`), rostering (`ShiftContextMenu`/`TabStrip`/`EntityFilter`), wizard (`WizardShell`/primitives/`ReviewCard`), `WorkflowRibbon`, premium `file-dropzone` (`FileDropzone`/`StagedFileCard`/`AttachmentUploader`), `LaravelPagination`, `SiteHazard` model+scopes, `SiteHazardRiskCalculator`, `SiteRecommendedHazards`, `SiteHazardObserver` (HsEvent + Control-Room bridge), `LaravelPagination`.

## Pending
- Live Chrome verification on `.test` (after merge) or `.com` (after deploy) — the unmerged worktree can't be served in a browser. Walk: register/hero/tabs/rows → detail modal → full lifecycle open→in_progress→mitigated→closed → create wizard → site embed → client read-only panel.
- Merge + push + deploy (await user). No new permissions to seed (`hazards.*` already seeded); `hazards.manage` is the only newly-surfaced `can` flag and it already exists.
