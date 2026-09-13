# GOV-W05 Implementation Summary: Minutes Immutability, Attestation, and Concurrency Workflow

## Work Package Details
- **Task ID**: GOV-W05
- **Acceptance Criteria**: GOV-A05 (Minutes lifecycle and immutability)
- **Status**: Verified
- **Date**: 2026-09-12
- **Engineer**: Gemini 3.8 Flash

---

## 1. Problem & Before/After State
- **Before**:
  - Approved and signed minutes could be overwritten via PUT requests, permitting post-facto alteration of official governance records.
  - No optimistic concurrency or version locking: simultaneous edits resulted in blind overwrites without conflict detection.
  - Foreign key and actor ID divergence (GOV-F03 / GOV-F10): `governance_meetings.minutes_signed_by` and `minutes_approved_by` reference `board_members.id`, whereas controller endpoints were passing `users.id`, leading to potential FK violations or attribution to unrelated board members.
  - Attributes `signed_by`, `signed_at`, and `archived_at` were omitted from Eloquent `$fillable` and `$casts` on `MeetingMinute`.
  - Empty minutes could be approved without validation.
  - Minute signing was not replay-safe (repeated clicks reset `signed_at` and generated extraneous history entries).
  - No formal legal attestation copy was presented to the authorized signatory during signing.
  - No auditable correction mechanism existed: fixing typos in signed minutes required manual database edits.
  - Recently completed rail and presenter used an erroneous property (`$minute->meeting_id` instead of `$minute->governance_meeting_id`), producing broken links (`/governance/meetings/?tab=minutes`) (GOV-F08).
- **After**:
  - Direct updates to approved or signed minutes are strictly rejected at the domain level (`MeetingMinuteService::updateMinutes` throws `DomainException`), preserving immutable records.
  - Optimistic concurrency control via `expected_version` prevents two-editor race conditions (throws HTTP 409 Conflict if current version != expected).
  - Both `reviewed_by` and `signed_by` resolve the user's canonical `BoardMember` ID (`$user->boardMember?->id`), maintaining foreign key integrity while accessors (`drafter_name`, `reviewer_name`, `signer_name`) render clean attribution with `'Legacy attribution unavailable'` fallback for historical data.
  - Full fillable and datetime casting added for `signed_by`, `signed_at`, and `archived_at`.
  - `approveMinutes` validates `hasReviewableContent()`, rejecting approval of blank or empty template minutes.
  - `signMinutes` is idempotent and replay-safe, retaining the original signing timestamp.
  - Formal internal attestation copy is presented in the UI modal before signing: *"By signing below, you confirm on behalf of the Board that these minutes represent an accurate, true, and complete record of the proceedings."*
  - `createCorrection` preserves the full superseded signed/approved snapshot in `version_history` along with the reason for correction, author, and timestamp, incrementing the version to draft vN+1.
  - Presenter fixed to use `governance_meeting_id`, generating valid URLs (`/governance/meetings/{id}?tab=minutes`).
  - Meeting show UI features complete lifecycle control: status badges, SHA-256 fingerprint, 4-card attribution grid, optimistic version editing, review submission, approval, formal signing, correction workflow, archival, and collapsible version inspection.

---

## 2. Code Changes
- **Backend Domain Model & Service**:
  - `app/Domain/Governance/Models/MeetingMinute.php`:
    - Added `signed_by`, `signed_at`, `archived_at` to `$fillable` and `$casts`.
    - Added `signedBy(): BelongsTo` relationship to `BoardMember`.
    - Added accessors: `content_hash`, `drafter_name`, `reviewer_name`, `signer_name`, `hasReviewableContent()`, `canEdit()`, `canSign()`, `canArchive()`.
    - Added `incrementVersion()` and `advanceStatus()`.
  - `app/Domain/Governance/Services/MeetingMinuteService.php`:
    - New domain service encapsulating draft creation, optimistic concurrency updates, review submission, approval with content validation, replay-safe signing with attestation, archival, and correction draft creation with lineage preservation.
- **Policies & Controllers**:
  - `app/Domain/Governance/Policies/GovernanceMeetingPolicy.php`:
    - Added `signMinutes()` and `archiveMinutes()` policy checks; updated `manageMinutes()` to permit `board_chair`, `board_secretary`, and `admin`.
  - `app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php`:
    - Injected `MeetingMinuteService`.
    - Updated `show()` to eager load `'minutes.draftedBy'`, `'minutes.reviewedBy.user'`, `'minutes.signedBy.user'` and pass `canSignMinutes`.
    - Updated `storeMinutes()`, `updateMinutes()`, `approveMinutes()`, `signMinutes()`.
    - Added `submitMinutesForReview()`, `archiveMinutes()`, `createMinutesCorrection()`.
  - `routes/governance.php`:
    - Added endpoints for review submission, signing, archiving, and corrections.
  - `app/Domain/Governance/Presenters/GovernancePresenter.php`:
    - Fixed `meeting_id` bug (GOV-F08) to use `governance_meeting_id`.
- **Frontend**:
  - `resources/js/Pages/Governance/Meetings/Show.tsx`:
    - Refactored Minutes tab with status badges, version numbers, SHA-256 digest, 4-way attribution grid, optimistic concurrency draft editing, review submission dialog, approval dialog, signing dialog with attestation, correction modal, archive modal, and expandable version history.
  - `resources/js/Components/Governance/RecentlyCompletedRail.tsx`:
    - Differentiated `minutes_approved` vs `minutes_signed`.
- **Tests**:
  - `tests/Feature/Governance/GovernanceMinuteIntegrityTest.php`:
    - Comprehensive 9-test suite verifying immutability, stale concurrency rejection, version history retention, user vs board_member ID divergence, signing idempotence, empty minutes rejection, correction lineage, and legacy attribution fallbacks.

---

## 3. Test Verification
```bash
pest tests/Feature/Governance/GovernanceMinuteIntegrityTest.php --compact
# Tests: 9 passed (49 assertions)

pest tests/Feature/Governance/GovernanceMeetingsTest.php --compact
# Tests: 9 passed (79 assertions)

npm run types
# tsc --noEmit: exited 0
```
