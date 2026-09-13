# W01 — Per-record ticket privacy slice

Date: 9 September 2026. Owner: W01 privacy subagent. Status: **Implemented; focused automated verification passed; browser verification remains with the root task**. This closes neither W01 nor the overall release gate.

## Boundary and implementation

The application is one organisation across approved sites. Existing `ItWorkAccessService::canView` permits requester/requested-for participation even when the participant also happens to hold an IT role. That participation does not grant internal technician access. Internal conversation, internal attachment access, routing context, composer knowledge suggestions and technician controls require the existing per-record `canWork` boundary. Read-only `it.view` does not acquire broader internal rights. No new permission or site exception is introduced.

- `app/Http/Controllers/It/ItTicketController.php`: shared Inertia/JSON `showPayload` derives technician presentation from `canWork`, matching the protected attachment download boundary.
- `app/Domain/It/Presenters/ItTicketActivityPresenter.php`: standalone timeline denies an inaccessible parent; the reusable event projection applies the same public event allowlist and payload allowlist for viewers without `canWork`.
- `app/Http/Controllers/It/ItProvisioningController.php`: only the recent-activity projection and injected presenter belong to this slice. The hub no longer emits raw internal event payloads for participant-only viewers. Other changes in this controller belong to separate concurrent work.
- `tests/Feature/It/ItTicketWorkspaceTest.php`: requester/requested-for × sensitive/unapproved-site cases cover page/quick-preview projections, internal evidence URL denial, public evidence success, internal mutation denial, activity filtering, positive authorized technician cases and revocation of a previously open participant record. A separate read-only IT test prevents permission broadening.

Mappings: W01; E04–E06 and E23 permission/privacy dimensions. F09/F16 feedback and other W01 capabilities are not closed by this slice.

## Actual verification

All database tests use `evidence/run-isolated-it-tests.ps1`, whose read-only preflight verified this checkout, `APP_ENV=testing`, loopback MySQL, a unique disposable schema under `oblivion_it_support_test_*`, no database URL/config cache, array mail, synchronous queue, null broadcasting, array cache/session and absent maintenance files. No production migration or real communication was performed. Herd PHP required sandbox escalation; normal in-sandbox execution could not access that installed binary.

1. Pre-fix command: `& docs/audits/2026-09-08-it-support/evidence/run-isolated-it-tests.ps1 -TestPaths tests/Feature/It/ItTicketWorkspaceTest.php -Filter 'participant-only technicians'`.
   - **Expected failure reproduced:** four dataset cases failed, 64 assertions, 235.55 seconds. Each failed because `comments` contained two records when the participant audience permitted one. Disposable schema: `oblivion_it_support_test_it_d8dcbef335864530`.
2. Focused Pint command: `& 'C:\Users\steph\.config\herd\bin\php84\php.exe' vendor/bin/pint app/Http/Controllers/It/ItTicketController.php app/Domain/It/Presenters/ItTicketActivityPresenter.php app/Http/Controllers/It/ItProvisioningController.php tests/Feature/It/ItTicketWorkspaceTest.php`.
   - **Passed.** No frontend or protected design sources changed.
3. Post-fix command: `& docs/audits/2026-09-08-it-support/evidence/run-isolated-it-tests.ps1 -TestPaths tests/Feature/It/ItTicketWorkspaceTest.php,tests/Feature/It/ItWorkAccessControllerTest.php,tests/Feature/It/ItTicketAttachmentTest.php`.
   - **35 passed; 5 failed; 767 assertions; 412.66 seconds.** All 14 access-controller tests and all three attachment tests passed. Disposable schema: `oblivion_it_support_test_it_4a1e1f888f134f9e`.
   - Four new privacy cases passed their page-projection assertions, then failed an order-sensitive JSON object assertion because MySQL reordered the `from`/`to` keys. The assertion now verifies each named field and the exact key count.
   - The existing permitted-watcher notification test had no approved site on its watcher fixture. The root task's new notification reauthorization correctly suppressed that recipient. The intended positive fixture now has current approved-site provenance; access checks were not weakened.
4. Workspace rerun: `& docs/audits/2026-09-08-it-support/evidence/run-isolated-it-tests.ps1 -TestPaths tests/Feature/It/ItTicketWorkspaceTest.php`.
   - **23 passed; 715 assertions; 293.89 seconds; exit 0.** All four participant-only cases passed through public/internal projection, file access, hub activity, authorized technician positive control and participant revocation. The read-only IT case and existing permitted-watcher notification case passed. Disposable schema: `oblivion_it_support_test_it_ee0d4dc555b74ebb`.
   - Focused Pint passed again. `git diff --check` passed on the four owned files. The unchanged, already-passing 14 access-controller and three attachment cases were not repeated. Across these focused results, all 40 applicable cases passed after fixture corrections.

## Browser and remaining work

Actual restricted browser verification is pending in the root task using the Codex in-app browser and guarded synthetic fixtures. Source inspection and HTTP tests are not a substitute for that evidence. No frontend build is needed for this PHP-only slice; browser verification must still confirm the current host/checkout/assets.

Next step: join the root task's requester/technician desktop/narrow browser evidence using `evidence/w01-browser-fixtures.json`: sensitive participant ticket 12, unapproved-site requested-for ticket 13 and permitted technician ticket 14. No browser result is claimed here. Notification recipient reauthorization is owned by the root task and is a separate W01 slice. Documentation/vault/approver/integration capability work remains open.
