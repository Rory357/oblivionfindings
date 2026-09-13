# GOV-W02 Implementation Summary

## Task Objective
Apply one restricted-record audience through every projection (GOV-W02), resolving findings GOV-F01, GOV-F02, GOV-F12, and GOV-F16, and satisfying acceptance criterion GOV-A02.

## Changes Implemented

1. **`App\Domain\Governance\Services\GovernanceRecordAccessService`**:
   - Centralized parent-aware audience resolution and query scopes for meetings, resolutions, board packs, action items, performance reviews, and documents.
   - Enforced that child records (resolutions, packs, actions) inherit parent meeting visibility rules.
   - Implemented D2 rules for performance reviews: restricting raw review appraisals to chair/admin and remuneration/governance committee members, while providing reviewees (e.g. CEO) access to their own reviews and self-assessment workflow.

2. **`App\Domain\Governance\Services\ExecutiveMeetingAccessService`**:
   - Removed RSVP existence as a self-authorizing grant for executive session meetings (`canViewMeeting` and `applyMeetingVisibilityScope`).
   - Retained explicit designation by chair/admin for recorded attendance and active committee appointments.

3. **`App\Domain\Governance\Services\BoardPackAccessService`**:
   - Updated `visibleQuery` and `canView` to enforce parent meeting visibility via `ExecutiveMeetingAccessService`.
   - Prevented pack managers from viewing packs of executive sessions without meeting-level authority.

4. **`App\Domain\Governance\Policies\ResolutionPolicy` & `ResolutionController`**:
   - Updated `ResolutionPolicy::view`, `update`, `delete`, and `vote` to verify parent meeting visibility.
   - Scoped `ResolutionController::index` to prevent leakage of executive session resolutions and meetings.
   - Added `$this->authorize('view', $resolution)` in `ResolutionController::show`.

5. **`App\Domain\Governance\Policies\PerformanceReviewPolicy` & `PerformanceReviewController`**:
   - Implemented `PerformanceReviewPolicy` and registered it in `AuthServiceProvider`.
   - Authorized all `PerformanceReviewController` methods: `index`, `show`, `create`, `store`, `edit`, `update`, `submitAssessment`, `submitFeedback`, `submitSelfAssessment`, and `approve`.
   - Moved `self-assessment` and `feedback` routes outside the blanket `governance.performance.manage` middleware so reviewees can submit self-assessments governed by policy.

6. **`App\Domain\Governance\Policies\ActionItemPolicy` & Scoping**:
   - Delegated `view` checks to `GovernanceRecordAccessService::canViewActionItem`, checking parent meeting and resolution visibility.
   - Applied `scopeActionItems` to `GovernanceWorkflowService::actionItemActions`.

7. **`App\Domain\Governance\Support\GovernancePresenter` & `GovernanceWorkflowService`**:
   - Applied viewer-scoped meeting filtering in `buildNextMeeting`, `buildBoardPack`, `buildCalendarEvents`, `buildKpiBand`, and `meetingReadinessCard`.
   - Prevented executive session titles, dates, or actions from leaking into ordinary members' cockpits, KPI bands, calendars, or workflows.

8. **`App\Domain\Governance\Http\Controllers\GovernanceDocumentController`**:
   - Updated `download` to use `Storage::disk('local')` ensuring disk consistency between upload and download paths.

## Verification
Automated test suite:
- `tests/Feature/Governance/ExecutiveMeetingVisibilityTest.php`
- `tests/Feature/Governance/GovernanceBoardPacksTest.php`
- `tests/Feature/Governance/GovernancePerformanceReviewTest.php`
- `tests/Feature/Governance/GovernanceDerivedAudienceTest.php`

Command:
`& 'C:\Users\steph\.config\herd\bin\php84\php.exe' vendor/bin/pest tests/Feature/Governance/ExecutiveMeetingVisibilityTest.php tests/Feature/Governance/GovernanceBoardPacksTest.php tests/Feature/Governance/GovernancePerformanceReviewTest.php tests/Feature/Governance/GovernanceDerivedAudienceTest.php --compact`

Result:
**36 passed, 697 assertions, 0 failures (299.40s)**.
