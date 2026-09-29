# P11 v4 — Medication settings and staff eligibility

A synthetic design preview for Stephan and the review session. It is design only: no application code, routes, schema or seeders are changed. `DESIGN.md` and `design_styles/*` stay read-only.

v4 starts from v3 as Main inspected it (`4e8540e35`, frozen in `../v3/`). It has everything v3 has, plus what Stephan asked for on 29 September 2026.

## Why v4

Stephan didn't approve v3 yet. He asked "what do you think we can still improve add/gaps?", and then chose seven additions from the gaps I listed. He also answered three open questions.

The gap review found one error in v3: the In-app group said alerts reach "the bell and the Tasks inbox". Medication alerts reach the bell only. All Tasks shows medication errors and controlled-drug loss reports as records, through `MedicationErrorProvider` and `CdLossReportProvider`. The wording is fixed.

## Stephan's answers and choices (29 September 2026)

| # | Decision | Where |
|---|---|---|
| Q8 | "Team lead on shift" means someone with the Team lead role rostered on a shift at that house at the time. | On-call rule |
| Q9 | The on-call person gets no alerts by default. It stays off until a manager turns it on for an alert. | Who gets it › Groups |
| Q10 | The on-call number is the work phone, else the personal cellphone. The cellphone is only used if the person agreed (see Cellphone consent). | On-call contacts |
| 1 | **Push**: a third channel, next to In-app and Email. | Alerts table, Delivery |
| 2 | **Alert log**: every alert, who was told, how, and who attended. | Alerts & access › Alert log |
| 3 | **Quiet hours**: non-urgent alerts can wait until morning. | Delivery › Quiet hours |
| 4 | **Who can't be reached**: gaps in contact details, push set-up and leave. | Delivery, and the Overview |
| 5 | **Review the defaults in one go**: a walkthrough of Still to decide. | Change history › Still to decide |
| 6 | **Restore from history**: put an earlier value back into the draft. | Change history › All changes |
| 7 | **Cellphone consent**: a person agrees before their cellphone is shown for on-call. | On-call dialog, and the account preview |

## What's new in v4

- **Push** (`1440-set-alerts-alerts-push.png`).
  - Each alert gets a Push switch, which starts off.
  - The Delivery tab gains a Push group: alert types sent by push, and staff with push set up (5 of 7).
  - The app already sends push to the phone app and browsers (`app/Notifications/Channels/PushChannel.php`), and each person has a push preference (`ShiftTaskDueNotification.php:40-52`).
  - "Keep client names and medicines out of email and push" now covers push, because push shows on lock screens.
- **Alert log** (`1440-set-alerts-log.png`, `1440-dlg-alertlog-*.png`).
  - An EntityTable of alerts with their status: not attended (with how long), attended by, or dealt with.
  - Filters by house, and by not attended, attended or after hours.
  - Clicking a row shows what happened as a timeline.
  - For an after-hours alert that nobody else heard about, the timeline says so and links to Delivery & follow-up.
  - The rows are synthetic examples that match today's settings, so there are no re-alerts or escalations in them.
- **Quiet hours**.
  - A switch, then **From** and **Until** times using the approved PKG-01 TimePicker. No times are set until someone chooses them (`1440-set-alerts-delivery-quiet-error.png`).
  - Email and push for alerts without Follow up wait until the end of quiet hours. They still show in the bell straight away.
  - Alerts with Follow up are never held.
- **Who can't be reached** (under Delivery, plus an Overview card).
  - It lists people with no work email, push not set up, no usable phone, or approved leave from the Leave hub.
  - For each gap it says why it matters now, or "Not needed yet". For example, a missing work email only matters once email is on for their alerts.
  - Clicking a row shows who fixes the gap.
  - The on-call preview uses leave too. If the backup is away, that night shows "Nobody — Hana Kereama is on leave" (`1440-dlg-oncall-set.png`).
- **Review the defaults one by one** (`1440-dlg-reviewdefaults-*.png`).
  - A WizardShell with one step per view. Each step has Keep switches and a "Keep all on this step" button, then a Review step.
  - Applying marks each kept value reviewed and records it, exactly as "Keep today's value" does.
  - Values that aren't configured, or that the person can't change, link to their tab instead of offering Keep.
- **Put the earlier value back** (`1440-dlg-restore-confirm.png`, `1440-set-rules-safety-restored.png`).
  - Available from a change's ⋯ menu, right-click, or its detail view.
  - It asks first, and warns when the change undoes a recorded decision. The value goes into the draft, on the setting's own tab, and nothing changes until it's reviewed and saved.
  - Auditors don't get this action.
- **Cellphone consent (Q10)**.
  - The dropdown lists someone whose cellphone isn't shared, but they can't be chosen ("they haven't agreed to show their cellphone").
  - An agreed cellphone is labelled "(personal cellphone)", with the date they agreed.
  - "What staff see in their account" shows the account switch (`1440-dlg-consent.png`).

## Run it

The preview needs `node_modules` in the worktree. Here that is a **junction** to the main checkout's folder, so:

- never run `npm install` or `npm ci` inside it;
- delete the junction link before this worktree is removed.

From the worktree root, build:

```bash
node node_modules/vite/bin/vite.js build --config docs/emar-design/P11/v4/vite.config.mjs
```

Then serve:

```bash
node docs/emar-design/P11/v4/serve.mjs
```

Open http://127.0.0.1:4375/. The **Preview guide** (`#/guide`) has a "v4 additions" row that links every new state.

## What's in it

| View | Tabs |
|---|---|
| Medication rules | Overview · Medicine rules · Safety checks · Controlled drugs · Medicine photos |
| Rounds & timing | Overview · Round templates · Dose timing |
| Staff & PINs | Overview · Competency · Exemption limit · Witness PINs · PIN status |
| Alerts & access | Overview · Alerts · Delivery · On-call contacts · Emergency access · **Alert log** |
| Change history | Still to decide · All changes |

Safety & oversight › Staff eligibility and Meds today › My eligibility are unchanged from v3.

## Evidence

- **`CHECKLIST.md`** has pass/fail and evidence for every item.
- **`AUDIT.md`** holds the gap audit, the build needs and the open questions.
- **`reuse-check.mjs`** runs from the worktree root:

```bash
node docs/emar-design/P11/v4/reuse-check.mjs
```

- **`screenshots/`** has 194 files, and **`VERSION.txt`** has the SHA-256 of every file.
