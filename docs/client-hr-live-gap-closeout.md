# Client Profile and HR Live Gap Closeout

**Status:** Verified complete
**Started:** 2026-07-12 (Pacific/Auckland)
**Branch:** `codex/client-hr-live-gap-closeout`
**Worktree:** `C:\Users\steph\Herd\oblivionfindings-client-hr-live-gap-closeout`
**Starting revision:** `b16e934d72fa8bafa0a86882c4772f6b20a56289`
**Starting `origin/main`:** `b16e934d72fa8bafa0a86882c4772f6b20a56289`

## Safety and ownership decisions

- The dirty detached root checkout is out of scope and must remain untouched.
- The dedicated `main` worktree is clean but currently carries five unpushed Fleet commits ending at `ab7f3636`; those commits must be preserved and reconciled before the final explicit merge.
- Client tab aliases remain owned by `resources/js/pages/operations/clients/tabs/_groups.ts`.
- Care & Support Plan remains owned by the canonical `care_plans` workspace.
- HR team ownership is `HrEmployeeProfile.team`; `HrPosition.team` remains an independent position-template attribute and is not dual-written.
- Durable synthetic HR data must extend the existing HR demo seeding path. Transient live lifecycle fixtures use one unique marker and must be removed.
- Control Room escalation remains owned by `AutoEscalateControlRoomQueues`.

## Slice ledger

### Startup

- Start SHA: `b16e934d72fa8bafa0a86882c4772f6b20a56289`
- End SHA: `19892c89a66d5670d004ffd93ea56f6e0635455c`
- Files changed: `docs/client-hr-live-gap-closeout.md`
- Tests: worktree, branch, clean baseline, dependency links, and testing environment verified before feature edits.
- Live URLs: none
- Smoke marker and record IDs: none
- Cleanup: not applicable
- Commit SHA: `19892c89a66d5670d004ffd93ea56f6e0635455c`
- Remaining: Slices 1-4, aggregate gates, integration, push, deployment, scheduled-interval observation, and single-session Chrome proof.

### Slice 1 — Client tab canonicalisation

- Start SHA: `19892c89a66d5670d004ffd93ea56f6e0635455c`
- End SHA: `e2958ca0a1ef64bb7c4729ffe506e5f14b71a43d`
- Ownership decision: legacy aliases stay in `_groups.ts`; the canonical care-plan workspace remains `care_plans`.
- Files changed: `resources/js/pages/operations/clients/tabs/_groups.ts`, `resources/js/test/client-profile-navigation.test.tsx`, `tests/e2e/operations-client-profile-phase-1.spec.ts`, and this ledger.
- Root cause: the alias canonicaliser called the browser History API directly, bypassing Inertia's history-state ownership. The visible URL changed, but Inertia navigation state was not updated safely.
- Fix: route alias `replace`/`push` operations through the supported Inertia router while retaining the full query and preserving scroll/state.
- Red proof: with the prior direct-History implementation restored temporarily, the focused Vitest run reported **1 failed / 13 passed** because the legacy alias made zero `router.replace` calls.
- Green tests and exact counts: focused Vitest **14 passed**; targeted Chromium desktop alias scenario **1 passed**; Prettier check passed for all 3 changed TS/TSX files; ESLint passed with zero warnings; `npm run types` passed; production client build passed (`4943` modules transformed).
- Browser URLs: local `/operations/clients/{id}?tab=support_plan&dialog=quick_note&record=99&source=legacy` canonicalised to the same URL with `tab=care_plans`; Back returned to the previous dashboard URL, Forward restored the canonical URL and open dialog, reload retained the canonical deep link, and the recent-client link used `tab=care_plans`.
- Console/network evidence: the targeted Playwright case asserted zero console errors and zero `>=400` responses for the target client route.
- Full-file classification: **1 passed / 2 failed**. The new alias case passed. Both pre-existing note-capture cases could not find the permission-gated quick-note action after canonical global setup failed on the existing duplicate `EMP0003` unique key in `SystemUsersSeeder`; the same two failures reproduced with reseeding skipped. This is a local fixture/seeder blocker, not an alias regression.
- Smoke marker and record IDs: exact local synthetic clients `5-38`, alternating `Playwright Profile` and `Recent Playwright Client`.
- Cleanup: deleted exactly client IDs `5-38` from the local testing database through model deletion; verified `remaining=0` for that ID set. The release rerun exposed that the spec itself did not automate this cleanup, so ID-scoped `afterEach` teardown now deletes each run's synthetic clients and their exact `auditable_type=client` audit rows. The final rerun passed with `synthetic_clients=0` and audit logs restored to `584`.
- Commit SHA: `e2958ca0a1ef64bb7c4729ffe506e5f14b71a43d`
- Remaining: post-deployment Chrome acceptance.

### Slice 2 — HR team ownership and Calendar configuration

- Start SHA: `e2958ca0a1ef64bb7c4729ffe506e5f14b71a43d`
- End SHA: `e75047ab63ad57684e29ced57fbc678cab3e52ff`
- Ownership decision: `HrEmployeeProfile.team` is the single employee-membership fact and the current Calendar contract. `HrPosition.team` remains an independent position-template attribute and is not dual-written. Calendar, manager create/edit, and demo assignments all use the profile field; no schema, route, taxonomy, or parallel configuration surface was added.
- Files changed: `HrEmployeeProfile`, the two employee requests, `EmployeeProfileController`, `CalendarController`, `HrDemoSeeder`, manager create/edit UI, shared segmented-control disabled support, Calendar wizard empty state, focused feature tests, demo-seeder test, and this ledger.
- Red proof: the first genuine focused test asserted that create input `clinical support` must resolve to the tenant's existing `Clinical Support`; it failed because the newly created profile team was `NULL`.
- Normalisation and safety: leading/trailing and repeated whitespace collapse; blank becomes `NULL`; existing tenant spelling/case is reused; 255-character maximum is enforced; foreign-tenant edit/update returns 404; Calendar options include only active same-tenant profiles and deduplicate case/spacing variants.
- Manager UI: Add Employee and Edit Employee expose the canonical profile team. Edit copy explains Calendar use and clearing. Calendar disables `A team` when none exist and links to `/hr/people` instead of exposing a dead selector.
- Demo ownership: `HrDemoSeeder` now targets only the three exact `hrdemo.worker{1..3}@example.test` profiles and assigns `Community Support`, `Community Support`, and `Operations` idempotently; it no longer selects arbitrary active profiles for its demo workflow data.
- Green tests and exact counts: `HrTeamConfigurationTest`, `HrDemoSeederTest`, and `HrCalendarResilienceTest` passed **16 tests / 118 assertions**. PHP syntax passed for all 8 changed PHP/test files. Pint passed before the legacy-formatting churn was reverted. TypeScript passed. ESLint passed with zero warnings. `git diff --check` passed.
- Formatting classification: all four touched legacy TSX files fail whole-file Prettier on the starting revision; formatting them would create hundreds of unrelated lines, so that sweep was explicitly reverted and only scoped lines remain. The changed PHP requests/seeder likewise predate current whole-file Pint formatting; the broad mechanical sweep was reverted for minimal churn.
- Browser URLs: pending
- Smoke marker and record IDs: pending
- Cleanup: no Slice 2 local transient records remain; feature tests use isolated per-process databases that are dropped at shutdown. Durable known demo assignments are intentionally idempotent.
- Commit SHA: `e75047ab63ad57684e29ced57fbc678cab3e52ff`
- Remaining: post-deployment browser lifecycle acceptance.

### Slice 3 — Fixture-gated HR browser matrix

- Start SHA: `abd84351b30f495b9bdefdf6ba7dce3d75784e5e`
- End SHA: `50b27bf9686a7b3dc9c553a87ed83caaae680c5f`
- Ownership decision: exercise canonical HR lifecycle workflows and existing demo ownership; do not create parallel implementations.
- Files changed: `tests/e2e/hr-live-gap-closeout.spec.ts` and this ledger. No Slice 3 application source, route, schema, or parallel workflow was added.
- Baseline counts: users `35`; profiles `33`; audit logs `584`; calendar events/attendees/reminders/attachments `0/0/0/0`; salary bands `3`; offboarding checklists/tasks/exit interviews `0/0/0`; candidates/applications/offers `5/5/0`; approval chains/steps `2/2`; payroll runs/payslips `1/0`.
- Local discovery state: `HrDemoSeeder` was run idempotently and exact synthetic demo workers were confirmed (`3`). It supplies one payroll run and two generic approval chains but no offer, payslip, offboarding, or exit-interview lifecycle fixture, so the Playwright spec creates transient canonical-model records under one exact marker.
- Marker: `CODEX-LIVE-HR-GAPS-20260712T151828`. Final green-run IDs: leaver user/profile `57/54`; active payroll user/profile `58/55`; calendar event `16`; salary band `19`; offboarding checklist/task `16/16`; offer `14`; generic approval chain `16`; leave-chain routes `27/28`; payroll run/payslip `15/14`; private attachment `hr/calendar/1/CODEX-LIVE-HR-GAPS-20260712T151828.txt`.
- Mail-driver safety: local default and resolved transport were both `array`, so offer resend exercised notification construction without external delivery.
- Browser URLs and results: Chromium desktop covered `/hr/settings/audit-log?action=codex.live_hr_gaps`, `/hr/calendar`, `/hr/compensation/bands`, `/hr/offboarding/{id}`, `/hr/recruitment?tab=offers`, `/careers/offers/{old-token}`, `/hr/approvals/chains`, `/hr/payroll`, `/hr/payroll/payslips`, and `/hr/my/payslips`; it also loaded `/hr/training/catalog`, `/hr/feedback`, `/hr/performance?tab=supervision`, `/hr/time?tab=overview`, and `/operations/shifts` as regression routes. Payroll payslips were asserted at `390x844`; other rows ran at desktop width.
- Lifecycle evidence: audit filtering and System attribution; calendar archive/restore with `1/1/1` attendees/reminders/attachments retained; salary-band placement/deactivate/reactivate; one canonical exit interview after reload with offboarding-completion login revocation preserved; required offer-expiry reason, immediate old-token invalidation, actor/reason attribution, resend token rotation and attribution reset; native leave-route reorder/edit/deactivate/reactivate; payroll run/admin payslip/mobile actions; and active employee self-service net pay.
- Green browser proof: final matrix **1 passed** in **1.6m**. The spec asserted zero captured console errors and zero `>=400` HR responses.
- Cleanup and final counts: reverse cleanup deletes the attachment and every marker record, pivot, and exact auditable ID. After a second full green run, marker users/profiles/events/files/audits were all `0`, and every baseline count above returned exactly, including audit logs `584`.
- Backend suites: `CanonicalAuditOrganizationTest`, `DeferredRetentionLifecycleTest`, `HrCalendarResilienceTest`, `SalaryBandPlacementTest`, `ExitInterviewWizardTest`, `OffboardingWizardStoreTest`, `RecruitmentDeferredLifecycleTest`, `ApprovalChainTenantTest`, `MyPayslipsSelfServiceTest`, `PayslipPdfTest`, and `PayrollRunIntegrityTest` passed **59 tests / 426 assertions**.
- Static gates: new spec Prettier passed, ESLint passed with zero warnings, TypeScript passed, and `git diff --check` passed.
- Commit SHA: `50b27bf9686a7b3dc9c553a87ed83caaae680c5f`
- Remaining: commit, aggregate release gates, deployment, and the required post-deployment real Chrome acceptance session.

### Slice 4 — Control Room scheduled escalation

- Start SHA: `e75047ab63ad57684e29ced57fbc678cab3e52ff`
- End SHA: `abd84351b30f495b9bdefdf6ba7dce3d75784e5e`
- Ownership decision: keep escalation in `AutoEscalateControlRoomQueues`; apply only required callback capture changes.
- Files changed: `app/Jobs/AutoEscalateControlRoomQueues.php`, `tests/Feature/ControlRoom/AutoEscalateControlRoomQueuesTest.php`, and this ledger.
- Red proof: the eligible-alert regression reproduced `Undefined variable $automationService` at `AutoEscalateControlRoomQueues.php:91` after entering the nested alert chunk.
- Fix: capture the injected automation service in the outer queue chunk and inner alert chunk. No escalation behavior, service, route, model, or schema was changed.
- Green tests and exact counts: focused job regression plus `AlertAutomationServiceTest` passed **14 tests / 31 assertions**. The regression verifies old assignment closure, new assignment creation, alert queue/level/context update, notification call, automation call, and clean completion.
- PHP gates: syntax passed for both changed PHP files; scoped Pint passed; `git diff --check` passed.
- Scheduled-interval server evidence: pending
- Commit SHA: `abd84351b30f495b9bdefdf6ba7dce3d75784e5e`
- Remaining: deployment and one normal scheduled-interval log observation.

## Crash-containment checkpoint — 2026-07-12

- Stop request received after Slice 3 discovery began. No further slice work, merge, push, deployment, or browser activity was performed.
- Worktree: `C:\Users\steph\Herd\oblivionfindings-client-hr-live-gap-closeout`
- Branch: `codex/client-hr-live-gap-closeout`
- HEAD before this checkpoint commit: `abd84351b30f495b9bdefdf6ba7dce3d75784e5e`
- Dirty state before ledger update: clean.
- Last safe command: `npm run build` completed successfully with `4943` modules transformed in `3m 52s`.
- Running-process state: no preview, Playwright, Vite, or browser process started by this task remains. Port `4173` is not listening. An older PHP server owned by another task is listening on `127.0.0.1:8768` (PHP PID `37228`, started 2026-07-11); it was not started or stopped here.
- Next step on resume: start from this ledger and branch, create the unique marker-scoped Slice 3 fixtures only after baseline counts and mail-driver safety are recorded, then run the required HR browser lifecycle matrix and cleanup proof.

## Code-review remediation checkpoint — 2026-07-12

- Start SHA: `42b7c7438eac8e6b991d4209e8b5d5dc649256da`.
- Review disposition: all five Important findings from the first independent review were addressed. The first follow-up then found a new Critical cross-tenant Shift task disclosure and an Important no-op Calendar team filter; both now have red/green regressions and minimal fixes. The second follow-up found no remaining Critical or Important issues and marked the branch ready to merge subject to merged-revision gates.
- Calendar canonical-team fix: store/update validation now resolves an active same-tenant team through `HrEmployeeProfile::normalizeTeam`, stores the canonical active-profile spelling, and compares viewer/audience teams case-insensitively after whitespace normalization. The regression creates, updates, and reads a team event using case and repeated-spacing variants.
- Exact cleanup fix: the HR E2E helper accepts one exact marker and uses exact names, IDs, employee numbers, notes, emails, and paths. A probe/sentinel test proves cleanup removes only the requested marker, including marker-bearing audit rows. Client fixtures now use event-suppressed `forceDelete()` and assert exact IDs are absent even with trashed rows included.
- Browser-proof expansion: the Calendar event is created through the team-audience wizard; scorecard-quorum override is submitted with a required reason and audited; generic approval-chain presence plus native leave add/edit/down/up/deactivate/reactivate/remove are exercised; manager/worker payslip view/download and the existing `390x844` card branch are exercised; Training Catalog, Feedback Pending, Supervision search, the real 30-second Timekeeping partial refresh, Shifts Open, and Calendar view are active controls rather than render-only checks.
- Mail safety: offer resend now aborts unless the resolved mail transport is `array` or `log`.
- Incidental in-scope blocker fixed: authenticated pages could fail when an active shift task existed because `ShiftTaskProvider` eager-loaded nonexistent `shift.user`. The provider now uses canonical `shift.staff`; its TDD regression first failed with `RelationNotFoundException` and then passed.
- Files changed: `app/Domain/Hr/Services/HrCalendarAggregator.php`, `app/Http/Controllers/Hr/CalendarController.php`, `app/Services/Tasks/Providers/ShiftTaskProvider.php`, `tests/Feature/Hr/HrCalendarResilienceTest.php`, `tests/Feature/Tasks/ShiftTaskProviderTest.php`, `tests/e2e/hr-live-gap-closeout.spec.ts`, `tests/e2e/operations-client-profile-phase-1.spec.ts`, and this ledger.
- Backend evidence: post-format Calendar plus Shift provider bundle **11 tests / 40 assertions** in **253.82s**; the broader corrected Calendar/team/Control Room review bundle previously passed **16 tests / 83 assertions** in **191.64s**.
- HR browser evidence: exact marker `CODEX-LIVE-HR-GAPS-20260712T181400-FINAL-LOCAL`; combined desktop spec **3 passed** in **4.0m** (cleanup isolation, lifecycle matrix, five regression controls). The lifecycle matrix itself passed in **2.5m** and the five-control test passed in **59.0s**, including the timed Inertia refresh and preserved Overview selection.
- Cleanup discovery and correction: the first post-run audit found one attachment-create audit row after all domain rows/files were gone. Cleanup now captures the exact attachment IDs, deletes their auditable rows, retains the uniquely named run-audit rule, and uses JSON marker equality for the cleanup-scope probe rather than a broad marker substring. The strict probe/sentinel test passed **1/1** in **11.8s**; final marker counts were calendar `0`, profiles `0`, users `0`, candidates `0`, audits `0`, and attachment file absent.
- Client browser evidence: targeted legacy alias Playwright **1/1** in **30.5s** on a drained local socket pool; exact synthetic `Playwright Profile` and `Recent Playwright Client` counts including trashed rows were both `0` after teardown.
- Environment classification: repeated local preview runs accumulated over 1,000 PHP built-in-server TIME_WAIT sockets and twice produced Chromium `ERR_NO_BUFFER_SPACE` chunk failures. The same Client proof passed unchanged after the pool drained to 136; these failures were local transport exhaustion, not application failures.
- Static evidence after all review edits: PHP syntax green for all five affected PHP files; scoped Pint green; Prettier green for both affected E2E specs; ESLint zero-warning green; TypeScript green; `git diff --check` green.
- First review-remediation commit: `3f9885c1402af3f3f04fd767eb6f9f0bf7ab9658`.
- Follow-up-review TDD: the new bundle failed **2 / 11 tests** in **246.23s** — the manager’s Clinical Support filter still contained `Operations team hui`, and the tenant-1 scheduler received two tasks including `Foreign tenant task`. After adding normalized team-audience filtering and `shifts.organization_id = viewer.organization_id`, the same bundle passed **11 tests / 45 assertions** in **221.27s**.
- Follow-up-review commit: `202227818d1b9718dba7ac040bda4792240da47a`.
- Independent clearance: no current Critical or Important findings; Shift tenant isolation, normalized team filtering, and exact attachment/audit cleanup were each confirmed with file/line evidence.
- Remaining: upstream reconciliation, merged-revision gates, push, deployment, scheduled-interval observation, and one final real Chrome session.

## Release gates

- Focused and aggregate tests: green — Slice 3 HR lifecycle pack **59 tests / 426 assertions**; HR team plus Control Room pack **30 tests / 149 assertions**; review-fix Calendar plus Shift bundle **11 tests / 40 assertions**; Client navigation Vitest **14/14**; post-build Client alias Playwright **1/1**; expanded HR browser spec **3/3**. The known two non-alias Client scenarios remain locally blocked by the pre-existing duplicate-`EMP0003` seeder failure recorded in Slice 1.
- PHP syntax: green for all 10 changed PHP/test files.
- Pint: seven changed PHP files green. The two employee request files retain their starting-revision `binary_operator_spaces` failure and `HrDemoSeeder` retains its starting-revision `fully_qualified_strict_types` / `ordered_imports` failures; the broad unrelated formatting sweep remains intentionally excluded.
- Prettier: the Client files and new HR matrix are green. The four touched legacy HR UI files retain their starting-revision whole-file failures recorded in Slice 2; no new formatter failure was introduced.
- ESLint zero-warning: green across all eight changed TS/TSX files.
- Wayfinder: green; actions and routes regenerated with no tracked diff.
- TypeScript: green after Wayfinder regeneration and after the final Slice 3 changes.
- Client build: green — `4943` modules transformed in `5m 2s`; manifest generated.
- SSR build: green — `1595` modules transformed in `1m 40s`; SSR manifest and bundle generated.
- `git diff --check`: green.

## Integration and deployment

- Latest upstream reconciliation: fetched `origin/main` at `0dff98b787525c67a004830e233f37530c87166b`; merge-tree inspection found no conflicts and preserved the five intervening Fleet commits.
- Feature commit(s): `19892c89`, `e2958ca0`, `e75047ab`, `abd84351`, `50b27bf9`, `42b7c743`, `3f9885c1`, `20222781`, and review-clearance ledger `8a1190ee`.
- Merge commit: `ee87852ef1139d55f8343027977eef817752e618` (`Merge Client Profile and HR live gap closeout`).
- Local `main`: `ee87852ef1139d55f8343027977eef817752e618` before this release-candidate ledger commit; clean and 11 commits ahead of `origin/main`.
- `origin/main` and `git ls-remote`: matched application release SHA `46acdd312e432c93cf09e8264d6d2dcb35f13637` before the final ledger-only closeout.
- Deployed application SHA: `46acdd312e432c93cf09e8264d6d2dcb35f13637`.
- Server state: clean `main`, zero pending migrations, regenerated Wayfinder and client manifest, optimized Laravel caches, restarted Redis queue worker, and healthy authenticated/live HTTP acceptance.

### Merged-revision release candidate — 2026-07-12

- Wayfinder regenerated cleanly: `883` routes and `561` actions with no tracked diff. The first TypeScript invocation raced route generation and reported missing generated modules; the post-generation rerun passed.
- PHP behavior bundle: `HrTeamConfigurationTest`, `HrDemoSeederTest`, `HrCalendarResilienceTest`, `AutoEscalateControlRoomQueuesTest`, and `ShiftTaskProviderTest` passed **18 tests / 136 assertions** in **229.79s**.
- Client navigation Vitest passed **14/14** in **73.56s**. Changed TypeScript/TSX ESLint passed with zero warnings. PHP syntax passed for all 14 changed PHP files.
- Production builds passed: client **4,944 modules** in **4m23s** and SSR **1,596 modules** in **1m30s**. TypeScript and `git diff --check` passed.
- Starting-revision-only format classification remains unchanged: three legacy PHP files retain the documented whole-file Pint findings and four legacy HR UI files retain the documented whole-file Prettier findings; no new formatter failure was introduced.
- Merged Client alias proof: targeted Chromium desktop **1/1** in **30.4s** with exact synthetic Client rows absent after teardown.
- Merged HR proof: `tests/e2e/hr-live-gap-closeout.spec.ts` passed **3/3** in **4.0m** on a serial Chromium desktop worker. Exact marker `CODEX-LIVE-HR-GAPS-20260712071119` covered cleanup isolation, the canonical lifecycle matrix, and the five active regression controls including the real timed Timekeeping refresh and `390x844` payroll-card branch.
- Merged HR cleanup proof: exact marker counts are events `0`, profiles `0`, users `0`, candidates `0`, approval chains `0`, salary bands `0`, payroll runs `0`, audits `0`; `hr/calendar/1/CODEX-LIVE-HR-GAPS-20260712071119.txt` is absent from private storage.
- Independent review clearance remains current: no Critical or Important findings after the cross-tenant Shift task and Calendar team-filter regressions were fixed and rerun.

### First live deployment and acceptance checkpoint — 2026-07-13

- Production checkout was already clean on pushed SHA `115cd5e96d467dc49613acf15e054925fc5a49f1`. Wayfinder generation passed, the required `NODE_OPTIONS=--max-old-space-size=4096 npm run build` transformed **4,944 modules** in **2m48s**, `optimize:clear`, `optimize`, and `queue:restart` passed, the manifest was regenerated at `2026-07-12 15:21:37 UTC`, the Redis queue worker was running, `/login` returned `200`, protected target routes returned the expected unauthenticated `302`, and there were zero pending migrations.
- Real Chrome Client acceptance at `1440x900`: `https://oblivionfindings.com/operations/clients/9040?tab=support_plan` visibly replaced the legacy alias with `?tab=care_plans`; Plans & goals and Care & Support Plan were active, the canonical support-plan content rendered, all visible recent-client links used `tab=care_plans`, the dialog/record/source query survived canonicalisation, and the captured console error list was empty.
- The idempotent canonical `HrDemoSeeder` populated only the three known demo profiles: `Community Support`, `Community Support`, and `Operations`. Live `/hr/calendar` then exposed exactly the active canonical options `Community Support` and `Operations`.
- Live UI discovery: `/hr/people/48/edit` rendered `start_date` blank even though the profile had `2025-11-03`, so native form validation prevented the normal Save Changes path from issuing an update request. Root cause was direct model serialization turning all four edit-date props into ISO timestamps that native `type=date` inputs reject.
- TDD evidence: the new Inertia boundary regression failed **1 test / 12 assertions** because `profile.start_date` was `2025-11-03T00:00:00.000000Z`; the minimal controller payload fix serializes `start_date`, `end_date`, `probation_end_date`, and `visa_expires_at` as date-only strings, and the focused rerun passed **1 test / 18 assertions**.
- Post-fix focused gate: the complete `HrTeamConfigurationTest` passed **6 tests / 54 assertions** in **182.37s**; PHP syntax, scoped Pint, and `git diff --check` passed.
- Date-input release: committed and pushed as `46acdd312e432c93cf09e8264d6d2dcb35f13637`; production was rebuilt and optimized on that exact clean SHA. The final manifest timestamp was `2026-07-12 15:52:35 UTC`, the Redis worker was running, and zero migrations were pending.
- Team acceptance: Demo Admin changed `/hr/people/48/edit` from `Operations` with a whitespace/case variant of `Community Support`; the UI saved and reloaded the canonical `Community Support` value. The same normal UI then restored `Operations`, which was confirmed after reload. The Start Date rendered as `2025-11-03` after the date-boundary fix. Console errors were empty.

### Final live production acceptance — 2026-07-13

- Client Profile, Demo Admin, desktop `1440x900`: `/operations/clients/9040?tab=support_plan` canonicalized to `?tab=care_plans`; the correct Plans & goals / Care & Support Plan surface rendered, legacy dialog query keys survived canonicalization, visible profile links used the canonical tab, and Chrome console errors were empty. The already-green Playwright alias test remains the Back/Forward proof because direct Chrome `goto` uses replace semantics.
- HR marker: `CODEX-LIVE-HR-GAPS-20260713T0400-LIVE`. Demo Admin exercised the canonical production UI for audit expansion/System fallback; team Calendar create, archive, restore and retained attendee/reminder/attachment evidence; salary-band historical placement, deactivate and reactivate; exit-interview recording, automatic completion, reopen and cancel/resume; offer expiry with required reason, invalid old portal token, safe resend/token rotation; scorecard-quorum override with required audited reason; generic-chain visibility plus native leave add/edit/down/up/deactivate/reactivate/remove; locked payroll, manager desktop detail, `390x844` mobile card, and worker self-service payslip detail/download controls.
- HR regression controls: Training Catalog search, Feedback Pending, Supervision search, the real 30-second Timekeeping refresh with `?tab=overview` and selected Overview preserved, Shifts Open/Calendar, and the optional shift licence-class/endorsement controls were all rechecked in production Chrome. No shift was saved for the licence-control proof.
- Chrome evidence: every grouped acceptance row reported an empty console-error list. Nginx access logs for the Chrome source during the 16:00 UTC acceptance hour contained **0** responses with status `>=400`.
- Server invariants before cleanup: Calendar event `1` was active with attendee/reminder/attachment counts `1/1/1`; salary band `9` was active; offboarding checklist `1` was cancelled after completion/reopen proof with one exit interview; offer `2` had a rotated non-null token and cleared expiry attribution; candidate `8` was `reference_check` with the override audit present; leave routes `1/2` were active at levels `1/2` with `36h/48h`; payroll run `3` was locked and payslip `1` had net pay `$1,814.00`; mail transport resolved to non-delivering `log`.
- Cleanup: the canonical exact-marker helper removed the synthetic graph and private attachment. A strict audit found two create/delete audit rows for an already-deleted synthetic leave route; exactly those two fixture rows were removed, restoring the pre-fixture audit count. The helper now captures this deleted-route audit shape before deleting fixture users, with a regression that passed in both configured Chromium projects (**2/2 in 34.4s**). The run's unrelated global setup warning remains the pre-existing duplicate `EMP0003` seeder issue; it did not fail the targeted cleanup tests.
- Final production baseline restored: users `57`, HR profiles `26`, audit logs `11,314`, Calendar events/attendees/reminders/attachments `0/0/0/0`, salary bands `8`, offboarding checklists/tasks/exit interviews `0/0/0`, candidates/applications/offers `6/5/1`, generic approval chains/steps `2/2`, payroll runs/payslips `1/0`; all exact marker counts were `0` and `hr/calendar/1/CODEX-LIVE-HR-GAPS-20260713T0400-LIVE.txt` was absent.
- Scheduled interval: `AutoEscalateControlRoomQueues` remained registered at `*/10`. From final application deployment at 15:53 UTC through 16:49 UTC, multiple normal intervals elapsed with **0** production error/critical/alert/emergency rows and **0** matches for the prior `automationService`, relation, or `shift.user` failure signatures.

## Final acceptance status

- Client browser row: Verified complete for this release — post-deployment desktop Chrome acceptance captured on the canonical Client Profile surface with empty console and failed-request evidence.
- HR L1: Verified complete — the exact fixture-backed production Chrome matrix, server invariants, strict marker cleanup, and cleanup regression are recorded above.
- Control Room scheduled interval: Verified complete — the deployed `*/10` job was observed across multiple normal intervals with no matching production failure.
