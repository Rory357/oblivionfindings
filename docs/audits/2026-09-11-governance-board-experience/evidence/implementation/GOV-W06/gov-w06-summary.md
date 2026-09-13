# GOV-W06 Implementation Summary: Board-Pack Immutability, Versioning Lineage, and Reading Acknowledgement Receipts

## Work Package Details
- **Task ID**: GOV-W06
- **Acceptance Criteria**: GOV-A06 (Board-pack generation, distribution, and reading acknowledgement)
- **Status**: Verified
- **Date**: 2026-09-12
- **Engineer**: Gemini 3.8 Flash

---

## 1. Problem & Before/After State
- **Before**:
  - `board_packs` had a unique constraint on `governance_meeting_id`, preventing multiple revisions for a single meeting.
  - Regenerating a board pack overwrote the existing record and its PDF on disk, destroying the audit trail of what was distributed to and read by the board.
  - Board-pack file paths used a naive naming scheme (`board-packs/board-pack-{meeting_id}-{date}.pdf`), risking path collisions for same-day/same-title packs.
  - Viewing a board pack triggered an automated on-mount POST to `/read`, falsely recording reading acknowledgement without explicit user action (GOV-F06).
  - Downloading a board pack was conflated with reading acknowledgement in some UI flows.
  - Manifest document counting (`count(document_manifest)`) counted top-level section keys rather than actual decision papers, committee reports, and supplementary documents.
  - No receipts existed for reading acknowledgement: when a pack was updated to revision 2, prior reads carried over or were lost.
  - Generation failures left no record or corrupted the existing published pack.
  - Checksums and internal storage paths were exposed on the public Inertia props.
- **After**:
  - Database schema updated (`2026_09_12_000034_add_versioning_and_status_to_board_packs_table.php`): dropped unique `governance_meeting_id`, added `revision_number` (int, default 1), `supersedes_id` (FK to self), `build_status` (`draft`, `published`, `failed`), `error_reference` (text), `is_current` (boolean, default true), and compound unique index `['governance_meeting_id', 'revision_number']`.
  - Immutable revision lineage: regenerating a pack creates revision N+1 with an atomic switch of `is_current = true`, keeping old PDF files on disk and preserving existing read and download receipts.
  - Unique collision-free paths: `board-packs/board-pack-m{meeting_id}-rev{rev}-{hash}.pdf`.
  - Removed auto-read on mount (GOV-F06): reading acknowledgement requires an explicit button click ("Mark Revision N as Read"), which generates an immutable, tamper-evident receipt containing `receipt_id` (UUID), `member_id`, `read_at`, and `revision_number`.
  - Distributing revision N+1 invalidates the "read" state for the new revision: board members must acknowledge the new revision independently.
  - Downloading records in `download_tracking` only, never marking the pack as read.
  - Manifest counting fixed: `actualDocumentCount()` correctly sums actual decision papers, committee reports, and supplementary attachments, ignoring root section keys.
  - Failure resilience: failed generation records `build_status = 'failed'` without affecting existing published revisions.
  - Security & privacy: checksum and file path omitted from Inertia props (`missing('pack.checksum')`, `missing('pack.file_path')`); unauthorized requests return 404 for complete concealment.
  - Board pack index and show pages include revision filters (`all`, `current`, `draft`, `superseded`, `failed`), revision lineage inspection, superseded version warning banners, and receipt confirmation badges.

---

## 2. Test Verification
- **Suite**: `tests/Feature/Governance/GovernanceBoardPacksTest.php` and `tests/Unit/Governance/BoardPackPresenterTest.php`
- **Result**: 23 passed (527 assertions)
- **TypeScript**: `npm run types` exited 0.
