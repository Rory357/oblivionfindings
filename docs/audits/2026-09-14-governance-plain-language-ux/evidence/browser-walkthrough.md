# Browser walkthrough findings (Chrome, 1366×768, oblivionfindings.test @ 1755a41fe, manager + impersonated ordinary member)

## Cross-cutting
- EmptyState icons never render app-wide: `components/ui/empty-state.tsx` checks `typeof Icon === 'function'`, lucide icons are forwardRef objects → blank grey circle on every empty list (Resolutions, Actions, Compliance, Budgets, Spend approvals, Strategy, CEO performance, My work, Meetings agenda/papers/minutes). 139 files use EmptyState.
- Double navigation on Home/My work/Calendar: sidebar Home · My work · Calendar AND header rail Overview · My work · Calendar · Records (same destinations, different names "Home" vs "Overview").
- Sidebar group label "GOVERNANCE" repeated inside the Governance module panel.
- Three names for the same thing everywhere: sidebar "Decisions & actions" / page "Resolutions" / button "New decision paper" / list "Decision papers" / meeting tab "Papers & resolutions" / meeting button "New resolution". Actions: page "Actions" / tab "Action items" / list "Action register".
- Title Case vs sentence case mixed on the same page (Home: "Priorities Requiring Board Attention", "Governance Timeline", "Operational Signals", "Risk & Compliance Watchlist" vs "Next meeting", "Board assurance"; buttons "Open Meeting", "Open Minutes", "Record Attendance", "Draft Minutes", "Add Item").
- ISO dates in priority cards ("Due 2026-09-06") instead of NZ format used elsewhere ("7 September 2026").
- Reassuring status chips when there is no data: Compliance "On track" with 0 obligations; Risk register "Within appetite"; Clinical "On target 100%"; My work "Up to date".
- Uppercase micro-labels + number blocks everywhere; many zero meters with no "so what".

## Home (manager)
- Very long: header 5 meters + Next meeting + My work + Board priorities + Board assurance (4 cards) + Risk & Compliance Watchlist (4 sub-cards) + Financial Governance (3 cards) + Governance Timeline + Operational Signals. Same numbers repeated 2–3× (risks above appetite: header meter, Board assurance, Watchlist).
- Redundancy: "Nothing pending for you" chip + My work meter 0 + My work card "Nothing is waiting on you".
- "Board priorities · Board-wide · 3 items — not only your own work" label directly above card titled "Priorities Requiring Board Attention / Ranked by urgency across…" (double heading).
- Priority cards: a "?" avatar circle (unassigned) with no meaning, "Unassigned · Why this matters" link, "MEETING" chip, "Critical" chip, ISO due date.
- Jargon: "PIAs", "DSR backlog", "Breaches (90D)", "Utilisation", "Variance", "Board threshold", "H&S backbone", "Above appetite", "Tracked", "0.0 h", "Open variance", "Upload evidence" on a card with 0 obligations, "Approve spend" with 0 pending.
- Contradictory signals: Board assurance "No risks above appetite… reported" while Privacy & data card is "Critical" (1 open breach) and Operational Signals "2 critical".
- "Refresh" button and "This month" period filter floating alone — unclear what they change.
- Governance Timeline "What has changed since <meeting title> on 7 Sep 2026" → "Nothing has changed since <title>.." (double full stop).

## Meeting workspace (manager)
- 8 readiness cards (Chair, Secretary, CEO report, Board pack, Quorum, Pending resolutions, Minutes, Previous follow-through) repeat on EVERY tab and push the tab's actual content below the fold at 768px; they duplicate header meters (Quorum 0/1 twice; Resolutions 0 vs Pending resolutions 0).
- Readiness card values use coloured text only (amber "Unassigned", green "Reviewed") — colour as the only signal, not StatusBadge.
- Past meeting (7 Sep) still "Scheduled" with primary "Generate pack"; nothing tells the chair "This meeting has happened — record attendance and minutes".
- Tier-2 tab tones: "Papers & resolutions" active shows amber (positional tone) which reads as a warning.
- Workflow 1/9 for manager vs 1/7 earlier as member (different totals for same meeting).

## Registers
- Resolutions meters: "Carried 0 of 0 papers", "Awaiting your vote … outstanding ballots".
- Actions empty state: "Actions are created from carried decisions and meeting follow-ups" — no way to add one and no link to where.
- Risk register: rail tabs (Risk register · Compliance · Clinical · Te Tiriti) PLUS a second view switch (Register · Heatmap · Trends) inside the filter row — two navigation levels in one header; jargon "residual scores and treatments", "Residual score 20+", "Appetite: Within", "5 · Low", "Treatments 0".
- Compliance: "Compliance centre" button jumps to a different module (operational /compliance) — two "compliance" places; "10 frameworks · 0 tracked"; "Compliance rate 0/0 0%".
- Clinical: section heading and card title identical ("Medication Errors" / "Medication Errors"); "0 COUNT"; "Automated source: Auto-fed from Health & Clinical clinical events and eMAR…"; "Automated 4 — 0 without data this period".
- Te Tiriti: 5 principle sections all showing "No obligations recorded for this principle" with no explanation of what an obligation is or an example; meters "Embedded", "Implemented or embedded"; chip "0% implemented".
- Budgets: relationship between Budgets, Spend approvals and adjustments not explained anywhere; "Pending review: Proposed or under review".
- Spend approvals: "YTD", "Capital expenditure / Operating expenditure / Supplier contract / Donor-restricted spend … requires sign-off" — no plain explanation of who signs off.
- Strategic plans: meters "Superseded", "Archived", "Drafts" all 0 dominate; "delivery horizons", "Horizon" filter.
- CEO performance: "Current cycle Q1 2026" in September; "review cycles, goals, KPIs and board ratings".
- Policies: "Attestation — active policies need sign-off"; "Human Resources · effective —".
- Board members: meter "Eligible staff 106 not yet on the board" (why would a board page show 106 staff?).
- Settings (worst): "Governance rules & electorate (D1 authority)", "Candidate rules apply strict majority quorum (floor(N/2)+1)… Recused members are excluded from presence without reducing the denominator", "Legal form: charitable_trust", "Quorum mode & formula: majority_floor_plus_one — floor(N/2)+1", "Electorate denominator (N = 1 entitled voters)", meter "Voting rules: Candidate — live voting unavailable", "Quorum 1 voters-floor(N/2)+1", chip "Rules not confirmed — live voting unavailable".

## Wizards
- Decision paper wizard: 6 steps; header "Author a decision paper — Structured decision paper authoring for informed, accountable board decisions"; step blurbs truncated in the rail ("Title, meeting, committee & bac…"); "Exact motion", "carried motion approves", "Consequential decisions evaluate real alternatives", "board determination", "Management recommendation"; completeness stuck at 45–55% with defaults; review lists 5 missing fields only at the end.
- Meeting wizard: clear tile picker ("What kind of meeting?") — good reference for plain language.

## Member (impersonated "Demo Board Member")
- Sidebar 4 links ✔; Home Next meeting/My work ✔; meeting workspace without Workflow ✔ (after fix).
- Meeting readiness cards (CEO report, Board pack, Previous follow-through) still shown to members — admin readiness information with no action for them.
- Records: breadcrumb "Records search" vs sidebar "Records" vs page title "Governance records".
