# PKG-01 — integration preflight

Owner: DESIGNER ASTRA. Revision2. 2026-09-20. **The earlier release pause and test-renewal request below are resolved.** Main approved candidate7E5A61877E5B757B386D3AF486FE405BBE0DA5CC3D72851DAE3572CF372914B4. Stephan directly confirmed that this is a test server and merge/push to main is fine; Main recorded the exact approval in `../PKG-01-test-server-publication-approval.md`. No additional push permission is required. Production use and actual policy/grant configuration remain outside that approval.

Application source commit869edf738035e7e42890fdf902d75d914d393a55 maps all63 approved source paths through repository clean/line-ending filters to staged treee66029cc5a9fe703a252f179546d9db2309fbb0f. Main's exact additive D1–D4 patch is committed separately asc008fb0cf3ab45cc56b0b038a542f18ed4cf20ba; all three resulting hashes match its decision. Current context publication uses Main revision2 manifestF59BF5673552B79052E93E51A338B9DA53F4A77C0441297F37E18A02308C9703 (42files plus manifest) and inspected Designer/implementation records. Final checks and remote publication are in progress; no remote or CI pass is claimed by these local commits.

## Revision1 — historical preflight observations

Owner: DESIGNER ASTRA. Revision1. 2026-09-20. Local preparation only; not published, deployed or accepted.

Main replacement review revision1 approved manifest14EA5A91290AE59808C4490613973E9142EA5ABDC99428B8F9788740E5B142D8 on basee62b569ff42ab471300fb6713a68758b647b2c32 for Section14F integration. Actual Designer continuation01a0bdf5-15f7-7b01-8450-bfc3418d304e at08:35:50.983Z verified gpt-6-astra/xhigh in both fields. Sol remains stopped and no correction counts change.

## Repository observations

- Confirmed destination: public GitHub repository `Rory357/oblivionfindings`, configured origin `https://github.com/Rory357/oblivionfindings.git`, default/target branch `main`.
- Read-only remote inspection and subsequent `git fetch origin main` both showe62b569ff42ab471300fb6713a68758b647b2c32. No intervening main commits or source conflicts were found.
- Branch API reports protected:false and rulesets returns an empty list. Branch-protection endpoint returned404. No protection/settings change was made; this is not a fabricated platform approval.
- Existing Actions files cover tests, linter, database bootstrap and visual regression. No deployment command was found in those workflows. GitHub deployment and environment lists are empty. Hook enumeration is unavailable to the current token, so these observations do NOT establish absence of an external deployment hook.
- Main confirmed the earlier Section0 record explicitly leaves production webhook/deployment/migration consequences unverified and has a concrete lead in the Fleet incident progress record. Publication remains paused while Main investigates. Main technical code approval is not production-release approval.
- The user's Main checkout remains on19354ecbc70046d12dfdf9c86f888e65fa1879d1 with Main-owned programme/guide changes and unrelated public/.user.ini. It has not been reset, stashed, overwritten or branch-ref advanced by Designer. Integration must preserve that working tree.

## Existing baseline CI

These are observed GitHub results for the untouched remote basee62b569…, before PKG-01 publication; not results for a newly integrated candidate.

- [Linter run35169262302](https://github.com/Rory357/oblivionfindings/actions/runs/35169262302): failed at Lint Frontend,322 problems (250errors,72warnings), including existing React refs-during-render violations across repository files.
- [Tests run35169262297](https://github.com/Rory357/oblivionfindings/actions/runs/35169262297): all nine jobs failed. Inspected foundation job105037135931:8failed/95passed in its first batch, including existing HR/site, H&S closure, Inertia SSR and IT-security architecture boundaries. This is a representative inspected failure, not a claim that every failed shard has the same cause.
- [Visual run35169262324](https://github.com/Rory357/oblivionfindings/actions/runs/35169262324): all four jobs failed. Inspected chromium-desktop job105037190447 reports49failed, with existing missing-element, URL and strict-selector errors across multiple modules.
- [Database bootstrap run35169262315](https://github.com/Rory357/oblivionfindings/actions/runs/35169262315): success.

No failure was waived, hidden, marked green or repaired by unrelated changes. Required integration checks and any repository/release gate still apply. Raw downloaded logs remain local evidence and are not part of the proposed public context set.

## Prepared package and affected renewal

Only63 application/configuration/migration/route/test files from the reviewed manifest are staged. The seven local implementation-evidence files are excluded from this source staging; private browser-seed.php, the machine-specific PHPUnit profile, stored browser fixture, built assets and temporary mock artifacts will not be committed. Appropriate non-sensitive programme/handoff/QA documentation will be selected separately with Main's owned-context approval. No source commit exists yet.

One integration preflight correction is pending renewed Main approval: Pkg01MaintenanceRollbackTest originally allowed only the workstation's dedicated test database prefix. Standard repository CI uses `oblivion_findings_codex_test_<pid>`, so the reviewed guard would reject CI. It now allows only the existing CI prefix OR the unchanged approved local prefix, with the exact current process PID. APP_ENV testing and pre-DDL rejection remain. The changed file SHA256 isF6765E8DAC133A4DF508D6D3E26C13C4FF70F1A2385AFBE8786D30EDB1904F21. No application, migration or frontend content changed. The isolated rollback rerun passed1test/38assertions, exit0,223.13s with one missing-local-.env warning. [Exact log](designer-integration-rollback-test.log). Renewed [candidate manifest](designer-integration-candidate-manifest.json) SHA2567E5A61877E5B757B386D3AF486FE405BBE0DA5CC3D72851DAE3572CF372914B4 records only that changed test; all other69 source/evidence files and all harness/build hashes remain unchanged. This result was sent to Main for affected approval. Existing approved source/build identity remains the comparison baseline, not silently re-labelled as the changed candidate.
