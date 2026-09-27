# PKG-05 — Main publication verification

Owner: MAIN ASTRA. Revision: 4. Updated: 2026-09-27.

## Final tooling packet and terminal49 hosted results reconciled

Main inspected and retained the [final owner packet](PKG-05-owner-lint-publication-verification.md), [before](PKG-05-owner-lint-publication-before.json)/[after](PKG-05-owner-lint-publication-after.json) fingerprints and [test comparison](PKG-05-owner-hosted-test-comparison.json). They agree with Main's independently verified exacta6 publication, config/tree, integrated scope/lint and67 protected files. The already-released Transport slot remains released; Fleet79ea01a56 has since been published through its own gate.

Main independently queried GitHub for the four runs on exact49e0be5b1: tests36294111080 **failure**, database-bootstrap36294111093 **success**, lint36294111128 **cancelled**, visual36294111139 **cancelled**. The owner attributes both cancellations to successor-push workflow concurrency; no manual cancellation is reported. Cancellation is not a passing check.

The retained owner comparison contains34 displayed candidate failure entries against31 base entries,29 matching signatures and five newly observed signatures. Main inspected the comparison but has not reproduced the five failures: `ControlRoomAlertNestedProvenanceTest.php:112,217`, `ControlRoomOperationalSurfaceSiteIsolationTest.php:352`, `ComplianceDashboardSiteScopeTest.php:278` and `ControlRoomJourneyAuthorizationTest.php:363`. They remain **unclassified**, not asserted inherited or Transport regressions. Unchanged source files, truncated display names and fail-fast batching cannot establish regression freedom; base-only missing failures are not claimed fixed. The independently confirmed frozen-preview packaging defect is separately corrected ina6.

At the owner's17:48 capture, all four a6 successor workflows were in progress. This record does not assert their eventual outcome. Final user acceptance and operating activation remain open; publication verification is complete without a full-CI-green claim.

## Lint-packaging follow-up published; slot released

Main independently verified local HEAD and remote `refs/heads/main` at exact approved `a6b9fae6a73516548b02e64c41cf1fc94ce43f01`; both candidate and local committed tree are `41fc7644a1d32f59b169a8284a0aadaeaf4c14a1`. Only the reviewed two-line ESLint ignore/comment and its three evidence files differ from49. The actual Main config matches reviewed SHA256 `D1062A9403194B559F30522EA3CBDD9366F3DE55E953C93F606339834E7E11A5`; application/test/config paths are clean.

Main independently compared all **67** paths in the owner's `output/playwright/pkg05-lint-publication-before.json`: **0 missing, 0 changed**. The new corrected-calendar probe was created by Main after that snapshot and is additional programme evidence, not a lost or changed captured file. `public/.user.ini` is preserved. The owner executed the permitted fast-forward/push; Main did not merge or stage application files.

The owner's actual-main `pkg05-main-lint-scope.json` reports576 unchanged frozen files,166 excluded frozen code files, unchanged real-source effective rules and unchanged unrelated-preview scope. Actual-main scoped lint log is empty with the owner's successful execution recorded. Main's earlier independent validator and scoped lint results remain applicable to the exact config. These checks support publication verification, not a green whole-repository CI claim.

The follow-up publication slot is **released**. Same owner retains terminal hosted assessment and final evidence packaging; it has no further main mutation slot. Database bootstrap is reported successful, while matched baseline failures and additional unclassified Control Room failures remain distinguished. Final user acceptance and operational activation remain open. This tooling publication supersedes49 as the current Git base without changing runtime source.

## Final owner handoff reconciled; serial slot released

Main has received and inspected the [owner's final publication packet](PKG-05-owner-PKG-05-PUBLICATION-VERIFICATION.md), preserved with its [before](PKG-05-owner-pkg05-publication-before.json) and [after](PKG-05-owner-pkg05-publication-after.json) fingerprints. Actual Main integrated checks passed:38 frontend tests/4.42s, expanded Transport TypeScript (empty success log), and18 PHP syntax checks. These are owner executions on actual main, in addition to Main's earlier independent candidate tests.

Main independently compared all62 captured pre-publication paths with the current files: **0 missing**. The only four newer content hashes are `00-project-rules.md`, `05-page-register.md`, `07-model-results.md` and `evidence/OPS-PL01-design-start.md`; Main made each of those documented programme edits in this turn. The two17:22 changes flagged by the owner are therefore reconciled. The remaining58 captured hashes match; unrelated `public/.user.ini` remains unchanged. No preservation repair/revert is required.

The PKG-05 serial publication slot is **released**. Source publication and integrated-check handoff are complete, while four exact-head hosted workflows were still in progress on the renewed read. Same owner retains their terminal result assessment; this is not final green-CI or user acceptance. No new package receives integration approval by this release. PKG-04 is reconciling the new published49e0be5b1 base for renewed exact review;06A remains in corrections,06B in preparation, and03 remains local-only without a main slot.

**Approved Transport candidate is published to local main and GitHub main.** Both independently resolve to `49e0be5b1c1716aeb4e681529bb71fdce2a7abc0`, the exact candidate approved in [technical review revision2](PKG-05-main-technical-review.md). The owning Designer executed the permitted fast-forward and ordinary push; Main only verified and maintained programme documentation.

## Exact-source verification

- Main independently read local HEAD and `git ls-remote origin refs/heads/main`: both49e0be5b1. Candidate and local HEAD tree are both `5b7b98cc2c8a12fecbd4dbb39e9e18687d209f78`.
- All48 application/test/config paths in [the approved manifest](PKG-05-main-approved-source-hashes.json) were checked.47 match raw SHA256. The remaining `resources/views/pdf/transport-workspace.blade.php` differs by exactly one CRLF→LF normalization; direct normalized contents are identical, and its working/index/approved Git blob is `5976ca273556c58b4b4ac39cb6fca7df955bace4`. This is a verified line-ending difference, not an unreviewed content edit.
- Application/database/resource/route/test/config status is clean. Main's dirty programme files remain, including the post-review revision111 register at the first publication check; unrelated `public/.user.ini` remains present. Main did not reset, clean, stash, commit or push these files.
- The previous base wasba5bff2e8. No PKG-03 local-only source, unapproved04/06A/06B source, operational database migration or deployment is included by this publication.

## Hosted checks at the observation

All four exact-commit workflows were **in progress**, with no conclusion yet:

- [Tests](https://github.com/Rory357/oblivionfindings/actions/runs/36294111080)
- [Database bootstrap](https://github.com/Rory357/oblivionfindings/actions/runs/36294111093)
- [Linter](https://github.com/Rory357/oblivionfindings/actions/runs/36294111128)
- [Visual regression](https://github.com/Rory357/oblivionfindings/actions/runs/36294111139)

The independent38-test frontend pass and reviewed owner QA are documented in the technical review; they are not substituted for these hosted results. Base CI had known failures, so no green-CI inference is made.

The same Designer retains the final integrated-check/publication packet and exact-head hosted-result assessment. Main's observed publication is confirmed; CI completion, Stephan's final package acceptance and operational activation remain separate. Other pending package owners must reconcile this new published base and keep their existing gates. No new worker, routine cross-chat loop or scheduled monitor is introduced.
