# PKG-06A — Main integration workflow intake

Owner: MAIN ASTRA. Revision: 1. Updated: 2026-09-27.

Status: **publication requested; existing owner preparing exact source for Main technical review**.

## Verified user direction

Owner task `01a0dd46-9982-7721-8534-97fc3fbb6f24` requested Main to begin the review/integration workflow. Main independently read the actual user message `01a0e0f9-1888-7120-ae32-a705ee3bb54e` in turn `01a0e0f9-1614-7bd3-85d1-f180a2ec1a52`: phone testing is not needed now, publish to local main and GitHub main, and notify Main to start. Phone/device testing is therefore deferred, not an approval blocker. The optional automatic Maintenance-task follow-up is outside this handoff.

## Observed candidate and evidence

Main read the checkout at `C:/Users/steph/.codex/worktrees/6421/oblivionfindings`, branch `codex/assets-register-implementation`, HEAD `4ea64c547ed85a5b7504e59599db351f6eba7deb`. Application changes and new controllers/models/services/frontend/tests/migration are still uncommitted. The untracked frozen v1–v9 preview/page/evidence directories are preserved; no broad staging or source transfer was performed by Main.

Main read `docs/fleet-assets-audit/PKG-06A-implementation.md` in that checkout, intake SHA256 `2FC314D469DBD94820F5D531748854DBC9AB150A6CBDEBB7E5C6369E84318163`. It describes the site/room Inventory register, durable stocktakes and explicit exception evidence, stable QR scanning/labels, PDF/XLSX output and durable CSV import/retry. It reports14 workflow tests/140 assertions,10 UI tests,4 infrastructure tests/13 assertions, a passing build and targeted checks. It also records six existing navigation-test TypeScript diagnostics and unverified camera hardware/USB scanner/physical printing. Main has not rerun these tests or claimed exact-code approval.

The owner reports an authenticated built preview at `http://127.0.0.1:8774/fleet-assets/assets` using a separate synthetic database. Current essential logs/screenshots are ignored under storage; the preparation request requires durable, non-sensitive copies with provenance. Main did not operate the preview or modify any database during intake.

## Preparation and serial integration

Main sent one consolidated preparation message to the existing owner under the approved §14F workflow. That chat is now active on turn `01a0e0fa-f9f8-7b22-8b74-d0d74257685d`, confirmed with a bounded status snapshot. The owner retains sole application custody and prepares an explicit scoped commit, reconciles published main, packages evidence and submits the exact commit/base with application writes frozen. Main retains technical review; publication requires its recorded Approved for integration and a serial publication slot. No new implementer or routine cross-chat loop was started.

Main's observed local HEAD and origin/main reference at intake are both `ba5bff2e8b6c22796369443f1cdac918039950dd`, including the newer Overview presentation and prior Vehicle Profile/export fixes. This is a local observation, not a new remote verification or a Main checkout update. Preserve all dirty Main programme files and public/.user.ini.

Reconciliation targets include routes, AssetController/register/create dialog, shared WizardShell, package/jsqr lockfile, TestCase timeout, SchemaCache database scope and VehicleTripReportExporter branding/precision. PKG-06B's reported shared label controller/model/exporter/view, proposed AssetQrLabelService and conditional migration need a compatible single table/service contract while retaining existing Asset qr_token. Read sibling code for dependency understanding only; do not silently import unapproved uncommitted work or create competing label tables. This intake does not approve PKG-06B.

PKG-04 is preparing a separate review candidate and PKG-05 has Changes requested. No package may treat another package's review or a completed test run as integration approval. Main performs no Git merge/push at this stage. Production deployment, operational database migration and final user package acceptance are not claimed.
