# I2a — private vehicle documents, profile photo and reusable choices

22 September 2026. Root's next bounded backend contract within the released full v13 build. **Prepared, not a writer-custody grant.** I1 remains with the backend worker until its explicit handoff. No frontend edits by the worker. Root will verify the I1 commit and grant this increment separately without another Main approval.

## Ownership and compatibility

`AssetDocument` remains the file owner; canonical Asset remains the vehicle and profile-photo owner. Existing AssetPolicy `view` / `manageDocuments` and current approved-site/direct-object scope govern these records. A Fleet management grant does not manufacture document or Finance access. Recheck the current actor and scope inside every publication or metadata transaction, including retries. A guessed foreign file/group ID must converge on the missing-ID response.

Keep existing non-vehicle document routes and data behavior compatible. For canonical vehicles, old `AssetDocumentController` routes must delegate to the protected service or reject unsupported legacy mutations with an actionable message; they cannot physically delete the new history or download unscanned files. Source-owned Maintenance/check evidence continues through its canonical service. This increment does not allow adding files to immutable submitted answers by changing a vehicle document link.

## Additive schema and retained history

Use one new timestamped migration after I1:

- `asset_document_sets`: Asset, title, category label/catalogue ID, external reference, date-only effective/expiry dates, optional responsible user, lock version, archive actor/time/reason, created actor/time. One set represents one policy/agreement/certificate with several files. Its stable ID will own one optional renewal in I2b. No task is claimed created before that integration exists.
- `asset_document_set_events`: immutable set/Asset, source version, action, actor, timestamp, bounded before/after metadata snapshot, request key and request fingerprint. Unique set/request identity protects duplicate metadata commands.
- Extend AssetDocument with nullable set ID; file-series UUID and numeric revision; replaces-document ID; SHA256; server-detected MIME; state; scan disposition/scanner/time/failure code; request key/fingerprint; archive provenance. Preserve the old string `version` field without reinterpreting historical labels as numeric revisions. Unique Asset/request and series/revision; all generated MySQL constraint names must fit 64 characters.
- Add nullable `assets.profile_photo_document_id` pointing to a current available same-Asset image. Any identity/catalogue fields added below are nullable and have explicit foreign keys.
- `fleet_catalogue_entries`: bounded kind, label, normalised label, optional typed value JSON for an interval, active/archive provenance and created actor/time. Unique kind/normalised label, stable ID. No tenant scope. Audited catalogue create/retire commands retain referenced entries.

Backfill only canonical vehicle AssetDocuments: one set and series per existing file, unchanged original metadata/path/bytes, state `legacy_unverified`; no fabricated clean scan. Make retryable verification available where original bytes still exist. Do not rewrite non-vehicle files. Migration rollback refuses after any new upload, catalogue entry, photo selection, or non-backfill metadata/history; it may remove only untouched migration scaffolding. Never delete operational bytes in a migration.

## Private upload and recovery commands

Vehicle document upload accepts PDF, PNG or JPEG up to the approved preview's 10 MiB per file. Reject empty content, invalid detected MIME/extension pairs, and invalid decoded images. Use a random private disk path, safe download filename and byte hash; never a path based on the original filename. Multipart requests include a caller-generated idempotency key. Do not put file bytes or paths in audit logs or DTOs.

1. Scope and validate first. Under Asset → set → file locks, reserve one immutable upload identity, expected set version and optional current file being replaced. Fingerprint includes actor, Asset, set, metadata, replacement ID and byte hash. Same request key/different content conflicts; an identical retry retains the same file ID.
2. Store bytes on the existing `private` disk and scan using `Services/Files/MalwareScanner`, with explicit configuration (reuse the existing configured scanner adapter; no new provider). Store clean/infected/unavailable outcome honestly.
3. Re-resolve current actor, Asset, set version and replacement currentness before publishing. Only a clean successful upload becomes available. A concurrent metadata edit, permission withdrawal, retired group or competing replacement prevents publication and retains a recoverable recorded state.
4. Storage failure, unavailable scan and publication conflict retain their upload identity and an actionable retry path. Identical retry may resume the applicable stage; it must never publish infected bytes or mutate an already published version. Do not hold database locks during storage/scanning. Return the persisted state even when the file is not available so the frontend can show recovery.

The prior ready version remains available while its replacement is pending or fails. Current file selection is latest published revision within that series, not the latest upload attempt. Metadata edits require expected set version, reason and idempotency; expiry can be recorded directly in this operation. Archive is explicit, reasoned and retained. Historical authorised downloads remain possible for clean superseded/archived files, clearly labelled; unknown/infected/unavailable files remain blocked. Current access is checked on each download, no public URL, cache-private/no-store, safe disposition and `nosniff`.

Profile photo upload uses the same private clean image flow; selecting/replacing/removing a photo uses current document authority and version checks. A pending replacement never removes the current image. A file from another vehicle or a PDF cannot become a profile photo. Return an authorised image-stream URL only; raw storage paths stay server-side.

## Searchable catalogues

Create a bounded catalogue service for otherwise unowned labels only: service type, vehicle body/use purpose, document category, reminder type and interval preset. Do not create parallel Site/User/HR driver/Vendor/AssetCategory/Finance registries. Existing canonical search contracts serve those selectors. Outcomes, applicability, permissions and lifecycle statuses remain controlled choices; VIN, identifiers and reasons remain text.

Catalogue lookup is read-only, searchable and bounded/paginated. Explicit Add new requires current `fleet.manage` (and the relevant document management permission for a document-only entry point), normalises whitespace/case for duplicate handling, returns the existing stable ID for an identical label/value, and conflicts if an existing interval label names another value. Interval presets store quantity plus unit (`months`, historical `days`, or `km`); no fictional service policy is seeded. New values persist across refresh and are not browser storage. Usage endpoints validate the selected entry's expected kind and active state. Retired referenced entries remain readable, unavailable for new selections.

## API and DTO contract

Add scoped routes under `/fleet-assets/vehicles/{asset}` for document-set list/create/update/archive, upload/retry/archive, private download/image and profile-photo selection/removal. Use existing generic download URL where appropriate rather than duplicate access rules. Expose stable IDs, safe name/title/category/reference, date strings, versions, source state, current/history distinction, size/MIME and allowed actions. Include `can.documents_view`, `can.documents_manage`, `can.photo_manage` separately in the eventual workspace DTO. Return paged histories and aggregate totals without exposing rows beyond current permissions. No success message may claim a failed/queued scan completed.

I1 compliance may accept a linked clean same-Asset document as sourced evidence once this trust service exists; record the immutable file/version and trust at assessment time. Legacy document IDs alone remain insufficient. Archiving a retained original does not silently change a compliance outcome; a new source assessment owns that decision. Do not mutate old compliance versions to rewrite their trust.

## Required verification and handoff

Use only the isolated package profile after preflight. Test foreign/missing IDs and current-grant withdrawal; old-route bypass; forged MIME/empty/oversize/invalid image; clean/infected/unavailable scanner; failed write/retry and duplicate fingerprint conflict; concurrent replacements/metadata; previous photo retained; private download/headers/history; metadata dates/version conflict; stable multi-file set identity; catalogue duplicate/retired/wrong-kind handling; conservative legacy backfill and guarded rollback. Fake scanner outcomes only in isolated tests; never configure the application to claim clean without a scanner.

Commit exact backend/migration/tests/evidence files, report DTO/routes/test results and residuals, then explicitly relinquish application-writer custody. Do not stage root's other implementation preparation or frozen mockups. This increment does not mark the vehicle workspace complete.
