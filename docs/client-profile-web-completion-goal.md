# Client Profile — Complete Web Workspace Goal

**Status:** Active persistent goal
**Canonical route:** `GET /operations/clients/{client}`
**Acceptance surface:** desktop web at 1440×900 only
**Started:** 10 July 2026 (Pacific/Auckland)

## Scope and authorization ledger

- The client profile is a presentation/composition boundary. Canonical domain models, policies, validation, services, events, jobs and mutations remain authoritative.
- Reuse `ProfileDialogs`, `WizardShell`, the Add Client completion flow, and specialised clinical/operational workflows. Do not create profile-specific domain copies.
- Local edits, local migrations, disposable local data, local automated tests and desktop-browser verification are authorized.
- Git staging, commits, branch/worktree changes, pushes, pull requests, merges, deployments, SSH/server changes, live writes, live migrations and live seeding are not authorized.
- `https://oblivionfindings.com/operations/clients/9040` is read-only current-state evidence. Post-change proof must use local/Herd until deployment is separately authorized.
- Mobile/native/PWA/responsive work and mobile testing are outside this goal.

## Batch execution plan

1. **Batch 0 — foundation and release blockers:** navigation/URL consistency, Privacy discovery, profile edit reuse, section authorization and sensitive-prop omission, nested binding/IDOR fixes, assignment/care-plan/finance integrity, and note consolidation.
2. **Batch 1 — Snapshot + Daily care + Plans & goals:** complete legitimate in-profile create/detail/edit/lifecycle behaviour and compatibility paths.
3. **Batch 2 — Health & safety:** complete client-scoped clinical/safety workflows by composing canonical modules.
4. **Batch 3 — Day-to-day:** complete operational workflows without rebuilding global modules.
5. **Batch 4 — Relationships & governance:** complete relationship, consent, portal, action, audit and Privacy workflows.
6. **Batch 5 — system proof:** focused and aggregate server/frontend tests, types, lint, client/SSR builds, desktop Chromium coverage, payload/query measurement and final closeout.

## Completion ledger

`—` means the current audit has not yet established evidence. A rendered tab or create button is not completion evidence.

| Hero group | Tab | Ownership classification | Canonical table/model | Canonical controller | Canonical service | Canonical policy | Canonical read endpoint | Canonical write endpoint | Reusable UI component | Anti-duplication decision | Permissions | Data source | Create | Detail | Edit | Lifecycle actions | Intentionally read-only or non-applicable actions | Loading state | Empty state | Error state | Success state | Modal key | Compatibility route behaviour | Server tests | Frontend tests | Desktop browser proof | Status | Remaining boundary |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| Snapshot | Overview | aggregate | canonical client/chat/assignment/medication/risk domains | owning domain controllers | `ClientFamilyCommunicationAccess`, `ClientProfileSectionAccess`, `ClientWorkerEligibility` | `ClientPolicy` + owning domain permissions/policies | `GET /operations/clients/{client}` | Owning endpoints only | `ClientProfileHero`, `overview-grid.tsx` | Aggregate remains read/navigation only; mutations stay with owning domains | named chat/assignment/medication/risk/navigation capabilities; no broad `can.edit` | Inertia aggregate | N/A | — | N/A | Individually gated chat/edit/assignment/MAR/risk/navigation actions | Summary is non-mutating | — | — | — | — | canonical dialogs only | Legacy summary callers pending inventory | Continuation capability + family communication suites green | Real-page restored-dialog and hero action tests green | Prior live mismatch only; no new Chrome run by scope | Partial | Remaining overview detail states and separately scoped browser proof |
| Snapshot | Personal Details | client-owned record | `clients` + linked intake records | `ClientController` | — | `ClientPolicy` | `GET /operations/clients/{client}/edit?modal=1` | `PUT /operations/clients/{client}` | `AddClientDialog` | Reuse Add Client edit/completion mode; retire flat form as primary | `clients.update` | Canonical client intake graph | N/A | — | Partial | Save/complete profile | Delete is non-applicable here | Partial | — | Partial | Partial | `edit_profile` | Existing edit URL must remain a compatibility adapter | — | — | Live 10 Jul: flat `Edit client` dialog still primary | Partial | Full hydration and round-trip via Add Client workflow |
| Snapshot | Onboarding | client-owned workflow + linked read-only HR projection | `client_onboarding_*` + canonical `hr_employee_profiles`, `hr_onboarding_checklists`, `hr_onboarding_tasks` | `ClientOnboardingController`, `Operations\ClientOnboardingWorkflowController`; HR reads remain owned by HR onboarding | `ClientOnboardingAccess`, `ClientStaffPreparationProjection`; canonical HR mutations remain in `OnboardingService` | `ClientPolicy` plus exact onboarding permissions | Profile props; canonical HR `/hr/onboarding` | Existing client onboarding checklist/workflow endpoints; HR mutations remain in HR | Existing onboarding section + `ProfileDialogs`; HR links use canonical pages | No HR/client onboarding copies; assigned workers are joined to HR profiles by canonical `user_id` and projected read-only | checklist: `clients.onboarding.manage|clients.update`; create: `onboarding.create|clients.create|clients.update`; workflow manage: `onboarding.edit|clients.create|clients.update`; HR projection: `hr.onboarding.view` | Canonical client onboarding records + safe HR checklist/task counts | Partial | Partial | N/A | add/complete/skip/checklist override; HR preparation is read-only here | HR task content and HR mutation remain outside Client Profile | — | truthful restricted/empty states | — | — | `add_onboarding_step` | Legacy checklist URL retains the same exact capability | Continuation route/capability/HR projection suite green | Restored dialog gating covered in real-page suite | No Chrome run by scope | Partial | Remaining onboarding lifecycle detail and separately scoped browser proof |
| Snapshot | Location | linked projection | — | `ClientController` | — | — | Profile/location history endpoints | Canonical tracker/setup endpoints | `ClientLocationTab` | Reuse Security & Devices records; no copied tracker/location data | — | Security & Devices projection | — | — | — | Client-scoped safety/setup actions | Site/device ownership may be read-only | — | — | — | — | — | — | — | — | — | Not inspected | Inventory in progress |
| Snapshot | Workers | linked projection | client-worker pivot + key worker | `ClientController` | — | `ClientPolicy` | Profile prop | Canonical assignment endpoints | `WorkersTab` | Reuse client assignment pivot and user records | `clients.assignments.update` | Client relations | N/A | — | — | assign/unassign/key-worker | User management is external | — | — | — | — | `assign_workers` | — | — | — | — | Not inspected | Eligibility, org scope and adversarial tests |
| Daily care | Daily Notes | client-owned record | `client_notes` / `ClientNote` | `Operations\ClientDailyNoteController` | — | `ClientNotePolicy` | Profile prop + paginated JSON | Canonical daily-note endpoints | `DailyNotesTab`, `DailyNoteWizard`, `QuickNoteDialog` | New writes use `ClientNote`; drafts are author-private working records and submitted notes are the formal record | exact `can.create_daily_note` / `can.create_quick_note` from `ClientNotePolicy::create`; per-record `can` | `ClientNote` | Partial | Partial | Partial | author draft resume/submit; submitted review/clear flag/follow-up/delete by policy | Submitted notes cannot return to draft; unrelated note types are not mutable here | — | truthful total/loaded/has_more for capped daily and communication collections | — | — | `daily_note`, `quick_note` | Legacy ProgressNote callers remain compatibility adapters | Batch One integrity + continuation capability suites green | Coverage, exact hero actions, N/Shift+N and restored URL tests green | No Chrome run by scope | Partial | Add in-profile draft resume/detail/edit controls and separately scoped browser proof |
| Daily care | Timeline | aggregate | `timeline_events` / `TimelineEvent` | — | `TimelineEmitter` | — | Profile prop | Canonical timeline comment/reaction/pin endpoints | `TimelineTab` | Retain aggregate projection; do not duplicate source records | — | Timeline projection | N/A | Partial | N/A | comments/reactions/pinning | Source edits stay in owning domain | — | truthful total/loaded/has_more for timeline and pinned handover caps | — | — | — | Standalone timeline escape pending inventory | Direct-route authorization suite green | Timeline and pinned-handover coverage copy test green | No Chrome run by scope | Partial | In-profile source detail and remaining compatibility proof |
| Daily care | Communication | client-owned record | `client_notes` / `ClientNote` | `Operations\ClientDailyNoteController` | — | `ClientNotePolicy` | Profile prop | Canonical shared note endpoint with `type=communication` | `CommunicationNotesTab`, `DailyNoteWizard` | Share `ClientNote` and common entry components | exact `can.create_communication_note` from `ClientNotePolicy::create` | `ClientNote` | Partial | Partial | Partial | review/visibility/void if supported | — | — | truthful no-create empty state + total/loaded/has_more | — | — | `comm_note` | — | Batch One integrity + continuation capability suites green | Exact creation and bounded-coverage tests green | No Chrome run by scope | Partial | Complete correction/visibility lifecycle and separately scoped browser proof |
| Daily care | Family Notes | externally authored record represented in profile | — | — | — | — | Profile prop | Canonical staff-response/status endpoints | Existing Family Notes section | Preserve immutable family-authored content; no duplicate portal storage | — | Portal/family notes | N/A | — | N/A | respond/assign shift/status | Family-authored body is immutable where required | — | — | — | — | — | — | — | — | — | Not inspected | Inventory in progress |
| Daily care | Rhythms & Routines | client-owned record | — | — | — | — | Profile prop | Canonical routine endpoint | `RhythmsRoutinesTab` | One shared modal edit path; retire duplicate inline editors after parity | — | — | N/A | — | Partial | edit canonical routine blocks | — | — | — | — | — | `edit_rhythms` | — | — | — | — | Partial | Inventory and parity proof |
| Daily care | Food & Meal | client-owned record + safety projection | — | — | — | — | Profile prop | Canonical meal-log/preference endpoints | `FoodMealTab` | Reuse meal logs/preferences; no profile copies | — | — | Partial | — | Partial | correct/void meal logs; preference add/edit/remove | Allergy/dietary sources may be projections | — | — | — | — | `meal_pref` | — | — | — | — | Partial | Inventory and complete lifecycle |
| Plans & goals | Care & Support Plan | client-owned record | `care_plans` / `CarePlan` | `CarePlanController` | — | `CarePlanPolicy` | Profile prop | Canonical care-plan endpoints | `CareSupportPlanTab`, `CarePlanWizardDialog` | Review copy is the working version; published/archived versions are immutable | `care_plans.*` | `CarePlan` graph | Partial | Partial | Partial | review/activate/fresh sign-off/archive/export; delete current mutable version with dedicated capability | Historical versions are immutable; downloads remain file actions | — | truthful totals/loaded/has_more for recent-note and version caps | — | — | `care_plan`, `plan_review` | Standalone callers pending inventory | Goal management and prior review integrity suites green | Care-plan review + bounded-version coverage tests green | No Chrome run by scope | Partial | Per-record lifecycle capabilities, remaining detail/error states and separately scoped browser proof |
| Plans & goals | Goals Path | client-owned record + aggregate | `care_plan_goals`, `client_path_plans` | — | — | `CarePlanPolicy::update` for goals; `ClientPolicy::update` for PATH | Profile prop | Canonical goal/PATH endpoints | `GoalsPathTab`, `GoalWizardDialog` | Reuse CarePlan goals and ClientPathPlan; no second goal model | exact split goal/PATH capabilities | Canonical plan graph | Partial | Partial | Partial | steps/hurdles/progress/complete/reopen/archive/PATH | — | — | — | — | — | `goal`, `edit_path_plan` | Remove required care-plan page escape after parity | Goal management + continuation capability suites green | Split-control, stale-prop and restored dialog tests green | No Chrome run by scope | Partial | Remaining lifecycle completion and separately scoped browser proof |
| Plans & goals | Behaviour / ABC | global-domain record represented in profile | — | — | `BehaviourPatternsService` | — | Profile/lazy prop | Canonical clinical observation endpoints | `BehaviourAbcTab`, `AbcEntryDialog` | Preserve specialised canonical clinical flow | — | Clinical observations | Partial | Partial | Partial | correct/void/delete if supported; analytics refresh | — | — | — | — | — | `abc` | — | Existing profile tests | — | — | Partial | Verify lifecycle and permissions |
| Plans & goals | Assessments | client-owned record | `client_assessments` / `ClientAssessment` | — | — | — | Profile prop | Canonical assessment endpoints | Existing assessment UI | Consolidate onto shared profile modal; retire duplicate form only after parity | — | `ClientAssessment` | Partial | — | Partial | archive/delete if supported | — | — | — | — | — | `add_assessment` | — | — | — | — | Partial | Inventory and parity proof |
| Health & safety | Health Monitoring | client-owned clinical records | — | — | — | — | Profile prop | Canonical chart endpoints | `HealthMonitoringTab` | Reuse existing chart models/controllers; no generic duplicate chart tables | — | Canonical chart records | Partial | — | — | correction/void for fluid/bowel/seizure/sleep/vitals/temp/weight | Hard delete only if supported | — | — | — | — | `record_obs` | — | Existing profile tests | — | — | Partial | Inventory all chart types and lifecycle gaps |
| Health & safety | Medical | client-owned clinical records | — | — | — | `ClientPolicy::viewMedications` + domain policies | Profile prop | Canonical medical endpoints | Existing medical tab/UI | Reuse canonical medical graph; no copied medication data | — | Medical profile/meds/conditions/contacts | Partial | — | Partial | legitimate medication/condition/contact management | — | — | — | — | — | — | Legacy `/medical` compatibility pending | — | — | — | Partial | Sensitive-prop authorization and modal parity |
| Health & safety | MAR | global-domain clinical record represented in profile | Canonical eMAR records | — | Canonical eMAR services | Canonical eMAR policies | Profile prop | Canonical eMAR endpoints | `MarTab`, `EmarRecordDialog` | Compose specialised eMAR; never copy medication/admin data | — | eMAR projection | N/A | — | N/A | safe administration/review/alerts/CD context | Global register remains external | — | — | — | — | `emar` | — | — | — | — | Partial | Inventory safe client-scoped lifecycle |
| Health & safety | Incidents & Accidents | global-domain record represented in profile | — | — | — | — | Profile prop | Canonical incident endpoints | `IncidentsTab` + specialised incident UI | Reuse incident domain; no profile incident table/controller | — | Canonical incidents | Partial | — | Partial | attachments/investigation/follow-up/triage/closure | — | — | — | — | — | `log_incident` | Standalone incident detail parity pending | — | — | — | Partial | Sensitive gating, binding and full modal lifecycle |
| Health & safety | First Aid | global-domain record represented in profile | — | — | — | — | Profile prop | Canonical First Aid endpoints | `FirstAidTab` | Reuse First Aid register records; no duplicates | — | Canonical First Aid | N/A | — | N/A | legitimate client-scoped actions | Global register remains | — | — | — | — | — | Current external link pending replacement | — | — | — | Partial | Inventory, sensitive gating and modal detail/actions |
| Health & safety | Risk Management | client-owned record + linked projections | `client_risks` + H&S/site records | — | — | — | Profile prop | Canonical risk endpoints | `RiskManagementTab` | Reuse client risks; site hazards/SWPs stay linked and may be read-only | — | Canonical risk/H&S sources | Partial | Partial | Partial | CRUD + legitimate review actions | Site hazards/procedures may be read-only | — | — | — | — | `add_risk`, `edit_risk` | — | — | — | — | Partial | Sensitive gating and linked detail parity |
| Day-to-day | Appointments | client-owned record | `client_appointments` / `ClientAppointment` | — | — | — | Profile prop | Canonical calendar endpoints | Existing calendar UI | Reuse appointment model/controller; no profile route copy | explicit appointment capabilities required | Canonical appointments | Partial | — | — | cancel/complete/safe delete | — | — | — | — | — | `appointment` | — | — | — | — | Partial | Replace client-view mutation gate; bind client/org |
| Day-to-day | Transport | global-domain record represented in profile | — | — | — | — | Optional `transport` prop | Canonical Fleet/transport endpoints | Existing transport tab/flow | Reuse transport/outing records; no copies | — | Fleet/transport projection | Partial | — | — | edit/cancel | Outing may be linked read-only | — | — | — | — | `transport_booking` | Remove required outing-page escape | — | — | — | Partial | Inventory and modal detail parity |
| Day-to-day | Leave & Excursions | client-owned record | `client_leave_requests`, `client_excursion_requests` | — | — | — | Profile prop | Canonical leave/excursion endpoints | `LeaveExcursionsTab` | Reuse canonical request records and transition vocabulary | — | Canonical requests | Partial | — | Partial | submit/approve/decline/cancel/outcome | — | — | — | — | — | `request_leave`, `plan_excursion` | — | Existing profile tests | — | — | Partial | Complete transition/permission matrix |
| Day-to-day | Respite | global-domain record represented in profile | canonical respite requests/bookings | — | canonical respite services | — | Profile prop | Canonical respite endpoints | `RespiteTab`, respite workspace components | Compose canonical Respite; do not rebuild it | `respite.*` | Canonical respite | Partial | — | — | intake/book/status/cancel | Global workspace remains | — | — | — | — | `respite_booking` | — | Existing data-gap tests | — | — | Partial | Inventory lifecycle and modal parity |
| Day-to-day | Personal Inventory | client-owned record | `client_personal_assets` | — | — | — | Profile prop | Canonical asset endpoints | Existing inventory UI | Reuse canonical asset model; retire duplicate forms after parity | — | Canonical assets | Partial | — | Partial | service/return/archive/disposal/safe delete | — | — | — | — | — | `add_asset` | — | — | — | — | Partial | Inventory lifecycle and parity |
| Day-to-day | Finance | global-domain record represented in profile | canonical client finance records | — | canonical finance services | canonical finance policies | Profile prop | Canonical finance endpoints | `FinanceTab` + reusable finance dialogs | Reuse canonical finance graph; no profile finance schema | explicit finance capabilities | Canonical finance | Partial | — | Partial | fund/transaction/purchase/discrepancy/reconcile | Global module remains | — | — | — | — | `transaction` | Remove client-funds page dependency after parity | — | — | — | Partial | Gate props; transactional locking/idempotency/concurrency tests |
| Day-to-day | Agreements | client-owned record | `service_agreements` / `ServiceAgreement` | — | — | — | Profile prop | Canonical agreement endpoints | Existing agreement UI | Reuse ServiceAgreement; no profile copy | — | Canonical agreements | Partial | — | — | status/signature/renew/archive/supersede | — | — | — | — | — | — | — | — | — | Partial | Inventory complete lifecycle |
| Day-to-day | Documents | client-owned record + storage | `client_documents` / `ClientDocument` | — | canonical storage | — | Profile prop | Canonical document endpoints | `DocumentsTab`, upload wizard | Reuse ClientDocument/storage; no copies | — | Canonical documents | Partial | Partial | — | category/version/visibility/archive/delete | Download may remain direct | — | — | — | — | `upload_doc` | Manage Documents escape pending removal | — | — | — | Partial | Sensitive gating and full modal lifecycle |
| Day-to-day | Photos | client-owned record + storage | `client_photos` / `ClientPhoto` | — | canonical storage | — | Profile prop | Canonical photo endpoints | Existing photo UI | Reuse ClientPhoto/storage; retire duplicate forms after parity | — | Canonical photos | Partial | — | — | caption/tags/visibility/replace/archive/delete | — | — | — | — | — | — | — | — | — | Partial | Inventory complete lifecycle |
| Relationships & governance | Family Tree | linked projection | next-of-kin + emergency contacts + portal users | — | — | relevant domain policies | Profile prop | Canonical relationship endpoints | `FamilyTreeTab` | Unified projection over legally distinct canonical records; do not add a fourth table | — | Linked canonical identities | Partial | — | Partial | permissions/remove/archive where supported | Distinct legal identities must not be collapsed | — | — | — | — | `add_relationship` | — | — | — | — | Partial | Sensitive gating, dedupe rules and lifecycle |
| Relationships & governance | Consents | client-owned regulated record | `client_consents` / `ClientConsent` | `Operations\ClientConsentController` | `ConsentValidationService` | `ClientConsentPolicy` | Profile prop | Canonical consent endpoints | Existing consent profile UI | Reuse ClientConsent/Policy; no duplicate consent status vocabulary | `consents.*` | Canonical consents | Partial | — | — | correction/renew/expiry/withdraw | Hard delete non-applicable unless domain supports | — | — | — | — | `consent_record` | — | — | — | — | Partial | P0 parent/client/org authorization and policy enforcement |
| Relationships & governance | Consent Requests | client-owned regulated record | `consent_requests` / `ConsentRequest` | `Operations\ConsentRequestController` | `ConsentRequestService` | `ConsentRequestPolicy` | Profile prop + existing pages | Canonical consent-request endpoints | Existing consent-request UI | Reuse ConsentRequest; family response remains portal-owned | `consents.*` | Canonical requests | Partial | — | N/A | resend/withdraw/cancel/status | Family decision remains in portal | — | — | — | — | — | Existing pages require modal parity/compatibility | Existing flow tests | Existing E2E | — | Partial | P0 parent/client/org binding and in-profile lifecycle |
| Relationships & governance | Family Portal | linked projection | users + client portal pivot | — | — | — | Profile prop | Canonical portal-user endpoints | Existing portal UI | Reuse user identities/pivot; no duplicate portal identity | — | Canonical portal access | Partial | — | Partial | resend/revoke/permission management | — | — | — | — | — | `portal_invite` | Portal-users page escape pending removal | — | — | — | Partial | Sensitive gating and full modal lifecycle |
| Relationships & governance | Actions & Reviews | aggregate | canonical source records | — | `ActionsAggregator` | source policies | Profile prop + JSON metadata | Canonical source endpoints | `ActionsReviewsTab` | Aggregate only; actions open owning tab/modal | source capabilities from `ClientProfileSectionAccess` | Cross-domain aggregate | Partial | — | Partial | assign/snooze/complete/reopen/cancel where supported | Source record lifecycle remains canonical | — | bounded per-source coverage probe with explicit has_more and incomplete-count labels | — | — | `add_action` | Deep links must remain in profile | Aggregate authorization + overflow endpoint tests green | Bounded-count labelling and grouping tests green | No Chrome run by scope | Partial | Source-modal routing and lifecycle completion |
| Relationships & governance | Audit History | aggregate | `audit_logs` / `AuditLog` | — | `AuditLogger` | — | Profile prop | N/A | `AuditHistoryTab` | Read-only projection over canonical audit log | `audit.viewClient` or safe equivalent | Canonical audit log | N/A | Partial | N/A | filters/detail/export only if supported | Mutation is non-applicable | — | — | — | N/A | — | — | — | — | — | Intentionally read-only | Permission-safe detail/export and payload omission |
| Relationships & governance | Privacy | global-domain record represented in profile | `data_subject_requests` / `DataSubjectRequest` | canonical Privacy controllers | canonical Privacy services | canonical Privacy policies | Profile prop | Canonical Privacy endpoints | `ClientPrivacyPanel` + reusable Privacy UI | Reuse Privacy records; no profile copy; global Privacy remains cross-client | `privacy.*` | Canonical DSRs | N/A | Partial | N/A | status/assignment actions | Global governance actions remain external | — | — | — | — | — | Global pages remain for cross-client work | Existing Privacy tests | Existing Privacy E2E | Live 10 Jul: absent from governance rail and finder | Partial | P0 scope/binding, nav/finder, in-profile detail/actions |

## Evidence and checkpoints

### Baseline — 10 July 2026

- `git status --short --branch`: current branch `claude/jovial-pasteur-c7154d`; pre-existing modified `package-lock.json` plus many unrelated untracked files. All are outside this goal and must remain untouched.
- Live read-only inspection at `https://oblivionfindings.com/operations/clients/9040`, Chrome desktop viewport 1440×900:
  - Selecting **Daily care** or **Relationships & governance** changed the tier-two rail but left Overview content rendered and left the URL at `/operations/clients/9040`.
  - Governance rail and section finder omitted **Privacy**.
  - **Edit profile** opened the flat `Edit client` dialog rather than Add Client completion/edit mode.
  - No forms were submitted and no live data was written.
- Frontend baseline: `npx vitest run resources/js/test/client-profile-phase-one-ui.test.tsx resources/js/test/client-profile-source-size.test.ts` → **2 files passed, 3 tests passed**.
- Combined Laravel baseline (`ClientProfilePhaseOneTest`, `ClientProfilePhaseTwoThreeTest`, `ClientProfileDataGapsBuildTest`) produced no output and remained non-responsive for more than two minutes; the exact PHP process was stopped and the run recorded as a baseline timeout, not a pass or product failure. Focused test files will be used per batch, followed by aggregate verification.

### Batch 0 checkpoint A — navigation, edit and authorization foundation

- Frontend navigation foundation implemented:
  - hero-group selection now selects the remembered or first visible subtab;
  - active group, subtab, content and `?tab=` URL use one canonical state;
  - Back/Forward restores tab and dialog state;
  - `support_plan` safely aliases to `care_plans`;
  - Privacy is registered under Relationships & governance and therefore appears in the section finder;
  - hidden deep links fall back to a permitted tab.
- Profile dialog state is URL-addressable through `dialog` and `record`. Initial load and `popstate` hydrate supported goal, ABC, eMAR, care-plan and risk records; missing collection records fail closed. Bespoke daily-note, quick-note, communication-note and profile-edit dialogs now preserve shareable URL state.
- The flat `ClientEditDialog` is now a compatibility loader for the canonical `AddClientDialog`. `ClientController::edit` hydrates the full persisted wizard shape, including cultural/support/about/care fields, medical profile, conditions and emergency contacts, plus organisation-scoped key-worker choices and geofences.
- Server section access is centralized in `ClientProfileSectionAccess`. Unauthorized medical, health, medication summary, finance, consent, risk, incident, First Aid, documents, portal access, audit, Privacy and respite props are absent from the Inertia response; restricted relations/queries are conditionally skipped.
- Legacy `/clients/{client}` is a compatibility entry point: staff are redirected to the canonical operations profile and client/NOK identities to the portal profile, with the query string preserved.
- Consent routes now use `consents.viewAny`, `consents.record`, and `consents.withdraw|consents.manage`; controllers authorize the parent client and nested record, and policies add parent-client defense in depth.
- Appointment creation now requires `calendar.create`; update/delete require `calendar.manage`. The redundant manual timeline emission was removed because `ProjectsToTimelineObserver` is canonical.
- `ClientWorkerEligibility` centralizes staff-role and organisation eligibility for quick key-worker changes, full profile updates and worker assignments.
- TDD RED evidence: `ClientProfileFoundationTest.php` initially failed all 6 tests (17 assertions), including an optional appointment-description 500 and each intended authorization/hydration failure.
- Server GREEN evidence: `$env:DB_DATABASE='oblivion_findings_codex_client_profile'; .\vendor\bin\pest.bat tests\Feature\Operations\ClientProfileFoundationTest.php` → **6 passed, 55 assertions, 165.88s**.
- Frontend GREEN evidence:
  - `client-profile-navigation.test.tsx` + `client-profile-edit-dialog.test.tsx` → **2 files, 9 tests passed** for the dialog-deep-link slice after the earlier focused edit-dialog test;
  - `npm run types` → exit 0;
  - scoped ESLint → 0 errors and one pre-existing hook-dependency warning in `show.tsx`;
  - `git diff --check` → no whitespace errors at this checkpoint.
- Route evidence: `php artisan route:list --path=consent -v` and `--path=calendar/appointments -v` show the granular middleware above.
- Remaining Batch 0 boundary: complete the positive role/capability matrix, care-plan binding and deep review copying, finance locking/idempotency/concurrency, and `ProgressNote` → `ClientNote` consolidation before the broader tab lifecycle sweep.

### Batch 0 checkpoint B — nested binding and care-plan integrity

- Added adversarial coverage for worker assignment, onboarding parent/assignee scope, daily-note shift binding, timeline-comment binding, portal family-note binding, meal preference tenancy, and care-plan create/update/review boundaries.
- Onboarding client and assignee choices are now organisation-scoped. Direct client onboarding rejects populated organisation mismatches, and workflow completion refuses unresolved required steps.
- Daily notes now validate that a selected shift belongs to the profile client.
- Timeline comment deletion/likes and reply parents are scoped through the requested client/event. Portal family-note update/delete is scoped through the requested client before ownership checks.
- Meal preferences now use `ClientPolicy::manageMeals`, preserving legitimate same-organisation kitchen access while denying cross-organisation IDs.
- Care-plan create/reassignment validates the client organisation. Review start/completion uses `CarePlanPolicy`, row locking and a transaction. Review creation copies structured domains, goals and every goal step. Prior sign-offs are carried as read-only `content.review_context.prior_sign_offs`; version-specific sign-off rows intentionally start empty and must be agreed afresh.
- RED evidence: `ClientProfileDailyWorkspaceSecurityTest.php` initially produced **10 failures and 1 pass**; each failure matched the intended unsafe boundary.
- GREEN evidence: `$env:DB_DATABASE='oblivion_findings_codex_client_profile'; .\vendor\bin\pest.bat tests\Feature\Operations\ClientProfileDailyWorkspaceSecurityTest.php` → **11 passed, 35 assertions, 160.16s**.
- Aggregate focused frontend evidence after navigation, deep-link, edit and section-access changes: five Vitest files → **15 tests passed** in 7.79s.
- Remaining Batch 0 boundary: portal/NOK payload matrix, finance transaction integrity, note consolidation and final expanded role-matrix run.

### Batch 0 checkpoint C — direct-route release-gate audit (in progress)

- A controller-by-controller audit of client-profile and portal compatibility routes found additional paths that could bypass the profile's section, tenancy or nested-resource decisions. These are treated as Batch 0 release blockers rather than deferred tab polish.
- Focused clinical/direct-route RED evidence:
  - cross-organisation break-glass creation returned a redirect and created access instead of returning 403;
  - restricted staff could open direct document/routine endpoints despite those profile sections being omitted;
  - both same-organisation and cross-organisation foreign shift IDs were accepted by legacy notes and medication administrations;
  - `ClientProfileClinicalDirectRouteTest.php` → **6 failed, 9 assertions, 352.12s**, with each failure matching the intended unsafe boundary.
  - GREEN after shared policy/section/binding fixes: `ClientProfileClinicalDirectRouteTest.php` → **6 passed, 35 assertions, 1225.53s**.
- Focused summary/RAG/timeline RED evidence:
  - linked portal identities could read and queue the shared unredacted summary surface;
  - a queued summary job trusted a portal actor without re-authorisation;
  - cross-organisation staff summary/timeline reads succeeded;
  - `/rag/clients` required no RAG capability and enumerated unscoped clients;
  - `SummaryRagTimelineAuthorizationTest.php` (initial eight-case run) → **8 failed, 10 assertions, 355.86s**, with each failure matching the audited boundary.
  - the added foreign-organisation summary-dispatch filter independently returned **1 failed, 1 assertion, 1047.67s** (302 instead of 403) before the scope authorisation fix.
  - GREEN for the first ten summary/RAG/timeline boundaries: **10 passed, 21 assertions, 1150.61s**. The later portal-role generic staff-summary/timeline regression is not included in that count and remains separately gated.
- Portal per-client RAG frontend evidence after server capability gating: `portal-client-rag-access.test.tsx` → **2 tests passed**; the allowed card now exposes a semantic level-two heading and the denied state renders no query form. Scoped Prettier check passed.
- Portal message/media RED evidence: `PortalMessageMediaSecurityTest.php` → **16 failed, 28 assertions, 1265.68s**. Failures confirmed unbound message mutations, non-canonical worker choices, public/null photo storage, active SVG/HTML acceptance, and the absence of private delivery, legacy migration and rollback guards.
- Consent stale-state/concurrency RED evidence: six selected regressions produced **6 failures, 8 assertions, 1107.08s**. Five directly confirmed committed decision-audit loss, post-terminal reminders, duplicate stale reminders, stale expiry overriding cancellation, and stale organisation context; the portal-link lock case had a test-fixture error and is being rerun before its production edit.
- Additional RED/GREEN work still running at this checkpoint: foreign-organisation summary dispatch, private client-photo delivery and portal-message binding, personal-asset photo rollback, stale consent state/audit concurrency, and the consolidated binding suite.
- No Batch 0 row is marked Verified from this checkpoint. GREEN aggregate evidence, migration/route proof and desktop browser proof remain required.

### Batch 0 checkpoint D — terminal release gate complete; browser proof deferred after desktop crash

- The complete Batch 0 Laravel aggregate was rerun after every direct-route, media, consent, finance, note-consolidation and test-harness correction: **298 tests passed, 2,679 assertions, 0 failures, 881.49s, exit 0**.
- The aggregate includes the formerly failing positive-timezone calendar case, portal timeline interactions, consent stale-recipient revalidation, tracker consent fixture, RAG tenancy, section-owned ActionsAggregator items, private photo delivery, migration rollback guards and clinical direct-route bindings.
- The MySQL test harness now preserves Laravel's lost-transaction cleanup signal after DDL tests and re-applies the Windows `Path`/`PATH` MySQL-client entry on every application bootstrap. A focused DDL → RAG proof passed **2 tests / 14 assertions**, and the consolidation migration → RAG proof passed **3 tests / 19 assertions** with real schema reloads between committed-DDL tests.
- Fresh static/frontend evidence:
  - PHP syntax: **125 changed PHP files clean**;
  - Pint: **passed**;
  - Prettier: **all matched files clean**;
  - `git diff --check`: **clean**;
  - `npm run types`: **exit 0**;
  - scoped ESLint: **0 errors**, with the one known `show.tsx:1132` unnecessary-hook-dependency warning retained;
  - seven focused Vitest files: **7 passed, 26 tests passed**, 8.63s.
- Production compilation evidence:
  - client Vite build: **4,930 modules transformed, exit 0**;
  - sequential SSR build: **1,582 modules transformed, 41.72s, exit 0**.
- Isolated migration proof used a throwaway MySQL database and completed a full `migrate:fresh`, then rolled back `2026_07_10_000004` through `000001`, reapplied `000001` through `000004`, and confirmed every migration as **Ran** before dropping the database.
- Route/command/scheduler proof confirms authenticated staff and portal photo media/thumbnail delivery routes, consent request and consent lifecycle routes, bounded staff/portal calendar and location routes, RAG and summary routes, break-glass and routine routes, hourly consent expiry/reminder commands, and the five-minute `ReconcileUnpostedClientFundJournalsJob` schedule.
- Desktop browser boundary:
  - the local login surface rendered correctly at **1440×900** with unique email/password/submit controls and no captured console warnings or errors;
  - before the authenticated client profile could be captured, the machine rebooted and the Codex desktop process subsequently terminated whenever the prior in-app browser state was resumed;
  - crash recovery explicitly forbids Browser Use, Chrome, Computer Use and Playwright browser automation for the current recovery turn. Authenticated client-profile and all-tab desktop proof therefore remain **not verified**, not inferred from builds or server tests.
- Batch 0 is terminal-verified but is not marked fully Verified until the deferred authenticated 1440×900 browser gate can be rerun safely.

### Batch 1 checkpoint A — Snapshot / Daily / Plans release-blocker inventory

- `ClientNote::scopeForUser()` currently scopes by organisation but not draft author. The profile labels all returned drafts “My Drafts”, and direct daily-note mutations do not consistently enforce `ClientNotePolicy`. Author-private draft visibility and mutation rules are the first Batch 1 server gate.
- An in-progress care-plan review is not the UI's working version: full goals/sign-offs are hydrated from the prior active plan, and review-mode sign-off/goal mutations target that prior version. Review-version ownership, lifecycle transition guards and fresh review agreement are the second Batch 1 server gate.
- Additional confirmed Batch 1 blockers are the broad `can.edit` action matrix, the false “HR integration is not complete” onboarding placeholder, and capped Timeline/Daily Notes/Care Plan/Actions collections presented without truthful coverage metadata.
- Adjacent tenancy blockers discovered during inventory: ordinary manager client-index composition and `/operations/review-queue` require explicit organisation scoping; the review queue must also exclude drafts and non-daily note types.
- Test-first order: draft ownership/finalisation boundaries → review-version care-plan integrity → exact contextual action map → canonical HR staff-preparation projection → truthful in-profile collection pagination and coverage.

### Batch 1 checkpoint B — bounded terminal slices integrated after reboot

- Daily-note draft integrity is implemented server-first:
  - profile and JSON reads expose submitted notes plus only the current author's drafts;
  - a draft author with create authority can resume, delete and submit that draft without receiving broad submitted-note update authority;
  - colleagues cannot view, update, delete, flag or review another author's draft, even when they hold manager mutation permissions;
  - submitted notes cannot be moved backwards into draft state;
  - review queues, flagged-note projections and follow-up actions exclude drafts;
  - daily-note routes reject unrelated `ClientNote` workflow types;
  - `ClientDailyNoteResource` now returns per-record `update`, `delete`, `flag` and `review` abilities, and note cards use those abilities instead of only global flags.
- Daily-note TDD evidence:
  - RED: `ClientProfileBatchOneIntegrityTest.php` → **6 failed, 17 assertions, 394.00s**, with every failure at an intended ownership, lifecycle, projection or binding boundary;
  - root GREEN after implementation and correction of one test-only nested-ID false positive: **6 passed, 53 assertions, 173.13s**;
  - focused daily-note frontend evidence: **1 file, 3 tests passed**.
- Care-plan review-version integrity is implemented:
  - `care_plans_summary.working_plan` resolves the in-progress review before the published source, with complete review-version goals and sign-offs;
  - an active source is frozen while its review copy exists, archived/history versions are immutable, generic endpoints reject lifecycle-only status transitions, and creation accepts only draft/active states;
  - completing review requires a fresh sign-off on that review version, archives the prior active version transactionally, and activates the agreed review copy;
  - sign-off deletion retracts its canonical timeline projection;
  - care-plan deletion now uses `care_plans.delete` consistently and still refuses historical versions.
- Care-plan TDD evidence:
  - RED: PHP failed on the missing `care_plans_summary.working_plan` contract (**1 failed / 9 pending, 12 assertions, 578.90s**); frontend failed **3/3** for published-version rendering, wrong sign-off target and no fresh-agreement gate;
  - GREEN: `CarePlanReviewIntegrityTest.php` → **10 passed, 72 assertions, 602.98s**; `care-support-plan-review.test.tsx` → **3/3 passed**.
- Adjacent tenancy integrity is implemented:
  - the ordinary operations client index excludes concrete foreign-organisation clients while retaining explicit legacy null-organisation compatibility;
  - `/operations/review-queue` applies the same organisation boundary and contains only submitted canonical daily-note types.
- Tenancy TDD evidence:
  - RED: **2 failed, 24 assertions, 311.84s** (index returned two organisations; queue returned four mixed records);
  - GREEN: `ClientProfileBatchOneTenancyTest.php` → **2 passed, 38 assertions, 625.44s**.
- Root integration evidence:
  - seven focused Laravel files initially produced **66 passed / 1 failed, 440 assertions, 467.86s**; the sole failure was a test-only `assertJsonMissing(['id' => 2])` collision with a nested author ID while the returned top-level note IDs were already correct. No product code changed after that run, and the corrected six-case daily suite then passed **6/6, 53 assertions**;
  - the two focused Vitest files passed together: **2 files, 6 tests**;
  - TypeScript exited **0**; 14 changed PHP/route/test files were syntax-clean; scoped Pint, Prettier and `git diff --check` passed; scoped ESLint reported **0 errors** and only the existing `show.tsx` hook-dependency warning.
- Exact action-matrix audit is complete but its remaining implementation is deliberately deferred to the next lean same-directory task:
  - split care-plan goal management from PATH/client editing;
  - reconcile Onboarding checklist/create/manage routes, controller checks and props, then replace the false HR-integration placeholder with the canonical HR projection;
  - gate Daily/Quick/Communication creation buttons, keyboard shortcuts, hero actions and restored `?dialog=` URLs;
  - split Snapshot chat, worker assignment, medication, risk and navigation actions by their real policies;
  - add truthful totals/`has_more` or pagination for capped profile collections.
- Batch 1 remains **Partial**. In-profile draft resume/detail/edit UX, the complete contextual action matrix, canonical HR onboarding projection, truthful collection coverage, remaining tab lifecycle/error states, and authenticated 1440×900 browser proof are not claimed.
- Recovery boundary: no browser tooling was used and the local preview was not restarted. Batch 2 was not started.

### Batch 1 checkpoint C — exact action matrix continuation (verification deferred by user)

#### Item 1 — Care-plan goals and PATH permissions split

- Canonical ownership:
  - care-plan goal creation, editing, progress, steps and hurdles remain owned by `CarePlanGoal` / `CarePlanGoalController` under the working `CarePlan` and `CarePlanPolicy::update`;
  - PATH planning remains owned by `ClientPathPlan` / `ClientPathPlanController`, with person-centred narrative fields remaining on `Client`, under `ClientPolicy::update`.
- Anti-duplication decision: no new goal, PATH, permission or workflow model was introduced. The profile continues to compose the two existing canonical domains.
- Files changed for this item:
  - `app/Http/Controllers/ClientController.php`;
  - `app/Http/Controllers/Operations/ClientActionsController.php`;
  - `app/Http/Controllers/Operations/ClientFamilyChatController.php`;
  - `app/Http/Controllers/Operations/CarePlanGoalController.php`;
  - `resources/js/pages/operations/clients/show.tsx`;
  - `resources/js/pages/operations/clients/tabs/goals-path.tsx`.
- Implemented behaviour:
  - the profile now emits `can.manage_care_plan_goals` from the working care-plan record policy and `can.edit_path_plan` from the client record policy;
  - goal add/manage controls use only the care-plan goal capability, while PATH editing uses only the client/PATH capability;
  - direct dialog opens and restored `?dialog=goal` / `?dialog=edit_path_plan` URLs fail closed when their exact capability is absent;
  - `CarePlanGoalController` now calls `CarePlanPolicy::update` for both create and nested goal operations rather than relying only on a raw permission check.
- RED/GREEN and regression commands: not run in this continuation because the user explicitly requested no testing at this stage. No test, type, lint, format or build result is claimed.
- Remaining boundary: focused server/frontend RED/GREEN coverage, related regressions, syntax/format/static checks and authenticated browser proof remain deferred. Item 2 is next; Batch 2 has not started.

#### Item 2 — Onboarding authorization and canonical HR staff preparation

- Canonical ownership:
  - client data-checklist overrides remain in `client_onboarding_overrides` through `ClientOnboardingController`;
  - client onboarding workflows and steps remain in `client_onboarding_workflows` / `client_onboarding_steps` through `ClientOnboardingWorkflowController`;
  - staff preparation remains owned by HR `HrEmployeeProfile`, `HrOnboardingChecklist`, `HrOnboardingTask` and `OnboardingService`.
- Anti-duplication decision: `ClientStaffPreparationProjection` is a read-only composition adapter. It joins assigned support-worker users to canonical HR profiles by `user_id` and returns only checklist status/count metadata; it creates no HR/client-profile records and exposes no HR task content.
- Files changed for this item:
  - `app/Services/Clients/ClientOnboardingAccess.php`;
  - `app/Services/Clients/ClientStaffPreparationProjection.php`;
  - `app/Services/Clients/ClientProfileSectionAccess.php`;
  - `app/Http/Controllers/ClientController.php`;
  - `app/Http/Controllers/ClientOnboardingController.php`;
  - `app/Http/Controllers/Operations/ClientOnboardingWorkflowController.php`;
  - `routes/operations.php`;
  - `routes/clients.php`;
  - `resources/js/pages/operations/clients/show.tsx`.
- Implemented behaviour:
  - checklist override, workflow creation and workflow management now have distinct server-authoritative capabilities shared by routes, controllers, section access and Inertia props;
  - operations and legacy checklist routes no longer inherit an accidental outer `clients.update` requirement;
  - onboarding index/show/create/manage middleware now matches the controller capability vocabulary;
  - add-step, complete/skip, workflow completion, checklist override and workflow-start UI gates use their exact capabilities; restored `?dialog=add_onboarding_step` state fails closed without workflow-management authority;
  - the false “HR integration is not complete” placeholder is replaced by a truthful canonical HR onboarding projection for assigned workers, with explicit empty and restricted states and links back to HR-owned pages.
- RED/GREEN and regression commands: not run because the user explicitly requested no testing at this stage. No route, syntax, type, lint, format, build or browser result is claimed.
- Remaining boundary: focused permission/projection tests, route proof, related regressions, static/build checks and authenticated browser proof remain deferred. Item 3 is next; Batch 2 has not started.

#### Item 3 — exact Daily, Quick and Communication creation gates

- Canonical ownership: all three capture flows remain canonical `ClientNote` writes through `Operations\ClientDailyNoteController::store` and `ClientNotePolicy::create`; the note `type` distinguishes daily, quick and communication records.
- Anti-duplication decision: no separate quick-note or communication table/controller was added. Existing shared dialogs and `ClientNote` storage remain authoritative.
- Files changed for this item:
  - `app/Http/Controllers/ClientController.php`;
  - `resources/js/pages/operations/clients/show.tsx`;
  - `resources/js/components/clients/profile/hero.tsx`;
  - `resources/js/pages/operations/clients/tabs/daily-notes.tsx`;
  - `resources/js/pages/operations/clients/tabs/communication-notes.tsx`.
- Implemented behaviour:
  - the server emits separate `can.create_daily_note`, `can.create_quick_note` and `can.create_communication_note` flags from the canonical note-create policy;
  - Daily Notes buttons, Communication creation, hero capture items and `N` / `Shift+N` shortcuts render or fire only with their matching capability;
  - direct dialog calls and restored `?dialog=daily_note`, `quick_note` or `comm_note` URLs fail closed through the same central dialog-capability gate;
  - hidden actions are omitted rather than shown as controls that fail after submission.
- RED/GREEN and regression commands: not run because the user explicitly requested no testing at this stage. No type, lint, format, build or browser result is claimed.
- Remaining boundary: focused server/frontend capability tests, related regressions, static/build checks and authenticated browser proof remain deferred. Item 4 is next; Batch 2 has not started.

#### Item 4 — Snapshot action capabilities split by owning domain

- Canonical ownership and authorization:
  - family chat reuses canonical `OpsConversation` / `OpsMessage` through `ClientFamilyChatController` and `ClientFamilyCommunicationAccess::canView/canManage`;
  - worker assignment remains in the client-worker pivot through `ClientAssignmentController`, `ClientWorkerEligibility`, `clients.assignments.update` and `ClientPolicy::update`;
  - medication signing remains in canonical medication administration through `ClientMedicalController`, `ClientPolicy::viewMedications` and the administration permission set;
  - overall client risk level remains a `Client` quick update under `ClientPolicy::update`, while risk records retain their `risks.create/update/delete` gates;
  - Snapshot navigation uses `ClientProfileSectionAccess` decisions for notes, care plans, risk, medical/MAR, calendar, workers and family portal, plus `sites.viewAny` for site navigation.
- Anti-duplication decision: no chat, assignment, medication, risk or navigation workflow was copied into the profile. Snapshot remains a composition surface over canonical endpoints and records.
- Files changed for this item:
  - `app/Http/Controllers/ClientController.php`;
  - `resources/js/pages/operations/clients/show.tsx`;
  - `resources/js/components/clients/profile/hero.tsx`;
  - `resources/js/components/clients/profile/overview-grid.tsx`;
  - `resources/js/components/clients/profile/flows.tsx`;
  - `resources/js/components/clients/profile/dialog-host.tsx`;
  - `resources/js/components/clients/profile/family-chat.tsx`.
- Implemented behaviour:
  - Snapshot emits and consumes separate client-update, chat-view, chat-send, assignment, medication-administration, risk-update and navigation capabilities;
  - read-only chat viewers can inspect the canonical family thread but receive no composer; users without chat-view authority receive no chat action or restored chat dialog;
  - profile edit, worker management, medication signing and overall risk-level controls are independently omitted when unauthorized;
  - overview/hero navigation links are interactive only when the server-authoritative destination section is available;
  - restored eMAR, risk and profile-edit dialogs fail closed against their exact capabilities;
  - `ClientProfileHero` and `OverviewDesignGrid` no longer accept or use a broad `canEdit` prop.
- RED/GREEN and regression commands: not run because the user explicitly requested no testing at this stage. No syntax, type, lint, format, build or browser result is claimed.
- Remaining boundary: focused action-matrix tests, related regressions, static/build checks and authenticated browser proof remain deferred. Item 5 is next; Batch 2 has not started.

#### Item 5 — truthful coverage for capped profile collections

- Canonical ownership: Timeline events, client notes, care-plan versions/progress notes and each Actions & Reviews source remain in their existing canonical records and endpoints. The profile continues to load bounded recent projections only.
- Anti-duplication decision: no shadow collection, copied record or replacement pagination endpoint was introduced. Coverage metadata describes the existing bounded projections, while full-domain work remains with the canonical owners.
- Files changed for this item:
  - `app/Http/Controllers/ClientController.php`;
  - `app/Services/Client/ActionsAggregator.php`;
  - `resources/js/pages/operations/clients/show.tsx`;
  - `resources/js/pages/operations/clients/tabs/timeline-tab.tsx`;
  - `resources/js/pages/operations/clients/tabs/daily-notes.tsx`;
  - `resources/js/pages/operations/clients/tabs/communication-notes.tsx`;
  - `resources/js/pages/operations/clients/tabs/care-support-plan.tsx`;
  - `resources/js/pages/operations/clients/tabs/actions-reviews.tsx`.
- Implemented behaviour:
  - Timeline and pinned handover now publish exact total, loaded and `has_more` metadata beside their 80/5 record caps, and the tab discloses a partial result when needed;
  - Daily and Communication Notes publish permission-filtered totals beside their 50-record caps; headline metrics use the total while list copy discloses the loaded subset;
  - care-plan recent progress notes and version history publish total, loaded and `has_more` metadata beside their 5/30 caps, with version history visibly labelled when partial;
  - ActionsAggregator probes one record beyond each existing per-source cap, preserves the bounded aggregate, and reports `has_more`; profile badges and metrics stop presenting loaded counts as complete totals when overflow is detected.
  - the dedicated client Actions JSON endpoint preserves its existing `data` array and now returns matching `meta.loaded` / `meta.has_more` coverage rather than presenting the bounded response as complete.
  - family chat preserves its canonical 100-message recent window and now returns/displays total, loaded and `has_more` metadata when older messages exist.
- RED/GREEN and regression commands: not run because the user explicitly requested no testing at this stage. No syntax, type, lint, format, build or browser result is claimed.
- Remaining boundary: all five exact action-matrix items are implemented but unverified in this continuation. Focused server/frontend tests, regressions, syntax/static/build checks and authenticated browser proof remain deferred; Batch 2 has not started.

#### Source-audit continuation — verification commands still deferred

- Re-read the five implemented slices against their current controllers, services, routes, Inertia props and profile consumers without running Chrome, tests, build, lint, type or static-check commands.
- Corrected two source-level defects found by that audit:
  - `GoalsPathTab` now imports the `Badge` component that its goal cards render;
  - `ClientActionsController` now carries the aggregate overflow contract through the dedicated JSON endpoint as `meta.loaded` / `meta.has_more`, preserving the existing `data` array for compatibility.
  - the canonical family-chat endpoint and popup now disclose when its 100-message recent window omits older messages.
- Reconfirmed from current source that restored goal/PATH/onboarding/note/chat/eMAR/risk/profile dialogs pass through the central exact-capability gate, and that the Snapshot hero/overview components receive optional domain actions rather than a broad edit flag.
- Reconciled the emitted action flags against current route middleware and controller authorization: care-plan goal writes require `care_plans.update` plus `CarePlanPolicy::update`; PATH/profile risk updates require `ClientPolicy::update`; note capture requires `ClientNotePolicy::create`; assignment, eMAR and onboarding routes retain their matching permission vocabulary; family chat remains controller-gated through `ClientFamilyCommunicationAccess`.
- At this source-audit checkpoint, executable verification was still pending. Checkpoint D records the later authorised focused suites and proportional static/build checks; browser proof remained excluded and Batch 2 was not started.

#### Deferred verification set — historical plan (executed in checkpoint D)

- Existing focused server suites to rerun include:
  - `tests/Feature/Operations/CarePlanGoalManagementTest.php`;
  - `tests/Feature/Operations/ClientProfileAggregateAuthorizationTest.php`;
  - `tests/Feature/Operations/ClientProfileDirectRouteAuthorizationTest.php`;
  - `tests/Feature/Operations/ClientProfileBatchOneIntegrityTest.php`.
- Existing focused frontend suites to rerun include:
  - `resources/js/test/client-profile-phase-one-ui.test.tsx`;
  - `resources/js/test/client-profile-navigation.test.tsx`;
  - `resources/js/test/client-profile-section-access.test.ts`.
- New focused coverage was required for the five continuation slices: split goal/PATH capability combinations; onboarding route/controller/prop parity and tenant-safe HR projection; Daily/Quick/Communication controls, shortcuts and restored URLs; read-only chat plus independently gated Snapshot actions; and overflow metadata/copy for every capped collection and the Actions JSON endpoint. Those cases were added and passed in checkpoint D.
- At the time this set was prepared, proportional verification still required the related Batch 1 regressions, PHP syntax on changed PHP/route files, scoped frontend formatting/lint, TypeScript, client build and SSR build. Checkpoint D records their later execution and results.

### Batch 1 checkpoint D — five-item continuation verified

- The previously deferred verification was authorised and completed on 11 July 2026. This checkpoint supersedes the earlier “not executed” wording for the bounded five-item continuation only.
- Final consolidated Laravel command covered six focused files: `CarePlanGoalManagementTest`, `ClientProfileAggregateAuthorizationTest`, `ClientProfileDirectRouteAuthorizationTest`, `ClientProfileBatchOneIntegrityTest`, `ClientFamilyCommunicationSecurityTest` and the new `ClientProfileBatchOneContinuationTest`:
  - **60 passed, 325 assertions, 241.70s, exit 0**.
- The new continuation server coverage proves:
  - care-plan goal and PATH capabilities remain independent;
  - Daily/Quick/Communication creation capabilities come from the canonical note-create policy;
  - chat view/send, medication administration, client risk update and destination navigation are independently emitted;
  - onboarding creation does not grant workflow/checklist management;
  - assigned workers project through canonical tenant-scoped HR employee/checklist/task records without copying HR data;
  - the bounded Actions endpoint and 100-message family-chat window return truthful overflow metadata.
- Final consolidated Vitest command covered `client-profile-phase-one-ui`, `client-profile-navigation`, `client-profile-section-access`, `care-support-plan-review` and `goals-path-tab`:
  - **5 files passed, 29 tests passed, exit 0**.
- The continuation frontend coverage proves split goal/PATH controls, exact hero capture actions, `N` / `Shift+N`, restored dialog fail-closed behaviour, read-only family chat and truthful partial-result copy for Daily Notes, Communication, Timeline, pinned handover, care-plan versions, Actions and family chat.
- Static/build evidence:
  - PHP syntax: **15 scoped PHP/controller/service/route/test files clean**;
  - scoped Pint: **passed** after formatting only the onboarding workflow controller and staff-preparation projection;
  - scoped Prettier: **passed**;
  - scoped ESLint: **exit 0, 0 errors**; five existing component-surface style warnings remain in `actions-reviews.tsx` and `communication-notes.tsx`; `show.tsx` and the changed test harnesses are clean;
  - TypeScript `tsc --noEmit`: **exit 0** after replacing three stale test uses of removed `canEdit` with `canManageGoals`;
  - client production build: **4,930 modules transformed, exit 0, built in 4m 16s**;
  - SSR production build: **1,582 modules transformed, exit 0, built in 45.24s**;
  - scoped `git diff --check`: **exit 0**.
- No Chrome or other browser automation was used, as explicitly requested. No deployment, commit, push, PR or Batch 2 work was performed.
- Completion boundary: the five-item Batch 1 continuation is implemented and verified. The wider Client Profile Batch 1 ledger remains **Partial** for the unrelated lifecycle/detail states and separately scoped authenticated browser proof already recorded in the matrix.

### Batch 1 checkpoint E — recovered, reconciled and release-verified

- On 12 July 2026, the user separately authorised committing this Client Profile work, merging it to `main` and pushing `main`. This supersedes the original VCS prohibition for those operations only; it does not authorise browser automation, direct deployment commands, live writes, live migrations or live seeding.
- Crash recovery reconstructed the exact successful-write boundary across the original task, all descendant agents and the continuation task: **175 paths** (**54 added, 121 modified, 0 deleted**). The unrelated dirty `package-lock.json`, prompt documents, HR/Fleet work and other root-checkout files were excluded.
- The recovered change was committed from source base `229be24b`, then cherry-picked onto current `main` baseline `9eaab3a5` in `codex/client-profile-web-completion`. Seven newer-main overlap paths auto-merged without conflict and were reviewed individually. Finance/IT shared props, IT morph maps and observer, Guardrail cards, removed vestigial client-ledger payload, and IT/budget schedules all remain intact.
- Integration review removed one duplicated `pageProps.first_aid_records` dependency, bumped the permission-map cache shape from `v4` to `v5`, and applied Pint/Prettier-only normalisation to the four touched baseline files that failed the repository formatters.
- Fresh isolated-branch verification:
  - focused Laravel gate: **60 passed / 325 assertions / 0 failed / 183.55s**;
  - focused Vitest gate: **5 files / 29 tests / 0 failed / 48.38s**;
  - PHP syntax: **137 changed PHP files clean**;
  - Pint: **all 137 changed PHP files passed**;
  - Prettier: **all 37 changed TypeScript files passed**;
  - ESLint: **exit 0 with zero output** across all 37 changed TypeScript files;
  - TypeScript `tsc --noEmit`: **exit 0**;
  - client production build: **4,940 modules transformed / exit 0 / 3m 27s**;
  - SSR production build: **1,592 modules transformed / exit 0 / 42.68s**;
  - `git diff --check main`: **exit 0**.
- No browser automation was rerun because the earlier browser attempt repeatedly crashed Codex. The wider workspace remains **Partial** exactly where the completion matrix says browser proof or later-batch lifecycle/detail work is still outstanding; this release checkpoint does not relabel those boundaries as complete.

## Deployment and external boundary

- No deployment is authorized. The live website is evidence of the pre-change state only.
- Local implementation and verification must be completed before a separately authorized deployment can make post-change live proof possible.
- No mobile work is included or claimed.
