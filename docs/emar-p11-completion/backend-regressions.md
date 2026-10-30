# P11 backend repair candidates — 4 October 2026

Base: 50ee59735aa91d6ef2229304839effd9a771040a. Branch: codex/emar-p11-backend-regressions, owned P11 worktree. Former P11 branches remain intact.

The frozen consolidated evidence remains 393 failures in 78 files. Eleven cases in the six assigned P11 files are mapped below. These are prepared repairs, not verified passes; Main explicitly owns all suite, build and browser execution.

| Frozen file | Cases | Prepared repair |
| --- | ---: | --- |
| EmergencyAccessLifecycleTest | 2 | Normalise explicit support collections to Eloquent collections in NotificationService before batched role loading. Retain the same recipient objects and role/user preference precedence. Assert the owner receives the manual-end notification and the ending actor does not. Add four-recipient preference regression and the missing historical fixture reason. No grant authority change. |
| MedicationAlertRoutingTest | 1 | Replace the obsolete AppEvent daily-report assertion with the current MedicationAlert ledger transport. Create a real previous NZ-day grant, assert current approved audit reviewers, deny revoked/unapproved and non-reviewer users, retain delivery-settings suppression, retries and empty-day coverage. Existing coordinator/clinical-lead audit permissions qualify them; no role permissions were added. |
| MedicationAlertSettingsTest | 3 | Include the released breakglass definition and default reviewer group. Add its missing real-notification preview and align the error sample with the current neutral summary. Share Settings saved rows and retain ceilings 110 and 33; SQL frequency diagnostics appear if the fixed ceiling still fails. |
| MedicationAlertsTest | 2 | Assert the neutral medication-error summary, canonical /emar/errors action and no person/medicine/free-text leakage, preserving discrepancy and recipient coverage. Keep unbuilt override suppression; explicitly cover the released emergency report and deduplication. |
| P11EventIntegrationTest | 1 | Compare every complete changes object with sorted object keys and strict values; retain change-list length, site/sequence/event counts, unchanged-save and stale-conflict coverage. |
| P11NavigationPayloadTest | 2 | Move page rule permissions to settingsCan. Shared can.medications survives for house/PIN-only and organisation-manager Settings responses. The real Rules renderer fixture uses the same production binding with opposite shared/page permission flags and read-only audit; three added cases cover add-rule admission and denial. Existing page manage/manage_global authority and readers are aligned. |

## Reviewable commits

- f65be7c3a38617cad2add3538be35235bc5508d8: explicit notification recipients, preferences, manual-end delivery and historical reason fixture.
- b053f76e271b749bc8299acf87f6e0888a663ab8: Settings navigation props, saved-row reuse and fresh-save/site/auditor/management regressions.
- This document's commit: released report/alert contracts, previews, strict JSON object comparisons, requested real Rules renderer permission fixtures and failure-manifest addendum.

Main's focused Rules renderer command after integration:

    node node_modules/vitest/vitest.mjs run resources/js/pages/emar/settings/settings-rules-concealed.test.tsx resources/js/pages/emar/settings/settings-navigation-render.test.tsx --maxWorkers=1

## Query investigation

The former page read organisation values twice (including the canonical emergency policy twice), organisation review row presence separately, and house values three times across values/reviews/alert people. The response now reads organisation rows once, emergency policy once and house rows once, then uses the same normalization and review helpers. This removes five duplicate database reads from that path. It introduces no persistent read or authorization cache. UserSiteAccessService already caches reviewer-site reads, so no extra gate cache was added.

The 110-query small-page ceiling and growth ceiling 33 are unchanged. Main must measure them with restored shared navigation permissions; no passing query count is claimed. The same store instance is tested before and after a real settings save to retain fresh value/review behavior, and existing house/auditor denials remain intact.

## Verification and remaining work

Thirteen changed PHP files passed php -l; the Settings page, production Rules binding and real Rules renderer fixtures passed scoped TSX syntax. git diff --check passed. The optional core-only formatting check was already false at the base and remains false; no unrelated formatting was applied. No suite, build, install, dependency cleanup or heavy queue was launched.

Main's focused backend command after integration:

    php artisan test --compact --filter="EmergencyAccessLifecycleTest|MedicationAlertRoutingTest|MedicationAlertSettingsTest|MedicationAlertsTest|MedicationSettingsStorageTest|MedicationSettingsSiteScopeTest|MedicationSettingsAuditorAccessTest|P11EventIntegrationTest|P11NavigationPayloadTest"

Remaining verification: Main's targeted backend run (including actual query counts and routing recipients), consolidated UI checks, production rebuild, and Settings browser navigation/edit authority. Restored shared navigation can have its own read cost; use the unchanged-budget test's SQL counts if further duplication remains. No additional source failure was confirmed by this static repair beyond the recorded collection, page-prop, missing preview and duplicated reads.

MedicineRuleSettingsTest, downtime/export classes, role grants, held grant/paper/offline expiry behavior and grant-policy source were not changed. Frozen 393/78 totals are retained until Main produces a new complete run.
