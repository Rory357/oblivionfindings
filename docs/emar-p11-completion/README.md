# P11 completion package

This package completes the recovered P11 B2 C6 alert-log draft, adds C7's approved medication-only bell ordering, and builds B3 emergency-policy settings. It uses the approved P11 v5 source at `12ecb24a2` (approval `cd0db835f`) and P10 v1 at `1c758eabc27c3e7844912432ba7e5e7ac4dbaab7`.

## Recovery and ownership

- Own managed checkout: `C:/Users/steph/.codex/worktrees/emar-p11-settings/oblivionfindings`, branch `codex/emar-p11-settings`, original base `9747cf7cb654c2ef441e8f60c7ea1b5918081925`.
- Recovered only the assigned C6 draft: `PruneTimelineAndAuditLogs`, `MedicationSettingsController`, and the untracked `MedicationAlertLog`. The source checkout is read-only; its three SHA256 hashes still match `recovery-hashes.txt`.
- P10 dependency: original commit `2a11723e54e95c280b261841bf1b22098d3359c1`, cherry-picked locally as `145e9adb3`. Main should integrate this prerequisite once. It owns `BreakGlassPolicy.second_person`, `review_days`, the migration, and `snapshot()`.
- Dependencies are a physical vendor copy, with app and TestCase reflection resolving to this checkout (`runtime-proof.txt`). No node_modules junction was created. Small pure frontend tests use the existing primary dependency runtime and this checkout's source; primary source remains unchanged.
- Main owns integration, combined frontend tests/build and real-browser verification. No push, deployment, live policy save, or live retention run occurred here.

## Resulting behavior

### Alert log

- Initial Settings response returns summary counts and a null log. `log=1` loads 25 rows per page; default is the last 30 days plus still-open alerts. All retained history is an explicit option, with no silent 500-row cap.
- Site and current canonical person access filter both SQL counts and rows. Forged house filters narrow to zero; moved or no-longer-readable people disappear before counting.
- Controlled alerts retain the approved generic site/timing/status shell. Title/type, medicine/person detail, recipients/channels, attendee, source link and event history are concealed. Secret terms do not influence search or counts.
- Delivered recipients are distinguished from held recipients; event IDs and source alert IDs remain canonical. Source links must be internal paths. Older years are visible in retained-history dates.
- Filters/search and pagination preserve the owning Settings draft. Existing loading skeleton, empty, failure/retry, row-menu and SettingsModal patterns are reused.
- Retention prunes only fully dealt-with, closed alerts whose raise AND closure dates are past the audit cutoff. Still-open and recently closed alerts survive. Dry-run reports without deleting; children cascade only with the removed alert.

### Delivery and on-call

- `delivery.pin_unattended` is off and unreviewed by default. On, it orders existing visible notifications BEFORE the bell limit, pinning only medication follow-up alerts still open and not shared-attended. Ordinary and attended alerts retain chronological ordering.
- Work phone remains first. Personal cellphone is available through OnCallResolver only after the account owner consents in account Profile. Consent can be withdrawn, partial profile forms preserve it, changes are audited atomically, and the timestamp is hidden from general User serialization. HR work contacts are not mirrored or changed.

### Emergency policy

- `ea` is an organisation-only group in Alerts & access. Existing runtime `BreakGlassPolicy` remains canonical; AppSetting entries mark review status, not duplicate operational policy authority.
- Save/keep/restore use the existing draft, revision lock, conflict, history and audit mechanisms. Duration relationships are checked under the write lock. Dedicated `medications.emergency_policy.manage` AND existing all-sites authority are required for policy mutations and rechecked against the locked current actor.
- The dedicated permission is installed/granted to existing admin/provider-manager roles through a deployment migration, not seeders. Normal Settings read authority remains required to open the Settings hub.
- Grant/extension/maximum minutes and repeat-use settings use canonical defaults. The worked example uses the current draft. Turning off a required reason, lengthening access, reducing second-person checks, or lengthening the review deadline is classified as loosening and requires confirmation.
- P10 additions are `second_person=off|optional|required` (default optional), `review_days=1|2|3` (default 2). P10 owns per-grant snapshot/enforcement and grant/review/report workflows; the UI reads these definitions and uses the same save/history path.

## Shared seams and contracts

`MedicationSettingsRegistry`, `MedicationSettingsStore`, `MedicationSettingsController`, and `pages/emar/settings/*` remain P11-owned until this package is integrated. Other package owners should supply narrow new setting-group/editor fragments; Main/P11 applies each registration once. A group declares `key`, view, effect, audit event and complete MedicationSettingDefinitions (canonical defaults, storage keys, accepted values, numeric `direction/off/off_is_loosest` or option rank, scope and section). The editor consumes `useRow(group)` and `SettingsContext` for drafts, authority, reviewed/default state, errors, navigation and review dialogs. Do not independently replace whole shared files.

B3 contract: group `ea`; storage markers `medications.emergency_policy.{canonical_field}`; dedicated permission `medications.emergency_policy.manage`; `emergencyPolicyAccess` response flag; adapter `EmergencyAccessPolicySettings::values($lock)` / `write($key,$value)`; canonical fields from `BreakGlassPolicy::defaults()`. P10 can use this contract to adapt its legacy policy endpoint to the same audited writer.

Alert log contract: `alertLogSummary={recent,open}`; `alertLog=null|Laravel pagination payload`; query `log=1`, `log_house`, `log_show=all|open|attended|afterhours`, `log_range=recent|all`, `log_q` (max150), `alert_page`. Concealed rows have no sensitive fields. `LaravelPagination.only` is an optional shared primitive addition; other callers are unchanged.

Bell seam is one ordering call in HandleInertiaRequests. Consent seams are the account owner's Profile request/controller/UI, a nullable hidden User timestamp, and OnCallResolver. No global notification restyle or new recipient authority was added.

## Verification

- First completion run: 1 genuine failure (missing numeric `off` metadata), fixed; 7 environment warnings; 50 assertions. See `p11-completion-php.log`.
- Pure Settings model: 11 tests passed in `p11-model-unit.log`, including simultaneous policy duration drafts and Alerts-view confirmation behavior.
- Scoped PHP syntax: 15 files passed (`php-lint.log`). Frontend syntax checked for the scoped changed TS/TSX files; this is not a combined typecheck/build or browser verification.
- Initial broad focused run: 104 cases had no assertion failures and one bell-order case failed (1347 assertions total). The failure showed that the notification relation already applies date ordering; the corrected pinning path now clears that ordering before applying the priority, with the caller adding date ordering afterward. The environment warnings came from the missing local testing environment file, which has been supplied.
- Alert-log lifecycle and pure Settings model: 15 tests passed across two files (`p11-alert-log-ui-unit.log`), covering supplied-page pagination, partial reloads, stale success/error cancellation, retry with draft preservation, and concealed-row defence.
- P09 event integration is included in this follow-up candidate. Original recorder dependency `466ce69df138cf20faac4ace81cdc26da1f03b2e` was cherry-picked locally as `fb540af09`; Main should integrate the original prerequisite once. Saves/keep and on-call changes append last inside their five-attempt transaction, use canonical subjects and omit contact/value secrets. Tests cover per-Site fan-out, unchanged/stale requests, and full rollback.
- Corrected focused functional regressions are queued through the existing machine-wide FIFO wrapper. An earlier attempt stopped before executing tests because the runner rejected `--no-ansi`; this candidate is not yet backend-verified. Final results will be recorded separately.

## Integration dependencies / remaining verification

- P10 owns the legacy policy endpoint, actual reviewer/report recipient configuration, daily-report command, per-grant snapshot enforcement, and `/emar/emergency-access?view=review`. Main assigned these to P10; this package has not claimed them tested.
- The daily-report recipient control shows `Not configured` until the canonical `alerts.breakglass` definition exists. It does not imply a configurable recipient path already works.
- Automatic approval review rejected the earlier combined edit to those P10-owned operational files because it crossed the reserved ownership boundary. Those edits were not applied or bypassed. Main subsequently assigned their independent review to P10.
- No witness-eligibility tab/meter was added: the approved amendment deliberately moved it to P07b. The real existing staff PIN status remains, and StaffEligibility regressions are included.
- Main still needs combined frontend type/tests/build and approved desktop/200% real-browser verification of these final integrated changes. This package is not a claim that all eMAR pages are complete.
