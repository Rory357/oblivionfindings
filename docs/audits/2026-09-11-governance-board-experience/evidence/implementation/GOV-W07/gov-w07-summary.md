# GOV-W07 Implementation Summary: Derive Full Authorised Totals and Personal Obligations

## Work Package Details
- **Task ID**: GOV-W07
- **Acceptance Criteria**: GOV-A07 (Personal workflow obligations & KPI band aggregation)
- **Status**: Verified
- **Date**: 2026-09-12
- **Engineer**: Gemini 3.8 Flash

---

## 1. Problem & Before/After State
- **Before**:
  - `GovernanceWorkflowService::dashboardWorkflow` capped the summary total at 15 items (`$summary['total'] = min(15, ...)`), truncating total counts.
  - Action items queries were restricted to 14 days and capped with artificial limits (`limit(6)`, `limit(8)`), omitting blocked tasks and older actions.
  - `DashboardAggregatorService::getTopRisks` counted only the sliced top N items rather than the true count of above-appetite risks (e.g. 12 above-appetite risks were miscounted).
  - Personal obligations on the cockpit rail relied on user display names (`assignee === user.name`), resulting in task attribution leaks when users shared the same name.
  - Work obligations lacked a unified structure and typed schema distinguishing Vote, Read, Act, and Know items with stable identifiers and provenance.
  - Missing deadlines on legacy resolutions caused inconsistent priority and sorting.
  - Reading obligations were not tracked across board pack revisions.
- **After**:
  - **Typed Enums & DTO**:
    - Created `App\Domain\Governance\Enums\GovernanceArea` and `GovernanceWorkKind` (`vote`, `read`, `act`, `know`).
    - Created `App\Domain\Governance\Data\GovernanceWorkItem` with typed attributes: stable domain:record:obligation ID, kind, source metadata, title, reason, priority, status, due timestamps, assignee user ID, board member ID, required action metadata, source version, and area.
  - **Canonical Work Query (`GovernanceWorkQuery`)**:
    - Derives personal obligations for Vote, Read, Act, and Know without synthetic caps.
    - Strictly enforces server-side viewer ID attribution (`assignee_user_id === viewer.id`), completely eliminating duplicate-name task attribution leaks.
    - Derives full authorized totals before pagination (`total`, `critical`, `overdue`, `by_kind`).
    - Supports full pagination allowing access to any page or the last record.
    - Tracks board pack revisions: unread packs appear as read obligations; reading rev 1 clears obligation; generating and distributing rev 2 creates a new reading obligation.
    - Resolves electorate status via `isMemberInElectorate` on `GovernanceVotingProfileService`.
    - Handles missing deadlines with high priority and explicit review reason.
  - **Aggregation Accuracy**:
    - `GovernanceWorkflowService`: calculates true totals before applying display limits.
    - `DashboardAggregatorService`: computes `count`, `critical`, `high`, `medium`, and `above_appetite` from the full active query before applying `limit($limit)`. (12 above-appetite risks counted as 12).
    - `GovernancePresenter`: KPI band `open_actions` counts all open/in_progress/blocked items scoped to the viewer independently of sample caps.
  - **Frontend Identity Matching**:
    - `BoardPriorityCard.tsx`: updated `WorkflowAction` interface to include `area_key`, `due_at`, `assignee_user_id`, `board_member_id`, `kind`, `source`.
    - `MyNextActionsRail.tsx`, `CockpitLayout.tsx`, `Dashboard.tsx`: receives `currentUserId` and strictly matches `a.assignee_user_id === currentUserId`.

---

## 2. Test Verification
- **Unit Tests**:
  - `tests/Unit/Governance/GovernanceWorkflowServiceTest.php`: 7 passed (39 assertions)
  - `tests/Unit/Governance/GovernancePresenterTest.php`: 4 passed (17 assertions)
- **TypeScript**: `npm run types` passed with 0 errors.
