# P11 v5 — design-rules checklist (self-check)

Checked on 30 September 2026 against `docs/emar-audit-2026-09-28/claude-second-review/Mockup-design-rules-checklist.md`.

Everything v4 passed still passes (`../v4/CHECKLIST.md`, which includes Main's inspections). This file covers what v5 adds, and the evidence re-run on the whole preview.

## Evidence tools (re-run on v5)

- **`reuse-check.mjs`**: all checks pass. That covers P00 wording, the real app modules, no shared file changed, tokens only, no browser dialogs or network calls, and buttons that are never restyled.
- **Route sweep**: 278 routes × 1440, 1280 and 200 % zoom. **0 problems.**
- **Interaction run**: 158 checks in headless Chromium at 1440. **158 of 158 pass, with 0 console errors.** 22 checks are new for v5.
- **Truncation check**: **0 at 1440, 0 at 1280.**
- **Type check** (`tsc --noEmit`): **0 errors.**
- **Screenshots**: 222 in `screenshots/`.

## Stephan's v5 choices

| What Stephan asked for | Status | Evidence |
|---|---|---|
| Q12: each house sets its own quiet hours | Pass | The organisation default is followed by a row per house with **Follow the organisation / Own hours / None** (three real options, so Segmented). Own hours use PKG-01 TimePickers with no default times, and both are required. A house lead can change only their own houses.<br>Checks: "Q12: each house follows the organisation's quiet hours by default", "Q12: own hours ask for times — nothing invented", "Q12: a house's own hours need both times", "Q12: a house lead can set their house's quiet hours, not the organisation's".<br>Screenshots: `1440-set-alerts-delivery-quiet-*.png`. |
| Message preview | Pass | Fleet's Notification preview pattern: a WizardShell with `sequential={false}`, Sample and Who and how, an alert picker, and In-app, Email and Push tabs (Fleet `Sections`). The example follows the privacy switch, and controlled-medicine alerts are marked.<br>Checks: "message preview opens on the in-app sample with full details", "with the privacy switch off, email includes client details and says so", "with the privacy switch on, push leaves out client details", "each alert's ⋯ menu offers Preview message".<br>Screenshots: `1440-dlg-msgpreview-*.png`. |
| What applies at this house | Pass | The "At a house" glass button opens a read-only WizardShell viewer with four areas. Every value carries an Organisation or This house badge, and the footer switches house.<br>Checks: "'At a house' opens what applies at the house", "switching house shows that house's rules", "the house view marks organisation vs house settings".<br>Screenshots: `1440-dlg-houselens-*.png`. |
| Remind to set a PIN | Pass | A header button and a row action lead to a ConfirmDialog that names who is reminded. Rows then show when they were reminded. The permission is the same as resetting a PIN, and auditors can't do it.<br>Checks: "PIN status offers to remind…", "the reminder asks first and names who gets it", "reminders are sent and shown on each row", "auditors can't send reminders".<br>Screenshots: `1440-set-staff-status-remind.png`, `1440-dlg-pinremind-all.png`, `1440-set-staff-status-reminded.png`. |
| Emergency access is hard to understand | Pass | It opens with **how it works** (four numbered steps, Start → Record → Extend or end → Review) built from the draft values. A read-only notice says who can change it. There are two plain groups, with "Who gets the daily report" linking to the alert's recipients. A **worked example of one grant** has real times, and it updates as the numbers change. The "Planned — not built yet" card is removed (hide-unbuilt).<br>Checks: "emergency access explains how it works in four steps…", "the steps use the settings…", "a read-only person is told who can change it", "the worked example shows when a grant ends and its latest possible end", "no 'planned, not built' card", "changing the grant length updates the steps and the example", "'Who gets the daily report' opens the alert's recipients".<br>Screenshots: `1440-set-alerts-emergency*.png`. |
| Q2, Q7, Q11, Q13 | Recorded | Q2 keeps Segmented (no change). Q7 is 14 days, a P05 build note. Q11: the alert log caption says "Kept as long as the audit log". Q13 stays as it is. |

## Design rules for the new parts

| Item | Status | Evidence |
|---|---|---|
| Viewers use WizardShell with `sequential={false}` and a header label (Fleet preview) | Pass | Message preview; What applies at this house. |
| Consequential single actions confirm first | Pass | PIN reminders use ConfirmDialog (default variant; nothing is loosened). |
| Settings are switches or 3-option Segmented in titled groups | Pass | Per-house quiet hours. |
| "↗" jumps use `variant="link"` | Pass | "Preview ↗", "Change ↗" (daily report), "Open … ↗" in the house view. |
| No invented values | Pass | House quiet hours start empty. The emergency access example uses the draft settings and synthetic names. |
| No stubs | Pass | The emergency access "planned" card is removed. |
