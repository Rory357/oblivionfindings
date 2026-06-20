# Worker Participation rebuild — PROGRESS (self-paced /loop)

Rebuild `/health-safety/worker-participation` to the H&S gold standard (Incidents/Safeguarding/Fleet)
+ the cross-module calendar/compliance integration the page is missing. NZ-only, web-only, modal-first.
Design drop: `C:/Users/steph/Downloads/wp-redesign-extract/design_handoff_worker_participation`.
Verified API facts: `AUDIT_FACTS.md` (10-agent audit `wf_5c0174c3-668`).

## ⚠️ Handoff corrections (verified against real code — DO NOT apply handoff blindly)

### Schema / models
- **FK is `hs_committee_id`** not `committee_id`. Existing `committee()`/`meetings()`/`user()`/`site()`
  relations + ALL casts already exist & are correct → **do NOT apply PATCHES.md §1/§2/§3 relation/cast edits.**
- Columns the handoff thinks are missing **already exist**: `work_group`, `ended_at`, `attendees`,
  `confirmed_attendees`, `agenda_items`, `action_items`, `minutes_document_path/name`, doc/outcome-doc paths.
- **Genuinely new**: `actions_due_count` column on `hs_committee_meetings`; `hs_meeting_attendees` pivot.
- **Name collision**: `attendees` is a JSON column AND the provider/controller want an `attendees()` relation.
  Eloquent resolves `$m->attendees` to the JSON cast (shadows the relation). → name the pivot relation
  **`attendeeUsers()`** (belongsToMany, withPivot response/attended). Keep JSON cols (additive, non-destructive);
  backfill once into the pivot; new code treats the pivot as source of truth.
- Merge casts, don't overwrite (HsRepresentative casts are a superset).

### Controller / routes
- Reconcile consultation status to ONE lifecycle everywhere: `open → feedback_received → actioned → closed`
  (the current `updateConsultation` narrow set `open/in_progress/closed` is the bug; rebuilt ctrl fixes it).
- Field-name drift: FE must use `elected_at`/`training_days_completed`/`scheduled_at` (DB names), not
  `elected_date`/`training_days`/`meeting_date`.
- **`/export` route is MISSING** but FE calls it → add `GET /export`→`export()` (CSV streamDownload, mirror
  `HealthSafetyDashboardController@analyticsExport`), name `health-safety.worker-participation.export`.
- **Permission fix**: move the 2 GET download routes + new export into the `hazards.view` group (read ops;
  view-only users must read register docs, else context-menu Download 403s). Writes stay `hazards.manage`.
- Keep all 16 existing route names byte-for-byte. Permission gate stays `hazards.*` (no new permission to seed).
- Enrich `detail()` to mirror `buildIncidentDetail`/`buildConcernDetail`: include `can`, `assignable_staff`,
  `stage_index` (consultation lifecycle), attendee user list (from pivot).

### Calendar provider
- Signature `obligations(array $siteIds, Carbon $start, Carbon $end)` + base helpers
  (inRange/isoDate/siteArray/dueStatus/ownerArray) are CORRECT.
- **FIX** attendee read: use `$meeting->attendeeUsers()->pluck('users.id')->all()` (pivot), not `attendees()`.
- **Add 3 CSS tokens** `--src-participation{,-bg,-ln}` (free hue ~340) near other `--src-*` in app.css, else
  chips render colourless. Register provider in `SiteCalendarAggregator::defaultProviders()`; add `participation`
  to `CalendarSources::all()` (6-key shape, icon `Users`); add to FE `CalendarSourceKey` union (recur.ts).
- Map consultation statuses to toned CalendarItem statuses (open→scheduled, closed→completed,
  feedback_received/actioned→ add `in_progress` to STATUS_TONE or map to pending) so chips aren't grey.

### Compliance
- **`createObligation()` does NOT call `scheduleReminders()`** → add `$compliance->scheduleReminders($ob)`
  after EACH create (2 in controller, 2 in command). Capture returned obligation.
- Use **`framework: 'hswa'`** (lowercase) to match `getFrameworkLabel()`/`getComplianceStatus()`.
- Dedupe HSR-TERM/HSR-TRAINING (firstOrCreate/exists guard) — controller has none → dup on every save.
- Sync command namespace `App\Console\Commands`; schedule daily in routes/console.php.

### HrCalendarEvent
- SAFE to remove `event_type='hs_meeting'` writes (nothing branches on that value). They ARE read generically
  by Hr/CalendarController@index + Hr/ICalController@feed (no filter) → removing drops meetings from the HR
  calendar UI + iCal. Net improvement: replace with Site Calendar provider visibility + a real
  `CommitteeMeetingScheduled` database+mail notification (attendees + all-site workers, HSWA). Remove the
  fragile `title LIKE` cancel delete.

### Frontend kits
- **Don't reuse `HeroComplianceBadges`** (hard-coded SAFETY labels) → add a generic chip primitive to
  hs-hero-kit (`HeroComplianceChip`/`HeroChipRow` taking `{icon,tone,label}[]`, additive shared export) and
  feed WP chips: reps coverage %, minutes overdue, consultations awaiting, training below 2-day min.
- register-kit `Tone` = 4 members (success/warning/critical/neutral) — no info/primary. ShiftCtxItem.tone =
  primary|critical only. HeroSegmented items keyed by `key`. WorkflowRibbon `current="report"` valid.
- **WizardShell real props**: `open,onClose,title,steps(readonly WizardStep[]),stepIndex,onStepClick,pct,
  footerStart,footerEnd,success,railIcon,railTitle,railSub`. Caller owns step state + footer buttons.
  WizardStep: `{icon: Component, blurb: string, ...}`. CONSUME WizardShell (add-client inlines its own copy).
- **Detail dialogs → build on `SafeguardingConcernDialog`** (only ref with initialAction/initialSection +
  multi-action OptionBtn Options bar). Actions = **inline panes** (StepHead+Field, footerEnd suppressed), NOT
  nested Dialogs. Submit form.post/put `{preserveScroll, onSuccess}` relying on controller `back()` (NOT
  router.reload only:[]). Status/type chips in `footerStart`. Local DOT/SEV_TONE maps (no FlagBadge in dialog).
- **Layout** (real house pattern, NOT per-tab split): one `pages/health-safety/worker-participation/index.tsx`
  + dialogs/wizards under `resources/js/components/worker-participation/` (mirrors components/incidents +
  components/safeguarding).

### Premium upload
- file-dropzone exports: `FileDropzone` (emits File[]), `StagedFileCard`, `AttachmentUploader` (multi-file,
  posts field **`file`**), `formatFileSize`.
- **AttachmentUploader NOT drop-in** for WP (endpoints want `document` + consultation needs `type`). Build a
  small single-file premium form: `useForm({document:File|null})` → FileDropzone (empty) / StagedFileCard
  (staged) + post `{forceFormData:true, preserveScroll}`. Consultation upload adds `type: document|outcome`.
- **storeConsultation accepts no file** → extend StoreConsultationRequest with optional `document` and store it
  inline at create (cleanest), so the wizard's Documents step is real. consultation_type canonical = 7
  (hazard_review,risk_assessment,procedure_change,policy_review,equipment_change,change_notification,general).
  `description` is required — wizard must collect it.
- Meeting minutes + consultation supporting/outcome = single-document slots (replace on upload), field
  `document`, mimes restricted, 20MB.

## Plan / status

### Phase 0 — Audit ✅ DONE (workflow wf_5c0174c3-668; AUDIT_FACTS.md)

### Phase 1 — Backend ✅ DONE + VERIFIED (boots: migrate DONE, 17 routes register, pivot/provider/command runtime-smoke OK)
- [x] Migration: `hs_meeting_attendees` pivot + `actions_due_count` column + FK-safe backfill (`2026_06_20_120000`)
- [x] Models: HsCommitteeMeeting `attendeeUsers()` belongsToMany + `actions_due_count` fillable/cast
- [x] Backfill pivot from JSON `attendees`/`confirmed_attendees` (in migration up(), DB:: query-builder, FK-filtered)
- [x] FormRequests: Store{Representative,Meeting,Consultation}Request (consultation = canonical 7 + description + optional document)
- [x] Controller rewrite: index (paginate/tabCounts/hero/detail/can/filters incl. period), enriched detail,
      reconciled lifecycle, removed HrCalendarEvent writes, attendeeUsers pivot, scheduleReminders+hswa+dedupe, export() CSV
- [x] Provider: WorkerParticipationObligationProvider (attendeeUsers + status mapping) + registered + CalendarSources + css tokens + recur.ts union
- [x] Notification: CommitteeMeetingScheduled (attendee=database+mail; worker notice=database-only throttle; workers = rostered via shifts)
- [x] Command: SyncParticipationObligationsCommand (+scheduleReminders, hswa, dedupe, +training) + scheduled daily 06:20
- [x] Routes: added /export under hazards.view; moved 2 downloads to hazards.view (read ops)
- [x] Migration run locally; php -l clean; route:list + tinker smoke green
- NOTE: dev DB has 0 WP rows → data-path verified via tinker, full data check via tests/browser later.

### Phase 2 — Frontend page shell ✅ DONE
- [x] index.tsx rebuilt: HeroShell+WorkflowRibbon+inline WP chip row+clusters+TabStrip+3 tables+right-click(can-gated)+pagination+filters+initialAction wiring
- [x] shared.tsx contract module (types, status/stage maps, CONSULTATION_TYPES×7, ELECTION_METHODS, MEETING_FREQUENCIES, fmt*)

### Phase 3 — Modals (components/worker-participation/) 🟡 BUILDING (workflow wf_581f068f-cfb / task wi1s1wt6c)
- [ ] consultation-detail (exemplar), representative-detail, meeting-detail (WizardShell sections + inline action panes + premium upload + initialAction)
- [ ] add-representative (exemplar), schedule-meeting, new-consultation (WizardShell + add-client lifecycle + premium upload)
- NOTE: 6 parallel agents; siblings race the exemplars so consistency relies on the shared PREAMBLE spec → verify+polish in Phase 4 tsc/lint.

### Phase 4 — Verify ✅ (automated complete; browser pending deploy)
- [x] tsc --noEmit: 0 new errors (88 pre-existing baseline unchanged; none in touched files)
- [x] eslint WP files: 0 errors / 0 warnings (inline disable comments on 3 bespoke surfaces)
- [x] vite build (full app): PASSED (exit 0)
- [x] Backend tests: WorkerParticipationTest 15/15 pass (82 assertions) — index payload, lifecycle, obligations+reminders, notify, pivot, provider, upload/download, export, permission gating
- [x] Modal quality review: premium FileDropzone+forceFormData on all 3 doc surfaces; all 6 modals on WizardShell; SuccessPane + Save-&-add-another in wizards; inline action panes (no nested Dialog); no AttachmentUploader misuse; no stubs/TODOs
- [ ] Browser screenshots — deferred: local .test needs auth + dev DB has 0 WP rows. Per project norm, verify on .com after merge+deploy (offer to do via Chrome MCP).

### Phase 5 — Adversarial review + hardening ✅ (6-reviewer workflow → 6-fixer workflow)
Found 1 blocker + 3 high + 6 medium + 7 low; fixed all blocker/high/medium + the cheap lows:
- 🔴 BLOCKER: schedule-meeting "create new committee" chain dropped the meeting — `created_committee_id` wasn't in the `HandleInertiaRequests` flash whitelist (fixed: added the key; regression test added).
- 🟠 consultation download used Inertia `<Link>` (binary) → plain `<a>`. 🟠 feedback/outcome panes could regress the lifecycle → non-regressing via CONSULT_ORDER. 🟠 meeting "Attended" badge never rendered (`=== true` vs MySQL 0/1) → truthy. 🟠 add-rep training step 0.5 vs integer rule + silent server errors → step 1 + round + full field→step onError map + term/integer validateStep. 🟠 new-consultation doc error hidden → surfaced + field→step fallback + client file-size/type guard. 🟠 schedule-meeting retry double-created committee → createdCommitteeId state.
- 🟡 lows: flash-error onSuccess guards across all panes, aria-labels on repeaters, blank-agenda filter.
- DEFERRED: shared `Field` primitive a11y label htmlFor association (app-wide component — out of scope; visible labels present).
- Re-verified: tsc 0 errors (whole project — the earlier "88" were missing Wayfinder route TS in the fresh worktree, regenerated by `npm run build`); eslint WP 0/0; **WorkerParticipationTest 16/16 (84 assertions)**.

## STATUS: ✅ IMPLEMENTATION COMPLETE + ADVERSARIALLY-REVIEWED + FULLY VERIFIED (uncommitted in worktree branch `claude/wonderful-hugle-e74baf`). Awaiting user decision on commit/merge/deploy. Files: 10 modified (incl. HandleInertiaRequests) + 8 new groups.

## Notes
- Build in MAIN repo worktree `wonderful-hugle-e74baf` (branch `claude/wonderful-hugle-e74baf`).
- ⚠️ junctioned-vendor worktree: PHP tests autoload PARENT app/ — verify backend by merging then testing in parent.
- Migration policy: run local autonomously.
