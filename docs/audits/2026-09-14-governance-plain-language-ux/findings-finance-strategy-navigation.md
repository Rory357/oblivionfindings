# Findings — finance, strategy & performance, navigation

Code review, 14 September 2026, read-only. Part of audit.md in this folder.

- **[P0] The CEO review can never be finished, and the pop-up points to a control that doesn't exist** — `resources/js/pages/Governance/Performance/_dialogs.tsx:394-399`, `Performance/Show.tsx:206-227, 312-331` (+ `PerformanceReviewController.php:304-315, 322-335`, `routes/governance.php:208, 215`)
  - *What a board member experiences:* The CEO opens their review and sees "Self assessment — Pending". There is no button to write or submit it. The edit pop-up says "Goals, KPIs and the self-assessment are managed on the review page", but they aren't. The board can't read a self-assessment either, because the Show page never receives the text (only `self_assessment_submitted_at`). "Submit Assessment" puts the review at `board_review`, and nothing on screen moves it to Completed. The `/self-assessment` and `/approve` routes have no UI. The rating and decision are hidden from the CEO until Completed, so the CEO never sees the outcome. Also, "Board assessment — Submitted" turns on as soon as the CEO self-submits (`PerformanceReview.php:143` sets `board_review`; `Show.tsx:204, 218`), before the board has scored anything.
  - *Rule/heuristic:* C, H (dead end; the page claims something that isn't there).
  - *Fix:* Add a "Write my self-assessment" panel for the reviewee (textarea plus "Submit to the board", with a confirmation). Show the submitted text to assessors. Add "Complete review" for the chair: optional board resolution, with the confirmation "Complete this review? The CEO will see the rating, decision and notes." Base the timeline on real events: board assessed = `overall_rating` set, not status. Until then, remove the "managed on the review page" sentence.

- **[P0] The CEO can see the board's confidential goal scores before the review is completed** — `app/Domain/Governance/Http/Controllers/PerformanceReviewController.php:33, 80-85, 144-159`; `Performance/Show.tsx:408-477`
  - *What a board member experiences:* The overall rating is hidden from the reviewee, but each goal's board score ("3 / 4"), its status ("Partially achieved") and its progress bar still show. `goals[].board_assessment` (board comments) and `scorecard.pillars[*].goals[*].actual` are in the page data. The index also loads `goals` with every field. Board members would assume their scoring stays private until sign-off.
  - *Rule/heuristic:* H, plus the privacy focus of this brief.
  - *Fix:* For a reviewee who can't assess, clear `actual_score`, `board_assessment` and the derived `status` on every goal, and the scorecard pillar scores and goal actuals, on both index and show. Show "The board's scores are shared when the review is completed." Add a banner on both performance pages: "Confidential — visible only to the reviewee, the chair and the oversight committees."

- **[P0] Budget changes ("adjustments") are broken from start to finish** — `resources/js/pages/Governance/Budgets/Show.tsx:1365-1369, 1413-1429, 1699-1752` (+ `BudgetController.php:118`, `GovernanceNestedMutationService.php:223-236`, `routes/governance.php:270`)
  - *What a board member experiences:* The tab says "Requests to modify the approved budget". But "Request Adjustment" only appears while a budget is Drafting or Proposed (`canEdit`), so an approved budget can never be changed.
    - "Reallocate" is offered, but the server always refuses it ("Unsupported one-sided reallocation…").
    - "Line Item (optional)" is actually required ("A budget line item is required…").
    - There is no Reject button, although the route exists.
    - "Approve" appears for every viewer. It isn't gated by permission and has no confirmation, so a viewer without approval rights hits a 403.
    - "Apply carried resolution…" is a dropdown that approves as soon as you pick "Apply RES-…", with no confirmation and no title or amount shown.
    - Approving a change on a *proposed* budget edits its lines. The budget's own board approval then fails with "has changed since the resolution was bound to it".
  - *Rule/heuristic:* C, D, H.
  - *Fix:*
    - Rename the tab "Budget changes" and allow "Request a budget change" on approved budgets only.
    - Remove Reallocate, or build "Move money between lines" with From and To.
    - Label the field "Budget line *".
    - Add "Decline change" with a required reason.
    - Show Approve/Apply only to approvers, behind a confirmation: "Apply the board's approval? Resolution 'Title' (RES-…) approved increasing Support worker wages by $5,000. The line becomes $125,000."
    - Block, or warn about, approving changes while the budget awaits its vote: "This budget is waiting for the board's vote. Changing it now means the board must vote again."

- **[P1] Nothing explains how budgets, spend approvals and budget changes differ, or what "apply carried resolution" means** — `Budgets/Index.tsx:197`; `SpendApprovals/Index.tsx:166`; `Budgets/Show.tsx:1552-1561, 1721`
  - *What a board member experiences:* "Plan, approve and monitor financial budgets…" sits next to "Board and finance-committee sign-off for spend above configured thresholds". The adjustment warning reads "requires a carried board resolution. After you submit, bind a decision paper to this adjustment; once it is carried, apply it from the adjustment row." A volunteer can't tell which of the three things they are looking at.
  - *Rule/heuristic:* A, B.
  - *Fix:* Use one-line explainers on each page:
    - Budgets: "The yearly spending plan the board approves."
    - Budget changes: "Moving an approved amount up or down on one line."
    - Spend approvals: "Permission for a single purchase or contract over the limit."
    - Replace the warning with: "Changes of 5% or more of the budget need a board decision. After you send this request, the board secretary adds it to a board paper. When the board passes it, an approver records the approval here."
    - Rename "Apply carried resolution" to "Record the board's approval".

- **[P1] Budget figures look authoritative but can't be kept up to date, and they contradict each other** — `Budgets/Show.tsx:282-308, 471-498, 615-720, 1298-1350`; `Budgets/Index.tsx:166-175, 229-282` (+ `BudgetController.php:118`, `GovernanceNestedMutationService.php:596-600`, `routes/governance.php:255`)
  - *What a board member experiences:*
    - Once approved, nobody can record actual spend. Editing lines is blocked, and `record-actuals` has no UI (the `actualsDialogOpen` state at `Show.tsx:193` is unused). "Actual $0 · Remaining $1.5m · Utilization 0.0%" therefore reads as "we've spent nothing".
    - The header "Variance" shows budget − actual (positive means under budget), captioned "Remaining". The summary card and table "Variance" show actual − budget (positive means over, in red). The same word carries opposite signs on one page.
    - The index "Spent" block adds up every budget: drafts, rejected, superseded versions and past years.
    - The "Approved" block says "In effect" for every approved budget ever.
  - *Rule/heuristic:* H, A ("Allocated", "Forecast", "Variance", "Utilization" are never explained).
  - *Fix:* Add "Record actual spend" (per line, or bulk) for approved budgets, and show "Actuals last recorded {date}" or "Actual spend not recorded yet" instead of $0. Use one convention: "Over budget by $X" or "Under budget by $X". Scope index totals to approved budgets for the current financial year: "Spent this year (FY2025/26)". Change "In effect" to "Approved this year".

- **[P1] "Pending board vote" is shown when there is no vote, including after the board voted it down** — `Budgets/Show.tsx:511-607, 746-779` (+ `Budget.php:122-155, 182-186, 217-221`; `BudgetController.php:119, 324`)
  - *What a board member experiences:*
    - "Submit for Approval" (flash: "Budget proposed to board.") creates a **draft** paper that sits on no agenda. The page still says "Pending Board Vote — Voting not yet completed" and "Awaiting Board Vote", and nobody is told the secretary must add the paper to a meeting.
    - If the board rejects it, the button still says "Awaiting Board Vote" and the banner shows "Outcome: defeated". There is no withdraw or re-submit (`canPropose` requires Drafting).
    - "Approve Budget" appears when the result is carried but voting isn't closed, and then fails with "Only a closed resolution with a carried outcome…".
    - Budgets proposed before bindings existed fail with "…was not explicitly bound…" and have no fix.
    - Editing or adding a line to a proposed budget silently invalidates it; only the wizard warns (`_dialogs.tsx:478-485`), and "Add Line Item" (`Show.tsx:818`) does not.
  - *Rule/heuristic:* H, C.
  - *Fix:*
    - Show a status based on the paper: "Board paper drafted — not yet on a meeting agenda" (with "Open paper"), then "On the agenda for {meeting}", "Voting open", "Passed — ready to record approval", "Not passed".
    - On a failed vote, offer "Return to drafting". Allow re-submitting a proposed budget whose paper is stale or missing.
    - Put the invalidation warning on every structural edit while Proposed.
    - Say what happens next in the submit confirmation: "This creates a board paper for the secretary to add to a meeting. Changing the budget after this means preparing a new paper."

- **[P1] Technical approval-rule errors reach users as toasts** — `GovernanceNestedMutationService.php:225, 235, 303, 321, 328, 337, 350, 660, 682, 707`; `GovernanceResolutionAuthorityService.php:50, 294, 346, 350, 354, 596, 626, 650, 678`; `Budget.php:184, 219`; `StrategicPlan.php:145, 171`; `PerformanceReview.php:185, 219`; `SpendApprovalCommandService.php:236, 258, 395, 728`
  - *What a board member experiences:* `FlashToaster` shows the first validation message raw. Users see enum values in quotes ("(status: implemented, outcome: carried)", "direction 'increase'…"), unformatted money ("(5000) does not match … (5000.5)"), words like bound, consumed, authority, digest, decision key and "terminal decision", and US spelling ("authorize").
  - *Rule/heuristic:* A, C.
  - *Fix:* Say what happened and what to do.

    | Current message | Replacement |
    |---|---|
    | "Unsupported one-sided reallocation…" | "Moving money between lines isn't available. Record a decrease on one line and an increase on the other." |
    | "A budget line item is required for an adjustment." | "Choose which budget line this change applies to." |
    | "…must be carried and closed… (status…)" | "This resolution can't be used yet — voting must be finished and the result recorded as passed." |
    | "…must explicitly specify the authorized financial cost impact amount." | "The resolution doesn't state the dollar amount approved. Ask the secretary to add it to the resolution's cost section." |
    | "(5000) does not match (5000.5)" | "The board approved $5,000.00 but this change is for $5,000.50." |
    | "…was not explicitly bound to this {x} before it was published for voting." | "This resolution wasn't linked to this {budget} before the board voted, so it can't approve it. Prepare a new board paper that names it." |
    | "…authority … has already been used." | "This resolution has already been used to approve this." |
    | "The {x} has changed since the resolution was bound…" | "The {budget} was edited after the board paper was prepared, so the board hasn't approved these figures. Put the updated version to the board." |
    | "…reached a terminal decision." | "This change has already been approved or declined." |
    | "Decision authority can only be bound while the paper is a draft." | "You can only change what a board paper approves before it's published." |
    | Spend "evidence has changed" / "decision key" / "digest" messages | "This request changed after it was submitted. Refresh the page and review it again." |

    Format money as NZD.

- **[P1] The sidebar gives no route to Finance, Strategy or CEO pages for the people who use them** — `resources/js/components/app-sidebar.tsx:1791-1801, 1828-1842` (+ `GovernancePermissionsSeeder.php:184-283`; `GovernanceRecordsController.php`)
  - *What a board member experiences:*
    - `isGovernanceAdmin` only counts the manage permissions for meetings, actions, settings, performance, policies, documents, evaluations, packs and resolutions.
    - A treasurer (`budgets.create/submit`, `spend.request`) and the CEO (`ceo-reports.manage`, reviewee) get only Home, My work, Calendar and Records, with no entry to Budgets, Spend approvals, CEO reports or their own review.
    - Ordinary members hold `budgets.view/approve` and `strategy.view`, but Records search only covers documents, meetings, resolutions and policies. "What is our strategic plan?" or "Are we within budget?" has no route.
    - Home only links to *proposed* budgets and pending changes.
  - *Rule/heuristic:* E.
  - *Fix:* Decide "admin" per hub: show a hub entry when the viewer can manage or decide anything in it (budgets create/submit/approve, spend request/approve, strategy manage, ceo-reports manage). For ordinary members, add "Strategic plan" and "Budgets" as record types in Records, or as Home quick links. Give the CEO a "My review" link in My work.

- **[P1] Budget page breaks the page-header and list design rules** — `Budgets/Show.tsx`:
  - 457-466: status is a hand-rolled `Badge` in lowercase ("under review"), plus a second "v2" chip.
  - 471-498: all three meter blocks link to the page itself.
  - 515, 563, 595: raw `<Button>` primaries on the header sky, with `bg-status-success` overrides; not `PageHeaderPrimaryButton`.
  - 782-804: shadcn `Tabs` below the header instead of the `TierTwoTabs` strip.
  - 615-720: bespoke stat cards that repeat the header, with `text-2xl`.
  - 1060, 1910, 2256: hand-rolled tables with no `EntityTable`, kebab or right-click menu.
  - 1032-1056, 1599, 2007, 2249: bare empty states.
  - 829-836, 1380-1383, 2041-2043, 2426-2429: dialogs with `aria-describedby={undefined}` and no description.
  - 1216-1237, 2353-2363: icon-only buttons with no `aria-label`.
  - Title Case throughout ("Submit for Approval", "Add Line Item").
  - *What a board member experiences:* Numbers repeated in two places, meter blocks that go nowhere, and a layout unlike the other Governance pages.
  - *Rule/heuristic:* F (page header rules, "Dead or decorative meter blocks", hand-rolled status pills, list rules, POPUP guide, typography), G.
  - *Fix:*
    - Title plus one `PageHeaderStatusChip`; put the version in the fact subline.
    - Meters: Budget (bar: actual against budget) → Line items; Over-budget lines → filtered lines; Pending changes → Budget changes; Board decision → the resolution.
    - One `PageHeaderPrimaryButton`.
    - Views Lines · Budget changes · By category · Monthly split, on `TierTwoTabs`.
    - `EntityTable` with a kebab menu, `EmptyState`, `DialogDescription`, and sentence case.

- **[P1] Spend approvals say "Board sign-off: Required", but one person approves with no board decision** — `SpendApprovals/_dialogs.tsx:436, 468, 503-513`; `SpendApprovals/Index.tsx:280-288`; `SpendApprovals/Show.tsx:96-102, 356-439` (+ `SpendApprovalCommandService.php:262`)
  - *What a board member experiences:* The wizard says the amount "will require a board resolution". The approve form has no resolution field, and approval goes through without one. The threshold rules also contradict each other. Tiles say "Sign-off from $5,000.00" and the thresholds card says "requires sign-off", implying smaller amounts need nothing. The wizard says "Amounts below the threshold are decided without a board resolution" but still requires a request. Nobody says who approves below the threshold.
  - *Rule/heuristic:* H, A.
  - *Fix:* When `requires_board`, require picking the board resolution. Otherwise show "Needs a board decision — link the resolution before approving." Explain once: "Up to $X: approved by a finance approver. $X and over: needs a board resolution." Rename the card "Who approves what".

- **[P1] Strategic plan approval uses technical rule words and offers a button that can't work** — `Strategy/_dialogs.tsx:520, 1032`; `Strategy/Show.tsx:334-340, 547-551, 910-926` (+ `StrategicPlanController.php:130-139`)
  - *What a board member experiences:*
    - Text reads "approved later by a bound, carried board resolution", "Bind a decision paper to it", "…explicitly bound to this version before voting".
    - "Approve plan" opens a dialog saying "No bound resolution is ready… Only unused resolutions bound to this plan are listed."
    - Resolutions are only listed when their status is `closed`, while budgets also accept `implemented`/`archived`, so a passed resolution can go missing.
  - *Rule/heuristic:* A, C, H.
  - *Fix:* Use "Approved by the board when a board paper naming this version passes." Only show "Record board approval" once a passed paper exists. Otherwise show a status line: "Waiting for a board paper — the secretary prepares one from Resolutions" with a link. Match the budget status list.

- **[P1] The new CEO review wizard only lists board members** — `Performance/_dialogs.tsx:494-507` (+ `PerformanceReviewController.php:121-134, 337-346`); `Performance/Index.tsx:216`
  - *What a board member experiences:* The chair opens "New review" for the CEO and sees "Select a board member". A staff CEO usually isn't one, so they can't be chosen. Cycles only cover the current calendar year, so last year's annual review can't be created. The "All reviews" caption always reads "Current cycle Q1 {year}", even in September.
  - *Rule/heuristic:* D, H.
  - *Fix:* List eligible reviewees (the CEO role, then executives) with the placeholder "Select the CEO or executive". Offer cycles for the previous, current and next financial year (NZ). Work out the current cycle from today's date.

- **[P2] Spend approval request page: decision form, errors and "no actions" message** — `SpendApprovals/Show.tsx:253-313, 356-439, 496-503, 205-244`
  - *What a board member experiences:*
    - Clicking Approve or Reject in the right column opens a form in the left column, possibly off-screen, and focus doesn't move.
    - The reason box has only a placeholder.
    - Errors appear only as a toast.
    - The site the spend is for (a required field) is never shown.
    - The requester sees "No actions available to you for this request." with no reason (a requester can't decide their own request).
    - The "Amount" meter links to the page itself; the "Version 3" caption is technical.
  - *Rule/heuristic:* C, D, G, F.
  - *Fix:* Put the decision in a dialog with a visible "Reason for your decision *" label and inline errors. Make Approve the header primary for deciders. Show Site. Explain: "You requested this, so another approver must decide it." Replace the self-links and version caption.

- **[P2] Budget wizard jargon and a risky shortcut** — `Budgets/_dialogs.tsx:109-113, 412, 487-499, 615-674, 723-786, 798, 815-821`
  - *What a board member experiences:*
    - Step "Envelope" ("Budget envelope").
    - "Forecast (NZD) — defaults to the budget" and "Account code" are unexplained.
    - "Fiscal year e.g. 2026" is ambiguous for an NZ financial year (2025/26?).
    - "created in drafting".
    - The description "A guided wizard to author a board budget, its budgeted lines and envelope."
    - A one-click "Already approved by the board" skips the whole approval with no date, minute or confirmation.
    - The review step shows the description only as "Added".
  - *Rule/heuristic:* A, D, C.
  - *Fix:*
    - Step "Total".
    - Hints: "Forecast: what you now expect to spend (optional)" and "Account code: from your accounting system, if known".
    - Fiscal year label "Financial year (e.g. 2025/26, 1 July–30 June)".
    - Description "Set up a yearly budget for the board to approve."
    - When "approved outside this system" is ticked, require the approval date and minutes reference.
    - Show the description text in review.

- **[P2] Budget change dialog: threshold copy** — `Budgets/Show.tsx:1531-1568`
  - *What a board member experiences:* The check is `>=`, but the text says "exceeds the 5% threshold ($75000.00)", with the raw number. The same rule is then repeated underneath: "Adjustments exceeding 5% of total budget will require board resolution." The 5% is hard-coded in the browser, separately from the server (`Budget.php:272`).
  - *Rule/heuristic:* A, H.
  - *Fix:* "Changes of $75,000 or more (5% of this budget) need a board decision." Format as NZD, show it once, and take the limit from the server.

- **[P2] Monthly split ("Allocations") promises sites but doesn't ask for one, and can fail with a 404** — `Budgets/Show.tsx:2022-2028, 2054-2072, 2185-2198, 2353-2363` (+ `BudgetController.php:440-447`, `GovernanceNestedMutationService.php:714-716`)
  - *What a board member experiences:* "Distribute this annual budget across sites and months… (Finance side)", but the form has no site field. Saving without a site needs `reports.viewAny`, otherwise it returns a 404 page. "Period (YYYY-MM)" uses a developer format. The category is free text, unlike the line categories. Delete has no confirmation.
  - *Rule/heuristic:* D, C, H.
  - *Fix:* Add a Site picker (or drop "sites" from the copy). Use a month picker labelled "Month". Reuse the line categories select. Add a confirmation: "Remove this monthly amount?"

- **[P2] Budgets list: the "Board approval" column contradicts Status** — `Budgets/Index.tsx:58-64, 407-415, 471-485`
  - *What a board member experiences:* Drafting and Rejected budgets both show "Pending approval". The filter says "Pending review" while rows say "Proposed". "v2" is unexplained.
  - *Rule/heuristic:* H, A.
  - *Fix:* Drop the column, or show "Approved {date}" / "—". Filter label "Waiting for board". Subline "Version 2 (replaces version 1)".

- **[P2] Budget links from Home land on the wrong tab** — `Budgets/Show.tsx:782` (links from `GovernanceWorkflowService.php:742-753`)
  - *What a board member experiences:* "Review budget adjustment" opens on Line Items, and the pending change is a tab away.
  - *Rule/heuristic:* E.
  - *Fix:* Support `?tab=adjustments` and link to it.

- **[P2] Plans store "TBD" and show it to the board** — `StrategicPlanController.php:179-180`; `Strategy/_dialogs.tsx:685-689, 710-713`; `Strategy/Show.tsx:495, 507`
  - *What a board member experiences:* The Vision and Mission cards read "TBD".
  - *Rule/heuristic:* H, A.
  - *Fix:* Store null and show an `EmptyState`: "Vision not written yet."

- **[P2] Strategy wizard vocabulary** — `Strategy/_dialogs.tsx:104-137, 152-155, 509-512, 598-615, 882-1005`
  - *What a board member experiences:*
    - "Pillar", "Key results" and "Planning horizon" have no help text.
    - The horizon isn't checked against the dates (a 3-year plan with a 1-year period is allowed).
    - The timeframe placeholder shows raw ISO dates ("Defaults to 2026-07-01 - 2029-06-30").
    - The pillar list includes "IT resilience" but nothing about the people supported.
    - An approved plan can still be edited and saved; there is only a warning.
  - *Rule/heuristic:* A, D, H.
  - *Fix:*
    - Hints: "Pillar: the strategic theme this goal belongs to" and "Key results: how you'll know the goal is met (measurable)".
    - Suggest period dates from the horizon, and format dates.
    - Make approved plans read-only and send users to "New version".

- **[P2] Strategic plan page presents progress nobody can record** — `Strategy/Show.tsx:370-452, 707-756, 1019-1024, 453-479` (+ `StrategicGoal.php:101-122`)
  - *What a board member experiences:*
    - Goal progress and key-result status only change through initiatives. These pages have no control to update either, so "0%" and "0/5 key results achieved" read as fact.
    - A key result that simply hasn't started shows a warning triangle.
    - "New version" says "branched from this plan… keep their lineage".
    - The "Approval" meter goes to a list of other plans.
  - *Rule/heuristic:* H, G, A, F.
  - *Fix:* Show "Progress not tracked yet" when nothing has been recorded, and use a neutral circle icon for not started. Use "Create version {n}: a copy of this plan you can update and put to the board." Link the Approval meter to the resolution.

- **[P2] "Changes" page shows raw values and only covers goals** — `Strategy/Changes.tsx:143-146, 197-223` (+ `StrategicPlan.php:268-294, 346-358`)
  - *What a board member experiences:* Rows read "Status: not_started → in_progress" and "Pillar: it_resilience → safety". The page uses "Compared with Version 2 snapshot" and "No prior approved baseline or snapshot exists…". Vision, mission and value changes are never compared, although the page is called "Changes". The filter row disappears when there's nothing to compare.
  - *Rule/heuristic:* A, H, F ("Filterless rail tabs").
  - *Fix:* Use labels ("Not started → In progress", "IT resilience → Safety"). Subline "Compared with the version the board approved". Empty state "This is the first version, so there's nothing to compare yet." Title "Goal changes", or include the direction fields.

- **[P2] Hub tab names don't match page titles; some labels are vague or clash** — `resources/js/lib/governance-sections.ts:74-110, 139-158, 187-209, 236-273, 302-309`
  - *What a board member experiences:*

    | Tab | Page title |
    |---|---|
    | Meetings | Board meetings |
    | Board packs | Board Packs |
    | CEO reports | CEO Board Reports |
    | Action items | Actions |
    | Clinical | Clinical governance |
    | Strategic plan | Strategic plans |
    | Members | Board members |
    | Interests | Interests register |
    | Evaluations | Board evaluations |

    The Governance "Finance" hub shares its name with the app's Finance module. Hub "Decisions & actions" holds "Resolutions", while pages say "decision paper" and "board decision". Members see "Records", managers see "Records search". The Roadmap tab leaves the hub: the Roadmap page has no `GovernanceSectionRail`, so there is no rail back. Sidebar groups are "Board business / Oversight / Board admin", and the sidebar code comment still says "Meetings & decisions".
  - *Rule/heuristic:* E, A.
  - *Fix:*
    - Make each tab label equal its page title, in sentence case: Board meetings, Board packs, CEO reports, Action items, Clinical governance, Strategic plans, Board members, Conflicts of interest, Board evaluations.
    - Rename the hub "Board finance".
    - Pick "Resolutions" everywhere (or "Board decisions") and use "Records" in both places.
    - Add the rail to Roadmap, or remove it from the hub.

- **[P2] Status names and colours differ page to page, with no shared label map** — `Budgets/Index.tsx:66-74`; `Budgets/Show.tsx:461, 1652, 1815-1826`; `SpendApprovals/_dialogs.tsx:154-168`; `Strategy/_dialogs.tsx:165-184`; `Performance/_dialogs.tsx:69-101`; `resources/js/lib/status-colors.ts`; `lib/governance-status.ts`
  - *What a board member experiences:*
    - "Drafting" vs "Draft".
    - "Proposed" vs "Submitted" vs "Pending review" vs "Submit for Approval".
    - Draft is amber in Strategy but grey in Budgets and Spend; superseded is blue in Strategy but grey elsewhere.
    - Lowercase raw values ("under review", "approved", "(carried)").
  - *Rule/heuristic:* A, F (StatusBadge rules).
  - *Fix:* Add `governanceStatusLabel()` and a variant map next to `governanceStatusColor`: Draft, Waiting for board, Approved, Not approved, Replaced, Archived. Use it on all four modules.

- **[P2] "Discard this draft?" appears when editing existing records** — `resources/js/components/governance/DiscardDraftDialog.tsx:27-35`
  - *What a board member experiences:* Closing the edit wizard for an approved budget asks "Discard this draft?" with a red "Discard". Budgets have a "Drafting" status, so this reads like deleting the budget.
  - *Rule/heuristic:* A, C.
  - *Fix:* Title "Discard your changes?", button "Discard changes". Only say "draft" in create mode.

- **[P2] Spend approvals list layout and "YTD"** — `SpendApprovals/Index.tsx:89-97, 186-219, 268-292` (+ `SpendApprovalController.php:59-63`)
  - *What a board member experiences:*
    - A thresholds card sits between the header and the list.
    - "Approved YTD" / "Rejected YTD" count the calendar year, not the NZ financial year.
    - The "Pending" meter includes drafts nobody submitted, shown in amber.
    - Filter label "Pending (draft + submitted)".
  - *Rule/heuristic:* F (nothing between header and list), A, H.
  - *Fix:* Move thresholds into an info popover or the subline. Use "Approved this financial year". Split the meters into "Waiting for decision" (submitted) and "Drafts". Filter labels "Waiting for decision" and "Drafts".

- **[P2] CEO review page layout and wording** — `Performance/Show.tsx:236, 258, 261-297, 551-578, 608-827`; `Performance/Index.tsx:159, 318`
  - *What a board member experiences:*
    - Three of the four meters link to the page itself.
    - The timeline is a hand-rolled stepper.
    - "Rating —" doesn't say it's hidden until completion.
    - The assessment dialog uses Title Case ("Overall Rating", "Board Decision", "Submit Assessment").
    - "Score (1-5)" has no meaning attached.
    - A remuneration decision is submitted with no confirmation, and rating/decision can also be set from a second place (the Edit wizard).
    - The raw cycle "2026-Annual" appears in breadcrumb, subline and list.
    - "KPIs" is unexplained.
  - *Rule/heuristic:* F, A, C, D.
  - *Fix:*
    - Meters link to sections (#goals, #kpis), plus "Board decision".
    - Use the shared timeline or `StatusBadge` list.
    - Scale hint "1 Not met · 3 Met · 5 Well exceeded".
    - Confirmation: "Save the board's assessment? The CEO won't see it until the review is completed."
    - Keep one place to rate.
    - Show cycle labels ("2026 annual review") and write "Key performance measures (KPIs)".

- **[P3] Hub rail disappears for single-tab viewers** — `resources/js/components/governance/GovernanceSectionRail.tsx:48, 52`
  - *What a board member experiences:* Someone who can only see Budgets gets no rail and no Find chip. The rail's aria label says "Finance registers".
  - *Rule/heuristic:* F ("Rail without the Find chip"), A.
  - *Fix:* Render a one-tab rail with Find, and use aria "Board finance pages".

- **[P3] Old edit links open nothing, with no message** — `resources/js/components/governance/governance-dialog-deep-link.ts:26-46`
  - *What a board member experiences:* An old `/budgets/{id}/edit` link to an approved budget lands on the page and nothing happens.
  - *Rule/heuristic:* C.
  - *Fix:* When the flag is present but not allowed, show an info toast: "This budget is approved and can no longer be edited."

- **[P3] US spelling and small copy** — `Budgets/Show.tsx:673` ("Utilization"), `:814` ("totaling"), `:2008` ("summarize"); server "authorize/authorized" (see the error-message finding); `SpendApprovals/Show.tsx:304-310, 337` ("Currency NZD", "by unknown"); `SpendApprovalController.php:211` ("Spend approval approved."); `Performance/Show.tsx:241` (CEO name in the browser tab title)
  - *Rule/heuristic:* A.
  - *Fix:* Use "Utilisation", "totalling", "summarise", "authorise". Remove the Currency row. Use "Decided by a former user" and "Spend request approved.". Use the tab title "Performance review".

### Patterns across these pages
- **No plain-language glossary.** Carried, bound, decision paper, envelope, variance, allocation, pillar, key result, horizon, snapshot, lineage and KPI appear with no explanation. A shared "What's this?" explainer (popover or `InfoCard`) with approved wording would fix most of the language findings at once.
- **One board decision, five names.** Resolution, decision paper, board decision, board resolution and carried resolution are all used, often led by the code "RES-…" rather than the title. Pick one term and always show the title first.
- **Approval-rule messages were written for auditors** and reach users unchanged through `FlashToaster`. The rule services need a user-message layer that says what happened and who fixes it.
- **Backend routes with no screen create dead ends:** record actuals, decline a budget change, CEO self-assessment, complete a review, withdraw or re-submit a budget. Meanwhile the UI offers things the server refuses (Reallocate, an "optional" line, Approve for non-approvers).
- **Status labels and colours are re-implemented on every page** (five `humanise` variants). A central label and variant map in `lib/governance-status.ts` is needed.
- **Record pages have the new header on top of legacy bodies.** Meters link to the page itself, the Budgets page carries bespoke cards, tabs and tables, and timelines are hand-rolled.
- **The sidebar's "admin" check is a fixed list of manage permissions,** not "which hubs can this person act in". Treasurers, the CEO and ordinary members lose finance, strategy and CEO entries, and Records doesn't cover them.
- **Periods use calendar years** ("YTD", "Q1 2026", fiscal year "2026") in an NZ organisation that reports by financial year.
