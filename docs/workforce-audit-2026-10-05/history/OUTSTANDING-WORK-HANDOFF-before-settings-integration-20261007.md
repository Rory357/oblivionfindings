# Workforce and Rostering — outstanding work and handoff

## Resumed checkpoint — 7 October 2026

The goal is active. Approximately **40% remains**, as a rough judgement across implementation, integration and verification, not a measured checklist score. The paused checkpoint below is historical and its instructions to wait no longer apply.

Local and GitHub main are verified at `231e57ab90900d9b53d722fa8e00215d2106e9fa`, including both independent eMAR merges and the complete Availability increment. Availability navigation, overnight declarations, current-authority checks, sensitive summary masking, per-row permission changes, draft recovery and committed-result handling are delivered. The four redundant summary cards stay removed.

Evidence: 93 focused UI cases; 57 distinct native cases / 956 assertions (31 / 691 receipt cases on bcfd3660d plus unchanged outside-class 26 / 265 on 411de15ec); lint, TypeScript and final 3m48s build pass. Actual administrator browser save/remove/overnight/draft/narrow-layout journeys and restricted direct-access denial passed. Missing-.env native warnings remain documented. Broader repository CI is not certified: the pre-integration bcfd3660d tests workflow was already failing. The exact Availability report is `docs/workforce-audit-2026-10-05/availability-integration-2026-10-06.md` (its pre-push status is superseded by this checkpoint).

The Availability corrections were carefully backported to the full Workforce source, preserving unrelated work and exact frontend backups under ignored test-results. Its focused UI gate passed 52 cases; backend syntax/format/whitespace checks passed. No full-source native run is claimed.

Workforce settings is the current package: parent owns eMAR-style UI/navigation and guarded save/recovery; backend agents are integrating current approval/grants, actual committed outcomes, existing global policy consumers, durable eligibility refresh, scoped status/retry and focused acceptance tests. It is not yet delivered or verified. Control Room stays independent, and the protected eMAR worktree/database is untouched. The broader module list and held qualification/modified-duty/respite/HR decisions below remain outstanding.

## Historical pause checkpoint

Updated 6 October 2026, Pacific/Auckland. The user requested a stop to conserve usage. This document is the restart point for the full supported-living improvement programme. The programme is **unfinished and paused**, not complete.

## Safe stopping point

- All three backend agents were interrupted. No new work, tests, builds or pushes are to start until the user resumes.
- A process check found **zero matching task test/build processes**. The two existing development preview PHP processes were retained.
- The last verified local `main` and GitHub `main` commit is **`2979469633cec95b7cefcaf7772bb37c05f188b8`**. Primary tracked files and index were clean after that push. This handoff is a new local document.
- The latest Availability integration is **partial, uncommitted and unverified** in the integration worktree. Do not push it as it stands.
- This pause does not cancel GitHub-hosted checks already triggered by earlier pushes. Their last known state was pending/queued; no current green result is claimed.

## What has actually reached main

| Commit | Delivered | Evidence |
| --- | --- | --- |
| `99814bb203f6a16224da26bcda60fe6a32a04c29` | Remove the four redundant Availability summary cards; retain staff list, search, editing and compact shown-count. | 26 focused existing frontend cases, lint and build; actual preview checked. |
| `87f809a9a39b452ade66321d293f621111e0686c` | Attendance header/mobile cards, full-period summaries, clock-in/clock-out/correction draft recovery, worker-zone date/time handling and exact committed-result messages. | Main integration: 80 frontend cases; 52 native cases / 727 assertions; types, build and lint. Actual shiftless clock-in/out and mobile preview checked. |
| `2979469633cec95b7cefcaf7772bb37c05f188b8` | Human-approved Leave reservation/concurrency repair, current entry/review/cancellation authority, worker-calendar balance year and outer-commit pending/adjustment notices. | Main integration: 118 native cases / 833 assertions, no failures/errors/skips; syntax/Pint; two independent source reviews. |

Attendance and Leave native gates reported suppressed phpdotenv missing-`.env` warnings because the isolated integration checkout deliberately has no preview environment file. The assertion results passed, and all owned native processes were verified exited. These gates are not evidence that the whole repository's CI is green.

The wider Workforce source contains substantial additional implemented and tested work that **has not been integrated into main**. Do not confuse preview availability or a recorded focused pass with main delivery or complete module acceptance.

## Workspaces and boundaries

| Purpose | Location / state |
| --- | --- |
| Primary main | `C:/Users/steph/Herd/oblivionfindings`; branch `main`; last verified head `2979469633cec95b7cefcaf7772bb37c05f188b8`. Preserve preexisting untracked documents. |
| Main integration | `C:/Users/steph/.codex/worktrees/workforce-main-integration/oblivionfindings`; branch `codex/workforce-main-integration-20261006`; same committed head, with seven partial Availability frontend files described below. |
| Full Workforce source | `C:/Users/steph/.codex/worktrees/workforce-improvements/oblivionfindings`; branch `codex/workforce-foundation-20261005`; base head `3710147631295c795c6be7a919856eedf393dc2f`; many retained uncommitted/untracked programme changes. Do not reset, overwrite or stage the entire worktree blindly. |
| Workforce preview | Port `8766`, database `oblivion_workforce_preview_20261005`. This serves the full Workforce source, not the partial main-integration checkout. |
| Protected eMAR | Port `8765`, database `oblivion_emar_preview_20261003`; independent worktree remains protected. Do not copy, migrate, test against or merge its work accidentally. |

Permanent requirements: one operating organisation; roles, exact permissions, approved Sites, canonical ownership and privacy are the boundary. No new tenant system. Control Room/operator rostering remains independent. Preserve current functionality. Follow `DESIGN.md`, `design_styles/POPUP_STYLE_GUIDE.md`, the current shared PageHeader, SiteCalendar and eMAR settings conventions. Backend agents were requested as GPT 6.1 Sol, Extra high; the parent owns frontend work. The user has authorized integrating verified batches into local main and GitHub main and pushing whenever possible; the current pause takes precedence until resumed.

## Immediate unfinished slice: Availability and duplicate navigation

### Saved just before the pause

The integration worktree currently contains these **frontend-only** changes:

1. `resources/js/components/app-sidebar.tsx`: staff read/self/any availability grants expose Workforce; roster readers lose the duplicate Availability left entry; global search retains the direct Rostering Availability destination; limited roles link to `/operations/availability`.
2. `resources/js/components/app-sidebar.test.ts`: seven corresponding navigation cases copied into the existing suite.
3. `resources/js/components/rostering/availability-pane.tsx`: copied reviewed Workforce pane, including overnight display and per-row edit capability.
4. `resources/js/components/rostering/edit-availability-dialog.tsx`: copied existing Workforce wizard, review and dirty-close behaviour.
5. `resources/js/components/rostering/edit-availability-dialog.test.tsx`: four existing editor cases copied; this file is untracked in the integration checkout.
6. `resources/js/pages/operations/rostering/index.tsx`: only Availability capability/timezone props and pane wiring added.
7. `resources/js/pages/staff/availability.tsx`: copied standalone weekly Availability page/editor entry.

No tests, lint, type check, build or browser acceptance have run for this partial integration. The main-integration backend was still unchanged when stopped. The standalone route and overnight storage support are therefore missing there; the partial sidebar/page combination is not ready to publish. Full source preview behaviour is not proof of this integration.

### Finish the backend dependency set

- Integrate the scoped `StaffAvailabilityController` from the reviewed Workforce source; replace the existing `/operations/availability` redirect with the authorized workspace action.
- Integrate the `StaffAvailability` model's `ends_next_day` field and exact migration `2026_10_05_040000` from the source. Do not add a second migration for the same change.
- Bring only `AvailabilityRule::checkDeclaredAvailability`, not unrelated eligibility work.
- Bring only the Availability summary, capability, filters and worker-timezone hunks in `RosteringController`.
- Reuse main's current authorization helpers and `WorkforceMutationGuard`. Do not import the broad `WorkforceAccessService` only to obtain the existing `reports.viewAny` constant.
- Preserve approved-Site/employee scoping and sensitive leave masking. `staff[].can_manage` is the authoritative edit capability; the response also uses `availabilityCapabilities={view_any,update_any,update_self}`.
- Preserve recurring availability semantics: same-day end must be later than start; next-day end may be earlier or equal; equal clock with next-day means 24 hours. This is declared availability, not paid/recorded work.

### New recovery defect identified, not repaired yet

The copied editor currently declares success for a generic redirect, has only a state-based pending guard, and can enable another attempt after an interrupted response. It can also receive callbacks after staff/context changes. Finish this repair before certifying the editor:

- Backend proposal already handed to HR agent: additive `flash.staff_availability_result` for actual committed create/delete, with `{ action: 'create'|'delete', staff_id, availability_id, day_of_week, starts_at: 'HH:mm', ends_at: 'HH:mm', ends_next_day }`.
- Clear stale receipts at command entry; emit only after physical root commit, using the established Attendance pattern. Capture actual writer values; keep existing authority and rollback behaviour. Add one lazy middleware flash key. This was requested but **not implemented or verified** at pause.
- Frontend must match the exact action, staff, block and submitted values; distinguish a known rejection from an unknown result; retain draft/reason/context; prevent same-tick double writes and stale callbacks; hold an uncertain result for explicit record review. Removal needs the same truthful result handling.
- Use per-open form state and protect a draft when switching/closing. A success should reflect actual saved data, not a translated message.

### Additional Availability UI issues found during integration review

- The card grid has a fixed 320px minimum that can overflow narrower content widths.
- Cards retain hand-built tiny status badges and name-derived raw-colour avatars; align these changed surfaces with shared semantic tokens/status primitives.
- Date chips use a fixed timezone formatter while the page accepts a configured worker timezone. Pass the actual timezone consistently and compare timestamps as instants, not different-offset strings.
- The shared time pickers in the copied editor do not yet receive its `workerTimezone` prop.
- Make full intervals and overnight continuation understandable without hover; retain every block and leave/time-off entry. Preserve searchable staff selection and row edit authorization.

### Required acceptance for this slice

- Extract the eight relevant Availability HTTP cases from the broader source suite; include all ten `DeclaredAvailabilityWindowTest` cases. Exclude unrelated cover/canonical-series cases. Add focused real-root receipt/rollback/owner/current-permission cases for the new receipt work.
- Reuse the four editor and seven navigation cases, extend with meaningful interrupted/malformed/wrong-context/retry tests, and run relevant existing preservation tests.
- Important native prerequisite: the reviewed full SQL snapshot **does not contain `staff_availabilities.ends_next_day` or migration `2026_10_05_040000`**. Use the existing pending-migration step after required-native import once the exact migration is present. Do not patch the dump or falsely mark the migration applied.
- Only the roster agent runs native DB tests, after source/fixture review and freeze, against its PID-isolated disposable schema. No tests were launched for this Availability slice before pause.
- Run changed-source lint, types and build; verify desktop/mobile, keyboard/zoom, overnight carryover, validation/draft recovery and actual save/remove results. Limited-role browser acceptance remains outstanding; do not fabricate grants or claim an administrator proves every role.
- Integrate/push only when the complete slice is verified. Carry accepted corrections back to the larger source carefully, preserving its additional changes.

## Workforce settings: implemented in source, still needs coherent main integration

The user explicitly requested a Workforce settings left-nav entry and a page similar to eMAR settings. Both exist in the full Workforce source. Recorded source evidence is 35 backend cases / 323 assertions and 13 frontend cases across three files, but authorized positive settings-edit browser acceptance is incomplete.

The complete editable settings page **cannot yet be copied into main alone**. Global saves directly stage durable eligibility rechecks, and the fatigue, projected-workload, capacity and reporting readers must consume the same saved policy. Main lacks those connected parts. Outstanding:

- Build/review the complete dependency manifest for settings, `HrFatiguePolicySettings`, durable recheck models/migrations/job/source observer/provider, recovery scheduling and all policy readers.
- Integrate the verified source coherently; preserve revision conflict checks, edit reason, exact HR authority, atomic audit and after-commit impact staging.
- Verify a policy change reaches the relevant future duties and each displayed metric, including rollback, stale edits and denied roles.
- Complete the eMAR-style settings UI's readable sections, personal defaults, cross-module ownership links and configuration completeness review.
- Add the left-nav entry with its working route/backend. Reuse main's richer `SettingsModal`; copying the older source component would remove supported props.
- Do not reduce the requested full settings deliverable to personal preferences and call it finished. A personal-only interim page would require a clearly reviewed adaptation.

## Remaining programme work by module

Many rows below have implemented/verified source packages; the remaining work includes integration and broader acceptance, not necessarily a rewrite. Read the latest source evidence before redoing completed work.

| Module | Outstanding implementation, integration or acceptance |
| --- | --- |
| My Day | Integrate verified care/draft/recovery improvements; complete worker-home journeys after reassignment, every linked task type and populated Observation/Sleep/Meal saves. The current account's empty-resident path is proven; positive care saves are not. Preserve distinct Sleep fields and canonical worker entry permissions. |
| Overview | Reconcile organisation dashboard measures with canonical worker-day, authorized scope and source definitions; verify every linked count lands in the matching worklist. |
| My Calendar | Integrate the verified recurrence/current-edit/timezone work and shared calendar design; finish rebuilt personal-adapter journeys, missing/unusable dates, historical moved occurrences and context/recovery browser acceptance. |
| All Tasks | Integrate scoped cover-work projections, responsive UI and source links; complete populated provider journeys beyond the verified cover-task-to-Leave receipt/export path. Generic actions must retain source authority. |
| Sites & Locations | Integrate House coverage, staff requirements, date/source links and shared editor improvements. Complete house/person demand changes and cross-module return journeys. Unmapped mandatory competency policy still needs the user's decision. Attention counts are limited summaries, not readiness certification. |
| Operations / care demand | Integrate qualifications, source-scoped notes/export, full interval/demand and continuity improvements. Finish structured person-preference authority, alternate direct-entry parity and retained-history acceptance. |
| Rostering / Shifts | Integrate verified assignment, replacement, conflict, URL/context, interval/capacity, permission and editor packages. Complete the full Shifts-list/filter/action/detail parity before consolidating the Shifts left entry. Preserve resource planning, sleepover/awake-night/on-call, planned multi-worker support, exact times and all fields. |
| Availability | Complete the partial integration and new receipt/recovery work described above. Preserve standalone self/limited-role access while eliminating the duplicate coordinator entry. |
| Conflict Queue | Integrate actual durable acknowledgement/resolution/current-source actions; finish reload/second-session and source-correction journeys. Consolidate its shortcut only after its full destination/action parity is retained. |
| Available shifts / Job Board | Integrate the claim/withdraw/reject/reopen/expiry/replacement lifecycle and role-specific UX. Complete simultaneous-claim, changed eligibility/authority, responsibility/handover and safe-notification journeys. This is not equivalent to Rostering's Open shifts pane. |
| Templates / recurring patterns | Integrate full-occurrence eligibility/workload and field-parity safeguards, create/edit/apply/delete/duplicate recovery and typed results. Source template apply now passes 36 native cases / 292 assertions; frontend apply recovery 74 cases / 3 files. Library delete/duplicate has separate 22-native/60-frontend proof. Authorized positive write browser journeys remain unavailable in the current account. Existing repeated-clock interpretation is retained; explicit first/second DST occurrence selection remains a separate follow-on. |
| Suggestions / auto-scheduling | Integrate cumulative accepted-batch checks, current source/privacy/capability reads and actual action receipts. Source lifecycle 54 / 587 and suggestion read 22 / 285 are verified, with 35 frontend cases. Complete authorized positive browser entry/generate/accept/dismiss/apply and publication flag-on/off journeys. Accepted decisions must not be presented as assigned duties. |
| Handovers | Integrate the reported hero migration and current worker/manager paths; finish populated responsibility-transfer, task and end-of-shift acceptance. Attendance's embedded handover action recovery remains an explicit remaining gap. |
| Shift Notes | Integrate current source/privacy/export/pagination/date/link repairs and header/mobile improvements; complete populated historical/private-note, late-written-note and export parity journeys. |
| Attendance | Main now includes three wizard recovery journeys and accurate summaries. Administrative end-session still uses generic `ReasonDialog` success handling; replace it with committed-result recovery and truthful payroll follow-up wording. Break and handover action recovery remain open. Selected-duty and manager-correction positive browser journeys are not established by the shiftless Demo journey. |
| Timesheets | Integrate the header migration, identity/scoped actions, editing/recovery and care-to-pay safeguards still in source. Complete returned/resubmitted/approved/locked history and actual payroll follow-up journeys. Do not equate draft/exported/approved with paid. |
| HR | Approved core Leave repair is on main. Integrate Leave/offboarding cover receipts, staffing policy history and future-compliance/read-context work. Complete date-edit/cancellation/offboarding/accepted-replacement journeys and authorized settings edit. Broader quiet/accrual balance writers, supporting-document cleanup and synchronous decline notice atomicity remain separate audit gaps. |
| Health & Safety | Integrate future-duty training windows, scoped modified-duty metadata, fatigue consumers and direct shift-incident integrity work. Complete representative H&S/source-to-duty journeys and scope-honest metrics. Modified-duty interpretation needs the user; do not infer fitness or expose medical details. |
| Health & Clinical | Integrate scoped observation loading, drafts, exact adjacent-duty occurrence/current-authority and canonical saved-record outcomes. Complete an authorized populated observation browser journey; no synthetic permission workaround. |
| Medication / eMAR | Preserve canonical competency/presence decisions and the independent eMAR checkout. Verify duty/cover/handover integrations without changing its ownership. Cross-site respite clinical/eMAR access requires an explicit rule. |
| Respite | Integrate verified separation of stay demand and employee duty while preserving completed/paid history. Resolve durable employee-duty placement provenance and cross-site clinical access policy; no inferred access expansion. Adjacent HR stay-purpose cover repair remains held separately. |
| Fleet & Assets | Integrate the scoped transport/resource read context and exact create-entry capability. Complete populated transport/resource disruption and duty-return journeys; the verified empty context/read and hidden unavailable action do not prove positive booking. Preserve Fleet hold/release authority. |
| IT & Support | Integrate reciprocal IT-booking/care-duty conflicts and privacy-safe busy context. Complete joiner/leaver, outages, equipment/access incident impact and permissioned return journeys. |
| Finance / payroll / funding | Integrate remaining timesheet reconciliation and source identity/locking work. Complete broader billing/payroll amendment/export/downstream-lock acceptance. Existing care-to-pay tests prove recorded/approved/draft-export facts, not external posting/payment or every finance path. |
| Reporting | Integrate source-aware periods, unknown worked-time, variance, provenance/redaction and exact report entry fixes. Complete populated historical-source journeys and reconciliation across dashboards, exports and policy consumers. The filtered overnight-gap CSV is proven in source; main integration remains. |
| Security & Devices | Source audit exists; preview permission blocks acceptance. Verify authorized device/context journeys without inventing a grant; telemetry is not proof of attendance, safe cover or completed work. |
| Governance | Conditional/source-only inventory exists. Complete a permitted aggregate workforce-risk/action/cost evidence journey while preserving private HR/medical material and owning approvals. |
| Control Room | Preserve independent routes, staffing/duty/history/handover and access. Check for regressions caused by shared dependencies. Future operator planning is separately deferred and is not a prerequisite or permission to merge it into care rostering. |
| Global settings / navigation / search | Complete coherent Workforce settings integration; shared PageHeader migrations and all navigation/old-link/role parity. Preserve search discoverability and existing shared eMAR/accessibility improvements. |

## Decisions and held scope

These are specific unresolved decisions, not reasons to discard independent work:

1. **WF-32:** how an unmapped mandatory qualification requirement should behave — warning versus assignment/publication block.
2. **WF-33:** the intended meaning of existing modified-duty approval and who can authorize which restrictions. Do not infer medical fitness.
3. **Cross-site respite:** durable provenance for a separate employee duty at a placement and the permitted clinical/eMAR access rule. Current Client/Site/privacy boundaries remain enforced.
4. **Adjacent HR stay-purpose cover:** existing legacy assigned stay aggregates can still acquire employee replacement/open work through `WorkforceAvailabilityCoverageService`. This correction was not included in the explicit Leave reservation approval. Preserve history and the held boundary until the exact repair is authorized; do not route around it.
5. Multi-applicant expressions of interest and future Control Room operator planning are conditional future designs, not implied permission to replace present immediate-claim or independent operator workflows.

## Cross-cutting release and acceptance still required

- Complete the canonical shift-to-care-to-pay journey: plan/assign/publish, My Day, clock-in, care/tasks/break/handover/clock-out, timesheet return/resubmit/approve and existing payroll amendment/export follow-up.
- Exercise two Houses, restricted coordinator, worker, read-only role and payroll reviewer; direct-object denial and all approved-Site/private-source boundaries; do not claim an admin happy path proves the role matrix.
- Complete source change → affected duties → re-evaluation → visible authorized decision → return-to-source outcomes for leave, employment, competency, requirements, restrictions, transport and IT commitments.
- Integrate the durable eligibility refresh graph and its readers. Isolated real-worker/minute-recovery proof exists (4 cases / 334 assertions plus the DATE-fingerprint correction); installed host scheduler, permanent worker supervision and real external notification transport are **not proven**. Avoid real messages during tests without authorization.
- Verify year-crossing weeks, local/UTC boundaries, both NZ DST transitions, exact midnight, different browser timezone, overnight carry-in, no demand, zero results, planned 2:1 and capacity/break denominators.
- Complete every old/new function in the navigation preservation register before deleting or redirecting entry points. Available shifts, Attendance, Timesheets, Handovers, Shift Notes and settings have distinct responsibilities.
- Verify failed requests, stale/context-switched responses, double submissions, interrupted saves, dirty close, reload/back/bookmarks, meaningful empty/loading/error/retry states, keyboard operation, mobile widths and 200% zoom.
- Reconcile all module statuses against actual commits, focused tests, source/build identity and browser evidence. Historical baseline findings are not automatically current defects; focused passing tests are not automatically complete acceptance.
- Review and integrate retained work in coherent batches, preserving newer main changes. Never reset or wholesale stage the large source worktree merely to finish integration faster.
- Recheck GitHub CI after resuming. Earlier Availability-only CI failed in existing Control Room, eMAR, Catering, Auth, Clinical and Assurance areas; its Attendance fixture failures were addressed by the Attendance batch. Visual checks also failed; the full log download stalled and was stopped. Latest main CI was pending/queued. Do not claim those failures resolved or broaden into the protected eMAR worktree without a scoped review.

## Resume order

1. Confirm the user has resumed. Re-read this handoff, inspect both worktrees and the actual main/GitHub heads; do not assume files or remote checks stayed unchanged.
2. Finish and review the partial Availability backend/frontend/receipt package. Run the migration-aware isolated native gate, targeted frontend checks and actual browser journeys, then integrate/push the complete batch.
3. Integrate Workforce settings with its durable refresh and policy-reader dependencies; finish authorized settings acceptance and the requested left-nav entry.
4. Promote the other verified source packages in dependency order, retaining the full navigation/action parity and module acceptance items above. Finish the remaining recovery and populated role-specific browser gaps.
5. Resolve the explicit policy/held-scope items through the user when needed, without blocking independent work.
6. Perform a requirement-by-requirement full completion audit. Only then mark the goal complete. A batch push, focused pass or depleted usage allowance is not programme completion.

## Detailed evidence to retain

The full-source audit folder is `C:/Users/steph/.codex/worktrees/workforce-improvements/oblivionfindings/docs/workforce-audit-2026-10-05/`. It contains:

- `module-status.md`: every sidebar module, current disposition and dated baseline.
- `implementation-status.md`: chronological receipts and later superseding checkpoints.
- `backend-completion-reconciliation.md`: verified backend packages versus remaining boundaries.
- `audit.md`, `implementation-plan.md`, `cross-module-architecture.md`, `navigation.md`, `verification.md`: complete findings, original requirement/parity inventory and acceptance framework.
- Approved Leave and Attendance integration records/proposals; template receipt, recurring DST, async eligibility, care-demand, calendar, Fleet and suggestion continuation records.

Main's delivered batch records are `attendance-main-integration-2026-10-06.md` and `leave-main-integration-2026-10-06.md` beside this file. Native/UI receipts live under ignored `test-results` in their respective worktrees. Browser proof is under `C:/Users/steph/AppData/Local/Temp/workforce-resumed-proof-20261005/`. Preserve those paths and the distinction between source preview proof and main integration proof.

This is a handoff, not a claim that all listed work has been implemented, tested or pushed. Resume from authoritative state and update the outstanding list as each complete requirement is verified.
