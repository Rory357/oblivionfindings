# eMAR P00 v5 — Medication rules & states

**Status: design candidate for inspection by the review session, then Stephan's go-ahead.** Not implemented. Supersedes v4 (frozen in `../v4/`, never approved).

- Version: v5, 29 September 2026 (NZDT). Branch `claude/vigilant-mclaren-233129`.
- Exact file identity: [`VERSION.txt`](VERSION.txt) (SHA-256 of each mockup file).
- Everything in the [v4 README](../v4/README.md) still applies unless changed below.
- Design only: no application code, routes, schema, seeders, configuration, DESIGN.md or design_styles changed.

## What changed from v4 — Stephan's answers, 29 September 2026

| # | Stephan said | Built as |
|---|---|---|
| D2.1 | Support workers recording a prescriber's phone instruction: "yes but … a setting where the client can decide" | New settings in Settings › Medication rules. **Who can record it:** support workers and leads, with a lead countersigning (default); leads only; or nobody (the order is changed first). **When a lead countersigns:** by the end of the next day (default), or before the end of the same shift. The recording dialog follows the setting, and when it isn't allowed, Continue is blocked with an inline reason. |
| D2.2 | Rostered task ownership: "yes — I agree" | Everyone rostered on a covering shift sees the medication task. A round's assignee or a lead can narrow it to one person (the "Narrowed by a lead" state and an "Assign to one person" menu item). |
| D8.1 | Override permission: "create a new one" | New permission "Grant controlled-drug witness overrides" (`medications.controlled.witness_override`). The existing discrepancy-override permission keeps its meaning. Implementation needs the RbacSeeder key **and** a grant migration. |
| D8.2 | Longest override: "you decide, make this a settings option" | Setting "Longest override": one rostered shift (default), up to 24 hours, or up to 7 days. The approve screen enforces it: "That's longer than your organisation allows … grant another override for the next shift". |
| D8.3 | "don't understand this question" | Renamed and explained: **"Heads-up before single staffing"** — when an upcoming shift has only one staff member at a house holding controlled drugs, managers see a heads-up offering to set up an override in advance. It never switches anything on. Recommended on; Stephan to confirm. |
| D12.1 | "everyone till it has been resolved" | Overdue medication alerts, tasks and follow-ups reach everyone rostered on a covering shift and the house lead until resolved. The dialogs and follow-up wording now say so. |
| NF-03 | "yes I agree" | Restricted competency is Block now, then Co-signer with witness PIN once the PIN is built (the "Agreed next step" chip). |

Also: a policy-specific inline message when a different dose isn't allowed, and the decisions table updated (D2, D3, D4, D8, D12).

## Open it

```
node docs/emar-design/P00/v5/serve.mjs
```

Then open http://127.0.0.1:4360/. The v5 settings are at `#/frame/clinical/settings/rules`. To try the prescriber setting, change "Who can record a prescriber's phone instruction", save, then record Tama's dose as a support worker and choose "Prescriber asked for a different dose?". The longest-override check is at `#/frame/pm/safety/overrides` → Grant an override (set the end past 3:00 pm).

## Verification record (29 September 2026)

- 316 routes load with no console errors or horizontal overflow at 1440, 1280 and 200 %. The first v5 sweep caught a crash in the catalogue's approve-screen specimen (no shift end); it was fixed and the catalogue and override routes re-checked clean at all three sizes.
- Flows exercised in the browser:
  - prescriber setting "leads only" and "nobody" as a support worker, including the blocked Continue
  - override longer than one shift rejected
  - "Up to 24 hours" and the heads-up switched off (banner gone), both saved through the confirm dialog
- 143 screenshots in `screenshots/` (1440, 1280, zoom200), including the six new v5 states.

## Still open — what Stephan needs to answer

1. **Heads-up before single staffing** (D8): on (recommended) or off?
2. **Allergy match** (D5): keep Warn, or move to "Block unless the prescriber has confirmed this allergy on the order" (mode 3, needs the order screen in P04)?
3. **Witness PIN numbers** (D8): the wrong-attempt limit, lockout length, renewal, who can reset, the forgotten-PIN fallback (general and controlled drugs), and the time limit for a named colleague to confirm.
4. **Escalation** (D12): the on-call contact for each house (after hours), and by when a follow-up is due.
5. **Timing** (D4, clinical governance): how long after its time a dose counts as late, time-critical medicines, and the re-offer rule after a refusal.
6. **Offline** (D7): record offline (saved on the device) or pause recording and use the print pack?

These stay "Not configured" and fail closed until someone chooses. NF-26 needs no input — Stephan chose the fix; it isn't on main yet.

## Approval

Stephan asked for the review session to inspect v5; if it is happy, implementation can start (package order per the plan: P01 first).
