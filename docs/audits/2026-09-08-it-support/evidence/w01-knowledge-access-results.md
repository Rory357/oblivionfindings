# W01 — Current knowledge audience and adjacent access checks

Date: 9 September 2026. Owner: W01 access subagent. Status: **Implemented; focused automated verification passed; current-assets browser verification pending with root**. This is one W01 privacy slice, not completion of W01, W21 or the release gate.

## Confirmed boundary gaps and chosen contract

Source inspection confirmed that the technician hub queried every knowledge article, including unapproved-site draft bodies, and ticket suggestions queried every published title. Published-reader checks already considered approved sites. Authoring normalized the proposed audience but did not first authorize the stored audience: an ordinary manager could address a hidden article directly and change its audience to `all_staff`, or invoke a lifecycle action on it. These are current code defects, distinct from the later W21 reviewed-revision enhancement. No pre-change runtime red test is claimed for this knowledge slice.

The application remains one organisation. Knowledge readers require current approval and an existing IT entry permission. Staff read published `all_staff` or approved-site articles; agents additionally read `it_agents` content and permitted drafts. Ordinary readers need an overlap with the article's specific sites. An ordinary manager must cover every site in the stored audience before changing that article. The existing explicit `it.organisationWide` governance exception is retained. `it.view` alone gains no write or vault capability; author ownership does not bypass audience checks.

## Changed files

- `app/Domain/It/Services/ItKbAccessService.php`: one query and direct-object audience boundary, using current approved sites and reloading canonical article authority. Batch management capabilities reuse that decision for visible rows without an article/site query per row.
- `app/Domain/It/Services/ItKbLifecycleService.php`: authorize the stored article under the existing transaction/row lock before updates and every lifecycle mutation; reuse the published-reader boundary for read/helpful interactions.
- `app/Http/Requests/It/Concerns/ConcealsInaccessibleItKnowledge.php`, `UpdateKbArticleRequest.php`, `DeleteKbArticleRequest.php`, `RetireKbArticleRequest.php`, `KbHelpfulRequest.php`: conceal inaccessible existing articles before payload validation.
- `app/Http/Controllers/It/Concerns/BuildsItOptions.php`: published suggestions take the viewer and apply audience scope before the result limit.
- `app/Http/Controllers/It/ItTicketController.php`: only the knowledge-suggestion argument change belongs to this slice; other edits belong to concurrent ticket work.
- `app/Http/Controllers/It/ItProvisioningController.php`: only `kbArticles`, `kbPublished`, their access-service import and the `kbArticles($user)` caller belong to this slice. Both catalog queries scope before limiting; article rows carry current `can.manage`. Other controller edits are separate work.
- `tests/Feature/It/ItKnowledgeAccessTest.php`: restricted primary/secondary/unapproved-site catalog and suggestion assertions, seven forged mutation routes, canonical-record and revoked-site checks, legitimate authoring/read/vote, and explicit organisation-wide governance.
- `resources/js/components/it/it-wizards.tsx`: only the additive `KbRow.can.manage` type belongs to this slice.
- `resources/js/pages/it/index.tsx`: only per-article KB action/context-menu gates and current reader selection/rendering belong to this slice. The reader stores an ID and renders solely from the current authorized `kbPublished` props; removed articles close the reader and clear selection. Later access restoration does not reopen a stale selection. No stale article object can supply body/title or a helpful-vote fallback. Existing layout, components and tokens are retained.
- `resources/js/pages/it/knowledge-access.test.tsx`: three React page regressions exercise removal of an open article, refreshed publication content and controls responding to per-record authority changes.

Mappings: W01 direct-object, payload and revocation acceptance; E04 discovery/privacy dimension; E20 restricted draft/publication discovery dimension. W21/E20 reviewed revisions, dedicated reader/editor and documentation lifecycle remain open. No existing audit finding is declared wholly closed by this slice.

## Actual verification

Focused Pint passed on the new access service, lifecycle service, request concern and four requests, KB suggestion trait, the two caller controllers and new test file. It changed formatting in `BuildsItOptions.php`; protected design sources were not edited. The root subsequently authorized the small per-article controls and stale-reader closure described above; the frontline UX and UI-pattern skills were read and applied for that UI follow-up.

The following bounded command ran through the established isolated wrapper:

`& docs/audits/2026-09-08-it-support/evidence/run-isolated-it-tests.ps1 -TestPaths tests/Feature/It/ItKnowledgeAccessTest.php,tests/Feature/It/ItKbTest.php,tests/Feature/It/ItSavedTicketFilterTest.php,tests/Feature/It/ItReportsTest.php,tests/Feature/It/ItSecureApiAccessBoundaryTest.php,tests/Feature/It/ItMailboxSettingsTest.php`

Read-only preflight passed: correct checkout, testing environment, loopback MySQL, unique disposable schema `oblivion_it_support_test_it_a221a2f809174259`, no database URL/config cache, array mail, synchronous queue, null broadcast, array cache/session and no maintenance state. Herd PHP execution required sandbox escalation. No provider request or real communication is part of this acceptance run; mailbox OAuth and dispatch tests use their existing explicit mocks/fakes.

Result: **37 passed; 7 failed; 455 assertions; 289.44 seconds; exit 1.** All 34 existing KB (7), saved-filter (5), reports (4), secure API (10) and mailbox settings (8) tests passed. Three new discovery/canonical-service/explicit-wide tests passed. All seven forged KB mutation routes returned the expected 404; their subsequent unchanged-state assertions failed because the pre-request factory object omitted database-default null fields and had different attribute key order. The test now snapshots a fresh persisted row before the request. Production code was not weakened. The service test also now explicitly exercises a published guide spanning one approved and one unapproved site: read permitted, edit denied.

A focused rerun of `tests/Feature/It/ItKnowledgeAccessTest.php` **passed 10 tests, 84 assertions, 301.41 seconds, exit 0**, with identical isolation preflight passed and fresh schema `oblivion_it_support_test_it_cfa82a5ba5534d4b`. The five unchanged, already-passing suites were not repeated. Focused Pint passed after the test correction.

After the per-article capability projection was added, the same KB file gained allowed/denied `can.manage` Inertia assertions and was rerun in fresh schema `oblivion_it_support_test_it_f21e3e07bb384b58`. Preflight and run passed: **10 tests, 110 assertions, 279.36 seconds, exit 0**. Final scoped `git diff --check` passed. Across the unchanged companion suites and final KB run, all 44 selected PHP cases passed; the actual separate runs and initial test correction remain recorded above.

`node node_modules/vitest/vitest.mjs run resources/js/pages/it/knowledge-access.test.tsx` **passed all 3 tests in 9.02 seconds, exit 0**. The first in-sandbox attempt could not load Vitest's config because the installed esbuild runtime could not traverse its parent directories; the focused run passed with sandbox escalation. Routing was mocked and no database/provider was used. The tests verify a removed article's body and voting controls disappear, restored access does not reopen it, current published body/title replace the stale selection, and denied rows have no edit/lifecycle action trigger. Focused ESLint passed with `--max-warnings=0` for the index, wizard type and new test file. Prettier formatted the new test file; shared index/wizard formatting is left to the coordinated frontend build owner to preserve concurrent work.

## Adjacent existing boundaries and remaining work

- `ItReportsController` ticket queries, aggregate relationship counts and CSV datasets already apply `ItWorkAccessService::applyViewScope`. Device projections also require their owning module permission and approved-site scope. The existing focused reports tests are included above; this does not verify the later SLA/reports truth criteria in W04/W17/E03.
- `ItSavedTicketFilterService` retains only allowed options, prunes stale site choices and selects saved filters by the current owner. The existing focused saved-filter tests cover private reads/deletion and scope application. W05/E04 browser search, pagination, saved-view navigation and keyboard behavior remain separate.
- Existing secure API tests cover execution-account, current site, capability, direct-object and replay reauthorization. They are included above; W13 provider/programme behavior remains separate.
- Mailbox controllers retain `integrations.manage_secrets`; ordinary IT rights do not grant configuration access. Live provider permissions and consequential operational policy remain W10/W11/DP07 dependencies.
- Canonical `Sites/SiteCredentialController` source review confirmed site concealment, distinct `credentials.manage` / `credentials.reveal`, parent-ID checks, locked canonical rows and audit-before-disclosure. This run does not test credential behavior. W24 must still verify shared-vault integration, approved SSO step-up, copy truth, external rotation and encrypted recovery; no duplicate credential system was added.

## Precise resumption note

No test process remains running for this subagent; all scoped automated checks have passed. Root owns the progress ledger and real browser evidence. The separate `evidence/w01-knowledge-browser-fixtures.php` helper passed syntax checking, was reviewed and executed by the root. Its manifest `w01-knowledge-browser-fixtures.json` reports six added articles (IDs 2–7), count 1→7, all tracked unrelated record counts unchanged and existing-article digest `03c9256d38f5a37515ef47cb2be0d251d27f84941b65c2afc0de5aeeb7ee2486` unchanged. The helper never repairs existing rows; no overlap fixture was added to the already-created six. The overlap capability has automated coverage. Root's coordinated asset build and browser checks must include the current per-record controls and stale-reader closure. Verify restricted approved-site/general discovery and hidden draft/publication absence and direct interaction denial against Sites 9403/9405, actors 230/231; no live provider or protected design edits are authorized here.
