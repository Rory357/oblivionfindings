I have full visibility now. The sidebar exposes Privacy as a single entry → `/privacy/dashboard` (the command-centre is the hub). I have the hero kit API, the canonical attachment pattern (Safeguarding), the model layer (no SoftDeletes anywhere), routes (create-before-wildcard already correct), and the working-days gap. Compiling the master plan.

---

# MASTER IMPLEMENTATION PLAN — Privacy Command Centre (`/privacy/dashboard`)

Synthesis of Audit A (design mock), Audit C (controllers) + my direct verification of models, migrations, routes, the H&S hero kit, the sidebar, and the canonical Safeguarding attachment pattern (Audits B/D/E/F returned empty; their scope is covered below from first-hand inspection). This is the build spec. Tracker: `docs/privacy-redesign/PROGRESS.md`.

Worktree root: `C:/Users/steph/Herd/oblivionfindings/.claude/worktrees/nervous-austin-66fe46`. Paths below are repo-relative.

---

## 1. LOCKED DECISIONS

### 1.1 Status → tone maps (verbatim from mock §4; reuse hero-kit `Tone` union `'success'|'warning'|'critical'|'neutral'` + badge variant)

**Request status** (`DataSubjectRequest.status`, enum 7 — keep values):
| value | label | tone |
|---|---|---|
| `received` | Received | warning |
| `under_review` | Under review | warning |
| `identity_verification` | Identity check | info→`neutral` |
| `in_progress` | In progress | info→`neutral` |
| `completed` | Completed | success |
| `rejected` | Refused | critical |
| `withdrawn` | Withdrawn | neutral |

**Breach status** (`DataBreachLog.status`, enum 5 — keep values):
| value | label | tone |
|---|---|---|
| `discovered` | Discovered | critical |
| `under_investigation` | Investigating | warning |
| `contained` | Contained | info→`neutral` |
| `notified` | OPC notified | info→`neutral` |
| `resolved` | Resolved | success |

(Hero kit has no `info` tone — map `info` → `neutral` for dots, use a blue badge variant for table badges if one exists; otherwise `neutral`.)

### 1.2 NZ/IPP re-skin label map (KEEP enum values, change labels only — purge GDPR framing)

**Request type** (`request_type`):
| value | NZ label |
|---|---|
| `access` | Access · IPP 6 |
| `rectification` | Correction · IPP 7 |
| `erasure` | Deletion |
| `restriction` | Restriction |
| `portability` | Portability |
| `objection` | Objection |
| `automated_decision` | Automated decision |

**DPIA review verb**: enum value `requires_dpo_review` stays (DB enum); **relabel UI → "Requires further review"** (drop "DPO"). **Report prop**: rename `ico_notifications` → `opc_notifications` in `PrivacyReportController::compliance` (FE-facing key only; no DB change).

### 1.3 Create-route reconciliation — **DECISION: thin redirect pages → `?new=<domain>`**

The command-centre is modal-first; all five create flows live in in-page wizards keyed off a query param. The seven legacy `*/create` and `*/edit` Inertia pages are retired to **thin redirect shells** that 302 to `/privacy/dashboard?new=<domain>` (create) / `?edit=<domain>&id=<id>` (edit), mirroring the Incidents/Safeguarding retirement pattern. Routes stay (preserve `route()` callers); their controller `create`/`edit` methods become `return redirect()->route('privacy.dashboard', [...])`. **Dashboard reads `?new`/`?edit`/`?view`/`?tab` to auto-open the wizard/detail.** Domains: `request|breach|hold|retention|dpia`.

### 1.4 Document-upload approach — **DECISION: reuse the Safeguarding attachment pattern verbatim**

Canonical pattern confirmed at `app/Models/SafeguardingAttachment.php` + `app/Http/Controllers/SafeguardingAttachmentController.php` + `database/migrations/2026_06_18_000001_create_safeguarding_attachments.php`. We build ONE **polymorphic** `privacy_attachments` table (single table, `attachable_type/_id`) rather than per-domain tables, since five domains need evidence. Storage: `Storage::disk('public')->store('privacy_attachments', 'public')`, `max:10240` (10 MB), `back()->with('success')`. FE uploader is net-new (the mock has NO upload UI — Audit A's critical finding) modelled on the existing `GovernanceAttachmentsPanel.tsx` / `_attachments.tsx` premium-uploader components.

### 1.5 +20-working-days rule location — **DECISION: a `PrivacyWorkingDays` helper, called from `DataSubjectRequest::boot()` + controller**

Replace `now()->addDays(30)` at `DataSubjectRequest.php:84` with `PrivacyWorkingDays::dueDate($receivedAt)` = received + 20 working days, skipping weekends + NZ public holidays via the existing `App\Domain\Hr\Services\PublicHolidayCalendar::isPublicHoliday()`. New thin service `app/Domain/Privacy/Services/PrivacyWorkingDays.php` with `addWorkingDays(CarbonInterface $from, int $n): Carbon` + `dueDate($received): Carbon`. The wizard's FE due-date prefill mirrors this (compute client-side for display, BE is authoritative). `extend` stays operator-supplied. `calculateAverageResponseDays` left as calendar days (reporting metric, out of scope).

---

## 2. DATA-LAYER GAPS & MIGRATIONS (additive, minimal; **run migrations locally**)

| # | Item | Status | Action |
|---|---|---|---|
| M1 | `privacy_attachments` polymorphic table | **ADD** | `id, attachable_type, attachable_id, uploaded_by(null,users), disk(default public), original_name, path, mime(null), size(unsignedBigInt null), notes(text null), timestamps, softDeletes; index [attachable_type, attachable_id]`. Mirror SG migration. |
| M2 | `DataSubjectRequest.request_details` NOT NULL vs validation `nullable` | **FIX** | Make column `nullable()` (additive change-column migration) — safest; avoids 500 on strict MySQL. |
| M3 | `DataBreachLog.likely_consequences` + `measures_taken` NOT NULL | **FIX** | Make both `nullable()`. |
| M4 | `PrivacyImpactAssessment.description` + `residual_risk_level` NOT NULL | **FIX** | Make both `nullable()`. |
| M5 | `PrivacyImpactAssessment.review_notes` | **ADD** | `text null` — so `dpia.review` stops discarding `review_notes` (Audit C #8). |
| M6 | SoftDeletes on the 3 right-click-deletable models | **DEFER/SKIP** | Mock has **no delete** action on requests/breaches/holds/retention/DPIA rows (only "Release", "Resolve", "Execute deletion" — all status transitions, not row deletes). **Right-click delete is NOT in scope** → no SoftDeletes migration needed. `privacy_attachments` gets SoftDeletes (M1) for evidence removal only. |
| M7 | `due_date` / `received_at` columns | **EXISTS** | Both present + cast on `DataSubjectRequest`. No change. |
| M8 | `breach_type` / `severity` on `DataBreachLog` | **EXISTS** (added 2026-04-23) | Already fillable; just wire into store + a hero severity badge if desired. |
| M9 | Attachment relations on 5 models | **ADD (model only)** | `morphMany(PrivacyAttachment::class, 'attachable')` on `DataSubjectRequest`, `DataBreachLog`, `LegalHold`, `PrivacyImpactAssessment`, `DataRetentionPolicy`. New model `app/Models/PrivacyAttachment.php` (copy SafeguardingAttachment, swap relation → `morphTo attachable`). |

**No new permissions** — all 6 keys (`privacy.viewRequests/processRequests/reportBreaches/manageRetention/manageLegalHolds/conductDPIA`) seeded at `RbacSeeder.php:438-443`, granted admin-tier `590-591`, already live.

---

## 3. BACKEND PLAN

### 3.1 `PrivacyDashboardController@index` — rebuilt payload

Extract a `PrivacyKpiService` (`app/Domain/Privacy/Services/PrivacyKpiService.php`, mirrors `HsKpiService`) to keep the controller thin. Payload (Inertia props for `privacy/dashboard`):

```
hero: {
  live: { new_requests, in_progress, completed, breaches },          // Cluster A (mock §1)
  attention: { overdue, opc_notify, subject_notify, active_holds,
               high_risk_dpia, retention_review },                    // Cluster B
  badges: { privacy_act_compliant: bool, opc_open: int,
            overdue_requests: int, active_holds: int,
            retention_active: int },                                  // 5 NZ chips
},
tabCounts: { overview, requests, breaches, legal_holds, retention, dpia, deletion_logs },
worklists: {                                                          // see 3.2
  requests: [...], breaches: [...], legal_holds: [...],
  retention: [...], dpia: [...], deletion_logs: [...],
},
filters: { q, period, site_id },                                     // period: month|quarter|year|all
can: {
  viewRequests, processRequests, reportBreaches,
  manageRetention, manageLegalHolds, conductDPIA,
},
staff: [{id,name}],                                                   // for wizard assignee selects
clients: [{id,name}],                                                 // optional DSR subject link (Audit C #5)
```

`detail` is NOT pushed wholesale — the request-detail modal hydrates from the `worklists.requests` row (it carries all fields). For breach/hold/dpia/retention/deletion detail modals (net-new, mock had only stubs), the row payloads must carry the full record (no separate `show` fetch — `LegalHold`/`DataRetentionPolicy` have **no `show` route**, Audit C #15).

### 3.2 Per-tab worklist queries (eager-load to kill N+1; honour `period` + `q`)

- **requests** (drives overview + requests tabs): `DataSubjectRequest::with(['assignedTo','client','user'])` → newest by `received_at`. Overdue flag via existing `scopeOverdue` (honours `extended_due_date` — fixes Audit C #2 where dashboard's inline calc ignored it). Fields: `reference_number, request_type, subject_name, subject_email, status, due_date, extended_due_date, identity_verified, assigned_to.name, received_at, client/user relation label, request_details`.
- **breaches**: `DataBreachLog::with(['discoveredBy','creator'])` newest by `discovered_at`. Flags: `requires_authority_notification && !authority_notified_at` → "OPC due"; `authority_notified_at` → "OPC notified"; `requires_subject_notification && !subjects_notified_at` → "Subjects due".
- **legal_holds**: `LegalHold::with(['imposedBy','releasedBy'])` newest by `imposed_at`. Active = `scopeActive`.
- **retention**: `DataRetentionPolicy::with(['creator','updater'])` by `model_type`. "review due" if a `review`-type date ≤ today (mock uses a `review` field — map to `last_applied_at`/policy review; if no review column, derive from `last_applied_at` + cadence or omit the red flag).
- **dpia**: `PrivacyImpactAssessment::with(['assessor','approvedBy'])` newest by `assessment_date`. Risk badge from `overall_risk_level`; status from `outcome` (null = "In review", `approved` = "Approved", `requires_dpo_review` = "Requires further review").
- **deletion_logs**: `AnonymizationLog::with('anonymizedBy')` last 30 days, mapped shape (already exists in `DataDeletionLogController::index`). Status `scheduled|executed` (mock has a scheduled path — the only scheduled record `DEL-547` exercises the Execute-deletion branch).

`tabCounts` = `count()` of each worklist under current filters.

### 3.3 Store extensions

- **`requests.store`**: add optional `client_id` (`nullable|exists:clients,id`) + `user_id` (`nullable|exists:users,id`) so `export` has a real subject (Audit C #5). Switch `due_date` to `PrivacyWorkingDays::dueDate()` (via model boot, §1.5). Accept `due` override from wizard (Audit A 6.1 step 3 — editable due date). Align `request_details` with M2.
- **`breaches.store`**: accept `breach_type` + `severity` (columns exist, M8); align nullable with M3.
- **`dpia.store`**: align `description`/`residual_risk_level` with M4.
- **`dpia.review`**: persist `review_notes` → new column M5.

### 3.4 New endpoints

| Route name | Verb/path | Permission | Purpose |
|---|---|---|---|
| `privacy.attachments.store` | POST `/privacy/attachments` | per-domain (see note) | Polymorphic upload. Body: `attachable_type`(allow-listed morph alias), `attachable_id`, `file`(required,file,max:10240), `notes?`. `back()->with('success','Evidence uploaded.')`. |
| `privacy.attachments.download` | GET `/privacy/attachments/{attachment}/download` | per-domain | `Storage::download`. |
| `privacy.attachments.destroy` | DELETE `/privacy/attachments/{attachment}` | per-domain | delete file + soft-delete row. |

**Morph allow-list**: a `PrivacyAttachmentController` resolves `attachable_type` against a hard map `['request'=>DataSubjectRequest, 'breach'=>DataBreachLog, 'hold'=>LegalHold, 'dpia'=>PrivacyImpactAssessment, 'retention'=>DataRetentionPolicy]` (no free-string morph — closes the IDOR surface Audit C flagged on legal-holds). Permission gate maps domain → its existing perm (`request`→processRequests, `breach`→reportBreaches, etc.).

**Build `PrivacyReportController::export`** (currently a "coming soon" stub, Audit C #13 — violates the no-stubs rule). Wire the 4 compliance-reports popover items (mock §1) to real CSV/JSON streamed exports: OPC breach register, Access-request SLA, Retention compliance, Full compliance. Reuse `reports.export?type=...`.

**Working-days helper**: `app/Domain/Privacy/Services/PrivacyWorkingDays.php` (§1.5), unit-tested.

### 3.5 Out of scope (document, don't build): real OPC NotifyUs integration, breach notifications dispatch, review/reminder scheduler (Audit C #10–12) — note in PROGRESS as deferred; `notifyOPC`/`notifySubjects` keep stamping timestamps.

---

## 4. FRONTEND PLAN

### 4.1 Page skeleton — `resources/js/pages/privacy/dashboard.tsx` (full rewrite)

Compose existing kits — **do not rebuild chrome**:
- **Hero**: `hs-hero-kit.tsx` exports → `HeroShell`, `HeroMedallion` (icon `Shield`), `HeroStatusPill` (×2), `HeroCluster`+`HeroClusterTile` (Cluster A "Live · this period" 4 tiles; Cluster B "Needs attention" 6 tiles — §1 tables), `HeroComplianceBadges` (5 NZ chips), `HeroSegmented` (period month/quarter/year/all), footer search input + site filter + Clear. Use primary gradient, NO brandColour (per memory `hs_hero_consistency`).
- **Tabs**: `resources/js/components/rostering/tab-strip.tsx` (`TabStrip`) — 7 tabs, exact order/icons/counts (mock §3): overview, requests, breaches, legal_holds, retention, dpia, deletion_logs.
- **Worklist**: a local `PrivacyWorklist` table component (rows: click→open, right-click→ctx, Enter/Space→open, `tabindex=0`) with per-tab column maps (mock §4). Cell renderers `isStack/isBadge/isEntity/isFlags`. Empty state per worklist (icon + "No {domain} match your filters" + Clear — mock had none, Audit A §9).
- **Right-click menus**: a `PrivacyContextMenu` (reuse the H&S/incidents ctx-menu pattern) — hero menu + 6 per-row menus (mock §2), gated by `can`.
- **Compliance reports popover**: anchored to hero CTA (mock §1) → 4 items → `privacy.reports.export?type=...`.

### 4.2 Detail dialogs

- **Request detail** (`privacy/components/request-detail-dialog.tsx`): full build per mock §5 — 940×720, left rail 4 sections (Overview / Subject & verification / Timeline & deadline / History audit trail), footer status chips + Options bar (Verify/Extend/Complete/Refuse/Export, gated by `open = status ∉ {completed,rejected,withdrawn}`). Deep-link action auto-fire (`?view=request&id=&action=verify`).
- **Breach / Hold / DPIA / Retention / Deletion detail dialogs** (net-new — mock stubbed these): build to the same rail+main+footer pattern, sourcing data from the worklist row. Footer Options bars map to lifecycle endpoints (breach: Notify OPC / Notify subjects / Resolve; hold: Edit / Release; dpia: Approve / Send for review / Edit; retention: Edit / Run review; deletion: Execute deletion).

### 4.3 The FIVE wizards — shared shell

Reuse `resources/js/components/meds/wizard-shell.tsx` (existing wizard shell) OR the H&S `wizard/shell.tsx` pattern. One `privacy/components/privacy-wizard.tsx` driving 5 configs. Field/step maps are **FE=BE locked** to Audit A §6 + Audit C validation tables:

- **Request** (`privacy.requests.store`) — 4 steps. Step1 `request_type`*(TilePicker 6, lift the NZ label map §1.2)+`received`*. Step2 `subject_name`*,`subject_email`*,`relation`(select),`verify_method`(select). **Add** optional `client_id` picker (Audit C #5). Step3 `request_details`(area),`assignee`→`assigned_to_user_id`(select staff),`due`(date, auto +20wd hint). Step4 review. Prefills: `received='2026-06-20'`, `due=+20wd`.
- **Breach** (`privacy.breaches.store`) — 4 steps. Step1 `nature_of_breach`*(area),`discovered_at`*. Step2 `approximate_individuals_affected`(num)→maps to `affected`, `affected_data_categories`(ChipMulti 7), `likely_consequences`(area). Step3 `measures_taken`(area), serious-harm info callout, `requires_authority_notification`+`requires_subject_notification` bools (mock's `serious_harm` select → set both flags). Step4 review.
- **Legal hold** (`privacy.legal-holds.store`) — 3 steps. Step1 `hold_type`*(TilePicker 5),`reason`*(area). Step2 `legal_authority`(text),`review_date`(date). Constrain `holdable_type` to allow-list if used. Step3 review.
- **Retention** (`privacy.retention.store`) — 3 steps. Step1 `policy_name`*,`model_type`*,`description`(area). Step2 `retention_period_years`*(num),`archive_after_years`(num),`legal_basis`(text). Step3 review.
- **DPIA** (`privacy.dpia.store`) — 4 steps. Step1 `assessment_name`*,`project_or_process`*,`assessment_type`*(TilePicker 4). Step2 `processing_purpose`*(area),`legal_basis`*(text). Step3 `overall_risk_level`*(TilePicker 4),`mitigation`→`mitigation_measures`(area→array, wrap single). **Send `residual_risk_level`** (default = overall) to satisfy M4. Step4 review.

Each wizard: completeness % bar, per-step validation, jump-to-first-bad-step, Success pane (Add another / Done). Lift `REVIEW_LABEL` map (mock line 836) verbatim.

### 4.4 Lifecycle action modals (`privacy/components/request-action-modal.tsx`)

460px, per mock §7: `verify` (verification_method select → `verify-identity`), `extend` (extended_due_date + extension_reason → `extend`), `complete` (completion_notes → `complete`), `refuse` (rejection_reason + rejection_legal_basis → `refuse`), `export` (→ `requests.export`). **Promote "Execute deletion" to a critical confirm modal** (red, names record count + model, explicit "Execute deletion" button → `deletion.execute` with `confirm=accepted`) — Audit A §7 recommendation. Add confirms for breach Notify-OPC and hold Release.

### 4.5 Premium document-upload component (net-new — headline feature)

`resources/js/components/privacy/privacy-attachments-panel.tsx` (model on `GovernanceAttachmentsPanel.tsx`): dashed dropzone (drag-drop + browse), type/size hint ("PDF, JPG, PNG · up to 10 MB"), per-file list (type icon + name + size + status), determinate progress bar per file, success-check/error+retry, remove (×), multi-file, client-side type/size validation in the wizard `f.error` style. Wired to `privacy.attachments.store/destroy`. Placements (Audit A §8): Request → Subject & verification + Verify-identity modal (ID docs); Breach → Response step (evidence); Legal hold → Scope (authority doc); DPIA → supporting docs; Deletion → certificate of destruction.

### 4.6 Enums/field lists to lift verbatim

From mock: `REQ_STATUS`, `REQ_TYPE` (with §1.2 NZ labels), `BREACH_STATUS`, `REVIEW_LABEL`. From Audit C: all validation enums (`request_type` 7, breach `status` 5, `hold_type` 5, `assessment_type` 4, risk levels 4). Centralise in `resources/js/pages/privacy/lib/privacy-enums.ts`.

### 4.7 Deletion-logs tab + compliance-reports popover

Deletion-logs tab consumes `worklists.deletion_logs` (existing mapped shape). Reports popover (§4.1) → real exports (§3.4).

---

## 5. CROSS-MODULE

- **Sidebar**: already wired — `app-sidebar.tsx:1307-1311` exposes single "Privacy" → `/privacy/dashboard` under "Compliance & Risk", gated by `can.privacy.viewRequests`. **No change** (the dashboard IS the hub; legacy sub-pages become redirects).
- **Permissions**: no new keys (§2). Seeder gotcha N/A (already live).
- **Client-profile panel**: optional `client_id` link on DSR (§3.3) enables a future "Privacy requests" panel on the client profile — **out of scope for this loop**, but the FK + export branch make it possible. Flag in PROGRESS.
- **Notifications / audit-history**: `AuditableChanges` already on `DataSubjectRequest` + `DataBreachLog` (model-change audit feeds the request detail History section). Real breach/review notifications **deferred** (§3.5). The History rail section (mock §5 #4) renders from model lifecycle timestamps, not a separate audit feed.
- **SHARED FILES touched** (cross-module risk — additive only): `app-sidebar.tsx` (none expected — already correct; verify no regression), `hs-hero-kit.tsx` (reuse only, no edits unless a missing prop forces an additive change — flag if so), `RbacSeeder.php` (no change).

---

## 6. STEP-BY-STEP BUILD ORDER (~11 steps, each shippable, tests after; lint+tsc+tests at end)

> Route ordering already correct (create-before-wildcard in `routes/privacy.php`). New attachment routes are non-wildcard (`/privacy/attachments`), no collision. Deep-link reads are query-params on the existing `/privacy/dashboard` — no new wildcard routes, so no ordering hazard.

1. **Data layer** — migrations M1–M5 (privacy_attachments, 3× nullable fixes, dpia review_notes); `PrivacyAttachment` model + `morphMany` on 5 models. Run locally. *Test: migrate + a model attach round-trip.*
2. **Working-days helper** — `PrivacyWorkingDays` service; swap `DataSubjectRequest::boot()` `addDays(30)`→`dueDate()`. *Test: unit test across a weekend + an NZ public holiday.*
3. **Store/validation alignment** — extend `requests.store` (client_id/user_id/due), `breaches.store` (breach_type/severity), `dpia.store` (residual default), `dpia.review` (persist review_notes), nullable alignments. *Test: feature tests per store (FE=BE contract table).*
4. **Attachment endpoints** — `PrivacyAttachmentController` (store/download/destroy) + allow-listed morph map + routes + per-domain perm gates. *Test: upload/download/destroy + IDOR rejection.*
5. **Reports export** — build `PrivacyReportController::export` (4 types) + rename `ico_notifications`→`opc_notifications`. *Test: each export returns a stream.*
6. **Dashboard payload** — `PrivacyKpiService` + rebuilt `PrivacyDashboardController@index` (hero/tabCounts/worklists/can/staff/clients); fix overdue to use `scopeOverdue`. *Test: dashboard renders, payload shape asserted, counts correct on seed data.*
7. **FE chrome** — `dashboard.tsx` rewrite: hero (hs-hero-kit) + TabStrip + worklist tables + empty states + enums lib. (No wizards/detail yet — tabs render read-only.) *tsc + visual.*
8. **Right-click menus + request detail modal** — `PrivacyContextMenu` + `request-detail-dialog.tsx` (4 sections, Options bar) + deep-link `?view/&action`. *tsc.*
9. **Lifecycle action modals** — verify/extend/complete/refuse/export + Execute-deletion critical confirm + breach/hold confirms; wire to endpoints. *Feature + tsc.*
10. **The 5 wizards** — `privacy-wizard.tsx` + 5 configs + redirect-shell retirement of legacy create/edit pages (§1.3) + `?new/&edit` auto-open. *Feature (store round-trips) + tsc.*
11. **Premium uploader + remaining detail modals** — `privacy-attachments-panel.tsx` wired into wizards/detail; breach/hold/dpia/retention/deletion detail dialogs. **Final gate: `pint` (new files only), `eslint`, `tsc`, scoped `php artisan test` (non-parallel).**

**SHARED-file steps**: Step 7 (verify `app-sidebar.tsx` unaffected), any step that imports `hs-hero-kit.tsx` (Steps 7 — reuse only; if a missing prop is needed, additive + flag in PROGRESS like the lone-workers loop did).

---

## 7. RISKS / WATCH-OUTS

1. **Route ordering** — `routes/privacy.php` already has create-before-wildcard (verified). New attachment routes are static paths; keep them static (`/privacy/attachments/{attachment}`) not under `/privacy/{x}`. Don't add any new `/privacy/{wildcard}` route.
2. **Worktree has no vendor/node_modules junction-resolved app** — per memory `reference_worktree_junction_tests_load_parent_app`: PHP tests autoload the **parent** `app/`, so unmerged worktree backend edits aren't exercised by tests run here. **Verify backend by merging then testing in the parent repo**, OR boot the parent and require the service. Migrations + frontend DO use the worktree. Run `artisan test` with cwd=worktree but know the caveat.
3. **Strict-MySQL 500s** — M2/M3/M4 nullable mismatches will 500 on store before the fix lands; do Step 3 (alignment) before Step 6/10 exercise the stores.
4. **Permissions seeding** — all 6 keys already seeded + live; **no new perms** → no deploy-seeder gotcha. If any new perm were added it would 403 on server until `*PermissionsSeeder --force` (memory `reference_deploy_seeders`) — avoid adding any.
5. **Deploy ordering** — backend deploys before vite; old JS briefly hits new payload (memory `hs_dashboard`). Keep the rebuilt payload **additive-superset** of the old props during transition so the old `dashboard.tsx` doesn't crash mid-deploy, OR accept the self-heal.
6. **Morph IDOR** — never accept free-string `attachable_type`/`holdable_type`; use the hard allow-list map (§3.4). Audit C flagged legal-hold `holdable_type` and retention `model_type` as unconstrained class-name surfaces.
7. **`info` tone gap** — hero kit `Tone` has no `info`; map to `neutral` (dots) / blue badge variant (tables). Don't introduce raw oklch (memory `hs_hero_consistency`).
8. **Deletion execute partial behaviour** — `getPersonalDataFields()` only covers Client/ClientNote/ClientDocument; other `model_type` silently anonymises nothing, and `hard_delete_after_years`/`active_case_exemption` are ignored (Audit C #14). **Surface this clearly in the Execute-deletion confirm modal** (name exactly what will happen) rather than fixing the engine this loop.
9. **DPIA `requires_dpo_review`** — enum value kept (DB), UI relabelled "Requires further review"; don't migrate the enum.
10. **Pint** — not CI-enforced; keep NEW files pint-clean, don't reformat shared/modified files (memory `reference_pint_not_enforced`).
11. **No SoftDeletes on the 3 domain models** — confirmed; right-click delete is correctly out of scope (mock has no delete). Don't add delete affordances.

---

### Key file references
- Routes: `routes/privacy.php` (create-before-wildcard correct).
- Controllers (8): `app/Http/Controllers/{DataSubjectRequest,DataBreach,LegalHold,DataRetentionPolicy,DPIA,DataDeletionLog,PrivacyReport,PrivacyDashboard}Controller.php`.
- Models (6, no SoftDeletes): `app/Models/{DataSubjectRequest,DataBreachLog,LegalHold,DataRetentionPolicy,PrivacyImpactAssessment,AnonymizationLog}.php`. `DataSubjectRequest.php:84` = `addDays(30)` to replace; `:170` = `scopeOverdue` (reuse).
- Canonical attachment pattern: `app/Models/SafeguardingAttachment.php` + `app/Http/Controllers/SafeguardingAttachmentController.php` + `database/migrations/2026_06_18_000001_create_safeguarding_attachments.php`.
- Hero kit: `resources/js/pages/health-safety/components/hs-hero-kit.tsx` (exports: `HeroShell, HeroMedallion, HeroStatusPill, HeroCluster, HeroClusterTile, HeroComplianceBadges, HeroSegmented, HeroSummaryStrip, Tone, DOT_CLASS, fmt`).
- TabStrip: `resources/js/components/rostering/tab-strip.tsx`. Wizard shell: `resources/js/components/meds/wizard-shell.tsx`. Premium uploader model: `resources/js/components/governance/GovernanceAttachmentsPanel.tsx`.
- Holiday calendar: `app/Domain/Hr/Services/PublicHolidayCalendar.php` (`isPublicHoliday()`).
- Sidebar: `resources/js/components/app-sidebar.tsx:1307-1311` (single Privacy entry, no change).
- Permissions: `database/seeders/RbacSeeder.php:438-443, 590-591` (live, no change).
- Existing FE privacy pages (retire create/edit → redirect shells): `resources/js/pages/privacy/{dashboard,requests,breaches,legal-holds,retention,dpia,deletion-logs}.tsx` + `requests/{create,show}`, `breaches/`, `legal-holds/`, `retention/`, `dpia/`, `reports/compliance.tsx`.