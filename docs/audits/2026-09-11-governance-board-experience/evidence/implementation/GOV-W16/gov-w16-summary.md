# GOV-W16 Implementation Summary: Repair Risk and Compliance Assurance, Evidence, and Recurrence

## 1. Overview & Objectives
- **Task ID**: GOV-W16
- **Title**: Repair risk and compliance assurance, evidence and recurrence
- **Findings Addressed**:
  - `GOV-F08` (P1): Risk and compliance totals and zero-record summaries.
  - `GOV-F14` (P1): Compliance completion can steal evidence and skip the next cycle.
  - `GOV-F19` (P2): Consistency with modern design tokens, structured dialogs, and clear remedy flows.
  - `GOV-F21` (P2): Stable recurrence progression and cross-period lineage.
- **Acceptance Criteria**: `GOV-A16`, relevant `GOV-A25`–`GOV-A28`.

---

## 2. Technical Implementation

### Database Layer
- **Migration**: `database/migrations/2026_09_12_000042_enhance_compliance_obligations.php`
  - Added `version_number` (`unsignedInteger`, default 1) for optimistic concurrency control.
  - Added `completion_notes` (`text`, nullable) to capture formal evidence and fulfillment justification.
  - Added `parent_obligation_id` (`foreignId`, nullable, references `compliance_obligations.id`, `nullOnDelete`) to track lineage across recurring cycles.
  - Added `recurrence_cycle_key` (`string(120)`, nullable, indexed) to guarantee cycle uniqueness and idempotent replay safety.

### Model Layer
- **`App\Domain\Governance\Models\ComplianceObligation`**:
  - Updated `$fillable` and `$casts` for `version_number`, `completion_notes`, `parent_obligation_id`, and `recurrence_cycle_key`.
  - Added `parentObligation(): BelongsTo` and `recurrences(): HasMany` relationships for audit lineage.
  - Enhanced `markComplete(int $userId, ?string $notes = null, ?int $expectedVersion = null)`:
    - Enforces optimistic concurrency (`409 Conflict` on version mismatch).
    - Automatically increments `version_number`.
    - Persists `completion_notes` and sets `status = complete`.

### Domain Service Layer
- **`App\Domain\Governance\Services\ComplianceEngineService`**:
  - `calculateNextDueDate(string $frequency, ?Carbon $from = null): Carbon`:
    - Fixed recurrence math to guarantee next due date is strictly greater than prior due date.
    - **Monthly**: Advances 1 month. If starting on month-end (e.g. Jan 31), lands on next month-end (e.g. Feb 28 in non-leap, Feb 29 in leap year; Feb 28 lands on Mar 31).
    - **Quarterly**: Advances 3 months with quarter-end preserved (e.g. Mar 31 lands on Jun 30).
    - **Annual**: Advances 1 year. Leap day (Feb 29) advances to Feb 28; Dec 31 advances to next year's Dec 31.
  - `completeObligation(ComplianceObligation $obligation, User $completedBy, ?array $evidenceIds = null, ?string $notes = null, ?int $expectedVersion = null)`:
    - Wrapped in a database transaction with pessimistic row lock (`lockForUpdate()`).
    - **Idempotency**: If obligation is already `complete`, returns early as a safe no-op.
    - **Optimistic Concurrency**: Verifies `expected_version` before mutation.
    - **Foreign Evidence Reparenting Denial**: Validates that all passed evidence IDs strictly belong to the obligation being completed (`compliance_obligation_id === $locked->id`). Reparenting is strictly rejected with `ValidationException`.
    - **Expired Evidence Denial**: Validates that no selected evidence has `valid_until < today()`. Expired items throw `ValidationException`.
    - **Mandatory Evidence Enforcement**: When `evidence_required` is true, verifies that active, unexpired evidence is linked to the obligation.
    - **Truthful Evidence Derivation**: Sets `evidence_provided` strictly based on the presence of valid, unexpired evidence.
    - Automatically triggers `scheduleNextOccurrence()`.
  - `scheduleNextOccurrence(ComplianceObligation $completed)`:
    - Idempotently creates exactly one next occurrence with unique `recurrence_cycle_key` (`CYCLE-{framework}-{code}-{date}`).
    - Copies framework, title, description, requirements, priority, frequency, reminder days, owner, backup owner, evidence requirements, and sign-off configuration.
    - Links `parent_obligation_id` to maintain historical lineage.
    - Dispatches scheduled reminders for the new cycle.
  - `uploadEvidence(...)`:
    - Derives `evidence_provided` based on presence of active unexpired evidence.

### Controller Layer
- **`App\Domain\Governance\Http\Controllers\ComplianceController`**:
  - `complete(Request $request, ComplianceObligation $obligation)`:
    - Validates `evidence_ids` (array of existing evidence IDs), `completion_notes` (nullable string up to 2000 chars), and `expected_version` (nullable integer).
    - Passes all inputs to `ComplianceEngineService::completeObligation`.
  - `show(ComplianceObligation $obligation)`:
    - Eager-loads `owner`, `completedBy`, `signedOffBy`, `evidence.uploadedBy`, `reminders`, `parentObligation`, and `recurrences`.

### User Interface (Show.tsx)
- **`resources/js/pages/Governance/Compliance/Show.tsx`**:
  - Replaced browser `confirm()` with a comprehensive modal dialog (`Complete Compliance Obligation`).
  - Lists all attached evidence with their upload metadata, expiration date, and validity status (`Valid until [date]` vs `Expired`).
  - If `evidence_required` is true and no valid evidence is attached, displays a warning banner and disables completion, providing a 1-click action to open the evidence upload dialog first.
  - Captures `completion_notes` explaining how compliance was fulfilled.
  - Transmits `expected_version` for optimistic locking.
  - Displays `completion_notes` and prior/next recurrence cycle links in the UI.

---

## 3. Verification & Evidence

### TypeScript Verification
```bash
npm run types
# Output:
# > types
# > tsc --noEmit
# Exit code: 0
```

### Automated Pest Suite
```bash
& 'C:\Users\steph\.config\herd\bin\php84\php.exe' vendor/bin/pest tests/Feature/Governance/GovernanceRiskRegisterTest.php tests/Feature/Governance/GovernanceComplianceTest.php tests/Unit/Governance/RiskScoringServiceTest.php tests/Unit/Governance/ComplianceEngineServiceTest.php tests/Feature/Governance/ComplianceReminderQueuedRecipientAuthorizationTest.php --compact
# Output:
# Tests: 36 passed (142 assertions)
# Duration: 371.26s
# Exit code: 0
```

### Key Test Cases Verified
1. **Recurrence Date Math**:
   - `test_calculate_next_due_date_annual_31_dec_advances_strictly_to_next_year` (Dec 31, 2026 -> Dec 31, 2027)
   - `test_calculate_next_due_date_annual_leap_day_advances_to_feb_28` (Feb 29, 2024 -> Feb 28, 2025)
   - `test_calculate_next_due_date_monthly_month_end_advances_to_next_month_end` (Jan 31 -> Feb 28, Feb 28 -> Mar 31)
   - `test_calculate_next_due_date_quarterly_quarter_end_advances_to_next_quarter_end` (Mar 31 -> Jun 30)
2. **Evidence Validation & Protection**:
   - `test_complete_obligation_blocks_when_evidence_required_and_none_attached` (throws `ValidationException`)
   - `test_complete_obligation_blocks_expired_evidence` (throws `ValidationException`)
   - `test_complete_obligation_forbids_borrowing_foreign_evidence` (throws `ValidationException` without reparenting)
   - `test_complete_obligation_fails_validation_when_borrowing_foreign_evidence` (HTTP 422 in feature test)
3. **Idempotent Replay & Concurrency**:
   - `test_complete_obligation_idempotent_replay_creates_only_one_next_cycle` (completing twice produces exactly 1 next occurrence)
   - `test_complete_obligation_enforces_optimistic_concurrency_version` (stale version throws HTTP 409)
   - `test_complete_obligation_fails_with_409_on_stale_expected_version` (HTTP 409 in feature test)
4. **Summary Stability**:
   - `test_category_summary_handles_zero_records_cleanly`
   - `test_board_report_handles_zero_records_cleanly`
