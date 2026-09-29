# P11 v4 — design-rules checklist (self-check)

Checked on 29 September 2026 against `docs/emar-audit-2026-09-28/claude-second-review/Mockup-design-rules-checklist.md`.

Everything v3 passed still passes: sections A–H and Main's inspection in `../v3/CHECKLIST.md`. This file covers what v4 adds, plus the evidence re-run on the whole preview.

## Evidence tools (re-run on v4)

- **`reuse-check.mjs`**: all checks pass. That covers P00 wording, the 26 real app modules imported, no shared file changed, no hex colours, raw palette classes or `dark:` pairs, and no browser dialogs or network calls. Buttons use default, outline, destructive, ghost or link only, and are never restyled.
- **Route sweep**: 266 routes × 1440, 1280 and 200 % zoom. It checks for console and page errors, page-level horizontal overflow, and that every `?open=` link opens a dialog. **0 problems.**
- **Interaction run**: 128 checks in headless Chromium at 1440. **128 of 128 pass, with 0 console errors.** 26 checks are new for v4.
- **Truncation check** (Still to decide identity names): **0 truncated at 1440, 0 at 1280.**
- **Type check** (`tsc --noEmit`): **0 errors.**
- **Screenshots**: 194 in `screenshots/`.

## Stephan's v4 choices

| What Stephan asked for | Status | Evidence |
|---|---|---|
| Push channel | Pass | Push switch column in Alerts, starting off. Delivery › Push group shows alert types sent by push and staff with push set up. Validation now needs in-app, email **or** push. The privacy switch covers email and push, because push shows on lock screens.<br>Checks: "push column, off by default", "turning push on is a draft change", "push group counts staff with push set up", "an alert with no channel is caught". |
| Alert log | Pass | A new **Alert log** tab: an EntityTable with Alert, Sent, Told, Where and Status, and ⋯ / right-click / row click. Header filters cover house and status. Row click shows the timeline in a Fleet Modal. After-hours alerts that nobody else heard about link to Delivery & follow-up. There is also an Overview card.<br>Checks: "alert log lists every alert with who was told", "alert timeline shows who acknowledged, and that nobody else was told after hours". |
| Quiet hours | Pass | A switch, then PKG-01 TimePickers for From and Until, labelled Pacific/Auckland. The times start empty and both are required. It never holds alerts with Follow up, and the bell always shows alerts straight away. The preview timeline says when email and push wait.<br>Checks: "quiet hours start off", "turning quiet hours on asks for times — nothing invented", "quiet hours need both times", "quiet hours hint names when held alerts go out". |
| Who can't be reached | Pass | An EntityTable under Delivery covers email, push, phone and leave gaps. Each row says "Can't be reached" or "Not needed yet", worked out from the draft. Row click shows why and who fixes it. The on-call preview and cards show nights when the backup is on leave. There is also an Overview card.<br>Checks: "who can't be reached lists contact gaps, none matter by default", "backup on leave matters once they're the on-call backup", "row click says why, and who fixes it", "backup on leave that night shows nobody". |
| Review the defaults in one go | Pass | A WizardShell with one step per view and a Review step. It has Keep switches, "Keep all on this step", ReviewCards with Edit, and a success pane. Counts match Still to decide: the emergency access policy counts as its five settings. A DiscardDraftDialog guards leaving with unapplied choices.<br>Checks: "walkthrough opens…", "review lists the kept values", "success says how many were kept", "kept values leave Still to decide". |
| Restore from history | Pass | "Put the earlier value back" is in the ⋯ menu, right-click and history detail. It is a ConfirmDialog, destructive when it weakens a safety check, and it names any recorded decision it undoes. The value goes into the draft on the setting's tab. Auditors don't get it.<br>Checks: "restore asks first, names the decision it undoes", "restore puts the value in the draft, on the setting's tab", "auditor can't put values back". |
| Cellphone consent (Q10) | Pass | The dropdown refuses a cellphone nobody agreed to share, and says why. An agreed cellphone is labelled, with the date. "What staff see in their account" shows the account switch.<br>Checks: "Q10: a cellphone nobody agreed to share can't be chosen", "Q10: an agreed cellphone can be chosen and is labelled", "Q10: choosing them says they agreed and can withdraw", "what staff see before their cellphone is shown". |
| Correction: in-app wording | Pass | The In-app group says "The bell (notifications)", and notes that medication errors and controlled-drug loss reports also appear as tasks. Check: "in-app says the bell, not the Tasks inbox". |

## Design rules for the new parts

| Item | Status | Evidence |
|---|---|---|
| Lists are EntityTable, with row click, ⋯ and right-click | Pass | Alert log and Who can't be reached. |
| Settings are Switch rows in titled groups | Pass | The Push and Quiet hours groups. |
| Multi-step editing uses WizardShell; simple views use Fleet Modal; consequential actions confirm | Pass | Review the defaults (WizardShell); alert timeline, reach detail and consent (Modal); restore (ConfirmDialog). |
| Approved time picker, time zone visible | Pass | PKG-01 TimePicker, with "Pacific/Auckland" in the label. |
| No invented values | Pass | Quiet hours have no times until chosen. The alert log examples match today's settings. Leave, phones and push set-up are synthetic and labelled. |
| Honest states | Pass | "Not needed yet" instead of an alarm, "Not attended · 12 min", and "Nobody — Hana Kereama is on leave". |
| 1280 and 200 % zoom | Pass | Sweep 0 problems. The Alerts table's seven columns fit at 1280 without wrapping the status badge (`1280-set-alerts-alerts.png`). |

## Questions for Stephan

See `AUDIT.md` §4.

## Main's inspection of the Delivery tab (5fce692b1), 29 September 2026: pass, with 2 fixes (done in v4)

Main confirmed the grounding on origin/main:
- the overdue alert is sent once (`Cache::add`);
- `emar:send-alerts` runs every 15 minutes, so the 15-minute floor is right;
- `MedicationDashboardAlert` has `acknowledged_by` and `acknowledged_at`.

Main also confirmed:
- the chips are the real `ChipMulti`;
- new behaviour starts off, with empty numbers;
- the privacy default is right under HIPC;
- the timeline is a good addition.

1. **Fixed: "↗" jumps use Fleet's `variant="link"`.** This covers the Overview cards (done in `4e8540e35`) and now every in-page jump:
   - Delivery's "Alerts ↗" and "N of 2 houses ↗";
   - Controlled drugs' "Current overrides";
   - Competency's and Exemption limit's links to Staff eligibility;
   - Staff eligibility's links back to Settings and to witness overrides;
   - Meds today's "Open round templates".

   Dialog footers keep their buttons. `reuse-check.mjs` allows link and ghost, and refuses restyled Buttons.
2. **Fixed: the timeline merges same-time steps.** At 1 hour it reads "Re-alert and escalate — the same people again, plus House lead and On-call person (from the roster)". It also says that re-alerts after an escalation reach **everyone told so far**, and the Re-alert hint says the same.
   - Checks: "preview merges a re-alert and escalation at the same time into one step", "re-alerts after an escalation reach everyone told so far".

Main's build notes are in `AUDIT.md` §2: one shared "attended" record per alert, and pinning in the bell is a shared-component change (Q13).
