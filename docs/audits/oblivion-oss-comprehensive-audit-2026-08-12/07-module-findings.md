# Module and capability findings

This document is the human-readable companion to `findings.json`. The retained finding set is **21 P0, 67 P1 and 12 P2**. The current versioned register is **904 capabilities (790 human, 111 download/API and three machine-ingress)**; findings remain linked to literal current IDs, but linkage does not prove runtime remediation.

The “Benchmark disposition” column is historical context only. Current target-specific reconciliation credits 500 targets (411 verified benchmark and 89 documented No Credible Match); 404 remain completion-unproved.

## Current 904-register additions

- **`AUTH-EMAIL-VERIFY-CONTRACT-01` — P1, source-observed.** `App\Models\User` inherits verification methods but does not implement `MustVerifyEmail`, while the standard registration listener and `verified` middleware both condition their behavior on that contract. Direct Fortify prompt/resend/verify routes remain source-reachable. Anchors: `app/Models/User.php:17-26`; `routes/web.php:110-128`; `vendor/laravel/framework/src/Illuminate/Auth/Middleware/EnsureEmailIsVerified.php:31-41`; `vendor/laravel/framework/src/Illuminate/Auth/Listeners/SendEmailVerificationNotification.php:16-20`; `vendor/laravel/fortify/routes/routes.php:82-97`. Runtime notification and access outcomes were not executed. Current target: `CAP-AUTH-EMAIL-VERIFICATION-LIFECYCLE`.
- **`HR-COMPLIANCE-EXPORT-PERMISSION-01` — P1, source-observed.** `ROUTE-1364` requires outer `hr.compliance.view`, then the vetting and driver branches additionally require their dataset-specific permissions; their independently admitted pages render unconditional Export controls. A specific-only viewer therefore has a source-predicted visible-but-denied action, but deployed role bundles and the 403 were not executed. Anchors: `routes/hr.php:281-288,328-343,350-360`; `app/Http/Controllers/Hr/ComplianceExportController.php:22-58`; `resources/js/pages/hr/vetting/index.tsx:135-145`; `resources/js/pages/hr/drivers/index.tsx:137-147`. Current targets: `HR-COMPLIANCE-EXPORT`, `CAP-HR-VETTING-CHECK-REGISTER-EXPORT`, `CAP-FLEET-DRIVER-ELIGIBILITY-REGISTER-EXPORT`.
- **`HR-COMPLIANCE-RENEWALS-DISCLOSURE-01` — P1, source-observed with bounded inference.** The inactive `dataset=renewals` selector defaults to `hr.compliance.view` but streams both staff-compliance and driver-eligibility renewal fields; the explicit driver branch requires `hr.driver.view`. No active client activator or branch-specific test was found, and no response was executed. Anchors: `app/Http/Controllers/Hr/ComplianceExportController.php:22-58,133-157`; `tests/Feature/Hr/ComplianceHubActionsTest.php:186-198`. The selector remains an excluded non-denominator branch; accepted emitted-data owners are linked for accountability only.
- **`CTRL-SIGNAL-002` — P1, source-observed with bounded concurrency inference.** The scheduled `ProcessControlRoomSignals` job consumes pending `Signal` rows through `SignalProcessingService`, applies suppression/rules/deduplication, creates a `ControlRoomAlert` and marks the signal processed. Concurrent safety and crash-recovery behavior remains unexecuted. Anchors: `app/Jobs/ProcessControlRoomSignals.php:13-31`; `app/Services/ControlRoom/SignalProcessingService.php:120-156,191-246`; `database/migrations/2026_02_04_000100_create_control_room_signal_system.php:99-132`; `routes/console.php:186`. Current target: `CAP-CR-SIGNAL-TO-ALERT-PIPELINE`.
- **`VIS-SYSTEM-USERS-COUNT-01` — P2, browser-observed with source-supported mechanism.** Filtering System Users to Clinical Lead produced impossible negative summary counts. The shared count-up animation caps progress at one but not zero, permitting a negative eased multiplier when the callback timestamp precedes the captured start time. Exact timing remains unproved. Anchors: `resources/js/pages/settings/users/index.tsx:145-180,245-303`; `resources/js/components/ops-stat-card.tsx:45-67`; `resources/js/components/page/stat-tile.tsx:113-136,169-209`. Current target: `CAP-SET-USER-ACCOUNT-LIFECYCLE`.
- **`VIS-MY-DAY-HEADER-OVERFLOW-01` — P2, browser-observed.** At `390x844`, the signed-in My Day header overflowed horizontally because its right-side `StaffHeader` action group is `shrink-0` and has no narrow-width wrap/collapse policy. The finding is bounded to Demo Administrator on My Day. Anchors: `evidence/browser/BVIS-0010-my-day-390x844-header-overflow-cropped.png`; `evidence/browser/BVIS-0010-my-day-390x844-header-overflow-cropped.json`; `resources/js/components/staff-header.tsx:154-172`. Current target: `CAP-DAY-MY-DAY-WORKSPACE`.
- **`HR-STAFF-CREATION-PATH-01` — P1, browser-observed with source contradiction.** Both supported staff-creation entry points were blocked: HR People supplied no usable site options while requiring Primary site, and deployed System Users suppressed its source-present Staff branch and redirected to that blocked flow. The deployment/build or payload cause remains unproved. Anchors: `evidence/browser/BVIS-0011-system-users-staff-path-blocked.png`; `evidence/source/browser-clinical-lead-account-creation-attempt.json`; `resources/js/components/hr/add-employee-dialog.tsx:128-162,396-412`; `resources/js/pages/system/users/Create.tsx:57-98,124-209,272-290`. Current targets: `CAP-HR-EMPLOYEE-PROFILE-LIFECYCLE`, `CAP-SET-USER-ACCOUNT-LIFECYCLE`.
- **`TASK-SHIFT-RELATION-500-01` — P0, browser/server/source-observed at the audited snapshot; historical remediation evidence recorded separately.** Authenticated shared navigation failed because `ShiftTaskProvider` referenced a non-existent `Shift.user` relationship while the model exposes the worker through `Shift.staff`; the provider runs in globally shared Inertia middleware. Anchors: `evidence/browser/BVIS-0012-authenticated-shift-task-provider-500.json`; `app/Services/Tasks/Providers/ShiftTaskProvider.php:41-46,72-74`; `app/Models/Shift.php:145-153`; `app/Http/Middleware/HandleInertiaRequests.php:81-90`. Current target: `CAP-DAY-ALL-TASKS-WORKBENCH`. The evidence remains part of the immutable baseline; the remediation register's historical merged/verified status is not treated as a fresh current-`origin/main` proof.

## Public and marketing

Capabilities: 7; human: 7; retained exact finding links: none.

| Capability | Legacy family | Submodule | Routes/pages | Benchmark disposition | Browser/source status | Findings |
|---|---|---|---:|---|---|---|
| `PUB-CONTACT` | `PUB-CONTACT` | Contact | 2/1 | No credible match | Observed entry; task blocked | — |
| `PUB-ROUTE-ABOUT` | `PUB-ROUTE-ABOUT` | About | 1/1 | No credible match | Observed entry; task blocked | — |
| `PUB-ROUTE-FEATURES` | `PUB-ROUTE-FEATURES` | Features | 1/1 | No credible match | Observed entry; task blocked | — |
| `PUB-ROUTE-HOME` | `PUB-ROUTE-HOME` | Home | 1/1 | No credible match | Observed entry; task blocked | — |
| `PUB-ROUTE-PRICING` | `PUB-ROUTE-PRICING` | Pricing | 1/1 | No credible match | Observed entry; task blocked | — |
| `PUB-ROUTE-SMART-MONITORING` | `PUB-ROUTE-SMART-MONITORING` | Smart Monitoring | 1/1 | No credible match | Observed entry; task blocked | — |
| `PUB-ROUTE-TERMS` | `PUB-ROUTE-TERMS` | Terms | 1/1 | No credible match | Observed entry; task blocked | — |

## Authentication and account security

Capabilities: 9; human: 8; retained exact finding links: none.

| Capability | Legacy family | Submodule | Routes/pages | Benchmark disposition | Browser/source status | Findings |
|---|---|---|---:|---|---|---|
| `AUTH-AUTHENTICATED-SESSION` | `AUTH-AUTHENTICATED-SESSION` | Authenticated Session | 3/1 | Keycloak | Source/blocked | — |
| `AUTH-GOOGLE` | `AUTH-GOOGLE` | Google | 2/0 | Keycloak | Source/blocked | — |
| `AUTH-IDENTITY-DISCONNECT` | `AUTH-IDENTITY-DISCONNECT` | Identity Disconnect | 1/0 | Keycloak | Source/blocked | — |
| `AUTH-MICROSOFT` | `AUTH-MICROSOFT` | Microsoft | 2/0 | Keycloak | Source/blocked | — |
| `AUTH-NEW-PASSWORD` | `AUTH-NEW-PASSWORD` | New Password | 2/1 | Keycloak | Source/blocked | — |
| `AUTH-PASSWORD-RESET-LINK` | `AUTH-PASSWORD-RESET-LINK` | Password Reset Link | 2/1 | Keycloak | Source/blocked | — |
| `AUTH-REGISTERED-USER` | `AUTH-REGISTERED-USER` | Registered User | 2/1 | Keycloak | Source/blocked | — |
| `AUTH-TWO-FACTOR-AUTHENTICATED-SESSION` | `AUTH-TWO-FACTOR-AUTHENTICATED-SESSION` | Two Factor Authenticated Session | 2/1 | Keycloak | Source/blocked | — |
| `AUTH-USER` | `AUTH-USER` | User | 3/0 | Keycloak | Non-human/unsafe | — |

## Frontline and My Day

Capabilities: 8; human: 7; retained exact finding links: `TASK-RBAC-001`, `TASK-WATCH-002`.

| Capability | Legacy family | Submodule | Routes/pages | Benchmark disposition | Browser/source status | Findings |
|---|---|---|---:|---|---|---|
| `DAY-ALL-TASKS` | `DAY-ALL-TASKS` | All Tasks | 9/4 | CommCare Android | Observed entry; task blocked | TASK-RBAC-001, TASK-WATCH-002 |
| `DAY-ANNOUNCEMENT-INBOX` | `DAY-ANNOUNCEMENT-INBOX` | Announcement Inbox | 2/0 | CommCare Android | Source/blocked | — |
| `DAY-LEGACY-REDIRECTS` | `DAY-LEGACY-REDIRECTS` | Legacy redirects | 1/0 | No credible match | Non-human/unsafe | — |
| `DAY-MY-CALENDAR` | `DAY-MY-CALENDAR` | My Calendar | 2/1 | CommCare Android | Observed entry; task blocked | — |
| `DAY-MY-TASKS` | `DAY-MY-TASKS` | My Tasks | 1/21 | CommCare Android | Observed entry; task blocked | — |
| `DAY-NOTIFICATION-INBOX` | `DAY-NOTIFICATION-INBOX` | Notification Inbox | 3/0 | CommCare Android | Source/blocked | — |
| `DAY-ROUTE-NOTIFICATIONS-INDEX` | `DAY-ROUTE-NOTIFICATIONS-INDEX` | Notifications Index | 1/1 | CommCare Android | Observed entry; task blocked | — |
| `DAY-TODAY-DASHBOARD` | `DAY-TODAY-DASHBOARD` | Today Dashboard | 1/1 | CommCare Android | Observed entry; task blocked | — |

## Operations and rostering

Capabilities: 79; human: 76; retained exact finding links: `WF-ATTENDANCE-FORCED-END-SITE`, `CARE-SIGNOFF-01`, `WF-TIMESHEET-CLIENT-REASSIGN`, `CONSENT-AUTH-01`, `CONSENT-CAPACITY-01`, `CONSENT-FILE-01`, `FIN-CLIENT-FUNDS-01`, `FUND-BIND-01`, `WF-ELIG-FAIL-OPEN`, `WF-AVAILABILITY-LIFECYCLE`, `WF-FATIGUE-TIMEZONE`.

| Capability | Legacy family | Submodule | Routes/pages | Benchmark disposition | Browser/source status | Findings |
|---|---|---|---:|---|---|---|
| `CAP-OPS-ATTENDANCE-BREAKS` | `OPS-ATTENDANCE` | Attendance break start and end | 2/5 | Pending capability-level adjudication | Source/blocked | WF-ATTENDANCE-FORCED-END-SITE |
| `CAP-OPS-ATTENDANCE-CLOCK-SESSION` | `OPS-ATTENDANCE` | Attendance clock and session correction lifecycle | 5/5 | Pending capability-level adjudication | Observed entry; task blocked | WF-ATTENDANCE-FORCED-END-SITE |
| `CAP-OPS-ATTENDANCE-HANDOVER` | `OPS-ATTENDANCE` | Attendance handover submission and acknowledgement | 2/5 | Pending capability-level adjudication | Source/blocked | WF-ATTENDANCE-FORCED-END-SITE |
| `CAP-OPS-CARE-PLAN-AUTHORING` | `OPS-CARE-PLAN` | Care plan authoring and export | 8/4 | Pending capability-level adjudication | Observed entry; task blocked | CARE-SIGNOFF-01 |
| `CAP-OPS-CARE-PLAN-GOAL-GOAL-STEPS` | `OPS-CARE-PLAN-GOAL` | Care-plan goals steps and progress | 8/0 | Pending capability-level adjudication | Source/blocked | — |
| `CAP-OPS-CARE-PLAN-GOAL-HURDLES` | `OPS-CARE-PLAN-GOAL` | Care-plan goal hurdles and resolution | 2/0 | Pending capability-level adjudication | Source/blocked | — |
| `CAP-OPS-CARE-PLAN-REVIEW-CYCLE` | `OPS-CARE-PLAN` | Care plan review start and completion | 2/4 | Pending capability-level adjudication | Source/blocked | CARE-SIGNOFF-01 |
| `CAP-OPS-CARE-PLAN-SIGNOFF` | `OPS-CARE-PLAN` | Care plan sign-off management | 2/4 | Pending capability-level adjudication | Source/blocked | CARE-SIGNOFF-01 |
| `CAP-OPS-CLIENT-LOCATION-PANIC` | `OPS-CLIENT` | Client location history locate-now and panic response | 3/1 | Pending capability-level adjudication | Observed entry; task blocked | — |
| `CAP-OPS-CLIENT-PHOTOS-GALLERY` | `OPS-CLIENT` | Client photos and gallery | 6/35 | Pending capability-level adjudication | Source/blocked | — |
| `CAP-OPS-CLIENT-RECORD-LIFECYCLE` | `OPS-CLIENT` | Client profile creation update archive and restore | 15/1 | Pending capability-level adjudication | Observed entry; task blocked | — |
| `CAP-OPS-SERVICE-AGREEMENT-DESIGN-RATES` | `OPS-SERVICE-AGREEMENT` | Service agreement terms rates and line items | 9/4 | Pending capability-level adjudication | Observed entry; task blocked | — |
| `CAP-OPS-SERVICE-AGREEMENT-STATE-CLOSURE` | `OPS-SERVICE-AGREEMENT` | Service agreement state transition and deletion | 4/4 | Pending capability-level adjudication | Source/blocked | — |
| `CAP-OPS-SERVICE-AGREEMENT-SUBMISSION-DECISION` | `OPS-SERVICE-AGREEMENT` | Service agreement submission approval and rejection | 3/4 | Pending capability-level adjudication | Source/blocked | — |
| `CAP-OPS-SHIFT-EXECUTION-RECOVERY` | `OPS-SHIFT` | Shift start completion occurrence cancellation and reopen | 4/20 | Pending capability-level adjudication | Source/blocked | — |
| `CAP-OPS-SHIFT-PLANNING-PUBLISH` | `OPS-SHIFT` | Shift planning series duplication and publication | 10/20 | Pending capability-level adjudication | Observed entry; task blocked | — |
| `CAP-OPS-SHIFT-STAFFING-COVER` | `OPS-SHIFT` | Shift assignment candidates auto-fill cover and replacement | 7/20 | Pending capability-level adjudication | Observed entry; task blocked | — |
| `CAP-OPS-TIMESHEET-ARCHIVE-PAYROLL` | `OPS-TIMESHEET` | Timesheet archive restore and payroll adjustment handoff | 4/3 | Pending capability-level adjudication | Observed entry; task blocked | WF-TIMESHEET-CLIENT-REASSIGN |
| `CAP-OPS-TIMESHEET-AUTHOR-SUBMIT` | `OPS-TIMESHEET` | Timesheet authoring submission and resubmission | 7/3 | Pending capability-level adjudication | Observed entry; task blocked | WF-TIMESHEET-CLIENT-REASSIGN |
| `CAP-OPS-TIMESHEET-MANAGER-REVIEW` | `OPS-TIMESHEET` | Timesheet manager decisions and bulk review | 6/3 | Pending capability-level adjudication | Source/blocked | WF-TIMESHEET-CLIENT-REASSIGN |
| `OPS-ACTIVITY-FEED` | `OPS-ACTIVITY-FEED` | Activity Feed | 1/1 | No credible match | Observed entry; task blocked | — |
| `OPS-CALENDAR` | `OPS-CALENDAR` | Calendar | 3/0 | No credible match | Source/blocked | — |
| `OPS-CALENDAR-SYNC` | `OPS-CALENDAR-SYNC` | Calendar Sync | 5/2 | No credible match | Source/blocked | — |
| `OPS-CARE-NOTE-TEMPLATE` | `OPS-CARE-NOTE-TEMPLATE` | Care Note Template | 6/3 | Bahmni IPD frontend | Observed entry; task blocked | — |
| `OPS-CLIENT-ACTIONS` | `OPS-CLIENT-ACTIONS` | Client Actions | 1/0 | No credible match | Source/blocked | — |
| `OPS-CLIENT-CONSENT` | `OPS-CLIENT-CONSENT` | Client Consent | 3/1 | OHC CARE frontend | Source/blocked | CONSENT-AUTH-01, CONSENT-CAPACITY-01, CONSENT-FILE-01 |
| `OPS-CLIENT-DAILY-NOTE` | `OPS-CLIENT-DAILY-NOTE` | Client Daily Note | 7/0 | Bahmni IPD frontend | Source/blocked | — |
| `OPS-CLIENT-FAMILY-CHAT` | `OPS-CLIENT-FAMILY-CHAT` | Client Family Chat | 2/0 | No credible match | Source/blocked | — |
| `OPS-CLIENT-FUND` | `OPS-CLIENT-FUND` | Client Fund | 6/3 | No credible match | Observed entry; task blocked | FIN-CLIENT-FUNDS-01 |
| `OPS-CLIENT-LEAVE-EXCURSION` | `OPS-CLIENT-LEAVE-EXCURSION` | Client Leave Excursion | 6/0 | No credible match | Source/blocked | — |
| `OPS-CLIENT-MEAL-LOG` | `OPS-CLIENT-MEAL-LOG` | Client Meal Log | 3/0 | No credible match | Source/blocked | — |
| `OPS-CLIENT-ONBOARDING-WORKFLOW` | `OPS-CLIENT-ONBOARDING-WORKFLOW` | Client Onboarding Workflow | 8/3 | No credible match | Observed entry; task blocked | — |
| `OPS-CLIENT-PATH-PLAN` | `OPS-CLIENT-PATH-PLAN` | Client Path Plan | 2/0 | No credible match | Source/blocked | — |
| `OPS-CLIENT-PERSONAL-ASSET` | `OPS-CLIENT-PERSONAL-ASSET` | Client Personal Asset | 4/0 | No credible match | Source/blocked | — |
| `OPS-CLIENT-PHOTO-MEDIA` | `OPS-CLIENT-PHOTO-MEDIA` | Client Photo Media | 4/0 | No credible match | Source/blocked | — |
| `OPS-CLIENT-ROUTINE` | `OPS-CLIENT-ROUTINE` | Client Routine | 3/0 | No credible match | Source/blocked | — |
| `OPS-CLIENT-TRANSPORT-BOOKING` | `OPS-CLIENT-TRANSPORT-BOOKING` | Client Transport Booking | 3/0 | No credible match | Source/blocked | — |
| `OPS-CLIENT-VISIT-REQUEST` | `OPS-CLIENT-VISIT-REQUEST` | Client Visit Request | 3/1 | No credible match | Source/blocked | — |
| `OPS-CONSENT-REQUEST` | `OPS-CONSENT-REQUEST` | Consent Request | 5/3 | OHC CARE frontend | Source/blocked | CONSENT-AUTH-01, CONSENT-CAPACITY-01 |
| `OPS-COVERAGE-GAP` | `OPS-COVERAGE-GAP` | Coverage Gap | 3/0 | Timefold Solver Community | Source/blocked | — |
| `OPS-COVERAGE-RESERVATION` | `OPS-COVERAGE-RESERVATION` | Coverage Reservation | 1/0 | Timefold Solver Community | Source/blocked | — |
| `OPS-CUSTOM-FORM` | `OPS-CUSTOM-FORM` | Custom Form | 8/5 | No credible match | Observed entry; task blocked | — |
| `OPS-DASHBOARD` | `OPS-DASHBOARD` | Dashboard | 1/1 | No credible match | Observed entry; task blocked | — |
| `OPS-EVV` | `OPS-EVV` | Evv | 5/2 | No credible match | Observed entry; task blocked | — |
| `OPS-FAMILY-PORTAL` | `OPS-FAMILY-PORTAL` | Family Portal | 4/3 | No credible match | Observed entry; task blocked | — |
| `OPS-FRONTEND-OPERATIONS-CLIENTS-MEDICAL` | `OPS-FRONTEND-OPERATIONS-CLIENTS-MEDICAL` | Operations/Clients/Medical | 0/1 | No credible match | Non-human/unsafe | — |
| `OPS-FUNDING` | `OPS-FUNDING` | Funding | 1/1 | No credible match | Observed entry; task blocked | FUND-BIND-01 |
| `OPS-FUNDING-CLAIM` | `OPS-FUNDING-CLAIM` | Funding Claim | 6/3 | No credible match | Observed entry; task blocked | FUND-BIND-01 |
| `OPS-GEOFENCE` | `OPS-GEOFENCE` | Geofence | 5/2 | No credible match | Observed entry; task blocked | — |
| `OPS-HANDOVER` | `OPS-HANDOVER` | Handover | 7/15 | Bahmni IPD frontend | Observed entry; task blocked | — |
| `OPS-JOB-BOARD` | `OPS-JOB-BOARD` | Job Board | 5/1 | No credible match | Observed entry; task blocked | WF-ELIG-FAIL-OPEN |
| `OPS-LEGACY-REDIRECTS` | `OPS-LEGACY-REDIRECTS` | Legacy redirects | 2/0 | No credible match | Non-human/unsafe | — |
| `OPS-LEGACY-ROUTE-REDIRECT` | `OPS-LEGACY-ROUTE-REDIRECT` | Legacy Route Redirect | 41/0 | No credible match | Non-human/unsafe | — |
| `OPS-MESSAGE` | `OPS-MESSAGE` | Message | 9/1 | No credible match | Observed entry; task blocked | — |
| `OPS-MILEAGE-CLAIM` | `OPS-MILEAGE-CLAIM` | Mileage Claim | 5/2 | No credible match | Observed entry; task blocked | — |
| `OPS-MY-DAY-ACTIONS` | `OPS-MY-DAY-ACTIONS` | My Day Actions | 5/0 | No credible match | Source/blocked | — |
| `OPS-OPS-NOTIFICATION` | `OPS-OPS-NOTIFICATION` | Ops Notification | 3/1 | No credible match | Observed entry; task blocked | — |
| `OPS-PROGRESS-NOTE` | `OPS-PROGRESS-NOTE` | Progress Note | 3/0 | Bahmni IPD frontend | Source/blocked | — |
| `OPS-QUALIFICATION-MATCH` | `OPS-QUALIFICATION-MATCH` | Qualification Match | 5/2 | No credible match | Observed entry; task blocked | — |
| `OPS-REPORT` | `OPS-REPORT` | Report | 2/2 | No credible match | Observed entry; task blocked | — |
| `OPS-RESPITE-HANDOVER-NOTE` | `OPS-RESPITE-HANDOVER-NOTE` | Respite Handover Note | 8/5 | Bahmni IPD frontend | Observed entry; task blocked | — |
| `OPS-REVIEW-QUEUE` | `OPS-REVIEW-QUEUE` | Review Queue | 1/1 | No credible match | Observed entry; task blocked | — |
| `OPS-ROSTER` | `OPS-ROSTER` | Roster | 2/1 | Timefold Solver Community | Observed entry; task blocked | WF-ELIG-FAIL-OPEN, WF-AVAILABILITY-LIFECYCLE, WF-FATIGUE-TIMEZONE |
| `OPS-ROSTER-SUGGESTION` | `OPS-ROSTER-SUGGESTION` | Roster Suggestion | 5/1 | Timefold Solver Community | Source/blocked | — |
| `OPS-ROSTER-TEMPLATE` | `OPS-ROSTER-TEMPLATE` | Roster Template | 5/0 | Timefold Solver Community | Source/blocked | — |
| `OPS-ROSTERING` | `OPS-ROSTERING` | Rostering | 9/4 | Timefold Solver Community | Observed entry; task blocked | WF-FATIGUE-TIMEZONE |
| `OPS-ROUTE-CLIENT-CALENDAR` | `OPS-ROUTE-CLIENT-CALENDAR` | Client Calendar | 1/1 | No credible match | Source/blocked | — |
| `OPS-ROUTE-OPERATIONS-AVAILABILITY-INDEX` | `OPS-ROUTE-OPERATIONS-AVAILABILITY-INDEX` | Operations Availability Index | 1/0 | Timefold Solver Community | Source/blocked | — |
| `OPS-ROUTE-OPERATIONS-PROGRESS-NOTES-INDEX` | `OPS-ROUTE-OPERATIONS-PROGRESS-NOTES-INDEX` | Operations Progress Notes Index | 1/0 | Bahmni IPD frontend | Source/blocked | — |
| `OPS-ROUTE-OPERATIONS-ROSTERING-TEMPLATES-INDEX` | `OPS-ROUTE-OPERATIONS-ROSTERING-TEMPLATES-INDEX` | Operations Rostering Templates Index | 1/0 | Timefold Solver Community | Source/blocked | — |
| `OPS-ROUTE-OPERATIONS-TIMESHEETS-APPROVALS` | `OPS-ROUTE-OPERATIONS-TIMESHEETS-APPROVALS` | Operations Timesheets Approvals | 1/0 | Kimai | Source/blocked | — |
| `OPS-ROUTE-OPERATIONS-TIMESHEETS-CREATE` | `OPS-ROUTE-OPERATIONS-TIMESHEETS-CREATE` | Operations Timesheets Create | 1/0 | Kimai | Source/blocked | — |
| `OPS-SHIFT-INCIDENT` | `OPS-SHIFT-INCIDENT` | Shift Incident | 1/0 | No credible match | Source/blocked | — |
| `OPS-SHIFT-NOTE` | `OPS-SHIFT-NOTE` | Shift Note | 6/9 | No credible match | Observed entry; task blocked | — |
| `OPS-SHIFT-REPORT` | `OPS-SHIFT-REPORT` | Shift Report | 2/1 | No credible match | Observed entry; task blocked | — |
| `OPS-SHIFT-SERIES` | `OPS-SHIFT-SERIES` | Shift Series | 4/2 | Timefold Solver Community | Source/blocked | — |
| `OPS-SHIFT-TASK` | `OPS-SHIFT-TASK` | Shift Task | 1/0 | No credible match | Source/blocked | — |
| `OPS-STAFF-AVAILABILITY` | `OPS-STAFF-AVAILABILITY` | Staff Availability | 3/1 | Timefold Solver Community | Source/blocked | — |
| `OPS-STAFF-TIME-OFF` | `OPS-STAFF-TIME-OFF` | Staff Time Off | 2/0 | No credible match | Source/blocked | — |

## Clients and supported people

Capabilities: 44; human: 37; retained exact finding links: `SITE-MEAL-CLIN-01`, `CONSENT-AUTH-01`.

| Capability | Legacy family | Submodule | Routes/pages | Benchmark disposition | Browser/source status | Findings |
|---|---|---|---:|---|---|---|
| `CAP-CLI-CLIENT-DOCUMENT-PORTAL-CONSUMPTION` | `CLI-CLIENT-DOCUMENT` | Portal document consumption | 1/1 | Pending capability-level adjudication | Source/blocked | — |
| `CAP-CLI-CLIENT-DOCUMENT-STAFF-LIBRARY` | `CLI-CLIENT-DOCUMENT` | Staff client document library | 12/1 | Pending capability-level adjudication | Source/blocked | — |
| `CLI-AUDIT-EXPORT` | `CLI-AUDIT-EXPORT` | Audit Export | 2/0 | No credible match | Source/blocked | — |
| `CLI-CLIENT-ASSESSMENT` | `CLI-CLIENT-ASSESSMENT` | Client Assessment | 6/0 | OpenMRS Form Engine | Source/blocked | — |
| `CLI-CLIENT-ASSIGNMENT` | `CLI-CLIENT-ASSIGNMENT` | Client Assignment | 4/1 | No credible match | Source/blocked | — |
| `CLI-CLIENT-CALENDAR` | `CLI-CLIENT-CALENDAR` | Client Calendar | 4/0 | No credible match | Source/blocked | — |
| `CLI-CLIENT-FINANCIALS` | `CLI-CLIENT-FINANCIALS` | Client Financials | 1/1 | No credible match | Source/blocked | — |
| `CLI-CLIENT-INCIDENT` | `CLI-CLIENT-INCIDENT` | Client Incident | 8/1 | No credible match | Source/blocked | — |
| `CLI-CLIENT-MAR` | `CLI-CLIENT-MAR` | Client Mar | 4/0 | No credible match | Source/blocked | — |
| `CLI-CLIENT-MEAL-PREFERENCES` | `CLI-CLIENT-MEAL-PREFERENCES` | Client Meal Preferences | 4/0 | No credible match | Source/blocked | SITE-MEAL-CLIN-01 |
| `CLI-CLIENT-NOTE` | `CLI-CLIENT-NOTE` | Client Note | 4/0 | No credible match | Source/blocked | — |
| `CLI-CLIENT-ONBOARDING` | `CLI-CLIENT-ONBOARDING` | Client Onboarding | 2/0 | No credible match | Source/blocked | — |
| `CLI-CLIENT-PORTAL-USER` | `CLI-CLIENT-PORTAL-USER` | Client Portal User | 6/1 | OpenEMR portal | Source/blocked | — |
| `CLI-CLIENT-RAG` | `CLI-CLIENT-RAG` | Client Rag | 3/0 | No credible match | Source/blocked | — |
| `CLI-CLIENT-RISK` | `CLI-CLIENT-RISK` | Client Risk | 8/1 | No credible match | Source/blocked | — |
| `CLI-CLIENT-SUPPORT-PLAN` | `CLI-CLIENT-SUPPORT-PLAN` | Client Support Plan | 2/0 | No credible match | Source/blocked | — |
| `CLI-CONSENT-REQUEST-PORTAL` | `CLI-CONSENT-REQUEST-PORTAL` | Consent Request Portal | 3/1 | OHC CARE frontend | Source/blocked | CONSENT-AUTH-01 |
| `CLI-FAMILY-DASHBOARD` | `CLI-FAMILY-DASHBOARD` | Family Dashboard | 3/1 | No credible match | Source/blocked | — |
| `CLI-FAMILY-NOTE` | `CLI-FAMILY-NOTE` | Family Note | 3/0 | No credible match | Source/blocked | — |
| `CLI-FINANCIAL-INSIGHTS-API` | `CLI-FINANCIAL-INSIGHTS-API` | Financial Insights Api | 8/0 | No credible match | Source/blocked | — |
| `CLI-FRONTEND-CLIENTS` | `CLI-FRONTEND-CLIENTS` | Clients | 0/3 | No credible match | Non-human/unsafe | — |
| `CLI-FRONTEND-CLIENTS-ASSIGNMENTS` | `CLI-FRONTEND-CLIENTS-ASSIGNMENTS` | Clients/Assignments | 0/1 | No credible match | Non-human/unsafe | — |
| `CLI-FRONTEND-CLIENTS-DOCUMENTS` | `CLI-FRONTEND-CLIENTS-DOCUMENTS` | Clients/Documents | 0/1 | No credible match | Non-human/unsafe | — |
| `CLI-FRONTEND-CLIENTS-MEDICAL` | `CLI-FRONTEND-CLIENTS-MEDICAL` | Clients/Medical | 0/1 | OpenMRS O3 patient chart | Non-human/unsafe | — |
| `CLI-FRONTEND-CLIENTS-MEDICAL-SIMPLE` | `CLI-FRONTEND-CLIENTS-MEDICAL-SIMPLE` | Clients/Medical Simple | 0/1 | OpenMRS O3 patient chart | Non-human/unsafe | — |
| `CLI-FRONTEND-CLIENTS-RISKS` | `CLI-FRONTEND-CLIENTS-RISKS` | Clients/Risks | 0/1 | No credible match | Non-human/unsafe | — |
| `CLI-LEGACY-REDIRECTS` | `CLI-LEGACY-REDIRECTS` | Legacy redirects | 1/0 | No credible match | Non-human/unsafe | — |
| `CLI-PORTAL-CALENDAR` | `CLI-PORTAL-CALENDAR` | Portal Calendar | 2/1 | OpenEMR portal | Source/blocked | — |
| `CLI-PORTAL-CLIENT` | `CLI-PORTAL-CLIENT` | Portal Client | 1/1 | No credible match | Source/blocked | — |
| `CLI-PORTAL-DOCUMENT` | `CLI-PORTAL-DOCUMENT` | Portal Document | 2/1 | OpenEMR portal | Source/blocked | — |
| `CLI-PORTAL-FAMILY-NOTE` | `CLI-PORTAL-FAMILY-NOTE` | Portal Family Note | 4/1 | No credible match | Source/blocked | — |
| `CLI-PORTAL-HEALTH` | `CLI-PORTAL-HEALTH` | Portal Health | 1/1 | OpenEMR portal | Source/blocked | — |
| `CLI-PORTAL-INCIDENT-ATTACHMENT` | `CLI-PORTAL-INCIDENT-ATTACHMENT` | Portal Incident Attachment | 1/0 | No credible match | Source/blocked | — |
| `CLI-PORTAL-LOCATION` | `CLI-PORTAL-LOCATION` | Portal Location | 2/1 | No credible match | Source/blocked | — |
| `CLI-PORTAL-MESSAGE` | `CLI-PORTAL-MESSAGE` | Portal Message | 8/1 | No credible match | Source/blocked | — |
| `CLI-PORTAL-PHOTO` | `CLI-PORTAL-PHOTO` | Portal Photo | 2/1 | No credible match | Source/blocked | — |
| `CLI-PORTAL-SCHEDULE` | `CLI-PORTAL-SCHEDULE` | Portal Schedule | 1/1 | OpenEMR portal | Source/blocked | — |
| `CLI-PORTAL-TIMELINE` | `CLI-PORTAL-TIMELINE` | Portal Timeline | 1/1 | No credible match | Source/blocked | — |
| `CLI-PORTAL-TIMELINE-INTERACTION` | `CLI-PORTAL-TIMELINE-INTERACTION` | Portal Timeline Interaction | 4/0 | No credible match | Source/blocked | — |
| `CLI-RESPITE-REFERRAL` | `CLI-RESPITE-REFERRAL` | Respite Referral | 4/2 | Primero | Observed entry; task blocked | — |
| `CLI-SITE-CLIENT` | `CLI-SITE-CLIENT` | Site Client | 3/0 | No credible match | Source/blocked | — |
| `CLI-SITE-GEOCODING` | `CLI-SITE-GEOCODING` | Site Geocoding | 3/0 | No credible match | Source/blocked | — |
| `CLI-TIMELINE` | `CLI-TIMELINE` | Timeline | 4/1 | No credible match | Observed entry; task blocked | — |
| `CLI-TIMELINE-INTERACTION` | `CLI-TIMELINE-INTERACTION` | Timeline Interaction | 4/0 | No credible match | Source/blocked | — |

## eMAR and medications

Capabilities: 39; human: 36; retained exact finding links: `MED-COMP-01`, `MED-OVERRIDE-01`, `MED-SCOPE-01`, `MED-RBAC-01`, `MED-VERIFY-01`, `VIS-HERO-DENSITY-01`.

| Capability | Legacy family | Submodule | Routes/pages | Benchmark disposition | Browser/source status | Findings |
|---|---|---|---:|---|---|---|
| `CAP-MED-BREAK-GLASS-ACCESS-GRANTS` | `MED-BREAK-GLASS` | Emergency access grant and removal across client surfaces | 4/0 | Pending capability-level adjudication | Source/blocked | — |
| `CAP-MED-BREAK-GLASS-GRANT-REVIEW` | `MED-BREAK-GLASS` | Break-glass grant review extension and revocation | 3/0 | Pending capability-level adjudication | Source/blocked | — |
| `CAP-MED-BREAK-GLASS-POLICY-FLAGS` | `MED-BREAK-GLASS` | Break-glass policy and alert-flag governance | 2/0 | Pending capability-level adjudication | Source/blocked | — |
| `CAP-MED-CLIENT-MEDICAL-ADMINISTRATION-STOCK` | `MED-CLIENT-MEDICAL` | Medication administration stock and discrepancy closure | 5/0 | Pending capability-level adjudication | Source/blocked | — |
| `CAP-MED-CLIENT-MEDICAL-EMERGENCY-CONTACTS` | `MED-CLIENT-MEDICAL` | Client emergency contact management | 6/0 | Pending capability-level adjudication | Source/blocked | — |
| `CAP-MED-CLIENT-MEDICAL-MEDICATION-ORDERS` | `MED-CLIENT-MEDICAL` | Client medication order maintenance | 6/0 | Pending capability-level adjudication | Source/blocked | — |
| `CAP-MED-CLIENT-MEDICAL-PROFILE-CONDITIONS` | `MED-CLIENT-MEDICAL` | Client medical profile and condition management | 10/0 | Pending capability-level adjudication | Source/blocked | — |
| `CAP-MED-EMAR-ALERTS-ATTENTION` | `MED-EMAR` | Medication alerts attention records and suppression | 5/3 | Pending capability-level adjudication | Source/blocked | MED-COMP-01, MED-OVERRIDE-01, MED-SCOPE-01, MED-RBAC-01, MED-VERIFY-01, VIS-HERO-DENSITY-01 |
| `CAP-MED-EMAR-CLINICAL-MONITORING` | `MED-EMAR` | INR syringe-driver and medication monitoring settings | 7/3 | Pending capability-level adjudication | Observed entry; task blocked | MED-COMP-01, MED-OVERRIDE-01, MED-SCOPE-01, MED-RBAC-01, MED-VERIFY-01, VIS-HERO-DENSITY-01 |
| `CAP-MED-EMAR-COMPETENCY` | `MED-EMAR` | Medication competency records | 4/5 | Pending capability-level adjudication | Observed entry; task blocked | MED-COMP-01, MED-OVERRIDE-01, MED-SCOPE-01, MED-RBAC-01, MED-VERIFY-01, VIS-HERO-DENSITY-01 |
| `CAP-MED-EMAR-CONTROLLED-DRUGS` | `MED-EMAR` | Controlled-drug ledger balances discrepancies and destruction | 7/2 | Pending capability-level adjudication | Observed entry; task blocked | MED-COMP-01, MED-OVERRIDE-01, MED-SCOPE-01, MED-RBAC-01, MED-VERIFY-01, VIS-HERO-DENSITY-01 |
| `CAP-MED-EMAR-HANDOVERS` | `MED-EMAR` | Medication handover drafting acknowledgement and locking | 9/4 | Pending capability-level adjudication | Observed entry; task blocked | MED-COMP-01, MED-OVERRIDE-01, MED-SCOPE-01, MED-RBAC-01, MED-VERIFY-01, VIS-HERO-DENSITY-01 |
| `CAP-MED-EMAR-MEDICATION-ORDERS` | `MED-EMAR` | Medication orders verification discontinuation and import | 8/3 | Pending capability-level adjudication | Observed entry; task blocked | MED-COMP-01, MED-OVERRIDE-01, MED-SCOPE-01, MED-RBAC-01, MED-VERIFY-01, VIS-HERO-DENSITY-01 |
| `CAP-MED-EMAR-PRESCRIPTIONS-COVERT` | `MED-EMAR` | Prescription countersigning and covert-authorisation lifecycle | 7/2 | Pending capability-level adjudication | Observed entry; task blocked | MED-COMP-01, MED-OVERRIDE-01, MED-SCOPE-01, MED-RBAC-01, MED-VERIFY-01, VIS-HERO-DENSITY-01 |
| `CAP-MED-EMAR-PRN-EFFECTIVENESS` | `MED-EMAR` | PRN administration effectiveness follow-up | 2/32 | Pending capability-level adjudication | Observed entry; task blocked | MED-COMP-01, MED-OVERRIDE-01, MED-SCOPE-01, MED-RBAC-01, MED-VERIFY-01, VIS-HERO-DENSITY-01 |
| `CAP-MED-EMAR-REVIEWS` | `MED-EMAR` | Medication review actions and completion | 6/5 | Pending capability-level adjudication | Observed entry; task blocked | MED-COMP-01, MED-OVERRIDE-01, MED-SCOPE-01, MED-RBAC-01, MED-VERIFY-01, VIS-HERO-DENSITY-01 |
| `CAP-MED-EMAR-ROUNDS` | `MED-EMAR` | Medication rounds templates generation assignment and completion | 9/7 | Pending capability-level adjudication | Observed entry; task blocked | MED-COMP-01, MED-OVERRIDE-01, MED-SCOPE-01, MED-RBAC-01, MED-VERIFY-01, VIS-HERO-DENSITY-01 |
| `CAP-MED-EMAR-SELF-ADMINISTRATION` | `MED-EMAR` | Self-administration plans | 4/3 | Pending capability-level adjudication | Observed entry; task blocked | MED-COMP-01, MED-OVERRIDE-01, MED-SCOPE-01, MED-RBAC-01, MED-VERIFY-01, VIS-HERO-DENSITY-01 |
| `CAP-MED-EMAR-STOCK-PHARMACY` | `MED-EMAR` | Medication stock adjustment receipt and pharmacy orders | 8/6 | Pending capability-level adjudication | Observed entry; task blocked | MED-COMP-01, MED-OVERRIDE-01, MED-SCOPE-01, MED-RBAC-01, MED-VERIFY-01, VIS-HERO-DENSITY-01 |
| `MED-AUDIT-LOG` | `MED-AUDIT-LOG` | Audit Log | 1/1 | No credible match | Observed entry; task blocked | — |
| `MED-CDLOSS-REPORT` | `MED-CDLOSS-REPORT` | CDLoss Report | 4/0 | No credible match | Source/blocked | MED-RBAC-01 |
| `MED-EMAR-PDF` | `MED-EMAR-PDF` | Emar Pdf | 3/0 | No credible match | Source/blocked | — |
| `MED-EMAR-REPORT` | `MED-EMAR-REPORT` | Emar Report | 2/1 | No credible match | Observed entry; task blocked | — |
| `MED-EMERGENCY-ACCESS` | `MED-EMERGENCY-ACCESS` | Emergency Access | 1/3 | No credible match | Observed entry; task blocked | — |
| `MED-FRONTEND-EMAR-PLACEHOLDER` | `MED-FRONTEND-EMAR-PLACEHOLDER` | Emar/Placeholder | 0/1 | No credible match | Non-human/unsafe | — |
| `MED-GUIDED-ROUND` | `MED-GUIDED-ROUND` | Guided Round | 3/0 | Bahmni IPD frontend | Source/blocked | MED-SCOPE-01 |
| `MED-LEGACY-REDIRECTS` | `MED-LEGACY-REDIRECTS` | Legacy redirects | 1/0 | No credible match | Non-human/unsafe | — |
| `MED-MEDICATION-ADMINISTRATION-CORRECTION` | `MED-MEDICATION-ADMINISTRATION-CORRECTION` | Medication Administration Correction | 6/0 | Bahmni medication administration | Source/blocked | — |
| `MED-MEDICATION-AUDIT` | `MED-MEDICATION-AUDIT` | Medication Audit | 3/1 | No credible match | Observed entry; task blocked | — |
| `MED-MEDICATION-AUDIT-EVENT` | `MED-MEDICATION-AUDIT-EVENT` | Medication Audit Event | 3/0 | No credible match | Source/blocked | — |
| `MED-MEDICATION-ERROR` | `MED-MEDICATION-ERROR` | Medication Error | 7/2 | Bahmni medication administration | Observed entry; task blocked | — |
| `MED-MEDICATION-SETTINGS` | `MED-MEDICATION-SETTINGS` | Medication Settings | 4/1 | No credible match | Observed entry; task blocked | — |
| `MED-MEDICATIONS` | `MED-MEDICATIONS` | Medications | 1/0 | Bahmni medication administration | Source/blocked | MED-VERIFY-01 |
| `MED-MEDICATIONS-API` | `MED-MEDICATIONS-API` | Medications Api | 30/0 | Bahmni medication administration | Non-human/unsafe | MED-OVERRIDE-01, MED-SCOPE-01 |
| `MED-MEDICATIONS-REPORT` | `MED-MEDICATIONS-REPORT` | Medications Report | 5/1 | No credible match | Observed entry; task blocked | — |
| `MED-MY-DAY-MEDICATIONS` | `MED-MY-DAY-MEDICATIONS` | My Day Medications | 3/0 | No credible match | Source/blocked | — |
| `MED-REFUSAL-FOLLOW-UP` | `MED-REFUSAL-FOLLOW-UP` | Refusal Follow Up | 3/0 | Bahmni medication administration | Source/blocked | — |
| `MED-ROUTE-MEDICATIONS-INDEX` | `MED-ROUTE-MEDICATIONS-INDEX` | Medications Index | 1/0 | No credible match | Source/blocked | — |
| `MED-WORKER-MEDS` | `MED-WORKER-MEDS` | Worker Meds | 4/6 | Bahmni medication administration | Observed entry; task blocked | MED-SCOPE-01 |

## Health and clinical

Capabilities: 12; human: 12; retained exact finding links: `CLIN-SITE-01`, `NZS-ASSURANCE-01`, `VIS-HERO-DENSITY-01`, `CLIN-SCHEDULE-01`.

| Capability | Legacy family | Submodule | Routes/pages | Benchmark disposition | Browser/source status | Findings |
|---|---|---|---:|---|---|---|
| `CLIN-BEHAVIOUR-ABC` | `CLIN-BEHAVIOUR-ABC` | Behaviour Abc | 5/0 | OpenMRS Form Engine | Source/blocked | — |
| `CLIN-CLIENT-BOWEL-CHART` | `CLIN-CLIENT-BOWEL-CHART` | Client Bowel Chart | 4/0 | OpenMRS Form Engine | Source/blocked | — |
| `CLIN-CLIENT-CLINICAL` | `CLIN-CLIENT-CLINICAL` | Client Clinical | 3/0 | OpenMRS O3 patient chart | Source/blocked | — |
| `CLIN-CLIENT-FLUID-CHART` | `CLIN-CLIENT-FLUID-CHART` | Client Fluid Chart | 4/0 | OpenMRS Form Engine | Source/blocked | — |
| `CLIN-CLIENT-SEIZURE-CHART` | `CLIN-CLIENT-SEIZURE-CHART` | Client Seizure Chart | 4/0 | OpenMRS Form Engine | Source/blocked | — |
| `CLIN-CLIENT-SLEEP-CHART` | `CLIN-CLIENT-SLEEP-CHART` | Client Sleep Chart | 4/0 | OpenMRS Form Engine | Source/blocked | — |
| `CLIN-CLINICAL-GOVERNANCE` | `CLIN-CLINICAL-GOVERNANCE` | Clinical Governance | 4/2 | OpenMRS O3 patient chart | Observed entry; task blocked | — |
| `CLIN-HEALTH-CLINICAL` | `CLIN-HEALTH-CLINICAL` | Health Clinical | 1/1 | OpenMRS O3 patient chart | Source/blocked | CLIN-SITE-01 |
| `CLIN-HEALTH-CLINICAL-CLIENT-TRENDS` | `CLIN-HEALTH-CLINICAL-CLIENT-TRENDS` | Health Clinical Client Trends | 1/2 | OpenMRS O3 patient chart | Source/blocked | — |
| `CLIN-HEALTH-CLINICAL-DASHBOARD` | `CLIN-HEALTH-CLINICAL-DASHBOARD` | Health Clinical Dashboard | 16/15 | OpenMRS O3 patient chart | Observed entry; task blocked | CLIN-SITE-01, NZS-ASSURANCE-01, VIS-HERO-DENSITY-01 |
| `CLIN-HEALTH-CLINICAL-PROTOCOL` | `CLIN-HEALTH-CLINICAL-PROTOCOL` | Health Clinical Protocol | 6/3 | OpenMRS Form Engine | Observed entry; task blocked | CLIN-SCHEDULE-01 |
| `CLIN-SHIFT-CLINICAL` | `CLIN-SHIFT-CLINICAL` | Shift Clinical | 3/0 | OpenMRS O3 patient chart | Source/blocked | CLIN-SCHEDULE-01 |

## Human resources

Capabilities: 132; human: 129; retained exact finding links: `PAY-LEAVE-REPLAY`, `WF-AVAILABILITY-LIFECYCLE`, `FIN-SETTLEMENT-01`, `MED-COMP-01`, `WF-HR-PROFILE-SITE-PRIVACY`, `WF-EMAIL-IDENTITY-CONVERGENCE`, `VIS-OVERLAY-FOCUS-01`.

| Capability | Legacy family | Submodule | Routes/pages | Benchmark disposition | Browser/source status | Findings |
|---|---|---|---:|---|---|---|
| `CAP-HR-ANNOUNCEMENT-ACKNOWLEDGEMENT-TRACKING` | `HR-ANNOUNCEMENT` | Announcement acknowledgement tracking and reminders | 7/2 | Pending capability-level adjudication | Observed entry; task blocked | — |
| `CAP-HR-ANNOUNCEMENT-AUTHORING-PUBLICATION` | `HR-ANNOUNCEMENT` | Announcement authoring and publication | 12/2 | Pending capability-level adjudication | Observed entry; task blocked | — |
| `CAP-HR-ASSET-CUSTODY` | `HR-ASSET` | Employee asset custody and returns | 3/2 | Pending capability-level adjudication | Observed entry; task blocked | — |
| `CAP-HR-ASSET-DOCUMENTS-IDENTIFICATION` | `HR-ASSET` | Employee asset documents and QR identification | 5/2 | Pending capability-level adjudication | Observed entry; task blocked | — |
| `CAP-HR-ASSET-REGISTER-LIFECYCLE` | `HR-ASSET` | Employee asset register lifecycle | 9/2 | Pending capability-level adjudication | Observed entry; task blocked | — |
| `CAP-HR-CANDIDATE-APPLICATION-PROGRESSION` | `HR-CANDIDATE` | Application progression and rejection | 3/3 | Pending capability-level adjudication | Source/blocked | — |
| `CAP-HR-CANDIDATE-CANDIDATE-POOL` | `HR-CANDIDATE` | Candidate profile pool tags and documents | 12/3 | Pending capability-level adjudication | Observed entry; task blocked | — |
| `CAP-HR-CANDIDATE-COMMUNICATIONS` | `HR-CANDIDATE` | Recruitment communications and templates | 3/3 | Pending capability-level adjudication | Source/blocked | — |
| `CAP-HR-CANDIDATE-INTERVIEWS` | `HR-CANDIDATE` | Interview scheduling and scoring | 3/3 | Pending capability-level adjudication | Source/blocked | — |
| `CAP-HR-CANDIDATE-OFFERS-HIRE` | `HR-CANDIDATE` | Offer approval response and hire conversion | 10/1 | Pending capability-level adjudication | Observed entry; task blocked | — |
| `CAP-HR-CANDIDATE-REFERENCES` | `HR-CANDIDATE` | Reference capture and review | 2/3 | Pending capability-level adjudication | Source/blocked | — |
| `CAP-HR-COMPENSATION-REVIEW-CYCLE` | `HR-COMPENSATION` | Compensation review and application cycle | 8/6 | Pending capability-level adjudication | Source/blocked | — |
| `CAP-HR-COMPENSATION-STRUCTURE-HISTORY` | `HR-COMPENSATION` | Pay bands settings and compensation history | 7/6 | Pending capability-level adjudication | Source/blocked | — |
| `CAP-HR-COMPLIANCE-RECORDS-EXEMPTIONS` | `HR-COMPLIANCE` | Staff compliance records evidence and exemptions | 9/2 | Pending capability-level adjudication | Observed entry; task blocked | — |
| `CAP-HR-COMPLIANCE-RENEWAL-OVERSIGHT` | `HR-COMPLIANCE` | Compliance renewal oversight and reminders | 3/2 | Pending capability-level adjudication | Source/blocked | — |
| `CAP-HR-CONTROLLERS-PERFORMANCE-REVIEW-GOALS-APPROVAL` | `HR-CONTROLLERS-PERFORMANCE-REVIEW` | Review goals and approval | 2/4 | Pending capability-level adjudication | Source/blocked | — |
| `CAP-HR-CONTROLLERS-PERFORMANCE-REVIEW-MANAGER-ASSESSMENT` | `HR-CONTROLLERS-PERFORMANCE-REVIEW` | Manager assessment and feedback | 3/4 | Pending capability-level adjudication | Source/blocked | — |
| `CAP-HR-CONTROLLERS-PERFORMANCE-REVIEW-SETUP-SELF-ASSESSMENT` | `HR-CONTROLLERS-PERFORMANCE-REVIEW` | Performance review setup and self-assessment | 6/4 | Pending capability-level adjudication | Observed entry; task blocked | — |
| `CAP-HR-FEED-CONVERSATIONS` | `HR-FEED` | Employee feed posts reactions and replies | 6/2 | Pending capability-level adjudication | Source/blocked | — |
| `CAP-HR-FEED-KUDOS` | `HR-FEED` | Kudos reactions and replies | 4/2 | Pending capability-level adjudication | Source/blocked | — |
| `CAP-HR-FEEDBACK-BULK-SUMMARY` | `HR-FEEDBACK` | Bulk feedback requests and summary | 2/3 | Pending capability-level adjudication | Source/blocked | — |
| `CAP-HR-FEEDBACK-REQUEST-RESPONSE` | `HR-FEEDBACK` | Feedback request response and follow-up | 8/3 | Pending capability-level adjudication | Source/blocked | — |
| `CAP-HR-FEEDBACK-TEMPLATES` | `HR-FEEDBACK` | Feedback template administration | 3/3 | Pending capability-level adjudication | Source/blocked | — |
| `CAP-HR-GOAL-CHECKINS-PROGRESS` | `HR-GOAL` | Goal check-ins and progress updates | 3/2 | Pending capability-level adjudication | Source/blocked | — |
| `CAP-HR-GOAL-GOAL-KEY-RESULTS` | `HR-GOAL` | Goals key results hierarchy and lifecycle | 13/2 | Pending capability-level adjudication | Source/blocked | — |
| `CAP-HR-HR-DOCUMENT-GENERATION-PREVIEW` | `HR-HR-DOCUMENT` | HR document generation and preview | 2/7 | Pending capability-level adjudication | Source/blocked | — |
| `CAP-HR-HR-DOCUMENT-LIBRARY` | `HR-HR-DOCUMENT` | HR document library movement and audit | 12/7 | Pending capability-level adjudication | Observed entry; task blocked | — |
| `CAP-HR-HR-DOCUMENT-PROFILE-DOCUMENTS` | `HR-HR-DOCUMENT` | Employee profile document management | 5/7 | Pending capability-level adjudication | Observed entry; task blocked | — |
| `CAP-HR-HR-DOCUMENT-TEMPLATES` | `HR-HR-DOCUMENT` | HR document templates | 6/7 | Pending capability-level adjudication | Observed entry; task blocked | — |
| `CAP-HR-HR-PERFORMANCE-REVIEW-PROBATION` | `HR-HR-PERFORMANCE-REVIEW` | Probation review management | 2/2 | Pending capability-level adjudication | Source/blocked | — |
| `CAP-HR-HR-PERFORMANCE-REVIEW-REVIEW-CYCLE` | `HR-HR-PERFORMANCE-REVIEW` | Employee performance review cycle | 11/2 | Pending capability-level adjudication | Observed entry; task blocked | — |
| `CAP-HR-LEAVE-BALANCES` | `HR-LEAVE` | Leave balances ledger and adjustments | 4/3 | Pending capability-level adjudication | Observed entry; task blocked | PAY-LEAVE-REPLAY, WF-AVAILABILITY-LIFECYCLE |
| `CAP-HR-LEAVE-BULK-ESCALATION` | `HR-LEAVE` | Bulk leave decisions and escalation | 3/3 | Pending capability-level adjudication | Source/blocked | PAY-LEAVE-REPLAY, WF-AVAILABILITY-LIFECYCLE |
| `CAP-HR-LEAVE-REQUEST-DECISION` | `HR-LEAVE` | Leave requests approval and cancellation | 9/3 | Pending capability-level adjudication | Observed entry; task blocked | PAY-LEAVE-REPLAY, WF-AVAILABILITY-LIFECYCLE |
| `CAP-HR-MY-HR-DOCUMENTS-POLICIES` | `HR-MY-HR` | My documents signatures and policy attestations | 5/2 | Pending capability-level adjudication | Observed entry; task blocked | — |
| `CAP-HR-MY-HR-EXPENSES` | `HR-MY-HR` | My expenses and claims | 3/1 | Pending capability-level adjudication | Observed entry; task blocked | — |
| `CAP-HR-MY-HR-GOALS` | `HR-MY-HR` | My goals and progress | 2/1 | Pending capability-level adjudication | Observed entry; task blocked | — |
| `CAP-HR-MY-HR-HOME-DIRECTORY-BENEFITS` | `HR-MY-HR` | My HR home directory and benefits | 3/3 | Pending capability-level adjudication | Observed entry; task blocked | — |
| `CAP-HR-MY-HR-KUDOS` | `HR-MY-HR` | My kudos and shoutouts | 4/1 | Pending capability-level adjudication | Observed entry; task blocked | — |
| `CAP-HR-MY-HR-LEAVE` | `HR-MY-HR` | My leave requests and balance preview | 4/1 | Pending capability-level adjudication | Observed entry; task blocked | — |
| `CAP-HR-MY-HR-ONBOARDING-TRAINING` | `HR-MY-HR` | My onboarding and training | 2/1 | Pending capability-level adjudication | Observed entry; task blocked | — |
| `CAP-HR-MY-HR-ONE-REVIEWS` | `HR-MY-HR` | My one-to-one and review actions | 4/2 | Pending capability-level adjudication | Observed entry; task blocked | — |
| `CAP-HR-MY-HR-PROFILE` | `HR-MY-HR` | My profile maintenance | 2/1 | Pending capability-level adjudication | Observed entry; task blocked | — |
| `CAP-HR-MY-HR-SURVEYS` | `HR-MY-HR` | My survey participation | 2/1 | Pending capability-level adjudication | Observed entry; task blocked | — |
| `CAP-HR-MY-HR-TIME-CALENDAR` | `HR-MY-HR` | My time clock shifts and calendar | 5/1 | Pending capability-level adjudication | Observed entry; task blocked | — |
| `CAP-HR-ONBOARDING-CHECKLIST-CASE` | `HR-ONBOARDING` | Onboarding checklist cases and bulk control | 11/2 | Pending capability-level adjudication | Observed entry; task blocked | — |
| `CAP-HR-ONBOARDING-TASKS` | `HR-ONBOARDING` | Onboarding task execution and provisioning | 7/2 | Pending capability-level adjudication | Source/blocked | — |
| `CAP-HR-ONBOARDING-TEMPLATES` | `HR-ONBOARDING` | Onboarding templates | 4/2 | Pending capability-level adjudication | Source/blocked | — |
| `CAP-HR-PAYROLL-EXPORT-EXPORT-PROFILES` | `HR-PAYROLL-EXPORT` | Payroll export profile configuration | 3/1 | Pending capability-level adjudication | Source/blocked | FIN-SETTLEMENT-01, PAY-LEAVE-REPLAY |
| `CAP-HR-PAYROLL-EXPORT-RUNS-PAYMENT` | `HR-PAYROLL-EXPORT` | Payroll run export locking and payment | 6/1 | Pending capability-level adjudication | Observed entry; task blocked | FIN-SETTLEMENT-01, PAY-LEAVE-REPLAY |
| `CAP-HR-PIP-MILESTONES-EVIDENCE` | `HR-PIP` | PIP milestones and evidence | 5/3 | Pending capability-level adjudication | Source/blocked | — |
| `CAP-HR-PIP-PLAN-LIFECYCLE` | `HR-PIP` | Performance improvement plan lifecycle | 8/3 | Pending capability-level adjudication | Source/blocked | — |
| `CAP-HR-RECRUITMENT-JOB-APPROVAL` | `HR-RECRUITMENT-JOB` | Recruitment job approval decision | 3/0 | Pending capability-level adjudication | Source/blocked | — |
| `CAP-HR-RECRUITMENT-JOB-AUTHOR-PUBLISH` | `HR-RECRUITMENT-JOB` | Recruitment job authoring publication closure and sync | 6/0 | Pending capability-level adjudication | Source/blocked | — |
| `CAP-HR-SUCCESSION-CANDIDATES` | `HR-SUCCESSION` | Succession candidates and talent-pool nomination | 4/2 | Pending capability-level adjudication | Source/blocked | — |
| `CAP-HR-SUCCESSION-PLANS` | `HR-SUCCESSION` | Succession plan lifecycle | 6/2 | Pending capability-level adjudication | Observed entry; task blocked | — |
| `CAP-HR-TIME-TRACKING-CLOCKING` | `HR-TIME-TRACKING` | Staff clock-in clock-out and on-behalf clocking | 3/1 | Pending capability-level adjudication | Source/blocked | — |
| `CAP-HR-TIME-TRACKING-ENTRY-CORRECTIONS` | `HR-TIME-TRACKING` | Time entry notes corrections and voids | 6/1 | Pending capability-level adjudication | Observed entry; task blocked | — |
| `CAP-HR-TIME-TRACKING-OVERSIGHT-REPORTING` | `HR-TIME-TRACKING` | Time oversight timesheets reports and export | 4/1 | Pending capability-level adjudication | Observed entry; task blocked | — |
| `CAP-HR-TRAINING-ASSIGNMENT-COMPLETION` | `HR-TRAINING` | Training assignment enrolment completion and certificates | 8/1 | Pending capability-level adjudication | Observed entry; task blocked | — |
| `CAP-HR-TRAINING-CLAIMS-EXPORT` | `HR-TRAINING` | Training fee claims and export | 2/1 | Pending capability-level adjudication | Observed entry; task blocked | — |
| `CAP-HR-TRAINING-COURSE-SESSION-CATALOG` | `HR-TRAINING` | Training course session and catalogue management | 11/1 | Pending capability-level adjudication | Observed entry; task blocked | — |
| `CAP-HR-WELLBEING-ACTION-PLANS` | `HR-WELLBEING` | Wellbeing action plans and follow-up notes | 6/2 | Pending capability-level adjudication | Source/blocked | — |
| `CAP-HR-WELLBEING-CHECKINS-SIGNALS` | `HR-WELLBEING` | Wellbeing check-ins flags and signal response | 8/2 | Pending capability-level adjudication | Source/blocked | — |
| `CAP-HR-WELLBEING-EAP-REFERRALS` | `HR-WELLBEING` | Employee assistance referrals | 1/2 | Pending capability-level adjudication | Source/blocked | — |
| `CAP-HR-WELLBEING-SURVEYS` | `HR-WELLBEING` | Wellbeing surveys responses and publication | 11/2 | Pending capability-level adjudication | Source/blocked | — |
| `HR-ANALYTICS-DASHBOARD` | `HR-ANALYTICS-DASHBOARD` | Analytics Dashboard | 1/1 | No credible match | Observed entry; task blocked | — |
| `HR-APPROVAL` | `HR-APPROVAL` | Approval | 4/2 | No credible match | Observed entry; task blocked | — |
| `HR-AUDIT` | `HR-AUDIT` | Audit | 2/1 | No credible match | Observed entry; task blocked | — |
| `HR-BENEFITS` | `HR-BENEFITS` | Benefits | 6/2 | No credible match | Source/blocked | — |
| `HR-BONUS` | `HR-BONUS` | Bonus | 3/1 | No credible match | Source/blocked | — |
| `HR-CALENDAR` | `HR-CALENDAR` | Calendar | 9/1 | No credible match | Observed entry; task blocked | — |
| `HR-CAREERS-CAREER-PORTAL` | `HR-CAREERS-CAREER-PORTAL` | Career Portal | 5/3 | No credible match | Observed entry; task blocked | — |
| `HR-COMPETENCY` | `HR-COMPETENCY` | Competency | 10/3 | No credible match | Source/blocked | MED-COMP-01 |
| `HR-COMPETENCY-FRAMEWORK` | `HR-COMPETENCY-FRAMEWORK` | Competency Framework | 6/0 | No credible match | Source/blocked | — |
| `HR-COMPLIANCE-CALENDAR` | `HR-COMPLIANCE-CALENDAR` | Compliance Calendar | 1/6 | No credible match | Observed entry; task blocked | — |
| `HR-COMPLIANCE-EXPORT` | `HR-COMPLIANCE-EXPORT` | Compliance Export | 1/0 | No credible match | Source/blocked | — |
| `HR-COMPLIANCE-MATRIX` | `HR-COMPLIANCE-MATRIX` | Compliance Matrix | 5/1 | No credible match | Observed entry; task blocked | — |
| `HR-CONTROLLERS-CAREER-PORTAL` | `HR-CONTROLLERS-CAREER-PORTAL` | Career Portal | 1/1 | No credible match | Source/blocked | — |
| `HR-CUSTOM-FIELD` | `HR-CUSTOM-FIELD` | Custom Field | 4/1 | No credible match | Observed entry; task blocked | — |
| `HR-DEPARTMENT` | `HR-DEPARTMENT` | Department | 5/0 | No credible match | Source/blocked | — |
| `HR-DEVELOPMENT-GOAL` | `HR-DEVELOPMENT-GOAL` | Development Goal | 4/0 | No credible match | Source/blocked | — |
| `HR-DIRECTORY` | `HR-DIRECTORY` | Directory | 3/0 | No credible match | Source/blocked | — |
| `HR-DISCIPLINARY` | `HR-DISCIPLINARY` | Disciplinary | 5/0 | No credible match | Source/blocked | — |
| `HR-EMPLOYEE-PROFILE` | `HR-EMPLOYEE-PROFILE` | Employee Profile | 9/3 | Frappe HR | Observed entry; task blocked | WF-HR-PROFILE-SITE-PRIVACY, WF-EMAIL-IDENTITY-CONVERGENCE |
| `HR-ESIGNATURE` | `HR-ESIGNATURE` | ESignature | 9/2 | No credible match | Observed entry; task blocked | — |
| `HR-EXIT-INTERVIEW` | `HR-EXIT-INTERVIEW` | Exit Interview | 5/3 | No credible match | Observed entry; task blocked | — |
| `HR-EXPENSE` | `HR-EXPENSE` | Expense | 10/3 | No credible match | Source/blocked | — |
| `HR-GOAL-CYCLE` | `HR-GOAL-CYCLE` | Goal Cycle | 5/0 | No credible match | Source/blocked | — |
| `HR-HEADCOUNT` | `HR-HEADCOUNT` | Headcount | 1/1 | No credible match | Observed entry; task blocked | — |
| `HR-HR-API` | `HR-HR-API` | Hr Api | 8/0 | No credible match | Non-human/unsafe | WF-HR-PROFILE-SITE-PRIVACY |
| `HR-HR-AUTOMATION` | `HR-HR-AUTOMATION` | Hr Automation | 5/1 | No credible match | Observed entry; task blocked | — |
| `HR-HR-CASE` | `HR-HR-CASE` | Hr Case | 8/2 | No credible match | Observed entry; task blocked | — |
| `HR-HR-REPORT` | `HR-HR-REPORT` | Hr Report | 8/2 | No credible match | Observed entry; task blocked | — |
| `HR-HR-WEBHOOK` | `HR-HR-WEBHOOK` | Hr Webhook | 5/1 | No credible match | Observed entry; task blocked | — |
| `HR-ICAL` | `HR-ICAL` | ICal | 2/0 | No credible match | Source/blocked | — |
| `HR-IMPORT-EXPORT` | `HR-IMPORT-EXPORT` | Import Export | 4/1 | No credible match | Source/blocked | VIS-OVERLAY-FOCUS-01 |
| `HR-INTERVIEW-KIT` | `HR-INTERVIEW-KIT` | Interview Kit | 3/0 | No credible match | Source/blocked | — |
| `HR-LEAVE-REPORT` | `HR-LEAVE-REPORT` | Leave Report | 2/1 | Frappe HR | Observed entry; task blocked | — |
| `HR-LEGACY-REDIRECTS` | `HR-LEGACY-REDIRECTS` | Legacy redirects | 6/0 | No credible match | Non-human/unsafe | — |
| `HR-OFFBOARDING` | `HR-OFFBOARDING` | Offboarding | 5/2 | No credible match | Observed entry; task blocked | WF-AVAILABILITY-LIFECYCLE |
| `HR-ONBOARDING-EMAIL` | `HR-ONBOARDING-EMAIL` | Onboarding Email | 4/0 | Frappe HR | Source/blocked | — |
| `HR-ORG-CHART` | `HR-ORG-CHART` | Org Chart | 2/0 | No credible match | Source/blocked | — |
| `HR-PAYSLIP` | `HR-PAYSLIP` | Payslip | 7/3 | No credible match | Observed entry; task blocked | — |
| `HR-PERFORMANCE-HUB` | `HR-PERFORMANCE-HUB` | Performance Hub | 2/1 | No credible match | Observed entry; task blocked | — |
| `HR-POLICY` | `HR-POLICY` | Policy | 10/2 | No credible match | Observed entry; task blocked | — |
| `HR-POLICY-ATTESTATION` | `HR-POLICY-ATTESTATION` | Policy Attestation | 2/1 | No credible match | Observed entry; task blocked | — |
| `HR-POSITION` | `HR-POSITION` | Position | 6/3 | Frappe HR | Observed entry; task blocked | — |
| `HR-PUBLIC-HOLIDAY` | `HR-PUBLIC-HOLIDAY` | Public Holiday | 4/1 | No credible match | Observed entry; task blocked | — |
| `HR-RECRUITMENT` | `HR-RECRUITMENT` | Recruitment | 2/1 | Frappe HR | Source/blocked | WF-EMAIL-IDENTITY-CONVERGENCE |
| `HR-RECRUITMENT-EXPORT` | `HR-RECRUITMENT-EXPORT` | Recruitment Export | 1/0 | Frappe HR | Source/blocked | — |
| `HR-REFERENCE` | `HR-REFERENCE` | Reference | 2/1 | No credible match | Source/blocked | — |
| `HR-REPORT-BUILDER` | `HR-REPORT-BUILDER` | Report Builder | 8/2 | No credible match | Observed entry; task blocked | — |
| `HR-ROUTE-CAREERS-SHOW` | `HR-ROUTE-CAREERS-SHOW` | Careers Show | 1/0 | No credible match | Source/blocked | — |
| `HR-ROUTE-HR-JOB-POSTINGS-INDEX` | `HR-ROUTE-HR-JOB-POSTINGS-INDEX` | Hr Job Postings Index | 1/0 | Frappe HR | Source/blocked | — |
| `HR-ROUTE-HR-JOBS-INDEX` | `HR-ROUTE-HR-JOBS-INDEX` | Hr Jobs Index | 1/0 | Frappe HR | Source/blocked | — |
| `HR-ROUTE-HR-KITS-INDEX` | `HR-ROUTE-HR-KITS-INDEX` | Hr Kits Index | 1/0 | No credible match | Source/blocked | — |
| `HR-ROUTE-HR-ONBOARDING-EMAILS-INDEX` | `HR-ROUTE-HR-ONBOARDING-EMAILS-INDEX` | Hr Onboarding Emails Index | 1/0 | Frappe HR | Source/blocked | — |
| `HR-ROUTE-HR-ONBOARDING-EMAILS-LOG` | `HR-ROUTE-HR-ONBOARDING-EMAILS-LOG` | Hr Onboarding Emails Log | 1/0 | Frappe HR | Source/blocked | — |
| `HR-ROUTE-HR-ONBOARDING-EMAILS-PREVIEW` | `HR-ROUTE-HR-ONBOARDING-EMAILS-PREVIEW` | Hr Onboarding Emails Preview | 1/0 | Frappe HR | Source/blocked | — |
| `HR-ROUTE-HR-RECRUITMENT-ANALYTICS` | `HR-ROUTE-HR-RECRUITMENT-ANALYTICS` | Hr Recruitment Analytics | 1/0 | Frappe HR | Source/blocked | — |
| `HR-ROUTE-HR-RECRUITMENT-KANBAN` | `HR-ROUTE-HR-RECRUITMENT-KANBAN` | Hr Recruitment Kanban | 1/0 | Frappe HR | Source/blocked | — |
| `HR-ROUTE-TRAINING-MATRIX` | `HR-ROUTE-TRAINING-MATRIX` | Training Matrix | 1/0 | No credible match | Source/blocked | — |
| `HR-SKILLS` | `HR-SKILLS` | Skills | 4/2 | No credible match | Observed entry; task blocked | — |
| `HR-STAFF` | `HR-STAFF` | Staff | 4/3 | Frappe HR | Observed entry; task blocked | — |
| `HR-STAFF-ASSIGNMENT` | `HR-STAFF-ASSIGNMENT` | Staff Assignment | 2/1 | Frappe HR | Source/blocked | — |
| `HR-STAFF-BACKGROUND-CHECK` | `HR-STAFF-BACKGROUND-CHECK` | Staff Background Check | 9/0 | Frappe HR | Source/blocked | — |
| `HR-STAFF-CREDENTIAL` | `HR-STAFF-CREDENTIAL` | Staff Credential | 4/1 | Frappe HR | Source/blocked | — |
| `HR-STAFF-INDUCTION` | `HR-STAFF-INDUCTION` | Staff Induction | 4/0 | Frappe HR | Source/blocked | — |
| `HR-SUPERVISION` | `HR-SUPERVISION` | Supervision | 6/1 | No credible match | Source/blocked | — |
| `HR-TRAINING-DASHBOARD` | `HR-TRAINING-DASHBOARD` | Training Dashboard | 0/0 | No credible match | Non-human/unsafe | — |
| `HR-VETTING` | `HR-VETTING` | Vetting | 10/4 | No credible match | Observed entry; task blocked | — |

## Health and safety

Capabilities: 35; human: 35; retained exact finding links: `NZS-ASSURANCE-01`, `HS-ASSURANCE-01`, `VIS-RESPONSIVE-OVERFLOW-01`, `HS-SITE-01`, `SITE-CHECK-003`, `VIS-HERO-DENSITY-01`, `HS-CLOSE-01`, `HS-NOTIFIABLE-01`, `SAFE-TERMINAL-SYNC-01`, `INCIDENT-RECOVERY-01`, `VIS-OVERLAY-FOCUS-01`.

| Capability | Legacy family | Submodule | Routes/pages | Benchmark disposition | Browser/source status | Findings |
|---|---|---|---:|---|---|---|
| `CAP-HS-EMERGENCY-DRILL-DRILL-LIFECYCLE` | `HS-EMERGENCY-DRILL` | Emergency drill scheduling execution participants and evidence | 12/3 | Pending capability-level adjudication | Observed entry; task blocked | NZS-ASSURANCE-01 |
| `CAP-HS-EMERGENCY-DRILL-FINDINGS` | `HS-EMERGENCY-DRILL` | Emergency drill findings and resolution | 3/3 | Pending capability-level adjudication | Source/blocked | NZS-ASSURANCE-01 |
| `CAP-HS-FIRST-AID-FOLLOWUP` | `HS-FIRST-AID` | First-aid follow-up and completion | 2/2 | Pending capability-level adjudication | Source/blocked | NZS-ASSURANCE-01 |
| `CAP-HS-FIRST-AID-RECORD-EVIDENCE` | `HS-FIRST-AID` | First-aid record attachments incident link and export | 10/2 | Pending capability-level adjudication | Observed entry; task blocked | NZS-ASSURANCE-01 |
| `CAP-HS-HAZARDOUS-SUBSTANCE-EXPOSURE` | `HS-HAZARDOUS-SUBSTANCE` | Hazardous substance exposure recording | 2/2 | Pending capability-level adjudication | Source/blocked | NZS-ASSURANCE-01 |
| `CAP-HS-HAZARDOUS-SUBSTANCE-REGISTER-SDS-STORAGE` | `HS-HAZARDOUS-SUBSTANCE` | Hazardous substance register SDS and storage | 8/2 | Pending capability-level adjudication | Observed entry; task blocked | NZS-ASSURANCE-01 |
| `CAP-HS-HAZARDOUS-SUBSTANCE-STATUS-GOVERNANCE` | `HS-HAZARDOUS-SUBSTANCE` | Hazardous substance status governance | 1/2 | Pending capability-level adjudication | Source/blocked | NZS-ASSURANCE-01 |
| `CAP-HS-HS-CORRECTIVE-ACTION-DELIVERY-CLOSURE` | `HS-HS-CORRECTIVE-ACTION` | Corrective action seeding start completion and closure | 5/0 | Pending capability-level adjudication | Source/blocked | HS-ASSURANCE-01, VIS-RESPONSIVE-OVERFLOW-01 |
| `CAP-HS-HS-CORRECTIVE-ACTION-VERIFICATION-REWORK` | `HS-HS-CORRECTIVE-ACTION` | Corrective action verification and rework | 2/0 | Pending capability-level adjudication | Source/blocked | HS-ASSURANCE-01, VIS-RESPONSIVE-OVERFLOW-01 |
| `CAP-HS-HS-RISK-ASSESSMENT-DRAFT-EVIDENCE` | `HS-HS-RISK-ASSESSMENT` | Risk assessment drafting residual risk and evidence | 8/0 | Pending capability-level adjudication | Source/blocked | NZS-ASSURANCE-01 |
| `CAP-HS-HS-RISK-ASSESSMENT-LIFECYCLE-REVIEW` | `HS-HS-RISK-ASSESSMENT` | Risk assessment activation review supersession and archive | 4/0 | Pending capability-level adjudication | Source/blocked | NZS-ASSURANCE-01 |
| `CAP-HS-LONE-WORKER-ALERT-RESPONSE` | `HS-LONE-WORKER` | Lone-worker alert acknowledgement and resolution | 2/1 | Pending capability-level adjudication | Source/blocked | — |
| `CAP-HS-LONE-WORKER-SESSION-SAFETY` | `HS-LONE-WORKER` | Lone-worker session check-in panic and emergency lifecycle | 9/1 | Pending capability-level adjudication | Observed entry; task blocked | — |
| `CAP-HS-PPE-ALLOCATIONS` | `HS-PPE` | PPE allocation acknowledgement and return | 6/6 | Pending capability-level adjudication | Observed entry; task blocked | — |
| `CAP-HS-PPE-INSPECTIONS` | `HS-PPE` | PPE inspection evidence | 3/6 | Pending capability-level adjudication | Observed entry; task blocked | — |
| `CAP-HS-PPE-INVENTORY` | `HS-PPE` | PPE inventory allocation condemnation and disposal | 11/6 | Pending capability-level adjudication | Observed entry; task blocked | — |
| `CAP-HS-PPE-TYPES` | `HS-PPE` | PPE type administration | 4/6 | Pending capability-level adjudication | Source/blocked | — |
| `CAP-HS-RESTRAINT-EVENTS-OVERSIGHT` | `HS-RESTRAINT` | Restraint events evidence incidents and client oversight | 9/2 | Pending capability-level adjudication | Observed entry; task blocked | HS-SITE-01, NZS-ASSURANCE-01 |
| `CAP-HS-RESTRAINT-PLANS` | `HS-RESTRAINT` | Restraint plan activation review and archive | 6/2 | Pending capability-level adjudication | Source/blocked | HS-SITE-01, NZS-ASSURANCE-01 |
| `CAP-HS-RETURN-TO-WORK-INJURY-CAPACITY` | `HS-RETURN-TO-WORK` | Injury record evidence and capacity assessment | 11/1 | Pending capability-level adjudication | Observed entry; task blocked | HS-SITE-01 |
| `CAP-HS-RETURN-TO-WORK-RTW-PLANS-DUTIES` | `HS-RETURN-TO-WORK` | Return-to-work plans and modified duties | 3/1 | Pending capability-level adjudication | Source/blocked | HS-SITE-01 |
| `CAP-HS-SAFE-WORK-PROCEDURE-ACKNOWLEDGE-ARCHIVE` | `HS-SAFE-WORK-PROCEDURE` | Procedure acknowledgement archive and restore | 3/1 | Pending capability-level adjudication | Source/blocked | — |
| `CAP-HS-SAFE-WORK-PROCEDURE-AUTHOR-EVIDENCE` | `HS-SAFE-WORK-PROCEDURE` | Safe work procedure authoring and evidence | 10/1 | Pending capability-level adjudication | Observed entry; task blocked | — |
| `CAP-HS-SAFE-WORK-PROCEDURE-REVIEW-APPROVAL` | `HS-SAFE-WORK-PROCEDURE` | Safe work procedure review changes and approval | 4/1 | Pending capability-level adjudication | Source/blocked | — |
| `CAP-HS-SITE-HAZARD-CORRECTIVE-ACTIONS` | `HS-SITE-HAZARD` | Hazard corrective action completion | 1/3 | Pending capability-level adjudication | Source/blocked | SITE-CHECK-003 |
| `CAP-HS-SITE-HAZARD-HAZARD-ASSESSMENT` | `HS-SITE-HAZARD` | Hazard assignment review evidence transition and closure | 9/3 | Pending capability-level adjudication | Observed entry; task blocked | SITE-CHECK-003 |
| `CAP-HS-SITE-HAZARD-REGISTER` | `HS-SITE-HAZARD` | Site and global hazard register | 5/3 | Pending capability-level adjudication | Observed entry; task blocked | SITE-CHECK-003 |
| `CAP-HS-WORKER-PARTICIPATION-COMMITTEES` | `HS-WORKER-PARTICIPATION` | Health and safety committees and meeting creation | 4/1 | Pending capability-level adjudication | Observed entry; task blocked | — |
| `CAP-HS-WORKER-PARTICIPATION-CONSULTATIONS` | `HS-WORKER-PARTICIPATION` | Worker consultations evidence and status | 5/1 | Pending capability-level adjudication | Observed entry; task blocked | — |
| `CAP-HS-WORKER-PARTICIPATION-MEETINGS` | `HS-WORKER-PARTICIPATION` | Worker-participation meetings attendees and minutes | 6/1 | Pending capability-level adjudication | Observed entry; task blocked | — |
| `CAP-HS-WORKER-PARTICIPATION-REPRESENTATIVES` | `HS-WORKER-PARTICIPATION` | Health and safety representative administration | 2/1 | Pending capability-level adjudication | Source/blocked | — |
| `HS-HEALTH-SAFETY-DASHBOARD` | `HS-HEALTH-SAFETY-DASHBOARD` | Health Safety Dashboard | 4/15 | No credible match | Observed entry; task blocked | HS-SITE-01, NZS-ASSURANCE-01, VIS-HERO-DENSITY-01 |
| `HS-HS-EVENT` | `HS-HS-EVENT` | Hs Event | 6/6 | No credible match | Observed entry; task blocked | HS-SITE-01, HS-CLOSE-01, HS-NOTIFIABLE-01, SAFE-TERMINAL-SYNC-01, INCIDENT-RECOVERY-01, VIS-OVERLAY-FOCUS-01 |
| `HS-HS-GOVERNANCE-REPORT` | `HS-HS-GOVERNANCE-REPORT` | Hs Governance Report | 5/0 | No credible match | Source/blocked | — |
| `HS-HS-INVESTIGATION` | `HS-HS-INVESTIGATION` | Hs Investigation | 5/0 | BeaconHS | Source/blocked | HS-ASSURANCE-01 |

## Incidents and safeguarding

Capabilities: 14; human: 13; retained exact finding links: `HS-NOTIFIABLE-01`, `INCIDENT-ALERT-LIFECYCLE-01`, `SAFE-SENSITIVITY-01`, `SAFE-TERMINAL-SYNC-01`, `SAFE-NESTED-01`.

| Capability | Legacy family | Submodule | Routes/pages | Benchmark disposition | Browser/source status | Findings |
|---|---|---|---:|---|---|---|
| `CAP-INC-INCIDENT-CORRECTIVE-HANDOFF` | `INC-INCIDENT` | Incident corrective-action handoff | 1/2 | Pending capability-level adjudication | Source/blocked | HS-NOTIFIABLE-01, INCIDENT-ALERT-LIFECYCLE-01 |
| `CAP-INC-INCIDENT-REPORT-EVIDENCE` | `INC-INCIDENT` | Incident report and attachment evidence | 9/2 | Pending capability-level adjudication | Observed entry; task blocked | HS-NOTIFIABLE-01, INCIDENT-ALERT-LIFECYCLE-01 |
| `CAP-INC-INCIDENT-REVIEW-CLOSURE` | `INC-INCIDENT` | Incident submission review closure and reopening | 4/2 | Pending capability-level adjudication | Source/blocked | HS-NOTIFIABLE-01, INCIDENT-ALERT-LIFECYCLE-01 |
| `CAP-INC-SAFEGUARDING-CONCERN-REPORT-TRIAGE` | `INC-SAFEGUARDING-CONCERN` | Safeguarding concern reporting sensitivity assignment and triage | 10/2 | Pending capability-level adjudication | Observed entry; task blocked | SAFE-SENSITIVITY-01, SAFE-TERMINAL-SYNC-01 |
| `CAP-INC-SAFEGUARDING-CONCERN-STATUS-CLOSURE` | `INC-SAFEGUARDING-CONCERN` | Safeguarding status progression and closure | 2/2 | Pending capability-level adjudication | Source/blocked | SAFE-SENSITIVITY-01, SAFE-TERMINAL-SYNC-01 |
| `INC-FRONTEND-CLIENTS-INCIDENTS` | `INC-FRONTEND-CLIENTS-INCIDENTS` | Clients/Incidents | 0/1 | BeaconHS | Non-human/unsafe | — |
| `INC-INCIDENT-FOLLOWUP` | `INC-INCIDENT-FOLLOWUP` | Incident Followup | 3/0 | BeaconHS | Source/blocked | — |
| `INC-INCIDENT-REPORT` | `INC-INCIDENT-REPORT` | Incident Report | 2/1 | BeaconHS | Observed entry; task blocked | — |
| `INC-INCIDENT-TEMPLATE` | `INC-INCIDENT-TEMPLATE` | Incident Template | 5/2 | BeaconHS | Observed entry; task blocked | — |
| `INC-SAFEGUARDING-ACTION-PLAN` | `INC-SAFEGUARDING-ACTION-PLAN` | Safeguarding Action Plan | 3/0 | OpenProject Community | Source/blocked | SAFE-NESTED-01 |
| `INC-SAFEGUARDING-ATTACHMENT` | `INC-SAFEGUARDING-ATTACHMENT` | Safeguarding Attachment | 3/0 | No credible match | Source/blocked | — |
| `INC-SAFEGUARDING-EXTERNAL-REPORT` | `INC-SAFEGUARDING-EXTERNAL-REPORT` | Safeguarding External Report | 2/0 | No credible match | Source/blocked | SAFE-NESTED-01 |
| `INC-SAFEGUARDING-INVESTIGATION` | `INC-SAFEGUARDING-INVESTIGATION` | Safeguarding Investigation | 2/0 | BeaconHS | Source/blocked | SAFE-NESTED-01 |
| `INC-SAFEGUARDING-RISK-ASSESSMENT` | `INC-SAFEGUARDING-RISK-ASSESSMENT` | Safeguarding Risk Assessment | 1/0 | CISO Assistant Community | Source/blocked | — |

## Privacy and compliance

Capabilities: 18; human: 17; retained exact finding links: `NZS-ASSURANCE-01`, `RETENTION-EXEC-01`, `PRIV-DSR-01`, `PRIV-STATEMENT-01`.

| Capability | Legacy family | Submodule | Routes/pages | Benchmark disposition | Browser/source status | Findings |
|---|---|---|---:|---|---|---|
| `PRIV-AUDIT-EXPORT` | `PRIV-AUDIT-EXPORT` | Audit Export | 4/1 | Medplum | Observed entry; task blocked | — |
| `PRIV-AUDIT-LOG` | `PRIV-AUDIT-LOG` | Audit Log | 1/1 | Medplum | Observed entry; task blocked | — |
| `PRIV-AUDIT-LOG-SETTINGS` | `PRIV-AUDIT-LOG-SETTINGS` | Audit Log Settings | 2/1 | Medplum | Observed entry; task blocked | — |
| `PRIV-COMPLIANCE` | `PRIV-COMPLIANCE` | Compliance | 10/5 | No credible match | Observed entry; task blocked | — |
| `PRIV-COMPLIANCE-DASHBOARD` | `PRIV-COMPLIANCE-DASHBOARD` | Compliance Dashboard | 1/5 | No credible match | Observed entry; task blocked | NZS-ASSURANCE-01 |
| `PRIV-DATA-BREACH` | `PRIV-DATA-BREACH` | Data Breach | 8/3 | No credible match | Observed entry; task blocked | — |
| `PRIV-DATA-DELETION-LOG` | `PRIV-DATA-DELETION-LOG` | Data Deletion Log | 2/1 | No credible match | Observed entry; task blocked | RETENTION-EXEC-01 |
| `PRIV-DATA-RETENTION-POLICY` | `PRIV-DATA-RETENTION-POLICY` | Data Retention Policy | 6/3 | No credible match | Observed entry; task blocked | RETENTION-EXEC-01 |
| `PRIV-DATA-SUBJECT-REQUEST` | `PRIV-DATA-SUBJECT-REQUEST` | Data Subject Request | 10/2 | Medplum | Observed entry; task blocked | PRIV-DSR-01 |
| `PRIV-DPIA` | `PRIV-DPIA` | DPIA | 8/3 | No credible match | Observed entry; task blocked | — |
| `PRIV-GOVERNANCE-AUDIT-LOG` | `PRIV-GOVERNANCE-AUDIT-LOG` | Governance Audit Log | 2/1 | Medplum | Observed entry; task blocked | — |
| `PRIV-LEGACY-REDIRECTS` | `PRIV-LEGACY-REDIRECTS` | Legacy redirects | 1/0 | No credible match | Non-human/unsafe | — |
| `PRIV-LEGAL-HOLD` | `PRIV-LEGAL-HOLD` | Legal Hold | 6/2 | No credible match | Observed entry; task blocked | — |
| `PRIV-PRIVACY-ATTACHMENT` | `PRIV-PRIVACY-ATTACHMENT` | Privacy Attachment | 3/0 | No credible match | Source/blocked | — |
| `PRIV-PRIVACY-DASHBOARD` | `PRIV-PRIVACY-DASHBOARD` | Privacy Dashboard | 1/1 | No credible match | Observed entry; task blocked | — |
| `PRIV-PRIVACY-REPORT` | `PRIV-PRIVACY-REPORT` | Privacy Report | 3/1 | No credible match | Observed entry; task blocked | — |
| `PRIV-ROUTE-PRIVACY` | `PRIV-ROUTE-PRIVACY` | Privacy | 1/1 | No credible match | Observed entry; task blocked | PRIV-STATEMENT-01 |
| `PRIV-ROUTE-PRIVACY-POLICY` | `PRIV-ROUTE-PRIVACY-POLICY` | Privacy Policy | 1/0 | No credible match | Source/blocked | PRIV-STATEMENT-01 |

## Fleet and vehicles

Capabilities: 38; human: 37; retained exact finding links: `ARCH-P0-C`, `ASSET-RBAC-01`, `ARCH-P0-B`, `FLEET-TRANSPORT-01`, `FLEET-MED-WITNESS-01`.

| Capability | Legacy family | Submodule | Routes/pages | Benchmark disposition | Browser/source status | Findings |
|---|---|---|---:|---|---|---|
| `CAP-FLEET-INCIDENT-CLAIMS-FOLLOWUPS` | `FLEET-INCIDENT` | Fleet incident claims police reports and follow-ups | 4/1 | Pending capability-level adjudication | Source/blocked | — |
| `CAP-FLEET-INCIDENT-RECORD-EVIDENCE` | `FLEET-INCIDENT` | Fleet incident record and evidence | 8/1 | Pending capability-level adjudication | Observed entry; task blocked | — |
| `CAP-FLEET-INCIDENT-VEHICLE-RESPONSE` | `FLEET-INCIDENT` | Vehicle off-road back-in-service and incident status | 3/1 | Pending capability-level adjudication | Source/blocked | — |
| `FLEET-ALERT` | `FLEET-ALERT` | Alert | 4/2 | No credible match | Observed entry; task blocked | ARCH-P0-C |
| `FLEET-ASSET` | `FLEET-ASSET` | Asset | 6/4 | Snipe-IT | Observed entry; task blocked | ASSET-RBAC-01 |
| `FLEET-CHECKLIST` | `FLEET-CHECKLIST` | Checklist | 4/3 | No credible match | Observed entry; task blocked | — |
| `FLEET-COMMUNITY-ACCESS` | `FLEET-COMMUNITY-ACCESS` | Community Access | 1/1 | No credible match | Observed entry; task blocked | — |
| `FLEET-COMPLIANCE` | `FLEET-COMPLIANCE` | Compliance | 1/1 | No credible match | Observed entry; task blocked | — |
| `FLEET-COST-ALLOCATION` | `FLEET-COST-ALLOCATION` | Cost Allocation | 1/1 | No credible match | Observed entry; task blocked | — |
| `FLEET-DAILY-CHECK` | `FLEET-DAILY-CHECK` | Daily Check | 2/1 | No credible match | Observed entry; task blocked | — |
| `FLEET-DASHBOARD` | `FLEET-DASHBOARD` | Dashboard | 1/1 | No credible match | Observed entry; task blocked | — |
| `FLEET-DEVICE` | `FLEET-DEVICE` | Device | 7/1 | Traccar | Observed entry; task blocked | — |
| `FLEET-DRIVER` | `FLEET-DRIVER` | Driver | 3/2 | No credible match | Observed entry; task blocked | — |
| `FLEET-DRIVER-ELIGIBILITY` | `FLEET-DRIVER-ELIGIBILITY` | Driver Eligibility | 6/2 | No credible match | Observed entry; task blocked | — |
| `FLEET-FLEET-MAP-USAGE-DASHBOARD` | `FLEET-FLEET-MAP-USAGE-DASHBOARD` | Fleet Map Usage Dashboard | 1/1 | Traccar Web | Observed entry; task blocked | — |
| `FLEET-FLEET-TRIP` | `FLEET-FLEET-TRIP` | Fleet Trip | 5/1 | Traccar | Observed entry; task blocked | — |
| `FLEET-GEOFENCE` | `FLEET-GEOFENCE` | Geofence | 7/3 | Traccar Web | Observed entry; task blocked | — |
| `FLEET-HANDOVER` | `FLEET-HANDOVER` | Handover | 6/2 | Snipe-IT | Observed entry; task blocked | — |
| `FLEET-INSPECTION` | `FLEET-INSPECTION` | Inspection | 4/3 | No credible match | Observed entry; task blocked | — |
| `FLEET-KEY` | `FLEET-KEY` | Key | 4/1 | Snipe-IT | Observed entry; task blocked | — |
| `FLEET-LEGACY-REDIRECTS` | `FLEET-LEGACY-REDIRECTS` | Legacy redirects | 3/0 | No credible match | Non-human/unsafe | — |
| `FLEET-LIVE-MAP` | `FLEET-LIVE-MAP` | Live Map | 1/1 | Traccar Web | Observed entry; task blocked | — |
| `FLEET-MAINTENANCE-DASHBOARD` | `FLEET-MAINTENANCE-DASHBOARD` | Maintenance Dashboard | 1/1 | No credible match | Observed entry; task blocked | — |
| `FLEET-MILEAGE` | `FLEET-MILEAGE` | Mileage | 7/1 | Traccar | Observed entry; task blocked | — |
| `FLEET-MOBILE` | `FLEET-MOBILE` | Mobile | 1/1 | No credible match | Source/blocked | — |
| `FLEET-OUTING` | `FLEET-OUTING` | Outing | 9/3 | No credible match | Observed entry; task blocked | — |
| `FLEET-REPORT` | `FLEET-REPORT` | Report | 5/3 | No credible match | Observed entry; task blocked | — |
| `FLEET-RESIDENT-TRACKING` | `FLEET-RESIDENT-TRACKING` | Resident Tracking | 7/2 | Traccar Web | Observed entry; task blocked | ARCH-P0-B |
| `FLEET-RESIDENT-TRANSPORT` | `FLEET-RESIDENT-TRANSPORT` | Resident Transport | 11/5 | No credible match | Observed entry; task blocked | FLEET-TRANSPORT-01, FLEET-MED-WITNESS-01 |
| `FLEET-ROUTE-FLEET-ASSETS-SETTINGS-NOTIFICATIONS` | `FLEET-ROUTE-FLEET-ASSETS-SETTINGS-NOTIFICATIONS` | Fleet Assets Settings Notifications | 1/1 | No credible match | Observed entry; task blocked | — |
| `FLEET-ROUTE-FLEET-TRIPS-PLAYBACK` | `FLEET-ROUTE-FLEET-TRIPS-PLAYBACK` | Fleet Trips Playback | 1/0 | Traccar | Source/blocked | — |
| `FLEET-ROUTE-FLEET-TRIPS-SHOW` | `FLEET-ROUTE-FLEET-TRIPS-SHOW` | Fleet Trips Show | 1/0 | Traccar | Source/blocked | — |
| `FLEET-ROUTE-FLEET-VEHICLES-SHOW` | `FLEET-ROUTE-FLEET-VEHICLES-SHOW` | Fleet Vehicles Show | 1/0 | No credible match | Source/blocked | — |
| `FLEET-SERVICE-SCHEDULE` | `FLEET-SERVICE-SCHEDULE` | Service Schedule | 4/1 | No credible match | Observed entry; task blocked | — |
| `FLEET-VEHICLE` | `FLEET-VEHICLE` | Vehicle | 10/5 | Snipe-IT | Observed entry; task blocked | — |
| `FLEET-VEHICLE-BOOKING` | `FLEET-VEHICLE-BOOKING` | Vehicle Booking | 9/3 | No credible match | Observed entry; task blocked | — |
| `FLEET-WANDERING-ALERT` | `FLEET-WANDERING-ALERT` | Wandering Alert | 1/0 | Traccar Web | Source/blocked | — |
| `FLEET-WORK-ORDER` | `FLEET-WORK-ORDER` | Work Order | 6/3 | No credible match | Observed entry; task blocked | — |

## Assets and equipment

Capabilities: 14; human: 13; retained exact finding links: `ASSET-RBAC-01`.

| Capability | Legacy family | Submodule | Routes/pages | Benchmark disposition | Browser/source status | Findings |
|---|---|---|---:|---|---|---|
| `ASSET-ASSET` | `ASSET-ASSET` | Asset | 1/0 | Snipe-IT | Source/blocked | ASSET-RBAC-01 |
| `ASSET-ASSET-ASSIGNMENT` | `ASSET-ASSET-ASSIGNMENT` | Asset Assignment | 2/0 | Snipe-IT | Source/blocked | — |
| `ASSET-ASSET-DOCUMENT` | `ASSET-ASSET-DOCUMENT` | Asset Document | 3/0 | Snipe-IT | Source/blocked | — |
| `ASSET-ASSET-GEOFENCE` | `ASSET-ASSET-GEOFENCE` | Asset Geofence | 2/0 | Traccar Web | Source/blocked | — |
| `ASSET-ASSET-INSPECTION` | `ASSET-ASSET-INSPECTION` | Asset Inspection | 1/0 | No credible match | Source/blocked | — |
| `ASSET-ASSET-MAINTENANCE` | `ASSET-ASSET-MAINTENANCE` | Asset Maintenance | 1/0 | No credible match | Source/blocked | — |
| `ASSET-ASSET-OWNERSHIP` | `ASSET-ASSET-OWNERSHIP` | Asset Ownership | 1/0 | Snipe-IT | Source/blocked | — |
| `ASSET-ASSET-QR` | `ASSET-ASSET-QR` | Asset Qr | 4/0 | Snipe-IT | Source/blocked | — |
| `ASSET-ASSET-REPORT` | `ASSET-ASSET-REPORT` | Asset Report | 1/1 | Snipe-IT | Observed entry; task blocked | — |
| `ASSET-ASSET-SCAN-EVENT` | `ASSET-ASSET-SCAN-EVENT` | Asset Scan Event | 1/0 | Snipe-IT | Source/blocked | — |
| `ASSET-ASSET-TRACKER` | `ASSET-ASSET-TRACKER` | Asset Tracker | 2/0 | Snipe-IT | Source/blocked | — |
| `ASSET-FIXED-ASSET` | `ASSET-FIXED-ASSET` | Fixed Asset | 6/2 | ERPNext | Observed entry; task blocked | — |
| `ASSET-LEGACY-REDIRECTS` | `ASSET-LEGACY-REDIRECTS` | Legacy redirects | 4/0 | No credible match | Non-human/unsafe | — |
| `ASSET-ROUTE-ASSETS-SHOW` | `ASSET-ROUTE-ASSETS-SHOW` | Assets Show | 1/0 | No credible match | Source/blocked | — |

## Sites, facilities and catering

Capabilities: 58; human: 52; retained exact finding links: `SITE-RBAC-001`, `SITE-CHECK-002`, `SITE-CHECK-003`, `SITE-MEAL-CLIN-01`, `CATER-SCOPE-003`, `CATER-STOCK-002`.

| Capability | Legacy family | Submodule | Routes/pages | Benchmark disposition | Browser/source status | Findings |
|---|---|---|---:|---|---|---|
| `CAP-SITE-SITE-ACTIVATION-ARCHIVE` | `SITE-SITE` | Site activation archive restore and bulk archive | 4/30 | Exact static finding link; runtime blocked | Source/blocked | SITE-RBAC-001 |
| `CAP-SITE-SITE-CALENDAR-EVENT-PLANNING` | `SITE-SITE-CALENDAR` | Site calendar event creation update approval and exceptions | 9/4 | Pending capability-level adjudication | Observed entry; task blocked | — |
| `CAP-SITE-SITE-CALENDAR-FEEDS` | `SITE-SITE-CALENDAR` | Calendar feeds views and reset | 4/4 | Pending capability-level adjudication | Observed entry; task blocked | — |
| `CAP-SITE-SITE-CHECKLIST-ASSIGNMENT-RUN-CREATION` | `SITE-SITE-CHECKLIST` | Site checklist assignment removal and run creation | 4/1 | Pending capability-level adjudication | Source/blocked | SITE-CHECK-002, SITE-CHECK-003 |
| `CAP-SITE-SITE-CHECKLIST-RUN-EXECUTION` | `SITE-SITE-CHECKLIST` | Checklist run response completion skip recovery and reschedule | 6/1 | Pending capability-level adjudication | Source/blocked | SITE-CHECK-002, SITE-CHECK-003 |
| `CAP-SITE-SITE-COMPLIANCE-CERTIFICATIONS` | `SITE-SITE-COMPLIANCE` | Site certification records | 4/3 | Pending capability-level adjudication | Source/blocked | — |
| `CAP-SITE-SITE-COMPLIANCE-CHECKS` | `SITE-SITE-COMPLIANCE` | Site compliance checks and completion | 3/1 | Pending capability-level adjudication | Source/blocked | — |
| `CAP-SITE-SITE-COMPLIANCE-COVERAGE-STAFF` | `SITE-SITE-COMPLIANCE` | Site coverage and staff compliance requirements | 6/1 | Pending capability-level adjudication | Source/blocked | — |
| `CAP-SITE-SITE-COMPLIANCE-FEEDBACK` | `SITE-SITE-COMPLIANCE` | Site compliance feedback and response | 4/2 | Pending capability-level adjudication | Source/blocked | — |
| `CAP-SITE-SITE-CREDENTIAL-LIBRARY-AUDIT` | `SITE-SITE-CREDENTIAL` | Site credential library copy and audit | 6/1 | Pending capability-level adjudication | Source/blocked | — |
| `CAP-SITE-SITE-CREDENTIAL-REVEAL-TOTP` | `SITE-SITE-CREDENTIAL` | Credential reveal TOTP and access proof | 3/1 | Pending capability-level adjudication | Source/blocked | — |
| `CAP-SITE-SITE-CREDENTIAL-ROTATION-REAUTH` | `SITE-SITE-CREDENTIAL` | Credential rotation and reauthentication controls | 2/1 | Pending capability-level adjudication | Source/blocked | — |
| `CAP-SITE-SITE-INTEGRATION-CONNECTION-SECRETS` | `SITE-SITE-INTEGRATION` | Site integration connection configuration secrets and testing | 4/0 | Pending capability-level adjudication | Source/blocked | — |
| `CAP-SITE-SITE-INTEGRATION-SYNC-OVERRIDES` | `SITE-SITE-INTEGRATION` | Site integration synchronization events and overrides | 4/0 | Pending capability-level adjudication | Source/blocked | — |
| `CAP-SITE-SITE-MEAL-PLAN-RESIDENT-SUITABILITY` | `SITE-SITE-MEAL-PLAN` | Resident meal settings and conflict checks | 3/0 | Pending capability-level adjudication | Source/blocked | SITE-MEAL-CLIN-01, CATER-SCOPE-003, CATER-STOCK-002 |
| `CAP-SITE-SITE-MEAL-PLAN-SERVICE-VENDORS` | `SITE-SITE-MEAL-PLAN` | Meal service status and takeaway vendors | 3/0 | Pending capability-level adjudication | Source/blocked | SITE-MEAL-CLIN-01, CATER-SCOPE-003, CATER-STOCK-002 |
| `CAP-SITE-SITE-MEAL-PLAN-WEEKLY-PLANNING` | `SITE-SITE-MEAL-PLAN` | Weekly meal planning copy clear and summary | 8/0 | Pending capability-level adjudication | Source/blocked | SITE-MEAL-CLIN-01, CATER-SCOPE-003, CATER-STOCK-002 |
| `CAP-SITE-SITE-PROFILE-ONBOARDING` | `SITE-SITE` | Site profile location contact safety and onboarding | 10/2 | Exact static finding link; runtime blocked | Observed entry; task blocked | SITE-RBAC-001 |
| `CAP-SITE-SITE-ROOM-ASSET-OCCUPANCY` | `SITE-SITE-ROOM` | Room asset custody resident assignment and door card | 4/1 | Pending capability-level adjudication | Source/blocked | — |
| `CAP-SITE-SITE-ROOM-ROOM-LAYOUT` | `SITE-SITE-ROOM` | Room record lifecycle ordering defaults and restoration | 7/3 | Pending capability-level adjudication | Source/blocked | — |
| `SITE-BUDGET-FORECAST-API` | `SITE-BUDGET-FORECAST-API` | Budget Forecast Api | 7/0 | No credible match | Source/blocked | — |
| `SITE-CHECKLISTS-DASHBOARD` | `SITE-CHECKLISTS-DASHBOARD` | Checklists Dashboard | 1/1 | No credible match | Observed entry; task blocked | — |
| `SITE-CREDENTIAL-TYPE` | `SITE-CREDENTIAL-TYPE` | Credential Type | 2/0 | No credible match | Source/blocked | — |
| `SITE-DASHBOARD` | `SITE-DASHBOARD` | Dashboard | 2/1 | No credible match | Observed entry; task blocked | — |
| `SITE-DIETARY-TAG` | `SITE-DIETARY-TAG` | Dietary Tag | 4/0 | No credible match | Source/blocked | SITE-MEAL-CLIN-01 |
| `SITE-FRONTEND-CATERING-PRODUCTS` | `SITE-FRONTEND-CATERING-PRODUCTS` | Catering/Products | 0/1 | Grocy | Non-human/unsafe | — |
| `SITE-FRONTEND-CATERING-RECIPES` | `SITE-FRONTEND-CATERING-RECIPES` | Catering/Recipes | 0/3 | Mealie | Non-human/unsafe | — |
| `SITE-FRONTEND-CATERING-TABS` | `SITE-FRONTEND-CATERING-TABS` | Catering/ Tabs | 0/2 | No credible match | Non-human/unsafe | — |
| `SITE-FRONTEND-CATERING-TAGS` | `SITE-FRONTEND-CATERING-TAGS` | Catering/Tags | 0/1 | No credible match | Non-human/unsafe | — |
| `SITE-FRONTEND-SITES-CAPACITY` | `SITE-FRONTEND-SITES-CAPACITY` | Sites/Capacity | 0/1 | No credible match | Non-human/unsafe | — |
| `SITE-HOUSE-LEDGER` | `SITE-HOUSE-LEDGER` | House Ledger | 6/1 | No credible match | Source/blocked | — |
| `SITE-LEGACY-REDIRECTS` | `SITE-LEGACY-REDIRECTS` | Legacy redirects | 1/0 | No credible match | Non-human/unsafe | — |
| `SITE-PRODUCT` | `SITE-PRODUCT` | Product | 4/0 | Grocy | Source/blocked | — |
| `SITE-QUALITY-CHECKLIST` | `SITE-QUALITY-CHECKLIST` | Quality Checklist | 1/1 | No credible match | Observed entry; task blocked | — |
| `SITE-RECIPE` | `SITE-RECIPE` | Recipe | 7/0 | Mealie | Source/blocked | CATER-SCOPE-003 |
| `SITE-ROUTE-SITES-CHECKLISTS-RUNS` | `SITE-ROUTE-SITES-CHECKLISTS-RUNS` | Sites Checklists Runs | 1/0 | No credible match | Source/blocked | — |
| `SITE-ROUTE-SITES-CHECKLISTS-SHOW-RUN` | `SITE-ROUTE-SITES-CHECKLISTS-SHOW-RUN` | Sites Checklists Showrun | 1/0 | No credible match | Source/blocked | — |
| `SITE-ROUTE-SITES-HOUSE-CHECKLISTS-INDEX` | `SITE-ROUTE-SITES-HOUSE-CHECKLISTS-INDEX` | Sites House Checklists Index | 1/0 | No credible match | Source/blocked | — |
| `SITE-SITE-CHECKLIST-TEMPLATE` | `SITE-SITE-CHECKLIST-TEMPLATE` | Site Checklist Template | 3/0 | No credible match | Source/blocked | — |
| `SITE-SITE-CONTACT` | `SITE-SITE-CONTACT` | Site Contact | 3/0 | No credible match | Source/blocked | — |
| `SITE-SITE-DAMAGE` | `SITE-SITE-DAMAGE` | Site Damage | 4/1 | No credible match | Source/blocked | SITE-CHECK-003 |
| `SITE-SITE-DOCUMENT` | `SITE-SITE-DOCUMENT` | Site Document | 6/1 | No credible match | Source/blocked | — |
| `SITE-SITE-EMERGENCY-PLAN` | `SITE-SITE-EMERGENCY-PLAN` | Site Emergency Plan | 3/1 | No credible match | Source/blocked | — |
| `SITE-SITE-FINANCIAL-DASHBOARD` | `SITE-SITE-FINANCIAL-DASHBOARD` | Site Financial Dashboard | 1/1 | No credible match | Source/blocked | — |
| `SITE-SITE-GEOFENCE` | `SITE-SITE-GEOFENCE` | Site Geofence | 3/0 | No credible match | Source/blocked | — |
| `SITE-SITE-HARDWARE` | `SITE-SITE-HARDWARE` | Site Hardware | 5/1 | NetBox | Source/blocked | — |
| `SITE-SITE-INSPECTION` | `SITE-SITE-INSPECTION` | Site Inspection | 5/2 | No credible match | Observed entry; task blocked | — |
| `SITE-SITE-MEAL-INVENTORY` | `SITE-SITE-MEAL-INVENTORY` | Site Meal Inventory | 7/0 | Grocy | Source/blocked | CATER-STOCK-002 |
| `SITE-SITE-MEAL-SHOPPING-LIST` | `SITE-SITE-MEAL-SHOPPING-LIST` | Site Meal Shopping List | 7/0 | Mealie | Source/blocked | — |
| `SITE-SITE-MEAL-WEEK-TEMPLATE` | `SITE-SITE-MEAL-WEEK-TEMPLATE` | Site Meal Week Template | 5/0 | Mealie | Source/blocked | — |
| `SITE-SITE-NOTE` | `SITE-SITE-NOTE` | Site Note | 2/0 | No credible match | Source/blocked | — |
| `SITE-SITE-REPORTING` | `SITE-SITE-REPORTING` | Site Reporting | 10/8 | No credible match | Observed entry; task blocked | — |
| `SITE-SITE-RESOURCE` | `SITE-SITE-RESOURCE` | Site Resource | 4/1 | NetBox | Source/blocked | — |
| `SITE-SITE-TYPE-PLAN` | `SITE-SITE-TYPE-PLAN` | Site Type Plan | 6/12 | No credible match | Source/blocked | — |
| `SITE-SITE-TYPE-PLAN-PIN` | `SITE-SITE-TYPE-PLAN-PIN` | Site Type Plan Pin | 3/0 | No credible match | Source/blocked | — |
| `SITE-SITE-VENDOR` | `SITE-SITE-VENDOR` | Site Vendor | 7/5 | Snipe-IT | Observed entry; task blocked | — |
| `SITE-SITE-ZONE` | `SITE-SITE-ZONE` | Site Zone | 4/1 | NetBox | Source/blocked | — |
| `SITE-SITES-FINANCIAL-OVERVIEW` | `SITE-SITES-FINANCIAL-OVERVIEW` | Sites Financial Overview | 1/1 | No credible match | Observed entry; task blocked | — |

## Security and devices

Capabilities: 25; human: 21; retained exact finding links: `ARCH-P0-B`, `SEC-PROV-003`, `SEC-HEALTH-004`, `SEC-UNIFI-TLS-01`, `ARCH-P0-C`.

| Capability | Legacy family | Submodule | Routes/pages | Benchmark disposition | Browser/source status | Findings |
|---|---|---|---:|---|---|---|
| `CAP-SEC-DEVICE-IDENTITY-ATTRIBUTES` | `SEC-DEVICE` | Security-device identity lifecycle and fields | 8/5 | Pending capability-level adjudication | Observed entry; task blocked | ARCH-P0-B, SEC-PROV-003 |
| `CAP-SEC-DEVICE-LINKAGE` | `SEC-DEVICE` | Security-device asset and related-device linkage | 4/5 | Pending capability-level adjudication | Source/blocked | ARCH-P0-B, SEC-PROV-003 |
| `CAP-SEC-QUECLINK-HUB-COMMANDS-DIAGNOSTICS` | `SEC-QUECLINK-HUB` | Queclink commands frames and live stream | 5/1 | Pending capability-level adjudication | Observed entry; task blocked | — |
| `CAP-SEC-QUECLINK-HUB-CONFIGURATION-SAFETY` | `SEC-QUECLINK-HUB` | Queclink configuration and resident safety profiles | 7/1 | Pending capability-level adjudication | Observed entry; task blocked | — |
| `CAP-SEC-QUECLINK-HUB-DEVICE-CUSTODY` | `SEC-QUECLINK-HUB` | Queclink device claim rejection release and bulk control | 5/1 | Pending capability-level adjudication | Observed entry; task blocked | — |
| `CAP-SEC-QUECLINK-HUB-PRESETS-SETTINGS` | `SEC-QUECLINK-HUB` | Queclink presets and hub settings | 4/1 | Pending capability-level adjudication | Source/blocked | — |
| `CAP-SEC-UNIFI-CREDENTIALS` | `SEC-UNIFI` | UniFi key setup testing and rotation | 3/1 | Pending capability-level adjudication | Source/blocked | SEC-HEALTH-004, SEC-PROV-003, SEC-UNIFI-TLS-01 |
| `CAP-SEC-UNIFI-DEFAULTS-HARDWARE` | `SEC-UNIFI` | UniFi defaults and hardware-room assignment | 3/1 | Pending capability-level adjudication | Observed entry; task blocked | SEC-HEALTH-004, SEC-PROV-003, SEC-UNIFI-TLS-01 |
| `CAP-SEC-UNIFI-DISCOVERY-SYNC` | `SEC-UNIFI` | UniFi site and device synchronization | 2/1 | Pending capability-level adjudication | Source/blocked | SEC-HEALTH-004, SEC-PROV-003, SEC-UNIFI-TLS-01 |
| `CAP-SEC-UNIFI-SITE-MAPPING` | `SEC-UNIFI` | UniFi site mapping and removal | 2/1 | Pending capability-level adjudication | Source/blocked | SEC-HEALTH-004, SEC-PROV-003, SEC-UNIFI-TLS-01 |
| `SEC-ALERTS-EVENTS` | `SEC-ALERTS-EVENTS` | Alerts Events | 1/1 | ThingsBoard | Observed entry; task blocked | ARCH-P0-C |
| `SEC-ASSET-TELEMETRY-INGEST` | `SEC-ASSET-TELEMETRY-INGEST` | Asset Telemetry Ingest | 2/0 | ThingsBoard | Non-human/unsafe | — |
| `SEC-CATEGORY-PAGE` | `SEC-CATEGORY-PAGE` | Category Page | 7/2 | Eclipse Ditto | Observed entry; task blocked | — |
| `SEC-DASHBOARD` | `SEC-DASHBOARD` | Dashboard | 1/1 | No credible match | Observed entry; task blocked | — |
| `SEC-DEVICE-ASSIGNMENT` | `SEC-DEVICE-ASSIGNMENT` | Device Assignment | 3/0 | Eclipse Ditto | Source/blocked | ARCH-P0-B |
| `SEC-DEVICE-DOCUMENT` | `SEC-DEVICE-DOCUMENT` | Device Document | 3/0 | Eclipse Ditto | Source/blocked | — |
| `SEC-DEVICE-GROUP` | `SEC-DEVICE-GROUP` | Device Group | 11/4 | Eclipse Ditto | Observed entry; task blocked | — |
| `SEC-FRONTEND-SECURITY-DEVICES` | `SEC-FRONTEND-SECURITY-DEVICES` | Security Devices | 0/1 | Eclipse Ditto | Non-human/unsafe | — |
| `SEC-FRONTEND-SECURITY-DEVICES-SECTION` | `SEC-FRONTEND-SECURITY-DEVICES-SECTION` | Security Devices/Section | 0/1 | Eclipse Ditto | Non-human/unsafe | — |
| `SEC-FRONTEND-SECURITY-DEVICES-SECURITY-DEVICES-SHELL` | `SEC-FRONTEND-SECURITY-DEVICES-SECURITY-DEVICES-SHELL` | Security Devices/Security Devices Shell | 0/1 | Eclipse Ditto | Non-human/unsafe | — |
| `SEC-INTEGRATIONS-HUB` | `SEC-INTEGRATIONS-HUB` | Integrations Hub | 1/1 | No credible match | Observed entry; task blocked | — |
| `SEC-MAINTENANCE-HEALTH` | `SEC-MAINTENANCE-HEALTH` | Maintenance Health | 4/1 | No credible match | Observed entry; task blocked | SEC-HEALTH-004 |
| `SEC-MILESIGHT` | `SEC-MILESIGHT` | Milesight | 5/1 | No credible match | Observed entry; task blocked | — |
| `SEC-QUECLINK` | `SEC-QUECLINK` | Queclink | 4/1 | No credible match | Source/blocked | — |
| `SEC-REPORTS` | `SEC-REPORTS` | Reports | 4/1 | No credible match | Observed entry; task blocked | — |

## Control Room

Capabilities: 29; human: 28; retained exact finding links: `CTRL-RBAC-001`, `SAFE-TERMINAL-SYNC-01`, `VIS-DEPLOYED-DRIFT-01`, `VIS-CR-SETTINGS-NAMES-01`, `VIS-OVERLAY-FOCUS-01`, `ARCH-P0-C`, `CTRL-SIGNAL-002`, `ARCH-P0-B`, `ARCH-P0-A`, `INCIDENT-ALERT-LIFECYCLE-01`.

| Capability | Legacy family | Submodule | Routes/pages | Benchmark disposition | Browser/source status | Findings |
|---|---|---|---:|---|---|---|
| `CAP-CR-CONTROL-ROOM-ALERT-INTAKE-TRIAGE` | `CR-CONTROL-ROOM-ALERT` | Alert intake triage and enrichment | 7/1 | Pending capability-level adjudication | Observed entry; task blocked | CTRL-RBAC-001, SAFE-TERMINAL-SYNC-01, VIS-DEPLOYED-DRIFT-01 |
| `CAP-CR-CONTROL-ROOM-ALERT-OWNERSHIP` | `CR-CONTROL-ROOM-ALERT` | Alert ownership and bulk assignment | 4/1 | Pending capability-level adjudication | Source/blocked | CTRL-RBAC-001, SAFE-TERMINAL-SYNC-01, VIS-DEPLOYED-DRIFT-01 |
| `CAP-CR-CONTROL-ROOM-ALERT-RESPONSE-CLOSURE` | `CR-CONTROL-ROOM-ALERT` | Alert response escalation and closure | 6/1 | Pending capability-level adjudication | Source/blocked | CTRL-RBAC-001, SAFE-TERMINAL-SYNC-01, VIS-DEPLOYED-DRIFT-01 |
| `CAP-CR-CONTROL-ROOM-SETTINGS-CONFIGURATION-OPTIONS` | `CR-CONTROL-ROOM-SETTINGS` | Control Room configuration options | 4/1 | Pending capability-level adjudication | Observed entry; task blocked | VIS-CR-SETTINGS-NAMES-01, VIS-OVERLAY-FOCUS-01 |
| `CAP-CR-CONTROL-ROOM-SETTINGS-MAINTENANCE-WINDOWS` | `CR-CONTROL-ROOM-SETTINGS` | Control Room maintenance windows | 3/1 | Pending capability-level adjudication | Source/blocked | VIS-CR-SETTINGS-NAMES-01, VIS-OVERLAY-FOCUS-01 |
| `CAP-CR-CONTROL-ROOM-SETTINGS-OUTBOX-RECOVERY` | `CR-CONTROL-ROOM-SETTINGS` | Failed signal outbox recovery | 1/1 | Pending capability-level adjudication | Source/blocked | VIS-CR-SETTINGS-NAMES-01, VIS-OVERLAY-FOCUS-01 |
| `CAP-CR-CONTROL-ROOM-SETTINGS-RESPONSE-QUEUES` | `CR-CONTROL-ROOM-SETTINGS` | Control Room response queues | 2/1 | Pending capability-level adjudication | Source/blocked | VIS-CR-SETTINGS-NAMES-01, VIS-OVERLAY-FOCUS-01 |
| `CAP-CR-CONTROL-ROOM-SETTINGS-SIGNAL-RULES` | `CR-CONTROL-ROOM-SETTINGS` | Signal routing rules | 3/1 | Pending capability-level adjudication | Source/blocked | VIS-CR-SETTINGS-NAMES-01, VIS-OVERLAY-FOCUS-01 |
| `CR-ALERT` | `CR-ALERT` | Alert | 4/1 | OneUptime Community | Observed entry; task blocked | ARCH-P0-C, CTRL-SIGNAL-002, VIS-DEPLOYED-DRIFT-01 |
| `CR-CONTROL-ROOM-BROADCAST` | `CR-CONTROL-ROOM-BROADCAST` | Control Room Broadcast | 3/2 | No credible match | Observed entry; task blocked | — |
| `CR-CONTROL-ROOM-DASHBOARD` | `CR-CONTROL-ROOM-DASHBOARD` | Control Room Dashboard | 1/1 | No credible match | Observed entry; task blocked | — |
| `CR-CONTROL-ROOM-DEVICE` | `CR-CONTROL-ROOM-DEVICE` | Control Room Device | 2/2 | Eclipse Ditto | Observed entry; task blocked | ARCH-P0-B |
| `CR-CONTROL-ROOM-DISCUSSION` | `CR-CONTROL-ROOM-DISCUSSION` | Control Room Discussion | 4/0 | No credible match | Source/blocked | ARCH-P0-A |
| `CR-CONTROL-ROOM-ESCALATION` | `CR-CONTROL-ROOM-ESCALATION` | Control Room Escalation | 5/1 | OneUptime Community | Observed entry; task blocked | ARCH-P0-A |
| `CR-CONTROL-ROOM-EVIDENCE` | `CR-CONTROL-ROOM-EVIDENCE` | Control Room Evidence | 7/0 | No credible match | Source/blocked | ARCH-P0-A |
| `CR-CONTROL-ROOM-HANDOVER` | `CR-CONTROL-ROOM-HANDOVER` | Control Room Handover | 1/1 | No credible match | Observed entry; task blocked | — |
| `CR-CONTROL-ROOM-INCIDENT` | `CR-CONTROL-ROOM-INCIDENT` | Control Room Incident | 3/1 | OneUptime Community | Observed entry; task blocked | INCIDENT-ALERT-LIFECYCLE-01 |
| `CR-CONTROL-ROOM-MAP` | `CR-CONTROL-ROOM-MAP` | Control Room Map | 1/1 | No credible match | Observed entry; task blocked | — |
| `CR-CONTROL-ROOM-MESSAGING` | `CR-CONTROL-ROOM-MESSAGING` | Control Room Messaging | 4/1 | No credible match | Observed entry; task blocked | — |
| `CR-CONTROL-ROOM-MY-TASKS` | `CR-CONTROL-ROOM-MY-TASKS` | Control Room My Tasks | 2/1 | OpenProject Community | Observed entry; task blocked | — |
| `CR-CONTROL-ROOM-PLAYBOOK` | `CR-CONTROL-ROOM-PLAYBOOK` | Control Room Playbook | 8/2 | OpenProject Community | Observed entry; task blocked | ARCH-P0-A |
| `CR-CONTROL-ROOM-REPORT` | `CR-CONTROL-ROOM-REPORT` | Control Room Report | 6/1 | No credible match | Observed entry; task blocked | — |
| `CR-CONTROL-ROOM-SHIFT` | `CR-CONTROL-ROOM-SHIFT` | Control Room Shift | 5/1 | No credible match | Observed entry; task blocked | — |
| `CR-CONTROL-ROOM-SLA` | `CR-CONTROL-ROOM-SLA` | Control Room Sla | 5/2 | OneUptime Community | Observed entry; task blocked | — |
| `CR-CONTROL-ROOM-STATS` | `CR-CONTROL-ROOM-STATS` | Control Room Stats | 1/1 | No credible match | Source/blocked | — |
| `CR-CONTROL-ROOM-TASK` | `CR-CONTROL-ROOM-TASK` | Control Room Task | 6/0 | OpenProject Community | Source/blocked | ARCH-P0-A |
| `CR-CONTROL-ROOM-TIME-ENTRY` | `CR-CONTROL-ROOM-TIME-ENTRY` | Control Room Time Entry | 5/0 | No credible match | Source/blocked | ARCH-P0-A |
| `CR-CONTROL-ROOM-WATCHER` | `CR-CONTROL-ROOM-WATCHER` | Control Room Watcher | 4/0 | No credible match | Source/blocked | ARCH-P0-A |
| `CR-LEGACY-REDIRECTS` | `CR-LEGACY-REDIRECTS` | Legacy redirects | 1/0 | No credible match | Non-human/unsafe | — |

## IT and service support

Capabilities: 1; human: 1; retained exact finding links: none.

| Capability | Legacy family | Submodule | Routes/pages | Benchmark disposition | Browser/source status | Findings |
|---|---|---|---:|---|---|---|
| `IT-IT-PROVISIONING` | `IT-IT-PROVISIONING` | It Provisioning | 7/1 | No credible match | Observed entry; task blocked | — |

## Finance and funding

Capabilities: 51; human: 50; retained exact finding links: `GOV-NESTED-01`, `FIN-BANK-RECON-01`, `FIN-GST-01`, `FIN-CONSOLIDATION-01`, `FIN-DONOR-FUND-01`, `VIS-HERO-DENSITY-01`, `FIN-GL-RECURRING-01`, `FIN-GL-REVERSAL-01`, `FIN-PAYMENT-MATCH-01`, `FIN-SETTLEMENT-01`.

| Capability | Legacy family | Submodule | Routes/pages | Benchmark disposition | Browser/source status | Findings |
|---|---|---|---:|---|---|---|
| `CAP-FIN-CONTROLLERS-BUDGET-ACTUALS-OVERSIGHT` | `FIN-CONTROLLERS-BUDGET` | Budget actuals recording and oversight | 3/4 | Pending capability-level adjudication | Observed entry; task blocked | GOV-NESTED-01 |
| `CAP-FIN-CONTROLLERS-BUDGET-APPROVAL-ADJUSTMENT` | `FIN-CONTROLLERS-BUDGET` | Budget proposal approval and adjustment decisions | 5/4 | Pending capability-level adjudication | Source/blocked | GOV-NESTED-01 |
| `CAP-FIN-CONTROLLERS-BUDGET-DESIGN` | `FIN-CONTROLLERS-BUDGET` | Budget structure line items and allocations | 10/4 | Pending capability-level adjudication | Observed entry; task blocked | GOV-NESTED-01 |
| `FIN-ACCOUNTING-INTEGRATION` | `FIN-ACCOUNTING-INTEGRATION` | Accounting Integration | 8/2 | ERPNext | Observed entry; task blocked | — |
| `FIN-ACCOUNTS-RECEIVABLE` | `FIN-ACCOUNTS-RECEIVABLE` | Accounts Receivable | 4/3 | ERPNext | Observed entry; task blocked | — |
| `FIN-BANK-ACCOUNT` | `FIN-BANK-ACCOUNT` | Bank Account | 4/2 | Bigcapital | Observed entry; task blocked | — |
| `FIN-BANK-FEED` | `FIN-BANK-FEED` | Bank Feed | 6/2 | Bigcapital | Observed entry; task blocked | — |
| `FIN-BANK-RECONCILIATION` | `FIN-BANK-RECONCILIATION` | Bank Reconciliation | 7/3 | Bigcapital | Observed entry; task blocked | FIN-BANK-RECON-01 |
| `FIN-BANK-TRANSACTION` | `FIN-BANK-TRANSACTION` | Bank Transaction | 3/1 | Bigcapital | Observed entry; task blocked | FIN-BANK-RECON-01 |
| `FIN-BANKING` | `FIN-BANKING` | Banking | 1/0 | Bigcapital | Source/blocked | — |
| `FIN-BILL` | `FIN-BILL` | Bill | 8/4 | ERPNext | Observed entry; task blocked | FIN-GST-01 |
| `FIN-BILLING` | `FIN-BILLING` | Billing | 2/2 | ERPNext | Observed entry; task blocked | — |
| `FIN-BUDGET-ACTUALS` | `FIN-BUDGET-ACTUALS` | Budget Actuals | 2/1 | No credible match | Observed entry; task blocked | — |
| `FIN-CASH-FLOW-FORECAST` | `FIN-CASH-FLOW-FORECAST` | Cash Flow Forecast | 4/2 | No credible match | Observed entry; task blocked | — |
| `FIN-CASH-POSITION` | `FIN-CASH-POSITION` | Cash Position | 1/1 | No credible match | Observed entry; task blocked | — |
| `FIN-CHART-OF-ACCOUNTS` | `FIN-CHART-OF-ACCOUNTS` | Chart Of Accounts | 7/4 | ERPNext | Observed entry; task blocked | — |
| `FIN-CONSOLIDATION` | `FIN-CONSOLIDATION` | Consolidation | 10/3 | No credible match | Observed entry; task blocked | FIN-CONSOLIDATION-01 |
| `FIN-CONTROLLERS-BUDGET-448958` | `FIN-CONTROLLERS-BUDGET-448958` | Budget | 2/0 | No credible match | Source/blocked | — |
| `FIN-COST-CENTRE` | `FIN-COST-CENTRE` | Cost Centre | 4/1 | No credible match | Observed entry; task blocked | — |
| `FIN-CREDIT-NOTE` | `FIN-CREDIT-NOTE` | Credit Note | 4/2 | ERPNext | Observed entry; task blocked | FIN-GST-01 |
| `FIN-CURRENCY` | `FIN-CURRENCY` | Currency | 5/1 | No credible match | Observed entry; task blocked | — |
| `FIN-DONOR-FUND` | `FIN-DONOR-FUND` | Donor Fund | 9/2 | No credible match | Observed entry; task blocked | FIN-DONOR-FUND-01 |
| `FIN-EFTPOS` | `FIN-EFTPOS` | Eftpos | 7/3 | No credible match | Observed entry; task blocked | — |
| `FIN-EXECUTIVE-FINANCIAL-DASHBOARD` | `FIN-EXECUTIVE-FINANCIAL-DASHBOARD` | Executive Financial Dashboard | 1/1 | No credible match | Source/blocked | — |
| `FIN-FINANCE-CALENDAR` | `FIN-FINANCE-CALENDAR` | Finance Calendar | 2/1 | No credible match | Observed entry; task blocked | — |
| `FIN-FINANCE-DASHBOARD` | `FIN-FINANCE-DASHBOARD` | Finance Dashboard | 1/1 | No credible match | Observed entry; task blocked | VIS-HERO-DENSITY-01 |
| `FIN-FINANCIAL-REPORT` | `FIN-FINANCIAL-REPORT` | Financial Report | 7/7 | No credible match | Observed entry; task blocked | — |
| `FIN-FISCAL-PERIOD` | `FIN-FISCAL-PERIOD` | Fiscal Period | 4/1 | ERPNext | Observed entry; task blocked | — |
| `FIN-FUNDING-STREAM` | `FIN-FUNDING-STREAM` | Funding Stream | 4/1 | No credible match | Observed entry; task blocked | — |
| `FIN-FX-REVALUATION` | `FIN-FX-REVALUATION` | Fx Revaluation | 4/2 | No credible match | Observed entry; task blocked | — |
| `FIN-GST-RETURN` | `FIN-GST-RETURN` | Gst Return | 5/3 | No credible match | Observed entry; task blocked | FIN-GST-01 |
| `FIN-INTERCOMPANY` | `FIN-INTERCOMPANY` | Intercompany | 3/1 | No credible match | Source/blocked | FIN-CONSOLIDATION-01 |
| `FIN-INVOICE` | `FIN-INVOICE` | Invoice | 10/4 | ERPNext | Observed entry; task blocked | FIN-GST-01 |
| `FIN-IRD-FILING` | `FIN-IRD-FILING` | Ird Filing | 6/2 | No credible match | Observed entry; task blocked | — |
| `FIN-JOURNAL` | `FIN-JOURNAL` | Journal | 6/3 | Odoo Community | Observed entry; task blocked | FIN-GL-RECURRING-01, FIN-GL-REVERSAL-01 |
| `FIN-LEDGER` | `FIN-LEDGER` | Ledger | 1/0 | ERPNext | Source/blocked | — |
| `FIN-LEGACY-REDIRECTS` | `FIN-LEGACY-REDIRECTS` | Legacy redirects | 14/0 | No credible match | Non-human/unsafe | — |
| `FIN-MATCH-RULE` | `FIN-MATCH-RULE` | Match Rule | 4/1 | Bigcapital | Observed entry; task blocked | FIN-PAYMENT-MATCH-01 |
| `FIN-PAYABLES` | `FIN-PAYABLES` | Payables | 1/0 | No credible match | Source/blocked | — |
| `FIN-PAYMENT-ALLOCATION` | `FIN-PAYMENT-ALLOCATION` | Payment Allocation | 2/1 | No credible match | Observed entry; task blocked | — |
| `FIN-PAYMENT-MATCH` | `FIN-PAYMENT-MATCH` | Payment Match | 5/1 | Bigcapital | Observed entry; task blocked | FIN-PAYMENT-MATCH-01 |
| `FIN-PAYMENT-RUN` | `FIN-PAYMENT-RUN` | Payment Run | 7/3 | LedgerSMB | Observed entry; task blocked | FIN-SETTLEMENT-01 |
| `FIN-PETTY-CASH` | `FIN-PETTY-CASH` | Petty Cash | 4/2 | No credible match | Observed entry; task blocked | — |
| `FIN-PRICE-BOOK` | `FIN-PRICE-BOOK` | Price Book | 7/2 | No credible match | Observed entry; task blocked | — |
| `FIN-PURCHASE-ORDER` | `FIN-PURCHASE-ORDER` | Purchase Order | 8/4 | ERPNext | Observed entry; task blocked | — |
| `FIN-QUOTE` | `FIN-QUOTE` | Quote | 8/2 | Dolibarr | Observed entry; task blocked | — |
| `FIN-RECURRING-CHARGE` | `FIN-RECURRING-CHARGE` | Recurring Charge | 4/1 | No credible match | Observed entry; task blocked | — |
| `FIN-REPORTS` | `FIN-REPORTS` | Reports | 1/0 | No credible match | Source/blocked | — |
| `FIN-SETTINGS` | `FIN-SETTINGS` | Settings | 1/0 | No credible match | Source/blocked | — |
| `FIN-TAX` | `FIN-TAX` | Tax | 1/0 | ERPNext | Source/blocked | — |
| `FIN-VENDOR` | `FIN-VENDOR` | Vendor | 6/4 | No credible match | Observed entry; task blocked | — |

## Governance

Capabilities: 27; human: 25; retained exact finding links: `GOV-NESTED-01`.

| Capability | Legacy family | Submodule | Routes/pages | Benchmark disposition | Browser/source status | Findings |
|---|---|---|---:|---|---|---|
| `CAP-GOV-GOVERNANCE-MEETING-ATTENDANCE-RSVP` | `GOV-GOVERNANCE-MEETING` | Meeting attendance and RSVP | 2/5 | Pending capability-level adjudication | Source/blocked | GOV-NESTED-01 |
| `CAP-GOV-GOVERNANCE-MEETING-MINUTES-SIGNOFF` | `GOV-GOVERNANCE-MEETING` | Meeting minutes approval and signing | 4/5 | Pending capability-level adjudication | Source/blocked | GOV-NESTED-01 |
| `CAP-GOV-GOVERNANCE-MEETING-SCHEDULING-AGENDA` | `GOV-GOVERNANCE-MEETING` | Meeting scheduling calendar and agenda | 11/5 | Pending capability-level adjudication | Observed entry; task blocked | GOV-NESTED-01 |
| `CAP-GOV-GOVERNANCE-MEETING-STATUS-LOCK` | `GOV-GOVERNANCE-MEETING` | Meeting status advancement and lock | 2/5 | Pending capability-level adjudication | Source/blocked | GOV-NESTED-01 |
| `CAP-GOV-RESOLUTION-DRAFT-EVIDENCE` | `GOV-RESOLUTION` | Resolution drafting and attachments | 8/4 | Pending capability-level adjudication | Observed entry; task blocked | — |
| `CAP-GOV-RESOLUTION-FINALIZATION` | `GOV-RESOLUTION` | Resolution finalization | 1/4 | Pending capability-level adjudication | Source/blocked | — |
| `CAP-GOV-RESOLUTION-VOTING-CONFLICTS` | `GOV-RESOLUTION` | Resolution voting window and conflict declarations | 4/4 | Pending capability-level adjudication | Source/blocked | — |
| `CAP-GOV-RISK-REGISTER-ACCEPTANCE-CLOSURE` | `GOV-RISK-REGISTER` | Risk acceptance and closure | 2/7 | Pending capability-level adjudication | Source/blocked | — |
| `CAP-GOV-RISK-REGISTER-REGISTER-ANALYTICS` | `GOV-RISK-REGISTER` | Risk register event linkage and analytics | 10/7 | Pending capability-level adjudication | Observed entry; task blocked | — |
| `CAP-GOV-RISK-REGISTER-TREATMENTS-EVIDENCE` | `GOV-RISK-REGISTER` | Risk treatment actions and evidence | 4/7 | Pending capability-level adjudication | Observed entry; task blocked | — |
| `CAP-GOV-SPEND-APPROVAL-DECISION` | `GOV-SPEND-APPROVAL` | Spend approval decision | 2/4 | Pending capability-level adjudication | Source/blocked | — |
| `CAP-GOV-SPEND-APPROVAL-REQUEST-SUBMISSION` | `GOV-SPEND-APPROVAL` | Spend request drafting evidence and submission | 10/4 | Pending capability-level adjudication | Observed entry; task blocked | — |
| `GOV-ACTION-ITEM` | `GOV-ACTION-ITEM` | Action Item | 8/2 | OpenProject Community | Observed entry; task blocked | — |
| `GOV-BOARD-EVALUATION` | `GOV-BOARD-EVALUATION` | Board Evaluation | 8/4 | OpenSlides | Observed entry; task blocked | — |
| `GOV-BOARD-INTEREST` | `GOV-BOARD-INTEREST` | Board Interest | 4/2 | OpenSlides | Observed entry; task blocked | — |
| `GOV-BOARD-MEMBER-ADMIN` | `GOV-BOARD-MEMBER-ADMIN` | Board Member Admin | 4/1 | OpenSlides | Observed entry; task blocked | — |
| `GOV-BOARD-PACK` | `GOV-BOARD-PACK` | Board Pack | 10/3 | OpenSlides | Observed entry; task blocked | — |
| `GOV-CEO-BOARD-REPORT` | `GOV-CEO-BOARD-REPORT` | Ceo Board Report | 11/5 | OpenSlides | Observed entry; task blocked | — |
| `GOV-DASHBOARD` | `GOV-DASHBOARD` | Dashboard | 3/2 | OpenSlides | Observed entry; task blocked | — |
| `GOV-FRONTEND-GOVERNANCE-COMPONENTS-WIDGET-CARD` | `GOV-FRONTEND-GOVERNANCE-COMPONENTS-WIDGET-CARD` | Governance/Components/Widgetcard | 0/1 | No credible match | Non-human/unsafe | — |
| `GOV-GOVERNANCE-DOCUMENT` | `GOV-GOVERNANCE-DOCUMENT` | Governance Document | 5/2 | No credible match | Observed entry; task blocked | — |
| `GOV-GOVERNANCE-POLICY` | `GOV-GOVERNANCE-POLICY` | Governance Policy | 10/5 | No credible match | Observed entry; task blocked | — |
| `GOV-GOVERNANCE-SETTING` | `GOV-GOVERNANCE-SETTING` | Governance Setting | 2/1 | No credible match | Observed entry; task blocked | — |
| `GOV-REPORT` | `GOV-REPORT` | Report | 6/4 | No credible match | Observed entry; task blocked | — |
| `GOV-ROUTE-GOVERNANCE-DASHBOARD-LEGACY` | `GOV-ROUTE-GOVERNANCE-DASHBOARD-LEGACY` | Governance Dashboard Legacy | 1/0 | OpenSlides | Non-human/unsafe | — |
| `GOV-STRATEGIC-PLAN` | `GOV-STRATEGIC-PLAN` | Strategic Plan | 10/5 | No credible match | Observed entry; task blocked | — |
| `GOV-TE-TIRITI` | `GOV-TE-TIRITI` | Te Tiriti | 3/1 | No credible match | Observed entry; task blocked | — |

## Respite

Capabilities: 23; human: 22; retained exact finding links: `RESP-SCOPE-01`, `RESP-STATE-01`, `RESP-EVIDENCE-01`.

| Capability | Legacy family | Submodule | Routes/pages | Benchmark disposition | Browser/source status | Findings |
|---|---|---|---:|---|---|---|
| `CAP-RESP-RESPITE-RISK-PLAN-ACTIVATION-ACKNOWLEDGEMENT-CONTEXT` | `RESP-RESPITE-RISK-PLAN-ACTIVATION` | Risk-plan acknowledgement and client or stay context | 4/6 | Pending capability-level adjudication | Observed entry; task blocked | — |
| `CAP-RESP-RESPITE-RISK-PLAN-ACTIVATION-ACTIVATION-LIFECYCLE` | `RESP-RESPITE-RISK-PLAN-ACTIVATION` | Respite risk-plan activation review suspension and deactivation | 9/6 | Pending capability-level adjudication | Observed entry; task blocked | — |
| `CAP-RESP-RESPITE-STAY-ADMISSION-DISCHARGE` | `RESP-RESPITE-STAY` | Respite stay admission check-in and discharge | 4/2 | Pending capability-level adjudication | Source/blocked | RESP-SCOPE-01, RESP-STATE-01 |
| `CAP-RESP-RESPITE-STAY-BED-HOLD-EXTENSION` | `RESP-RESPITE-STAY` | Respite bed hold and stay extension | 2/2 | Pending capability-level adjudication | Source/blocked | RESP-SCOPE-01, RESP-STATE-01 |
| `CAP-RESP-RESPITE-STAY-CLINICAL-RECONCILIATION` | `RESP-RESPITE-STAY` | Respite medication reconciliation and restraint recording | 2/2 | Pending capability-level adjudication | Source/blocked | RESP-SCOPE-01, RESP-STATE-01 |
| `CAP-RESP-RESPITE-STAY-INCIDENTS-COMPLAINTS` | `RESP-RESPITE-STAY` | Respite stay incident and complaint recording | 2/2 | Pending capability-level adjudication | Source/blocked | RESP-SCOPE-01, RESP-STATE-01 |
| `CAP-RESP-RESPITE-TASK-APPROVAL-WORKLISTS` | `RESP-RESPITE-TASK` | Respite task approval rejection and approval worklists | 6/5 | Pending capability-level adjudication | Observed entry; task blocked | — |
| `CAP-RESP-RESPITE-TASK-EXECUTION-EVIDENCE` | `RESP-RESPITE-TASK` | Respite task assignment start checklist evidence and completion | 7/5 | Pending capability-level adjudication | Observed entry; task blocked | — |
| `RESP-CLIENT-RESPITE-ALLOCATION` | `RESP-CLIENT-RESPITE-ALLOCATION` | Client Respite Allocation | 3/0 | No credible match | Source/blocked | — |
| `RESP-RESPITE-BOOKING` | `RESP-RESPITE-BOOKING` | Respite Booking | 5/3 | No credible match | Observed entry; task blocked | RESP-STATE-01 |
| `RESP-RESPITE-BOOKING-REQUEST` | `RESP-RESPITE-BOOKING-REQUEST` | Respite Booking Request | 6/3 | No credible match | Observed entry; task blocked | RESP-STATE-01 |
| `RESP-RESPITE-CALENDAR` | `RESP-RESPITE-CALENDAR` | Respite Calendar | 0/1 | No credible match | Non-human/unsafe | — |
| `RESP-RESPITE-COMMUNICATION-LOG` | `RESP-RESPITE-COMMUNICATION-LOG` | Respite Communication Log | 7/4 | No credible match | Observed entry; task blocked | — |
| `RESP-RESPITE-DAILY-NOTE` | `RESP-RESPITE-DAILY-NOTE` | Respite Daily Note | 8/6 | No credible match | Observed entry; task blocked | RESP-SCOPE-01 |
| `RESP-RESPITE-EVIDENCE-PACK` | `RESP-RESPITE-EVIDENCE-PACK` | Respite Evidence Pack | 10/4 | No credible match | Observed entry; task blocked | RESP-SCOPE-01, RESP-EVIDENCE-01 |
| `RESP-RESPITE-PROCEDURE-RUN` | `RESP-RESPITE-PROCEDURE-RUN` | Respite Procedure Run | 11/5 | No credible match | Observed entry; task blocked | — |
| `RESP-RESPITE-PROCEDURE-TEMPLATE` | `RESP-RESPITE-PROCEDURE-TEMPLATE` | Respite Procedure Template | 5/3 | No credible match | Observed entry; task blocked | — |
| `RESP-RESPITE-RESOURCE-ALLOCATION` | `RESP-RESPITE-RESOURCE-ALLOCATION` | Respite Resource Allocation | 3/1 | No credible match | Observed entry; task blocked | — |
| `RESP-RESPITE-WORKSPACE` | `RESP-RESPITE-WORKSPACE` | Respite Workspace | 1/1 | No credible match | Observed entry; task blocked | — |
| `RESP-ROUTE-RESPITE-BOOKINGS-INDEX` | `RESP-ROUTE-RESPITE-BOOKINGS-INDEX` | Respite Bookings Index | 1/0 | No credible match | Source/blocked | — |
| `RESP-ROUTE-RESPITE-CALENDAR` | `RESP-ROUTE-RESPITE-CALENDAR` | Respite Calendar | 1/0 | No credible match | Source/blocked | — |
| `RESP-ROUTE-RESPITE-REQUESTS-INDEX` | `RESP-ROUTE-RESPITE-REQUESTS-INDEX` | Respite Requests Index | 1/0 | No credible match | Source/blocked | — |
| `RESP-ROUTE-RESPITE-STAYS-INDEX` | `RESP-ROUTE-RESPITE-STAYS-INDEX` | Respite Stays Index | 1/0 | No credible match | Source/blocked | — |

## Reporting and summaries

Capabilities: 5; human: 5; retained exact finding links: none.

| Capability | Legacy family | Submodule | Routes/pages | Benchmark disposition | Browser/source status | Findings |
|---|---|---|---:|---|---|---|
| `REP-COMBINED-REPORT` | `REP-COMBINED-REPORT` | Combined Report | 2/1 | No credible match | Observed entry; task blocked | — |
| `REP-MODULE-REPORT` | `REP-MODULE-REPORT` | Module Report | 2/1 | No credible match | Observed entry; task blocked | — |
| `REP-REPORTS` | `REP-REPORTS` | Reports | 1/1 | No credible match | Observed entry; task blocked | — |
| `REP-ROUTE-SUMMARIES-HOME` | `REP-ROUTE-SUMMARIES-HOME` | Summaries Home | 1/0 | No credible match | Source/blocked | — |
| `REP-SUMMARY` | `REP-SUMMARY` | Summary | 6/1 | No credible match | Observed entry; task blocked | — |

## Roadmap

Capabilities: 7; human: 6; retained exact finding links: none.

| Capability | Legacy family | Submodule | Routes/pages | Benchmark disposition | Browser/source status | Findings |
|---|---|---|---:|---|---|---|
| `ROAD-DECISION-REQUEST` | `ROAD-DECISION-REQUEST` | Decision Request | 2/1 | OpenProject Community | Observed entry; task blocked | — |
| `ROAD-INITIATIVE` | `ROAD-INITIATIVE` | Initiative | 6/1 | OpenProject Community | Observed entry; task blocked | — |
| `ROAD-LEGACY-REDIRECTS` | `ROAD-LEGACY-REDIRECTS` | Legacy redirects | 1/0 | No credible match | Non-human/unsafe | — |
| `ROAD-QUARTERLY-PLAN` | `ROAD-QUARTERLY-PLAN` | Quarterly Plan | 8/2 | OpenProject Community | Observed entry; task blocked | — |
| `ROAD-REPORT` | `ROAD-REPORT` | Report | 2/0 | OpenProject Community | Source/blocked | — |
| `ROAD-ROADMAP-DASHBOARD` | `ROAD-ROADMAP-DASHBOARD` | Roadmap Dashboard | 1/2 | OpenProject Community | Observed entry; task blocked | — |
| `ROAD-SUGGESTION` | `ROAD-SUGGESTION` | Suggestion | 4/1 | OpenProject Community | Observed entry; task blocked | — |

## Settings and system access

Capabilities: 40; human: 37; retained exact finding links: none.

| Capability | Legacy family | Submodule | Routes/pages | Benchmark disposition | Browser/source status | Findings |
|---|---|---|---:|---|---|---|
| `CAP-SET-DATA-SETTINGS-BREACHES` | `SET-DATA-SETTINGS` | Data breach recording | 1/1 | Pending capability-level adjudication | Source/blocked | — |
| `CAP-SET-DATA-SETTINGS-PRIVACY-CONTROLS` | `SET-DATA-SETTINGS` | Privacy control configuration | 2/1 | Pending capability-level adjudication | Observed entry; task blocked | — |
| `CAP-SET-DATA-SETTINGS-PROCESSORS` | `SET-DATA-SETTINGS` | Data processor register | 3/1 | Pending capability-level adjudication | Source/blocked | — |
| `CAP-SET-DATA-SETTINGS-REQUESTS` | `SET-DATA-SETTINGS` | Privacy or data request intake | 1/1 | Pending capability-level adjudication | Source/blocked | — |
| `CAP-SET-DATA-SETTINGS-RETENTION-COMPLIANCE` | `SET-DATA-SETTINGS` | Retention and compliance configuration | 2/1 | Pending capability-level adjudication | Source/blocked | — |
| `CAP-SET-USERS-ACCOUNT-LIFECYCLE` | `SET-USERS` | User creation approval update suspension and deletion | 11/3 | Pending capability-level adjudication | Observed entry; task blocked | — |
| `CAP-SET-USERS-IMPERSONATION` | `SET-USERS` | User impersonation start and stop | 2/3 | Pending capability-level adjudication | Source/blocked | — |
| `CAP-SET-USERS-SESSIONS` | `SET-USERS` | User session termination | 4/3 | Pending capability-level adjudication | Source/blocked | — |
| `SET-ACCESS` | `SET-ACCESS` | Access | 5/1 | Keycloak | Observed entry; task blocked | — |
| `SET-ACCESS-CONTROL` | `SET-ACCESS-CONTROL` | Access Control | 9/4 | Keycloak | Observed entry; task blocked | — |
| `SET-API-SETTINGS` | `SET-API-SETTINGS` | Api Settings | 6/1 | No credible match | Observed entry; task blocked | — |
| `SET-APPEARANCE` | `SET-APPEARANCE` | Appearance | 3/1 | No credible match | Observed entry; task blocked | — |
| `SET-BRANDING` | `SET-BRANDING` | Branding | 2/1 | No credible match | Observed entry; task blocked | — |
| `SET-CALENDAR-SYNC-OAUTH` | `SET-CALENDAR-SYNC-OAUTH` | Calendar Sync OAuth | 3/0 | No credible match | Source/blocked | — |
| `SET-CALENDAR-SYNC-SETTINGS` | `SET-CALENDAR-SYNC-SETTINGS` | Calendar Sync Settings | 6/1 | No credible match | Source/blocked | — |
| `SET-CONFIRMABLE-PASSWORD` | `SET-CONFIRMABLE-PASSWORD` | Confirmable Password | 2/1 | Keycloak | Observed entry; task blocked | — |
| `SET-CONFIRMED-PASSWORD-STATUS` | `SET-CONFIRMED-PASSWORD-STATUS` | Confirmed Password Status | 1/0 | Keycloak | Source/blocked | — |
| `SET-CONFIRMED-TWO-FACTOR-AUTHENTICATION` | `SET-CONFIRMED-TWO-FACTOR-AUTHENTICATION` | Confirmed Two Factor Authentication | 1/0 | Keycloak | Source/blocked | — |
| `SET-CONTROLLERS-TWO-FACTOR-AUTHENTICATION` | `SET-CONTROLLERS-TWO-FACTOR-AUTHENTICATION` | Two Factor Authentication | 2/0 | Keycloak | Source/blocked | — |
| `SET-EMAIL-SETTINGS` | `SET-EMAIL-SETTINGS` | Email Settings | 3/1 | No credible match | Observed entry; task blocked | — |
| `SET-FRONTEND-SETTINGS-SSO` | `SET-FRONTEND-SETTINGS-SSO` | Settings/Sso | 0/1 | Keycloak | Non-human/unsafe | — |
| `SET-LEGACY-REDIRECTS` | `SET-LEGACY-REDIRECTS` | Legacy redirects | 5/0 | No credible match | Non-human/unsafe | — |
| `SET-MODULE-SETTINGS` | `SET-MODULE-SETTINGS` | Module Settings | 2/1 | No credible match | Observed entry; task blocked | — |
| `SET-NOTIFICATION-ESCALATIONS` | `SET-NOTIFICATION-ESCALATIONS` | Notification Escalations | 2/1 | No credible match | Observed entry; task blocked | — |
| `SET-NOTIFICATION-PREFERENCES` | `SET-NOTIFICATION-PREFERENCES` | Notification Preferences | 5/3 | No credible match | Observed entry; task blocked | — |
| `SET-NOTIFICATION-TEMPLATE` | `SET-NOTIFICATION-TEMPLATE` | Notification Template | 5/1 | No credible match | Observed entry; task blocked | — |
| `SET-PASSWORD` | `SET-PASSWORD` | Password | 2/1 | Keycloak | Observed entry; task blocked | — |
| `SET-PROFILE` | `SET-PROFILE` | Profile | 6/1 | Keycloak | Observed entry; task blocked | — |
| `SET-PUSH-SUBSCRIPTION` | `SET-PUSH-SUBSCRIPTION` | Push Subscription | 2/0 | No credible match | Source/blocked | — |
| `SET-RECOVERY-CODE` | `SET-RECOVERY-CODE` | Recovery Code | 2/0 | Keycloak | Source/blocked | — |
| `SET-ROLES` | `SET-ROLES` | Roles | 5/2 | Keycloak | Observed entry; task blocked | — |
| `SET-SECURITY-POLICY` | `SET-SECURITY-POLICY` | Security Policy | 2/1 | No credible match | Observed entry; task blocked | — |
| `SET-SERVICE-CONTEXT` | `SET-SERVICE-CONTEXT` | Service Context | 4/1 | No credible match | Observed entry; task blocked | — |
| `SET-SETTINGS-TWO-FACTOR-AUTHENTICATION` | `SET-SETTINGS-TWO-FACTOR-AUTHENTICATION` | Two Factor Authentication | 1/1 | Keycloak | Source/blocked | — |
| `SET-SSO-CONFIG` | `SET-SSO-CONFIG` | Sso Config | 1/1 | Keycloak | Observed entry; task blocked | — |
| `SET-SSO-GROUP` | `SET-SSO-GROUP` | Sso Group | 5/1 | Keycloak | Observed entry; task blocked | — |
| `SET-TERMINOLOGY` | `SET-TERMINOLOGY` | Terminology | 2/1 | No credible match | Observed entry; task blocked | — |
| `SET-TWO-FACTOR-QR-CODE` | `SET-TWO-FACTOR-QR-CODE` | Two Factor Qr Code | 1/0 | Keycloak | Source/blocked | — |
| `SET-TWO-FACTOR-SECRET-KEY` | `SET-TWO-FACTOR-SECRET-KEY` | Two Factor Secret Key | 1/0 | Keycloak | Source/blocked | — |
| `SET-USER-MANAGEMENT-REDIRECT` | `SET-USER-MANAGEMENT-REDIRECT` | User Management Redirect | 2/0 | Keycloak | Non-human/unsafe | — |

## Client and family portal

Capabilities: 7; human: 5; retained exact finding links: none.

| Capability | Legacy family | Submodule | Routes/pages | Benchmark disposition | Browser/source status | Findings |
|---|---|---|---:|---|---|---|
| `PORT-FRONTEND-CLIENTS-PORTAL-USERS` | `PORT-FRONTEND-CLIENTS-PORTAL-USERS` | Clients/Portal Users | 0/1 | No credible match | Non-human/unsafe | — |
| `PORT-FRONTEND-PORTAL-MESSAGES` | `PORT-FRONTEND-PORTAL-MESSAGES` | Portal/Messages | 0/1 | No credible match | Non-human/unsafe | — |
| `PORT-PORTAL` | `PORT-PORTAL` | Portal | 1/1 | OpenEMR portal | Observed entry; task blocked | — |
| `PORT-PORTAL-NOTIFICATION` | `PORT-PORTAL-NOTIFICATION` | Portal Notification | 3/1 | No credible match | Observed entry; task blocked | — |
| `PORT-PORTAL-OAUTH` | `PORT-PORTAL-OAUTH` | Portal OAuth | 4/0 | No credible match | Source/blocked | — |
| `PORT-PORTAL-PREFERENCE` | `PORT-PORTAL-PREFERENCE` | Portal Preference | 2/1 | No credible match | Observed entry; task blocked | — |
| `PORT-ROUTE-PORTAL-LOGIN` | `PORT-ROUTE-PORTAL-LOGIN` | Portal Login | 1/1 | OpenEMR portal | Source/blocked | — |

## Platform and shared infrastructure

Capabilities: 18; human: 7; retained exact finding links: `VIS-MOBILE-NAV-01`, `SEC-UNIFI-TLS-01`, `ARCH-P0-C`, `INTEG-WEBHOOK-001`.

| Capability | Legacy family | Submodule | Routes/pages | Benchmark disposition | Browser/source status | Findings |
|---|---|---|---:|---|---|---|
| `PLAT-COMPETENCY-ASSESSMENT` | `PLAT-COMPETENCY-ASSESSMENT` | Competency Assessment | 0/0 | No credible match | Non-human/unsafe | — |
| `PLAT-DASHBOARD` | `PLAT-DASHBOARD` | Dashboard | 1/1 | No credible match | Observed entry; task blocked | VIS-MOBILE-NAV-01 |
| `PLAT-EMAIL-VERIFICATION-NOTIFICATION` | `PLAT-EMAIL-VERIFICATION-NOTIFICATION` | Email Verification Notification | 1/0 | No credible match | Source/blocked | — |
| `PLAT-EMAIL-VERIFICATION-PROMPT` | `PLAT-EMAIL-VERIFICATION-PROMPT` | Email Verification Prompt | 1/1 | No credible match | Source/blocked | — |
| `PLAT-FRONTEND-INTEGRATIONS-UNIFI` | `PLAT-FRONTEND-INTEGRATIONS-UNIFI` | Integrations/Unifi | 0/1 | No credible match | Non-human/unsafe | SEC-UNIFI-TLS-01 |
| `PLAT-FRONTEND-SYSTEM-USERS` | `PLAT-FRONTEND-SYSTEM-USERS` | System/Users | 0/1 | No credible match | Non-human/unsafe | — |
| `PLAT-FRONTEND-WELCOME` | `PLAT-FRONTEND-WELCOME` | Welcome | 0/1 | No credible match | Non-human/unsafe | — |
| `PLAT-INDUCTION` | `PLAT-INDUCTION` | Induction | 0/0 | No credible match | Non-human/unsafe | — |
| `PLAT-LEGACY-REDIRECTS` | `PLAT-LEGACY-REDIRECTS` | Legacy redirects | 4/0 | No credible match | Non-human/unsafe | — |
| `PLAT-RAG` | `PLAT-RAG` | Rag | 2/0 | No credible match | Source/blocked | — |
| `PLAT-ROUTE-INTERNAL-DESIGN-PAGE-HERO` | `PLAT-ROUTE-INTERNAL-DESIGN-PAGE-HERO` | Internal Design Page Hero | 1/1 | No credible match | Observed entry; task blocked | — |
| `PLAT-ROUTE-ROBOTS` | `PLAT-ROUTE-ROBOTS` | Robots | 1/0 | No credible match | Source/blocked | — |
| `PLAT-ROUTE-STORAGE-LOCAL` | `PLAT-ROUTE-STORAGE-LOCAL` | Storage Local | 1/0 | No credible match | Non-human/unsafe | — |
| `PLAT-ROUTE-STORAGE-LOCAL-UPLOAD` | `PLAT-ROUTE-STORAGE-LOCAL-UPLOAD` | Storage Local Upload | 1/0 | No credible match | Non-human/unsafe | — |
| `PLAT-ROUTE-UP` | `PLAT-ROUTE-UP` | Up | 1/0 | No credible match | Non-human/unsafe | — |
| `PLAT-TRAINING-RECORD` | `PLAT-TRAINING-RECORD` | Training Record | 0/0 | No credible match | Non-human/unsafe | — |
| `PLAT-VERIFY-EMAIL` | `PLAT-VERIFY-EMAIL` | Verify Email | 1/0 | No credible match | Source/blocked | — |
| `PLAT-WEBHOOK-RECEIVER` | `PLAT-WEBHOOK-RECEIVER` | Webhook Receiver | 1/0 | No credible match | Non-human/unsafe | ARCH-P0-C, INTEG-WEBHOOK-001 |

## Detailed P0/P1 register

### ARCH-P0-A — P0 — Nested alert resources

- Feature IDs: `CR-CONTROL-ROOM-TASK`, `CR-CONTROL-ROOM-DISCUSSION`, `CR-CONTROL-ROOM-EVIDENCE`, `CR-CONTROL-ROOM-ESCALATION`, `CR-CONTROL-ROOM-PLAYBOOK`, `CR-CONTROL-ROOM-TIME-ENTRY`, `CR-CONTROL-ROOM-WATCHER`
- Actor/job: A site-scoped Control Room operator mutates work belonging to an alert.
- Current behavior: Alert-level actions call the site-aware access service, but nested controllers use permission checks and directly bound child IDs without proving the child belongs to the route alert or an allowed site.
- Failure sequence: An operator authorised for alert A submits A with a task, evidence, discussion, watcher, time entry, escalation or playbook-run ID from hidden alert B; the child on B is read or mutated.
- Boundary/root cause: Owning-alert relationship, site visibility, direct-object authorization and operational privacy. Independent route-model binding leaves child authorization detached from the parent safety object.
- Impact: Cross-site operational data disclosure and alteration can corrupt live safety-response ownership and evidence.
- Evidence: `app/Http/Controllers/ControlRoom/ControlRoomAlertController.php:768-772,803-807,918-938`; `app/Services/UserSiteAccessService.php:167-190,350-375`; `app/Http/Controllers/ControlRoom/ControlRoomTaskController.php:16-25,51-80,93-196`; `app/Http/Controllers/ControlRoom/ControlRoomDiscussionController.php:20-169`; `app/Http/Controllers/ControlRoom/ControlRoomEvidenceController.php:20-71`; `app/Http/Controllers/ControlRoom/ControlRoomEscalationController.php:249-301`; `app/Http/Controllers/ControlRoom/ControlRoomPlaybookController.php:406-557`; `routes/control-room.php:395-423`
- Existing tests (not executed): ControlRoomTaskControllerTest; ControlRoomEvidenceControllerTest; ControlRoomDiscussionControllerTest; ControlRoomWatcherControllerTest; ControlRoomTimeEntryControllerTest
- Missing tests: Alert-child mismatch for every child type; Hidden-site direct IDs; Bulk mixed-site operations; Concurrent child mutation; Browser-hidden picker/direct-URL denial
- Benchmark: OpenProject Community — Native benchmark; Owned work packages with due state and blocking relationships.
- Neutral requirements: Use a shared owner, due, evidence and dependency shell while preserving domain state machines.
- Native design direction: Introduce one native parent-scoped resolver used by all child controllers, with a stable denial contract and complete audit context.
- Interim safeguard: Restrict nested mutation permissions to explicitly global Control Room roles and review child-resource mutations against their owning alert/site.
- Acceptance: Given the actor and boundary described, when the failing sequence is attempted, then the operation is denied or completed atomically with no foreign disclosure, partial mutation or duplicate effect.; Resolve each child through the supplied alert relationship.; Apply UserSiteAccessService before disclosure or mutation.; Return 403/404 with no side effect for mismatched or hidden children.; Cover site-A/site-B, global-role, bulk and concurrency cases.
- Owner / effort / confidence: Control Room Product Owner and Authorization Platform Owner / L / High
- Evidence typing: source-observed behavior; failure/impact inference; priority/design is the named specialist owner's decision. No third-party code/UI/schema/wording may be copied.

### ARCH-P0-B — P0 — Device identity, telemetry, location and custody

- Feature IDs: `CR-CONTROL-ROOM-DEVICE`, `SEC-DEVICE`, `SEC-DEVICE-ASSIGNMENT`, `FLEET-RESIDENT-TRACKING`
- Actor/job: A site-bound security, fleet or Control Room user views or changes a device or resident-location record.
- Current behavior: Lists, statistics, detail, coordinates, telemetry, identifiers, assignment history and mutations are permission-only/global. Device scope is not reliably derived from current custody; resident tracking's scope helper becomes unrestricted for broadly permitted users.
- Failure sequence: A site-A actor directly supplies a device or assignment associated with site/client B and receives precision location/identity data or mutates custody; non-null consent is treated as sufficient without ownership, status or purpose checks.
- Boundary/root cause: Current and historical custody, site, resident consent/purpose, precision-location privacy and direct-object authorization. Device identity and authorization are permission-global while operational ownership is temporal and distributed across modules.
- Impact: Direct exposure or mutation of resident location, device identity and custody is an immediate privacy and safety risk.
- Evidence: `app/Http/Controllers/ControlRoom/ControlRoomDeviceController.php:18-76,83-194`; `app/Domain/SecurityDevices/Policies/DevicePolicy.php:10-38`; `app/Domain/SecurityDevices/Http/Controllers/DeviceController.php:35-165,438-516`; `app/Domain/SecurityDevices/Http/Controllers/DeviceAssignmentController.php:25-61,115-165`; `app/Domain/SecurityDevices/Services/DeviceAssignmentService.php:18-49,55-71,96-108,124-134`; `app/Http/Controllers/FleetAssets/ResidentTrackingController.php:34-320,416-526,550-620`; `database/seeders/SecurityDevicesPermissionsSeeder.php:63-123`
- Existing tests (not executed): Device controller and resident-tracking happy-path tests; Security-device permission tests
- Missing tests: Current versus historical custody; Unassigned/quarantined device; Resident consent status/type/ownership; Transfer concurrency; Site-bound worker negatives; Global-role positive
- Benchmark: Eclipse Ditto — Native benchmark; Thing policies and distinct desired versus reported properties preserve device identity and state provenance.
- Neutral requirements: Separate intended from reported device state and apply custody-derived policy before identity, telemetry or mutation is exposed.
- Native design direction: Keep Security & Devices as canonical identity and add native custody-scoped projections for Fleet, Sites and Control Room.
- Interim safeguard: Limit device/location/assignment surfaces to named global security roles and suppress precision telemetry for site-bound roles pending custody scoping.
- Acceptance: Given the actor and boundary described, when the failing sequence is attempted, then the operation is denied or completed atomically with no foreign disclosure, partial mutation or duplicate effect.; Define an authoritative temporal custody relationship.; Apply custody/site scope to list, picker, detail, history, export and mutation.; Require active purpose-specific resident authority for tracking.; Test transfer races and global override separately.
- Owner / effort / confidence: Security Devices Owner, Fleet Safety Owner and Privacy Officer / XL / High
- Evidence typing: source-observed behavior; failure/impact inference; priority/design is the named specialist owner's decision. No third-party code/UI/schema/wording may be copied.

### ARCH-P0-C — P0 — Safety signal delivery and recovery

- Feature IDs: `CR-ALERT`, `FLEET-ALERT`, `PLAT-WEBHOOK-RECEIVER`, `SEC-ALERTS-EVENTS`
- Actor/job: A domain or integration emits a safety-relevant signal that must reach the Control Room queue exactly once and remain recoverable.
- Current behavior: Outbox rows have retries once created, but source records are committed before outbox creation. A failure between them makes retry exit on the existing source. Webhook routing and device-event bridging catch downstream failure without an automated replay work queue.
- Failure sequence: Source safety event commits, outbox/routing fails, retry sees the existing source and exits, or a caught webhook/device routing error remains persisted but unprocessed; no Control Room alert is guaranteed.
- Boundary/root cause: End-to-end durability, idempotency, ordering, null-site disposition, replay, dead-letter visibility and alert ownership. Durability begins only after an outbox row exists; pre-outbox and swallowed routing failures lack a recovery invariant.
- Impact: A critical shift, fleet, webhook or device signal can be accepted without reaching the operational response queue.
- Evidence: `app/Services/Fleet/FleetSignalService.php:13-47`; `app/Services/ShiftSignalService.php:28-58`; `app/Jobs/DispatchFleetSignalOutbox.php:27-77`; `app/Jobs/DispatchShiftSignalOutbox.php:26-80`; `app/Http/Controllers/Api/WebhookReceiverController.php:99-121`; `app/Services/Integration/AlertRoutingService.php:65-108`; `app/Observers/DeviceEventObserver.php:54-124`
- Existing tests (not executed): ShiftSignalServiceTest; SignalOutboxControllerTest; WebhookReceiverTest; ShiftControlRoomSignalPipelineTest; DeviceEventSignalPipelineTest
- Missing tests: Failure between source and outbox; Router and observer exception replay; Null-site disposition; Dead-letter visibility; Recovery-time objective; Duplicate/reordered events
- Benchmark: OneUptime Community — Native benchmark; Observable timed escalation attempts and acknowledgement.
- Neutral requirements: Keep severity, responder, timed escalation, delivery evidence and terminal handover without client-data publication.
- Native design direction: Use Oblivion-native transactional-outbox/reconciliation contracts with visible replay and no competing domain alert queues.
- Interim safeguard: Run reconciliation sweeps for sources without outbox/alert, surface failed routing metrics and document an authorised manual replay.
- Acceptance: Given the actor and boundary described, when the failing sequence is attempted, then the operation is denied or completed atomically with no foreign disclosure, partial mutation or duplicate effect.; Create source plus outbox transactionally or reconcile idempotently.; Use unique source keys and deterministic routing.; Expose failed/dead-letter state and replay.; Inject every boundary failure and prove exactly-once alert outcome.
- Owner / effort / confidence: Control Room Platform and Integration Reliability Owners / XL / High
- Evidence typing: source-observed behavior; failure/impact inference; priority/design is the named specialist owner's decision. No third-party code/UI/schema/wording may be copied.

### ASSET-RBAC-01 — P0 — Fleet asset policy bypass

- Feature IDs: `FLEET-ASSET`, `ASSET-ASSET`
- Actor/job: A site or fleet custodian views or changes an assigned asset.
- Current behavior: The controller relies on broad route permissions and does not invoke the AssetPolicy whose rules express assigned-only access, leaving global list, detail and mutation available to broadly permitted actors.
- Failure sequence: A site-A custodian supplies asset B's ID; route permission succeeds even though policy ownership would deny, and B is disclosed or changed.
- Boundary/root cause: Asset custody, assigned-site visibility, direct-object authorization and audit provenance. Route middleware and the domain policy encode different authorization semantics, and the controller bypasses the stronger rule.
- Impact: Global asset access enables loss of custody integrity and disclosure of site/device associations.
- Evidence: `app/Http/Controllers/FleetAssets/AssetController.php:57-196,494-620`; `app/Policies/AssetPolicy.php:11-95`
- Existing tests (not executed): Fleet asset happy-path and permission tests
- Missing tests: Controller-policy parity; Two-site direct ID; Assigned/unassigned/quarantined states; Bulk and export scope; Custody transfer concurrency
- Benchmark: Snipe-IT — Native benchmark; Asset-tag, assignment, checkout and check-in workflows preserve accountable custody.
- Neutral requirements: Use one authoritative asset identity, explicit custody transitions, policy checks and immutable assignment history.
- Native design direction: Make the Asset domain policy authoritative and expose site-scoped native projections to Fleet and Sites.
- Interim safeguard: Restrict fleet-asset permissions to central custodians and manually verify assignment before any mutation.
- Acceptance: Given the actor and boundary described, when the failing sequence is attempted, then the operation is denied or completed atomically with no foreign disclosure, partial mutation or duplicate effect.; Invoke one canonical asset authorization rule for list and every object action.; Scope queries and pickers before pagination/counting.; Test assigned, secondary-site and global roles.; Preserve custody transition history.
- Owner / effort / confidence: Asset Platform and Authorization Owners / M / High
- Evidence typing: source-observed behavior; failure/impact inference; priority/design is the named specialist owner's decision. No third-party code/UI/schema/wording may be copied.

### CLIN-SCHEDULE-01 — P0 — Clinical protocol schedule completion

- Feature IDs: `CLIN-HEALTH-CLINICAL-PROTOCOL`, `CLIN-SHIFT-CLINICAL`
- Actor/job: A worker records an observation intended to complete a due protocol schedule.
- Current behavior: protocol_schedule_id is existence-only and completion does not prove its protocol resident or observation type matches the new observation.
- Failure sequence: A resident-A observation supplies a resident-B or wrong-type pending schedule; the observation is stored and the unrelated schedule is marked complete.
- Boundary/root cause: Schedule-protocol-resident-observation-type relational integrity. A schedule is treated as an independent ID instead of a child of the resident-specific protocol.
- Impact: An overdue clinical observation can appear complete for the wrong resident or measure.
- Evidence: `app/Http/Controllers/Clinical/Concerns/RecordsClinicalRecords.php:32-47`; `app/Http/Controllers/Clinical/ShiftClinicalController.php:68`; `app/Domain/Clinical/Services/ClinicalObservationService.php:65-81,579-585`
- Existing tests (not executed): ClinicalObservationServiceTest completes a supplied schedule; ShiftClinicalControllerTest happy path
- Missing tests: Cross-resident; Cross-observation type; Inactive/wrong protocol; Concurrent completion
- Benchmark: OpenMRS Form Engine — Native benchmark; Field validation and explicit multi-operation success and error processing.
- Neutral requirements: Provide cross-field validation, recoverable draft, explicit partial failure, idempotent retry and immutable submitted versions.
- Native design direction: Use one native observation command that derives and locks the due schedule from the resident/type context.
- Interim safeguard: Clinical lead reconciles completed schedules to linked observation resident/type and does not rely only on the completed flag.
- Acceptance: Given the actor and boundary described, when the failing sequence is attempted, then the operation is denied or completed atomically with no foreign disclosure, partial mutation or duplicate effect.; Lock a pending schedule through its protocol.; Require resident and observation type match before creating/completing.; Reject mismatch atomically.; Test both recording surfaces and concurrency.
- Owner / effort / confidence: Clinical Engineering and Clinical Governance / M / High
- Evidence typing: source-observed behavior; failure/impact inference; priority/design is the named specialist owner's decision. No third-party code/UI/schema/wording may be copied.

### CLIN-SITE-01 — P0 — Same-organization site exposure

- Feature IDs: `CLIN-HEALTH-CLINICAL-DASHBOARD`, `CLIN-HEALTH-CLINICAL`
- Actor/job: A site-bound coordinator reviews and records clinical observations and events.
- Current behavior: Clinical registers, KPIs, client/site/staff pickers and write/review paths are organization-global; ClientPolicy permits same-organization access and does not apply allowed-site IDs.
- Failure sequence: A site-A coordinator lists or directly selects a site-B resident and reads vitals/behaviour/events or records/reviews site-B clinical data.
- Boundary/root cause: Single-tenant site access, resident clinical privacy, direct-object denial and explicit global clinical role. Organization membership is incorrectly used as the complete client authorization boundary in a single-tenant multi-site product.
- Impact: Site-restricted staff can disclose or change another site's PHI and safety records.
- Evidence: `app/Http/Controllers/Clinical/HealthClinicalDashboardController.php:60-93,99-208,324-353,575-601`; `app/Domain/Clinical/Services/ClinicalDashboardService.php:52-68,205-214,321-345`; `app/Policies/ClientPolicy.php:17-42`; `database/seeders/RbacSeeder.php:677,683-691,795-823`
- Existing tests (not executed): HealthClinicalCrossOrgAuthorizationTest denies another organization but positively allows same organization
- Missing tests: Site-A list/picker/KPI exclusion; Direct site-B read/write/review denial; No counts leakage; Explicit global-role positive
- Benchmark: OpenMRS O3 patient chart — Native benchmark; Persistent identity context and detailed allergy severity and reaction.
- Neutral requirements: Persist clear person and critical-clinical context and reset it on person change.
- Native design direction: Add one native clinical-site query scope and direct-object assertion, with an explicitly named global bypass role.
- Interim safeguard: Restrict global clinical permissions to central clinical roles pending site-aware query and mutation enforcement.
- Acceptance: Given the actor and boundary described, when the failing sequence is attempted, then the operation is denied or completed atomically with no foreign disclosure, partial mutation or duplicate effect.; Apply allowed-site scope to services, pickers, aggregates and exports.; Assert site access before every direct object and write.; Test coordinator and central clinical roles across two sites.; Return 403/404 without PHI/count leakage.
- Owner / effort / confidence: Clinical Governance, Privacy and Authorization Platform / L / High
- Evidence typing: source-observed behavior; failure/impact inference; priority/design is the named specialist owner's decision. No third-party code/UI/schema/wording may be copied.

### FLEET-TRANSPORT-01 — P0 — Resident transport site/client/clinical scope

- Feature IDs: `FLEET-RESIDENT-TRANSPORT`
- Actor/job: A transport coordinator plans and records a resident journey.
- Current behavior: Transport registers, detail and mutations expose broad resident, shift, asset and medication context and validate related IDs independently rather than through one authorised resident/site journey.
- Failure sequence: A site-A operator supplies resident, shift, asset or medication IDs from site B, views clinical travel context or records a journey against mismatched records.
- Boundary/root cause: Resident/site/shift/asset/medication relationship and minimum-necessary travel privacy. Independent existence validation is used where the workflow requires one relationship-bound aggregate.
- Impact: Wrong-resident transport and medication context creates immediate privacy and care-safety risk.
- Evidence: `app/Http/Controllers/FleetAssets/ResidentTransportController.php:184-960,967-1040`
- Existing tests (not executed): Resident transport feature happy paths
- Missing tests: Two-site list/detail/direct-ID matrix; Every mixed resident/shift/asset/medication combination; No side effects on rejection; Explicit global transport role
- Benchmark: No credible match — No credible match; Evidence gap—not a documented No Credible Match. Only catalogue-level candidates were retained for this Fleet and vehicles capability: Traccar, Traccar Web, Snipe-IT. No feature-specific query, beyond-catalogue search or exact rejected-repository evidence is retained; partial patterns were not stretched into a match.
- Neutral requirements: Preserve actor, owning record, site and role boundary, explicit states, validation, recovery, provenance and auditable hand-off in an original Oblivion-native workflow.
- Native design direction: Build a native resident-journey command that derives site, shift, vehicle and medication context server-side.
- Interim safeguard: Limit transport access to central coordinators and manually reconcile every journey's resident, site, shift and medication plan.
- Acceptance: Given the actor and boundary described, when the failing sequence is attempted, then the operation is denied or completed atomically with no foreign disclosure, partial mutation or duplicate effect.; Resolve all related records through the authorised resident and site.; Return 403/404 without leaking foreign context.; Persist one journey provenance graph.; Test direct IDs and concurrent edits.
- Owner / effort / confidence: Fleet Safety and Client Privacy Owners / L / High
- Evidence typing: source-observed behavior; failure/impact inference; priority/design is the named specialist owner's decision. No third-party code/UI/schema/wording may be copied.

### HS-SITE-01 — P0 — Global H&S and worker-health site boundary

- Feature IDs: `HS-HS-EVENT`, `HS-HEALTH-SAFETY-DASHBOARD`, `HS-RETURN-TO-WORK`, `HS-RESTRAINT`
- Actor/job: A site-bound team lead reviews or changes H&S, worker injury/return-to-work or restraint records.
- Current behavior: Broad H&S permissions expose organization-global registers, counts, pickers and direct actions. Optional site filters are query choices rather than allowed-site authorization; the existing site-access service is unused on the named paths.
- Failure sequence: A site-A team lead omits the filter or supplies a site-B record ID and reads or changes incident, worker injury, return-to-work, restraint or statutory workflow data.
- Boundary/root cause: Single-tenant site/role boundary, staff-health and resident restraint PHI, direct-object denial and named global-H&S bypass. Organization-wide permissions and voluntary filters replace the application's actual site boundary.
- Impact: Site-restricted staff can disclose or mutate highly sensitive safety, worker-health and restraint records.
- Evidence: `routes/health-safety.php:29-85,97-155`; `app/Http/Controllers/HealthSafety/HsEventController.php:38-55,160-211,250-315`; `app/Http/Controllers/HealthSafety/HealthSafetyDashboardController.php:40-175`; `app/Http/Controllers/HealthSafety/ReturnToWorkController.php:187-201`; `app/Http/Controllers/HealthSafety/RestraintController.php:59-166,423-638`; `database/seeders/RbacSeeder.php:795-842`; `app/Services/UserSiteAccessService.php:22-84,255-283`
- Existing tests (not executed): HsEventRegisterTest covers organization-wide happy path
- Missing tests: Two-site lists/counts/pickers/exports; Direct show/close/WorkSafe/RTW/restraint mutations; No PHI/count leak; Explicit global role
- Benchmark: No credible match — No credible match; Evidence gap—not a documented No Credible Match. Only catalogue-level candidates were retained for this Health and safety capability: BeaconHS, CISO Assistant Community, OpenProject, Primero. No feature-specific query, beyond-catalogue search or exact rejected-repository evidence is retained; partial patterns were not stretched into a match.
- Neutral requirements: Preserve actor, owning record, site and role boundary, explicit states, validation, recovery, provenance and auditable hand-off in an original Oblivion-native workflow.
- Native design direction: Add one native H&S site-scope contract shared by dashboards, events, injuries, return-to-work and restraint.
- Interim safeguard: Restrict H&S permissions to explicitly approved organization-wide roles and verify site before close/WorkSafe/restraint/RTW action.
- Acceptance: Given the actor and boundary described, when the failing sequence is attempted, then the operation is denied or completed atomically with no foreign disclosure, partial mutation or duplicate effect.; Apply UserSiteAccessService to every collection, aggregate, picker, export and object action.; Name/audit any global bypass.; Test the full role-by-action two-site matrix.; Deny without counts or PHI.
- Owner / effort / confidence: H&S Product, Authorization/AppSec and Privacy / XL / High
- Evidence typing: source-observed behavior; failure/impact inference; priority/design is the named specialist owner's decision. No third-party code/UI/schema/wording may be copied.

### MED-COMP-01 — P0 — Medication administrator competency

- Feature IDs: `MED-EMAR`, `HR-COMPETENCY`
- Actor/job: A permitted worker signs a medication administration.
- Current behavior: Failed or expired competency can block, but absence of any assessment returns success/overrideable and a missing expiry can pass. Medication permission alone can satisfy coverage eligibility in some contexts.
- Failure sequence: A worker receives administration permission without current competency evidence and signs a dose or is treated as medication-capable for coverage.
- Boundary/root cause: Permission, current assessed competency, scoped exemption and common rostering/eMAR eligibility decision. Competency absence is treated as permissive while recorded failure is treated as blocking.
- Impact: An unassessed worker can be scheduled for or record medication administration.
- Evidence: `app/Services/EnhancedMarService.php:883-913`; `app/Services/Eligibility/Rules/MedicationCompetencyRule.php:31-57,70-72`; `tests/Feature/Emar/MedicationIntegrityAuditTest.php:201`
- Existing tests (not executed): MedicationIntegrityAuditTest covers failed/expired and positively codifies no-record permission-only behavior
- Missing tests: No-record fail closed; Null expiry; Scoped/expiring exemption; Rostering/eMAR parity; Expiry concurrent with submit
- Benchmark: Bahmni medication administration — Native benchmark; Structured performer, status, reason, effective time, request, medication, dose and notes.
- Neutral requirements: Bind person, site, active order, scheduled cell, performer and dose; keep coded omission and append-only correction evidence.
- Native design direction: Make current competency a native, versioned eligibility prerequisite with explicit governed exceptions.
- Interim safeguard: Grant administration capability only after a current competency check and report administrations by staff lacking a valid assessment.
- Acceptance: Given the actor and boundary described, when the failing sequence is attempted, then the operation is denied or completed atomically with no foreign disclosure, partial mutation or duplicate effect.; Adopt an explicit no-record policy.; Require approver, scope, reason and expiry for any exemption.; Use one eligibility service across rostering and eMAR.; Test no record, null expiry, failed, expired, valid and exempt states.
- Owner / effort / confidence: Medication Governance and Workforce Competency / L / High
- Evidence typing: source-observed behavior; failure/impact inference; priority/design is the named specialist owner's decision. No third-party code/UI/schema/wording may be copied.

### MED-OVERRIDE-01 — P0 — Caller-controlled medication safety override

- Feature IDs: `MED-MEDICATIONS-API`, `MED-EMAR`
- Actor/job: A medication worker responds to a blocked safety or dose-window check.
- Current behavior: An ordinary administration caller may submit override_safety or override_window. A blocked check proceeds without a distinct privileged capability, mandatory structured reason or co-signature; the over-limit PRN incident branch is bypassed.
- Failure sequence: A recorder receives a block, resubmits override_safety=true and records the dose; an over-limit PRN override can also avoid incident emission.
- Boundary/root cause: Privileged medication-safety authority, reason, co-signature/emergency policy and immutable incident/audit continuity. A client-supplied boolean is used as the safety-authority decision.
- Impact: A caller can bypass a clinically blocking check and record a dose without elevated authority.
- Evidence: `routes/api_medications.php:35-37`; `app/Http/Controllers/Api/MedicationsApiController.php:618-654,757-763`; `app/Services/EnhancedMarService.php:608-667`; `resources/js/components/medications/SafetyCheckPanel.tsx:128-137`; `resources/js/components/medications/RecordAdministrationDialog.tsx:277-306`
- Existing tests (not executed): OneChartAdministrationSafetyTest; MedicationIntegrityAuditTest
- Missing tests: Ordinary caller override denial; Privileged reason/co-signature; PRN incident under override; Direct JSON tamper; Replay/concurrency
- Benchmark: Bahmni medication administration — Native benchmark; Structured performer, status, reason, effective time, request, medication, dose and notes.
- Neutral requirements: Bind person, site, active order, scheduled cell, performer and dose; keep coded omission and append-only correction evidence.
- Native design direction: Use a native privileged override grant with reason, scope, expiry, optional checker and immutable event linkage.
- Interim safeguard: Do not use the override action; medication lead reviews every override note and over-limit PRN attempt daily.
- Acceptance: Given the actor and boundary described, when the failing sequence is attempted, then the operation is denied or completed atomically with no foreign disclosure, partial mutation or duplicate effect.; Require server-verified override capability and structured reason.; Bind approval to the resident, medication and failed check.; Always emit the configured safety incident.; Test ordinary/privileged actors, forged JSON, replay and concurrency.
- Owner / effort / confidence: Medication Safety and Clinical Governance / M / High
- Evidence typing: source-observed behavior; failure/impact inference; priority/design is the named specialist owner's decision. No third-party code/UI/schema/wording may be copied.

### MED-SCOPE-01 — P0 — Medication relational and assignment scope

- Feature IDs: `MED-GUIDED-ROUND`, `MED-WORKER-MEDS`, `MED-EMAR`, `MED-MEDICATIONS-API`
- Actor/job: A medication worker records a scheduled or PRN administration, completes a round, or changes an order.
- Current behavior: Round, medication, resident, prescription, PRN-effect, shift, assignee and site relationships are not consistently proven. Worker writes may proceed without an active shift; prescription/medication updates accept independently existing IDs.
- Failure sequence: An authorised caller substitutes an ID to administer or revise another resident's medication, attach a medication to the wrong round, change another PRN effect, cease another resident's medicine, or record off assignment.
- Boundary/root cause: Round-cell-medication-resident relationship plus active assignment/site or explicit audited emergency access. Existence and broad permission checks substitute for one server-authoritative medication aggregate.
- Impact: Wrong-person or off-assignment medication recording creates immediate medication-error and clinical-record risk.
- Evidence: `routes/emar.php:124-130`; `app/Http/Controllers/Emar/GuidedRoundController.php:63-67,162-187,208-212,270-294`; `app/Http/Controllers/Emar/WorkerMedsController.php:161-225,275-330,356-415,473-481`; `app/Http/Controllers/Emar/EmarController.php:2925-3014,4447-4486`
- Existing tests (not executed): GuidedRoundOfflineReplayTest; WorkerMedsRecordDoseTest; OneChartAdministrationSafetyTest
- Missing tests: Every wrong round/medication/resident/site pair; Off-board and no-shift administration; PRN-effect ownership; Cease-order resident mismatch; Medication reassignment; Concurrent round completion/administration
- Benchmark: Bahmni IPD frontend — Native benchmark; Time-aware due, completed, missed, stopped and not-done task grouping.
- Neutral requirements: Keep person-scoped due work, explicit exception states, chronological grouping and late-window feedback.
- Native design direction: Create one native administration command resolving resident, order, due cell, medication, performer and site before any write.
- Interim safeguard: Reconcile administrations to generated round cells, resident, site, assignee and active shift; pharmacy independently checks every changed or ceased medication.
- Acceptance: Given the actor and boundary described, when the failing sequence is attempted, then the operation is denied or completed atomically with no foreign disclosure, partial mutation or duplicate effect.; Use server-resolved round-cell identity.; Bind order, medication and resident IDs.; Require active assignment or approved break-glass.; Reject adversarial/stale/offline IDs atomically and test concurrency.
- Owner / effort / confidence: Medication Safety, Clinical Governance and Medication Backend / XL / High
- Evidence typing: source-observed behavior; failure/impact inference; priority/design is the named specialist owner's decision. No third-party code/UI/schema/wording may be copied.

### RESP-SCOPE-01 — P0 — Stay, resident and related-record scope

- Feature IDs: `RESP-RESPITE-DAILY-NOTE`, `RESP-RESPITE-STAY`, `RESP-RESPITE-EVIDENCE-PACK`
- Actor/job: A respite coordinator records a daily note or restraint and seals evidence.
- Current behavior: Stay, resident, linked incident and behaviour-plan IDs are independently existence-validated. Store lacks a resident access assertion, and a filled unrelated plan ID can satisfy evidence linkage.
- Failure sequence: A coordinator pairs stay B with resident A or links another resident's incident/plan, creating cross-associated notes, restraints or apparently complete sealed evidence.
- Boundary/root cause: Stay-resident-site relationship, related incident/plan ownership and sealed-evidence integrity. Independent ID validation is used where stay-owned relationship validation is required.
- Impact: Wrong-resident care evidence can drive unsafe respite decisions and disclose sensitive information.
- Evidence: `app/Http/Controllers/Respite/RespiteDailyNoteController.php:60-91,293-319`; `app/Http/Controllers/Respite/RespiteStayController.php:295-340`; `app/Http/Controllers/Respite/RespiteEvidencePackController.php:350-392`
- Existing tests (not executed): RespiteActionsTest; RespiteNzWorkflowCompletionTest
- Missing tests: Stay/resident mismatch; Incident and plan mismatch; Cross-site direct ID; Seal-time relationship revalidation; No partial incident creation
- Benchmark: No credible match — No credible match; Evidence gap—not a documented No Credible Match. Only catalogue-level candidates were retained for this Respite capability: Primero, OpenMRS Patient Management. No feature-specific query, beyond-catalogue search or exact rejected-repository evidence is retained; partial patterns were not stretched into a match.
- Neutral requirements: Preserve actor, owning record, site and role boundary, explicit states, validation, recovery, provenance and auditable hand-off in an original Oblivion-native workflow.
- Native design direction: Make the stay the native aggregate root for notes, restraints, incidents and evidence obligations.
- Interim safeguard: Reconcile notes and incidents to the stay resident; RN verifies every restraint's plan/incident before discharge or seal.
- Acceptance: Given the actor and boundary described, when the failing sequence is attempted, then the operation is denied or completed atomically with no foreign disclosure, partial mutation or duplicate effect.; Derive resident/site from a locked authorised stay.; Require every incident/plan to share resident/site and applicable stay.; Require active/current plan.; Revalidate all bindings at seal and test adversarial IDs.
- Owner / effort / confidence: Respite Clinical Safety and Backend / L / High
- Evidence typing: source-observed behavior; failure/impact inference; priority/design is the named specialist owner's decision. No third-party code/UI/schema/wording may be copied.

### RETENTION-EXEC-01 — P0 — Retention configuration and bulk execution

- Feature IDs: `PRIV-DATA-RETENTION-POLICY`, `PRIV-DATA-DELETION-LOG`
- Actor/job: A privacy administrator configures and executes a retention policy.
- Current behavior: A privileged request supplies an arbitrary Eloquent model class and conditions. Manual/daily execution deletes then irreversibly anonymizes old rows; invalid conditions may be skipped and manual exemption parity is incomplete.
- Failure sequence: A mistaken or crafted active policy selects a safety-critical model/rows and the daily job bulk soft-deletes/anonymizes records before a dependable preview/independent approval/restore invariant.
- Boundary/root cause: Approved model/field registry, legal holds/exemptions, dry-run delta, four-eyes activation, idempotency and restore evidence. Arbitrary data-model execution is exposed as policy configuration without a governed registry or safe activation lifecycle.
- Impact: Bulk scheduled irreversible anonymization can destroy clinical, safety, safeguarding or audit evidence.
- Evidence: `routes/privacy.php:61-80`; `app/Http/Controllers/DataRetentionPolicyController.php:61-84`; `app/Http/Controllers/DataDeletionLogController.php:61-178`; `app/Jobs/EnforceDataRetentionJob.php:23-150,211-274`; `routes/console.php:679-683`
- Existing tests (not executed): EnforceDataRetentionJobTest; PrivacyControllerTest
- Missing tests: Model/column allowlist; Manual/scheduled exemption parity; Preview and independent approval; Concurrency/idempotency; Restore
- Benchmark: No credible match — No credible match; Evidence gap—not a documented No Credible Match. Only catalogue-level candidates were retained for this Privacy and compliance capability: Medplum, OHC CARE, Primero. No feature-specific query, beyond-catalogue search or exact rejected-repository evidence is retained; partial patterns were not stretched into a match.
- Neutral requirements: Preserve actor, owning record, site and role boundary, explicit states, validation, recovery, provenance and auditable hand-off in an original Oblivion-native workflow.
- Native design direction: Create native retention adapters per record owner with preview, approval, holds, audit and restore checkpoints.
- Interim safeguard: Suspend manual execution and scheduled enforcement; keep policies inactive until model ownership, exemptions and backups are independently verified.
- Acceptance: Given the actor and boundary described, when the failing sequence is attempted, then the operation is denied or completed atomically with no foreign disclosure, partial mutation or duplicate effect.; Explicit per-model retention/anonymizer registry.; Draft to independent approval with dry-run counts/samples.; Mandatory hold/exemption parity.; Lock/idempotency plus tested restore.
- Owner / effort / confidence: Privacy Officer, Records Owner and Data Platform / XL / High
- Evidence typing: source-observed behavior; failure/impact inference; priority/design is the named specialist owner's decision. No third-party code/UI/schema/wording may be copied.

### SAFE-NESTED-01 — P0 — Safeguarding child-resource substitution

- Feature IDs: `INC-SAFEGUARDING-INVESTIGATION`, `INC-SAFEGUARDING-EXTERNAL-REPORT`, `INC-SAFEGUARDING-ACTION-PLAN`
- Actor/job: A safeguarding assignee updates investigation, external-report or action-plan work on their concern.
- Current behavior: Parent concern and child are independently bound. Controller authorizes only the supplied parent and never verifies the child's safeguarding_concern_id; the attachment controller shows the intended guard pattern.
- Failure sequence: An assignee authorized for concern A substitutes a child ID from concern B and updates/completes B's protective work.
- Boundary/root cause: Parent-child integrity, actual concern ownership, site access and protective-record audit. Independent route binding detaches child mutation from the concern used for authorization.
- Impact: An actor can alter or close protective work on an unrelated active safeguarding concern.
- Evidence: `routes/safeguarding.php:63,71,90,92`; `app/Http/Controllers/SafeguardingInvestigationController.php:50-83`; `app/Http/Controllers/SafeguardingExternalReportController.php:56-85`; `app/Http/Controllers/SafeguardingActionPlanController.php:43-80`; `app/Policies/SafeguardingConcernPolicy.php:52-64`; `app/Http/Controllers/SafeguardingAttachmentController.php:51-73`
- Existing tests (not executed): SafeguardingWorkflowContractTest and monitoring tests cover same-parent paths
- Missing tests: Cross-parent investigation; Cross-parent external report; Cross-parent action update/complete; Hidden-site IDs; No audit side effect
- Benchmark: BeaconHS — Native benchmark; Five-step investigation and separately verified corrective-action closure; project is immature.
- Neutral requirements: Separate investigation evidence, root causes, accountable actions and independent effectiveness verification.
- Native design direction: Use one native concern-scoped child resolver for all safeguarding workflow records.
- Interim safeguard: Limit child mutations to the central safeguarding lead and reconcile changes to their true parent concerns.
- Acceptance: Given the actor and boundary described, when the failing sequence is attempted, then the operation is denied or completed atomically with no foreign disclosure, partial mutation or duplicate effect.; Resolve child through the concern relation or assert equality.; Authorize the child's actual parent.; Return 403/404 with no mutation/audit side effect.; Test all four operations and sites.
- Owner / effort / confidence: Safeguarding Lead, AppSec and Backend / M / High
- Evidence typing: source-observed behavior; failure/impact inference; priority/design is the named specialist owner's decision. No third-party code/UI/schema/wording may be copied.

### SITE-MEAL-CLIN-01 — P0 — Meal-plan clinical authority

- Feature IDs: `SITE-SITE-MEAL-PLAN`, `CLI-CLIENT-MEAL-PREFERENCES`, `SITE-DIETARY-TAG`
- Actor/job: A site meal planner prepares resident meals while clinical restrictions remain authoritative.
- Current behavior: Broadly seeded meal-plan roles can operate across sites and overwrite IDDSI, fluid, diet and allergy tags without a separate clinical author, provenance or review transition.
- Failure sequence: A meal planner opens another site/resident, alters texture, fluid or allergy-critical data and immediately changes operational meal planning.
- Boundary/root cause: Site access, resident identity, clinical dietary authority, provenance, effective dates and review. Operational meal-plan permissions own fields that should be clinical source data.
- Impact: An unqualified or wrong-site change can result in an unsafe meal, aspiration or allergen exposure.
- Evidence: `routes/sites.php:75,292-336`; `app/Http/Controllers/Sites/SiteMealPlanController.php:42-122,432-469`; `database/seeders/RbacSeeder.php:795-823`
- Existing tests (not executed): Catering and meal-plan happy-path tests
- Missing tests: Site-A/site-B list and mutation; Non-clinical role cannot change clinical restriction; Effective/expired restriction; Allergy/IDDSI conflict; Independent clinical approval
- Benchmark: Mealie — Native benchmark; Recipe and meal-plan workflows connect planned meals to structured recipe data.
- Neutral requirements: Keep planning, recipe, shopping and stock lineage explicit while retaining Oblivion clinical-authority controls.
- Native design direction: Separate clinical restriction ownership from the native planning/shopping workflow while keeping context visible and actionable.
- Interim safeguard: Treat clinical diet, IDDSI, fluid and allergy fields as read-only in catering and require clinical verification of any discrepancy.
- Acceptance: Given the actor and boundary described, when the failing sequence is attempted, then the operation is denied or completed atomically with no foreign disclosure, partial mutation or duplicate effect.; Keep one clinically governed restriction record.; Allow catering to plan against a read-only projection and raise discrepancies.; Require qualified approval, effective dates and immutable amendment history.; Test wrong-site and stale-plan cases.
- Owner / effort / confidence: Clinical Governance, Catering Product and Authorization Owners / L / High
- Evidence typing: source-observed behavior; failure/impact inference; priority/design is the named specialist owner's decision. No third-party code/UI/schema/wording may be copied.

### WF-ELIG-FAIL-OPEN — P0 — Assignment eligibility failure handling

- Feature IDs: `OPS-ROSTER`, `OPS-JOB-BOARD`
- Actor/job: A site manager assigns a worker or approves a claimed shift.
- Current behavior: Direct assignment and claimed-position approval catch any eligibility exception, log it and continue assigning. This does not apply to every rostering path; self-claim/auto scheduling have stronger checks.
- Failure sequence: Manager selects worker, eligibility service throws, catch suppresses the failure, assignment still writes, and no completed safety eligibility decision exists.
- Boundary/root cause: Safety eligibility for site-authorized managers; not a generic authorization bypass. Eligibility infrastructure failure is treated as permission to proceed in two high-impact assignment paths.
- Impact: An ineligible or unassessed worker can be assigned to care work after an internal eligibility error.
- Evidence: `app/Http/Controllers/ShiftController.php:1908-1993`; `app/Http/Controllers/Operations/JobBoardController.php:429-525`; `routes/operations.php:792-800,1174-1176`
- Existing tests (not executed): JobBoardControllerTest hard-block claim and stale ineligibility tests
- Missing tests: Injected exception denies direct assignment; Injected exception denies approval; No shift/position/audit mutation; Visible outage/retry
- Benchmark: Timefold Solver Community — Native benchmark; Pinned assignments and hard feasibility versus soft preference; score analysis is Enterprise-only.
- Neutral requirements: Treat safety and eligibility as hard, preferences as soft, preserve pinned choices and build native reason codes.
- Native design direction: Use a native fail-closed eligibility decision object with reason codes, outage state and governed override where explicitly approved.
- Interim safeguard: Stop direct assignment and job-board approval whenever eligibility cannot be positively completed; manually verify credential, leave, competency and fatigue prerequisites.
- Acceptance: Given the actor and boundary described, when the failing sequence is attempted, then the operation is denied or completed atomically with no foreign disclosure, partial mutation or duplicate effect.; Centralize the named paths on one fail-closed gateway.; Return actionable 422/503 on unavailable decision.; Keep hard/warn/pass states explicit.; Assert transaction rollback and exception injection.
- Owner / effort / confidence: Rostering and Workforce Platform / M / High
- Evidence typing: source-observed behavior; failure/impact inference; priority/design is the named specialist owner's decision. No third-party code/UI/schema/wording may be copied.

### WF-HR-PROFILE-SITE-PRIVACY — P0 — Site-bound employee profile privacy

- Feature IDs: `HR-EMPLOYEE-PROFILE`, `HR-HR-API`
- Actor/job: A coordinator or team lead finds and reviews staff relevant to their site.
- Current behavior: Site-context roles receive hr.employees.viewAny. People index and API/profile direct-object reads are organization-global; site_id is an optional user filter, not an authorization scope.
- Failure sequence: A site-A coordinator lists all staff or supplies a site-B profile/API ID and receives contact, leave, probation, performance or site metadata.
- Boundary/root cause: Single-tenant site access, employee privacy, direct-object denial and an explicit central-HR boundary. A broad HR permission is granted to site roles while the controller treats the site selector as voluntary filtering.
- Impact: Site-restricted roles can disclose another site's sensitive employee record and HR metadata.
- Evidence: `database/seeders/RbacSeeder.php:633-680,795-810`; `app/Http/Controllers/Hr/EmployeeProfileController.php:49-136,644-688`; `app/Http/Controllers/Api/HrApiController.php:26-55`; `routes/hr.php:255-273`; `routes/api-hr.php:6-14`
- Existing tests (not executed): EmployeePeopleIndexTest list and optional filter; EmployeeProfileDetailRegressionTest positive detail
- Missing tests: Two-site list/count isolation; Foreign profile/API denial; Secondary-site access; Central HR/admin/auditor positives
- Benchmark: Frappe HR — Native benchmark; Active-worker, overlap, cross-midnight and mandatory-onboarding gates.
- Neutral requirements: Fail closed on inactive worker, overlap and mandatory prerequisites; keep traceable approval and timezone rules.
- Native design direction: Build a native site-scoped staff directory projection and separately authorize sensitive HR sections.
- Interim safeguard: Restrict People and HR API to central HR/admin/auditor roles; never rely on the optional site filter.
- Acceptance: Given the actor and boundary described, when the failing sequence is attempted, then the operation is denied or completed atomically with no foreign disclosure, partial mutation or duplicate effect.; Apply accessible-site scope before list/count/API serialization.; Assert site access on profile detail.; Separate a minimum-data directory from sensitive sections.; Test coordinator, team lead, HR, admin, auditor and worker across two sites.
- Owner / effort / confidence: HR Platform, Privacy and Authorization / L / High
- Evidence typing: source-observed behavior; failure/impact inference; priority/design is the named specialist owner's decision. No third-party code/UI/schema/wording may be copied.

### CARE-SIGNOFF-01 — P1 — Care-plan sign-off authority

- Feature IDs: `OPS-CARE-PLAN`
- Actor/job: A resident, representative or clinician attests to a specific care-plan version.
- Current behavior: A care_plans.update actor enters party role/name/date/method while authenticated user is only recorder; review completion requires merely that a sign-off row exists.
- Failure sequence: Staff-entered proxy details satisfy the activation gate without authenticated signer, authority/capacity evidence or immutable plan-version attestation.
- Boundary/root cause: Signer versus recorder, representative authority/capacity, exact plan version and declined/unavailable states. A staff-entered label is treated as attestation evidence.
- Impact: A plan can appear agreed and complete without dependable assent or authority evidence.
- Evidence: `app/Http/Controllers/Operations/CarePlanController.php:465-519,543-594`
- Existing tests (not executed): CarePlanReviewIntegrityTest requires a fresh review-version row
- Missing tests: Signer authentication; Authority/capacity evidence; Plan digest; Declined/unavailable; Witnessed/portal path
- Benchmark: Bahmni IPD frontend — Native benchmark; Time-aware due, completed, missed, stopped and not-done task grouping.
- Neutral requirements: Keep person-scoped due work, explicit exception states, chronological grouping and late-window feedback.
- Native design direction: Use a native attestation workflow with explicit actor, authority, version and provenance.
- Interim safeguard: Label records as staff-recorded and require supporting evidence plus second review before treating them as agreement.
- Acceptance: Given the actor and boundary described, when the failing sequence is attempted, then the operation is denied or completed atomically with no foreign disclosure, partial mutation or duplicate effect.; Bind signer, recorder, authority basis and immutable version.; Support declined/unavailable/witnessed states.; Require authenticated portal or eligible witness where policy demands.; Obtain clinical/legal owner approval.
- Owner / effort / confidence: Care Planning and Clinical Governance / L / High
- Evidence typing: source-observed behavior; failure/impact inference; priority/design is the named specialist owner's decision. No third-party code/UI/schema/wording may be copied.

### CATER-SCOPE-003 — P1 — Meal-plan resident and recipe relationship

- Feature IDs: `SITE-SITE-MEAL-PLAN`, `SITE-RECIPE`
- Actor/job: A site meal planner assigns residents and recipes to a plan.
- Current behavior: Global existence validation accepts a foreign client or private-site recipe ID and persists it under current site.
- Failure sequence: Planner at site A supplies resident/recipe B and creates a mixed-site plan/conflict result.
- Boundary/root cause: Active current-site client and same-site or explicitly shared recipe relationship. Related IDs are validated globally rather than through site ownership.
- Impact: Wrong-site resident dietary data can be exposed or applied to a meal plan.
- Evidence: `app/Http/Controllers/Sites/SiteMealPlanController.php:245-324,625-643`
- Existing tests (not executed): Meal-plan happy paths
- Missing tests: Foreign client create/update; Private recipe; Inactive resident; Conflict recalculation; No side effect
- Benchmark: Mealie — Native benchmark; Recipe and meal-plan workflows connect planned meals to structured recipe data.
- Neutral requirements: Keep planning, recipe, shopping and stock lineage explicit while retaining Oblivion clinical-authority controls.
- Native design direction: Use a native site meal-plan aggregate resolving authorized residents/recipes server-side.
- Interim safeguard: Manually verify every selected resident belongs to displayed site.
- Acceptance: Given the actor and boundary described, when the failing sequence is attempted, then the operation is denied or completed atomically with no foreign disclosure, partial mutation or duplicate effect.; Scope clients to active current site.; Scope recipes to same-site/shared.; Revalidate conflicts.; Test two-site direct IDs create/update.
- Owner / effort / confidence: Catering and Sites Authorization / M / High
- Evidence typing: source-observed behavior; failure/impact inference; priority/design is the named specialist owner's decision. No third-party code/UI/schema/wording may be copied.

### CATER-STOCK-002 — P1 — Serve/unserve inventory atomicity

- Feature IDs: `SITE-SITE-MEAL-PLAN`, `SITE-SITE-MEAL-INVENTORY`
- Actor/job: A catering worker serves or reverses a planned meal and updates stock.
- Current behavior: Serve/unserve lacks one enclosing transaction, locking and occurrence idempotency; served state, movement journal and quantity can diverge under partial/concurrent execution.
- Failure sequence: Two workers serve/unserve or failure occurs between writes; meal status and stock journal/materialized balance disagree.
- Boundary/root cause: Meal/product occurrence identity, locked movement, linked reversal and reconciled quantity. One logical serve action spans multiple mutable records without an atomic occurrence key.
- Impact: Food stock and served records can disagree, undermining availability and audit.
- Evidence: `app/Http/Controllers/Sites/SiteMealPlanController.php:335-415`; `app/Services/Catering/InventoryMovementRecorder.php:34-70`; `database/migrations/2026_05_17_120010_create_site_meal_inventory_movements_table.php:10-34`
- Existing tests (not executed): Catering happy paths
- Missing tests: Parallel action; Injected failure; Retry; Journal-balance reconciliation; Unserve dependent movements
- Benchmark: Mealie — Native benchmark; Recipe and meal-plan workflows connect planned meals to structured recipe data.
- Neutral requirements: Keep planning, recipe, shopping and stock lineage explicit while retaining Oblivion clinical-authority controls.
- Native design direction: Use a native immutable stock movement journal and atomic meal-service command.
- Interim safeguard: Serialize serve/unserve and reconcile quantity from movement history.
- Acceptance: Given the actor and boundary described, when the failing sequence is attempted, then the operation is denied or completed atomically with no foreign disclosure, partial mutation or duplicate effect.; Lock meal/inventory in one transaction.; Unique per-meal/product/action movement.; Linked reversal.; Test failure/retry/concurrency/reconciliation.
- Owner / effort / confidence: Catering and Inventory / L / High
- Evidence typing: source-observed behavior; failure/impact inference; priority/design is the named specialist owner's decision. No third-party code/UI/schema/wording may be copied.

### CONSENT-AUTH-01 — P1 — Informational next-of-kin authority

- Feature IDs: `OPS-CONSENT-REQUEST`, `CLI-CONSENT-REQUEST-PORTAL`, `OPS-CLIENT-CONSENT`
- Actor/job: A resident or representative responds to a consent request and downstream services rely on the result.
- Current behavior: The model labels next-of-kin authority informational_only, yet approval materializes an active ClientConsent status=given. Generic downstream gates consume consent type/status/expiry without excluding informational authority.
- Failure sequence: An informational acknowledgement becomes active consent and can be used by a sensitive disclosure, portal section, tracking or device-assignment gate.
- Boundary/root cause: Self decision, verified current substitute authority, purpose/type scope, expiry/revocation and downstream consumption. The workflow explicitly recognizes informational authority but then collapses it into the same active consent state.
- Impact: A person without decision authority can create a consent record relied upon for a sensitive operation.
- Evidence: `app/Models/ConsentRequest.php:45-55,184-190`; `app/Services/ConsentRequestService.php:86-127,277-327`; `app/Services/ConsentValidationService.php:21-79`; `app/Services/Portal/PortalClientSectionAccess.php:121-150`; `app/Domain/SecurityDevices/Http/Controllers/DeviceAssignmentController.php:64-109`; `tests/Feature/Consents/ConsentRequestIntegrityTest.php:234-260`
- Existing tests (not executed): ConsentRequestIntegrityTest positively asserts informational approval materialises consent
- Missing tests: Informational never creates authoritative consent; Downstream denial; Authority/type matrix; Expired/revoked authority
- Benchmark: No credible match — No credible match; No sufficiently analogous verified behavior was found in the audited official-repository catalogue; partial patterns were not stretched into a match.
- Neutral requirements: Capture purpose, decision, period, source, representative basis and withdrawal effects.
- Native design direction: Build a native authority-aware consent decision model; keep informational acknowledgement separate and visible.
- Interim safeguard: Treat all informational_only records as non-authoritative and manually review existing records before any disclosure/tracking/device use.
- Acceptance: Given the actor and boundary described, when the failing sequence is attempted, then the operation is denied or completed atomically with no foreign disclosure, partial mutation or duplicate effect.; Separate acknowledgement from authoritative consent.; Require self-consent or verified decision-scoped substitute authority.; Make informational records unconsumable by authorization services.; Test every authority/type/downstream combination with clinical/legal owner.
- Owner / effort / confidence: Clinical Governance, Privacy Officer and Operations / L / High
- Evidence typing: source-observed behavior; failure/impact inference; priority/design is the named specialist owner's decision. No third-party code/UI/schema/wording may be copied.

### CONSENT-CAPACITY-01 — P1 — Capacity and best-interests evidence

- Feature IDs: `OPS-CONSENT-REQUEST`, `OPS-CLIENT-CONSENT`
- Actor/job: A verified representative participates where a resident may lack competence for a specific decision.
- Current behavior: A substitute relationship automatically creates capacity_assessed=true, lacks_capacity and best_interests=true without decision-specific assessment or evidenced substitute decision.
- Failure sequence: Relationship is selected and record asserts incapacity/best interests even though no separate assessment/decision was captured.
- Boundary/root cause: Relationship versus authority, decision-specific competence, assessor/evidence, scope/expiry and substitute decision. Substitute relationship is incorrectly used as evidence of both incapacity and decision process.
- Impact: Record may falsely state incapacity and best-interests decision prerequisites were met.
- Evidence: `app/Services/ConsentRequestService.php:287-313`; `tests/Feature/Consents/ConsentRequestIntegrityTest.php:152-187`
- Existing tests (not executed): ConsentRequestIntegrityTest positively asserts generated fields
- Missing tests: Relationship does not imply incapacity; Assessment evidence; Assessor/date; Authority scope/expiry; Decision evidence
- Benchmark: No credible match — No credible match; No sufficiently analogous verified behavior was found in the audited official-repository catalogue; partial patterns were not stretched into a match.
- Neutral requirements: Capture purpose, decision, period, source, representative basis and withdrawal effects.
- Native design direction: Build a native decision-specific capacity and substitute-authority workflow.
- Interim safeguard: Do not treat generated fields as clinical/legal evidence; require supporting documentation.
- Acceptance: Given the actor and boundary described, when the failing sequence is attempted, then the operation is denied or completed atomically with no foreign disclosure, partial mutation or duplicate effect.; Separate relationship, authority, capacity assessment and decision.; Capture assessor/evidence/scope/expiry.; Test every combination.; Obtain clinical/legal approval.
- Owner / effort / confidence: Clinical Governance, Legal and Privacy / L / High
- Evidence typing: source-observed behavior; failure/impact inference; priority/design is the named specialist owner's decision. No third-party code/UI/schema/wording may be copied.

### CTRL-RBAC-001 — P1 — Queue counts and creation pickers

- Feature IDs: `CR-CONTROL-ROOM-ALERT`
- Actor/job: A view-only site-bound support worker monitors alerts.
- Current behavior: Alert rows are site-scoped, but global queue counts and all client/site creation-picker props are serialized to view-only workers.
- Failure sequence: Site-A viewer loads the queue and receives counts/names/IDs for hidden site-B records even when creation is unavailable.
- Boundary/root cause: Same authorized alert scope for rows, counts and pickers; minimum necessary props. Row queries are scoped but adjacent derived props use global sources.
- Impact: Sensitive client/site identities and operational load leak outside a role's site scope.
- Evidence: `routes/control-room.php:43-53`; `database/seeders/RbacSeeder.php:722`; `app/Http/Controllers/ControlRoom/ControlRoomAlertController.php:33-47,140-192`
- Existing tests (not executed): Control Room queue tests
- Missing tests: Two-site counts; View-only prop absence; Create-role scoped pickers; No foreign IDs/names
- Benchmark: OneUptime Community — Native benchmark; Observable timed escalation attempts and acknowledgement.
- Neutral requirements: Keep severity, responder, timed escalation, delivery evidence and terminal handover without client-data publication.
- Native design direction: Create a native scoped alert-view presenter used for rows, aggregates and pickers.
- Interim safeguard: Remove create props for view-only roles and ignore global counts until reconciled.
- Acceptance: Given the actor and boundary described, when the failing sequence is attempted, then the operation is denied or completed atomically with no foreign disclosure, partial mutation or duplicate effect.; Derive counts/options from authorized base query.; Serialize creation props only when allowed.; Test view/create roles across two sites.
- Owner / effort / confidence: Control Room and Authorization / M / High
- Evidence typing: source-observed behavior; failure/impact inference; priority/design is the named specialist owner's decision. No third-party code/UI/schema/wording may be copied.

### CTRL-SIGNAL-002 — P1 — Concurrent signal-to-alert deduplication

- Feature IDs: `CAP-CR-SIGNAL-TO-ALERT-PIPELINE`
- Actor/job: The signal processor converts one normalized signal into one operational alert.
- Current behavior: Concurrent workers can both pass a detached signal-status check and create separate alerts; last signal update wins and leaves an open orphan alert.
- Failure sequence: Two jobs process one origin simultaneously and both create alerts before either marks the signal.
- Boundary/root cause: Stable signal identity, atomic claim, one active alert and crash-safe retry. Idempotency is checked in mutable application state without an atomic/unique invariant.
- Impact: Duplicate live alerts split acknowledgement, escalation and closure ownership.
- Evidence: `app/Services/ControlRoom/SignalProcessingService.php:120-156,191-246`; `database/migrations/2026_02_04_000100_create_control_room_signal_system.php:99-132`
- Existing tests (not executed): Happy idempotency tests
- Missing tests: Parallel jobs; Crash after alert before signal update; Retry existing alert; Database uniqueness
- Benchmark: OneUptime Community — Native benchmark; Observable timed escalation attempts and acknowledgement.
- Neutral requirements: Keep severity, responder, timed escalation, delivery evidence and terminal handover without client-data publication.
- Native design direction: Use native signal claim and unique alert provenance within the Control Room owner.
- Interim safeguard: Use one processing worker and monitor duplicate origin-signal IDs.
- Acceptance: Given the actor and boundary described, when the failing sequence is attempted, then the operation is denied or completed atomically with no foreign disclosure, partial mutation or duplicate effect.; Atomic signal claim/row lock.; Unique origin-signal alert key.; Retries return existing alert.; Validate parallel jobs/crash recovery.
- Owner / effort / confidence: Control Room Platform / M / High
- Evidence typing: source-observed behavior; failure/impact inference; priority/design is the named specialist owner's decision. No third-party code/UI/schema/wording may be copied.

### FIN-BANK-RECON-01 — P1 — Bank reconciliation relationship and terminal state

- Feature IDs: `FIN-BANK-RECONCILIATION`, `FIN-BANK-TRANSACTION`
- Actor/job: A finance officer imports, matches, completes or corrects a bank reconciliation.
- Current behavior: Imports lack a proven unique fingerprint. Mutations do not consistently lock/prove reconciliation, account, transaction and journal-line membership; unmatching an adjustment can leave its GL and completed records remain mutable.
- Failure sequence: Duplicate/cross-account transaction is matched or adjusted, reconciliation completes, then is mutated/unmatched while GL and statement provenance diverge.
- Boundary/root cause: Bank account/reconciliation membership, status locks, site/finance scope and correction lineage. The UI aggregate does not enforce one atomic relationship among statement, account, reconciliation and GL.
- Impact: Bank workspace and GL can disagree while a completed reconciliation appears authoritative.
- Evidence: `app/Domain/Finance/Services/BankReconciliationService.php:17-78,195-221,230-285,290-349`
- Existing tests (not executed): Bank/reconciliation tests inventoried but not executed
- Missing tests: Duplicate import; Cross-account/reconciliation ID; Concurrent match/complete; Adjustment unmatch reversal; Post-completion mutation
- Benchmark: Bigcapital — Native benchmark; Bank match row locks, membership checks, amount tolerance and transactional unmatch.
- Neutral requirements: Lock bank sources, validate membership and amount atomically, and define recoverable unmatch.
- Native design direction: Use a native locked reconciliation aggregate and explicit post-completion amendment/reversal workflow.
- Interim safeguard: Operationally lock completed reconciliations, prohibit adjustment unmatch and inspect orphan adjustment journals.
- Acceptance: Given the actor and boundary described, when the failing sequence is attempted, then the operation is denied or completed atomically with no foreign disclosure, partial mutation or duplicate effect.; Unique import fingerprint.; Same-account membership plus row locks.; Amend/reverse after completion.; Linked reversal for adjustment unmatch and full negative/concurrency tests.
- Owner / effort / confidence: Finance Banking / L / High
- Evidence typing: source-observed behavior; failure/impact inference; priority/design is the named specialist owner's decision. No third-party code/UI/schema/wording may be copied.

### FIN-CLIENT-FUNDS-01 — P1 — Client-fund overdraft and segregation

- Feature IDs: `OPS-CLIENT-FUND`
- Actor/job: An authorized custodian credits or debits money held for a client.
- Current behavior: The service has row locking, decimal math and UUID idempotency, but debit does not enforce non-negative balance; one manage permission can create, credit and debit, and generic trust GL lacks proven client-level reconciliation dimension.
- Failure sequence: One actor debits beyond available client balance; aggregate trust GL balances while a client-level deficit or misuse persists.
- Boundary/root cause: Named client/site, segregated custody authority, no overdraft and per-client trust reconciliation. Strong technical idempotency lacks client-money policy controls and dimensional reconciliation.
- Impact: Client-held money can be overdrawn or moved without dual control while aggregate accounting still balances.
- Evidence: `routes/operations.php:1150-1158`; `app/Domain/Finance/Services/ClientFundTransactionService.php`
- Existing tests (not executed): Source includes idempotency/locking/concurrency strengths
- Missing tests: Insufficient balance; Maker/checker; Wrong client; Per-client subledger to trust GL
- Benchmark: No credible match — No credible match; Evidence gap—not a documented No Credible Match. Only catalogue-level candidates were retained for this Operations and rostering capability: Frappe HR, Timefold, Kimai, Bahmni IPD, ERPNext. No feature-specific query, beyond-catalogue search or exact rejected-repository evidence is retained; partial patterns were not stretched into a match.
- Neutral requirements: Preserve actor, owning record, site and role boundary, explicit states, validation, recovery, provenance and auditable hand-off in an original Oblivion-native workflow.
- Native design direction: Add native client-money approval and subledger control without weakening existing locks/idempotency.
- Interim safeguard: Manual dual approval, no overdraft and daily per-client trust reconciliation.
- Acceptance: Given the actor and boundary described, when the failing sequence is attempted, then the operation is denied or completed atomically with no foreign disclosure, partial mutation or duplicate effect.; Preserve locking/idempotency.; Enforce available balance.; Separate maker/approver.; Prove per-client reconciliation and direct-object denial.
- Owner / effort / confidence: Client Funds, Finance and Privacy / L / High
- Evidence typing: source-observed behavior; failure/impact inference; priority/design is the named specialist owner's decision. No third-party code/UI/schema/wording may be copied.

### FIN-INSIGHTS-DIRECT-OBJECT-01 — P1 — Financial Insights direct-object scope

- Feature IDs: `CAP-FIN-API-CLIENT-FINANCIAL-SUMMARY-LEDGER`, `CAP-FIN-API-SITE-FINANCIAL-SUMMARY`
- Related, not exact links: `CAP-FIN-API-FINANCIAL-INSIGHTS`, `CAP-FIN-API-FINANCIAL-KPIS`, `CAP-FIN-API-SITES-FINANCIAL-OVERVIEW` require downstream query-scope review.
- Actor/job: A finance-dashboard user requests financial detail for one client or one site in the single-tenant multi-site application.
- Current behavior: All eight Financial Insights API routes use `permission:finance.dashboard`. The inspected client/site detail paths accept integer object IDs and load the supplied client/site without a policy, `authorize()`, site-access service or equivalent caller-to-record guard; client summary uses `Client::withTrashed()`. No target-specific wrong-client, wrong-site or deleted-client denial test was found.
- Failure sequence: If the broad permission is granted to a site-limited role or a global exception was not deliberate, changing a client/site path ID could disclose out-of-scope financial information. This is an evidence-backed risk inference, not a reproduced exploit.
- Boundary/root cause: Role intent, site assignment, client relationship, direct-object denial, deleted-client visibility and minimum-necessary disclosure; this is not tenant isolation. A broad dashboard permission is used without an explicit object-scope resolver or documented global exception.
- Impact: Another site's client name, ledger, personal transactions, cost, funding, staffing or occupancy data could be exposed if the inferred precondition holds; deployed exploitability remains unverified.
- Evidence: `routes/finance.php:720-742`; `app/Domain/Finance/Http/Controllers/FinancialInsightsApiController.php:35-47,68-95`; `app/Domain/Finance/Services/ClientFinancialSummaryService.php:25-35`; `app/Domain/Finance/Services/ClientLedgerService.php:35-97`; `app/Domain/Finance/Services/SiteFinancialDashboardService.php:25-83,88-99`; `evidence/source/finance-insights-direct-object-review.json`
- Existing tests (not executed): Static search found no target-specific wrong-client, wrong-site or deleted-client denial test in `tests/Feature/Finance`.
- Missing tests: Allowed scoped client/site; wrong-client and wrong-site denial; nonexistent IDs; deleted-client rule; explicit global-finance exception; aggregate-query scope review.
- Benchmark: No credible match — no disposition is inherited for these newly audit-assigned current-manifest API IDs; target-specific research remains completion-unproved.
- Native design direction: Classify every endpoint as global, organization, site or client-relationship scoped, then use one explicit finance object-scope resolver before object loading; represent a global exception and deleted-client visibility deliberately.
- Interim safeguard: Grant `finance.dashboard` only to explicitly approved global finance roles until the scope and runtime denial contract are documented and tested.
- Acceptance: Enforce scope before any existence/name/count/amount/deleted state is disclosed; return a consistent 403/404 with no payload leakage; prove scoped allowance, wrong-object denial and the separate global exception using synthetic two-site fixtures.
- Owner / effort / confidence: Finance Product, Authorization Platform, Security and Privacy / M / High for the missing guard/test; runtime exploitability and intended global role scope unverified.
- Evidence typing: missing target-level guard/test is source-proved; disclosure/exploitability is conditional inference; endpoint scope, global exception and deleted-client visibility are specialist decisions.

### FIN-CONSOLIDATION-01 — P1 — Consolidation product boundary and partial state

- Feature IDs: `FIN-CONSOLIDATION`, `FIN-INTERCOMPANY`
- Actor/job: A finance administrator runs consolidation/intercompany processing.
- Current behavior: Arbitrary organization/entity IDs can be attached outside the established single-tenant multi-site product boundary. Elimination mutates sources incrementally; partial failure changes subsequent selection, and balance-sheet classes use period movement rather than full carrying balances.
- Failure sequence: A run journals/marks some rows, later fails, retry skips prior rows, producing a partial report; arbitrary entities can also be admitted.
- Boundary/root cause: Single-tenant product scope, approved legal entity/site reporting, atomic run and carrying-balance correctness. A multi-organization accounting concept is present without a proven product boundary or atomic run model.
- Impact: A partial or out-of-bound run can materially misstate consolidated financials and alter future runs.
- Evidence: `routes/finance.php:609-623`; `app/Domain/Finance/Services/ConsolidationService.php:24-81,224-259`; `app/Domain/Finance/Http/Controllers/ConsolidationController.php:134`
- Existing tests (not executed): No executed proof of entity scope, atomic retry or carrying values
- Missing tests: Arbitrary entity ID; Mid-run failure/retry; Repeat equivalence; Opening balance/currency; Carrying values
- Benchmark: No credible match — No credible match; Evidence gap—not a documented No Credible Match. Only catalogue-level candidates were retained for this Finance and funding capability: ERPNext, Bigcapital, Dolibarr, LedgerSMB, Odoo Community. No feature-specific query, beyond-catalogue search or exact rejected-repository evidence is retained; partial patterns were not stretched into a match.
- Neutral requirements: Preserve actor, owning record, site and role boundary, explicit states, validation, recovery, provenance and auditable hand-off in an original Oblivion-native workflow.
- Native design direction: Quarantine rather than expand; any retained design must be an original site/legal-entity reporting workflow within Oblivion intent.
- Interim safeguard: Quarantine consolidation/intercompany routes and review any existing cross-record journals.
- Acceptance: Given the actor and boundary described, when the failing sequence is attempted, then the operation is denied or completed atomically with no foreign disclosure, partial mutation or duplicate effect.; Prefer removal/disablement under current product intent.; If retained, explicitly constrain approved same-legal-entity sites.; Stage and atomically commit one immutable run.; Prove clean-run/retry equivalence.
- Owner / effort / confidence: Finance Architecture and Product / XL / High
- Evidence typing: source-observed behavior; failure/impact inference; priority/design is the named specialist owner's decision. No third-party code/UI/schema/wording may be copied.

### FIN-DONOR-FUND-01 — P1 — Donor-fund posting and source linkage

- Feature IDs: `FIN-DONOR-FUND`
- Actor/job: A finance officer records restricted receipt and expenditure.
- Current behavior: Receipt credits the fund account; expenditure also credits that account while debiting expense, and a bill-linked expenditure can separately duplicate the bill expense. Locking, unique source and reversal are not proven.
- Failure sequence: A donor expenditure is recorded alongside a supplier bill; fund balance polarity and expense are misstated without one canonical application/source.
- Boundary/root cause: Restricted fund, approved accounting policy, source bill/site/program and exactly-once application. Fund release/application lacks an approved source-linked accounting invariant.
- Impact: Restricted balances and expenses may be materially misstated.
- Evidence: `routes/finance.php:691-717`; `app/Domain/Finance/Services/DonorFundService.php`
- Existing tests (not executed): No executed fund roll-forward/reversal proof
- Missing tests: Receipt/expenditure roll-forward; Bill source once; Concurrent replay; Restriction/overspend; Reversal/reclass
- Benchmark: No credible match — No credible match; Evidence gap—not a documented No Credible Match. Only catalogue-level candidates were retained for this Finance and funding capability: ERPNext, Bigcapital, Dolibarr, LedgerSMB, Odoo Community. No feature-specific query, beyond-catalogue search or exact rejected-repository evidence is retained; partial patterns were not stretched into a match.
- Neutral requirements: Preserve actor, owning record, site and role boundary, explicit states, validation, recovery, provenance and auditable hand-off in an original Oblivion-native workflow.
- Native design direction: Implement a native restricted-fund subledger based on the approved policy and exactly-once source links.
- Interim safeguard: Do not independently post the donor expenditure and bill; maintain accountant-reviewed fund roll-forward.
- Acceptance: Given the actor and boundary described, when the failing sequence is attempted, then the operation is denied or completed atomically with no foreign disclosure, partial mutation or duplicate effect.; Approve accounting policy.; One immutable source-to-fund application.; Recognize each source once.; Test release/reclassification, replay, overspend and reversal.
- Owner / effort / confidence: Finance Funding and Accounting Policy / L / High source; medium policy
- Evidence typing: source-observed behavior; failure/impact inference; priority/design is the named specialist owner's decision. No third-party code/UI/schema/wording may be copied.

### FIN-GL-RECURRING-01 — P1 — Recurring-journal occurrence idempotency

- Feature IDs: `FIN-JOURNAL`
- Actor/job: The scheduler posts a due recurring journal occurrence.
- Current behavior: Due schedules are selected without a proven row lock/unique occurrence. Posting precedes schedule advancement and failures are caught without a user-facing recovery state.
- Failure sequence: Two workers or a partial retry select one due timestamp, create duplicate journals or leave a posted journal with stale next_run_at.
- Boundary/root cause: Backend scheduler, authorized configuration, occurrence identity and visible recovery. No durable occurrence identity spans posting and schedule advancement.
- Impact: Duplicate or missing recurring postings can distort every financial report and close.
- Evidence: `app/Domain/Finance/Services/RecurringJournalService.php:21-53`; `app/Domain/Finance/Jobs/GenerateRecurringJournalsJob.php:18-28`
- Existing tests (not executed): No exact concurrent occurrence test proven; tests not executed
- Missing tests: Concurrent workers; Same due-timestamp replay; Failure before/after post; Failed occurrence UI/retry
- Benchmark: Odoo Community — Native benchmark; Draft, posted and cancelled moves with inverse reversal and bank undo.
- Neutral requirements: Use explicit immutable lifecycle, inverse correction lineage and recoverable unmatch.
- Native design direction: Add a native recurring-occurrence ledger and atomic scheduler state machine.
- Interim safeguard: Run one scheduler and reconcile REC references to expected occurrences each period.
- Acceptance: Given the actor and boundary described, when the failing sequence is attempted, then the operation is denied or completed atomically with no foreign disclosure, partial mutation or duplicate effect.; Unique schedule-ID plus due-time occurrence key.; Lock schedule and atomically post/advance.; Concurrent workers produce one journal.; Expose failed/retry history.
- Owner / effort / confidence: Finance Platform / M / High
- Evidence typing: source-observed behavior; failure/impact inference; priority/design is the named specialist owner's decision. No third-party code/UI/schema/wording may be copied.

### FIN-GL-REVERSAL-01 — P1 — Exactly-once journal reversal

- Feature IDs: `FIN-JOURNAL`
- Actor/job: An authorised finance officer reverses one posted journal.
- Current behavior: Reversal does not lock the source, reject an existing reversal or enforce one unique reversal lineage inside a proven outer transaction.
- Failure sequence: Two workers see one source as reversible and both create/post inverse journals.
- Boundary/root cause: Authorized source journal, open period, exactly-one reversal and immutable lineage. The lifecycle is implemented as sequential writes without an exactly-once source invariant.
- Impact: Duplicate reversal materially corrupts the general ledger.
- Evidence: `app/Domain/Finance/Services/JournalPostingService.php:176-215`; `app/Domain/Finance/Services/AccountsPayableService.php:153-205,309-402`
- Existing tests (not executed): Journal happy-path tests inventoried; concurrency not executed
- Missing tests: Two simultaneous requests; Replay after success; Mid-operation failure; Wrong-record/closed-period
- Benchmark: Odoo Community — Native benchmark; Draft, posted and cancelled moves with inverse reversal and bank undo.
- Neutral requirements: Use explicit immutable lifecycle, inverse correction lineage and recoverable unmatch.
- Native design direction: Use an Oblivion-native immutable reversal command with source lock, inverse lines and unique lineage.
- Interim safeguard: One operator per reversal and daily duplicate source/reversal-reference review.
- Acceptance: Given the actor and boundary described, when the failing sequence is attempted, then the operation is denied or completed atomically with no foreign disclosure, partial mutation or duplicate effect.; Lock the source in one transaction.; Enforce one durable reversal pointer/unique key.; Assert one concurrent request succeeds.; Inject failure and prove rollback.
- Owner / effort / confidence: Finance Platform / M / High
- Evidence typing: source-observed behavior; failure/impact inference; priority/design is the named specialist owner's decision. No third-party code/UI/schema/wording may be copied.

### FIN-GST-01 — P1 — GST return completeness and basis

- Feature IDs: `FIN-GST-RETURN`, `FIN-INVOICE`, `FIN-BILL`, `FIN-CREDIT-NOTE`
- Actor/job: A finance officer prepares an NZ GST return from posted source transactions.
- Current behavior: GST preparation reads journal lines with tax_rate_id. Invoice posting splits GST but omits that metadata; AP posts gross expense/AP without the documented input-GST split. Invoice, payments and hybrid selections do not produce distinct timing logic.
- Failure sequence: Normal invoices/bills/credits/payments are posted; a payments or hybrid return is prepared; components are omitted or timed as accrual journal activity; reported total diverges from sources/settlements.
- Boundary/root cause: Single-organization tax reporting, source-document completeness and selected-basis period timing; accounting interpretation requires an NZ tax owner. The return depends on tax metadata and timing that normal subledger postings do not consistently preserve.
- Impact: Return values can be materially incomplete or in the wrong filing period.
- Evidence: `app/Domain/Finance/Services/GstReturnService.php:17-103`; `app/Domain/Finance/Http/Controllers/GstReturnController.php:79-82`; `app/Domain/Finance/Services/FinInvoiceJournalService.php:39-70`; `app/Domain/Finance/Services/AccountsPayableService.php:166-194,320-323`
- Existing tests (not executed): Finance tests inventoried but not executed; no mixed-tax/basis oracle was proven
- Missing tests: Invoice/payments/hybrid timing; Mixed/exempt/zero-rated; Bills/invoices/credits/partial payments; Amendment/closed period/concurrency
- Benchmark: No credible match — No credible match; Evidence gap—not a documented No Credible Match. Only catalogue-level candidates were retained for this Finance and funding capability: ERPNext, Bigcapital, Dolibarr, LedgerSMB, Odoo Community. No feature-specific query, beyond-catalogue search or exact rejected-repository evidence is retained; partial patterns were not stretched into a match.
- Neutral requirements: Preserve actor, owning record, site and role boundary, explicit states, validation, recovery, provenance and auditable hand-off in an original Oblivion-native workflow.
- Native design direction: Build a native source-tax ledger with explicit taxable components, settlement allocation and basis-specific return projection.
- Interim safeguard: Do not file from the application; reconcile all sources, payments and GL to an accountant-controlled return.
- Acceptance: Given the actor and boundary described, when the failing sequence is attempted, then the operation is denied or completed atomically with no foreign disclosure, partial mutation or duplicate effect.; Create an accountant-approved source oracle.; Implement basis-specific event timing.; Prove every component appears once in the correct period.; Test amendments and concurrent preparation.
- Owner / effort / confidence: Finance Domain Owner and NZ Tax Accountant / XL / High source; medium accounting-policy
- Evidence typing: source-observed behavior; failure/impact inference; priority/design is the named specialist owner's decision. No third-party code/UI/schema/wording may be copied.

### FIN-PAYMENT-MATCH-01 — P1 — Payment-match concurrency and reversal

- Feature IDs: `FIN-PAYMENT-MATCH`, `FIN-MATCH-RULE`
- Actor/job: A finance user or rule confirms a bank payment match.
- Current behavior: Auto confirmation may post, rule threshold can be zero, and no proven unique confirmed match/transaction lock prevents two confirmations. A posted match can be rejected without linked reversal.
- Failure sequence: Manual and automatic actors race on one bank transaction, create multiple ledger effects, then status can become rejected without undoing GL.
- Boundary/root cause: Same-account transaction, one confirmed match, rule activation segregation and direct-object denial. Suggestion, confirmation, posting and rejection lack one transactional state invariant.
- Impact: One cash receipt can be recognized more than once or leave unreversed ledger effects.
- Evidence: `app/Domain/Finance/Services/PaymentMatchingService.php:171-216,277-306`; `app/Domain/Finance/Http/Controllers/MatchRuleController.php:45-72`; `app/Domain/Finance/Http/Controllers/PaymentMatchController.php:94-121,149-167,270-274`
- Existing tests (not executed): No executed proof of concurrent confirmation/one-match/reversal
- Missing tests: Concurrent manual; Auto/manual race; Zero threshold; Reject posted; Foreign bank ID
- Benchmark: Bigcapital — Native benchmark; Bank match row locks, membership checks, amount tolerance and transactional unmatch.
- Neutral requirements: Lock bank sources, validate membership and amount atomically, and define recoverable unmatch.
- Native design direction: Create a native match aggregate with unique source, preview, approval and reversible posted outcome.
- Interim safeguard: Disable auto-confirm; reject only unposted suggestions.
- Acceptance: Given the actor and boundary described, when the failing sequence is attempted, then the operation is denied or completed atomically with no foreign disclosure, partial mutation or duplicate effect.; One confirmed match per transaction.; Lock transaction and target.; Maker/checker rule activation.; Immutable posted state and explicit reversal.
- Owner / effort / confidence: Finance Banking and Security / L / High
- Evidence typing: source-observed behavior; failure/impact inference; priority/design is the named specialist owner's decision. No third-party code/UI/schema/wording may be copied.

### FIN-SETTLEMENT-01 — P1 — Prepared versus settled payment lifecycle

- Feature IDs: `FIN-PAYMENT-RUN`, `HR-PAYROLL-EXPORT`
- Actor/job: Finance/payroll prepares, exports and reconciles a payment file.
- Current behavior: This is not a non-atomicity claim: transactions exist. The gap is lifecycle/provenance—sources can be marked paid and bank GL credited before file download/bank acceptance; active-run uniqueness, overpayment and rejection recovery are not fully proven.
- Failure sequence: Run is processed, sources/GL show paid, file is never delivered or bank rejects it, and no authoritative rejected/corrected settlement state restores provenance.
- Boundary/root cause: Source/run uniqueness, bank account, maker/checker and settlement evidence. Internal processing completion is conflated with external settlement completion.
- Impact: Application state can say paid and reduce bank GL without evidence that the bank accepted settlement.
- Evidence: `app/Domain/Finance/Services/PaymentRunService.php:23-80,104-163`; `app/Domain/Finance/Http/Controllers/PaymentRunController.php:200-214`; `app/Domain/Hr/Services/PayrollExportService.php:141-225`; `app/Domain/Finance/Services/PayrollJournalService.php:214-294`; `app/Http/Controllers/Hr/PayrollExportController.php:218-235`
- Existing tests (not executed): Payment-run/payroll tests inventoried; bank rejection not executed
- Missing tests: Same source in two active runs; Overpayment; Not downloaded; Bank rejection; Self-approval; GL job failure
- Benchmark: LedgerSMB — Native benchmark; Separation of duties, reconciliation uniqueness and one reversal per source.
- Neutral requirements: Enforce required four-eyes, submitted-before-approved, one cleared match and one reversal per source.
- Native design direction: Use a native settlement state machine and immutable bank evidence/reconciliation hand-off.
- Interim safeguard: Operationally label as prepared, require four-eyes and reconcile file to bank acceptance/feed before treating as paid.
- Acceptance: Given the actor and boundary described, when the failing sequence is attempted, then the operation is denied or completed atomically with no foreign disclosure, partial mutation or duplicate effect.; Unique active-run membership.; Prepared/approved/exported/accepted/rejected/settled/reconciled states.; Move paid to confirmed settlement.; Test rejection, correction and no duplicate GL.
- Owner / effort / confidence: Finance Payments and HR Payroll / XL / High
- Evidence typing: source-observed behavior; failure/impact inference; priority/design is the named specialist owner's decision. No third-party code/UI/schema/wording may be copied.

### FLEET-MED-WITNESS-01 — P1 — Controlled-medication transport witness

- Feature IDs: `FLEET-RESIDENT-TRANSPORT`
- Actor/job: A transport worker records custody of controlled medication during a journey.
- Current behavior: Witness evidence may be free text or any existing user; only same-actor rejection is evident, without eligibility, presence, site or medication-custody binding.
- Failure sequence: Recorder supplies an unrelated user or free-text witness and completes controlled-medication handover evidence that appears dual-controlled.
- Boundary/root cause: Witness identity, eligibility, presence, site, medication/custody event and immutable attestation. A label/value is treated as dual-control evidence without a verified attestation workflow.
- Impact: Weak witness evidence undermines controlled-drug custody and investigation reliability.
- Evidence: `app/Http/Controllers/FleetAssets/ResidentTransportController.php:184-960,967-1040`
- Existing tests (not executed): Resident transport controlled-medication happy path
- Missing tests: Ineligible/off-site/non-present witness; Authenticated attestation; Exact custody-event binding; Refusal/unavailable witness; Correction without erasure
- Benchmark: No credible match — No credible match; Evidence gap—not a documented No Credible Match. Only catalogue-level candidates were retained for this Fleet and vehicles capability: Traccar, Traccar Web, Snipe-IT. No feature-specific query, beyond-catalogue search or exact rejected-repository evidence is retained; partial patterns were not stretched into a match.
- Neutral requirements: Preserve actor, owning record, site and role boundary, explicit states, validation, recovery, provenance and auditable hand-off in an original Oblivion-native workflow.
- Native design direction: Reuse a native, domain-neutral attestation service with medication-specific eligibility and immutable event binding.
- Interim safeguard: Require a named present eligible witness and manually confirm both signatures before relying on the transport record.
- Acceptance: Given the actor and boundary described, when the failing sequence is attempted, then the operation is denied or completed atomically with no foreign disclosure, partial mutation or duplicate effect.; Use authenticated witness acceptance bound to the exact medication/custody event.; Validate role, site and time overlap.; Preserve refusal and correction states.; Test forged IDs and concurrent handover.
- Owner / effort / confidence: Medication Governance and Fleet Safety Owners / M / High
- Evidence typing: source-observed behavior; failure/impact inference; priority/design is the named specialist owner's decision. No third-party code/UI/schema/wording may be copied.

### FUND-BIND-01 — P1 — Funding-claim relationship and monetisation provenance

- Feature IDs: `OPS-FUNDING-CLAIM`, `OPS-FUNDING`
- Actor/job: An operations funding officer creates and submits a claim from delivered support.
- Current behavior: Agreement, client, line, shift and timesheet are existence-validated independently; the same delivery can also create billing. Claim GL uses agreement accounts while caller-derived evidence/amount may be unrelated. Observer failure is swallowed and job tries once.
- Failure sequence: A claim combines client B/evidence C with agreement A, posts to A's funder accounts, or the same delivery is separately invoiced and claimed.
- Boundary/root cause: Agreement-client-line-period-delivery relationships, exactly-once monetisation, site access and posting recovery. Independent IDs and parallel monetisation paths lack one relational/source-use invariant.
- Impact: Revenue/receivable can be posted against the wrong funder/client or recognized twice.
- Evidence: `app/Http/Controllers/Operations/FundingClaimController.php:66-118,145-178`; `app/Domain/Finance/Services/FundingClaimJournalService.php:48-121`; `app/Observers/FundingClaimObserver.php:25-39`; `app/Domain/Finance/Jobs/PostFundingClaimJournalJob.php:18`; `app/Domain/Shifts/Timesheets/TimesheetApprovalService.php:373-401`; `app/Services/Operations/BillingService.php:34-107`
- Existing tests (not executed): FundingClaimJournalDispatchTest happy same-client idempotency
- Missing tests: Every mixed relationship; Same delivery invoice+claim; Duplicate/partial claim; Expired agreement/rate; Posting failure/replay
- Benchmark: No credible match — No credible match; Evidence gap—not a documented No Credible Match. Only catalogue-level candidates were retained for this Operations and rostering capability: Frappe HR, Timefold, Kimai, Bahmni IPD, ERPNext. No feature-specific query, beyond-catalogue search or exact rejected-repository evidence is retained; partial patterns were not stretched into a match.
- Neutral requirements: Preserve actor, owning record, site and role boundary, explicit states, validation, recovery, provenance and auditable hand-off in an original Oblivion-native workflow.
- Native design direction: Build a native delivery-provenance ledger and relationship-bound claim command, with external lifecycle and posting recovery.
- Interim safeguard: Manually reconcile each claim to agreement/client/evidence and maintain a delivery-ID duplicate register before submit/invoice.
- Acceptance: Given the actor and boundary described, when the failing sequence is attempted, then the operation is denied or completed atomically with no foreign disclosure, partial mutation or duplicate effect.; Resolve all evidence through one authorised agreement/client.; Create immutable delivery-to-monetisation provenance.; Allow one active use per permitted amount.; Expose GL failed/retry and test relationship, replay and concurrency.
- Owner / effort / confidence: Operations Funding and Finance / XL / High
- Evidence typing: source-observed behavior; failure/impact inference; priority/design is the named specialist owner's decision. No third-party code/UI/schema/wording may be copied.

### GOV-NESTED-01 — P1 — Governance child-record parent binding

- Feature IDs: `GOV-GOVERNANCE-MEETING`, `FIN-CONTROLLERS-BUDGET`
- Actor/job: A governance/finance actor changes an agenda item, budget line or adjustment.
- Current behavior: Agenda items, budget lines and adjustments are independently bound without parent equality; parent A's authorization/lock can be paired with child B. Adjacent allocation code contains the intended guard.
- Failure sequence: Actor selects editable parent A and child from locked/different B; B is mutated and totals/state may use wrong context.
- Boundary/root cause: Actual parent relationship, actual-parent lock/authorization and transactional recalculation. Independent nested bindings detach authorization and state from the child being changed.
- Impact: Locked meeting/budget integrity can be bypassed and wrong records changed.
- Evidence: `routes/governance.php:64-67,223-243`; `app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:218-247`; `app/Domain/Governance/Http/Controllers/BudgetController.php:202-234,268-294,328-347`
- Existing tests (not executed): GovernanceMeetingsTest; GovernanceBudgetsTest; Allocation guard source
- Missing tests: Cross-meeting agenda; Cross-budget line; Cross-budget adjustment; Locked real parent; Recalculation
- Benchmark: OpenSlides — Native benchmark; Constrained motion transitions, agenda cycle denial and incomplete-meeting close protection.
- Neutral requirements: Keep agenda ownership, permissioned decision states, minute provenance and close gates.
- Native design direction: Use native parent-scoped resolvers and actual-parent transactional totals.
- Interim safeguard: Restrict edits and reconcile changed children to actual parents.
- Acceptance: Given the actor and boundary described, when the failing sequence is attempted, then the operation is denied or completed atomically with no foreign disclosure, partial mutation or duplicate effect.; Scoped binding/equality.; Authorize/lock actual parent.; Transactionally recalculate actual budget.; Cross-parent negatives.
- Owner / effort / confidence: Governance Secretary, Finance and Backend / M / High
- Evidence typing: source-observed behavior; failure/impact inference; priority/design is the named specialist owner's decision. No third-party code/UI/schema/wording may be copied.

### HS-ASSURANCE-01 — P1 — Investigation approval assurance

- Feature IDs: `HS-HS-INVESTIGATION`, `HS-HS-CORRECTIVE-ACTION`
- Actor/job: An investigator submits findings for independent review and approval.
- Current behavior: Current actor can become reviewer/default approver, or an arbitrary existing approved_by_id is stored without that person performing authenticated approval; CAPA has a stronger distinct-verifier pattern.
- Failure sequence: Investigator completes and attributes approval to self/another user; assurance record exists without independent actual approval.
- Boundary/root cause: Distinct eligible investigator/reviewer/approver, authenticated transition and concurrency-safe rework. Approval identity is caller-supplied metadata rather than an action by the approver.
- Impact: Approval attribution may not represent an actual independent assurance decision.
- Evidence: `app/Http/Controllers/HealthSafety/HsInvestigationController.php:103-115`; `app/Services/HealthSafety/HsInvestigationService.php:223-257`; `app/Services/HealthSafety/HsCorrectiveActionService.php:257-286`; `tests/Feature/HealthSafety/HsInvestigationTest.php:188-249`
- Existing tests (not executed): HsInvestigationTest lifecycle
- Missing tests: Self-review denial; Named approver performs action; Eligible role; Concurrent approval/rework
- Benchmark: BeaconHS — Native benchmark; Five-step investigation and separately verified corrective-action closure; project is immature.
- Neutral requirements: Separate investigation evidence, root causes, accountable actions and independent effectiveness verification.
- Native design direction: Reuse a native attestation/approval service with H&S actor-separation rules.
- Interim safeguard: Require independently documented review outside the application for serious/notifiable investigations.
- Acceptance: Given the actor and boundary described, when the failing sequence is attempted, then the operation is denied or completed atomically with no foreign disclosure, partial mutation or duplicate effect.; Separate submit/review/approve actions.; Require distinct eligible actors.; Record authenticated acknowledgement/e-signature.; Test separation and concurrency.
- Owner / effort / confidence: H&S Governance / M / High
- Evidence typing: source-observed behavior; failure/impact inference; priority/design is the named specialist owner's decision. No third-party code/UI/schema/wording may be copied.

### HS-CLOSE-01 — P1 — H&S close blockers and override

- Feature IDs: `HS-HS-EVENT`
- Actor/job: An H&S lead closes an investigated event.
- Current behavior: Blockers cover investigation/CAPA only; WorkSafe, site preservation and linked alert are not hard blockers. Any non-empty override reason bypasses implemented blockers.
- Failure sequence: An authorized closer supplies text, closes while statutory/alert/protective work remains, and reduces visibility.
- Boundary/root cause: Non-overridable statutory/alert blockers, distinct exception authority and evidence/expiry. Free-text override is the only separation between blocked and terminal safety state.
- Impact: A terminal status can conceal unfinished statutory or operational safety work.
- Evidence: `app/Services/HealthSafety/HsEventService.php:179-237,252-305`; `app/Http/Controllers/HealthSafety/HsEventController.php:262-315`; `tests/Feature/HealthSafety/HsEventClosureTest.php:38-116`
- Existing tests (not executed): HsEventClosureTest positively asserts free-text override
- Missing tests: WorkSafe/preservation/alert blockers; Separate permission/approver; Expiry/review; Concurrency
- Benchmark: No credible match — No credible match; Evidence gap—not a documented No Credible Match. Only catalogue-level candidates were retained for this Health and safety capability: BeaconHS, CISO Assistant Community, OpenProject, Primero. No feature-specific query, beyond-catalogue search or exact rejected-repository evidence is retained; partial patterns were not stretched into a match.
- Neutral requirements: Preserve actor, owning record, site and role boundary, explicit states, validation, recovery, provenance and auditable hand-off in an original Oblivion-native workflow.
- Native design direction: Implement a native close-readiness aggregate with policy-owned hard and exceptional gates.
- Interim safeguard: Do not close events with pending WorkSafe, preservation or alert work; independent approval for exceptions.
- Acceptance: Given the actor and boundary described, when the failing sequence is attempted, then the operation is denied or completed atomically with no foreign disclosure, partial mutation or duplicate effect.; Add hard statutory/active-alert blockers.; Create dedicated exception permission and approval.; Record evidence/expiry.; Run blocker-by-role/concurrency matrix.
- Owner / effort / confidence: H&S and Compliance / M / High
- Evidence typing: source-observed behavior; failure/impact inference; priority/design is the named specialist owner's decision. No third-party code/UI/schema/wording may be copied.

### HS-NOTIFIABLE-01 — P1 — WorkSafe notifiable-event decision support

- Feature IDs: `HS-HS-EVENT`, `INC-INCIDENT`
- Actor/job: An H&S lead decides whether an event may require WorkSafe notification.
- Current behavior: Classifier recognizes death, hospitalization and generic critical severity, otherwise emits a definitive below-threshold result; it omits the complete specified injury/illness, dangerous incident and work-relatedness decision tree.
- Failure sequence: A real event falls outside the reduced categories and is labelled below threshold, or generic critical is overclassified.
- Boundary/root cause: Versioned official criteria, work-relatedness, uncertainty/escalation and accountable qualified sign-off. A narrow severity shortcut is presented as a complete statutory classification.
- Impact: False negative may miss statutory escalation and evidence preservation.
- Evidence: `app/Services/HealthSafety/NotifiableEventClassifier.php:24-76`; `resources/js/pages/health-safety/components/report-incident-dialog.tsx:134-218`; `tests/Feature/HealthSafety/NotifiableEventClassifierTest.php:22-83`
- Existing tests (not executed): Classifier test codifies reduced rules
- Missing tests: Specified injury/illness; Dangerous incident; Work-relatedness; Uncertain state; Version/effective date
- Benchmark: No credible match — No credible match; Evidence gap—not a documented No Credible Match. Only catalogue-level candidates were retained for this Health and safety capability: BeaconHS, CISO Assistant Community, OpenProject, Primero. No feature-specific query, beyond-catalogue search or exact rejected-repository evidence is retained; partial patterns were not stretched into a match.
- Neutral requirements: Preserve actor, owning record, site and role boundary, explicit states, validation, recovery, provenance and auditable hand-off in an original Oblivion-native workflow.
- Native design direction: Build NZ-specific native decision support with specialist-approved content and uncertainty, never autonomous legal determination.
- Interim safeguard: Treat automation as preliminary and require qualified H&S review for every potentially notifiable event.
- Acceptance: Given the actor and boundary described, when the failing sequence is attempted, then the operation is denied or completed atomically with no foreign disclosure, partial mutation or duplicate effect.; Implement owner-approved versioned decision tree.; Include uncertainty/needs-review.; Validate against official scenario matrix.; Record sign-off and effective source date.
- Owner / effort / confidence: H&S, Legal/Compliance and Product / L / High
- Evidence typing: source-observed behavior; failure/impact inference; priority/design is the named specialist owner's decision. No third-party code/UI/schema/wording may be copied.

### INCIDENT-ALERT-LIFECYCLE-01 — P1 — Incident close/reopen alert lifecycle

- Feature IDs: `INC-INCIDENT`, `CR-CONTROL-ROOM-INCIDENT`
- Actor/job: An incident coordinator closes or reopens a client incident while Control Room owns operational visibility.
- Current behavior: Close resolves the linked Control Room alert and catches failure without a durable retry. Reopen changes only incident state; no inverse alert activation/creation occurs, and some origins are skipped by observer bridging.
- Failure sequence: An authorized close suppresses the alert; a later reopen leaves the serious incident absent from the live queue, or resolution failure is swallowed.
- Boundary/root cause: Control Room-owned alert transition, idempotent incident signal, origin matrix, retry and reopen history. Incident state directly changes alert terminal state but the reverse transition and durable delivery are absent.
- Impact: A reopened high-risk incident can remain invisible to live response staff.
- Evidence: `app/Http/Controllers/IncidentController.php:908-1054`; `app/Observers/ClientIncidentObserver.php:52-70,149-183`; `tests/Feature/IncidentControllerTest.php:1203-1219,1447-1469,1584-1597`
- Existing tests (not executed): Close resolves alert; Reopen changes incident; Close/reopen incident-only test
- Missing tests: Reopen reactivates/creates alert; Origin matrix; Repeated reopen; Concurrent close/reopen; HsEvent coherence; Resolve failure replay
- Benchmark: BeaconHS — Native benchmark; Five-step investigation and separately verified corrective-action closure; project is immature.
- Neutral requirements: Separate investigation evidence, root causes, accountable actions and independent effectiveness verification.
- Native design direction: Use the native signal/outbox boundary for symmetric close/reopen requests and visible reconciliation.
- Interim safeguard: Reopening coordinator must manually notify Control Room and confirm an active alert; reconcile failed close resolutions.
- Acceptance: Given the actor and boundary described, when the failing sequence is attempted, then the operation is denied or completed atomically with no foreign disclosure, partial mutation or duplicate effect.; Emit idempotent close/reopen signals to Control Room.; Control Room validates its own transition.; Preserve history and origin.; Test failure/replay and concurrency.
- Owner / effort / confidence: Control Room, Incident Product and H&S / L / High
- Evidence typing: source-observed behavior; failure/impact inference; priority/design is the named specialist owner's decision. No third-party code/UI/schema/wording may be copied.

### INTEG-WEBHOOK-001 — P1 — Webhook site binding and replay semantics

- Feature IDs: `PLAT-WEBHOOK-RECEIVER`
- Actor/job: A configured integration sends an authenticated event for normalization and alert routing.
- Current behavior: A valid decrypted X-Integration-Key is required, so this is not unauthenticated. However, optional secondary HMAC, payload site_id not bound to provider configuration, and no-ID replay receiving unique persisted identity weaken source/site/replay integrity.
- Failure sequence: A key holder supplies a foreign site or replays no-ID content; it persists as separate events/signals and can create repeated/wrong-site alerts.
- Boundary/root cause: Configured provider/site/device binding, deterministic source identity, replay window and quarantined invalid mapping. Transport authentication does not establish event object scope or idempotent source identity.
- Impact: Authenticated but unbound/replayed integration data can create wrong-site or duplicate operational alerts.
- Evidence: `routes/integrations.php:26-30`; `app/Http/Controllers/Api/WebhookReceiverController.php:25-68,92-112`; `app/Services/Integration/IntegrationSignalNormaliser.php:299-324`; `tests/Feature/Integrations/WebhookReceiverTest.php:79-96`
- Existing tests (not executed): WebhookReceiverTest rejects invalid supplied signature
- Missing tests: Unsigned provider contract; Foreign site; Stale/replayed payload; Concurrent no-ID replay; Quarantine
- Benchmark: No credible match — No credible match; Evidence gap—not a documented No Credible Match. Only catalogue-level candidates were retained for this Platform and shared infrastructure capability: none recorded. No feature-specific query, beyond-catalogue search or exact rejected-repository evidence is retained; partial patterns were not stretched into a match.
- Neutral requirements: Preserve actor, owning record, site and role boundary, explicit states, validation, recovery, provenance and auditable hand-off in an original Oblivion-native workflow.
- Native design direction: Add a native provider contract and normalized-event identity/binding layer before Control Room routing.
- Interim safeguard: Require source IDs/signatures where provider supports them and monitor unmapped site/device IDs and duplicate fingerprints.
- Acceptance: Given the actor and boundary described, when the failing sequence is attempted, then the operation is denied or completed atomically with no foreign disclosure, partial mutation or duplicate effect.; Bind provider schema/site/device to configuration.; Require signature where contract mandates it.; Use replay window and deterministic fallback identity.; Reject/quarantine unmapped events and test replay/concurrency.
- Owner / effort / confidence: Integration Security and Control Room / L / High
- Evidence typing: source-observed behavior; failure/impact inference; priority/design is the named specialist owner's decision. No third-party code/UI/schema/wording may be copied.

### MED-RBAC-01 — P1 — Controlled-drug and stock action capability

- Feature IDs: `MED-EMAR`, `MED-CDLOSS-REPORT`
- Actor/job: A medication order manager records CD, stock, discrepancy or destruction evidence.
- Current behavior: Controlled-drug, balance, discrepancy and destruction mutations are reachable under medications.orders.manage and do not invoke the dedicated controlled-record/stock capability.
- Failure sequence: An orders-only actor directly records or closes CD evidence, changes stock or voids destruction despite lacking the dedicated authority.
- Boundary/root cause: Exact action capability, site/resident scope, recorder/witness separation and immutable correction. Route permission grouping collapses distinct medication-governance duties.
- Impact: A broad order role can alter controlled-drug and stock evidence outside intended authority.
- Evidence: `routes/emar.php:154,211-226,351-361`; `app/Http/Controllers/Emar/EmarController.php:4545-4566,4691-4706,4849-4870,4963-4979`
- Existing tests (not executed): ControlledDrugsTest; DestructionsTest; StockManagementTest
- Missing tests: Orders-only denial; Dedicated capability positive; Cross-site direct ID; Recorder/witness matrix
- Benchmark: Bahmni medication administration — Native benchmark; Structured performer, status, reason, effective time, request, medication, dose and notes.
- Neutral requirements: Bind person, site, active order, scheduled cell, performer and dose; keep coded omission and append-only correction evidence.
- Native design direction: Use native action-level permissions and common medication object scope checks.
- Interim safeguard: Grant orders.manage only alongside separately verified CD/stock authority and audit existing actions by capability.
- Acceptance: Given the actor and boundary described, when the failing sequence is attempted, then the operation is denied or completed atomically with no foreign disclosure, partial mutation or duplicate effect.; Gate every action by the exact capability and site/resident policy.; Make orders.manage alone fail.; Preserve distinct witness and void history.; Add direct-route capability matrices.
- Owner / effort / confidence: Medication Governance and Access Control / M / High
- Evidence typing: source-observed behavior; failure/impact inference; priority/design is the named specialist owner's decision. No third-party code/UI/schema/wording may be copied.

### MED-VERIFY-01 — P1 — Medication order verification segregation

- Feature IDs: `MED-MEDICATIONS`, `MED-EMAR`
- Actor/job: A qualified clinician creates and independently verifies a medication order.
- Current behavior: orders.manage and clients.update count as verification capabilities; a capable creator's medication is immediately verified and explicit verification need not use a different actor.
- Failure sequence: The order creator auto-verifies or explicitly verifies their own medication, bypassing independent checking.
- Boundary/root cause: Creator/verifier separation, exact capability, high-risk order policy and emergency-waiver provenance. Creation permission is conflated with independent clinical verification.
- Impact: Self-verification weakens a core medication-order safety control.
- Evidence: `app/Http/Controllers/Emar/EmarController.php:110-116,4429-4441,4491-4500`; `routes/emar.php:205-207,233-235`
- Existing tests (not executed): OneChartAdministrationSafetyTest pending-verification order behavior
- Missing tests: Creator self-verification denial; Exact verifier capability; High-risk second check; Emergency waiver
- Benchmark: Bahmni medication administration — Native benchmark; Structured performer, status, reason, effective time, request, medication, dose and notes.
- Neutral requirements: Bind person, site, active order, scheduled cell, performer and dose; keep coded omission and append-only correction evidence.
- Native design direction: Implement a native order-state transition with policy-driven segregation and emergency evidence.
- Interim safeguard: Review creator-equals-verifier orders before first administration.
- Acceptance: Given the actor and boundary described, when the failing sequence is attempted, then the operation is denied or completed atomically with no foreign disclosure, partial mutation or duplicate effect.; Use an explicit verify transition/capability.; Require distinct verifier for configured high-risk classes.; Record waiver reason/approver.; Test creator, verifier and unauthorized combinations.
- Owner / effort / confidence: Medication Governance / M / High
- Evidence typing: source-observed behavior; failure/impact inference; priority/design is the named specialist owner's decision. No third-party code/UI/schema/wording may be copied.

### NZS-ASSURANCE-01 — P1 — Unsupported Ngā Paerewa and first-aid assurance

- Feature IDs: `HS-HEALTH-SAFETY-DASHBOARD`, `HS-HAZARDOUS-SUBSTANCE`, `HS-HS-RISK-ASSESSMENT`, `HS-RESTRAINT`, `HS-EMERGENCY-DRILL`, `HS-FIRST-AID`, `CLIN-HEALTH-CLINICAL-DASHBOARD`, `PRIV-COMPLIANCE-DASHBOARD`
- Actor/job: A frontline, clinical, H&S or governance user relies on the compliance badges to understand current service assurance.
- Current behavior: A shared snapshot explicitly says Ngā Paerewa certification and first-aid cover have no backing source, then returns both as true. Shared and local pages render affirmative green 'Certified'/'Cover OK' badges; other controllers hardcode true or infer whole-standard certification from drills/FENZ status. Clinical defaults a missing KPI to true. A frontend test positively asserts the unsupported Certified label.
- Failure sequence: Missing or partial proxy data becomes an affirmative regulatory/safety assurance and staff or governance users rely on it when prioritising review, evidence or operational cover.
- Boundary/root cause: Authoritative certificate identity, provider/site and modular scope, issuer, number, evidence, issue/expiry/revocation status; separate first-aider competency/roster coverage and operational readiness indicators. UI badges convert absent or proxy data into affirmative organization/site certification and staffing assurance.
- Impact: Unsupported assurance can hide evidence gaps and mislead safety/compliance decisions across high-visibility pages.
- Evidence: `app/Support/HazardComplianceSnapshot.php:22-43`; `resources/js/pages/health-safety/components/hs-hero-kit.tsx:181-221`; `resources/js/pages/health-safety/components/dashboard-tabs.tsx:279-305`; `app/Http/Controllers/HealthSafety/HazardousSubstanceController.php:145-158`; `app/Http/Controllers/HealthSafety/RestraintController.php:218-223`; `app/Http/Controllers/HealthSafety/EmergencyDrillController.php:135-141`; `resources/js/pages/health-clinical/components/health-clinical-shell.tsx:113-123`; `resources/js/pages/health-safety/risk-assessments/index.tsx:220`; `resources/js/pages/health-safety/injuries/index.tsx:191`; `resources/js/pages/health-safety/first-aid/index.tsx:232`; `resources/js/pages/compliance/index.tsx:398`; `resources/js/pages/health-safety/components/hs-hero-kit.test.tsx:21`
- Existing tests (not executed): hs-hero-kit test positively asserts Certified
- Missing tests: Unknown/missing defaults unknown; Certificate scope/site/expiry/revocation; Drill/FENZ proxy cannot certify whole standard; First-aider competency and shift coverage; Role/site presentation
- Benchmark: No credible match — No credible match; Evidence gap—not a documented No Credible Match. Only catalogue-level candidates were retained for this Health and safety capability: BeaconHS, CISO Assistant Community, OpenProject, Primero. No feature-specific query, beyond-catalogue search or exact rejected-repository evidence is retained; partial patterns were not stretched into a match.
- Neutral requirements: Preserve actor, owning record, site and role boundary, explicit states, validation, recovery, provenance and auditable hand-off in an original Oblivion-native workflow.
- Native design direction: Use one native assurance registry for certification evidence and separate live operational-readiness measures; never infer one from the other.
- Interim safeguard: Suppress green Certified/Cover OK claims and display 'Not connected—verify with compliance owner' until authoritative evidence exists.
- Acceptance: Given the actor and boundary described, when the failing sequence is attempted, then the operation is denied or completed atomically with no foreign disclosure, partial mutation or duplicate effect.; Store an authoritative, evidence-linked certificate with issuer, number, provider/site, modular scope, issue/expiry and status.; Keep certification distinct from drills, FENZ, procedures and first-aid operational readiness.; Default missing/stale/revoked evidence to unknown or action required.; Have the accountable certification/H&S owner approve semantics and test site/scope/expiry/revocation.
- Owner / effort / confidence: Compliance Owner, Clinical Governance and H&S / L / High
- Evidence typing: source-observed behavior; failure/impact inference; priority/design is the named specialist owner's decision. No third-party code/UI/schema/wording may be copied.

### PAY-LEAVE-REPLAY — P1 — Paid-leave payroll provenance

- Feature IDs: `HR-PAYROLL-EXPORT`, `HR-LEAVE`
- Actor/job: A payroll officer creates, locks and exports a period run containing approved leave.
- Current behavior: Overlap is blocked only against draft/locked runs. Once exported, another same/overlapping run can be created. Approved leave is recomputed and not linked/consumed per run; only timesheets gain paid provenance.
- Failure sequence: Run A includes leave and is exported; it no longer blocks overlap; run B for the same period includes the same leave again.
- Boundary/root cause: Privileged payroll idempotency, period overlap and source-leave provenance. Payroll provenance is implemented for timesheets but absent for leave, and exported is incorrectly treated as non-blocking.
- Impact: The same approved leave can be paid more than once.
- Evidence: `app/Domain/Hr/Services/PayrollExportService.php:61-134,257-347,441-615`; `app/Http/Controllers/Hr/PayrollExportController.php:114-140`
- Existing tests (not executed): AuditFixPayrollCasesTest includes paid leave and leave-only workers; PayrollRunIntegrityTest timesheet cascade
- Missing tests: Same period after export/net pay; Concurrent run creation; Partial overlap; Leave spanning periods; Correction/reversal lineage
- Benchmark: Kimai — Native benchmark; Timezone-aware edit lockdown and exported-entry protection.
- Neutral requirements: Separate draft, submitted, approved, exported and paid states with timezone-safe controlled correction.
- Native design direction: Use a native payroll-source ledger for both timesheet and leave slices with unique run linkage.
- Interim safeguard: Check every prior run status and reconcile leave request/date slices before creating or releasing a run.
- Acceptance: Given the actor and boundary described, when the failing sequence is attempted, then the operation is denied or completed atomically with no foreign disclosure, partial mutation or duplicate effect.; Block overlap with every non-void run except explicit correction.; Persist unique leave request/date-slice provenance.; Test concurrency and spanning leave.; Provide controlled reversal/correction.
- Owner / effort / confidence: Payroll and Finance Integrity / L / High
- Evidence typing: source-observed behavior; failure/impact inference; priority/design is the named specialist owner's decision. No third-party code/UI/schema/wording may be copied.

### RESP-EVIDENCE-01 — P1 — Evidence-pack custom item preservation

- Feature IDs: `RESP-RESPITE-EVIDENCE-PACK`
- Actor/job: A respite compliance user adds evidence, seals a pack and exports the sealed record.
- Current behavior: addItem appends custom evidence, but seal replaces items with a regenerated manifest and export regenerates it again.
- Failure sequence: A user adds evidence; sealing/export omits it while audit history still says it was added.
- Boundary/root cause: Evidence preservation, sealed snapshot digest and reproducible export. Manifest regeneration overwrites rather than snapshots the complete evidence set.
- Impact: Material evidence can silently disappear from the artifact relied upon for assurance.
- Evidence: `app/Http/Controllers/Respite/RespiteEvidencePackController.php:137-178,223-253,286-320`
- Existing tests (not executed): RespiteNzWorkflowCompletionTest checks required manifest blockers
- Missing tests: Custom add then seal/export; Failed-seal preservation; Export equals sealed digest
- Benchmark: No credible match — No credible match; Evidence gap—not a documented No Credible Match. Only catalogue-level candidates were retained for this Respite capability: Primero, OpenMRS Patient Management. No feature-specific query, beyond-catalogue search or exact rejected-repository evidence is retained; partial patterns were not stretched into a match.
- Neutral requirements: Preserve actor, owning record, site and role boundary, explicit states, validation, recovery, provenance and auditable hand-off in an original Oblivion-native workflow.
- Native design direction: Use a native immutable evidence-pack aggregate whose export is a deterministic sealed snapshot.
- Interim safeguard: Retain custom evidence separately and compare before/after seal.
- Acceptance: Given the actor and boundary described, when the failing sequence is attempted, then the operation is denied or completed atomically with no foreign disclosure, partial mutation or duplicate effect.; Separate generated obligations from user evidence or merge without loss.; Seal an immutable versioned digest.; Export the sealed snapshot.; Test add/remove/failure/seal/export sequences.
- Owner / effort / confidence: Respite Compliance and Backend / M / High
- Evidence typing: source-observed behavior; failure/impact inference; priority/design is the named specialist owner's decision. No third-party code/UI/schema/wording may be copied.

### RESP-STATE-01 — P1 — Request, booking and stay state transitions

- Feature IDs: `RESP-RESPITE-BOOKING-REQUEST`, `RESP-RESPITE-BOOKING`, `RESP-RESPITE-STAY`
- Actor/job: A respite coordinator approves, confirms, admits, extends or discharges a stay.
- Current behavior: Generic update endpoints can set approved/confirmed using respite.update while dedicated transitions have stronger permissions and downstream work. Check-in/extend do not reject a discharged stay.
- Failure sequence: An update actor bypasses readiness/capacity/downstream transition effects or resurrects a discharged stay and re-enters shift/funding synchronization.
- Boundary/root cause: Transition permission, current state, readiness/capacity gates and atomic projections. Generic update and dedicated workflow commands compete for the same state fields.
- Impact: Bypassed or resurrected states can admit a person without required readiness and corrupt funding/roster projections.
- Evidence: `app/Http/Controllers/Respite/RespiteBookingRequestController.php:162-250`; `app/Http/Controllers/Respite/RespiteBookingController.php:161-281`; `app/Http/Controllers/Respite/RespiteStayController.php:91-153,198-240`
- Existing tests (not executed): RespiteReadinessTest; RespiteAdmissionSafetyTest; RespiteFundingCompletionTest
- Missing tests: Generic privileged-state denial; Full invalid state matrix; Discharged resurrection; Concurrent transitions; No side effects on rejection
- Benchmark: No credible match — No credible match; Evidence gap—not a documented No Credible Match. Only catalogue-level candidates were retained for this Respite capability: Primero, OpenMRS Patient Management. No feature-specific query, beyond-catalogue search or exact rejected-repository evidence is retained; partial patterns were not stretched into a match.
- Neutral requirements: Preserve actor, owning record, site and role boundary, explicit states, validation, recovery, provenance and auditable hand-off in an original Oblivion-native workflow.
- Native design direction: Consolidate transitions into one native state machine with explicit effects and correction path.
- Interim safeguard: Reconcile approvals to bookings/shifts and report any transition after discharge.
- Acceptance: Given the actor and boundary described, when the failing sequence is attempted, then the operation is denied or completed atomically with no foreign disclosure, partial mutation or duplicate effect.; Remove privileged states from generic validators.; Use a locked per-transition service with idempotent effects.; Make discharge terminal except an explicit new episode.; Test state matrix and concurrency.
- Owner / effort / confidence: Respite Operations and Backend / L / High
- Evidence typing: source-observed behavior; failure/impact inference; priority/design is the named specialist owner's decision. No third-party code/UI/schema/wording may be copied.

### SAFE-SENSITIVITY-01 — P1 — Safeguarding declassification

- Feature IDs: `INC-SAFEGUARDING-CONCERN`
- Actor/job: A safeguarding/privacy lead reviews whether a concern may leave need-to-know status.
- Current behavior: Any ordinary updater, including assignee, can set is_sensitive=false without distinct declassification permission, reason, approval or audience preview.
- Failure sequence: An assignee declassifies and allegations become visible to a wider role set.
- Boundary/root cause: Need-to-know classification, audience impact, independent approval and immutable event. Classification and declassification share ordinary update authorization.
- Impact: Sensitive allegations can be disclosed beyond need-to-know without review.
- Evidence: `app/Http/Controllers/SafeguardingConcernController.php:519-537`; `app/Policies/SafeguardingConcernPolicy.php:52-64`; `tests/Feature/Safeguarding/SafeguardingIndexRedactionTest.php:47-108`
- Existing tests (not executed): SafeguardingIndexRedactionTest visibility
- Missing tests: Updater cannot declassify; Reason/approval; Audience preview; Immutable audit
- Benchmark: No credible match — No credible match; Evidence gap—not a documented No Credible Match. Only catalogue-level candidates were retained for this Incidents and safeguarding capability: BeaconHS, CISO Assistant Community, OpenProject, Primero. No feature-specific query, beyond-catalogue search or exact rejected-repository evidence is retained; partial patterns were not stretched into a match.
- Neutral requirements: Preserve actor, owning record, site and role boundary, explicit states, validation, recovery, provenance and auditable hand-off in an original Oblivion-native workflow.
- Native design direction: Add a native declassification request/approval with audience preview.
- Interim safeguard: Only safeguarding/privacy lead removes sensitivity after recorded review.
- Acceptance: Given the actor and boundary described, when the failing sequence is attempted, then the operation is denied or completed atomically with no foreign disclosure, partial mutation or duplicate effect.; Distinct permission.; Mandatory reason/approval.; Expanded-audience preview.; Test all roles and preserve redaction until approved.
- Owner / effort / confidence: Safeguarding and Privacy / M / High
- Evidence typing: source-observed behavior; failure/impact inference; priority/design is the named specialist owner's decision. No third-party code/UI/schema/wording may be copied.

### SAFE-TERMINAL-SYNC-01 — P1 — Safeguarding terminal-state propagation

- Feature IDs: `INC-SAFEGUARDING-CONCERN`, `HS-HS-EVENT`, `CR-CONTROL-ROOM-ALERT`
- Actor/job: A safeguarding lead closes a concern linked to protective and operational work.
- Current behavior: An assigned updater can close with free-text override despite open protective work/referrals, then controller directly and best-effort closes H&S and resolves Control Room rather than asking those owners to validate.
- Failure sequence: Concern closes, linked queues become terminal, but underlying protective work remains open or a cross-domain transition fails without coherent recovery.
- Boundary/root cause: Domain-owned terminal transitions, hard blockers, independent closure approval and durable cross-module request/retry. One controller owns terminal state in three domains and treats override text as sufficient governance.
- Impact: Protective work can remain open while live operational visibility is removed.
- Evidence: `app/Http/Controllers/SafeguardingConcernController.php:452-500,751-784`; `app/Policies/SafeguardingConcernPolicy.php:52-64`; `tests/Feature/Safeguarding/SafeguardingCrossModuleTest.php:66-91`
- Existing tests (not executed): SafeguardingCrossModuleTest positively asserts propagation
- Missing tests: Open child hard blocker; Independent approver; Partial failure/retry; Reopen/reconciliation; Distinct override permission
- Benchmark: OneUptime Community — Native benchmark; Observable timed escalation attempts and acknowledgement.
- Neutral requirements: Preserve actor, owning record, site and role boundary, explicit states, validation, recovery, provenance and auditable hand-off in an original Oblivion-native workflow.
- Native design direction: Use native domain-owned transition requests and a reconciled cross-module closure view.
- Interim safeguard: Require independent closure review and manually confirm H&S/Control Room terminal readiness.
- Acceptance: Given the actor and boundary described, when the failing sequence is attempted, then the operation is denied or completed atomically with no foreign disclosure, partial mutation or duplicate effect.; Use an outbox/closure request.; Owning domains validate transitions.; Separate override permission with approver/evidence/expiry.; Test partial failure and reopen.
- Owner / effort / confidence: Safeguarding, Control Room and H&S / L / High
- Evidence typing: source-observed behavior; failure/impact inference; priority/design is the named specialist owner's decision. No third-party code/UI/schema/wording may be copied.

### SEC-HEALTH-004 — P1 — Unsupported UniFi device-health state

- Feature IDs: `SEC-UNIFI`, `SEC-MAINTENANCE-HEALTH`
- Actor/job: An operator relies on integration health to identify degraded devices.
- Current behavior: Adapter advertises device_health while production pullHealth is a TODO returning an empty successful result.
- Failure sequence: No health rows are returned and UI/operations can interpret empty success as healthy or uneventful despite stale/unsupported data.
- Boundary/root cause: Capability truth, healthy-zero versus unsupported/stale/failed, freshness and operator action. Capability declaration and runtime implementation contradict each other.
- Impact: A failed or unsupported security-device health feed can appear normal.
- Evidence: `app/Services/Integration/Adapters/UnifiAdapter.php:46-49,329-333`
- Existing tests (not executed): No production health behavior test found
- Missing tests: Unsupported; Healthy zero; Stale; Partial; Provider error
- Benchmark: libOSDP — Native benchmark; The protocol implementation supports an authenticated secure-channel mode for access-control communications.
- Neutral requirements: Require authenticated encrypted transport, managed keys and visible degraded state; do not treat protocol security as application authorization.
- Native design direction: Use a native integration capability/health contract with explicit unsupported and degraded states.
- Interim safeguard: Label UniFi health unsupported/degraded and use independent heartbeat monitoring.
- Acceptance: Given the actor and boundary described, when the failing sequence is attempted, then the operation is denied or completed atomically with no foreign disclosure, partial mutation or duplicate effect.; Advertise capability only when implemented.; Model unsupported/stale/failed distinctly.; Expose freshness and remediation.; Test every no-data/error state.
- Owner / effort / confidence: Security Integrations / S / High
- Evidence typing: source-observed behavior; failure/impact inference; priority/design is the named specialist owner's decision. No third-party code/UI/schema/wording may be copied.

### SEC-PROV-003 — P1 — Provider versus local device-field ownership

- Feature IDs: `SEC-DEVICE`, `SEC-UNIFI`
- Actor/job: An operator edits an integrated device while provider synchronization updates observed state.
- Current behavior: Generic local update and provider sync both write serial, MAC, IMEI, firmware, IP, status, health and provider linkage without field ownership/conflict semantics.
- Failure sequence: Operator edits provider-owned state; next sync silently overwrites it or local value masks actual observed state.
- Boundary/root cause: Canonical identity, Oblivion-managed metadata, provider-observed state, provenance and conflict/override expiry. Two writers own the same fields without provenance or conflict rules.
- Impact: Device identity/health can become stale, contradictory or unauditable.
- Evidence: `app/Domain/SecurityDevices/Http/Controllers/DeviceController.php:475-503`; `app/Services/Integration/UnifiOperationalBridgeService.php:23-76`
- Existing tests (not executed): Device/integration happy paths
- Missing tests: Manual then sync; Imported versus manual; Override reason/expiry; Conflict visibility
- Benchmark: Eclipse Ditto — Native benchmark; Thing policies and distinct desired versus reported properties preserve device identity and state provenance.
- Neutral requirements: Separate intended from reported device state and apply custody-derived policy before identity, telemetry or mutation is exposed.
- Native design direction: Apply native intended-versus-observed projections while Security & Devices retains canonical identity.
- Interim safeguard: Permit local edits only to Oblivion-managed metadata on integrated devices.
- Acceptance: Given the actor and boundary described, when the failing sequence is attempted, then the operation is denied or completed atomically with no foreign disclosure, partial mutation or duplicate effect.; Separate intended/local from observed/provider fields.; Record source/time/quality.; Govern overrides with reason/expiry.; Test subsequent sync.
- Owner / effort / confidence: Security Devices and Integrations / L / High
- Evidence typing: source-observed behavior; failure/impact inference; priority/design is the named specialist owner's decision. No third-party code/UI/schema/wording may be copied.

### SEC-UNIFI-TLS-01 — P1 — UniFi adapter transport security

- Feature IDs: `SEC-UNIFI`, `PLAT-FRONTEND-INTEGRATIONS-UNIFI`
- Actor/job: An integration operator synchronises access/security-device data with UniFi.
- Current behavior: The adapter sends a decrypted bearer credential over HTTPS with certificate verification disabled.
- Failure sequence: A network attacker or misconfigured endpoint presents an untrusted certificate; the client accepts it and transmits the integration credential and operational data.
- Boundary/root cause: Authenticated encrypted transport, trusted endpoint identity, secret exposure and visible degraded state. Transport trust is disabled inside the adapter instead of being a governed deployment choice.
- Impact: Credential interception can compromise physical/security-device control and privacy.
- Evidence: `app/Services/Integration/Adapters/UnifiAdapter.php:335-362`
- Existing tests (not executed): Integration configuration tests; no adapter TLS-negative proof found
- Missing tests: Untrusted certificate rejected; Trusted private CA succeeds; Hostname mismatch; Credential redaction; Visible sync failure/retry
- Benchmark: libOSDP — Native benchmark; The protocol implementation supports an authenticated secure-channel mode for access-control communications.
- Neutral requirements: Require authenticated encrypted transport, managed keys and visible degraded state; do not treat protocol security as application authorization.
- Native design direction: Use the platform HTTP client with native trusted-CA configuration, secret redaction and operator-visible health.
- Interim safeguard: Disable the integration unless it is on a separately verified isolated path; rotate credentials if untrusted transit is suspected.
- Acceptance: Given the actor and boundary described, when the failing sequence is attempted, then the operation is denied or completed atomically with no foreign disclosure, partial mutation or duplicate effect.; Enable certificate and hostname validation by default.; Support an explicitly managed private CA rather than verify=false.; Redact credentials and surface degraded state.; Test trusted/untrusted endpoints.
- Owner / effort / confidence: Security Integrations Owner / S / High
- Evidence typing: source-observed behavior; failure/impact inference; priority/design is the named specialist owner's decision. No third-party code/UI/schema/wording may be copied.

### SITE-CHECK-002 — P1 — Checklist run ownership and signature provenance

- Feature IDs: `SITE-SITE-CHECKLIST`
- Actor/job: An assigned worker completes a site checklist run.
- Current behavior: Any worker who can view the site may edit/complete another worker's run; required signature_name is discarded while completion is attributed to the current login.
- Failure sequence: Peer opens an assigned run, completes it, and the retained record does not represent the typed signer evidence.
- Boundary/root cause: Run assignee/handover, manager override, signer/recorder provenance and idempotent completion. Site view permission substitutes for run ownership and a collected signature field is discarded.
- Impact: Completion evidence may identify the wrong accountable worker or permit unowned work closure.
- Evidence: `routes/sites.php:485-494`; `app/Http/Controllers/Sites/SiteChecklistController.php:39-108`
- Existing tests (not executed): Checklist run happy paths
- Missing tests: Peer denial; Handover/manager override; Signature retention; Reassignment race; Duplicate submission
- Benchmark: No credible match — No credible match; Evidence gap—not a documented No Credible Match. Only catalogue-level candidates were retained for this Sites, facilities and catering capability: Snipe-IT, NetBox, Grocy, Mealie, Tandoor Recipes. No feature-specific query, beyond-catalogue search or exact rejected-repository evidence is retained; partial patterns were not stretched into a match.
- Neutral requirements: Preserve actor, owning record, site and role boundary, explicit states, validation, recovery, provenance and auditable hand-off in an original Oblivion-native workflow.
- Native design direction: Use native run assignment/handover and attestation primitives.
- Interim safeguard: Operationally restrict completion to assignee/manager and state typed name is not retained as e-signature.
- Acceptance: Given the actor and boundary described, when the failing sequence is attempted, then the operation is denied or completed atomically with no foreign disclosure, partial mutation or duplicate effect.; Only assignee, explicit recipient or reasoned manager override completes.; Retain signer provenance/correction.; Test race and duplicate.
- Owner / effort / confidence: Sites Operations and Assurance / M / High
- Evidence typing: source-observed behavior; failure/impact inference; priority/design is the named specialist owner's decision. No third-party code/UI/schema/wording may be copied.

### SITE-CHECK-003 — P1 — Checklist failure risk projection

- Feature IDs: `SITE-SITE-CHECKLIST`, `HS-SITE-HAZARD`, `SITE-SITE-DAMAGE`
- Actor/job: A worker records a checklist failure that creates a hazard or damage follow-up.
- Current behavior: Every generated hazard is forced to medium/possible and damage to minor regardless of template-item criticality.
- Failure sequence: Critical fire/safety/equipment failure is submitted and projected as ordinary medium/minor, reducing escalation urgency.
- Boundary/root cause: Template-governed criticality, H&S ownership, idempotent follow-up and non-silent closure. Projection hard-codes a single low/moderate severity rather than carrying source criticality.
- Impact: A critical site hazard can be systematically under-classified and under-escalated.
- Evidence: `app/Http/Controllers/Sites/SiteChecklistController.php:239-283`
- Existing tests (not executed): Checklist follow-up happy path
- Missing tests: Critical versus ordinary mappings; Required escalation; Repeat-save idempotency; Closure blocker
- Benchmark: BeaconHS — Native benchmark; Five-step investigation and separately verified corrective-action closure; project is immature.
- Neutral requirements: Preserve actor, owning record, site and role boundary, explicit states, validation, recovery, provenance and auditable hand-off in an original Oblivion-native workflow.
- Native design direction: Add native governed failure-to-risk mapping with clear H&S hand-off.
- Interim safeguard: H&S/site lead manually reviews every checklist-generated follow-up.
- Acceptance: Given the actor and boundary described, when the failing sequence is attempted, then the operation is denied or completed atomically with no foreign disclosure, partial mutation or duplicate effect.; Govern risk mapping per template item.; Critical failures trigger required escalation.; Prevent silent close.; Test critical/ordinary and replay.
- Owner / effort / confidence: Sites and H&S / M / High
- Evidence typing: source-observed behavior; failure/impact inference; priority/design is the named specialist owner's decision. No third-party code/UI/schema/wording may be copied.

### SITE-RBAC-001 — P1 — Empty site-scope ambiguity

- Feature IDs: `CAP-SITE-SITE-ACTIVATION-ARCHIVE`; `CAP-SITE-SITE-PROFILE-ONBOARDING`
- Actor/job: A permission-bearing site worker or manager accesses or changes a site-bound record that must remain limited to explicitly assigned sites.
- Current behavior: For SitePolicy and SiteController index, an empty accessible-site ID set is treated as unrestricted; fail-closed service consumers treat that same set as denied. Zero assignments and intended global access are not represented separately.
- Failure sequence: A permission-bearing actor with no primary, user-level or secondary site ID requests a direct site route; the empty set bypasses the index filter or SitePolicy membership check, permitting foreign-site read or mutation according to the actor's other permissions.
- Boundary/root cause: Fail-closed assignment scope and explicit audited global bypass. One data representation encodes opposite security meanings.
- Impact: An unassigned support worker can directly read a supplied site profile; an unassigned role with `sites.update` can modify profile data or active state. Archive exposure additionally requires `sites.archive`, which no seeded non-admin role currently receives.
- Evidence: `routes/assets.php:31-75,128-139`; `routes/sites.php:74-75,433-435`; `app/Http/Controllers/SiteController.php:62-93,272-274,912-1068,1533-1673`; `app/Policies/SitePolicy.php:17-24,32-50,72-80`; `app/Services/UserSiteAccessService.php:22-45,69-83,196-207`; `database/seeders/RbacSeeder.php:21,36,44-45,564-567,694-710,795-799`
- Existing tests (not executed): Site policy/permission happy paths
- Missing tests: Unassigned denial; Site A/B; Explicit admin bypass; Secondary assignments
- Benchmark: No credible match — No credible match; Evidence gap—not a documented No Credible Match. Only catalogue-level candidates were retained for this Sites, facilities and catering capability: Snipe-IT, NetBox, Grocy, Mealie, Tandoor Recipes. No feature-specific query, beyond-catalogue search or exact rejected-repository evidence is retained; partial patterns were not stretched into a match.
- Neutral requirements: Preserve actor, owning record, site and role boundary, explicit states, validation, recovery, provenance and auditable hand-off in an original Oblivion-native workflow.
- Native design direction: Introduce a native explicit scope result: global, assigned-set or denied.
- Interim safeguard: Require active site assignment and remove site permissions from unassigned frontline accounts.
- Acceptance: Given the actor and boundary described, when the failing sequence is attempted, then the operation is denied or completed atomically with no foreign disclosure, partial mutation or duplicate effect.; Represent bypass separately from an empty assignment set.; Make no assignment deny.; Test policy and routes for admin, A, B and unassigned.
- Owner / effort / confidence: Authorization Platform and Sites / L / High
- Evidence typing: source-observed behavior; failure/impact inference; priority/design is the named specialist owner's decision. No third-party code/UI/schema/wording may be copied.

### TASK-RBAC-001 — P1 — Cross-provider task row scope

- Feature IDs: `DAY-ALL-TASKS`
- Actor/job: A frontline worker views, exports or opens aggregated tasks.
- Current behavior: Provider-level permission checks enable globally queried sources, exposing foreign-site incidents, checklist notes, assets and assignees through lists, detail, statistics and CSV.
- Failure sequence: Site-A worker has a provider permission, aggregation queries all provider rows and returns site-B task context.
- Boundary/root cause: Owning module row scope must survive list, count, CSV, lookup and detail aggregation. Provider availability is permission-scoped while provider records are not row-scoped.
- Impact: Aggregated work reveals foreign-site safety, asset and staff information.
- Evidence: `routes/tasks.php:15-30`; `app/Services/Tasks/TaskAggregator.php:50-76,147-163`; `app/Services/Tasks/Providers/FleetIncidentProvider.php:28-74`; `app/Services/Tasks/Providers/FleetMaintenanceProvider.php:34-65,111-147`; `app/Services/Tasks/Providers/SiteChecklistRunProvider.php:28-73`; `app/Http/Controllers/AllTasksController.php:34-88`
- Existing tests (not executed): Task aggregator/provider happy paths
- Missing tests: Two sites per provider; List/count/CSV/detail parity; Foreign direct ID; Revoked access
- Benchmark: CommCare Android — Native benchmark; Pending-form send, cancellation, errors and recovery sync.
- Neutral requirements: Expose durable pending state, last sync, per-item retry, idempotency and conflict handling.
- Native design direction: Define a native task-provider authorization contract returning only already-authorized work projections.
- Interim safeguard: Disable affected providers for frontline roles or apply shared site scope before aggregation.
- Acceptance: Given the actor and boundary described, when the failing sequence is attempted, then the operation is denied or completed atomically with no foreign disclosure, partial mutation or duplicate effect.; Every provider reuses owning module's row scope.; Scope before aggregation/serialization.; Test each provider and output surface.
- Owner / effort / confidence: Frontline Tasks and Authorization / L / High
- Evidence typing: source-observed behavior; failure/impact inference; priority/design is the named specialist owner's decision. No third-party code/UI/schema/wording may be copied.

### TASK-WATCH-002 — P1 — Task watcher authorization over time

- Feature IDs: `DAY-ALL-TASKS`
- Actor/job: A user watches a task and receives later changes.
- Current behavior: Watch checks provider-level access only; guessed foreign item may be subscribed, and notifications later disclose title/link without reauthorization.
- Failure sequence: Actor subscribes to a hidden item or loses access after subscription; delivery still reveals it.
- Boundary/root cause: Exact item authorization at subscribe and recipient reauthorization at every delivery. Subscription and delivery rely on coarse provider permission instead of object visibility.
- Impact: Persistent subscriptions can continue disclosing sensitive work after access is absent or revoked.
- Evidence: `routes/tasks.php:25-27`; `app/Http/Controllers/AllTasksController.php:100-113,218-246`; `app/Services/Tasks/TaskAssignmentNotifier.php:17-76`; `app/Console/Commands/EscalateOverdueTasks.php:88-109,159-171`
- Existing tests (not executed): Watcher happy paths
- Missing tests: Guessed foreign item; Access revoked after subscription; Duplicate watcher; Delivery redaction/removal
- Benchmark: CommCare Android — Native benchmark; Pending-form send, cancellation, errors and recovery sync.
- Neutral requirements: Expose durable pending state, last sync, per-item retry, idempotency and conflict handling.
- Native design direction: Use a native watch relation tied to authorized task identity and revalidated delivery.
- Interim safeguard: Disable watching on scoped providers and remove watchers lacking current row access.
- Acceptance: Given the actor and boundary described, when the failing sequence is attempted, then the operation is denied or completed atomically with no foreign disclosure, partial mutation or duplicate effect.; Resolve exact authorized item before watch/unwatch.; Prevent duplicate watchers.; Reauthorize recipient at delivery.; Test guessed IDs and revoked access.
- Owner / effort / confidence: Tasks, Notifications and Authorization / M / High
- Evidence typing: source-observed behavior; failure/impact inference; priority/design is the named specialist owner's decision. No third-party code/UI/schema/wording may be copied.

### WF-ATTENDANCE-FORCED-END-SITE — P1 — Manager forced-end site scope

- Feature IDs: `OPS-ATTENDANCE`
- Actor/job: A manager closes an abandoned open attendance session.
- Current behavior: Worker clock flows are actor-bound, but the manager end-session path checks timesheets.manageAny without verifying access to the session worker/site or linked shift site.
- Failure sequence: A site-A manager enumerates a site-B session, closes it, may complete the linked shift, and creates draft time/audit evidence.
- Boundary/root cause: Cross-site manager scope and attendance/payroll integrity; not a generic worker IDOR. ManageAny is treated as global despite site-context role use.
- Impact: A site manager can change another site's attendance, shift and pay-source record.
- Evidence: `app/Http/Controllers/AttendanceController.php:371-400`; `app/Domain/Hr/Services/AttendanceService.php:271-365`; `routes/shifts.php:105-108`
- Existing tests (not executed): AttendanceAdminEndSessionTest manager success, linked shift completion, worker denial and required reason
- Missing tests: Foreign-site manager denial; Canonical site fallback; No session/shift/timesheet mutation; Global payroll manager positive
- Benchmark: Kimai — Native benchmark; Timezone-aware edit lockdown and exported-entry protection.
- Neutral requirements: Separate draft, submitted, approved, exported and paid states with timezone-safe controlled correction.
- Native design direction: Add a native manager-session scope guard shared with payroll/timesheet operations.
- Interim safeguard: Limit forced-end to central payroll/HR or manually confirm session site.
- Acceptance: Given the actor and boundary described, when the failing sequence is attempted, then the operation is denied or completed atomically with no foreign disclosure, partial mutation or duplicate effect.; Resolve canonical site from shift/session/profile.; Assert access before status disclosure/mutation.; Test two-site direct IDs and global role.; Prove transaction rollback.
- Owner / effort / confidence: Attendance and Authorization / M / High
- Evidence typing: source-observed behavior; failure/impact inference; priority/design is the named specialist owner's decision. No third-party code/UI/schema/wording may be copied.

### WF-AVAILABILITY-LIFECYCLE — P1 — Leave and offboarding propagation

- Feature IDs: `HR-LEAVE`, `HR-OFFBOARDING`, `OPS-ROSTER`
- Actor/job: HR approves leave or begins offboarding while rostering owns future assignments.
- Current behavior: Approved leave projects time off but does not resolve published shifts. Offboarding holds last day only in a checklist and keeps profile active/end_date unset until every task completes; eligibility ignores prospective end date.
- Failure sequence: Leave/offboarding begins, future assignments remain and the worker stays selectable beyond the effective last day or during leave until a scheduler notices manually.
- Boundary/root cause: HR-to-roster effective availability and owned coverage hand-off. HR state changes produce passive records rather than durable roster hand-offs.
- Impact: A worker can remain assigned or selectable while unavailable or after their last day.
- Evidence: `app/Domain/Hr/Services/LeaveService.php:261-330`; `app/Domain/Hr/Services/OnboardingService.php:762-884,958-995`; `app/Services/ShiftStaffEligibilityService.php:158-186`
- Existing tests (not executed): LeaveProjectionSyncTest; OffboardingWizardStoreTest; OffboardingWorkflowTest
- Missing tests: Leave overlaps published shift; Effective end date blocks assignment; Existing shifts become owned actions; Cancellation/delay/rehire
- Benchmark: Frappe HR — Native benchmark; Active-worker, overlap, cross-midnight and mandatory-onboarding gates.
- Neutral requirements: Fail closed on inactive worker, overlap and mandatory prerequisites; keep traceable approval and timezone rules.
- Native design direction: Publish native availability-change events with owned coverage actions and effective dates.
- Interim safeguard: Leave approvers and HR immediately search and resolve overlaps, with a named coverage owner and do-not-schedule-after date.
- Acceptance: Given the actor and boundary described, when the failing sequence is attempted, then the operation is denied or completed atomically with no foreign disclosure, partial mutation or duplicate effect.; Persist effective end date at offboarding start.; Enforce leave/end date in eligibility.; Create idempotent coverage actions.; Test draft/published/in-progress, cancellation, delay and rehire.
- Owner / effort / confidence: HR Operations and Rostering / L / High
- Evidence typing: source-observed behavior; failure/impact inference; priority/design is the named specialist owner's decision. No third-party code/UI/schema/wording may be copied.

### WF-EMAIL-IDENTITY-CONVERGENCE — P1 — Employee intake account convergence

- Feature IDs: `HR-EMPLOYEE-PROFILE`, `HR-RECRUITMENT`
- Actor/job: An HR practitioner converts a candidate or creates an employee account.
- Current behavior: Intake resolves any existing User by email, approves it, adds staff role and active employee profile. It does not distinguish portal/family/client/nonstaff account types.
- Failure sequence: HR enters an email belonging to a nonstaff identity; firstOrCreate returns it and staff privileges/profile are attached.
- Boundary/root cause: Privileged identity linking, compatible account kinds and verified merge; not an unauthenticated takeover. Email uniqueness is treated as proof that two domain identities should converge.
- Impact: A privileged but mistaken hire can grant staff capabilities to an identity created for a different purpose.
- Evidence: `app/Domain/Hr/Services/EmployeeIntakeService.php:47-122`; `app/Domain/Hr/Services/RecruitmentService.php:207-276`; `routes/hr.php:218-219,262-269`
- Existing tests (not executed): RecruitmentOfferLifecycleTest new user and existing-profile collision
- Missing tests: Family/portal/client user collision; Inactive leaver/candidate account; Email case/alias; Mutation-free rejection
- Benchmark: Frappe HR — Native benchmark; Active-worker, overlap, cross-midnight and mandatory-onboarding gates.
- Neutral requirements: Fail closed on inactive worker, overlap and mandatory prerequisites; keep traceable approval and timezone rules.
- Native design direction: Introduce a native account-kind and verified identity-link workflow.
- Interim safeguard: Preflight every hire email against roles and linked account records; stop nonstaff collisions for documented identity review.
- Acceptance: Given the actor and boundary described, when the failing sequence is attempted, then the operation is denied or completed atomically with no foreign disclosure, partial mutation or duplicate effect.; Define compatible account/link policy.; Require second-person identity evidence for merges.; Reject incompatible collisions without mutation.; Audit merge decision and test all account kinds.
- Owner / effort / confidence: Identity and Access / HR People / L / High
- Evidence typing: source-observed behavior; failure/impact inference; priority/design is the named specialist owner's decision. No third-party code/UI/schema/wording may be copied.

### WF-FATIGUE-TIMEZONE — P1 — Fatigue day/week boundary calculation

- Feature IDs: `OPS-ROSTER`, `OPS-ROSTERING`
- Actor/job: A scheduler assesses an overnight or boundary-crossing shift against fatigue limits.
- Current behavior: Application timestamps use UTC while worker timezone defaults Pacific/Auckland. Daily/weekly boundaries use shift Carbon values without worker-timezone conversion, and candidate weekly duration is wholly added to its start week.
- Failure sequence: An NZ-local overnight/week-boundary shift is partitioned differently in UTC, under- or over-counting a local day/week and changing eligibility near a threshold.
- Boundary/root cause: Workforce safety/calendar calculation; absolute rest gaps are not implicated. Calendar buckets are derived in a different timezone from the workforce rule's intended operational calendar.
- Impact: A boundary miscalculation can allow an excessive local-day/week assignment or incorrectly block safe work.
- Evidence: `config/app.php:99,113`; `app/Services/Eligibility/Rules/FatigueRule.php:61-156,269-288`
- Existing tests (not executed): FatigueExcludesCancelledShiftsTest
- Missing tests: NZ versus UTC midnight; Sunday/Monday overnight; DST transition; Exact threshold; Cross-week segmentation
- Benchmark: Timefold Solver Community — Native benchmark; Pinned assignments and hard feasibility versus soft preference; score analysis is Enterprise-only.
- Neutral requirements: Treat safety and eligibility as hard, preferences as soft, preserve pinned choices and build native reason codes.
- Native design direction: Use a native interval-segmentation service with explicit timezone and DST semantics.
- Interim safeguard: Manually calculate borderline overnight fatigue in Pacific/Auckland and do not auto-assign those shifts.
- Acceptance: Given the actor and boundary described, when the failing sequence is attempted, then the operation is denied or completed atomically with no foreign disclosure, partial mutation or duplicate effect.; Select a documented work timezone.; Segment all existing/candidate duration by local day/week using one DST-safe calculator.; Add table-driven boundary tests.; Exercise assignment routes.
- Owner / effort / confidence: Rostering and Time Platform / M / Medium
- Evidence typing: source-observed behavior; failure/impact inference; priority/design is the named specialist owner's decision. No third-party code/UI/schema/wording may be copied.

### WF-TIMESHEET-CLIENT-REASSIGN — P1 — Manual timesheet client reassignment

- Feature IDs: `OPS-TIMESHEET`
- Actor/job: A worker corrects and resubmits an unlinked draft/returned timesheet.
- Current behavior: Update/resubmit validate client_id only for existence then copy its snapshot fields. Store already asserts client access, proving parity is missing; linked-shift clients are correctly forced.
- Failure sequence: The owner submits another site's client ID for an unlinked row; snapshot and allocation are rebound and can enter payroll/billing approval.
- Boundary/root cause: Site access, client privacy and billing/payroll allocation integrity for owner-controlled unlinked timesheets. Validation parity is absent between store and later mutable states.
- Impact: A worker can misattribute time and client identity across sites, affecting billing and payroll records.
- Evidence: `app/Http/Controllers/TimesheetController.php:920-980,1052-1133,1286-1302,733-740`; `routes/operations.php:1048-1061`
- Existing tests (not executed): TimesheetControllerTest owner update/resubmit and ownership denial
- Missing tests: Foreign-site client on update/resubmit; No snapshot/status change on reject; Secondary-site/global finance cases
- Benchmark: Kimai — Native benchmark; Timezone-aware edit lockdown and exported-entry protection.
- Neutral requirements: Separate draft, submitted, approved, exported and paid states with timezone-safe controlled correction.
- Native design direction: Use one native client-allocation resolver for every manual timesheet transition.
- Interim safeguard: Manager verifies every manual client reassignment against the worker's approved sites before approval.
- Acceptance: Given the actor and boundary described, when the failing sequence is attempted, then the operation is denied or completed atomically with no foreign disclosure, partial mutation or duplicate effect.; Run site-access assertion before client lookup/snapshot on update and resubmit.; Return 403/422 without disclosure.; Test worker/manager/finance across two sites.; Preserve linked-shift authoritative client.
- Owner / effort / confidence: Workforce Time and Payroll Integrity / S / High
- Evidence typing: source-observed behavior; failure/impact inference; priority/design is the named specialist owner's decision. No third-party code/UI/schema/wording may be copied.


## P2 register

| ID | Module | Finding | Evidence limit |
|---|---|---|---|
| `CONSENT-FILE-01` | Operations / privacy | The workflow protects the database row but places the evidence object in a public filesystem namespace. | High |
| `INCIDENT-RECOVERY-01` | Health and safety | A multi-step safety form uses ephemeral local state without a recovery contract. | High source; medium user impact |
| `PRIV-DSR-01` | Privacy and compliance | A broad export generator is permission-gated but not joined to the request's identity, authority, response-scope and secure-delivery lifecycle. | High |
| `PRIV-STATEMENT-01` | Privacy and compliance | A marketing component is acting as a legal notice without a governed content source, effective version or NZ-specific review contract. | High |
| `VIS-CR-SETTINGS-NAMES-01` | Control Room | Dense local form composition bypasses shared labelled-field and responsive overlay conventions. | Medium-high |
| `VIS-DEPLOYED-DRIFT-01` | Control Room / release evidence | The deployed test/dev application has no inspectable build identity; retained evidence measures page height but does not prove a source/deployed visual mismatch. | High for provenance and measured height; low for source causation |
| `VIS-HERO-DENSITY-01` | Shared visual system | Shared PageHero availability has not established an auditable per-page variant and first-action-distance contract. | Medium |
| `VIS-MOBILE-NAV-01` | Platform and shared UI | Global navigation is a hand-built fixed panel rather than the established accessible overlay primitive. | High source semantics; medium-high sampled interaction |
| `VIS-OVERLAY-FOCUS-01` | Shared overlays | Hundreds of custom wrappers/usages obscure a consistent, auditable trigger-return contract. | Medium |
| `VIS-RESPONSIVE-OVERFLOW-01` | Health and safety / shared responsive UI | The deployed document overflow is measured. Audited source wraps a min-w-[1080px] surface in overflow-x-auto, but the deployed SHA and computed ancestor trace are absent, so the exact escaping descendant/root cause cannot be attributed. | High for measured symptom; source cause blocked |

## MED-READER-SITE-CONCEALMENT-01 — P0 — eMAR and medications

Broad medication-read permissions reach global medication, controlled-drug, destruction, stock, alert, widget and report queries without canonical accessible-Site filtering or direct-object concealment. The retained source finding is open and runtime-unverified; a pushed or source-ready branch does not resolve it.

- Exact owners: `CAP-MED-MEDICATION-ORDER-LIFECYCLE`, `CAP-MED-CD-REGISTER-BALANCE`, `CAP-MED-STOCK-CONTROL`, `CAP-MED-DESTRUCTION-REGISTER`, `CAP-MED-API-ALERT-LIFECYCLE`, `CAP-MED-API-DASHBOARD-WIDGETS`, `CAP-MED-API-REPORT-DISPATCH`
- Evidence: `routes/emar.php:79-96,139-141`; `routes/api_medications.php:12-16,76-100`; `app/Http/Controllers/Emar/EmarController.php:87-90,591-619,1452-1522,1692-1729,1811-2100,2749-2828`; `app/Http/Controllers/Api/MedicationsApiController.php:927-963,1028-1082`; `app/Services/MedicationReportingService.php`
- Required verification: two-Site same-Site positive, foreign-list/direct-ID/report concealment, omitted-filter denial, and explicit global-Site permission plus exact action-permission positive.

## HR-WEBHOOK-OUTBOUND-SSRF-01 — P1 — Human resources

An actor with `hr.settings.manage` can persist a generic-URL-validated webhook destination, and event publication or retry queues a job that posts to it without a canonical private/reserved-address, DNS-binding or redirect policy. This is source-only evidence: no worker, destination, response or exploit was executed.

- Exact owners: `CAP-HR-WEBHOOK-ENDPOINTS`, `CAP-HR-WEBHOOK-DELIVERY-RETRY`
- Evidence: `routes/hr.php:1269-1283`; `app/Http/Controllers/Hr/HrWebhookController.php:79-123,143-150`; `app/Domain/Hr/Services/HrWebhookService.php:46-156`; `app/Domain/Hr/Jobs/DeliverHrWebhookJob.php:76-107,125-136`; `tests/Feature/Hr/HrWebhookDeliveryTest.php:36-129`
- Required verification: fake-resolver/fake-transport denial for loopback/private/link-local/IPv6, DNS rebinding and redirect-to-private targets, plus an approved public endpoint and stable retry path.

## CLIN-PROTOCOL-SCHEDULING-01 — P1 — Health and clinical

Six time-based clinical protocol frequencies depend on `ClinicalProtocolService::generateSchedule()`, but the production call graph contains only the method declaration. Protocol write paths persist the definition while due/overdue consumers only read existing schedule rows, and an empty denominator can report 100% compliance. This is source-only evidence: no deployed protocol, scheduler, missed observation or harm was executed or observed.

- Exact owner: `CAP-CLIN-PROTOCOL-DEFINITION-LIFECYCLE`
- Evidence: `routes/health-clinical.php:69-86`; `app/Http/Controllers/Clinical/HealthClinicalProtocolController.php:22-132`; `app/Domain/Clinical/Services/ClinicalProtocolService.php:25-65`; `app/Http/Controllers/Clinical/ShiftClinicalController.php:32-49`; `app/Domain/Clinical/Services/ClinicalDashboardService.php:60-74,240-264,922-952`
- Required verification: disposable-MySQL protocol creation/activation, bounded due-row visibility, activation/deactivation reconciliation, replay/concurrency convergence and honest failed/empty compliance reporting.

## FLEET-BOOKING-SITE-PRIVACY-01 — P0 — Fleet and vehicles

An ordinary Site-bound Support Worker can receive `fleet.viewAny`, while the vehicle-booking controller globally lists, exports and counts bookings; returns all active vehicles, Sites and Clients including transport needs; accepts global asset/Site IDs; and direct-loads lifecycle mutations without canonical Site/object scope. This is source-only evidence: no foreign record was populated, accessed or mutated.

- Exact owners: `CAP-FLEET-VEHICLE-BOOKING-REQUEST`, `CAP-FLEET-VEHICLE-BOOKING-DECISION`, `CAP-FLEET-VEHICLE-BOOKING-CHECKOUT-RETURN`
- Evidence: `routes/fleet-assets.php:108-126`; `database/seeders/RbacSeeder.php:732-759`; `app/Http/Controllers/FleetAssets/VehicleBookingController.php:23-100,202-375,391-511`; `app/Policies/AssetPolicy.php:17-21`; `app/Services/UserSiteAccessService.php:53-83,825-833`
- Required verification: disposable two-Site same-Site positive, foreign register/picker/direct-ID concealment, zero side effects, and explicit global-Site plus exact booking-action permission positive.
