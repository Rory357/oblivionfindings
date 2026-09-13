# W07 scanner integration — bounded proposal

Prepared from `w07-attachment-capability-map.md`; no implementation or runtime change is included. This proposal keeps all files on canonical ItAttachment rows and the existing private disk. W06 imported JavaScript remains frozen during Build6.

## First independent backend slice

Extract the executable scanner implementation from `ConsentEvidenceMalwareScanner` into a neutral shared file-security service, keeping the existing consent class/signature as a compatibility adapter. The neutral entry point accepts only a server-owned readable local path or a typed file object prepared from a protected storage stream. Preserve direct Symfony Process argument-array execution, the existing timeout bounds, clean/infected/unavailable result semantics and safe configured scanner identity. Add bounded error codes without exposing stdout, filenames, paths or file content. Keep existing consent environment resolution backwards compatible; do not install, activate or select a second provider.

Test this through an injected process runner or Symfony Process seam using local tests only. Cases: unreadable/missing file, missing/unusable binary, clean exit, infected exit, other exit, thrown/timeout execution and configured argument handling. Existing ConsentEvidenceService mocks and consent lifecycle regression must still pass. These tests use synthetic inert bytes and injected process outcomes; no production mock, real scanner call or malware fixture is introduced. This extraction can be reviewed and verified independently while provider configuration and W07 comment integration remain open.

## Additive IT lifecycle contract

Use one additive migration on `it_attachments`, with a small canonical scan projection separate from `draft_storage_state`. Proposed fields are `scan_status`, `scan_attempt_uuid`, `scan_claimed_at`, `scan_checked_at`, `scan_sha256`, `scan_scanner`, `scan_error_code` and `scan_attempts`. Exact names can follow repository conventions at implementation review. `scan_status` distinguishes pending/scanning/clean/quarantined/unavailable; null historical state presents as unverified, never clean. The scan digest is evidence for the exact scanned/downloaded bytes; the existing draft upload hash remains the exact-upload idempotency check. No tenant/organisation scope field or new attachment table is introduced.

A small IT scan service/job claims an exact existing attachment row and attempt under canonical locks, reads the current private object into bounded verified bytes, runs the shared scanner outside the business transaction, and applies the result only if row, path, expected content and attempt still match. Removal/transfer/retry cannot be undone by a stale completion. A killed worker leaves a durable scanning claim with a diagnosable retry path; retry makes a new explicit attempt and never turns unknown into clean. Scan/quarantine events use required audit with safe identifiers/counts/codes.

The actual provider is not needed for fake-scanner lifecycle tests. It is needed before real clean/quarantine acceptance can be marked Verified. Preserve the observed local gap: scanner binary/env/PATH unavailable in the checked tool environment; Herd process and real engine/signature state are unverified.

## Attach, retry and download integration

Extend `ItAttachmentStorageService`, `ItTicketDraftAttachmentService` and their existing DTOs rather than creating another upload endpoint/store. Storage-ready and scan-clean are independent. Existing row/path reservation, upload UUID/hash checks, five-file/10MiB limits, current actor/draft locks, transfer and removal tombstones remain authoritative. Malformed/oversize/forbidden upload is a known validation rejection; storage/scan outage is an explicit recoverable state. Text-only requests must not wait for the scanner.

`ItTicketController::downloadAttachment` retains its current ticket/comment/internal/draft policy checks, then denies any non-downloadable scan state. Future previews use that same gate. A clean response streams the same bounded bytes that were checked against `scan_sha256`, following the consent verified-stream pattern; verify-then-reopen should not reintroduce a content race. Use server-detected MIME/sanitized filename metadata. Failed integrity becomes an unavailable/quarantined state with safe evidence, not a success response or a raw path error.

Authorized retry/removal actions operate on current canonical ownership/audience and use the existing durable attachment row as their recovery intent. Failed physical deletion retains a retryable tombstone/cleanup state. Reconciliation must run independently of draft feature activation for already-submitted ticket/comment attachments. Register and monitor the separate existing `it.prune-drafts` expiry/cleanup command before enabling persisted drafts; no registration was found in the inspected current app/routes/config.

## One operational decision before active upload wiring

The plan requires truthful quarantine/retry and distinguishes comment commit from delivery. It does not settle whether a new reply may commit its text while an attachment scan is unavailable, or whether the entire attached reply must wait. Do not silently choose this behavior while implementing the scanner. The recommended recoverable contract is a committed text/comment with clearly pending private attachment metadata, no download/preview/provider transfer until clean, and an explicit attachment outcome in the acknowledgement. This avoids losing a useful reply during a scanner outage, but it needs the operational owner's decision under DP07 because it changes what “reply submitted” includes. The alternative is a pre-commit attached-reply blocker that preserves the exact draft/files for retry. Either can be tested without provider access once chosen.

Quarantine retention/removal authority, signature freshness acceptance, scanner process permissions and historical unverified-file treatment also need explicit DP07/W26 decisions. No arbitrary expiry, historical clean backfill or production cleanup is proposed. Independent scanner extraction and fake lifecycle tests can proceed before these choices; live wiring/verification must keep the unresolved gates visible.

## Focused acceptance evidence

- Unit scanner contract plus existing consent regression; no live engine claimed.
- Isolated IT attachment tests for clean/infected/unavailable/timeout/hash change, original upload retry and no duplicate row/path, stale result after remove/retry/transfer, current requester/technician/internal permissions and copied download/preview denial.
- Isolated storage failure/cleanup retry and killed-worker claim recovery using exact owned fixtures; no broad cleanup.
- Exact comment acknowledgement and composer retention for the selected commit policy, keeping comment commit, attachment availability and notification delivery as different facts.
- After an approved local scanner fixture/configuration exists: real clean/flagged/unavailable outcomes and desktop browser recovery. Then E05/W07/W26 may record actual verified evidence. Current source-read mapping and injected outcomes alone cannot close those gates.
