# Assets register implementation

User approved implementation of the frozen v9 design on 27 September 2026.
Branch: `codex/assets-register-implementation`. Frozen previews remain unchanged.

Current integration source: `c962e0db1e3b14ed56777d4cc678911b6daf5787`, reconciled with published main `49e0be5b1c1716aeb4e681529bb71fdce2a7abc0`. It includes the three corrections requested by Main: retained history authorization references, canonical room reconciliation when moving an asset, and worker-zone audit timestamps. The fresh integrated run passes 74 backend/infrastructure tests (936 assertions), 38 frontend tests, full TypeScript and scoped lint. The timestamp tests also pass in separate UTC, New York and Auckland processes. See [revision 2](implementation/PKG-06A/REVISION-2.md) for current exact-source evidence; the [original review packet](implementation/PKG-06A/REVIEW-PACKET.md) remains historical. Phone/device testing is explicitly deferred by Stephan. The PKG-06A Designer retains integration execution after Main records approval and allocates the serial slot.

## Delivery checklist

- [x] Canonical register: searchable assigned site/room selectors, list/cards, attention/archive, retained selection, canonical ownership filters and room assignment on creation.
- [x] Durable stocktakes: scoped snapshot, drafts/resume, version conflicts, scan-first modal, explicit exceptions, review/completion and immutable history.
- [x] QR reading: keyboard/printed tag, camera and image; authorised stable identity resolution; duplicate protection and undo; no implicit custody writes.
- [x] Completed reports: Vehicle Trip branding, PDF and genuine XLSX, results/follow-ups/activity and current permission checks.
- [x] Imports: CSV mapping/validation, row selection, durable results, partial retry and canonical placement/identity checks.
- [x] QR label batches: stable tokens, shared branding, millimetre layout, PDF and PNG/SVG ZIP, bounded selection, history/retry/access checks.
- [x] Verification: isolated MySQL feature tests, frontend regression tests and browser workflows on this checkout. See qualifications below.

## Boundaries

One organisation, approved sites, canonical Asset and SiteRoom identities. Existing roles and permissions remain the access boundary. Stocktake recording uses `assets.scan.record`; import uses `assets.create`; reading/export requires current Asset visibility. Counts are observations and do not transfer assets or alter ownership. Completed evidence is immutable. No production migration or deployment is authorised by this implementation task.

## Initial implementation verification (historical)

The results below describe the original implementation pass. Current integrated results supersede its test totals and old TypeScript failures; see revision 2 above.

- The new workflow suite passes **14 tests / 140 assertions**. Coverage includes version conflicts, request/command replay, immutable completion, canonical room boundaries, hidden-site denial, revoked asset visibility, printed tags and QR origins, duplicate/undo behaviour, empty-room attestation, genuine PDF/XLSX output, partial import recovery and stable label identity. The visual alignment follow-up adds authorised site/room totals, setup checklist previews, coverage exclusions for selected-item counts, and access rechecks for follow-up summaries.
- The broader run also passed **34 existing AssetController and AssetMutationBoundary tests**. Its two failures were invalid test fixtures moving an asset to a different site while retaining its original room; clearing the room fixed the fixtures, and the complete new suite passed on rerun.
- **10 frontend regression tests** cover recoverable save failures, command identity, QR resolution, explicit off-list confirmation, read-only exports, session/download errors, accurate unfinished-count guidance, closing a linked count before refreshing its hub, and clearing stale rows when switching hub sections.
- **4 infrastructure tests / 13 assertions** cover the test bootstrap and database-scoped schema cache. `SchemaCache` now enumerates the current database only, avoiding unrelated schema locks.
- Targeted ESLint, Pint and `git diff --check` pass. Full TypeScript checking reports six existing `getByRole(..., { exact: ... })` errors in `fleet-workspace-navigation.test.tsx`; the changed application files are clean.
- The production Vite build passes. It retains the repository's existing large-chunk warnings.
- Browser verification used normal authentication against a separate synthetic MySQL database at `http://127.0.0.1:8774/fleet-assets/assets`. It covered 100-site search, canonical room selection, new/resumed counts, typed tags, actual QR-image decoding, duplicates, an off-list asset, undo, persistence across reload, review validation, completion, PDF/Excel controls, a valid-plus-duplicate CSV import and PDF/ZIP label generation history.
- Actual completed-report PDF and XLSX downloads were opened with PDF/OOXML readers. PDF pages were rendered and visually checked; PNG and SVG labels decoded to the canonical stable asset URL. Label generation endpoints also passed feature tests. The in-app browser's blob-download event capture was inconsistent, so it is not used as the export correctness assertion.
- All **368 frozen artifacts** checked across v1–v9 match their manifests. Two active v9 server log files were excluded because they remain open and mutable. Frozen preview sources, builds and review evidence were not edited.

## Local preview and rollout

The preview uses only synthetic users, 100 synthetic sites and sample asset/count/import/label records. Its server is bound to loopback, uses this checkout's built application, and keeps normal authentication and authorization. Local helpers, schema snapshots, test reports and rendered QA files are under ignored `storage/app/register-verification` and `storage/logs` paths.

The workflow migration is `2026_09_27_120000_create_asset_register_workflows.php`; it creates stocktake, stocktake-reference, import and label-batch tables. The data-only `2026_09_27_140000_retain_stocktake_history_asset_references.php` backfills canonical asset references retained in older count activity. Both are required on activation. The npm lockfile adds `jsqr` 1.4.0. This branch has not been deployed and no production data or schema has been changed.

Camera access requires the browser's usual permission and a secure context (localhost is supported). Camera hardware, a physical USB scanner and physical label printing have not been tested here; the image decoder and keyboard/scanner input path have been tested. Follow-up owners and review notes are saved as stocktake evidence; completion does not automatically create a maintenance job or change an asset's safe-use status.

## Integration with PKG-06B

Both bulk labels and the individual asset profile use `assets.qr.redirect` and the Asset's existing `qr_token`; regenerating labels never rotates an existing token. Branding comes from `VehicleTripReportExporter::branding()` and its existing settings. This implementation changes register index/create behavior and the create-only canonical room field. Main's T06A-02 correction also updates the shared canonical Asset update path to reconcile a room when the authoritative site changes; PKG-06B must retain that correction when combining profile edits. Reconcile the shared `SchemaCache` one-line fix when combining the branches. No unapproved PKG-06B implementation has been imported. The existing coordinated order remains PKG-06A first, followed by removal/reconciliation of PKG-06B's unapplied fallback label-table migration; the current candidate does not claim order-independent label migrations.

## Visual alignment correction

The first application pass did not carry over the approved v9 composition. The correction restores the bounded left-hand assigned-location navigator, searchable paginated site directory, five pinned and three recent shortcuts, room navigation, search and compact filters inside the shared hero, and the adjacent inventory results. Shortcuts are optional local preferences keyed to the signed-in user and filtered against the current permitted sites. Site and room totals are calculated through the canonical authorised asset query, before result filters.

Stocktake now uses the approved Counts / Follow-ups / Site coverage hub with the saved-count resume panel, list/cards within a single surface, and the primary new-count action in the hero. The setup modal restores the location card, real checklist preview and optional-details disclosure. Coverage is historical, excludes selected-asset counts, and intersects recorded rooms with current canonical rooms. Follow-ups retain the completed count's evidence; they do not claim that work in another module is still open. Summary reads stream completed snapshots and retain only the requested follow-up page in memory.

The real Inventory columns show recorded status, ownership and dated inspections; they do not invent the mockup's illustrative condition, custody or verification data. Appearance continues to use the existing light/dark theme and brand tokens. No global appearance preference was changed as part of the code correction.

Browser checks cover the 100-site directory and pinning, restored hub navigation, follow-up details, historical room coverage, setup checklist loading and the mobile scan modal. Phone-width Inventory renders cards rather than a squeezed desktop table. Header clipping during keyboard navigation is fixed locally with non-scrolling overflow clipping. Desktop and mobile visual evidence is stored under `storage/app/register-verification/visual-*.png`.
