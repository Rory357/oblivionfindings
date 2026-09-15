# Governance plain-language UX audit — 14 September 2026

**Question asked:** "For a normal person it is currently difficult to understand." Audit the whole Governance module for user-friendliness; the UI must follow Rory's design rules (DESIGN.md + design_styles/).

**Who we judged for:** ordinary NZ supported-living board members — volunteers, family/community representatives, a CEO, chair and secretary — who use the app a few times a month and need to answer: *What do I need to do? What's the next meeting and what do I read? What did the board decide? Is anything wrong?*

**Method**
- Hands-on walkthrough in Chrome at 1366×768 on the local Herd site (main @ `1755a41fe`), as Demo Admin (manager) and impersonating "Demo Board Member" (ordinary member). Notes: `evidence/browser-walkthrough.md`.
- Four independent read-only code reviews covering every Governance page, dialog, wizard, the payload builders and user-facing server messages, against DESIGN.md and an eight-point usability checklist (plain language, orientation, next steps, forms, navigation, design rules, accessibility, truthfulness).
- Two P0 claims were re-verified by hand (conflict payload mismatch; vote note → conflict).

**Verdict:** the module now has the right *shape* (hubs, Event Horizon headers, wizards, member home) but it still speaks like an auditor and a developer, repeats itself, hides consequences, and has several flows that look finished but silently fail or dead-end. Roughly **260 findings**: **12 P0**, ~95 P1, ~110 P2, ~40 P3. The P0s are behavioural (wrong records, privacy, impossible jobs), not cosmetic, and should be fixed before any board member uses the module.

---

## 1. P0 — fix before real board use

| # | Problem (what a member experiences) | Where | Fix |
|---|---|---|---|
| P0-1 | **Declaring a conflict in the meeting workspace silently fails.** Workspace posts `declaration_type/declaration_text/withdrew_from_voting`; server requires `type/description/withdraw_from_voting`. No error shown; nothing recorded. | `components/governance/MeetingPaperWorkspace.tsx:270-280` · `ResolutionController.php:400-405` | One shared conflict dialog (reuse `Resolutions/_dialogs.tsx` `DeclareConflictDialog`), inline errors, test through the real route. |
| P0-2 | **Typing a note with your vote records a conflict of interest.** The optional "Vote note" is sent as `conflict_note`; `VotingService` sets `conflict_declared = !is_null($note)`; receipt says "Conflict noted" and the frozen result says so. | `MeetingPaperWorkspace.tsx:252-258, 586-598` · `Resolutions/Show.tsx:845-877` · `VotingService.php:167` | Separate `vote_note`; only a real declaration sets `conflict_declared`; backfill check for existing votes. |
| P0-3 | **A vote is final after one click with no warning or confirmation**, and nothing explains For/Against/Abstain (abstaining defeats a unanimous motion). | `MeetingPaperWorkspace.tsx:600-606` · `Resolutions/Show.tsx:824-883` | Confirm dialog "Cast your vote: For? You can't change it"; one plain line per threshold explaining how it passes. |
| P0-4 | **Live voting can never be switched on.** Activating voting rules needs a carried resolution, and carrying a resolution needs active rules; Settings' instructions go in a circle. | `Settings/_dialogs.tsx:126-144` · `VotingService.php:46-49` · `GovernanceVotingProfileService.php:141-144` | First-time path: chair records the board's existing approval (governing document, date, minutes ref) with confirmation; clear "voting is switched off" banner with who can fix it. **Needs owner decision.** |
| P0-5 | **Actions that need evidence can't be completed** — the dialog asks for "Managed storage paths" like `governance/evidence/signed-policy-v2.pdf`; no upload exists. | `Actions/_dialogs.tsx:624-706` · `ActionItem.php:254-283` | Shared `FileDropzone`/`AttachmentUploader` + upload route; list files by name. |
| P0-6 | **My work is never "up to date"** — every scheduled meeting (up to 3) counts as a pending task ("Upcoming: …"). | `GovernanceWorkQuery.php:500-555, 78` | Exclude "know" items from pending/overdue; show as "Coming up" or drop (Next meeting card covers it). |
| P0-7 | **Every member's Board priorities list chair-only voting tasks led by codes** ("Close voting for RES-2026-004"), linking to a page they can't act on. Risk/compliance/budget priorities are also not permission-filtered (403 links, possible title leaks). | `GovernanceWorkflowService.php:589-606, 639-793` | Emit open/close-voting only for `openVoting/closeVoting`; filter every source by its view permission; lead with the title. |
| P0-8 | **"Policies awaiting your sign-off" lists every approved policy**, including ones that need no sign-off and future-dated ones that fail with 422. | `GovernancePolicyController.php:272-299` · `Policies/Attestations.tsx:170` | Filter `requires_attestation` + effective ≤ today; show future policies as "Comes into effect on…". |
| P0-9 | **Compliance status never refreshes over time** — header says "On track / Overdue 0" while rows say "12 days overdue"; the Compliance Status report reads the same stale value. | `ComplianceObligation.php:64-84` · `ComplianceEngineService.php:495` | Daily scheduled status refresh, or compute overdue/due-soon from `due_date` in every query. |
| P0-10 | **A CEO performance review can never be finished** — no self-assessment UI for the CEO, no "Complete review" for the chair (routes exist), and "Board assessment: Submitted" lights up when the CEO self-submits. | `Performance/Show.tsx:206-331` · `Performance/_dialogs.tsx:394-399` · `PerformanceReviewController.php:304-335` | Reviewee "Write my self-assessment → Submit to board"; chair "Complete review" with confirmation; timeline from real events. |
| P0-11 | **Privacy: the CEO sees the board's confidential goal scores, statuses and comments before the review is completed** (only the overall rating is masked). | `PerformanceReviewController.php:33, 80-85, 144-159` · `Performance/Show.tsx:408-477` | Mask `actual_score`, `board_assessment`, derived status and scorecard actuals for the reviewee on index + show until completed; confidentiality banner. |
| P0-12 | **Budget changes are broken end to end** — only offered on draft/proposed budgets (never approved ones), "Reallocate" always refused, "Line item (optional)" is required, no Decline, Approve shown to everyone without confirmation, "Apply carried resolution" approves on select, approving a change invalidates the budget's own approval. | `Budgets/Show.tsx:1365-1752` · `BudgetController.php:118` · `GovernanceNestedMutationService.php:223-236` | Rebuild as "Budget changes" on approved budgets with From/To, Decline with reason, approver-only confirm dialogs, and a warning while the budget awaits its vote. |

## 2. P1 highlights (grouped by what goes wrong)

### Records are wrong or misleading
- **Deadlines saved ~12 hours out** — resolution voting deadlines and CEO report deadlines post NZ wall time as UTC (`Resolutions/_dialogs.tsx:469-471, 1889-1900`, `CeoReports/_dialogs.tsx:380-386`). Use `toDatetimeLocal` + server NZ parsing, as Meetings now does.
- **Follow-up actions promised "when the motion carries" are never created** (`auto_generate_actions` never set; `ResolutionController.php:196-221`).
- **"For information — no vote" papers still go to a vote** (`VotingService` never checks `purpose`).
- **Spend approvals say "Board sign-off: Required" but one person approves with no resolution** (`SpendApprovals/Show.tsx:356-439`).
- **"Escalate" says it alerts the chair and secretariat but notifies nobody**; automatic escalations say the owner escalated their own action.
- **Pending board vote shown when no vote exists (budgets)**; draft papers sit on no agenda and nobody is told.
- **Green/zero when data is missing:** CEO report KPI snapshot shows green 0s for failed widgets; Board Monthly headline shows 0 while its card says "could not be loaded"; Compliance "On track" with 0 obligations; Clinical "On target" with no data; Risk register "Within appetite" by default.
- **Counts don't match the lists they open:** Risks (active-only counts vs all-status list; heatmap "Critical" = before controls, register "Critical" = after controls), Compliance ("Due in 30 days" includes overdue/cancelled; rate counts not-yet-due as non-compliant; MSD/ACC frameworks left out of totals), Attestations "9/7 · 129%", Evaluation completion always 100%.
- **Receipts overclaim** ("cryptographic", "tamper-proof", "immutable" beside IDs built from database keys or `Date.now()` in the browser).

### Jobs that dead-end
- Accept risk (no resolution picker; always rejected) · Close risk (no UI) · Complete a risk treatment (no route/UI) · Risk trends (snapshot job never scheduled; empty state claims it is) · Compliance evidence can't be opened (no download route) · New policy version (route exists, no button) · Record actual spend on approved budgets (route, no UI) · Decline budget change · Evaluations: can't edit drafts, text comments never shown in results, blank answers give a raw 422 page · Interests: can't update or end a declaration · Board members: can only appoint staff accounts, clearing a term end silently does nothing · CEO report / committee report pages unreachable · Treasurer and CEO get no sidebar route to Finance, CEO reports or their own review (`isGovernanceAdmin` is a fixed list of manage permissions).

### Consequential actions without confirmation
Cast vote · Publish & open voting · Distribute pack · Submit CEO report / mark presented · Archive decision · Launch/close evaluation · Approve policy (and Edit can set "Active" without approval) · Apply carried resolution · Remuneration decision.

### Admin work on member screens
- 8-tile readiness grid (Chair, Secretary, CEO report, Board pack, Quorum, Pending resolutions, Minutes, Previous follow-through) repeats above **every** meeting tab and pushes papers below the fold at 768px; shows "Pending"/"Not started" incorrectly for members.
- Member-facing "Conflicts: N to check" never clears; "Papers" count is really agenda items; "Read paper & vote" shown when voting isn't possible; voting section disappears with no reason.
- Pack page is a management dashboard (distribution %, section types) rather than a place to read the pack; superseded notice links members to a 404 until the new revision is distributed.
- Recently Completed, Timeline and Governance calendar (obligations, policy reviews) are not permission-filtered.

### Home is too much for anyone
Manager Home stacks header meters, Next meeting, My work, Board priorities (6 tabs), Board assurance (4 tiles), Risk & Compliance watchlist (4 cards), Financial governance (3 cards), Timeline and Operational Signals; "risks above appetite" appears three times; contradictory signals (assurance "no issues" beside Privacy "Critical" and Signals "2 critical"). Jargon: PIAs, DSR backlog, Breaches (90D), Utilisation, Variance, Board threshold, MTTA, "H&S backbone", "WorkSafe posture". The "This month" filter only changes the collapsed signals.

## 3. Systemic causes (fix these once, not page by page)

1. **No single vocabulary.** The same thing has 3–6 names: *resolution / decision paper / board decision / carried resolution / papers & resolutions / votes*; *actions / action items / action register / follow-up / follow-through*; *board pack / pack / revision / version / v2 / edition*; *attestation / sign-off / confirm / acknowledgement*; *records / records search / governance records*; *obligations* (Compliance) vs *obligations* (Te Tiriti). Tab labels don't match page titles (Meetings→"Board meetings", CEO reports→"CEO Board Reports", Clinical→"Clinical governance", Interests→"Interests register"…).
2. **Wording is generated on the server and passes straight through.** `GovernanceWorkflowService`, `GovernanceWorkQuery`, `GovernancePresenter`, rule services and FormRequests produce Title Case labels, leading codes (RES-/ACT-/R-/CO-), raw enums (`full_board`, `no_quorum`, `charitable_trust`), US spelling (finalize, authorize, utilization) and auditor phrasing ("bound", "consumed", "terminal decision", "electorate denominator", "floor(N/2)+1", "D1 authority", "fingerprint"). Laravel default validation messages name internal fields.
3. **Jargon is never explained.** Quorum, appetite/tolerance, inherent vs residual, control effectiveness, variance, motion, carried, recuse, attest, snapshot, horizon, pillar, key result, capex/opex, YTD, KPI.
4. **Status labels and colours are re-implemented per page** (five `humanise` variants; Draft is amber in Strategy, grey in Budgets).
5. **Every promise in the copy isn't backed by behaviour or a test** (follow-ups, escalation, "no vote" papers, snapshots scheduled, "active version for members", Settings "Ordinary decision threshold" text that the engine ignores).
6. **Blocked and ineligible states are silent** — sections disappear, buttons disable behind tooltips.
7. **Permission filtering differs per data source** (My work and the meeting workspace filter; priorities, recently completed, timeline and calendar don't).
8. **Record pages have the new header on legacy bodies** — Budgets Show, CEO report Show (tabs below the hero + duplicate status strip), Packs Show, Performance Show, the four Reports pages (raw palette classes, hand-rolled pills, decorative meters, `text-3xl`), Meeting Show minutes/RSVP dialogs.
9. **Shared component bug:** `components/ui/empty-state.tsx` only renders icons when `typeof Icon === 'function'`; lucide icons are forwardRef objects, so **every empty state in the app shows a blank grey circle** (139 files).
10. **Timezone handling is inconsistent** (`toISOString()`/UTC "today" in several wizards and pages).
11. **Calendars:** Governance sources have no `--src-*` tokens (uncoloured), raw type keys, "[Vote deadline]" prefixes, meetings labelled "Manual calendar entry", every past meeting "Overdue", dead "To approve" meter.
12. **NZ content accuracy:** Te Tiriti principles pair older principles with the wrong Māori terms ("Partnership / Rangatiratanga"), missing macrons; compliance frameworks lack the Code of Rights and a disability-support funder, with three different names per framework. Needs a quality lead and a Māori advisor.

## 4. Design-rule (DESIGN.md) breaches found

Hand-rolled status pills (PriorityBadge, Reports, Packs dialog, Budgets Show) · multiple status chips per record header (Resolutions, Actions, Packs, CEO reports) · meter blocks that link to the page itself or the wrong filter (Budgets Show, Performance Show, Risk Trends, Clinical, Documents, Reports, Settings "Configuration", Board members "Eligible staff") · main tabs below the hero (CEO report Show, Budgets Show) · content between header and list (Spend approvals thresholds card, Compliance framework cards) · bespoke lists without kebab/right-click (Attestations, Interests, Audit log, Budgets tables) · ad-hoc typography (`text-2xl/3xl`, `text-[10px]`, `text-[11.5px]`) · raw palette classes + `dark:` pairs (Risk Narrative report; Home refresh banner already fixed) · dialogs with `aria-describedby={undefined}` and no description · icon-only buttons without `aria-label` (Budgets Show) · per-page button overrides (`bg-status-success`, `h-7 text-xs`, destructive variant for non-destructive steps) · Title Case headings and buttons throughout · hand-rolled steppers/timelines (Performance) · rail hidden for single-tab viewers (no Find chip).

## 5. Recommended plan

| Phase | Scope | Outcome |
|---|---|---|
| **1 — Stop the harm** | All 12 P0s; deadlines timezone; follow-up actions creation; "no vote" papers; spend approval resolution requirement; escalation notification; permission filtering of priorities/recently-completed/timeline/calendar; EmptyState icon bug | No wrong records, no private leaks, every core job completable |
| **2 — One vocabulary** | Naming table (single source next to `governance-sections.ts`) + enum label helpers + a server-side governance wording layer (titles, reasons, action labels, error/validation messages) + sentence case + NZ English + codes demoted to muted suffixes + a shared "What's this?" term hint and a short "How board voting works" explainer | Same word for the same thing everywhere; no developer text reaches users |
| **3 — Member-first screens** | Member Home trimmed to Next meeting · My work · one assurance summary; manager Home de-duplicated; meeting workspace: remove readiness grid, tabs Agenda · Decision papers · Attendance · Minutes · Workflow, member meters; pack page as a reader; confirmations for every consequential action; visible blocked-state reasons | A first-time member understands Home in 10 seconds and completes read → conflict → vote → follow-up without leaving the meeting |
| **4 — Finish dead-end flows** | Risk accept/close/treatments + trends job; compliance evidence download + status refresh; policy new version + read-and-confirm; CEO review lifecycle; budget changes + actuals; evaluations edit/comments/validation; interests update/end; board appointments for non-staff; sidebar routes for treasurer/CEO | Every button and promise works |
| **5 — Design-rule debt** | Budgets/CEO report/Packs/Performance record bodies, the four Reports pages, calendar source tokens/labels, dialogs, tables, typography | Full DESIGN.md conformance on retained pages |
| **6 — Content & people** | Te Tiriti principles and compliance framework list checked by a quality lead and Māori advisor; test with 2–3 real board members (GOV-A28) | Accurate NZ content; real comprehension evidence |

## 6. Implementation status — 15 September 2026

**Shipped to main (first half).** Foundation (labels/status chips with a TS↔PHP fixture, glossary + `GovernanceTermHint`, EmptyState icons, hub/tab renames, vocabulary guide, DESIGN.md anti-pattern) and wave 1:

| Area | Result |
|---|---|
| Voting, conflicts, resolutions, Settings | **P0-1, P0-2, P0-3, P0-4 fixed.** One shared ballot + conflict dialog (meeting workspace and resolution page); `vote_note` column with guarded data repair; vote confirmation, receipts, plain outcome sentences; real "at least two-thirds For" rule; NZ-time deadlines; follow-up actions created on pass; information/discussion papers can't go to a vote; chair records the board's existing approval of voting rules (first activation only); "Voting is switched off" banner; Settings rebuilt as "How the board votes"; plain server/validation messages. |
| Home, My work, priorities, calendar | **P0-6, P0-7 fixed.** Upcoming meetings are "Coming up", not pending; every priority source permission-filtered, voting admin only for people who can do it; plain server wording layer (`GovernanceWording`); one assurance summary, no duplicated metrics, manager-only timeline/signals; Recently completed, timeline, calendar and dashboard widgets audience-filtered; calendar source tokens, labels, "Minutes due". Also fixed: completed votes never shown (wrong table), member checklist crash, pack read tracking, packs shown as unavailable. |
| Finance, strategy, CEO reviews, navigation | **P0-10, P0-11, P0-12 fixed.** CEO's board scores masked on the server until completion; self-assessment → board assessment → complete review; budget changes on approved budgets with approver-only confirmed decisions and server thresholds; record actual spend; approval status from the linked resolution; spend approvals needing the board require a covering resolution; strategic plans read-only once approved, no "TBD"; plain authority messages; hub navigation for treasurer/CEO/reviewee. `SpendApprovalAuthorityTest` held two classes so 13 tests never ran — split and fixed. |
| Coordinator | Meeting page no longer sends attachment storage paths or vote hashes and carries ballot state; plain fallback action verbs; unused calendar component removed. |

Verification (merged with origin/main): Pest `tests/Feature/Governance tests/Unit/Governance tests/Feature/Sites/Calendar` **548/549**, the one failure a stale wording assertion, corrected and re-run **1/1**; Vitest (changed + sidebar + Sites calendar) **179/179**; ESLint 96 changed files clean; `tsc --noEmit` clean; production build succeeded.

**Decisions taken during implementation (owner to confirm):** "Move money between lines" removed from budget changes (one resolution can only approve one record — request a decrease and an increase instead); `special_majority`/`three_quarters` legacy values keep "More For than Against" and new ones are rejected (a 75% option needs an owner decision).

### Second half — 15 September 2026 (all 12 P0s now fixed)

| Area | Result |
|---|---|
| Actions, packs, CEO reports, board members, interests, evaluations, audit log | **P0-5 fixed** (evidence uploaded to private storage, listed by name). Escalation notifies chair and secretary; automatic escalations name no person; pack page reader-first with confirmed sending; CEO reports "Not available" for missing figures and locked once submitted; any approved person can be appointed; interests update/end (own only); evaluation drafts editable, inline validation, anonymous comments; audit log in sentences with hidden records masked. Bugs fixed: other members could edit your interest declaration, anonymous evaluation respondents were named, escalation downgraded critical actions, CEO report attachment paths leaked, audit date filters. |
| Risk, compliance, care quality, Te Tiriti, policies, documents, records, reports | **P0-8, P0-9 fixed.** Read-and-confirm list, receipts, re-confirmation, new policy versions; compliance status from due dates + daily refresh, evidence download; before/after controls wording, accept needs a passed resolution, close risk, complete treatments, monthly risk records; care quality like-for-like periods; Te Tiriti on the Hauora principles as owned commitments; documents keep file names; Records adds approved budgets and plans; four report pages to DESIGN.md with sanitised CSV exports. |
| Meeting pages | Readiness grid manager-only, member preparation meters, vocabulary tabs, plain minutes/RSVP dialogs with inline errors, consistent committee/type and schedule-or-cancel edits, reviewee "My performance review" in My work, budget-change links, stored quorum results repaired. |
| Coordinator | A generated pack no longer locks the meeting or blocks a new pack version; shared labels for new statuses/audit events/frameworks/principles; My work lists only policies that ask to be confirmed; report links shown only to people who can open them. |

Verification (second half): Pest Governance + Unit Governance + Sites calendar + Compliance dashboard + schedule architecture **648/649**, the one failure an intended label change, corrected and re-run **10/10**; Vitest **269/269** (40 files); ESLint 79 changed files clean; `tsc --noEmit` clean; production build succeeded.

**Still open (owner decisions and follow-ups):**
- **Decided 15 September 2026:** budget approval limited to finance approvers (chair, treasurer, admin; migration `2026_09_15_720000`); evaluation results open only after the evaluation closes.
- **Decisions still open:** `performance.view` holders get 403 on the performance list; managers can send a CEO self-assessment on the CEO's behalf; a 75% voting rule.
- **Content:** Te Tiriti principle descriptions (Māori advisor) and the compliance framework list (quality lead); then GOV-A28 testing with real board members.
- **Small follow-ups:** committee meetings linking to their committee risk view and report; `DashboardAggregatorService::getTopRisks()` critical count vs the register and fake `getDataFreshness()` times; re-confirmation-due policies in My work; `GovernancePresenter::complianceStatus()` possibly unused; Dusk chair journey spec needs the Workflow tab; signed-in browser walkthrough of both halves.

**Owner decisions (14 September 2026):** implement phases 1–5; board decisions are called **Resolutions**; policy sign-off is **Read and confirm**; voting rules are first switched on by the **chair or secretary recording the board's existing approval** (governing document, date approved, minutes reference). The approved wording lives in `vocabulary.md`.

Full per-area findings with file:line references and suggested wording are in `findings-member-journey.md`, `findings-decisions-board-settings.md`, `findings-risk-policies-records.md` and `findings-finance-strategy-navigation.md` in this folder.
