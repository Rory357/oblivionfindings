# P11 v4 — gap audit (29 September 2026)

This carries forward `../v3/AUDIT.md` (and through it `../v2/AUDIT.md`), and adds what v4 changed and what building it needs.

**Verified** means I read the code on `origin/main` myself.

## 1. Changed in v4

| Change | Why |
|---|---|
| Push channel per alert, plus a Push group in Delivery. | Stephan chose it. Push reaches the on-call person after hours much better than email does. |
| Alert log tab, plus an Overview card. | Stephan chose it. It gives evidence of who was told and who attended, for audits and incident reviews. |
| Quiet hours (From and Until). | Stephan chose it. Non-urgent alerts can wait until morning; alerts with Follow up never wait. |
| Who can't be reached, under Delivery, plus an Overview card. The on-call preview now uses leave. | Stephan chose it. |
| Review the defaults one by one. | Stephan chose it. Still to decide has 44–45 items. |
| Put the earlier value back from Change history. | Stephan chose it. |
| Cellphone consent for on-call. | Stephan chose it, following from his Q10 answer. |
| The privacy switch covers email **and push**. | Push text shows on lock screens. |
| In-app wording: "The bell (notifications)", not "the bell and the Tasks inbox". | That claim was wrong (see §2). |

## 2. What building this needs (verified on origin/main)

| Area | Today | This design | Build note |
|---|---|---|---|
| Push | `app/Notifications/Channels/PushChannel.php` sends to Expo (phone app) and web push subscriptions. `ShiftTaskDueNotification.php:40-52` picks database, mail and push from a per-person preference (`channel_push`). Medication notifications use `database` only. | Push switch per alert, off by default. | Add the push channel to the medication notifications, with a `toPush` payload that leaves out client names and medicines when the privacy switch is on. |
| In-app | Medication alerts are database notifications, shown in the bell. All Tasks has `MedicationErrorProvider` and `CdLossReportProvider` (`app/Services/Tasks/Providers`), but nothing for alerts. | "The bell (notifications)". | No change. |
| Alert log | No eMAR screen shows who was told about an alert or who attended it. I searched `resources/js/pages/emar` on main: acknowledgement fields appear only for handovers and competency. `MedicationDashboardAlert` has `acknowledged_by` / `acknowledged_at`. | A log of alerts with recipients, channels, re-alerts, escalations, attended-by and dealt-with. | Record each alert, recipient, channel and attended event. This extends the follow-up work in v3 §2. |
| Quiet hours | Not present. | Hold email and push for alerts without Follow up until quiet hours end. | The scheduler releases held alerts at the end of quiet hours. It is organisation-wide in this design. |
| Who can't be reached | `User` has `work_phone` and `cellphone` (`app/Models/User.php:50-51`). Push subscriptions exist per person. Approved leave is in the Leave hub. | A list of contact gaps, with why each matters now. | Read-only checks across users, push subscriptions, leave and the draft settings. |
| Cellphone consent | No consent field. | "Show my personal cellphone when I'm on call" in the person's account, with the date they agreed. | A new per-person preference, recorded when it changes. The on-call resolver uses `work_phone`, else `cellphone` only with consent (Q10). |
| Restore from history | Change history (this design) records before and after text. | Put the earlier value back into the draft. | Store the structured earlier value with each saved change. |
| Review the defaults | "Keep today's value" one at a time (v2). | A walkthrough over Still to decide. | The same audited action as "Keep today's value", in a batch. |
| "Attended" (Main's build note) | The bell's database notifications only have a per-user `read_at`. `MedicationDashboardAlert` holds `acknowledged_by` / `acknowledged_at`. | Re-alerts and escalation stop once the alert is attended (opened, acknowledged or dealt with). | The build needs **one "attended" record per alert, shared by every recipient**. When anyone attends, re-alerts stop for everyone, and the alert log shows who attended. |
| Re-alerts after escalation | — | The preview says re-alerts after an escalation go to **everyone told so far**: the first people plus the escalated groups. A re-alert and an escalation at the same moment are one step. | The follow-up scheduler keeps a growing recipient list per alert. |
| Keep unattended alerts at the top of the bell (Main's build note) | The bell is shared by the whole app. | A switch in Delivery › In-app, off by default. | This changes a **shared component**, the app-wide bell, so it needs Stephan's OK (Q13). |

## 3. Bugs found

These are unchanged from v3 §3:

- the alert routing fix is on main (`88b7d3a3a`);
- the Control Room recipients fix is on a branch, not merged (`36ee5e407`);
- four Control Room queue roles match no role (`control_room_operator`, `control_room_supervisor`, `site_manager`, `on_call_manager`);
- the reversed dose window fix is on a branch, not merged.

## 4. Questions for Stephan

**Answered on 29 September:**
- **Q3:** Medication Settings owns alerts.
- **Q4:** follow P00.
- **Q6:** in-app and email, and now push too.
- **Q8:** the team lead role, on shift at the house.
- **Q9:** the on-call person's alerts stay off until a manager turns them on.
- **Q10:** work phone, else cellphone. v4 adds consent for the cellphone.

**Still open:**
- **Q1 (shared component):** promote Fleet Settings' `Modal` and `Notice` to a shared component when P11 is built?
- **Q2 (two-answer choices):** keep Segmented for "A lead countersigns" and "Who gets the follow-up", or reword each as a switch?
- **Q5 (more settings?):** stock expiry, controlled-drug check overdue, review due and INR, as-needed 75 %, syringe driver checks. **Recommendation:** make each a setting in its own package (P06, P07 or P05).
- **Q7 (end-date warning):** 7 days or 14 days?
- **Q11 (alert log retention):** how long is the alert log kept? **Recommendation:** the same as the audit log, because it is evidence of who was told.
- **Q12 (quiet hours scope):** quiet hours are organisation-wide in this design. Should a house be able to set its own? **Recommendation:** organisation-wide for now, and add per-house times only if a house asks.
- **Q13 (shared bell):** "Keep unattended alerts at the top of the bell" changes the app-wide bell. OK to change that shared component when P11 is built, or should the switch be dropped? **Recommendation:** keep it off by default, and build it with the bell's owner.

## 5. Main's inspection of v4: build notes

- **Loosening a check.** The build should use one rule for every save and every restore, like `loosens()` in the preview: a change that turns a check off or makes it less strict needs a destructive confirmation and is labelled in the change history.
- **Controlled-medicine alerts.** They reach only people with controlled-medicine access, and their details (what, who was told, what happened) stay hidden in the alert log from anyone without it. This applies EM-12 to the log as well as to delivery.
- **Shared ConfirmDialog bug (found here, verified).** `AlertDialogAction` always adds `btn-soft-primary`. Its unlayered `background: linear-gradient(...)` (`resources/css/app.css:575-578`) beats `bg-destructive`, so every destructive ConfirmDialog in the app shows a purple button. This is raised as a separate fix task (a shared-component change).
