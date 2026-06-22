# Employee Intake Unification — Audit & Design

Audit of the two employee-creation paths and the design for unifying them on one
`EmployeeIntakeService`. (Phase 2 of the `/hr/people` redesign — see
`docs/people-redesign/PROGRESS.md`.)

## The two paths (today)

- **Path A — manual:** `POST /hr/people` → `EmployeeProfileController@store`
  (`app/Http/Controllers/Hr/EmployeeProfileController.php`), validated by
  `app/Http/Requests/Hr/StoreEmployeeRequest.php`. Gate: `hr.employees.manage`.
- **Path B — recruitment:** `RecruitmentService::convertToEmployee`
  (`app/Domain/Hr/Services/RecruitmentService.php`), called from
  `CandidateController@respondOffer` (on accept) and `@convertToEmployee`.
  Gate: `hr.recruitment.manage`.

## Findings (file:line evidence in the agent audit; summary here)

| Aspect | `store()` (manual) | `convertToEmployee()` |
|---|---|---|
| User | `User::create` — **hard-blocks** on duplicate email (`unique:users,email`) | `User::firstOrCreate` by email — **reuses/links** |
| Profile | `HrEmployeeProfile::create` (no dedupe) | `updateOrCreate(['user_id'=>…])` (idempotent) |
| Onboarding checklist | ❌ none | ✅ `maybeGenerateOnboardingChecklist` |
| Candidate doc transfer | ❌ | ✅ |
| offer_id / candidate_id link | ❌ not set | ✅ set |
| Invite / first login | ❌ nothing (random pw, no email) | ✅ `Password::sendResetLink` |
| Domain event | ❌ none | `recruitment.offer.converted` (in controller) |
| RTW/visa + emergency contact | ❌ not captured/persisted | ❌ not from offer |
| employee_number format | `EMP-00001` | `EMP00001` (config prefix, no hyphen) — **inconsistent** |
| tenant | hardcoded `?? 1` | `$candidate->tenant_id` / `resolveHrTenantIdForUser` |

Key structural facts:
- `hr_employee_profiles.user_id` is **UNIQUE** (`2026_02_12_100002_…:14`) → a single
  `updateOrCreate(['user_id'=>…])` is the safe unifying primitive (no duplicate profiles possible).
- RTW/visa columns (`work_rights_status`, `visa_type`, `visa_expires_at`) + `emergency_contacts`
  (JSON) **already exist** on the table + model fillable — just never captured by the create flow.
- The current `add-employee-dialog.tsx` is **3 steps** (Person / Job / Review) — no RTW or
  emergency-contact step (the prototype's steps 3–4 are unbuilt).
- `StaffInviteNotification` exists but is **never dispatched**; its `acceptUrl` has no route. The
  proven first-login mechanism is the Laravel password-reset link (what convert already uses).

## Design — one `EmployeeIntakeService`

`app/Domain/Hr/Services/EmployeeIntakeService.php` — the **single writer** of `User` +
`HrEmployeeProfile`. One method both doors call:

```
intake(name, email, roleName, profileAttributes[], actorId, tenantId,
       startOnboarding = true, sendInvite = false, source = 'manual'): HrEmployeeProfile
```

It: (1) `firstOrCreate` the user by email (links candidate-created accounts, back-fills
role/approval); (2) sync the role pivot; (3) `updateOrCreate` the profile by `user_id`
(employee_number only on first create — fixes the convert re-run overwrite); (4) optionally
generate onboarding (idempotent, missing-template non-fatal); (5) optionally send the one invite
(reset link); (6) publish a consistent `employee.created` webhook with `source`.

**Callers:**
- `store()` builds `profileAttributes` from the request (incl. RTW/visa/emergency), resolves tenant
  via `resolveHrTenantIdForUser`, and calls `intake(source:'manual')`. A **dedupe gate**: if the
  email already belongs to a staff member *with a profile*, it returns a "link to existing record"
  validation error unless `link_existing` is set (the modal's "Link record" callout).
- `convertToEmployee()` keeps its offer/candidate guards + candidate lifecycle (advanceStage,
  application→hired) + document transfer, but delegates the User+profile write to
  `intake(source:'recruitment', startOnboarding:true, sendInvite:true)` (resolved via the container
  so `RecruitmentService`'s constructor is untouched).

## Decisions

1. **One writer:** all User+profile writes go through `EmployeeIntakeService::intake`. Manual and
   recruitment are two doors into it. (Convert keeps recruitment-only steps around the core call.)
2. **Dedupe = link, not error:** drop `unique:users,email` from `StoreEmployeeRequest`; link to an
   existing user; require explicit `link_existing` confirmation only when they already have a profile.
3. **Onboarding parity:** manual gets the checklist via the same idempotent path, behind a
   `start_onboarding` toggle (default **on**).
4. **One invite path:** the password-reset link, behind a `send_invite` toggle (default **off** for
   manual quick-add; **on** for recruitment convert — preserves current behaviour).
5. **Persist the full wizard:** extend `StoreEmployeeRequest` + the service for `work_rights_status`,
   `visa_type`, `visa_expires_at`, `emergency_contacts` (all nullable → quick-add still works).
6. **Consistent event:** new `employee.created` webhook from the service for both sources (closes the
   manual-path silence). Recruitment keeps its existing `recruitment.offer.converted` too.
7. **Permissions unchanged:** manual stays `hr.employees.manage`; recruitment stays
   `hr.recruitment.manage` (the service itself is gate-agnostic; callers enforce).

## Build order
1. ✅ Audit (this doc).
2. `EmployeeIntakeService` + `employee.created` webhook key.
3. Refactor `store()` (+ dedupe gate) and extend `StoreEmployeeRequest`.
4. Refactor `convertToEmployee` onto the service.
5. Tests (`EmployeeIntakeServiceTest` — run post-merge vs a throwaway DB).
6. Frontend: Add-Employee → WizardShell with RTW + emergency steps + onboarding/invite toggles +
   dedupe "Link record" callout (Phase 2b).
