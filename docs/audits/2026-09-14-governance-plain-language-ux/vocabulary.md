# Governance vocabulary — the words board members see (approved 14 September 2026)

Every Governance screen, dialog, toast, validation message, notification and server-generated label uses these words. If a term isn't here, write it the way you'd explain it to a volunteer board member in one breath. Owner decisions: **"Resolutions"** is the name for board decisions; policy sign-off is **"Read and confirm"**; voting rules are first switched on by the **chair or secretary recording the board's existing approval**.

## House style
- **Sentence case** for every heading, tab, button, label, chip and menu item ("New resolution", not "New Resolution").
- **NZ English:** organisation, finalise, authorise, utilisation, programme, totalling, summarise, recognise.
- **Dates/times** only through `resources/js/lib/datetime.ts` NZ helpers (e.g. "7 September 2026", "7 Sep 2026, 5:00 pm"). Never ISO (`2026-09-06`) or raw `toLocaleString`. Wall-clock inputs use `toDatetimeLocal` / `toDateInput`; the server parses NZ wall time (`app.worker_timezone`) and stores UTC.
- **Money:** NZD with separators, `$85,000` / `$85,000.50`. Periods use the NZ **financial year** ("2025/26", 1 July–30 June) — never "YTD" or "fiscal year 2026".
- **Codes never lead.** Show the title first; put references last and muted: "Approve the 2026/27 budget · Ref RES-2026-004". Breadcrumbs and browser titles use the record title.
- **No raw values.** Enum/snake_case values always go through the label helpers (`resources/js/lib/governance-labels.ts`, server `App\Domain\Governance\Support\GovernanceLabels`).
- **Explain, don't abbreviate.** Unavoidable terms get a "What's this?" hint (`GovernanceTermHint`, definitions in `resources/js/lib/governance-glossary.ts`).
- **Banned in user-facing text:** immutable, frozen, snapshot (say "saved copy" / "final version"), fingerprint, digest, hash/SHA-256 (hide behind "Record details" for auditors), bound/binding/consumed (say "linked", "used"), electorate, denominator, N, floor(N/2)+1, D1/candidate/authority profile, payload, source/provenance/backbone/posture, terminal state, tabled, secretariat, recuse/recusal (say "step aside"), attest/attestation (say "confirm"), MTTA/MTTR, PIA, DSR, capex/opex, YTD, KPI without expansion ("key performance measures (KPIs)" once).
- **Truthful states:** unknown data shows "Not available" / "No data yet" — never 0 and never a green "On track".
- **Consequential actions** (vote, open voting, distribute, submit, approve, complete, archive, close, decline, delete) always use `components/confirm-dialog.tsx` stating the effect, who is affected and whether it can be undone.
- **Blocked states** say what's blocked, why, and who can fix it — as visible text, never only a tooltip on a disabled button.

## Navigation (sidebar hub → tabs; page title = tab label; breadcrumb = Home → Governance → tab → record)
| Sidebar entry | Tabs (and page titles) |
|---|---|
| Home · My work · Calendar · Records | Header rail: Home · My work · Calendar · Records |
| **Meetings** | Meetings · Board packs · CEO reports |
| **Resolutions & actions** | Resolutions · Actions |
| **Risk & compliance** | Risk register · Compliance · Care quality · Te Tiriti |
| **Board finance** | Budgets · Spend approvals |
| **Strategy & performance** | Strategic plan · CEO performance · Roadmap |
| **Policies & records** | Policies · Documents · Records |
| **Board & members** | Board members · Interests · Evaluations |
| **Settings & audit** | Settings · Audit log |

## Terms
| Thing | Use | Don't use |
|---|---|---|
| A matter the board decides | **resolution** ("New resolution", "Resolutions") | decision paper, paper(s) & resolutions, board decision, carried resolution, motion as a noun on its own |
| The exact words voted on | **resolution wording** (hint: "the exact words the board votes on") | exact motion, operative text |
| Resolution outcome | **Passed** · **Not passed** · **No decision — not enough members took part** | carried, defeated, no_quorum |
| Resolution status | Draft · Open for voting · Voting closed · Done · Archived | implemented, open, closed (raw) |
| Voting rule (threshold) | **More For than Against** (ordinary) · **At least two-thirds For** (special) · **Everyone entitled votes For** (unanimous) | simple majority (50%+1), special majority (75%), 100% entitled |
| Vote outside a meeting | **vote outside a meeting (written resolution)** | standalone paper, out-of-session |
| Purpose | **For decision** (vote) · **For discussion** · **For information** (no vote) | noting, endorsement |
| Conflict | **declare a conflict of interest**; **step aside** from the vote | recuse, withdraw from voting, prejudicial, related-party transaction |
| Quorum | **quorum** + hint "the minimum number of voting members who must take part for a vote to count" | electorate denominator |
| Who can vote | **voting members** | entitled voters, electorate, N |
| Follow-up work | **action** ("Actions", "Mark as done", "Update progress") | action item, action register, follow-through, deliverable |
| Proof an action is done | **evidence** (hint: "a file showing it's done — e.g. the signed document") | managed storage path |
| Meeting reading | **board pack**, **version 2** | revision, rev, edition, v2 |
| Reading receipt | "I've read this pack" / "You confirmed you read version 2 on 7 Sep 2026" | acknowledgement required, durable receipt |
| Policy sign-off | **Read and confirm** · "Policies to confirm" · "Confirm I've read this policy" · "You confirmed version 3 on …" | attest, attestation, sign-off |
| Risk scores | **risk before controls** (inherent) · **risk after controls** (residual) · **the board's limit** (appetite; hint "the most risk the board has agreed to accept for this kind of risk") · **above the board's limit** | inherent, residual, appetite, tolerance on their own |
| Risk response | Reduce it (treat) · Share it (transfer) · Stop the activity (avoid) · Live with it and monitor (tolerate) | treat/transfer/terminate/tolerate alone |
| Risk status | Open · Accepted by the board · Closed | mitigating, transferred, avoided |
| Compliance item | **requirement** ("Add requirement", "Requirements overdue") | obligation |
| Te Tiriti item | **commitment** | obligation |
| Clinical indicators | **Care quality** (subline: "Medication errors, falls, skin injuries and infections") | clinical governance snapshot, automated source |
| Budget line change | **budget change** | adjustment, reallocation |
| Linking a resolution to a record | "This resolution approves: [record] (version 3)" · "Record the board's approval" | bound, binding, apply carried resolution |
| Spend over the limit | **spend request**; card "Who approves what" | capex/opex threshold |
| Budget position | "Over budget by $X" / "Under budget by $X" | variance (alone) |
| Strategy | **theme** (pillar) · **measures of success** (key results) · **plan length** (horizon) · "Create version 3" | pillar, key results, horizon, lineage, superseded |
| CEO review | **performance review** · **self-assessment** · **Complete review** | 2026-Annual, board_review |
| Interests | **interest** · "Declare an interest" · "This interest has ended" | nature of interest (alone) |
| Settings voting card | **How the board votes** · table **Who can vote** (with "Why not?" column) | Governance rules & electorate (D1 authority) |
| Voting rules switch-on | "Record the board's approval of these voting rules" (governing document, date approved, minutes reference) | activate candidate profile |

## Status chips (via `governanceStatus()` helper — one label + StatusBadge variant per status)
Draft (neutral) · Waiting for the board (warning) · Open for voting (info) · Approved / Passed / Done / Confirmed (success) · Not approved / Not passed (critical) · Overdue (critical) · Replaced by a newer version (neutral) · Archived (neutral) · No data yet (neutral).
