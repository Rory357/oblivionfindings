# eMAR repair pass and re-audit — 4 October 2026

Status: the scoped audit repairs are implemented and locally verified for draft PR #16. Complete CI and cross-module clinical acceptance remain release gates; no production deployment has occurred.

This report follows the critical audit of `7318603c00db31c1a2d859af5c20e94d91897569`. Frontend changes were completed by Main; bounded backend repairs and independent review were delegated to GPT-6.1 Sol Extra high. The application remains one operating organisation, with approved houses, exact role permissions and canonical person/record ownership.

## What changed

### Rory headers, navigation and phone use

- The shared hero uses the connected 40 px active tab and 34 px inactive tabs. A separate 44 px interaction area preserves touch access. Both the global frontline minimum-height rule and the Orders-specific override were corrected.
- On the worker phone board, identity, current task and urgent state remain visible. Details and filters expand explicitly. The measured hero dropped from 614 px in the original audit to 281 px at a 390 × 840 CSS-pixel viewport, with no horizontal document overflow.
- Clock in is the clear header action when a shift exists but the worker has not clocked in. The current person MAR uses the shared white primary action.
- House, person and valid day context survive hub changes. Pharmacy orders are an explicit stock-hub destination. Low/expiring/expired stock views no longer reset to All stock; order views hide irrelevant stock chips. Empty filtered stock offers Show all stock instead of implying that existing stock needs ordering.
- Pharmacy-order and controlled-stock searches now filter their own visible records, with accurate search labels and empty-result messages.
- The phone re-audit found duplicate Ask about a client triggers in the shared app bar when its icon variant was used. The duplicate compact trigger is suppressed for that variant; the same dialog remains available at every width.
- Settings and record sections retain the shared bare secondary-tab strip. Safety no longer repeats the same KPI cards beneath its hero. The action-centre selection is labelled as items shown, not a complete open-work total.

### Dialogs and recovery

- Medication dialogs use the shared simple-dialog or multi-step wizard shell. Dirty close/Cancel paths ask whether to keep editing; submitting blocks closure and step changes. Server errors remain visible in a focused summary.
- Receipt drafts retain quantity, batch, notes and chosen expiry through cancelled closure. Shared date controls replace 21 native modal date fields; the remaining syringe commencement field uses the shared date-and-time control with explicit Pacific/Auckland context.
- Stock creation, receipt, delivery, count and disposal flows show a truthful confirmed-versus-queued result. Confirmed disposal refreshes the visible register.
- The live Schedule review and INR wizards now show saved confirmation, field-based completeness, and return to the relevant input step after validation errors.

### Follow-ups and reporting

- Worker, oversight and Tasks readers expose unresolved legacy effect checks without creating records on a GET. Canonical work and legacy work are not double-counted. Unknown check times and owners stay explicit; no default deadline is invented.
- At the inspected preview scope the worker board and oversight both showed 10 open follow-ups. Oversight labelled all 10 unknown check times. The legacy list has an explicit 25-item display limit and total.
- View-only Task links open filtered oversight rather than attempting a preparation write. Record-capable users retain the stable administration deep link.
- Shared administration deep links resolve an authorised canonical follow-up outside the current page through a read-only lookup. Only a selected legacy row with server-granted preparation permission mounts the preparation adapter; view-only, foreign and concealed records do not trigger that POST.
- A bounded preview/import command remains available for deliberate preparation of legacy work. Opening a check does not record an outcome. Other consumers that require a canonical workflow identity acquire legacy items after authorised preparation/import.
- Report person actions use the authorised canonical MAR destination; a browser check reached the selected person and report day rather than a 404. Report-only actors do not receive a MAR link they cannot open. Medication report builder now identifies itself as Medication reports and returns to Reports & audit.

### Controlled medicines and audit integrity

- Voiding a destruction records an annotation and does not put physically destroyed medicine back into stock. A separate physical reconciliation remains a separate governed action.
- A nullable, restrictive administration link records new controlled-dose evidence. Historical identities are not guessed or backfilled. Populated evidence prevents a destructive migration rollback; soft-deleted administration identity remains readable through the evidence relation.
- Retries revalidate the current recorder, person, presence and witness requirements before reporting success. Matching durable receipts still prevent duplicate effects. A historical residual-stock count to zero can replay its original receipt; a new empty-history count remains denied.
- Ordinary disposal and history access are restored behind their exact permissions. Linked controlled medicines cannot be probed or voided by an ordinary-only actor, including legacy snapshot cases. Form and strength snapshots come from the canonical medicine.
- Register, alerts and oversight use the same roster/count policy and witnessed count evidence. Zero-balance historical medicines are retained in history without becoming due-count work. Positive residual stock can be counted without granting new administration or other stock authority on a retired order.
- Editing a legacy order with an unknown original creator records the current snapshot actor separately, preserving the original creator as unknown. Existing immutable versions are not rewritten.

## Navigation retained

The seven role-aware left-navigation hubs remain:

1. **Meds today:** Schedule, Rounds, As-needed, Follow-ups, Stock alerts, Activity and Controlled checks.
2. **MAR & medicines:** people’s medication records, medicines and support/self-administration; a person record contains Chart, Medicines, Support plan, Allergies & alerts, Clinical and History.
3. **Orders & reviews:** Orders, To check, Covert giving, Reconciliation and Medication reviews.
4. **Stock & controlled drugs:** Stock, Pharmacy orders, Discrepancies, Controlled register, Loss reports and Destructions & returns.
5. **Safety & oversight:** Overview, Follow-ups, Medication errors, Handovers, Staff eligibility, Witness overrides and Emergency access.
6. **Reports & audit:** Standard reports, Report builder, Audit trail and Print & exports.
7. **Settings:** Medication rules, Rounds & timing, Staff & PINs, Alerts & access and Change history, with the existing secondary sections.

Visibility follows existing capabilities. Consolidating navigation does not grant the underlying action.

## Verification

- Full frontend suite: **524 files, 3,579 tests passed**. The first full run had one asynchronous IT-focus assertion failure; it passed alone, and the assertion now waits for the existing focus effect without removing the focus requirement. The full rerun passed. Five subsequent follow-up deep-link UI regressions passed, including view-only, different-dose, concealed, permitted legacy and off-page canonical cases.
- Earlier affected interface suite: **56 files, 433 tests passed**; subsequent INR saved-confirmation regression: **2 tests passed**. These overlap the full run and must not be added to its total.
- Full application-source ESLint passed. The initial unrestricted scan found an old lint error only in an ignored archived preview copy under `storage/app`; the successful source scan excluded `storage/**`. Final changed-file lint also passed.
- Full TypeScript passed. Final production build passed in **5m48s**. The browser loaded **`app-Ba2cAUFe.js`**, matching the final manifest; the phone remained 390 × 840 with a 281 px hero, no horizontal overflow and exactly one visible Ask about a client trigger. The final stock view hides filters that do not apply to controlled reconciliation. The normal preview size and Meds today page were restored afterwards. The final duplicate-trigger cleanup was checked by type/lint/build and this actual phone inspection after the full frontend suite.
- Medication order workflow: **38 tests, 794 assertions passed**, including the unknown-original-creator cases.
- Follow-up/workers/reporting/Tasks: **92 distinct cases passed** across the relevant full-file and corrective runs (88 earlier cases plus four new direct-link cases). Reporting alone passed all 21 cases; the Task read-only boundary file passed 2 cases / 39 assertions.
- Controlled latest-result union: **158 distinct cases passed** — ControlledProduct 37, ControlledDrugs 27, ControlledDoseOverride 15, ControlledProductReadFilters 7, Destructions 16, MedicationGovernanceAuthorization 42 and ControlledPolicy 14. This is a union of overlapping full-file and narrow corrective runs, not one new monolithic run. The final confirmation passed **20 cases / 266 assertions**, including injected incident failure/rollback, historical count-to-zero provenance and privacy, exact replay, current presence and witness checks, and count policy.
- The authoritative final combined run passed **61/61 cases / 1,103 assertions in 444.75s**: readiness 3/81 assertions, full RBAC 28/704, full Stock 25/258 and five direct-link cases/60. This supersedes the preceding 11 failed cases. Negative authority, no-effects, witness-revocation, replay and strict-audit rollback assertions remain; fixtures now provide the valid presence/witness/request evidence needed to reach those assertions.
- The readiness cases prove historical synthetic creation/check chronology, test-clock restoration, the actual Morning due row, overdue/badge projection after the configured window, real controlled PRN and scheduled recording followed by dependency-safe reset, and preservation of foreign records, immutable checked sources and event chains. Existing sources are not rewound. Browser e2e selectors/setup were updated, but those automated browser journeys have not been executed locally in this repair pass; fresh CI must establish their result.
- All 22 controlled PHP files passed syntax checks. The controller passed Pint after its scoped import correction. The remaining full-file style failure is `routes/emar.php`; an isolated committed-parent copy produced the same import diagnostics, proving they precede the one-line middleware change. The large route file was not reformatted wholesale. New files and scoped owned changes were formatted. Source whitespace passed.
- Final syntax scan of **all 55 changed PHP files passed**, including new files. Final changed frontend formatting and source whitespace checks passed.
- Earlier full architecture run: 183 passed and one navigation-contract failure; the corrected affected architecture files subsequently passed all 11 cases. These overlapping runs are not one new monolithic suite.

Local logs are under ignored `storage/logs/emar-*`. Browser evidence is under `C:/Users/steph/.codex/visualizations/2026/09/28/01a0e70d-f675-77b2-bbaf-2394094c898c/emar-fixes-20261004/` (phone header, receipt draft/calendar, person-scope handoff and follow-up oversight).

The final browser check also confirmed the Orders-specific override is gone: active connected tab **40 px**, inactive tabs **34 px**. Show all stock restored the three filtered-out stock rows while retaining person 10, and Pharmacy orders retained that person while hiding irrelevant stock-view, controlled-only and cold-chain controls. These are synthetic local observations, not production clinical actions.

## What is still wrong or not proven

- **Weekly controlled-count reminders are unavailable.** A stored weekly choice has no reviewed day/shift anchor. The option, warning and review effect state that limitation. No weekday or overdue threshold has been invented. Roster-based supported cadences retain their existing policy.
- **Legacy follow-up preparation is still a rollout task.** The read-only queues expose old work immediately, but canonical-only handover/person consumers require authorised preparation or the reviewed import. No operational import was performed.
- **Release-wide checks are not replaced by these passing subsets.** The previous commit had 14 failed checks out of 15. The new candidate needs fresh complete CI and the full cross-module browser journey matrix, including offline recovery, exports, emergency access and paper reconciliation. Prior failures outside eMAR must be classified from fresh evidence.
- **Deployment requires the reviewed migration before the linked writer.** The new nullable administration foreign key must exist before deploying the controlled-dose writer. Existing unknown links remain null; rollback refuses to discard populated evidence. This pass applied the migration only in isolated test schemas.
- **Existing withheld capability expansions remain separate.** This pass does not grant unassigned-round recording, new frontline stock permissions, administration on retired orders, historical paper-to-clinical posting or entered-in-error writer authority. Original broad P07 packages were not imported; current targeted integrity repairs have their own source changes and regression evidence.
- **Local setup is not ready for real medication work.** The inspected synthetic preview contains unchecked orders, missing on-call contacts and deliberately ineligible/off-shift actors. Those states are shown rather than bypassed.

No operational medication outcome, stock movement, permission change, migration, main-branch merge or deployment was performed in the browser audit. Saved browser form values were synthetic unsaved drafts and were explicitly discarded.
