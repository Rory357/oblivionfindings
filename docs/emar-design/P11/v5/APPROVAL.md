# P11 v5 — APPROVED

**Approved design:** P11 v5, exactly as commit `12ecb24a2`, whose `VERSION.txt` SHA-256 is `a735a83d9d0b071cc60cef4f8bbdbbd9cd0b0e1ae7ad7066d18ff3a8acf0326b` (25 files, plus 222 screenshots with a combined hash of `8b1017c2…`).

- **Stephan's approval (30 September 2026, design session, by pop-up):** "v5, once Main passes it".
- **Main's inspection (30 September 2026):** PASS. Main's one consistency check, whether the house view and the On-call contacts meter agree, was verified with no change needed: both read the same saved on-call state ("1 of 2" and the roster rule with `oncall=1`; "0 of 2" and "Not configured" without it).
- **Supersedes v4.** The review session relayed "all approved" for v4 (`71d86968c`). Stephan then chose v5 directly and answered Q12 differently; see `../v4/APPROVAL.md`.
- **Build:** after PIN-1 lands, in a separate implementation session, view for view against this version at 1440.

## Stephan's decisions and answers

| # | Decision | Source |
|---|---|---|
| 1–21 | The 21 v1 answers in `../v1/APPROVAL.md`, **except answer 15** (reversed below). | v1 |
| — | Managers set who gets each alert: organisation-wide, plus extras per house. This reverses answer 15. | 29 Sep |
| — | Settings are cards and groups; lists of records stay EntityTable; the Fleet page structure applies (Overview, groups, switch tables). | 29 Sep |
| — | Email and other new behaviour start **off**, shown as "Default — not yet reviewed". | 29 Sep |
| — | The on-call contact is an employed staff member and follows the roster: on-call shift, then the team lead on shift, then a backup. | 29 Sep |
| — | Delivery is interactive: re-alert until attended, what counts as attended, escalation, email and push handling. | 29 Sep |
| Q1 | When P11 is built, promote Fleet Settings' `Modal` and `Notice` to a shared settings component. | review session |
| Q2 | Keep the two-button choices ("A lead countersigns", "Who gets the follow-up"). | review + design |
| Q3 | Medication Settings owns who gets medication alerts. Control Room keeps its queue. | 29 Sep |
| Q4 | Follow P00: any dose over the order is a medication error, and the 20 % warning is retired. | 29 Sep |
| Q5 | The fixed stock, controlled-drug check, review, as-needed and syringe-driver values become settings in their own packages (P06, P07, P05). | review session |
| Q6 | Channels are in-app, email and push. The organisation sets them; people can add email copies for themselves. | 29 Sep |
| Q7 | Warn **14 days** before a medicine's end date (P05). | review + design |
| Q8 | "Team lead on shift" means someone with the Team lead role on shift at the house. | 29 Sep |
| Q9 | The on-call person gets no alerts until a manager turns them on. | 29 Sep |
| Q10 | The on-call number is the work phone, else the personal cellphone, and the cellphone only with the person's consent. | 29 Sep |
| Q11 | Keep the alert log as long as the audit log. | review + design |
| Q12 | **Each house sets its own quiet hours**: follow the organisation, own hours, or none. | design session (supersedes the review session's "organisation-wide for now") |
| Q13 | Keep unattended alerts at the top of the bell: **off by default, for medication follow-up alerts only**, built with the bell's owner. | review + design |

## Known follow-up outside P11

The shared `ConfirmDialog` paints destructive confirm buttons purple, because `btn-soft-primary` beats `bg-destructive`. This affects every destructive confirm in the app, including P11's "Put the earlier value back" and "Pause rule". It is being fixed in a separate session. The design intent is a red (destructive) button.
