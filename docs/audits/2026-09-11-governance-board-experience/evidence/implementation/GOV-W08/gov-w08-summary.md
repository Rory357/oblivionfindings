# GOV-W08 Implementation Summary: Make Dashboard Availability, Provenance and Refresh Honest

## Work Package Details
- **Task ID**: GOV-W08
- **Acceptance Criteria**: GOV-A08 (Honest Availability, Provenance and Refresh)
- **Status**: Verified
- **Date**: 2026-09-12
- **Engineer**: Gemini 3.8 Flash

---

## 1. Problem & Before/After State
- **Before**:
  - **Finance writes on GET endpoints (GOV-F09)**:
    - Calling `GET /governance/dashboard/data?fresh=1` executed `syncBudgetActuals`, creating/updating `Budget` and `FinancialActual` records on read.
    - Calling `GET /governance/reports/board-monthly` also executed `syncBudgetActuals` write logic on read.
  - **Filtered-List Serialization Crash (GOV-F26)**:
    - `GovernancePresenter::buildTimeline` serialized filtered collections with `'events' => $formatted->all()`. When items were filtered out (due to spend approval access or other audience checks), PHP associative keys were preserved, serializing as a JSON object instead of an array.
    - `GovernanceTimeline.tsx` failed to defensively validate whether `timeline.events` was an array, crashing or causing runtime errors.
  - **Fabricated Healthy State & Masked Failures**:
    - `DashboardAggregatorService` was exposing raw exception messages in widget error payloads, leaking internal stack and schema details.
    - `DashboardController::data` caught unhandled aggregation errors and returned a fabricated healthy zero snapshot (`['status' => 'good', 0, 0, ...]`) rather than reporting the outage.
    - `DashboardAggregatorService::getRiskChanges` used a broken query (`residual_score > inherent_score`) to flag escalations, misidentifying mitigated risks whose residual score was naturally lower than inherent.
  - **Client-side Error Handling & Race Conditions**:
    - When refresh failed, error messages were either lost or completely blanked out the cockpit.
    - Fast switching between date periods (Today / Week / Month / Year) lacked request sequencing, allowing stale responses from slower queries to overwrite fresher selections.

- **After**:
  - **Eliminated Finance writes on GET endpoints (GOV-F09)**:
    - Removed `syncBudgetActuals` and `BudgetActualsService` invocations from both `DashboardController::data` and `ReportController::boardMonthly`.
    - `fresh=1` strictly invalidates the user-scoped dashboard cache (`Cache::forget($cacheKey)`) and recomputes read data without executing any mutation queries against Finance models.
  - **Fixed Filtered-List Serialization Crash (GOV-F26)**:
    - `GovernancePresenter::buildTimeline` now explicitly returns `$formatted->values()->all()`, guaranteeing a 0-indexed sequential JSON list.
    - `GovernanceTimeline.tsx` includes client-side defensive checks: `if (!Array.isArray(timeline?.events))` renders an honest error state ("Recent activity could not be loaded") with a Retry button without crashing the cockpit.
    - Passed `onRefresh` to `CockpitLayout` and forwarded to `GovernanceTimeline` as `onRetry`.
  - **Honest Availability, Error Handling & Escalation**:
    - `DashboardAggregatorService` suppresses raw internal exception text, returning sanitized generic error states (`['status' => 'unavailable', 'reason' => 'Widget data temporarily unavailable']`).
    - `DashboardAggregatorService::getRiskChanges` inspects actual audit logs for residual score increases rather than comparing residual against inherent score.
    - `DashboardController::data` reports unhandled exceptions and returns an honest 500 JSON response (`['message' => 'Board information could not be loaded.']`) instead of fabricating healthy zeroes.
  - **Preserved State on Refresh Failure & Request Monotonicity**:
    - `Dashboard.tsx` tracks `error` and `refreshError`. On initial load failure, displays a clear error state with Retry. On background refresh failure, preserves the last-known-good payload and renders an alert banner: "Refresh failed — showing data as of [time]".
    - Implemented `activeRequestRef` monotonic sequence counter in `Dashboard.tsx` to discard out-of-order period responses.

---

## 2. Test Verification
- **Automated Feature Tests**:
  - `tests/Feature/Governance/GovernanceDashboardTest.php`:
    - `test_dashboard_requires_authentication` (PASSED)
    - `test_dashboard_renders_for_admin` (PASSED)
    - `test_dashboard_data_endpoint_returns_snapshot` (PASSED)
    - `test_dashboard_data_includes_overdue_resolution_in_workflow` (PASSED)
    - `test_board_member_dashboard_data_includes_self_service_role_actions` (PASSED)
    - `test_dashboard_data_fresh_invalidates_cache_without_finance_writes` (PASSED - verified 0 DB write queries on GET with fresh=1)
    - `test_dashboard_data_returns_500_when_aggregator_fails` (PASSED - verified honest 500 on outage)
    - `test_dashboard_data_timeline_events_is_zero_indexed_list` (PASSED - verified zero-indexed list)
    - Result: **8 passed, 48 assertions (Exit 0)**
- **Automated Unit Tests**:
  - `tests/Unit/Governance/GovernancePresenterTest.php`:
    - `test_build_timeline_always_returns_zero_indexed_array` (PASSED)
    - `test_risk_changes_does_not_falsely_escalate_when_residual_score_below_inherent` (PASSED)
    - Result: **6 passed, 21 assertions (Exit 0)**
- **TypeScript Verification**:
  - `npm run types`: **Exit 0 (0 errors across entire TypeScript codebase)**
