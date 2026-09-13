# GOV-W04 Implementation Summary: Atomic Voting, Recusal, and Closure

## Work Package Details
- **Task ID**: GOV-W04
- **Acceptance Criteria**: GOV-A04 (Voting integrity)
- **Status**: Verified
- **Date**: 2026-09-12
- **Engineer**: Gemini 3.8 Flash

---

## 1. Problem & Before/After State
- **Before**:
  - `castVote` and `closeVoting` had no database concurrency control or pessimistic row locking (`lockForUpdate`), allowing concurrent cast vs close race conditions.
  - Declaring a conflict with withdrawal erroneously injected an `abstain` vote into the `votes` table, corrupting both participation counts and voting intent (recusal != abstention).
  - Out-of-session resolutions could not record conflict declarations because `governance_meeting_id` on `conflict_declarations` was non-nullable.
  - Resolutions where quorum was unmet were incorrectly stamped as `defeated` instead of `no_quorum` ("No valid decision — quorum not met").
  - Resolutions with unmet quorum could be finalized and transitioned to `implemented`.
  - Closed resolution results recalculated live values rather than strictly consuming the immutable `decision_snapshot`.
  - Vote replay was not idempotent.
- **After**:
  - `openVoting`, `castVote`, `declareConflict`, and `closeVoting` all execute within strict `DB::transaction()` blocks acquiring `Resolution::lockForUpdate()`.
  - Recusal is strictly segregated from abstention: `ConflictDeclaration` with `withdrew_from_voting: true` excludes the member from participation without creating an abstention vote in `votes`. Any prior vote is cleanly removed upon recusal.
  - Migration `2026_09_12_000033_make_governance_meeting_id_nullable_on_conflict_declarations_table.php` enables conflict declarations for both meeting and circular/out-of-session resolutions.
  - `determineOutcome` returns `'no_quorum'` when quorum is required but unmet. Ties are defeated only after valid quorum is satisfied.
  - `markImplemented` in `Resolution.php` and `finalize` in `ResolutionController.php` strictly forbid marking non-carried (`no_quorum`, `defeated`, `cancelled`) resolutions as `implemented`.
  - `getVotingResults` exclusively serves frozen snapshot data (`decision_snapshot`) once a resolution is closed, rendering historic decisions resilient against subsequent member resignation or deletion.
  - Idempotent vote replay returns the existing vote receipt without duplicating rows; conflicting duplicate attempts are rejected.
  - In `Show.tsx`, "Declare a conflict" is a separate dedicated modal dialog with required nature (material, related, prejudicial, other), description (min 20 chars), withdraw confirmation, and consequence explanation.

---

## 2. Code Changes
- **Migration**:
  - `database/migrations/2026_09_12_000033_make_governance_meeting_id_nullable_on_conflict_declarations_table.php`
- **Backend Services & Models**:
  - `app/Domain/Governance/Services/VotingService.php`:
    - Wrapped `openVoting`, `castVote`, `declareConflict`, and `closeVoting` in `DB::transaction` with `lockForUpdate()`.
    - Idempotent replay in `castVote`; conflicting vote rejection.
    - Exclusion of recused members from voting.
    - Pure recusal in `declareConflict` without creating abstention votes; cleanup of prior vote if member recuses later.
    - `getVotingResults` reading from `decision_snapshot` when resolution is closed.
  - `app/Domain/Governance/Models/Resolution.php`:
    - `closeVoting`: captures frozen snapshot including electorate roster, quorum details, individual votes, and conflicts.
    - `determineOutcome`: returns `'no_quorum'` if quorum is required and unmet.
    - `markImplemented`: guards against non-carried outcomes.
  - `app/Domain/Governance/Http/Controllers/ResolutionController.php`:
    - Handled exceptions with user-facing flash notices.
    - Provided `my_conflict` and passed `$resolution` to `calculateQuorum`.
    - Guarded `finalize` against implementing non-carried resolutions.
- **Frontend**:
  - `resources/js/Pages/Governance/Resolutions/Show.tsx`:
    - Added dedicated `<Dialog>` for declaring conflict of interest.
    - Replaced "Declare Conflict & Abstain" with clean separation between vote submission and conflict declaration.
    - Added "Conflict Declared — Recused from Voting" state badge/card.
    - Enhanced results view to display "No valid decision — quorum not met" and frozen snapshot status.
    - Guarded "Mark Implemented" button in finalize card to require carried outcome.
- **Tests**:
  - `tests/Unit/Governance/VotingServiceTest.php`:
    - Updated recusal test to assert `votes` table has no abstain entry.
    - Added tests for idempotent replay, conflicting duplicate rejection, recusal blocking vote, recusal removing prior vote, deadline enforcement, markImplemented guard, and frozen snapshot survival after member deletion.
  - `tests/Feature/Governance/ResolutionQuorumDecisionSnapshotTest.php`:
    - Updated expectations for unmet quorum to `no_quorum`.
    - Added `test_resolution_tie_is_defeated_only_after_quorum_is_met` contrasting tie after quorum met (`defeated`) with unmet quorum (`no_quorum`).
  - `tests/Feature/Governance/GovernanceResolutionsTest.php`:
    - Updated recusal test to assert no vote row is generated.
    - Added HTTP tests for idempotent replay, conflict rejection, out-of-session conflict, and finalize guard.

---

## 3. Automated Verification Results
- **Command**: `vendor/bin/pest tests/Unit/Governance/VotingServiceTest.php tests/Feature/Governance/GovernanceResolutionsTest.php tests/Feature/Governance/ResolutionQuorumDecisionSnapshotTest.php --compact`
- **Result**: `32 passed (141 assertions)` - Exit code: 0
- **TypeScript Verification**: `npm.cmd run types` - Exit code: 0
- **Regression Suites**:
  - `GovernanceDerivedAudienceTest.php` & `GovernanceSyntheticFixturesTest.php`: `8 passed (88 assertions)` - Exit code: 0
