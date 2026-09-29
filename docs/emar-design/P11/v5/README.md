# P11 v5 — Medication settings and staff eligibility

A synthetic design preview for Stephan and the review session. It is design only: no application code, routes, schema or seeders are changed. `DESIGN.md` and `design_styles/*` stay read-only.

v5 starts from v4 as Main passed it (`71d86968c`, frozen in `../v4/`). It adds what Stephan asked for on 30 September 2026.

## Why v5

Stephan asked "is there any improvements we can do?" instead of approving v4. He chose three improvements and answered five questions. While v5 was being built, he also asked: "please improve emergency access, it is hard to understand what is going on".

## Stephan's answers (29–30 September 2026)

| # | Decision | Where |
|---|---|---|
| Q2 | Keep the two-button choice for "A lead countersigns" and "Who gets the follow-up". | Safety checks, Witness PINs |
| Q7 | Warn **14 days** before a medicine's end date. | Build note for P05 (`AUDIT.md`) |
| Q11 | The alert log is kept as long as the audit log. | Alert log caption |
| Q12 | **Each house sets its own quiet hours.** | Delivery › Quiet hours |
| Q13 | Keep "Keep unattended alerts at the top of the bell", off by default, and build it with the bell's owner. | Delivery › In-app |

## What's new in v5

- **Quiet hours per house (Q12).**
  - The organisation default stays as it was.
  - Each house then chooses **Follow the organisation**, **Own hours** (From and Until, using the PKG-01 TimePicker with no default times) or **None**.
  - A house lead can change only their own houses. The organisation default needs all-sites authority.
  - The "What happens if nobody attends" timeline shows each house's hours.
- **Message preview** (Fleet's "Notification preview").
  - A WizardShell viewer with two steps, **Sample** and **Who and how**. It has an alert picker and In-app, Email and Push tabs.
  - It is worked out from the draft. With "Keep client names and medicines out of email and push" on, the email and lock-screen examples leave them out; with it off, they include them and say so.
  - Open it from each alert's ⋯ menu or right-click, or from Delivery › Email › "See what a message looks like".
- **What applies at this house.**
  - A new **At a house** button in the header opens a read-only summary with one step per area: Medication rules, Rounds & timing, Staff & PINs, and Alerts & on-call.
  - Every value is labelled **Organisation** or **This house**.
  - It covers: safety checks, the controlled-drug witness at this house, the medicine rules that apply there, active rounds, dose timing, competency, PIN status at the house, alert extras, the house's quiet hours, on-call tonight, and emergency access.
  - Switch houses in the footer.
- **Remind to set a PIN** (Staff & PINs › PIN status).
  - "Remind 3 to set a PIN" in the section header, plus "Remind them to set a PIN" on each row.
  - It asks first and names who gets the reminder. It sends an in-app reminder, and push if they've set it up, and records it in the audit log.
  - Each row then shows "Reminded Today 9:12 am, by …". House leads can remind people at their own houses and clinical leads anyone, the same as resetting a PIN. Auditors can't.
- **Emergency access, made understandable** (Stephan: "it is hard to understand what is going on").
  - The tab opens with **how it works** in four steps: Start, Record, Extend or end, Review. The steps use the live settings, for example "For 1 hour… never past 4 hours in all".
  - Anyone who can't change the policy is told so, and who can.
  - The settings are in two plain groups: *How long a grant lasts*, and *What reviewers see*. The second includes "Who gets the daily report", with a link to change it.
  - It ends with **a worked example of one grant**: Mere starts at 9:00 am, access ends at 10:00 am, the latest possible end is 1:00 pm, reviewers get the daily report, and repeat use is flagged.
  - The "Planned — not built yet" card is gone, under the hide-unbuilt rule. Those items are now build notes in `AUDIT.md`.

## Run it

The preview needs `node_modules` in the worktree. Here that is a **junction** to the main checkout's folder, so:

- never run `npm install` or `npm ci` inside it;
- delete the junction link before this worktree is removed.

From the worktree root, build:

```bash
node node_modules/vite/bin/vite.js build --config docs/emar-design/P11/v5/vite.config.mjs
```

Then serve:

```bash
node docs/emar-design/P11/v5/serve.mjs
```

Open http://127.0.0.1:4376/. The **Preview guide** (`#/guide`) has "v5 additions" and "v4 additions" rows that link every new state.

## Evidence

- **`CHECKLIST.md`**, **`AUDIT.md`** and **`reuse-check.mjs`**, run from the worktree root:

```bash
node docs/emar-design/P11/v5/reuse-check.mjs
```

- **`screenshots/`** has 222 files, and **`VERSION.txt`** has the SHA-256 of every file.
