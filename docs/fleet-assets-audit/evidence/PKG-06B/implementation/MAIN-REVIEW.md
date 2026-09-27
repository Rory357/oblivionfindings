# PKG-06B final combined candidate for Main review

Stephan explicitly requested notification to Main and publication to local main/GitHub main. Main independently verified that authority and the four bounded design/implementation instructions in RULE-AUTHORITY.md. This packet requests substantive exact-source review and the serial integration slot; neither is presumed granted.

Branch/worktree: `codex/asset-profile-implementation`, `C:/Users/steph/.codex/worktrees/8821/oblivionfindings`.
Published base: **b05702d7208c88d9cffb9c092df23d3b02300b1e** (PKG-06A, including published Fleet/Transport).
Verified runtime merge: **42aed581844b94bc6c807591b35d81fb8c4581dd**.
The final packet commit adds only implementation evidence/documentation; its exact tip is supplied in the Main handoff. `combined-source-sha256.json` records all **75** changed source/config/design-guide Git blobs against that base. No Main write, remote push, deployment or operational migration has been performed.

## Result and integration scope

- Full Asset Profile with the seven approved views and mockup hierarchy; canonical custody/physical receipt/kit/ownership/history, source-owned Finance and checks/Maintenance, guarded scan observations and optimistic/idempotent actions.
- Real protected, versioned/scanned documents and original downloads through the shared file viewer. Only the previously verified narrow file-preview guide exception changes Rory's rules.
- Shared approved date picker for purchase, warranty and compliance dates; optional clearing and bounded mobile placement. Canonical assignment receipt attestation remains visible in Current custody and the overview assignment deep link.
- Published Assets Register inventory, attention, stocktakes, imports, selection and history retained. Its index, stocktake service and retained-asset-reference migration are unchanged. Main's canonical Transport fixture correction remains exactly once. `combined-register-preservation.json` enumerates unchanged and intentionally reconciled source.
- One shared label controller/API/model/table/exporter/PDF template. Register presets now use the same minimum as Profile (50 × 46 mm; default 60 × 50 mm) and include custom printer media plus Branding logo choice. Old saved 60 × 45 mm A4 requests still replay/download; new undersized batches fail. Changed retry payloads, expiry, current visibility and missing-token update authority remain enforced.
- A visible direct-file download fallback is available after register export preparation, including browsers that block automatic blob downloads. Existing progress/cancel/error/history behavior is retained.
- Canonical placement checks are combined with pending-receipt room protection. Receipt revalidates that its destination room still belongs to the destination site before atomically moving the asset and kit.

## Combined verification

- Fresh isolated backend run: **111 tests, 2,170 assertions, zero errors/failures/skips** (`combined-backend.xml`). Covers Profile (19), Register (22), mutation boundaries, Finance, legacy protected documents, overview, Transport, Fleet all-sites and schema-cache scope. The runner reports **110 warnings** from phpdotenv's suppressed read of the intentionally absent `.env`; `combined-warning-diagnostic.log` identifies that exact source. No normal application environment or database was loaded. The disposable regression database was removed; only the owned synthetic preview remains (`combined-database-cleanup.json`).
- Final UI run: **70 tests in 14 files passed** (`final-ui.log`), including the direct download fallback, A4/custom media, stocktakes, receipt attestation, documents, Finance, shared calendar, navigation and Transport. Repository-wide TypeScript, focused ESLint and PHP formatting passed. Final production build passed in **5m 22s**, with the existing chunk-size advisory (`final-*`, `combined-eslint.log`, `combined-pint.log`).
- The published jsqr 1.4.0 dependency was restored locally from its exact locked archive after npm's local tree installer failed. SHA-512 matches package-lock; no manifest/version change was made for this repair (`combined-dependency-integrity.json`).
- Real Laravel/Inertia loopback preview on port **8905**, source worktree **8821**, final bundle **app-6O9LjipA.js**. Register selected three synthetic assets and created A4 batch 7/custom batch 8; both appear in Profile's same export history. The branded custom PDF opens in the shared viewer as three pages. The profile editor retains its shared calendar; at 390 px its popup occupies x=16–374, y=99–584.5 with reachable actions. Temporary viewport override was reset.
- PDF/ZIP artifacts were saved through the browser's supported file-download API. IAB automatic downloads did not produce a local file; direct page navigation to the attachment was blocked by the browser client. That browser behavior is not represented as a successful automatic download. The final visible direct-file fallback and its real endpoint are verified separately. Physical printing and normal-browser download policy remain operational acceptance checks.
- Actual downloaded artifacts: A4 PDF is **210 × 297 mm / one page / three decoded QR labels**; custom PDF is **60 × 50 mm / three pages / one decoded QR each**. All three PNG QR codes decode to the same URLs as the PDF and archive manifest; all SVGs embed the Branding logo (`combined-label-verification.json`). Rendered A4/custom sheets were visually inspected with legible names/tags, clear quiet zones and no clipping.
- All **371 frozen entries across v1–v9**, including all **42 v9 files**, match their recorded hashes (`combined-frozen.json`). Local credentials/runtime configuration remain excluded. Shared source diff checks pass.

## Migration ownership and release boundary

`BASE-RECONCILIATION.md` gives the full disposition. PKG-06A's 120000 register migration is the sole label schema definition; 130000 is a no-op compatibility marker. Canonical creation adopts an existing compatible fallback table; rollback retains shared label history. Normal activation is 06A first, then the marker. The published 140000 stocktake-reference privacy fix is unchanged.

Reverse-order adoption was exercised only in `oblivion_findings_pkg06b_8821_test_36804`. All **three original label batch rows/history records** remained byte-for-byte identical before/after activation, with identical SHA-256 in `combined-migration-adoption.json`. Browser QA subsequently created batches 7/8 and recorded their downloads, explicitly separate from that migration-preservation proof. No operational migration or history deletion took place.

The same Designer owns any approved serial integration. Preserve Main's dirty programme records and all protected Rory/frozen references. Do not sweep local-only PKG-03 or unapproved PKG-07 into publication. Recheck exact Main/origin ancestry and protected-file inventory when the slot is issued. Deployment, production configuration/migrations/scanner activation and physical printer/scanner calibration are separate release work.
