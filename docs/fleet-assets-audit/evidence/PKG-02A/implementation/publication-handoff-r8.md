# Client Location — bounded publication handoff r8

21 September 2026. Prepared by the sole Client Location implementation writer for MAIN's final technical gate. **Not yet approved for integration; no commit, merge, push, deployment or operational migration has occurred.**

## Source identity and authority

Branch `codex/pkg-02a-client-location-design`; base and HEAD `2302ca33a95616e442a78e957ddce82d8db98669`. Fresh `git ls-remote` and GitHub branch API both returned that exact remote `main` SHA during this review. There are no incoming commits to reconcile. GitHub reports `protected: false`; the branch-rules endpoint returned an empty list. No active local Git hook or `core.hooksPath` override was found. Recheck remote state immediately before publication and never force-push.

Frozen r7 manifest: `source-build-manifest-r7.json`, SHA256 `1715A78D9310302252EA84DC00834F2F7C4738A795DBF1AB93327A0180EC2C7D`, 124 application/test/config entries. r8 changes only test/support isolation. The patch and before/after SHA256 inventory are `test-isolation-r8.patch` and `test-isolation-r8-changes.json`: all eleven original test files were reconstructed and verified against r7 hashes before diff generation; one support base is added. Application behavior, shared `tests/TestCase.php`, `phpunit.xml`, dependencies and lockfiles remain unchanged.

Actual user authority, independently recovered by MAIN:

- Implement reviewed v3 without Sol: message `01a0c099-b873-7ed2-a30e-9b586e278d31`, recorded in the approved implementation contract.
- Safe zones to Control Room: “can you do that now it should go to control room”, message `01a0c204-953d-74b2-ba9c-fabf68b5c77e`.
- Finish the stated client Location mode/fall gaps: “ok before you stop again complete please”, message `01a0c237-f817-7131-99ff-c90f7b4eb1db`, after the specific remaining-feature list.
- Current MAIN request: “since the designer is done now can you merge and push to main and then start the new designer please”. Publication is authorized; MAIN's technical release is pending. MAIN owns the one subsequent design-only task.

Mobile verification is supplementary robustness evidence. It is not a separate mobile scope approval or a substitute for desktop acceptance.

## Implemented scope

- Full-width client Location workspace, grayscale map tiles, recorded/nearest address above smaller coordinates with truthful unavailable fallbacks; private reverse lookup remains off by default.
- Visible and right-click/Shift+F10 map actions; typed address search; polygon/circle/rectangle drawing, whole-shape and handle movement, keyboard adjustments, Undo/Redo; validated four-step zone definition and Auckland schedules/exceptions; immutable private draft revisions.
- Current client, care/site, consent, collection and exact assignment checks on reads and writes, including stale-response clearing and independently current authorization reads.
- Locate now uses the canonical signed command, current client origin, identity/approval/idempotency, serial native delivery and observation provenance. Acceptance/acknowledgement is separate from a measured location.
- Uniform motion/fall, SOS and animated charging/battery evidence; live page refresh reads received observations and does not poll a device.
- Separate Standard/Live tracking/Power saving requests for supported, properly configured GL30-family trackers. Exact immutable profiles, approved change, independent approval, real identity confirmation and verified configuration readback remain required. Standard reports every30seconds, Live every10seconds, Power saving every120seconds. Power saving retains GNSS/SOS; Live does not auto-expire.
- Explicit zone activation/pause against an immutable revision, schedule-aware qualified telemetry, uncertainty and episode deduplication, durable existing signal/outbox and revalidated Control Room publication. Critical explicit fall events use the same existing response platform and do not require GPS. Neither motion nor man-down is guessed to be a fall.

Current source and behavior details are in `client-tracker-modes-and-falls.md` and `control-room-zone-monitoring.md`. Earlier draft-only records are historical, not current scope limitations.

## Still open — not whole-package acceptance

The approved implementation contract also includes work that this candidate does **not** implement:

- Client-scoped FleetOuting planning/membership adapters, responsible support worker, planned arrival and departure/arrival/return confirmations. No additive outing fields or new outing mutations are present. Non-vehicle planning is not blocked by Maintenance configuration; it remains unfinished approved scope. Vehicle-backed writes must retain canonical Maintenance eligibility and restriction checks. Operational overdue/arrival evaluation additionally needs its approved thresholds/response policy.
- Combined Activity stream/filter for plans, attributable Control Room responses and outings. The current Activity view renders canonical location history with From/To ranges, retention/authority constraints and governed export, not that combined stream.
- New named-recipient sharing and client self-access/disclosure rules remain dependent on an actual approved privacy-owner contract covering audience, purpose, authority, duration, review and channels. Existing relationships or collection consent are not treated as a new sharing grant.
- Operational configuration, deployment, physical tracker acceptance and authenticated normalization for any future fall-detection model remain pending. A Git push does not perform or establish them.

MAIN may release this bounded implementation for publication while preserving these open items. Do not label all PKG-02A approved scope complete.

## T01 portable test isolation

The five worktree-specific database-prefix checks now compare the exact schema generated by `Tests\TestCase`, its process token, configured connection and live PDO `SELECT DATABASE()`. Real independent PDO, zero transaction depth, lock timeout, stale snapshot, audit hash/signature and rollback assertions remain.

`CommittedDatabaseTestCase` snapshots bounded pre-test rows only inside that verified disposable process schema, closes test-owned transactions, and restores only tables whose rows changed. It preserves pre-existing/seeded rows, their IDs/relationships and foreign-key state, then asserts restoration. Auto-increment counters may advance normally, as they do in ordinary transactional tests; no counter reset or statistics-expiry changes remain. It uses no production connection, new schema naming convention, CI skip or excluded proof. The original global test harness and operational database are untouched. Cleanup exceptions are reported as non-assertion exceptions so PHPUnit retains the original test failure.

The committed base covers concurrency/address/cache proofs, actual DDL migration tests and Locate delivery's zero-depth atomic rollback proof. Ordinary Locate request tests now declare `RefreshDatabase` explicitly because Pest's directory declaration does not apply to a PHPUnit class. Draft rollback follows monitoring-table → draft-tables order, then reinstalls parents before monitors, with foreign-key enforcement intact. Existing populated-evidence rollback denials remain.

Final corrected T01 validation passed: **75 tests /2,168 assertions, exit0**, in one process with all committed proof families, Locate request/delivery/migration, mode/fall and downstream approval/batch/break-glass count/sole checks. No test assertions were narrowed to conceal leftover records. MAIN's final technical disposition remains separate.

## Validation and desktop evidence

All paths below are relative to this worktree; raw logs are local evidence and excluded from publication.

- `.pkg02a-publication-frontend-verified.log`: 60 tests /14files passed, exit0, on unchanged r7 application source. Initial `.pkg02a-publication-frontend.log` failed at esbuild startup because of sandbox access to the existing dependency junction; it did not run tests.
- `.pkg02a-publication-eslint.log`: all source-manifest TypeScript/TSX files passed, max-warnings0, exit0.
- `.pkg02a-publication-types.log`: full TypeScript rerun passed, exit0; the earlier `.pkg02a-complete-types-final.log` also passed on identical application source.
- `.pkg02a-complete-build.log`: successful production build4m46s; existing >500kB advisory. Installed manifest SHA256 `1DA452F3818931BFF67ABCDFC66ECBDF0D1FF89CFCAE29361EA1106B67A0060A`. The final client asset is `show-De9Pya9y.js`. Builds/dependencies are not publication payload.
- `.pkg02a-publication-unit-feature.log`: first combined49tests/1514assertions,3errors/1failure, exit2. All committed proof families passed; migration dependency and plain-PHPUnit Locate fixture leakage were exposed. This is retained failed evidence, not a green claim.
- `.pkg02a-publication-features.log`: historical Feature run loaded pre-correction test classes; 153 tests /1,601 assertions,10 errors/15 failures, exit2. It confirms cascading leaked-fixture failures and is not accepted as final corrected-source evidence.
- `.pkg02a-publication-final.log`: **216 tests /3,541 assertions**,1error/1failure. Runner exit2 is recorded in `.pkg02a-publication-final-exit.txt`; the outer tool process reported exit1. Exactly two intermediate test-support issues failed: the newly added raw-counter assertion and Delivery's enclosing transaction. All other cases passed, including current consent, native Queclink, Control Room provenance and signal atomicity. This is not described as a green whole run.
- `.pkg02a-publication-corrected.log`: **75 tests /2,168 assertions passed**, runner/tool exit0,5m09s. Covers all final committed-support families plus Locate request/delivery/migration, mode/fall and downstream approval/batch/break-glass in a single process. Fresh preflight found no leftover package schemas. The existing absent-`.env` bootstrap warnings remain explicit; no warnings are represented as failures or hidden.
- `.pkg02a-publication-pint-verified.log`: final test/support formatting passed, exit0. `git diff --check` passed.

Source mapping: all application code in both backend runs is byte-identical to r7. The full216-test run loaded the intermediate counter machinery and Delivery's temporary RefreshDatabase trait. Those are the only subsequent behavior changes in the test support; the final75-test run covers both corrections and their real suite-order effects. Unchanged native provider, consent, Control Room and other passing Feature cases are reused from the full run. Counts from the two runs are not added together.

MAIN initially requested exact auto-increment metadata restoration in the support helper. Its new implementation-only assertion failed on the first bootstrap. MAIN explicitly corrected that requirement: existing row IDs and valid allocation must be preserved, but raw counters need not return to their old value. The unnecessary counter-reset DDL and assertion were removed. The failed diagnostic is retained; no product behavior assertion was weakened.

Actual guarded preview at `http://127.0.0.1:4335/operations/clients/1?tab=location`:

- 1440×900 desktop: Enter opened Tracker mode; dialog504×619px, documentwidth1440. Tab cycled through both Close controls within the modal. Escape closed after the transition and returned focus to Manage tracker mode.
- Resize while the dialog remained open to1024×768: documentwidth1024, dialog504×619px, top90/bottom710, fully within the viewport. Main separately checked1280×720 with no horizontal overflow.
- Shift+F10 on the map opened Map actions. Enter on Draw a zone here opened the real editor. Start rectangle and the whole-zone handle were keyboard operated; ArrowRight moved Corner1 x315→323px and Undo restored x315exactly. Unsaved test changes were discarded; nothing was saved/activated. Viewport was reset.
- No captured browser console errors. Earlier grayscale checks remain valid on unchanged source. Existing synthetic Control Room zone/fall alerts CR-2026-0001/0002 remain evidence only; no real notifications or tracker commands were sent.
- Actual browser zoom remains unverified by the automation surface. Control+plus did not change innerWidth/devicePixelRatio; IAB exposes only viewport/visibility. MAIN owns the bounded manual200% check. No viewport resize is described as real browser zoom.

The synthetic manual tracker intentionally has no supported native hardware/control permission; unavailable mode choices are truthful. Positive command lifecycle/readback behavior is covered by automated tests, not asserted as physical-device browser acceptance.

## Audit-tail, provider cache and rollout constraints

Audit-tail remedy is implemented, superseding the earlier proposal-only status: append locks the exact request primary key, current-reads its exact event primary key, inserts with unchanged hash bytes and atomically writes the hidden internal tail pointer. Missing/foreign pointers fail closed; lifecycle/signature fields and caller model remain unchanged. Collector recovery now locks parent before attempt and revalidates after discovery. The original index-only/range-gap diagnostic failures remain preserved. Writer inventory and prior isolated interleaving proofs are in `status-and-audit-checkpoint.md`.

Migration000004 requires **quiesce and drain all governed command/audit writers**, additive migration/backfill of MAX(eventid) and pointer verification, deployment of every corrected writer, then resume. Mixed old/new writers are unsupported because old appends leave stale pointers. No operational quiescence/backfill was performed. Rollback retains pointer/evidence; down refuses when audit events exist. Re-upgrade after older code requires quiesced pointer rebuild/verification.

Migration000001 adds draft rules/versions;000002 adds nullable signed client origin while retaining generic signing compatibility;000003 records governed Queclink provenance;000005 adds monitoring evidence. Reverse dependency order matters. Evidence-bearing down migrations refuse destructive rollback. Do not run these against operational data as part of Git publication.

Typed address search now explicitly uses a shared database/Redis store from `ADDRESS_SEARCH_CACHE_STORE` (defaultdatabase), independent of the application's global cache. Endpoint-scoped result keys and one provider-wide lock/start-spacing budget are shared with Site search, including NZ/global fallback. Array/local stores fail closed before provider calls. Actual independent database PDO/cache-lock proof is included. All app instances must use the same cache/lock backend; database deployments need cache/cache_locks tables. Typed public queries carry no client/tracker identity or measured coordinate context. Private reverse geocoding remains disabled until an approved organisation-controlled provider is configured.

Existing deployments publish the new immutable presets with `QueclinkPresetSeeder`; current device permissions, approved changes, independent approval, native connection/credential configuration and existing durable outbox workers remain prerequisites. Do not run physical mode/poll commands or enable future sensor protocols implicitly during deployment.

## Publication hygiene

Use the explicit final allowlist only. Exclude `.pkg02a-*`, local forced test/vitest configs, credentials/environment files, `vendor`, `node_modules`, runtime storage, raw logs, generated application/preview build directories and synthetic helpers. Protected Rory guides and all103frozen design artifact hashes are recorded separately; frozen build hashes are evidence, not implicit publication files.

MAIN-owned current context documents will be imported only after MAIN confirms their final exact paths/hashes. Do not stage the old frozen v1 page over MAIN's current PKG-02A page. The original page needs an explicit archival mapping approved by MAIN. No unrelated files from Main's dirty checkout may be copied or staged.
