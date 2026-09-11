# W07 neutral scanner extraction — implemented and focused checks verified

This slice extracts the existing consent scanner process adapter for reuse. It does not activate IT scanning, change consent upload policy, install an engine, select a provider, or change provider configuration. W07/E05 remain incomplete pending the attachment lifecycle and operational decisions described in `w07-scanner-integration-proposal.md`.

## Source changes

- `app/Services/Files/MalwareScanner.php`: accepts a server-owned local path and caller-supplied settings; preserves direct Process argument-array execution and bounded timeout. Returns clean/infected/unavailable and safe failure codes, without returning process output, content, names or paths.
- `app/Services/Files/MalwareScanDisposition.php` and `MalwareScanResult.php`: typed neutral result.
- `app/Services/Consents/ConsentEvidenceMalwareScanner.php`: compatibility adapter preserving the existing `scan(UploadedFile)` response shape and `consent-evidence.malware_scanner` configuration.
- `tests/Unit/Files/MalwareScannerTest.php` and `ConsentEvidenceScannerCompatibilityTest.php`: injected Process outcomes and exact compatibility checks. No real executable or malware fixture is run.

Existing consent configuration, ConsentEvidenceService, IT attachment storage/download behavior, database schema and frontend sources are unchanged by this slice.

## Actual checks

- `php vendor/bin/pest tests/Unit/Files`: **16 passed, 89 assertions, 0.86s, exit 0**. Log: `w07-scanner-unit-tests.txt`. Covers process exit states, missing binary/file, timeout bounds, fd-pass arguments, timeout/exception safe results and all legacy consent dispositions/configuration.
- Explicit Pint over only the six source/test paths above: **exit 0**. No broad dirty-tree formatting.
- Isolated `tests/Feature/Operations/ClientConsentEvidenceTest.php`, token `it_810be3aa72284451`: **20 PHPUnit Test Passed events and 20 Finished events**, no Failed/Errored events; runner and application Finished events observed. Wrapper **exit 0**. The console summary was swallowed, so an assertion count and PHPUnit duration are unavailable and are not inferred. Evidence: `w07-consent-compatibility-tests.txt` and `it_810be3aa72284451.diagnostic.jsonl`.
- The same isolated wrapper's read-only postflight independently observed `isolated_schema_does_not_exist: true`, plus the expected local checkout/array mail/sync queue/null broadcasting/array cache/session guards. No test schema remains from this run. Diagnostic shutdown reported no PHP last error and no buffered PHPUnit internal error.

The feature tests use the existing injected consent scanner seam. Together with the pure adapter tests, they verify compatibility of this source change. They do not verify a real engine, signatures, process permissions, provider availability, IT quarantine/retry/download behavior or browser attachment journeys.

## Next dependency-ready work

Keep this extraction stable while the owner resolves the attached-reply outage behavior. Then extend canonical ItAttachment rows and storage/authorization services with durable scan state, exact-attempt retry, removal races, protected download integrity and isolated fake-scanner lifecycle evidence. Preserve historical unverified status and the separate draft-storage state. Register/monitor the existing draft pruning command before any authorized persisted-draft activation. No new attachment system or retention policy is introduced here.
