# P11 v3 — design-rules checklist (self-check)

Checked on 29 September 2026 against `docs/emar-audit-2026-09-28/claude-second-review/Mockup-design-rules-checklist.md`. Items that are unchanged from v2 keep v2's evidence; see `../v2/CHECKLIST.md`.

## Evidence tools

- **`reuse-check.mjs`** covers P00 wording, real-component imports, and the token and honesty lint. **21 of 21 pass.**
- **Route sweep**: 249 routes × 1440, 1280 and 200 % zoom. It checks for:
  - console errors and page errors;
  - page-level horizontal overflow;
  - every `?open=` deep link opening a dialog.

  Result: **0 problems.**
- **Interaction run**: 102 checks in headless Chromium at 1440. **102 of 102 pass, with 0 console errors.**
- **Truncation check** on the Still to decide identity names: **0 truncated at 1440, 0 at 1280.**
- **Type check**: `tsc --noEmit` on the preview, **0 errors.**
- **Screenshots**: 171 in `screenshots/`. Each name is the size followed by the screen.

## Stephan's third correction (v2 → v3)

| What Stephan said | Status | Evidence |
|---|---|---|
| "alerts and access there is no in app or email similar to fleet" | Pass | **Alerts & access › Alerts** is one `EntityTable`, with columns:<br>• **In-app** switch (decided alerts are locked on and say "Always on");<br>• **Email** switch;<br>• **Goes to**;<br>• **Status**.<br>Screenshots: `1440-set-alerts-alerts.png`, `1440-set-alerts-alerts-email-on.png`. **Channels** is its own tab, like Fleet's "Delivery & channels" (`1440-set-alerts-channels.png`).<br>Interaction run: "decided alert: in-app locked on", "email starts off", "turning email on is a draft change", "an alert with no channel is caught". |
| "staff pins tab looks so plain there is no structure please look at fleet" | Pass | Every settings tab is a grid of titled groups, each with an icon, a caption and compact rows. For example, **Witness PINs** has Witness PIN · Locking · Forgotten PIN · Who can reset a PIN · Named colleague confirms (`1440-set-staff-pins.png`). **Competency** has Assessment · Evidence and renewal, plus "How competency is used" (`1440-set-staff-competency.png`). Every view opens on an **Overview** of icon cards (`1440-set-staff-overview.png`). |
| "the border over the time is an issue" | Pass | The round time and "Doses due within" are plain side-by-side fields. The time zone is in the label ("Round time · Pacific/Auckland"), and there is no bordered fieldset (`1440-dlg-tpl-new.png`). Interaction run: "round time has no bordered box around it". |
| "still no dropdown for on call, it will be a staff member employed" | Pass | On-call contacts use a searchable dropdown of **employed staff with access to the house**. Each person's phone number comes from their staff record. Someone with no phone number is listed but can't be chosen, and the dropdown says why. Screenshots: `1440-dlg-oncall-picker.png`, `1440-dlg-oncall-rimu-no-phone.png`. Interaction run: "staff dropdown lists employed staff…", "phone comes from the staff record", "staff without a phone number can't be chosen". |
| "on call to follow the on call manager from rostering … team lead … auto decide the on call after hours" | Pass | Three steps: **Follow the roster** (on-call shift), then **Then the team lead on shift** (switch), then **If nobody is rostered** (backup). A preview lists who staff will see on each of the next three nights, and why (`1440-dlg-oncall-set.png`, `1440-dlg-oncall-no-team-lead.png`). Houses can switch the roster off and name one on-call person (`1440-dlg-oncall-fixed-person.png`). Rostering has on-call shifts and a `team_lead` role but no "on-call manager" field (see `AUDIT.md` §2). |
| "why is it not interactive like for example re-alert attended to all those type of things" (Delivery tab) | Pass | The static Channels cards are replaced by **Delivery** (`1440-set-alerts-delivery*.png`). Every row is a setting:<br>• **Re-alert until attended**: switch, every [ ] minutes, up to [ ] times;<br>• **An alert counts as attended when**: opens / acknowledges / dealt with;<br>• **Escalate if still not attended**: switch, after [ ] minutes, to chosen groups including the on-call person;<br>• **Email**: hourly summary, keep client details out of emails, personal copies;<br>• **In-app**: keep unattended alerts at the top of the bell.<br>A **What happens if nobody attends** timeline is worked out from the draft, and each alert has a **Follow up** switch.<br>Everything new starts off (today each alert is sent once), and numbers are never invented.<br>Interaction run (15 new checks): "turning re-alert on asks for numbers — nothing invented", "empty re-alert numbers are caught and focused", "re-alert under 15 minutes is refused", "preview shows the re-alerts from the draft", "escalation needs someone to escalate to", "attended choice changes when follow-up stops", "review lists the delivery changes", "follow up switch per alert…", "house lead: delivery read-only". |
| No internal codes on screen | Pass | `plain()` strips decision codes from help text. "Decided" chips, "(answer n)" and "(fixed in code)" are removed from the UI and kept in these notes. |

## 1. Build method

These items are unchanged from v2 and all pass:

- Vite + React preview in `docs/emar-design/P11/v3/`, with `@` aliased to `resources/js` and `@tailwindcss/vite` (the `app.css` tokens).
- **26 real app modules imported** (`reuse-check.mjs`).
- Synthetic data only, on a fixed clock: Tue 29 Sep 2026, 9:12 am NZDT.
- `serve.mjs` is GET/HEAD only, with `connect-src 'none'`.
- No shared component changed.
- `node_modules` is a **junction** to the main checkout's. Never run `npm install` or `npm ci` inside it, and delete the junction link before the worktree is removed.

**Q1:** Fleet's `Modal` and `Notice` are still imported from the page-local `pages/fleet-assets/settings/_ui.tsx`.

## 2. Checklist

### A. Page top

Pass, unchanged from v2:

- `PageHeader`, with one StatusBadge chip and a one-line subline;
- five meter blocks, each a link, with "Unavailable" and "n/a" states;
- search and filters inside the header;
- the real `PageHeaderRail`;
- breadcrumbs rooted at Home.

The Alerts tab adds a **Show** filter in the header filter row: All alerts · Not yet reviewed · Unsaved changes · Email on.

### B. Layout

| Item | Status | Evidence |
|---|---|---|
| Only the shell gutter; `gap-5` between sections | Pass | Group grids use `gap-5`. |
| Full width | Pass | All screenshots. |
| A number lives once, in the meter row | Pass, note | Overview cards describe settings in words ("Locks after 5 wrong attempts, for 15 minutes"). They carry no KPI numbers that repeat the meters. |
| 1440, 1280 and 200 % zoom; no horizontal page scroll | Pass | Sweep: 747 page loads, 0 problems. |

### C. Lists

| Item | Status | Evidence |
|---|---|---|
| `EntityTable` for lists of records, identity first, kebab last | Pass | Medicine rules, round templates, PIN status, **alerts**, still to decide, all changes, and the eligibility lists. On-call contacts are **cards** (one per house), because they are settings, not records (Stephan's decision 2). |
| Row click, ⋯ menu, right-click (Shift+F10) | Pass | Clicking an alert row opens *Who gets it*. The ⋯ menu has "Edit who gets it" and "Add a person". Interaction run: "row click opens the who-gets-it wizard", "wizard review lists the groups and person". |
| Empty, loading, error | Pass | Unchanged from v2 (`state-*` screenshots). |

### D. Dialogs

| Item | Status | Evidence |
|---|---|---|
| Add or edit with 2+ sections uses `WizardShell` | Pass | v2's four wizards, plus **Who gets it**: Groups · Named people · House extras · Review, with "Apply to draft" and a DiscardDraftDialog guard (`1440-dlg-alertwho-*.png`). |
| Simple dialogs use Fleet's `Modal` | Pass | The on-call contact dialog is a Fleet `Modal`. It has switch rows, the staff picker, a phone card from the staff record, and the roster preview. |
| Consequential saves state the effect | Pass | Alert changes go into the page draft and then through "Review alerts & access changes" ("review states the effect with channels and people"). Saving an on-call contact records old → new in the change history, for example "Follows the roster, then the team lead on shift · backup Hana Kereama". Removing a contact is a destructive `ConfirmDialog`. |
| Errors keep values and focus the first error; focus returns to the opener | Pass | Interaction run: "on-call validation: backup required, focus on the staff picker", plus v2's checks. |

### E. Controls

| Item | Status | Evidence |
|---|---|---|
| On/off settings use `Switch` with an On/Off word | Pass | Includes In-app and Email per alert, recipient groups, "Follow the roster" and "Then the team lead on shift". |
| Growing lists use a searchable picker | Pass | Employed staff for on-call; people for alerts; houses for extras. |
| Approved date and time pickers, timezone visible | Pass | PKG-01 `TimePicker` for round time, with "Pacific/Auckland" in the field label. There is no bordered fieldset (Stephan). |
| Q2 two-answer choices | Question | Still Segmented: "A lead countersigns" and "Who gets the follow-up". |

### F. Tokens, status and accessibility

Pass, unchanged from v2. `reuse-check.mjs` confirms:

- no hex colours;
- no raw palette classes;
- no `dark:` pairs.

Also carried over from v2:

- dark theme screenshots;
- On/Off words beside every switch;
- `aria-label` on every icon-only button and on each alert's switches ("Overdue doses: email").

### G. Language

| Item | Status | Evidence |
|---|---|---|
| NZ English, sentence case, plain words, no codes | Pass | Codes are removed from the UI (see above). |
| "Not configured" instead of a fake zero | Pass | On-call: "Not configured", or "Rostering has on-call shifts here — set up a contact to use them". |
| Blocked actions say why | Pass | Staff who can't be chosen for on-call show "no phone number on their staff record". Read-only on-call cards say who can change them. |
| No invented clinical values | Pass | Alert defaults come from today's code and Stephan's decisions. The on-call roster, staff and phone numbers are synthetic. |

### H. Honesty

| Item | Status | Evidence |
|---|---|---|
| No dead actions | Pass | "Planned — not built yet" on Emergency access is information only, with no buttons. |
| Synthetic data labelled | Pass | Preview bar. |
| Earlier approved views reused unchanged | Pass | P00 v5 wording is verbatim (`reuse-check.mjs`). |

## Questions for Stephan

See `AUDIT.md` §4.

## Main's inspection, 29 September 2026: pass, with one fix (done)

Main inspected `8eda02a78`.
- `sha256sum -c` passed on all 23 entries.
- Main compared v3 side by side with the live Fleet Settings at 1440 (demo admin):
  - Tracking › Overview matches the Overview;
  - Notifications › Preferences matches the Alerts table;
  - Delivery & channels is its own tab;
  - Staff & PINs and Safety checks have titled groups.
- The Who-gets-it wizard and the on-call dialog read well, and no internal codes appear on screen.

1. **Fixed: "Review …" links on Overview cards.** They now use Fleet's `<Button variant="link">` with a size-4 arrow, exactly as in `fleet-assets/settings/_owners.tsx:146-151`. They were outline buttons.
   - The same change covers "Go to …" in the Unsaved changes dialog, which is also inside a ReviewCard.
   - The Alerts footer strip's "Review delivery & follow-up" now uses Fleet's footer pattern: `variant="ghost" size="sm"` (`_notifications.tsx:577-584`).
   - `reuse-check.mjs` now allows `ghost` and `link` (`design_styles/DESIGN_TOKENS.md:104-110`). It still refuses restyling: a Button `className` may hold spacing classes only.
2. **New question Q10** (on-call phone privacy) is added to `AUDIT.md` §4.

After Main's pass, Stephan asked for the Delivery tab to be interactive ("re-alert, attended"). That change (`5fce692b1`) is covered in the table at the top of this file.
