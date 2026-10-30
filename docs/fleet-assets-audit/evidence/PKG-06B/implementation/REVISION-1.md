# PKG-06B — correction of Main review findings

This packet supersedes the exact candidate `173a49bb8678bd0f4f5f7ed0f6e83e3f19b768c8` for technical review. Main requested T06B-01 and T06B-02 corrections. The existing user publication authority remains valid; Main's renewed technical review and serial integration slot are still required. The same owner retains the work. No Main checkout write, remote push, deployment or operational migration is included in these corrections.

Finding corrections: `58a9d6606fe54ae6db501cc209411c4c30e74263`. Final runtime merge: **`93952363f724733f14bfff9a46406b9401154f7d`**. Published Main base: **`64995efca1b067814a53f93fae962028e6510eb1`**, verified on GitHub and fetched before reconciliation. The final packet commit adds evidence only; its exact tip accompanies the Main handoff. `revision-1-source-sha256.json` records all 76 changed source/config/design-guide Git blobs against that published base.

## T06B-01: canonical Finance read scope

`AssetProfilePresenter` now starts with `VehicleFinanceReviewQueue::scoped($actor)` before projecting reviews. Asset visibility through Security Devices does not confer Finance Site visibility. The existing Finance capability and accounts-payable source restrictions still apply. Replacement amounts, decisions, references and links are derived from those same scoped rows. Existing cost/bill projections already intersect Asset and Finance scope. Submission and decision authority remain distinct and unchanged.

The new regression creates a replacement request through the real endpoint and proves that an all-Site Asset viewer at a different Finance Site receives no review or derived cost rows, while the canonical Finance queue also withholds the request. Permitted local and central Finance readers retain the amount, decision and link without gaining submission/decision authority. Accounts-payable restrictions retain a separate positive control.

## T06B-02: linked-kit placement integrity

The shared `AssetMutationIntegrityService` checks active relationships for both parents and components before ordinary Site, home Site, client, room or free-text placement edits. Callers already lock the canonical Asset; the relationship query uses a current locking read so a relationship created while waiting for that Asset lock is not missed. The approved dispatch/receipt path supplies the checked parent identity while retaining its parent/component locks, relationship checks and atomic movement.

Kit linking now rejects components with a pending, incomplete or disputed receipt, or an acknowledged loan still awaiting return. Movement and relationship guards use current locking reads. No link is silently removed and no receipt is fabricated. Explicit removal retains kit history. Existing inconsistent data also fails closed when an independent receipt would split a currently linked component.

Regression coverage includes complete valid ordinary edit payloads and a successful standalone move before linking, direct moves of both the parent and component, unrelated detail edits, all placement fields exposed by the ordinary editor, removal followed by a permitted standalone move, unresolved movements, outstanding loans and legitimate returns, legacy inconsistent links and their explicit recovery. The existing acknowledged whole-kit receipt remains a positive control.

## Verification record

- Focused pre-reconciliation run: 7 tests, 183 assertions, zero errors/failures/skips; includes all six new regressions and the existing acknowledged whole-kit receipt (`revision-1-focused.xml`). It used rollback transactions in the owner-only synthetic fixture. The seven runner warnings are the previously diagnosed phpdotenv read of the deliberately absent `.env`.
- PHP formatting of all four correction files passed. No schema or frontend change is part of the two finding corrections themselves.
- Full affected backend run before Maps reconciliation: **69 tests, 1,123 assertions, zero errors/failures/skips**, exit 0, 327.72 seconds. It covers Profile (25), Register (22), shared mutation boundaries (11) and Finance (11) on fresh disposable schema `oblivion_findings_pkg06b_8821_test_34656`; the schema was removed after completion. The 69 phpdotenv warnings have the same previously diagnosed absent-environment-file cause (`revision-1-backend.*`).
- After Maps reconciliation, **46 UI tests across 14 files passed** (40.06 seconds), covering Profile actions/custody/Finance/documents, the shared file viewer, Register labels/stocktakes/API, and Maps address search, boundaries/wizard and operational map (`revision-1-ui.log`). Focused ESLint and final PHP formatting passed (`revision-1-eslint.log`, `revision-1-pint.log`).
- Final combined backend: **29 tests, 450 assertions, zero errors/failures/skips**, exit 0, 326.22 seconds. The exact 25 Profile source cases run through a small subclass using the repository's PDO schema loader, followed by all four published Maps privacy cases (`PKG06BRevisionReviewTest.php`, `revision-1-combined.*`). Fresh disposable schema `oblivion_findings_pkg06b_8821_test_52792` was removed. No source test was copied or altered by that harness. The 29 absent-`.env` warnings are distinct from assertion failures. Final metadata-only isolation verification finds only the owned browser fixture, with local autoload and no application/config environment loaded (`revision-1-isolation-final.json`).
- Repository-wide TypeScript passed without diagnostics. Production build passed in **4m 45s**, with the existing chunk-size advisory only (`revision-1-types.log`, `revision-1-build.log`).
- Real browser preview reloaded the final **app-DGSJd41Z.js** bundle on port 8905. The Profile retains its purple hero, view/section navigation and Location cards; its Maps link targets `/fleet-assets/geofences?tab=map&resource=1`. Following that link opened the Maps workspace with Transfer hoist selected and its source profile link; the browser was then returned to the Profile. No JavaScript errors were captured on the Profile (`revision-1-browser.json`, `revision-1-location.png`, `revision-1-maps.png`).

## Published Maps reconciliation

Maps & Boundaries was actually published while these corrections were underway. It was merged only after `git ls-remote origin refs/heads/main` and the fetched ref both confirmed exact `64995efca1b067814a53f93fae962028e6510eb1`. No unpublished sibling source was imported.

Of 71 published Maps source files, 68 remain byte-identical to the published base. The three shared files retain both packages: `AssetController` includes the permission-controlled Maps link alongside the Profile projection, `assets/show.tsx` retains the legacy Maps entry, and `routes/fleet-assets.php` includes both route sets. The new Profile's Location view also renders that same server-provided link, so its replacement of the legacy screen does not hide the published entry point. Maps privacy/provenance code and migrations are unchanged. Published Register index/stocktake contracts, retained-reference migration and canonical Transport fixture are unchanged.

The three published Maps migrations were applied only to existing synthetic preview `oblivion_findings_pkg06b_8821_test_36804`, using the explicit environment/database/fixture-identity guards in `prepare-revision-1-fixture.php`. Its label history remained byte-identical (`revision-1-fixture-adoption.json`). No operational database or deployment was used.

All **371 frozen entries across v1–v9** matched their recorded hashes, with zero mismatches and no local runtime files staged (`revision-1-frozen.json`). Source verification is reproducible with `verify-revision-1.py`; historical combined manifests were not overwritten.

Earlier `MAIN-REVIEW.md`, `combined-*` and `final-*` artifacts remain historical evidence for the original reviewed source. In particular, automatic IAB downloads and physical printing are not newly claimed as verified. All frozen v1–v9 design references and the narrow previously authorized file-preview guide exception remain protected.
