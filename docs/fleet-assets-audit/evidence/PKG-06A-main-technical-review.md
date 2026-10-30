# PKG-06A — Main technical review

Owner: MAIN ASTRA. Revision: 3. Updated: 2026-09-27.

Decision: **Approved for integration — exactb05702d7208c88d9cffb9c092df23d3b02300b1e on published79ea01a561f7d2fa5affa592dc096f516d663635. Same Designer has the exclusive next serial publication slot.**

## Final published-base gate

Main independently inspected the exact final candidate, frozen source `2d88fdd73d272d062ca8d77b7e951ef2ede8c607`, and its parents reviewed05724b477/published79ea01a56. The finalb057 commit is documentation/evidence only; app/test/package/config paths have no subsequent changes. All38 current [approved source hashes](PKG-06A-main-approved-source-hashes.json) match the candidate.37 of38 reviewed Assets files retain their exact prior hashes. Main inspected the sole difference: two already-published Fleet GET endpoints added inside `fleet.viewAny` in `routes/fleet-assets.php`; every reviewed Assets route is retained.

Main independently enumerated all37 app/test/config paths changed by published main since49:36 exactly match the published Git blobs and the sole difference is the same shared route file containing both packages' additions. This preserves the reviewed Fleet calendar contracts, source returns, shared components and Transport canonical-client fixture correction. No03 local-only or unapproved06B source is imported. Application-scoped whitespace checks pass; the owner's only untracked paths remain the three protected frozen PKG-06A trees.

The owner reran affected checks on this combined source: **48 backend tests/1,097 assertions**, **83 frontend tests/14 files**, full TypeScript and strict scoped lint, then a fresh **4m43 production build**. Main inspected the final logs, cleanup/provenance report and actual Inventory/completed-stocktake screenshots. The reported desktop smoke loaded exact`index-CaAGLVia.js`, no hot file, correct completed counts/Auckland times/export controls, accessible search and100-site navigator; no recorded warnings/errors. Main's earlier independent6-test/81-assertion and38-test runs remain explicitly attributed to the unchanged reviewed contracts, not represented as fresh final-source executions. The owner appropriately rebuilt because Fleet changes shared runtime components/CSS outside the38-file Assets manifest. Main did not rerun the full final suite/build/browser or operational migrations.

Main independently verified local and remote main still at exact79ea01a56 with clean application/guide paths. PKG-04's slot is released. **The same06A owner may now ordinary-fast-forward actual main to exactb05702d72 and perform the already-authorised ordinary GitHub push**, taking a fresh protected-file fingerprint, preserving all dirty programme records and unrelated`public/.user.ini`, and returning exact refs/tree/source and integrated checks. No reset/clean/stash/broad staging, source mixing, operational migration/deployment or new user confirmation. Source/base advancement returns for reconciliation. No other owner has a main-write slot.

Both06A migrations remain activation prerequisites; this is source publication, not operating activation.06B must reconcile its single canonical label contract and migration/history/preset handling only after this approved base is published, under its separate technical gate. Phone/hardware checks remain expressly deferred; no Maintenance auto-task or guide change. Hosted CI and final user acceptance are separate.

## Renewed correction review

Reviewed successor `05724b4776384e12bf9f392886da6a7a1a3d5f4d`, frozen source `c962e0db1e3b14ed56777d4cc678911b6daf5787`, on published49e0be5b1. All38 application hashes independently match. The final successor afterc962 is evidence-only; its application/test paths are clean. Main reviewed every corrective source/test delta and the reconciliation with49.

- **T06A-01 closed:** additive references retain current entries, explicit event asset identities and legacy server-owned undo-snapshot identities. Direct reads/writes/exports also union retained table references. The new140000 data-only backfill runs in bounded chunks, is idempotent and preserves audit JSON/version/timestamps; rollback intentionally retains privacy references. Lists/resume/coverage use the reference-backed exclusion. Both package migrations remain activation prerequisites.
- **T06A-02 closed:** the canonical controller resolves the destination site including client placement, preserves a same-site room, clears the old room on a site move without an explicit replacement, and validates an explicit replacement within that destination under lock. Existing placement/status/device guards and the composite constraint remain. Audit before/after includes room. The edit wizard explains the behaviour.
- **T06A-03 closed:** `stamp()` uses the shared Auckland/en-NZ long-date helper, with the existing missing/invalid fallback. The actual PDF template has matching worker-zone time.

Main independently ran the six corrective backend regressions through [an isolation-only subclass](PKG06AMainRegressionTest.php): **6 tests,81 assertions passed**,1m47.032s. They cover retained undone-extra history after movement/revocation, lists/resume/coverage/direct/export/edit denial, twice-run legacy backfill without evidence rewriting, same-site/destination/client-derived room handling and actual PDF time. The subclass uses a distinct `of_main06a_correction_20260927_<process>` database, disables sibling pruning and maintenance-file mutation, and inherits the candidate's real controllers/tests. The process exited0; Main independently queried schema metadata and confirmed **0 remaining review schemas**. No operational records or sibling database were changed.

Main separately passed **38 frontend tests/four files in a UTC process**,34.98s, including the exact timestamp and recovery cases. The owner's74 backend/936 assertions, separate four-test runs in three timezones, full types/lint/Pint,6m38 build and fresh actual-app desktop screenshots remain separately attributed owner evidence. No broad duplicate backend/build run or Main browser certification is claimed. Phone/hardware testing remains expressly deferred.

The base has advanced to publisheda6b9fae6a and PKG-04 exact79ea01a56 now owns the next serial publication slot. The same06A owner must reconcile actual published main after that slot, preserve these38 corrected source contracts and the Transport fixture/calendar fixes, run affected checks, and return its final exact SHA/base/hash delta for the final gate. No competing label implementation,06B source import, guide edit, automatic Maintenance task or PKG-03 local-only source is authorised. This base-preparation requirement is not a reopened correction finding or request for user confirmation.

## Historical first review — findings and reproduction

Exact candidate `80731cf56b4f8224769afaa91573ad1c1e8240dd`, application freeze `556e582cbd43aff875e1640ebc9e32fb9f52f6a0`, actual merged base `ba5bff2e8b6c22796369443f1cdac918039950dd`. Existing Astra/xhigh owner in checkout6421 retains corrections.

## Findings

### T06A-01 — P1: undone extra assets escape the current-access check for stocktake history

`app/Services/Assets/AssetStocktakeService.php:29–43` derives both the visibility-reference table and direct-record access checks solely from current `entries`. Recording an extra item writes its name/key into activity (`:169`), then Undo removes its current entry (`:175`) and the reference is deleted by `syncReferences()`. `present()` strips the internal previous snapshot but keeps the activity name/key (`:244`). The UI, PDF and Excel activity render those retained values.

Main reproduced this through real candidate HTTP controllers in a fresh synthetic database: start a selected-item count; add a permitted extra asset; undo the extra; mark the expected item found; finish; move the extra asset outside the viewer's approved sites. The extra's canonical profile then returns404, but the completed stocktake still returns200 containing **SYNTHETIC HIDDEN EXTRA** in its activity, and its visibility-reference row is absent. The first test in [Main's reproduction](PKG06AMainReviewTest.php) passed all assertions documenting this exposure.

Retain authoritative asset references for retained history as well as current entries, or apply equivalent source-owned access projection consistently to list/show/export. Keep immutable audit evidence; do not erase it to fix disclosure. Test removal/Undo followed by revocation/site move, direct URLs, list/resume and PDF/XLSX access. Avoid discovering references only from mutable display strings.

### T06A-02 — P2: ordinary site editing fails for an asset registered with a room

The new create flow records canonical `site_room_id` (`AssetController.php:731,775–776` and the create-only room picker). The existing shared edit flow can change Site, but `update()` neither accepts a new room nor clears/reconciles the old one before `$locked->update($data)` at`:864`.

Main created an asset through POST with a valid room, then submitted an otherwise authorised ordinary PUT to a different permitted Site. The request returned **500**, with MySQL1452 on `assets_site_room_id_site_id_foreign` (`site_room_id, site_id` → `site_rooms(id, site_id)`). Main's second probe expected the valid edit to redirect and failed at that assertion. The composite constraint prevents the hypothesised inconsistent room disclosure; Main does **not** claim the move succeeded or exposed the old room. The confirmed bug is an offered ordinary workflow ending in a database error.

Reconcile room placement explicitly in the canonical site-change path: clear the old room or require/select a valid destination room with suitable review and validation, retaining current assignment/device guards. Preserve the room for edits that do not change its Site; account for a client-driven Site change too. Test both canonical create and CSV-imported room assignments followed by ordinary site editing. Do not remove the integrity constraint.

### T06A-03 — P2: register audit times follow the browser zone instead of Auckland

`resources/js/pages/fleet-assets/assets/register/api.ts:108–116` adds `stamp()` using `Intl.DateTimeFormat` without a timezone. Its consumers label stocktake observation/completion/activity, import history and label history. The report renderer explicitly uses the configured worker timezone, so the same evidence disagrees between the screen and its PDF when the browser is elsewhere.

Main executed the submitted helper in two timezone settings. `2026-09-27T01:00:00Z` displays **27Sept2026,1:00am** in UTC and **27Sept2026,2:00pm** in Auckland, with no zone label explaining the difference. [Exact source hash and output](PKG-06A-main-time-display-probe.json). Use the existing worker date/time contract and verify different browser zones, with agreement between record and export. Do not change global formatting policy.

## Independent evidence and limitations

- All37 submitted application hashes matched exact80731cf56; application/test/package paths were clean at the initial check. Scoped diff checks passed. `VehicleTripWorkbook` matches the actual base, and the exporter diff is the bounded branding visibility change.
- Main independently ran all four submitted frontend files: **37 tests passed**,33.93s, local start17:10:16. These do not cover the three findings.
- Main's two controller probes ran against `of_main06a_review_20260927_12084`, built from the owner's schema-only test snapshot. The harness disables sibling-database pruning and maintenance-file removal, uses rollback fixtures, and registers cleanup of only its own process database. Result: **2 tests,11 assertions,1 expected-behaviour failure**,3m59.152s. The first documents a real disclosure; the second fails on the500 described above. This is not a passing backend regression suite. No operational data or application source was changed.
- After the process completed, Main independently queried schema metadata for that exact database name and confirmed **0 remaining schemas**. No sibling database was pruned.
- The owner's68 backend/infrastructure tests/855 assertions, clean TypeScript/lint and final build remain independently inspected owner evidence, not a Main rerun. Main inspected the actual Inventory and completed-stocktake screenshots from its packet; this is not a fresh Main browser run or complete visual certification.
- Main reviewed stocktake scope/commands/history/immutable completion, imports/partial recovery, label retry equality/token mutation/download access, canonical Asset creation/edit integration, export generation, shared shell/schema-cache/test-harness changes and frontend recovery. No separate duplicate label owner or Maintenance-task creation is approved.
- Phone/device testing remains explicitly deferred and is **not** an approval blocker. Physical camera/scanner/printer and operational activation remain unverified. The review findings concern application behaviour independent of those devices.

## Disposition

Same owner corrects the three findings, runs affected privacy/placement/timezone regressions and submits one exact successor commit/base with its final QA. No new worker, protected-guide change, Main app edit or new user confirmation. The existing local-main/GitHub publication request remains authorised after Main's renewed **Approved for integration** and an explicit serial slot. Main is the reviewer/coordinator; the owning Designer executes permitted integration. Deployment/operational migrations remain separate. Preserve the shared06B label reconciliation and keep PKG-03 local-only changes out of remote publication.
