# P11 v4 — approval record (superseded by v5)

## 1. Approved in the review session (relayed by Main, 30 September 2026)

Stephan replied "all approved" to "P11 v4: approve, all as recommended" on the review session's approvals page. That approval was for **v4 exactly as version `71d86968c`**, whose `VERSION.txt` SHA-256 is `aee08de50c0d6ea51c7bdb8a3757e9ed3e4fcb4cc2c4fa3ecf911e0990755029`. Main had passed that version.

These are the answers approved there, as Main relayed them:

| # | Answer |
|---|---|
| Q1 | Yes. When P11 is built, promote Fleet Settings' `Modal` and `Notice` to a shared settings component. |
| Q2 | Keep the two-button choices. |
| Q5 | Stock expiry, the controlled-drug check, reviews and the other fixed values become settings in their own packages (P06, P07, P05). |
| Q7 | Warn 14 days before a medicine's end date. |
| Q11 | Keep the alert log as long as the audit log. |
| Q12 | Quiet hours organisation-wide for now. **Superseded — see §2.** |
| Q13 | Yes: keep unattended alerts at the top of the bell, for medication follow-up alerts only. That matches the design, which applies only to alerts with Follow up on. |

## 2. Superseded by Stephan in the design session (30 September 2026)

In the design session, Stephan asked for improvements instead of approving v4. He also answered Q12 differently: "Each house sets its own". That work became v5 (`12ecb24a2`). v5 adds:

- quiet hours set per house;
- a message preview;
- "What applies at this house";
- reminders to set a PIN;
- a clearer Emergency access tab ("please improve emergency access, it is hard to understand what is going on").

The two approvals conflicted, so I asked him directly by pop-up. He answered:

- **Approved design:** "v5, once Main passes it".
- **Q12:** "Each house sets its own".

So **v4 is frozen as a record and is not the design to build.** v5 will be recorded as approved in `../v5/APPROVAL.md` once Main passes it. The other answers in §1 (Q1, Q2, Q5, Q7, Q11, Q13) carry over to v5 unchanged.
