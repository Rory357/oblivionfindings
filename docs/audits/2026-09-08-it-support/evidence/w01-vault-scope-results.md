# W01 — Empty-Site vault feed boundary

9 September 2026. Status: **Implemented; focused automated verification passed. Browser verification is root-owned and pending for this slice.** Source changes are limited to the two global directory/audit projections in `app/Http/Controllers/Sites/SiteVendorController.php`. New regression file: `tests/Feature/Sites/VaultAccessScopeTest.php`. No new permission, role grant, credential field, vault lifecycle or frontend was implemented.

## Defect and fix

The global directory and credential audit feed treated `UserSiteAccessService::accessibleSiteIds()` returning `[]` as unrestricted. The canonical service currently returns `[]` when there is no current employee profile or approved Site assignment. A user retaining metadata/reveal permissions after their last assignment disappeared could therefore discover records across Sites, although direct credential disclosure was denied by `SitePolicy`.

Both feed queries now use the existing explicit `sites.viewAll` exception from `SitePolicy` and always apply `whereIn`. Empty scope yields no rows. The directory's vendor/credential records, Site picker and data-derived type filters use the same predicate; the application-wide credential-type catalogue is unchanged. Existing Site/type checks and separate `credentials.view`, `credentials.reveal`, `credentials.manage` permissions remain intact. No sensitive values are added to payloads, exports, logs or evidence.

## Actual test runs

All commands use `evidence/run-isolated-it-tests.ps1`: exact checkout, testing environment, loopback MySQL, unique disposable schema, array mail, sync queue, null broadcast, array cache/session, no database URL/config cache and no maintenance state. All 14 preflight guards passed. Installed Herd PHP required sandbox escalation. No working-database reset, provider request or real communication occurred.

- Pre-fix command: `& docs/audits/2026-09-08-it-support/evidence/run-isolated-it-tests.ps1 -TestPaths tests/Feature/Sites/VaultAccessScopeTest.php -Filter 'vault metadata and audit feeds deny empty current Site scope'`.
- Result: **3 expected failures, 68 assertions, 281.73 seconds, exit 1**, schema `oblivion_it_support_test_it_12bec2bd11bb48fc`. Missing profile, ended employment and last assignment removed all returned two directory vendor records where zero were required. These failed at the first assertion, so later credential/audit assertions are not claimed to have run before the fix; their identical conditional-scope defect was source confirmed.
- Focused Pint on the controller and new test file: **passed**.
- Post-fix command: `& docs/audits/2026-09-08-it-support/evidence/run-isolated-it-tests.ps1 -TestPaths tests/Feature/Sites/VaultAccessScopeTest.php,tests/Feature/Sites/VendorsCredentialsGlobalTest.php,tests/Feature/It/ItWorkAccessServiceTest.php,tests/Feature/It/ItTicketApprovalTest.php`.
- Post-fix: **48 passed, 490 assertions, 282.03 seconds, exit 0**, schema `oblivion_it_support_test_it_483240b53d694ca3`. Breakdown: four new vault regressions, 14 existing global vendor/credential cases, eight canonical work-access cases and 22 ticket-approval cases. The new tests verify empty metadata/audit/pickers and direct reveal denial for three zero-scope states, permitted explicit Site bypass, and revocation of that bypass. The existing suites verify assigned-Site, metadata-versus-reveal, team/assignment and approver separation boundaries. No existing test or permission was changed to pass the batch.

## Acceptance and resumption

Mappings: W01 credential reader/revealer, current approved-Site metadata/record denial and revocation; E21 privacy dimension. This does not close W24 SSO step-up, clipboard truth, credential retirement/versioning or backup/key-custody recovery. The broader capability map is in `w01-capability-contract.md`.

Root owns the progress ledger and desktop browser matrix under the user's clarified web-only scope. Verify the empty directory and denied disclosure after approved-Site revocation in that browser matrix. An HTTP/Pest result is not browser evidence. No global fixture or operational actor was modified. The code and focused automated checks for this slice are complete; broader W01 and W24 gates remain separate.
