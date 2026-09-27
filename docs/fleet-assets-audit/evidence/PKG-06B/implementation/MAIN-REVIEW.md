# PKG-06B exact-candidate review packet

Request: Stephan asked this Designer to notify Main and get the Asset Profile committed/merged to local main and GitHub main. Main independently verified and recorded that authorization in `PKG-06B-main-integration-intake.md` in its checkout. This packet requests substantive technical review and the serial integration slot; it does not claim either has been granted.

Branch/worktree: `codex/asset-profile-implementation`, `C:/Users/steph/.codex/worktrees/8821/oblivionfindings`. Current-main base included: `a6b9fae6a73516548b02e64c41cf1fc94ce43f01`. Verified runtime source is frozen in `0d86dd517816ed2a990f3c767de2000816725dfb` (includes the Transport runtime integration at `49e0be5b1`). The final packet commit adds only this handoff, scoped lint packaging and its verification; the exact final tip is supplied in the Main message. No branch rewrite, main write, remote push, deployment or live migration has been performed by this Designer.

## Review scope

- Full Asset Profile with seven views and the approved mockup hierarchy, source-owned Finance/checks/Maintenance/ownership data, custody/kit/history, and protected scan observations.
- Shared real-file viewer, original downloads, protected versioned/scanned documents, upload failure/retry and source-owned attachment handling.
- Stable branded individual and bulk QR labels, A4/custom media, PDF and PNG/SVG ZIP exports with manifests, expiry, history and current-access checks.
- Approved shared date controls in the editor, optional clearing and bounded mobile placement. Current main's custom calendar trigger, command retry body preservation, search callbacks, wizard options and navigation remain intact.
- Current main's canonical assignment receipts are exposed through an explicit attestation/review dialog, with source permission/privacy checks. Its overview assignment link opens Current custody. Assignment alone is not presented as confirmed receipt, and the canonical current assignment is used beyond bounded history.
- A stale inherited Transport test payload now supplies its canonical client identity and additionally checks that omission is rejected. No production Transport access check changed.

## Verification and limits

- 49 Asset Profile/Finance/documents/overview backend tests / 841 assertions passed on the reconciled runtime source in the fresh isolated database run. That combined run also found the stale Transport fixture. After correcting only the fixture, the full 14-test Transport suite / 619 assertions passed in rollback transactions on the explicitly identified synthetic fixture. This is 63 distinct passing tests / 1,460 assertions across these runs, not a claim that the original combined log was green. The failure, diagnosis and rerun are retained in `PUBLICATION.md`, `current-main-backend.xml`, `transport-allocation-rerun.log` and `current-main-transport.xml`.
- 57 UI tests in 10 files passed, including receipt attestation, current assignment outside history, shared date controls, private files, Finance, navigation, retry and Transport calendar/model checks (`current-main-ui.log`).
- Repository-wide TypeScript, focused ESLint and PHP formatting passed. Production build passed in 6m 4s with only the existing chunk-size advisory (`current-main-*` logs).
- Real Laravel/Inertia browser at loopback port 8905 loaded `app-FMwTQuLf.js`. The assignment deep link, required attestation, review, synthetic backend save and persisted receipt were verified. Desktop/mobile calendar screenshots and console evidence are in `current-main-browser.json` and `current-main-*.png`. Mobile calendar occupied x=16–374 inside 390 px, with reachable actions; viewport was restored. Earlier document, Finance and actual QR PDF/ZIP decode evidence remains in this directory.
- All nine frozen manifests: 371 recorded file hashes match Git blobs; all 42 v9 entries unchanged. Credentials and local runtime configuration are excluded. `verify-publication.py` and `current-main-frozen.json` record this. Scoped lint exclusions cover only frozen PKG-06B references; production pages/components/tests remain linted (`lint-scope.json`). Main's lint-only Transport packaging commit is included.

## Ownership and gates

Read `PUBLICATION.md` for the exact shared label API/model/exporter/view contract and fallback migration disposition. The fallback is applied only in the owned synthetic fixture, with three existing label histories retained. The sibling PKG-06A register migration is absent/unapplied here. PKG-06A's under-review branch is not imported or treated as approved. Its Designer has been notified of the migration/application-order and minimum-label-size overlap. Resolve one canonical shared label implementation when assigning the serial integration order; do not drop history or replace whole shared files with older variants.

`RULE-AUTHORITY.md` quotes the exact later user instructions for the focused file-preview guide addition, full-profile implementation and date conformance correction. The guide diff stays bounded; no unrelated Rory rule rewrite or mobile/date rule waiver is claimed.

Main's dirty programme records remain untouched. PKG-03 is local-only and must not be swept into GitHub publication. Recheck main/origin ancestry and the protected-file inventory when the slot is issued. The same Designer retains integration execution after exact-source approval; Main verifies publication. Production configuration/migrations, scanner activation, deployment, physical printer/scanner calibration and operational acceptance remain separate release steps.
