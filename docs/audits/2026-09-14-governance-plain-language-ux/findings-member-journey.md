# Findings — member journey (Home, My work, Calendar, Meetings, meeting workspace)

Code review, 14 September 2026, read-only. Part of audit.md in this folder.

This is a code-level audit (read-only, no browser). Every claim below was checked against the files cited.

- **[P0] Declaring a conflict from the meeting workspace always fails, and nothing tells the member** — `resources/js/components/governance/MeetingPaperWorkspace.tsx:273-279` (+ `app/Domain/Governance/Http/Controllers/ResolutionController.php:400-405`)
  - *What a board member experiences:* They fill in "Declare Conflict of Interest" and press "Record Declaration & Recuse". The dialog stays open, the button re-enables, and no conflict is recorded. They may then think they are barred from voting, or vote while conflicted.
  - *Why it fails:* The workspace posts `declaration_type`, `declaration_text` and `withdrew_from_voting`. The server requires `type` and `description`, so validation fails. `submitConflict` has no `onError`, so no error is ever shown. The canonical dialog in `Resolutions/_dialogs.tsx:727-732` sends the correct keys.
  - *Rule:* C, H.
  - *Fix:* Reuse the Resolutions conflict dialog, or send `type`, `description`, `withdraw_from_voting` and `withdraw_from_discussion`. Show validation errors inline and gate success on `!flash.error`. Add a test that posts through the real route.

- **[P0] Typing a note with your vote records the vote as "conflict declared"** — `MeetingPaperWorkspace.tsx:586-598` and `:252-258` (+ `app/Domain/Governance/Services/VotingService.php:167`)
  - *What a board member experiences:* The optional box is labelled "Vote Note (optional) — Optional context or reason for your vote…". Anything typed there is sent as `conflict_note`. The server then sets `conflict_declared = true`, and the receipt shows "Conflict Noted". The member has unknowingly put a conflict on the formal record.
  - *Rule:* H, D.
  - *Fix:* Remove the field, or send it to a real vote-comment field. A conflict must only be declared through the conflict flow.

- **[P0] One click casts a permanent vote, with no confirmation and no warning** — `MeetingPaperWorkspace.tsx:600-606`
  - *What a board member experiences:* Choosing "For (Yes)" and pressing "Submit Vote" records the vote straight away. The server refuses any change afterwards ("already cast a vote", `VotingService.php:153-158`), but the UI never says a vote is final.
  - *Rule:* C (confirm consequential actions).
  - *Fix:* Add a confirm step that follows the POPUP guide:
    - Title: "Cast your vote?"
    - Body: "You are voting **For** '{motion}'. You can't change your vote after it is recorded."
    - Buttons: "Cancel" / "Cast vote".
    - Near the options, add: "Your vote is final once recorded."

- **[P0] "My work" counts upcoming meetings as pending tasks, so members are never "up to date"** — `app/Domain/Governance/Services/GovernanceWorkQuery.php:500-555`, `:78`; shown at `Dashboard.tsx:177-190, 276-294`, `MyWork/Index.tsx:490-503`
  - *What a board member experiences:* Every scheduled meeting (up to 3) becomes a "Pending" item titled "Upcoming: {meeting}" with a "View Meeting" button. The header reads "3 pending for you" even when nothing needs doing. "Nothing pending for you" and "Up to date" can never appear while meetings are scheduled.
  - *Rule:* H, B.
  - *Fix:* Leave `know` items out of `pending` and `overdue` (and the rail count). Show them in a separate "Coming up" group, or drop them, since the Next meeting card already covers this.

- **[P0] Every member's Board priorities list chair-only voting tasks, led by internal codes** — `app/Domain/Governance/Services/GovernanceWorkflowService.php:589-606`; rendered by `BoardPriorityCard.tsx:140-148`
  - *What a board member experiences:* Cards say "Close voting for RES-2026-004" or "Open voting for draft RES-2026-004". The real decision is demoted to the detail line ("Resolution: …"). The button "Open Resolution" goes to the separate resolution page, not the meeting workspace. Members read these as their own jobs, which they cannot do.
  - *Why:* Meeting tasks are checked with `$user->can('update')`, but resolution tasks are not.
  - *Rule:* A, H, E.
  - *Fix:*
    - Only emit open/close-voting items for users who pass `openVoting`/`closeVoting`.
    - Lead with the plain title: "Voting closes {date}: {title}".
    - Link through `decisionWorkspaceHref`.

- **[P1] An 8-tile admin status grid sits above every tab of the meeting workspace** — `resources/js/pages/Governance/Meetings/Show.tsx:1187-1193`, `:2648-2797`
  - *What a board member experiences:* Before the agenda or papers they see Chair, Secretary, "CEO Report: Pending", "Board Pack: Not started", Quorum, "Pending Resolutions", Minutes and "Previous Follow-through: Open items", in two rows of cards. At 1366×768 this pushes papers below the fold.
  - *Why it is wrong:*
    - It repeats the header's Quorum meter.
    - It shows "Pending" for committee meetings where the CEO report is "not applicable".
    - It shows "Not started" when a pack exists but hasn't been sent to the member (`visiblePack` is null).
    - Text colour is the only signal of status.
  - *Rule:* B, F ("Page tops that bypass the header" — numbers belong in the meter row), G, H.
  - *Fix:* Delete the strip. For members, put a "Your preparation" meter set in the header (Pack read · My votes open · Conflicts · Attendance). Keep admin status in the manager-only Workflow tab.

- **[P1] Members can only declare a conflict while voting is open and before they vote — but Home tells them to do it first** — `MeetingPaperWorkspace.tsx:544-619` (+ `GovernancePresenter.php:346-349`, `NextMeetingCard.tsx:308-322`)
  - *What a board member experiences:* Home says "Check 2 decisions and declare any conflict before voting". For a draft paper, or one they can't vote on, the paper shows no voting section, no conflict button and no explanation.
  - *Rule:* C, E.
  - *Fix:*
    - Always show a "Declare a conflict of interest" action on papers in draft or open.
    - When voting isn't available, say why: "Voting hasn't opened yet — the chair opens voting at the meeting." / "You're not a voting member for this committee." / "Voting closed on {date}."

- **[P1] "Conflicts: N to check" never clears** — `app/Domain/Governance/Support/GovernancePresenter.php:346-349`; `NextMeetingCard.tsx:293-305`
  - *What a board member experiences:* The count only drops when a conflict is *declared*. A member with no conflicts, or who has already voted, sees "2 to check" permanently.
  - *Rule:* H, C.
  - *Fix:* Leave out papers the member has voted on. Add a "No conflict" confirmation per paper, or reword to "{n} decisions on this agenda — declare a conflict if you have one" with a neutral badge.

- **[P1] Home's "Papers" count is really the number of agenda items** — `GovernancePresenter.php:307-309, 366-369`; `NextMeetingCard.tsx:236-257`
  - *What a board member experiences:* "5 papers — 5 agenda items are available for you to read". Items like "Apologies" count as papers, and the link opens the Agenda tab, where only some items have a paper.
  - *Rule:* H, A.
  - *Fix:* Count visible resolutions (papers) plus attachments. Label it "Decision papers", link to `?tab=resolutions`, and make "Agenda" its own row.

- **[P1] Meeting workspace: tabs in the wrong order, and four names for one thing** — `Show.tsx:846-871`, `:963-971`, `:2403-2429`
  - *What a board member experiences:* Tabs run "Agenda / Attendance / Minutes / Papers & resolutions / Workflow", so the thing they came to do is fourth, behind post-meeting minutes. The same records are called "Papers & resolutions" (tab), "Resolutions — Decisions filed" (meter), "decision papers" (card), "Votes" (Home) and "Resolutions tabled for this meeting" (empty state). "Tabled" is jargon.
  - *Rule:* A, B, E.
  - *Fix:*
    - Tab order: Agenda · Papers & decisions · Attendance · Minutes · Workflow (managers).
    - Use "Decision papers" everywhere. Meter caption: "{n} to read · {m} open for your vote".
    - Empty state: "No decision papers yet. Papers appear here when the secretary adds them to the agenda."

- **[P1] The decision paper is full of governance and technical jargon** — `MeetingPaperWorkspace.tsx:351-363, 372, 375-382, 398, 413-416, 465, 476, 491, 514, 522, 549, 627-634, 686`
  - *What a board member experiences:* Strings they must decode include:
    - "Frozen Decision Paper: All votes evaluate these exact terms as frozen upon opening", "Immutable Snapshot"
    - "Exact Motion", "Alternatives Evaluated", "Consequential decisions evaluate alternatives…", "Sole Option Justification"
    - "Impact & Governance Assessments", "Conflict Declared — Recused from Voting", "Your seat is excluded from quorum calculations"
    - "Nature: material" (raw value), and `decision_type` shown raw with CSS capitalize (underscores stay)
    - "Cast Your Vote in Context", "Official Decision Results"
  - "Confirmed: No direct financial cost." also appears when no cost information was entered at all.
  - *Rule:* A, H.
  - *Fix:* Use sentence-case, plain copy:
    - "What the board is asked to decide" (motion)
    - "This paper is locked — everyone votes on this exact wording"
    - "Why this is before the board"
    - "Options considered" / "Why there is only one option"
    - "Management's recommendation"
    - "Cost", "Effect on the people we support and safety", "Risk and fairness"
    - "You declared a conflict, so you won't vote on this decision"
    - "Result: Passed / Not passed"
    - "No cost information given" when `cost_impact` is null
    - Map enum values to labels.

- **[P1] Receipts claim more than they prove, and some are made up** — `MyWork/Index.tsx:840-843`; `MeetingPaperWorkspace.tsx:648-652`; `Show.tsx:565, 1168-1172` (+ `GovernanceWorkQuery.php:610, 721, 774`)
  - *What a board member experiences:*
    - "Durable cryptographic or audit receipt proving completion" — but IDs like "ACT-RCP-12" and "VOTE-RCP-9" are made from database IDs.
    - "Official tamper-proof record of your vote" — with no paper version or reference shown, although the spec requires an exact version and time.
    - The RSVP "Receipt ID" is built in the browser (`RSVP-{id}-{Date.now()}`), ignoring the server's ID, and only appears if the dialog is reopened, because it closes on success.
  - *Rule:* H, C.
  - *Fix:*
    - Wording: "Record of completion" / "Your vote is recorded".
    - Show the vote time, the paper version (as `Resolutions/Show.tsx:921` does: "paper v{n}") and the server receipt ID.
    - Show the RSVP confirmation inline after the dialog closes, using the server's `receipt_id`, or drop it.

- **[P1] Governance calendar: entries have no colour, show raw codes, and include records members can't open** — `resources/js/lib/governance-calendar-adapter.ts:10-43`; `app/Domain/Governance/Services/GovernanceCalendarQuery.php:60-79, 112, 131-136, 172-177, 191`; `resources/css/app.css:296-346`
  - *What a board member experiences:*
    - Sources `meetings`, `decisions`, `obligations` and `policies` have no `--src-*` tokens, so dots, bars and chips render uncoloured.
    - Type chips show raw values (`full_board`, `audit_risk`, framework and category keys) because no `eventTypes` map is passed.
    - Titles are prefixed "[Vote deadline] …" and "[Policy review] …".
    - Meetings are labelled "Manual calendar entry" (`SiteCalendar.tsx:2343-2345`).
    - Any meeting whose start time has passed shows **Overdue** unless its status is completed, signed or archived.
    - Obligations and policy reviews aren't filtered by permission, so members see entries whose links return 403.
  - *Rule:* F (calendar standard, tokens), A, G, H.
  - *Fix:*
    - Add the four `--src-*` token triples.
    - Pass governance `eventTypes` labels, drop the bracket prefixes and set `group: 'auto'` with origin "Board meetings".
    - Only mark a meeting "Minutes due" (not overdue) once it is over.
    - Filter obligations and policies by `compliance.view` / `policies.view`.

- **[P1] Home fails the 10-second test** — `resources/js/pages/Governance/Dashboard.tsx:233-384`; `resources/js/pages/Governance/Cockpit/CockpitLayout.tsx:147-248`
  - *What a board member experiences:* The page stacks all of this:
    - a title chip and up to 5 meters, a period filter, Refresh, search and a primary button;
    - Next meeting with 5 rows, and My work with 5 items;
    - "Board priorities" with 6 tabs and 8 cards;
    - "Board assurance" with 4 tiles, repeating the 3 header meters;
    - an "Operational Signals" accordion and "Recently Completed".
  - "What do I need to do?" and "is anything wrong?" are answered three times each, in different words.
  - *Rule:* B.
  - *Fix:*
    - For members, keep: header meters (My work · Next meeting · one "Needs board attention" meter), then Next meeting + My work, then one assurance summary.
    - Collapse Board priorities to the top 3 with "See all".
    - Remove Operational Signals from the member view.
    - Add a one-line subline: "What you need to do, your next meeting, and anything the board must know."

- **[P1] Board priorities mix codes, jargon and records members may not be allowed to open** — `GovernanceWorkflowService.php:639-640, 682-683, 719-720, 745-746, 793`
  - *What a board member experiences:*
    - "Review risk R-2026-003 — Above appetite (score 16): …"
    - "Complete obligation CO-01"
    - "Complete ACT-12" (description hidden in the detail line)
    - "Review budget adjustment (reallocation) — $12000.5 - …" (raw enum, unformatted money)
  - The risk, compliance and budget queries take no user, so they are not filtered by permission. Their "Open … register →" links can return 403.
  - *Rule:* A, H, E.
  - *Fix:*
    - Lead with the record title and put the code in a muted suffix. Examples: "Risk needs review: {title}", "Action due: {description}".
    - Explain the score: "Risk is higher than the board has agreed to accept".
    - Format money as NZD and map the enums.
    - Filter each source by the matching `view` permission.

- **[P1] Risk and budget jargon on Home is never explained** — `Dashboard.tsx:334-352`; `resources/js/components/governance/AssuranceSummaryPanel.tsx:209-221, 230-236, 269-280`; `resources/js/components/governance/PriorityOverviewPanel.tsx:129-140`
  - *What a board member experiences:* "Risks above appetite", "outside tolerance", "Obligations overdue", "statutory or compliance obligations", "Budget variance", "Material — beyond ±5% of budget". The "Risks" tab empty state claims "All tracked risks are within appetite" and "Policies" claims "Attestations are up to date" when those tabs are merely empty in the priorities list, not verified.
  - *Rule:* A, H.
  - *Fix:*
    - Labels: "Risks above agreed limit", "Legal/compliance deadlines missed", "Spending vs budget". Caption example: "5.2% over budget (board flags anything over 5%)".
    - Add a tooltip or "What's this?" link.
    - Empty states: "No risks need board attention right now."

- **[P1] "Recently Completed" shows every member unfiltered titles, with wrong labels** — `GovernancePresenter.php:674-794`; `resources/js/components/governance/RecentlyCompletedRail.tsx:37-71`
  - *What a board member experiences:*
    - Risk, action, minutes and policy titles are not filtered by record access, unlike My work, which uses `canViewActionItem`.
    - Voided risks are labelled "Risk closed".
    - Approved policies arrive as `policy_approved`, which has no label entry, so they show "ACTION COMPLETE".
    - Titles lead with codes ("ACT-12 …").
  - *Rule:* H (and the "private titles never leak" release gate).
  - *Fix:* Scope each source like its register. Add `policy_approved` → "Policy approved". Label voided risks "Risk removed". Drop codes from titles.

- **[P1] "Read paper & vote" appears on every agenda item that has a paper** — `Show.tsx:1513-1523`
  - *What a board member experiences:* The button shows even when voting is closed, the paper is a draft, or the member can't vote. It suggests action they can't take.
  - *Rule:* H.
  - *Fix:* Show "Read paper & vote" only when `status==='open' && can_vote && !my_vote`. Otherwise "Read paper".

- **[P1] "My work" item types use jargon labels that change between pages** — `MyWork/Index.tsx:155-160, 214-220, 546-593`; `resources/js/components/governance/MyNextActionsRail.tsx:73-78`
  - *What a board member experiences:*
    - Filters read "Act (2)" and "Know (3)"; meters "Awareness & updates".
    - Home calls the same types "Action" and "Update".
    - Type badges use severity colours: every "Act" is red, every "Vote" is amber.
  - *Rule:* A, G, H.
  - *Fix:* Labels "Vote", "Read", "Do", "For your information", identical on both pages, rendered as neutral chips. Keep colour for due status only.

- **[P1] Server-written task wording reaches members as developer text** — `GovernanceWorkQuery.php:220, 244, 325-326, 335, 380-381, 390, 468, 478, 531, 541, 643, 755`
  - *What a board member experiences:*
    - "Legacy resolution has missing voting deadline; prompt governance manager review."
    - "Read Board Pack (Rev 2) — …; explicit reading acknowledgement required."
    - "Attest Policy: …" / "Governance policy review and formal attestation required."
    - "Scheduled in 3 day(s)."
    - "Vote cast as FOR."
    - "Read: … Pack (r2)"
    - Buttons "Cast Vote", "Read Pack", "Attest Policy", "Open Action", "View Meeting" in Title Case.
  - *Rule:* A.
  - *Fix:*
    - "No voting deadline has been set — the secretary has been told."
    - "Read the board pack for {meeting}" / "Please confirm you've read it before the meeting."
    - "Read and confirm: {policy}"
    - "In 3 days."
    - "You voted For."
    - Buttons in sentence case: "Vote", "Read pack", "Read policy", "Update action".

- **[P1] Minutes tab shows members hashes and "immutable" wording** — `Show.tsx:1900-1910, 2216-2267, 2299-2334, 2078, 2100-2104, 2137-2139`
  - *What a board member experiences:*
    - "SHA-256: 3fa91c0b2d...", "Lifecycle Status", "Signatory Attestation", "Legacy attribution unavailable", "Signed & immutable"
    - "This version is locked and immutable. Direct in-place edits are prevented to protect governance record integrity."
    - "Internal Attestation Notice … organizational sign-off" (US spelling)
  - *Rule:* A.
  - *Fix:*
    - Hide hashes behind a "Record details" disclosure for audit users.
    - "Status", "Signed by", "Approved minutes can't be edited. The secretary can start a correction if something is wrong."
    - NZ spelling: "organisational".

- **[P2] Home's "This month" filter barely changes anything visible** — `Dashboard.tsx:369-377` (+ `DashboardController.php:59-79`)
  - *What a board member experiences:* The period only feeds the collapsed Operational Signals widgets. My work, Next meeting, the assurance meters and priorities don't change.
  - *Rule:* F ("never a decorative pill that filters nothing").
  - *Fix:* Replace it with a real filter (e.g. "Show: Everything / Needs my action / Board-wide"), or apply the period to a visible "Changed this month" view.

- **[P2] Headings, buttons and dialogs mix Title Case and US spelling** — examples:
  - `PriorityOverviewPanel.tsx:300` "Priorities Requiring Board Attention"; `RiskComplianceWatchlist.tsx:142`; `FinancialGovernancePanel.tsx:135`; `BoardPackPanel.tsx:46`; `OperationalSignalsAccordion.tsx:141`; `RecentlyCompletedRail.tsx:84`; `GovernanceTimeline.tsx:94`
  - `Show.tsx:1206, 1219, 1228, 1587, 1605, 1820, 1890, 2023, 2048, 2073, 2129, 2210`
  - `GovernanceWorkflowService.php:177, 188, 205, 216, 273` ("Finalize Minutes"), `:313, 339` ("Review & Vote")
  - *Rule:* A, POPUP guide (sentence-case verbs).
  - *Fix:* Sentence case throughout, NZ spelling ("Finalise minutes"). Fix it in the backend `action_label`s too, because `resolveActionVerb` passes them through.

- **[P2] Hand-rolled status pills and ad-hoc headings** — `PriorityOverviewPanel.tsx:299, 314-323`; `resources/js/components/governance/PriorityBadge.tsx:15-21, 51-56`; `MeetingPaperWorkspace.tsx:372, 695-708` (`text-2xl font-bold`); `Show.tsx:1069-1103` (bespoke RSVP banner); `MyWork/Index.tsx:409-418` (`className="h-7 text-xs"`)
  - *Rule:* F (StatusBadge, typography helpers, per-page button restyling).
  - *Fix:* Use `<StatusBadge>` and `.text-section-title`, and remove the Button className overrides.

- **[P2] Overdue work in My work gets red "destructive" buttons** — `MyWork/Index.tsx:409-418`
  - *What a board member experiences:* "Cast Vote" on an overdue item looks like a delete button.
  - *Rule:* F, G.
  - *Fix:* Use the `default` variant; the overdue badge already signals urgency.

- **[P2] "Why this matters" is mouse-only and never actually explains** — `resources/js/components/governance/BoardPriorityCard.tsx:161-174, 75-79`
  - *What a board member experiences:* The trigger is a non-focusable `<span>`, so keyboard users can't open it. The text only repeats the status ("Open and awaiting board action."), and `blocked` items get no text at all.
  - *Rule:* G, B.
  - *Fix:* Make it a button, or show the real reason inline (e.g. the risk title and why it's above the limit).

- **[P2] Board priority due dates show as raw ISO dates** — `BoardPriorityCard.tsx:135`
  - *What a board member experiences:* "Due 2026-09-20" here, but "Due 20 Sep 2026" in My work.
  - *Rule:* A.
  - *Fix:* Use `formatDateOnly(action.due_date)`.

- **[P2] "Confirm attendance/quorum for {meeting}" is flagged a week before it can be done** — `GovernanceWorkflowService.php:494-507`
  - *What a manager experiences:* A "High", due-soon priority reading "Present 0 / Required 3". But the checklist itself says attendance "Will be marked when meeting commences" (`:187`), so the task can't be completed yet.
  - *Rule:* A, H.
  - *Fix:* Only raise it on or after the meeting day. Wording: "Record who attended {meeting}" / "{present} of {required} needed for decisions to be valid."

- **[P2] Operational Signals and Timeline show abbreviations and raw event names to members** — `OperationalSignalsAccordion.tsx:73, 141-147` (+ `GovernancePresenter.php:1559-1560, 1611-1612`); `GovernanceTimeline.tsx:169-180` (+ `GovernancePresenter.php:796-799`)
  - *What a board member experiences:*
    - "MTTA" appears; only the first 4 metrics show, so MTTR is cut off.
    - "Health & safety backbone … WorkSafe posture".
    - Timeline badges show `humaniseEventType` output like "RESOLUTION.VOTED", because it doesn't replace dots.
  - *Rule:* A.
  - *Fix:* Hide Operational Signals from ordinary members, or rename metrics ("Average time to respond"). Map audit event keys to phrases like "voted on".

- **[P2] Governance calendar has a dead meter and a misleading "Mine" count** — `pages/sites/calendar/SiteCalendar.tsx:1836-1862` (adapter doesn't set `showApprovalMeter: false`); `GovernanceCalendarQuery.php:55, 82`
  - *What a board member experiences:* "To approve — awaiting sign-off" is always a green 0. "Mine — you own or attend" counts only meetings where they are chair or creator, not meetings they attend.
  - *Rule:* F (dead meter blocks), H.
  - *Fix:* Set `showApprovalMeter: false` for governance. Count invited or RSVP'd meetings as "mine".

- **[P2] The Calendar and Meetings pages drop the member's Home rail** — `pages/Governance/Calendar/Index.tsx:41-46`; `pages/Governance/Meetings/Index.tsx:444`
  - *What a board member experiences:* Home and My work show Overview / My work / Calendar / Records. Calendar switches to calendar views, and Meetings (reached via "All meetings") shows the manager hub rail (Meetings / Board packs / CEO reports). The way back changes from page to page.
  - *Rule:* E.
  - *Fix:* Add a "← Governance home" glass button in these headers for members, or a shared rail variant.

- **[P2] Meetings list: kebab items that don't work, and "—" for quorum** — `Meetings/Index.tsx:198-202, 606-618`
  - *What a board member experiences:* "Workflow checklist" silently opens the Agenda tab for members, because `Show.tsx:413` ignores it. The Quorum column shows "—" both for future meetings and for meetings that missed quorum.
  - *Rule:* E, H.
  - *Fix:* Only include that menu item when the viewer can run the meeting. Show "Not met" as a warning badge for held meetings and "—" only for future ones, with the column header tooltip "Enough members present for decisions".

- **[P2] Meeting wizard lets managers set contradictory or unsafe values** — `Meetings/_dialogs.tsx:590-650, 826-838, 524`
  - *What a manager experiences:*
    - Step 1 accepts "Finance Committee" with committee "None (full board)", or "Full Board" with a committee.
    - Edit exposes a free "Status" select including "Minutes signed" and "Minutes approved", which skips the sign and approve controls.
    - The dialog description reads "A guided wizard to schedule…" even when editing.
  - *Rule:* D, H.
  - *Fix:*
    - Derive the committee from the type, or show committee only for committee types.
    - Remove the lifecycle statuses from edit (allow Scheduled/Cancelled only).
    - Edit description: "Change this meeting's details."

- **[P2] Minutes and vote errors can look like success** — `Show.tsx:698-707, 745-753, 768-776`; `MeetingPaperWorkspace.tsx:260-266`
  - *What a board member experiences:* The server returns problems via `back()->with('error')` (e.g. "Voting deadline has passed", stale version). That triggers `onSuccess`: dialogs close, the vote choice clears, and `minutesError` (read from `errors.error`) is never set. Only a toast hints at the problem.
  - *Rule:* C.
  - *Fix:* Check `page.props.flash.error` in `onSuccess`, keep the dialog open, and show the message inline.

- **[P2] Workspace dialogs don't follow the POPUP guide** — `Show.tsx:1224, 1610, 1944, 2076, 2132` (`aria-describedby={undefined}`); `:1107-1184` (RSVP: no description, category Select instead of tiles, one notes field shared between apology reason and dietary notes); `MeetingPaperWorkspace.tsx:869-878` (native `<select>`, "Related party transaction", "Prejudicial bias or loyalty conflict", "Record Declaration & Recuse")
  - *Rule:* F (POPUP guide), A, G.
  - *Fix:*
    - Add a DialogDescription to each.
    - RSVP: a tile picker "Attending / Sending apologies / Not sure yet", with separate apology and dietary fields.
    - Conflict: plain options ("Personal or financial interest", "A family member or close associate is involved", "Something else that could look unfair"). Button "Declare conflict", with the consequence stated first: "You won't vote on this decision."

- **[P2] Meeting header meters are admin numbers that raise false alarms** — `Show.tsx:940-971`
  - *What a board member experiences:* Only 3 meters: "Quorum 0/3 — Quorum pending" in warning colour for every future meeting, "Scheduled items" and "Decisions filed". Nothing about their own preparation.
  - *Rule:* B, F (4–6 meters), H.
  - *Fix:* Member meters: "Board pack — Read / To read", "Your votes — {n} open", "Conflicts — {n} declared", "Attendance — Attending / Not confirmed". Show Quorum only on or after the meeting day.

- **[P2] Manager-only Home panels have wrong buttons and jargon** — `BoardPackPanel.tsx:77-86`; `RiskComplianceWatchlist.tsx:142-146, 160-175`; `FinancialGovernancePanel.tsx:156`; `MeetingReadinessPanel.tsx:143, 203`
  - *What a manager experiences:*
    - "Pack not yet generated… generate the board pack", but the button says "Upload board pack" and opens the meeting page.
    - "privacy gaps", "Open PIAs", "DSR backlog", "Board threshold", "Open variance" (goes to Finance, leaving Governance), "Secretariat steps".
    - An unknown checklist status shows as its raw key.
  - *Rule:* A, E.
  - *Fix:* Button "Open meeting to build pack". Expand the abbreviations and label outbound links "(opens Finance)".

- **[P2] Paper list: every row has a primary button plus a second way to vote** — `Show.tsx:2461-2485`
  - *What a board member experiences:* Each row has a filled "Read paper" button and a "Full record" link to the resolution page, which has its own vote and conflict forms. Two ways to vote on one paper.
  - *Rule:* B, E.
  - *Fix:* Make the row itself open the paper, use one outline button, and move "Full record" into the paper's own header.

- **[P2] After voting, "Next paper" is only at the top of the page** — `MeetingPaperWorkspace.tsx:330-342, 643-679`
  - *What a board member experiences:* They vote at the bottom of a long paper and get no prompt to move on.
  - *Rule:* C (approved "return to the next required item").
  - *Fix:* Add "Next paper: {title} →" (or "Back to papers") inside the receipt card.

- **[P2] My work: the "unavailable sources" warning is written for developers** — `MyWork/Index.tsx:690-696, 723-724`; `MyNextActionsRail.tsx:143-146, 168`
  - *What a board member experiences:* "Some source systems could not be reached (board packs, action items). … Missing records from unavailable sources are not marked as completed." and "Nothing found in the sources that loaded".
  - *Rule:* A.
  - *Fix:* "We couldn't load your board packs right now, so this list may be missing items. Try again in a few minutes."

- **[P3] Unused code and payload** — `resources/js/components/governance/KpiBand.tsx` (never rendered); `GovernancePresenter.php:105, 109, 119-228` (`kpi_band`, `calendar_events`, unscoped policy-attestation %, run on every Home load); `Show.tsx` never reads `meetingCockpit`
  - *Fix:* Delete them or wire them up.

- **[P3] Same destination, different labels** — `Dashboard.tsx:156-160` ("Prepare meeting" vs "Prepare for meeting"); `Meetings/Index.tsx:232` ("Board meetings" vs sidebar and breadcrumb "Meetings"); `Calendar/Index.tsx:40` ("Governance Calendar" vs "Governance calendar"); pack buttons "Read pack" / "Open pack" / "View pack" / "Read board pack"
  - *Fix:* Use one label per destination.

- **[P3] Small polish items**
  - My work builds its own pagination instead of `LaravelPagination` (`MyWork/Index.tsx:763-821`).
  - The "Owner" column always says "You" (`:374-381`).
  - "Search personal work..." uses three dots, not an ellipsis (`:522`).
  - Receipt dialog width uses a className instead of an inline style (`:834`).
  - The wizard asks for quorum as a %, but the workspace shows a head count ("0/3") (`_dialogs.tsx:807`, `Show.tsx:948`).
  - Tile grids use `gap-3` (`AssuranceSummaryPanel.tsx:299`).
  - The meeting back chip goes to Meetings rather than where the member came from (`Show.tsx:904`).

## Patterns across these pages

- **Much of the confusing wording comes from the backend.** `GovernanceWorkflowService`, `GovernanceWorkQuery` and `GovernancePresenter` write titles, reasons and button labels in Title Case, lead with codes (RES-/ACT-/R-/CO-), leak enum values and include developer phrases. Only 'Open' is re-mapped (`resolveActionVerb`); every other backend label passes straight through. A single governance wording layer (label maps plus sentence-case verbs) would fix Home, My work and the checklists together.
- **No single name for each thing.** Papers / resolutions / decisions / votes; board pack / pack / pre-read; action items / actions / follow-up / follow-through; RSVP / attendance / apologies; Act / Action / Do; Know / Update / Awareness. Pick one member-facing term each and record it in DESIGN.md.
- **Jargon is never explained.** Quorum, appetite, tolerance, variance, motion, recuse, attest, carried, immutable, SHA-256, obligation. A shared "What does this mean?" tooltip or glossary, used in meters and paper sections, would serve lay board members.
- **Receipts overclaim.** "cryptographic", "tamper-proof" and "immutable" appear next to IDs made from database keys or built in the browser. Receipts should show only what the server actually records: time, version, receipt ID.
- **Admin work leaks onto member screens.** The meeting status grid, unfiltered Board priorities, readiness counts that never clear, and "Board priorities" alongside "My work" all put chair and secretary work on the ordinary member's path. Member screens should show what's theirs; admin detail belongs behind Workflow and the manager panels.
- **Permission filtering is inconsistent across data sources.** Risk, compliance and budget priorities, Recently completed, Timeline, and calendar obligations and policies skip the viewer checks that My work and the meeting workspace apply. That gives counts and links that return 403, and possible private-title leaks.
- **The vote and conflict flow is built twice and has drifted.** The meeting workspace and `Resolutions/Show` + `_dialogs` implement it separately; the workspace copy has the broken conflict payload, the note-as-conflict bug and no receipt version. Both should use one shared component. Forms across Show.tsx also ignore the known flash-error → `onSuccess` behaviour.
- **Show.tsx still has legacy markup inside the new header.** Title-Case cards, dialogs without descriptions, hand-rolled banners and Select pickers remain under the new PageHeader and need a POPUP, typography and StatusBadge pass.
