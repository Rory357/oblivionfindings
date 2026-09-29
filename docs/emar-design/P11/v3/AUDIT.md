# P11 v3 — gap audit (29 September 2026)

This carries forward the v2 audit (`../v2/AUDIT.md`) and adds what v3 changed and what the on-call roster design needs.

**Verified** means I read the code myself. **Reported** means it comes from the inventory agent and hasn't been re-checked.

## 1. Changed in v3

| Change | Why | Where |
|---|---|---|
| **Overview first in every view**: icon cards with a "Review …" button. | Stephan: "no structure … look at fleet" | Every view |
| **Grouped settings**: titled groups with an icon, a caption and compact rows. Replaces the stacks of identical cards. | Same | Every settings tab |
| **Alerts table** with In-app and Email switch columns, Goes to and Status. Row click opens *Who gets it* (WizardShell). | Stephan: "no in app or email similar to fleet"; Q6 "in app and email" | Alerts & access › Alerts |
| **Email starts off, marked not yet reviewed.** An alert with both channels off is caught before saving. | Stephan's answer | Alerts |
| **Delivery tab**, all interactive:<br>• re-alert until attended;<br>• what counts as attended;<br>• escalation (including to the on-call person);<br>• email summary and privacy;<br>• personal email copies;<br>• keep unattended alerts at the top of the bell;<br>• a "what happens if nobody attends" preview.<br>Plus a **Follow up** switch per alert. | Stephan: "why is it not interactive like … re-alert, attended"; the organisation sets channels; people can add email for themselves | Alerts & access › Delivery |
| **On-call follows the roster**: on-call shift, then the team lead on shift, then a backup chosen from employed staff. The phone number comes from the staff record. | Stephan: "a staff member employed … follow the on call … from rostering" | On-call contacts |
| **"On-call person (from the roster)"** is a recipient group on urgent alerts, off by default. | So after-hours alerts can reach the person on call | Who gets it › Groups |
| **Round time without a bordered box.** | Stephan: "the border over the time is an issue" | Round template wizard |
| **More than ordered** is stated as always being a medication error, not a setting. | Q4: follow P00 | Safety checks › Amount given |
| **Internal codes removed from screens.** | Plain language | All |

## 2. What building this design needs (additions to v2 §2)

| Area | Today in the code | This design | Build note |
|---|---|---|---|
| Re-alert and escalation | **Verified on origin/main:**<br>• each overdue-dose alert is sent once per dose (a `Cache::add` key per person, medicine and slot, `SendMedicationAlerts.php:101-111`);<br>• the refusal cluster reminds each lead once a day;<br>• `emar:send-alerts` runs every 15 minutes (`routes/console.php:710-714`);<br>• `MedicationDashboardAlert` has `acknowledged_by` / `acknowledged_at`;<br>• medication notifications are `database` only. | Re-alert every N minutes up to N times, until attended (opens / acknowledges / dealt with); escalate after N minutes to chosen groups. | Needs:<br>• per-alert attended state for notifications (not only dashboard alerts);<br>• a follow-up scheduler that runs within the 15-minute tick;<br>• an audit of each re-alert and escalation.<br>The minimum interval is 15 minutes unless the schedule changes. |
| Alert channels | Medication alerts are in-app notifications. Email is not configured for them. | In-app and Email switches per alert. People can add email copies for themselves. | Per-alert channel storage. The personal copies are in account › Notifications. |
| On-call contact | No field exists. | Follows the roster, with a team lead fallback and an employed backup. | Resolve at the moment an alert or screen needs it: on-call shift at the house → team lead on shift → backup. Store only the rule and the backup `user_id`, never a typed name or phone. |
| Rostering on-call | **Verified:** `app/Models/Shift.php` has `is_on_call` (boolean), `shift_type` (`standard`, `sleepover`, `on_call`, `split`, `travel`), `site_id`, `user_id`, `starts_at`, `ends_at`. `RbacSeeder` has a `team_lead` role. There is **no "on-call manager" field or role**.<br>Control Room › Settings offers `on_call_manager` as a queue "Assigned roles" option (`control-room/settings.tsx:191-197`), but no role with that name exists, so it matches nobody. | "Follow the roster" reads on-call shifts. "Team lead on shift" means a person with the `team_lead` role on a shift at the house at that time. | No Rostering change is needed if houses roster on-call shifts. If they don't, the backup person is used. See Q8. |
| Phone numbers | Staff records hold phone numbers (synthetic here). | Read-only in the dialog, "from their staff record". | Staff without a number can't be chosen as backup. |

## 3. Bugs found

The v2 audit started two fix sessions. Their status:

- **"Verify and fix medication alert routing faults": merged to main** (`origin/main` `88b7d3a3a`). It fixed:
  - the refusals alert querying `roles.slug`;
  - the repeating competency-expiring alert;
  - the emergency access report routing.
- **"Check Control Room medication signal recipients": fixed on a branch, not merged** (`36ee5e407` on `claude/bold-darwin-189d2f`). Seeded group names (`managers_core`, `coordinators` and others) and real role names now both resolve, Site-scoped.
  - **Still open (verified by that session):** Control Room triage queues offer five "Assigned roles" (`control-room/settings.tsx:191-197`, used for queue `assigned_roles` at :891-903). Four of them match no role or group: `control_room_operator`, `control_room_supervisor`, `site_manager` and `on_call_manager`. Only `clinical_lead` is real.
  - A queue set to those four roles sends its first notice, its escalation and its auto-assign to nobody. This is still true after the fix.
  - Nothing was mapped to `on_call_manager`, because on-call resolution from Rostering isn't built. With this design, it would become "the house's on-call contact", resolved from the roster. Flagged for Stephan.

The reversed dose window in `shift-medication-card.tsx` is in the v2 audit. Its fix is on `claude/objective-neumann-4aeb2f`, not merged.

## 4. Questions for Stephan

**Answered on 29 September:**

- **Q3:** Medication Settings owns who gets alerts.
- **Q4:** Follow P00.
- **Q6:** In app and email.

**Still open:**

- **Q1 (shared component):** Promote Fleet Settings' `Modal` and `Notice` to a shared component when P11 is built?
- **Q2 (two-answer choices):** "A lead countersigns" and "Who gets the follow-up" each have two answers that aren't on/off. Keep the two-button choice, or reword each as a switch?
- **Q5 (more settings?):** These values are fixed in code today:
  - stock expiry warning;
  - controlled-drug check overdue;
  - review due and INR;
  - as-needed 75 %;
  - syringe driver checks.

  **Recommendation:** make each one a setting in its own package (P06, P07, P05), not in P11.
- **Q7 (end-date warning):** 7 days or 14 days before a medicine's end date?
- **Q8 (team lead on shift):** "Team lead on shift" means someone with the Team lead role rostered on a shift at the house at that time. Is that right, or do you mean the house lead (who may not be on shift)? **Recommendation:** use the team lead on shift. The house lead already gets the decided alerts.
- **Q9 (on-call group by default):** "On-call person (from the roster)" is **off** by default on every alert, matching "email starts off". Should it start **on** for overdue doses and witness override requests, so the person on call hears about them after hours?
