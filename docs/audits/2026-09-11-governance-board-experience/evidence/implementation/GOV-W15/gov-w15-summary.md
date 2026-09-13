# Implementation Evidence: GOV-W15

## Work Package Details
- **Work Package**: GOV-W15 (Make financial oversight and approvals enforce their authority)
- **Acceptance Criterion**: GOV-A15
- **Date**: 2026-09-12
- **Status**: Verified (100% Complete)

---

## Findings Addressed
- **GOV-F13**: `threshold_applies` flag was previously informational on budget adjustments while approval applied mutations directly without requiring a carried board resolution.
- **GOV-F09**: Unsupported one-sided reallocation adjustments mutated budgets without balanced two-sided transfers.
- **GOV-F19**: Direct object and authority boundary checks required enforcement for spend approvals and threshold-level budget adjustments.

---

## Changes Implemented

### 1. Domain Mutations & Authority Enforcement (`app/Domain/Governance/Services/GovernanceNestedMutationService.php`)
- **Unsupported Reallocation Rejection**:
  - `requestBudgetAdjustment`: Explicitly rejects `'adjustment_type' === 'reallocate'` with `ValidationException`: *"Unsupported one-sided reallocation. Reallocation requires a balanced two-sided transfer."*
  - `adjustedLineAmount`: Rejects `'reallocate'` defensively with `ValidationException` to prevent single-line reallocation abuse.
- **Threshold Rule & Carried Resolution Enforcement**:
  - `approveBudgetAdjustment`: Accepts optional `?int $approvalResolutionId = null`.
  - Evaluates `$thresholdApplies = (bool) ($lockedAdjustment->threshold_applies ?? $lockedBudget->requiresBoardApproval((float) $lockedAdjustment->amount))`.
  - When threshold applies, strictly requires a board resolution (`$resolutionId = $approvalResolutionId ?? $lockedAdjustment->approval_resolution_id`).
  - If threshold applies and no resolution is supplied, throws `ValidationException` on both `'approval_resolution'` and `'approval_resolution_id'`: *"This budget adjustment requires a carried board resolution."*
  - Locks and reloads the linked `Resolution` using `lockForUpdate()`.
  - Validates that resolution status is finalized (`['closed', 'implemented', 'archived']`) and outcome is strictly `'carried'`. Draft, open, defeated, cancelled, or no-quorum resolutions are rejected.
  - Validates that if `cost_impact['amount']` is specified on the resolution, it matches the requested adjustment amount within $0.01.
  - Asserts that the resolution has not already been applied to another approved adjustment (`alreadyUsed` uniqueness query).
  - Idempotent approval replay: If the adjustment was already approved, replaying approval returns the adjustment without re-mutating lines or totals.
  - Automatically recalculates budget totals from the sum of line items via `recalculateBudgetTotal`.

### 2. Controller Validation & Payload Enrichment (`app/Domain/Governance/Http/Controllers/BudgetController.php`)
- **Resolution Linkage**:
  - `requestAdjustment`: Accepts optional `approval_resolution_id` (`['nullable', 'integer', 'exists:resolutions,id']`) and persists on request.
  - `approveAdjustment`: Accepts and validates `approval_resolution_id` (`['nullable', 'integer', 'exists:resolutions,id']`) and forwards to `approveBudgetAdjustment`.
  - `show`: Eager-loads `adjustments.approvalResolution:id,resolution_reference,title,status,outcome,cost_impact` and queries `carriedResolutions` (`outcome: carried`, `status: closed, implemented, archived`) to present board decision options.
  - `store`: Ensures `version_number` is auto-incremented dynamically per fiscal year to eliminate duplicate key collisions.

### 3. Model Integrity (`app/Domain/Governance/Models/Budget.php`)
- Added `boot` method with `creating` callback to auto-increment `version_number` per fiscal year when not explicitly provided.

### 4. Frontend Experience (`resources/js/pages/Governance/Budgets/Show.tsx`)
- **Adjustment Request Form**:
  - Disabled "reallocate" option in adjustment type selector with explanatory banner: *"One-sided reallocation is disabled. Reallocation requires a balanced two-sided transfer."*
  - Dynamic threshold alert: Displays warning when requested amount meets or exceeds 5% of total budget ($5,000 on $100k budget) noting board approval requirement.
  - Added Carried Board Resolution selector for upfront linking.
- **Pending Adjustments Table**:
  - Displays linked resolution reference badge with clickable link.
  - If threshold applies without a linked resolution, highlights *"Board decision required"* with direct link to `/governance/resolutions` and dropdown to select and apply an available carried resolution directly on approval.
- **Resolved Adjustments History**:
  - Shows linked resolution reference on approved adjustments for transparent fiduciary audit trails.

---

## Verification Results

### 1. PHPUnit / Pest Test Suites
Command:
```bash
& 'C:\Users\steph\.config\herd\bin\php84\php.exe' vendor/bin/pest tests/Feature/Governance/GovernanceBudgetsTest.php tests/Feature/Governance/GovernanceSpendApprovalsTest.php tests/Feature/Governance/SpendApprovalAuthorityTest.php tests/Feature/Governance/GovernanceNestedBindingIntegrityTest.php tests/Feature/Finance/BudgetActualsLiveGlTest.php --compact
```
Output:
```
......................................

Tests:    38 passed (213 assertions)
Duration: 309.67s
```

Test Coverage Breakdown in `GovernanceBudgetsTest.php`:
1. `test_admin_can_view_create_page`: Confirms inertia component rendering.
2. `test_admin_can_create_budget`: Confirms budget creation and auto-versioning.
3. `test_admin_can_propose_and_update_budget`: Confirms workflow transitions.
4. `test_admin_can_view_budget_show`: Confirms show page props and resolution loading.
5. `test_adjustment_below_threshold_is_approved_without_board_resolution`: Proves adjustments below 5% threshold are approved directly and update line item and total budget amounts.
6. `test_adjustment_at_or_above_threshold_requires_board_resolution_to_approve`: Proves adjustments >= 5% strictly require a carried resolution and reject approval without one.
7. `test_adjustment_above_threshold_fails_with_draft_or_defeated_resolution`: Proves draft and defeated resolutions are rejected.
8. `test_adjustment_above_threshold_fails_when_resolution_amount_mismatches`: Proves resolutions with mismatched authorized amounts are rejected.
9. `test_adjustment_above_threshold_succeeds_with_carried_closed_resolution`: Proves valid carried closed resolution links successfully and updates line item and total budget.
10. `test_carried_resolution_cannot_be_reused_across_multiple_adjustments`: Proves a resolution cannot be reused across multiple approved adjustments.
11. `test_approved_adjustment_replay_is_idempotent`: Proves replaying an approved adjustment is idempotent without double-applying values.
12. `test_one_sided_reallocation_adjustment_is_rejected`: Proves one-sided reallocations are rejected with a validation exception.

### 2. Frontend TypeScript Typecheck
Command:
```bash
npm run types
```
Output:
```
> types
> tsc --noEmit
```
Exit 0 (clean, 0 errors).

---

## Boundaries Preserved
- **Single-Tenant Boundary**: Authenticated and scoped strictly within organizational and canonical site boundaries; zero multi-tenant constructs added.
- **Design Integrity**: Preserved existing design system tokens and component guidelines.
- **Spend Approval Command Invariants**: Existing row-locking, `expected_version`, content digest checks, and site scoping in `SpendApprovalCommandService.php` preserved without alteration.
