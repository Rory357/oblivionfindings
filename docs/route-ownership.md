# Route Ownership

This app keeps route ownership narrow so each module has a clear operational surface.

## Operations

Operations is the canonical surface for scheduler and admin workflows:

- shifts and shift series
- rostering board, roster templates, suggestions, publishing, conflicts, coverage gaps, and reservations
- availability planner
- timesheets, review, approvals, payroll adjustments, and payroll exports
- job board, qualification matching, and EVV

Scheduler entry points under `/operations/*` use `role_scope:my-day` where frontline users should be redirected to `/my-day` instead of seeing a manager-oriented screen.

## HR

HR owns the employment lifecycle and staff self-service:

- people profiles, recruitment, onboarding, and offboarding
- leave, performance, supervision, PIPs, goals, compensation, benefits, and expenses
- policies and attestations
- compliance matrix, compliance calendar, vetting register, and driver eligibility
- payroll exports, payslips, HR documents, analytics, departments, positions, and org chart
- training catalog, enrolments, and certificates

Self-service routes live under `/hr/my/*`.

## Staff

Staff is a thin person-record surface used by Operations and HR:

- staff list and profile
- staff edits
- client assignments
- credentials and registrations attached to a person
- availability patterns

Staff does not own rostering, training catalogs, or compliance dashboards.

## Permission Aliases

Timesheet permissions are canonical under `timesheets.*`. Legacy `hr.time.*` keys are policy-layer aliases only.

Vetting permissions are canonical under `hr.vetting.*`. Legacy `vetting.*` keys are policy-layer aliases only.

## Training

Training keeps only the distinct specialist surfaces and retired URL redirects:

- `competency/frameworks/*` through `Training\CompetencyFrameworkController`
- `staff/{user}/induction/*` through `Training\StaffInductionController`
- `staff/background-checks/*` through `Staff\StaffBackgroundCheckController`, which redirects to HR vetting
- `training/courses/*` as unnamed redirects to `hr.training.*`

New training catalog and enrolment work belongs under HR.

## Attendance

Attendance remains frontline clock-in, clock-out, breaks, and handover under `routes/shifts.php`.

The canonical attendance write endpoints are:

- `POST /attendance/clock-in`
- `POST /attendance/clock-out`
- `POST /attendance/break/start`
- `POST /attendance/break/end`
- `POST /attendance/handover`

Do not move these into HR or Operations; `/my-day` depends on them as the frontline workflow.
