# PKG-06A Assets register — integration candidate

Prepared on 27 September 2026 in worktree `6421`, branch `codex/assets-register-implementation`.

## Authorization and scope

Stephan explicitly requested publication to local main and GitHub main, and deferred phone testing. Main requested an exact reconciled candidate for its substantive technical review and serial publication slot. This packet does not claim integration approval or publication. Automatic Maintenance task creation remains outside this candidate.

The candidate implements the approved Inventory, Stocktake, Imports and QR-label workflows. The Inventory layout restores the v9 assigned-location sidebar, bounded site directory and shortcuts, hero filters, and adjacent list/cards. Stocktake provides the counts/follow-ups/coverage hub, resumable QR-first modal, explicit exceptions, immutable completed evidence, and branded PDF/XLSX reports. Imports persist mapping, validation and partial recovery. Label exports preserve canonical asset tokens.

Single operating organisation; approved sites, roles, canonical ownership and direct-object denial are the access boundary. No production database changes were made during development or verification.

## Exact source and integration base

- Original implementation base: `4ea64c547ed85a5b7504e59599db351f6eba7deb`.
- Initial implementation commit: `eca233b583622f3a1e974eee8ddbaed1c2465681`.
- Published main fetched for integration: `ba5bff2e8b6c22796369443f1cdac918039950dd`.
- Merge commit: `627fc3d191fbe2e4405e1934ee545f1d7161cb4f`, with the implementation and published main as its actual parents.
- Frozen application source: `556e582cbd43aff875e1640ebc9e32fb9f52f6a0`. Subsequent review commits contain documentation/evidence only unless explicitly recorded otherwise.
- `application-files.txt` is the explicit source/test/package/migration manifest relative to the published main base.

No main branch was modified or pushed by this session. Frozen v1–v9 preview/page/evidence files remain outside the staged application candidate. Main's dirty programme records were not touched.

## Reconciliation decisions

- `AssetController`: preserve main's assignment recipient privacy, current assignment query, receipt evidence and HR permission handling. Candidate changes are register index/create, canonical room selection and register workflow metrics.
- `VehicleTripReportExporter`: retain main's native XLSX, locally rendered maps, driving-review behavior and recorded-seconds precision fix. The candidate's only diff against main is making the existing `branding()` helper public for shared Assets report branding. `VehicleTripWorkbook` is unchanged from main.
- `routes/fleet-assets.php`: preserve main routes/imports and add only the scoped `/asset-register` routes.
- `WizardShell`: main had no intervening change. Optional `sequential` defaults to true, preserving normal wizard behavior; completed report sections opt out of sequential progress. Footer actions can wrap.
- `tests/TestCase.php`: resolve the sole textual merge conflict in favor of main's commented, bounded 300–1800 second timeout. Keep the candidate's MySQL password transport through the process environment rather than command-line arguments.
- `SchemaCache`: keep discovery limited to the current database, with its focused regression test.

The final focused label correction independently enforces replay payload equality, normalizes numeric layout/selection inputs, rejects unrecognized layout keys, and requires the canonical Asset update policy before repairing a missing QR token. Tests cover same-request retries, changed requests and read-only token repair denial. This correction does not import the unapproved PKG-06B workspace or label-printer implementation.

## PKG-06B QR-label ownership plan

Read-only comparison was made with worktree `8821` on 27 September 2026. That worktree is still an independent candidate; its uncommitted code is not an approved dependency of this commit.

1. PKG-06A owns the single `asset_label_batches` table through `2026_09_27_120000_create_asset_register_workflows.php`, its model, the `/fleet-assets/asset-register/labels` JSON/download routes, and the shared bulk label controller/exporter/view in this candidate. Integrate one implementation of each, never duplicate classes or routes.
2. PKG-06B adds a `/labels/workspace` page and custom label-printer media to those same contracts. Main should review and layer those additions onto these files when approving PKG-06B, preserving retry equality, current-visibility download checks, expiry and missing-token mutation authorization. Both packages must continue using `assets.qr.redirect` and the existing `Asset.qr_token`; regenerating labels must not rotate it.
3. PKG-06B currently has `2026_09_27_130000_create_asset_label_batches.php`, which skips creation when the table exists and deliberately has a no-op rollback. If 06A is integrated first, remove that redundant, unapplied fallback from 06B before publication. If it has already been applied anywhere, audit migration history before changing it; do not drop label history. The 06A combined migration assumes it is the owner/first creator.
4. PKG-06B currently uses `AssetQrLabelService::branding()` for its label-specific logo options. 06A shares `VehicleTripReportExporter::branding()` for brand name, colour/tint and logo across labels and stocktake reports. Main should choose one reviewed branding source or an explicit adapter when combining them; do not replace the report helper with a label-only shape that lacks colour/tint. No copy of the unapproved service is added here.

## Verification and evidence

Integrated verification results and reproducible commands are recorded in `VERIFICATION.md`. Only synthetic preview screenshots and sanitized test/build outputs are included. Runtime configuration, credentials, database/schema dumps, server request logs and downloaded records are excluded.

Preview host: `http://127.0.0.1:8774/fleet-assets/assets`, served from worktree `6421` with a separate synthetic MySQL database and normal authentication. The preview uses built assets with no `public/hot` file. The saved dark theme is preserved; this candidate does not silently change global appearance preferences.

## Known limits

- Phone/device testing is explicitly deferred by the user. Physical camera, USB scanner and label-printer hardware remain unverified; keyboard input and QR-image decoding were tested.
- Follow-up owners/notes are saved evidence. This candidate does not automatically create or resolve Maintenance work.
- Coverage is historical room coverage, excludes selected-item counts, and is not a declaration of current custody or safety.
- Production migration, deployment, final integrated smoke test and publication are Main's next steps after its approval and serial integration slot.
