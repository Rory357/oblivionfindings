# P11 v3 — Medication settings and staff eligibility

A synthetic design preview for Stephan and the review session. It is design only: no application code, routes, schema or seeders are changed. `DESIGN.md` and `design_styles/*` stay read-only.

## Why v3

Stephan didn't approve v2 (29 September 2026): "alerts and access there is no in app or email similar to fleet / staff pins tab looks so plain there is no structure please look at fleet".

**What was wrong with v2.** It copied Fleet's *parts* (Switch, Modal, WizardShell) but not Fleet's *page structure*.

- Alerts had no In-app or Email columns, so there was no way to say how an alert is sent.
- Staff & PINs was one long stack of identical cards, each with long help text, so nothing was grouped.
- Internal decision codes (D8, P00, "answer 15") leaked into the screens.

**What v3 does, following Fleet Settings:**

1. **Every view opens on an Overview**: icon cards that say what the area controls now, each with a "Review …" button (Fleet Tracking › Overview).
2. **Settings are grouped.** Each tab is a grid of titled groups, each with an icon and a short caption, holding compact rows with a switch, number or choice on the right. For example, Witness PINs has Witness PIN, Locking, Forgotten PIN, Who can reset a PIN, and Named colleague confirms.
3. **Alerts are one compact table** with **In-app** and **Email** switch columns, "Goes to" and Status (Fleet Notifications). Clicking a row opens *Who gets it*, a WizardShell with these steps: Groups, Named people, House extras, Review.
4. **Channels** is its own tab (Fleet "Delivery & channels"): In-app, Email, Personal email copies, and After hours.
5. **No internal codes on screen.** Decision references are kept in these notes, not in the UI.

## Stephan's decisions (29 September 2026)

| # | Decision | Where |
|---|---|---|
| 1 | Managers set who gets each alert. Scope is organisation-wide, plus extras per house. | Alerts › row click |
| 2 | Settings are cards and groups; lists of records stay `EntityTable`. | Every view |
| 3 | Q3: Medication Settings owns who gets medication alerts. Control Room keeps its queue. | Alerts footer |
| 4 | Q4: follow P00. Any dose over the order is a medication error; the 20 % warning is retired. | Safety checks › Amount given |
| 5 | Q6: alerts go "in app and email". | Alerts table |
| 6 | Channels are set by the organisation; people can add email copies for themselves. | Channels |
| 7 | Email starts **off** everywhere, marked "Default — not yet reviewed". | Alerts table |
| 8 | Rebuild on Fleet's structure (this version). | All |
| 9 | Round time sits without a bordered box ("the border over the time is an issue"). | Round template wizard |
| 10 | **The on-call contact is an employed staff member, chosen from a dropdown, and it follows the roster.** | On-call contacts |

### On-call follows the roster (decision 10)

Rostering today:

- It marks on-call shifts per house (`shifts.is_on_call`, and `shift_type` `on_call`).
- It has a `team_lead` role.
- It has **no separate "on-call manager" field**.

So each house's on-call contact is worked out in this order:

1. **Follow the roster.** Whoever is rostered on an on-call shift at the house.
2. **Then the team lead on shift** (a switch). If nobody is on an on-call shift, the team lead working at the house.
3. **If nobody is rostered.** A backup person, picked from employed staff with access to the house.

Or the house can switch off "Follow the roster" and name one on-call person.

- **Phone numbers** come from the staff record. People with no phone number are listed but can't be chosen, and the dropdown says why.
- **Preview:** the dialog shows who staff will see for the next three nights and why, for example "On an on-call shift", "Team lead on shift" or "Backup — nobody rostered".
- **Alerts:** "On-call person (from the roster)" is a new group that can receive urgent alerts. It is off by default, and the Who-gets-it wizard names any house without an on-call contact.

## Run it

The preview needs `node_modules` in the worktree. Here that is a **junction** to the main checkout's folder, so:

- never run `npm install` or `npm ci` inside it;
- delete the junction link before this worktree is removed.

From the worktree root, build:

```bash
node node_modules/vite/bin/vite.js build --config docs/emar-design/P11/v3/vite.config.mjs
```

Then serve:

```bash
node docs/emar-design/P11/v3/serve.mjs
```

Open http://127.0.0.1:4374/.

- The server is GET/HEAD only, and there is no application API behind it.
- `dist/` is committed, so the reviewed build can be served without rebuilding.
- The **preview bar** switches role and data state, and sets whether the next save works, fails once, or finds someone else saved first.
- The **Preview guide** (`#/guide`) links every state.

## What's in it

**Medication › Settings.** One PageHeader page with 5 rail views, and tabs inside each:

| View | Tabs |
|---|---|
| Medication rules | Overview · Medicine rules · Safety checks · Controlled drugs · Medicine photos |
| Rounds & timing | Overview · Round templates · Dose timing |
| Staff & PINs | Overview · Competency · Exemption limit · Witness PINs · PIN status |
| Alerts & access | Overview · Alerts · Channels · On-call contacts · Emergency access |
| Change history | Still to decide · All changes |

**Safety & oversight › Staff eligibility.** Unchanged from v2: Register, Renewals, Exemptions, and Witnesses & PINs.

**Meds today.** Only the *My eligibility* block and the Rounds link are P11's.

## Evidence

- **`CHECKLIST.md`** has pass/fail and evidence for every design-rules item.
- **`AUDIT.md`** holds the gap audit, the bugs found and the open questions.
- **`reuse-check.mjs`** checks four things:
  - P00 v5 wording is used verbatim;
  - the preview imports the real primitives;
  - no shared file changed;
  - there are no raw colours, no browser dialogs and no network calls.

  Run it from the worktree root:

```bash
node docs/emar-design/P11/v3/reuse-check.mjs
```

- **`screenshots/`** has 164 files at 1440, 1280 and 200 % zoom, plus dark theme.
- **`VERSION.txt`** has the SHA-256 of every file.
