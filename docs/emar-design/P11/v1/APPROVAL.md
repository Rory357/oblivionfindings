# P11 v1 — approval record

> **Withdrawn by Stephan the same day. See "Withdrawn, 29 September 2026" at the end. The replacement is `docs/emar-design/P11/v2/`.**

**Approved, 29 September 2026 (NZDT), exactly as committed in `c13048324`.**

- In this session Stephan said the review session ("main") would give the approval.
- The review session then relayed his approval: "rather do this one approval approved". It came with the review session's recommended answers to the 21 questions in the README.
- The approved files are unchanged. This file sits beside them, so their hashes still match `VERSION.txt`:

| File | SHA-256 prefix |
|---|---|
| `index.html` | `05469ef5…` |
| `mockup.css` | `4300fbbd…` (same as P00 v5) |
| `mockup.js` | `64aaf7d4…` |
| `p11.css` | `3f41de59…` |
| `serve.mjs` | `e7ea7abd…` |
| `reuse-check.mjs` | `f42ff04f…` |
| `README.md` | `11ee518f…` |

## Answers to build in when P11 is implemented

No v2 was asked for. Answer 1 changes an approved P00 view slightly; the others are implementation details.

| # | Topic | Answer |
|---|---|---|
| 1 | House rules (D2) | **Restore today's capability.** Stephan, in this session: "Yes, keep it. They can today, and we shouldn't remove existing features." House managers add and change medicine rules for their own houses. Organisation-wide rules still need all-sites authority. This is a small change to the P00 Medication rules view: house-scoped rules become editable by house managers. |
| 2 | Emergency access policy editors | Keep today's role check: admins and provider managers. |
| 3 | Round templates gate | Keep today's rule: people who manage orders at the house. |
| 4 | Who records assessments | Keep today's rule (manages orders at the house) for now. |
| 5 | Competency register | Leads only. Each worker sees their own status in My eligibility. |
| 6 | Auditors | Get read-only access to Settings. |
| 7 | Competency values | Accept them as defaults for the clinical lead to review: 12 months, pass mark 10 of 12, every core area must pass, 30-day renewal reminder. Observed administrations stay "Not configured". |
| 8 | Longest exemption | 30 days, shown as "Default — not yet reviewed" for the clinical lead to confirm. Exemptions can be granted within it. |
| 9 | During an exemption | The restricted and area rules still apply. |
| 10 | Witness tick | "Can witness controlled drugs" is only allowed when the controlled drugs area is passed. |
| 11 | Restricted workers | Can't witness controlled drugs while restricted. |
| 12 | Acknowledgement | Only from the worker's own login. The assessor's tick box is removed. |
| 13 | Dose timing | Stays in Settings › Rounds & timing. |
| 14 | Fixed-in-code values | The late-dose incident threshold (120 min) and refusal escalation (3 in 7 days) become settings, shown as "Default — not yet reviewed". |
| 15 | Alert recipients | Don't build fully configurable recipients yet. Build the decided routing plus the per-house on-call contact; the rest stay as proposals. |
| 16 | Proposed recipients | Agreed as proposals. |
| 17 | Suspected alert faults | Fix task raised. It's already running as the separate session "Verify and fix medication alert routing faults", started by Stephan from this session. |
| 18 | Medicine photos | Defaults and placement agreed: anyone who can receive stock; prompt, never required; a card on the Medication rules page. |
| 19 | Emergency access proposals | Approved for P10. |
| 20 | P00 wording updates | Approved for implementation:<br>• an empty PIN renewal reads "No renewal";<br>• the locked-PIN message uses the set limits;<br>• house leads can reset PINs for their houses;<br>• the heads-up badge shows Stephan's decision. |
| 21 | "More" overflow | Acceptable at 1440 and 1280 px. |

## What happens next

- PIN-1 is being built.
- P11 is built after PIN-1 lands, because only one session writes application code at a time.
- Nothing is implemented until the review session releases it.
- The next design package is P01 (recording a dose). It starts only when Stephan asks for it.

## Withdrawn, 29 September 2026

**Stephan withdrew this approval the same day**, after he saw the mockup for the first time. His words: "i dont approve this design their is no toggle switches on off it is difficult to navigate and the modals is not following the rules. Please look at the fleet settings for a bit more inspiration".

- v1 stays frozen as a record. Its files and hashes are unchanged; only this note is added.
- The replacement is **P11 v2**, in `docs/emar-design/P11/v2/`. It is a Vite + React preview on the app's real components, which is the review session's mandatory method.
- The 21 answers above carry into v2, except **answer 15**. On 29 September Stephan decided that managers set who gets each alert: one set for every house, plus extra people a house manager adds for their own house.
- He also decided that settings are shown as cards, while lists of records stay tables.
- Lesson recorded: show Stephan the mockup in his browser before asking for approval.
