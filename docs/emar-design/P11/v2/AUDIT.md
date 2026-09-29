# P11 v2 — gap audit (29 September 2026)

Stephan asked for an audit of the settings design "for gaps etc.". There were two passes:

1. A read-only inventory of what the medication module does today: every value, alert, recipient and settings screen, with file and line.
2. A review of this design against that inventory and against itself.

Audit agents over-report, so anything surprising was spot-checked. **Verified** means I read the code myself; **reported** means it comes from the inventory and hasn't been re-checked yet.

## 1. Changed in v2 because of this audit and Stephan's answers

| Change | Where |
|---|---|
| **Who gets alerts is now set by managers** (Stephan, 29 Sep; reverses answer 15). Each alert is a card with a switch per group. Your decided routing is locked on. Named people are added for every house through a picker in a Fleet Modal, and a house manager adds extra people for their own house. Changes go through Review changes and are recorded in the change history. | Alerts & access › Who gets alerts |
| **Five missing alerts added**: controlled-drug count doesn't match, as-needed dose over the limit, out of stock or expired stock, controlled-drug balance check overdue, medication review due. They exist today only as Control Room signals or dashboard tiles. | Who gets alerts |
| **On-call contacts are cards**, one per house, instead of a table. | Alerts & access › On-call contacts |
| **"Keep today's value"**: a default can be confirmed as reviewed without being changed. Before, a "Default — not yet reviewed" setting could only become reviewed by changing it. It is recorded in the change history. | Change history › Still to decide › ⋯ |
| Dose timing now says how it differs from a round template's window (5–120 minutes). | Rounds & timing › Dose timing |
| A note that Control Room also routes medication signals today (see Q3). | Who gets alerts |

## 2. What building this design needs (today vs design)

| Area | Today in the code | This design | Build note |
|---|---|---|---|
| Medication settings screen | `/emar/settings` has medicine rules and three safety options (`MedicationSettingsController`, `Settings.tsx`). There is no change history. | 5 views, tabs, drafts, review, history | New storage for most values. Every save must be audited. |
| Dose timing | Early/late/due soon come from `config/medications.php` with an `app_settings` override that nothing writes (`MarScheduleService.php:128-144`). Late-incident at 120 min and refusal escalation at 3 in 7 are hard-coded. | Settings, "Default — not yet reviewed" | The escalation is **defined twice with different counting** (`RefusalFollowUpController.php:104-107` counts refused + withheld; `SendMedicationAlerts.php:285-288` counts refused only). Unify on the setting. |
| Competency values | Pass mark 10/12 and 1 year are hard-coded (`EmarController.php:5479-5600`). The 30-day reminder is repeated in 4 places. | Settings | Read from one policy object. |
| Exemptions | The model, service and `medications.competency.exempt` permission exist, with **no route, no UI and no maximum** (reported). | Finite exemptions, 30-day longest | New routes and UI; enforce the maximum on the server. |
| Controlled-drug witness | Per medicine only (`witness_required`) | Organisation default, per house, overrides | New settings, plus the PIN-2 override work. |
| Witness PIN | Doesn't exist. The second checker types their login password (`ControlledMedicationTransportWitnessService.php:139-154`), and witness checks aren't rate-limited (reported). | Personal 6-digit PIN | Being built as PIN-1. |
| Emergency access policy | One global row, gated by **role** rather than a permission. Changes aren't audited and `auto_revoke` is hard-coded (reported). | Admins and provider managers; recorded | P10 |
| Alert recipients | Hard-coded in `SendMedicationAlerts` and friends. Control Room signal rules are the only editable routing. | Configurable per alert, organisation + house extras | New storage. See Q3 on who owns which alert. |
| On-call contact | No field exists | One per house | New field and the screens that show it. |
| Medicine photos | No field; only generic MAR attachments (10 MB) | Staff photos at receipt | P06 |
| Scope | The safety policy, MAR windows, break-glass policy and timezone are all single global values | Per house where the design says so | Per-house storage for witness, templates, on-call and alert extras. |
| Dashboard "overdue" | Looks back 3 h and counts a dose overdue **the moment its time passes**, ignoring the 60-minute late window. It may compare NZ dose times with UTC `now()` (reported, `MedicationAlertService.php:455-470`). | Late = the dose timing setting | Must read the same timing setting as Meds today. |

## 3. Bugs found (not design questions)

| Bug | Status | Where it goes |
|---|---|---|
| The repeated-refusals alert queries `roles.slug`, which doesn't exist, so it should throw whenever a cluster exists (`SendMedicationAlerts.php:296-306`; `roles` table has no `slug`). | **Verified** | The existing session "Verify and fix medication alert routing faults" |
| The competency-expiring alert has no de-duplication, so it repeats every 15 minutes for 30 days. | Reported | Same fix session |
| The emergency access daily report's routing key doesn't match its setting, so it goes to every manager role organisation-wide, including **HR and Finance**, and isn't scoped to houses. | Reported (routing key present in `config/notification_routing.php:74`; the report is built by `notifyCrud` in `SendDailyBreakGlassReport.php:44`) | Same fix session |
| Control Room recipient resolution matches role **names** literally (`ControlRoomNotificationService.php:~400`), but the seeded rules use group names `managers_core` and `coordinators`, so medication signals probably reach nobody. Missed and late doses are seeded with no recipients. | **Verified** (matching code and seeds) | New fix task: "Check Control Room medication signal recipients" |
| The shift medication card flags the window as −60/+30 minutes, the reverse of the server's 30 before / 60 after (`shift-medication-card.tsx:178-190`). | **Verified** | New fix task: "Fix reversed dose window in shift medication card" |
| `MedicationErrorNotification` is never sent; it appears only in a comment. | Reported | Covered by the new "Medication errors reported" alert card when built |
| The medicine end-date warning is 7 days in one place and 14 in another (`ClientMedication.php:438` vs `MedicationAlertService.php:717`). | Reported | Q7 |
| Stock-low alerts share a timestamp with the 6:00 am stock check, which can silence them for 24 hours. | Reported | Noted on the "Stock running low" card; fix with P06 |

## 4. Questions for Stephan

- **Q1 (shared component):** Promote Fleet Settings' `Modal` and `Notice` to a shared component when P11 is built?
- **Q2 (two-answer choices):** "When a lead countersigns" and "Who gets the follow-up" each have two answers that aren't on/off. Keep the two-button choice, or reword each as a switch?
- **Q3 (one owner per alert):** Control Room › Settings already routes some medication signals: missed or late doses, discrepancies, stock. Should Medication › Settings › Who gets alerts own all medication alerts, with Control Room showing them? Or should each alert live in one place with a link from the other? **Recommendation:** Medication settings owns who is told, and Control Room keeps its incident queue.
- **Q4 (more than ordered):** Today the code only warns when a dose is more than 20 % above the order (`MedicationSafetyService.php:553`). The approved P00 contract says any amount more than ordered is recorded as a medication error. **Recommendation:** follow P00 and retire the 20 % warning.
- **Q5 (more settings?):** These values are fixed in code today:
  - stock expiry warning, 30 and 7 days;
  - controlled-drug balance check overdue, 7 days;
  - review due, 7 days, and INR, 3 days;
  - as-needed "nearly at the limit", 75 %;
  - syringe driver checks, every 4 hours.

  **Recommendation:** make each one a setting in its own package (P06 stock, P07 controlled drugs, P05 reviews), not in P11, each shown as "Default — not yet reviewed".
- **Q6 (after hours):** Alerts are in-app only. The on-call contact is a phone number shown on screens; nobody is actually alerted after hours. Add email or push for chosen alerts, or escalation to the on-call person when an alert isn't acknowledged?
- **Q7 (end-date warning):** 7 days or 14 days before a medicine's end date?
