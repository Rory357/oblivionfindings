# Published Assets Register reconciliation

Main independently verified the four user instructions quoted in RULE-AUTHORITY.md and the bounded 99-line additive guide delta. This resolves the authority-mapping intake question; it is not technical approval or a general permission to alter Rory's rules.

Approved/published Fleet base `79ea01a561f7d2fa5affa592dc096f516d663635` was cleanly reconciled in `19061001b3d918d7158891c121fa5a831d7f85c0`. Main's canonical Transport fixture correction is identical to the earlier PKG-06B integration correction and occurs once in the resulting tree. Shared page-header accessibility and Fleet navigation/calendar/source changes are retained.

Published dependency: PKG-06A `b05702d7208c88d9cffb9c092df23d3b02300b1e`, independently verified by Main on local/GitHub main. This includes the published Fleet and Transport source. No unapproved PKG-07 or local-only PKG-03 source was imported. The earlier `e364c8186` review packet remains historical evidence only.

The eight overlapping files were reconciled by function. The register index is byte-identical to the published version: inventory, attention, stocktakes, import workflow, selection and label history are retained. The editor combines the register's canonical create-room payload with the profile's optimistic version and shared calendar inputs. Controller imports and routes retain both sets of functionality, with exactly one label API/model/exporter/PDF view.

## Shared QR contract

- Register and profile use `/fleet-assets/asset-register/labels` and `asset_label_batches`; no parallel exporter, batch identity or table was added.
- New A4/custom labels require at least 50 × 46 mm. The register default is 60 × 50 mm, with its larger A4 preset retained and a custom printer preset added. Both support the logo from Branding settings.
- Old saved A4 layouts default to paper `a4` and logo enabled. Their original request/layout can be retried and downloaded, including the earlier 60 × 45 mm preset. Changed payloads conflict; new undersized batches are rejected. Expiry, owner, current asset visibility and missing-token update authority are rechecked. Existing QR tokens remain stable.
- Limits remain 200 assets, 20 copies per asset and 1,000 labels per batch. Custom media uses one label per page; A4 keeps margins, gaps and skipped positions. PNG/SVG ZIPs and manifests use the same asset identities.

## Migration and retained history

PKG-06A's `2026_09_27_120000_create_asset_register_workflows` is now the sole label-schema definition. The unpublished profile fallback `2026_09_27_130000_create_asset_label_batches` remains as a no-op compatibility marker, retaining the identity of already-recorded isolated-preview migrations. In the normal 06A-first activation sequence, the canonical migration creates the table and the marker does nothing.

The canonical migration's label creation is guarded for an existing compatible fallback table. Its rollback retains shared label histories instead of deleting them. Other register tables keep their published definitions. The `140000_retain_stocktake_history_asset_references` correction is unchanged; deleted asset identities continue to fail closed for stocktake visibility.

Reverse-order adoption was executed only in the owned synthetic preview database `oblivion_findings_pkg06b_8821_test_36804`. Its three complete label rows/history records matched SHA-256 `a0d272f2f1dd076ad1be170530157da855febd115531b8c85e5f26161179925c` before and after register activation. The fallback's original batch-3 migration record remains; canonical register and stocktake correction were recorded as batches 7 and 8. See `combined-migration-adoption.json`. No operational migration, schema repair or history deletion was performed.

## Placement protection

The register's canonical client/site/room guards remain in place. Pending-custody protection now runs after canonical client placement is resolved and includes `site_room_id`. Actual receipt locks and revalidates the destination room against the destination site, since a room can change after dispatch. Failed receipt leaves the movement pending and the asset in its original placement. The same atomic placement is used for linked kit components.

All 371 frozen v1–v9 entries remain unchanged. See `MAIN-REVIEW.md` for the final exact source and combined QA; earlier checks are not claimed as verification of the combined candidate.

Main writes and GitHub push remain held for substantive approval and the serial integration slot. Existing user publication authority remains valid; no new user confirmation or worker is needed. Main's dirty programme files and local-only PKG-03 must remain outside publication.
