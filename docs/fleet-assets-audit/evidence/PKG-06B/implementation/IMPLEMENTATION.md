# PKG-06B Asset Profile implementation

Stephan authorised implementation on 27 September 2026: “Full Asset Profile, including documents”. He also explicitly requested adding the file-preview pattern to Rory’s design rules and implementing discovered gaps. This supersedes the earlier design-only scope for this checkout. It does not authorise deployment, a live database migration, or changes to other worktrees.

Working branch: `codex/asset-profile-implementation`, worktree `8821`, base `4ea64c547ed85a5b7504e59599db351f6eba7deb`. Frozen v1–v9 previews remain separate from production source.

## Completion pass — 27 September 2026

This section supersedes the earlier remaining-gap and TypeScript notes below. The production source now includes the complete authorised Asset Profile scope, with these final connections:

- Canonical ownership, linked suppliers, immutable submitted checklist versions, original Maintenance/check evidence and separately timestamped QR observations are projected through current permission, approved-site and privacy checks.
- Finance shows the last 12 months of posted Maintenance allocations, grouped by currency. Drafts, reversals and estimates are excluded; linked invoices are not added again. Replacement review uses the existing Finance queue and creates no approval, journal or disposal.
- Source-owned documents open their original protected endpoints and expose their original source. Asset document management actions cannot replace or archive those source records.
- Ownership changes use the existing ownership service, require their own permission and retain history. They do not move the asset or acknowledge custody.
- Scan identity is bound to the authenticated actor; wrong asset tokens, future timestamps and unauthorised sites are rejected. A scan does not alter placement.
- Bulk QR labels are available from the Assets register and Asset details. Select assets across pages, filter authorised records, configure A4 sheets or label-printer dimensions, set copies/used-sheet offsets, include the Branding logo, preview/download PDF, download labelled PNG/SVG ZIP files with manifests, and reopen recent exports. Limits are 200 assets, 20 copies per asset and 1,000 labels per batch; download access is rechecked and exports expire after seven days.
- Purchase, warranty, inspection and maintenance dates use the approved shared DatePicker. Optional values can be cleared. The shared calendar now places vertically on mobile and respects viewport width; desktop keeps the side calendar. Finance's year-spanning cost period includes both years.

Verification for this pass:

- Clean isolated backend regression: **31 tests, 564 assertions passed**. Three additional focused ownership/replacement/lifecycle-filter tests passed with **29 assertions**, inside rollback transactions on the identified synthetic fixture (**34 tests / 593 assertions across these runs**). An earlier dirty-fixture rerun had three baseline-count failures and is not used as acceptance evidence.
- Focused UI regression: **6 files, 39 tests passed**, including optional date clearing, required-date cancellation, file viewer states, receipt confirmation, document upload failures, Finance requests and shared navigation.
- Browser verified three distinct assets in A4 output starting at slot 18 (two pages), custom 60 × 50 mm output (three pages), embedded Branding logo, PDF preview/download and PNG/SVG ZIP history download. All six PDF codes and three PNG codes decoded to the same three stable identities; manifests matched. See `bulk-label-verification.json` and `bulk-labels-*-page-*.png`.
- Mobile calendar measured x=16, right=374 within a 390-pixel viewport. See `completion-mobile-calendar.png`. Original Maintenance PDF rendered two pages through the shared viewer; the original check link displayed the immutable submitted answer and its protected evidence.
- The QR workspace header now retains its title after keyboard focus on mobile (`overflow: clip`, internal scrollTop=0). Its content width is 375 pixels inside the 390-pixel viewport. The lifecycle filter uses the register's canonical `out_of_service` state and excludes inaccessible sites.
- Repository-wide TypeScript and focused ESLint passed with no diagnostics. The six earlier shared-navigation test typing errors were corrected. PHP formatting and `git diff --check` passed. No native `type="date"` inputs remain in the Asset Profile/editor paths.
- Final production build passed in **5m 29s** (`completion-build.log`); the existing large-chunk advisory remains. The temporary Vite server was stopped and its owned `public/hot` file removed. Browser reload confirmed the compiled **`app-Jte2U_jh.js`** bundle. The loopback PHP preview and its isolated fixture remain running for review.
- Frozen v9: **42 manifest files checked, zero changes**. The implementation preserves its layout hierarchy and approved patterns while using real source values and the shared application shell; it does not claim identical synthetic screenshots.

Release remains separate from local implementation: migrations, production scanner/Branding/base-URL configuration and physical printer/scanner calibration still need the normal release process. No live database or other worktree was changed.

## Implemented scope

- The canonical Asset Profile now uses the shared PageHeader and seven main views: Overview, Custody, Checks & service, Maintenance, Location, Components & kit, History. Overview contains Summary, Asset details, Documents and Finance. Vehicles hand off to their canonical Vehicle Profile.
- Readable, permission-controlled document library with original PDF/image preview, PDF page/zoom/text controls, original download, title search, retained version history, replacement, archive reasons and retryable scanner outages. Unsupported formats offer original download. Files are private and unavailable until scanning succeeds; existing legacy files retain their recorded legacy status.
- New custody records distinguish responsibility, dispatch, acknowledged receipt, incomplete/disputed receipt, loans and actual returns. Kit contents are snapshotted at dispatch and individually confirmed at receipt. Idempotent requests, asset locking and optimistic versions protect duplicate and stale submissions. All changes have an actor and immutable event; a receipt never releases a Maintenance hold.
- Existing assignment, Maintenance, Finance, device-access and lifecycle services remain canonical. Formal assessments use approved Maintenance checklists; asset check observations record facts without claiming safety release. Condition and optional approved due dates can be recorded with the observation.
- Finance includes source-owned fixed-asset reconciliation, linked Maintenance exceptions and a two-step Finance review request with type, source, optional estimate, note and checked evidence. Requests use the existing Finance queue, duplicate boundary and separation of duties. The queue returns to the correct Asset or Vehicle profile; no new approval or ledger subsystem is created. The existing internal `vehicle` source discriminator is retained for compatibility with the shared Finance service.
- Manual location observations retain their own source and timestamp. Assigned location and tracker observations remain separate; static equipment requires no GPS.
- Components retain their original asset identity/history. Linked component placement moves atomically with an acknowledged kit receipt and records the receipt in each component's history; a component cannot dispatch independently while still in a kit. Removal retains a reason. Retirement checks assignments, pending receipts, loans, linked devices, work, holds, Finance and kit dependencies.
- Checked document images can become the profile photo. Stable QR identities can be generated for legacy records that lack one. Individual PNG/SVG codes and printable PDF label copies support A4 sheets and custom label dimensions, offsets and Branding settings. A PDF export is recorded as an export, never as proof of physical printing.
- History is paged at 100 profile events. Foreign-site movement detail and manual observations are withheld outside the actor’s approved sites.

## Gaps closed during implementation

- Removed the production page’s hardcoded edit permission.
- Replaced non-vehicle upload’s direct-to-download path with private scanned document sets.
- Kept archived/replaced originals; blocked the old non-vehicle deletion endpoint.
- Rechecked asset/file ownership, permission and scan state at each download.
- Added real receipt and return state rather than deriving custody from an assignment or due date.
- Added explicit kit confirmation, stale-write rejection, safe retry, draft-discard confirmation and error recovery to the new record dialogs.
- Extended the existing retirement boundary so legacy retirement endpoints cannot bypass the new dependencies.
- Corrected Maintenance link targets and QR download path; corrected scan-history timestamps to `scanned_at`.
- Corrected inspection history to use the actual inspection timestamp. Restricted the shared schema probe to the configured application database; enumerating unrelated schemas was blocking the preview behind other worktrees' migrations.
- Kept final document states from being downgraded by late duplicate storage or scanner responses. Added empty-file validation and corrected the upload review summary.
- Updated the shared Finance review queue's wording and source links for non-vehicle assets.
- Kept single-organisation roles, approved sites, canonical ownership and privacy as the access boundary. No tenant machinery was introduced.

## Shared rules

`DESIGN.md` and `design_styles/POPUP_STYLE_GUIDE.md` now reference `design_styles/FILE_PREVIEW_STYLE_GUIDE.md`. The new guide is explicitly approved by Stephan; it does not claim approval from Rory. It specifies the shared viewer, exact-version downloads, failure states, accessibility, local rendering and source-level access checks.

## Visual comparison correction — 27 September 2026

The first implementation verified working flows but did not adequately verify visual parity with approved v9. A direct browser comparison found substantial structural differences. The production components have now been corrected against the frozen reference:

- Restored the identity/photo mark, scoped search, glass actions and vertically stacked header metrics; removed double page padding and the unrelated Vehicle Profile stylesheet dependency.
- Restored second-level navigation for custody, original checks versus service, kit contents versus replacement history, and history versus retirement review. Section search navigates to these real sections.
- Restored the custody origin-to-destination strip and side-by-side Responsibility and Exceptions panels. Actual receipt and assignment records drive their states and available actions.
- Restored Summary's attention, location/custody, original-check, document and recent-history hierarchy. Completed receipt is shown as context, not an outstanding action.
- Restored Asset details' separate profile-photo card, details grid, visible stable QR panel and document shortcuts. The QR panel opens the working label exporter; document shortcuts open the shared original-file viewer. Retirement is under History.
- Restored Documents' desktop table, List/Cards choice, summary counts, search and availability filter. Mobile uses cards. The header and library now count current records consistently, including a current file whose scan is unavailable.
- Restored Finance's value metrics, fixed-asset/source panel, linked work, review requests and replacement/retirement information. Missing values remain unavailable; conflicting Finance sources do not produce authoritative-looking amounts.
- Fixed a browser-only header defect: tab focus could scroll the identity row inside the rounded header. Scoped `overflow: clip` preserves the sky band's rounded clipping without making the header a scroll container. After switching tabs, computed overflow is `clip` and header scrollTop remains zero.

Evidence: `visual-custody.png`, `visual-summary.png`, `visual-identity.png`, `visual-documents.png`, `visual-finance.png`, `visual-file-preview.png`, `visual-mobile-custody.png` and `visual-mobile-documents.png`.

The focused dialog suite passed again after these changes (4 files / 10 tests), Asset Profile ESLint completed without errors or warnings, and TypeScript reported only the same six pre-existing navigation-test diagnostics. Mobile custody, documents and identity had matching viewport/content widths, and the shared custody wizard was visually rechecked (`visual-custody-modal.png`). Frozen v9 retained all 42 manifest hashes. See `visual-comparison.json` for the comparison checklist.

The final visual-correction production build passed in 4m 22s. The temporary Vite server was stopped and its `public/hot` file removed. Browser reload confirmed the compiled `app-DbcGuVKl.js` bundle, the restored Asset details and Finance layouts, and the final custody page with a visible identity row. The loopback PHP server and isolated fixture remain running for review.

This is a comparison of production structure and behavior, not a claim of identical screenshots. The real shared application shell and role-visible navigation remain canonical. The synthetic implementation record has an acknowledged receipt at Rimu House and no recognised fixed-asset source; the v9 design scenario shows a pending receipt at Kōwhai House, a Maintenance hold and illustrative Finance values. Those fixture facts were not copied into production logic. The completion pass below connects ownership, suppliers and source evidence and integrates the focused bulk-export implementation.

## Dependencies and deployment boundary

- Apply `2026_09_27_120000_add_asset_profile_custody_history.php` and `2026_09_27_130000_create_asset_label_batches.php` in the normal release process. They have only been applied to isolated verification databases here. The label migration is compatible with PKG-06A's earlier combined register migration: it creates the shared table only if absent and retains export history on rollback.
- Production file uploads require the existing malware scanner configuration. Scanner failure keeps bytes unavailable and offers retry; tests use an explicit controllable scanner double.
- QR printing uses the configured application URL and Branding settings. Physical printer calibration and a real scanner/device check remain operational acceptance steps.
- PKG-06A's focused label controller, exporter, model and PDF view were integrated and adapted in this checkout. Its unrelated Inventory and Stocktake UI changes remain in its own worktree. The shared `/fleet-assets/asset-register/labels` API and table must have one final owner when the branches are combined.
- Maintenance policy, approval/routing, release authority and financial disposal remain owned by their existing modules. No synthetic approvals or policy defaults were introduced.

## Verification

All backend verification uses `phpunit.pkg06b.xml`, whose database base is `oblivion_findings_pkg06b_8821_test`, suffixed by the owning PID and cleaned up at process exit. The browser fixture is synthetic and bound to loopback only. No normal application environment file is used. The local implementation preview is `http://127.0.0.1:8905/__pkg06b/sign-in`; its response identifies `8821-implementation`. Keep the fixture owner and server alive while reviewing it.

- Initial backend suite: 5 tests / 58 assertions passed, with an independently created PID-suffixed schema.
- Backend regression: 26 tests / 520 assertions passed across the Asset Profile, legacy document routes and existing Vehicle Finance suites. Coverage includes site/object denial, stale and duplicate writes, kit receipt placement, source permissions, scanning/version/archive, actual QR PDF output and application-only schema lookup.
- Additional isolated fixture verification: 10 checks passed, covering linked component placement/history, retirement dependencies, legacy photo restrictions and three real PDF formats. See `fixture-regression.json`.
- UI suite: 10 tests passed across the file viewer, receipt, document and Finance request dialogs, including explicit kit acknowledgement, exact-payload uncertain-save retry and unavailable scanner results.
- Shared Finance navigation: 9 tests passed. The focused asset Finance integration test also passed after the final source-label adjustment. A separate broad rerun exited before producing a test result; it is not included in the successful count above.
- TypeScript: no diagnostics in the changed files. Repository-wide checking still reports six existing `getByRole({ exact })` typing errors in `fleet-workspace-navigation.test.tsx`.
- Final production build passed in 6m 9s; ESLint, PHP formatting and `git diff --check` passed for the touched implementation. The existing large-chunk advisory remains. The final build was reloaded in the browser and the corrected Finance modal/source link rechecked.
- `fixture-test-bootstrap.php` optionally reuses the explicitly identified synthetic browser schema for further transaction-rolled-back regression tests. It validates the fixture identity and never creates, migrates or drops a schema. The fixture owner remains responsible for cleanup.
- Browser: real two-page PDF rendering, next page, zoom, extracted text, image viewing and original download passed. The downloaded manual's SHA-256 is `f622b50181eb97fefaa7ba126c147cdee9a0278140af5abdc1d1d9289beade9c`, matching its original bytes.
- Browser: dispatched the synthetic kit, explicitly acknowledged both items and received it at Rimu House. Confirmed actual placement/history, observation draft-discard protection and separate location sources. At 390 × 844, document width matched viewport width without horizontal overflow (`mobile-custody.png`).
- Browser: uploaded a real synthetic PDF. With the scanner intentionally unconfigured, it remained unavailable, exposed no download, and retained one record after a file-check retry. The first test attempt used an incorrect local path and was discarded; that failed attempt is not counted as successful upload verification.
- Browser: submitted `FRQ-2026-0001` through the two-step request, saw it in the canonical Finance queue, verified that the requester could not decide it, and followed its source link back to the Asset Profile (`finance-review.png`, `finance-section.png`).
- QR output: rendered and decoded all 22 labels across A4 18-up, A4 offset 17 with page rollover, and custom 60 × 50 mm pages. Dimensions, company logo and quiet zone were inspected. See `label-verification.json` and the adjacent PDF/PNG artifacts. Physical printing remains unverified.
- Browser QR download: downloaded `asset-1-labels.pdf` through the viewer and inspected its actual 20,034 bytes: one A4 page with `AS-104` in the PDF content.
- Frozen v9 manifest: all 42 recorded files retained their original hashes.
