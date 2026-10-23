# Drawing changes implied by the build

The design viewer is paused (Stephan, 23 Oct). When a page is built differently from its approved drawing — a
refinement, a rule from DESIGN.md, or data the drawing assumed but the code doesn't have — it is listed here so
the viewer can be brought up to date later. Newest last.

| Drawing | What the built page does differently | Why |
| --- | --- | --- |
| All lists (20, 20e, 44 …) | Every record row has a kebab menu (and the same items on right-click) | DESIGN.md: entity rows keep the kebab and the context menu; the drawings showed right-click only |
| 20, 20b–20e | "These measures cover records you can access. The full picture may be incomplete." sits under the header | The caveat was in the filter row as dark text on the band (unreadable); the drawings left it out |
| 20 | "Needs you now" lists WorkSafe notifications to make, overdue actions, reviews waiting for acceptance and expired register items | The drawing's lone-worker and "choose an owner" rows have no source on the overview yet (backend gap) |
| 20 | "Where incidents are up to" is hidden until the overview sends incident stage counts | No invented counts (backend gap: `incident_stages`) |
| 20, 20e | Site cards show the Site name and its two counts, without the "House" type line | The comparison doesn't carry the Site type |
| 20c | "Incidents with the injury rates" keeps the rate lines over the bars | The drawing showed bars only although its title names the rates |
| 20c | A "With injury" number appears when a restraint event recorded harm; with none, the calm line "Nobody was hurt in a restraint event" | The drawing showed only the zero case |
| 44 | Hub cards use the code's names ("Corrective action traceability"); descriptions are the drawing's | Keeps the hub and the report tabs named the same |
| 80, 44 | Register and report cards show their flag and facts only when the source sends them | No invented counts (backend gaps: `register_status`, `attention`, `hub_status`) |
| 20 | Each tab can be opened from a link (`?tab=leading` …) | Shareable links; not visible in the drawing |
| 24 | The two approval choices keep the code's names and wording ("Separate specialist decision", "Authorised reviewer decision") | The approval dialog uses that wording; who can approve a plan stays described exactly as the code enforces it |
| 24, 24c | "Good to know" lines keep the code's wording (ownership, consent, qualification) | Ownership and consent wording stays word for word; the drawing had plainer rewrites |
| 24c | Routing and independent review each keep their own "Good to know" and change log | They are separate settings with separate histories; the drawing merged them into one |
| 24c | Route lanes show a person icon, not initials | The lead's name is looked up from current access after the page loads |
| 24e | The consent caveat sits as a note under the time bars; the history has its own card | The drawing didn't show the caveat or the history |
| 24–24e | Each section can be opened from a link (`?view=routing` …) | Shareable links; not visible in the drawing |
| 45 | "This period" also counts incidents | Incidents are the first thing a manager looks for; the drawing listed them only under harm |
| 45 | "Incidents by type" keeps the clickable rows under the bars | The rows open the drill-in and its right-click menu |
| 45 (Sites) | The site league is titled "Sites side by side" and the heatmap "Where the load sits" | Same plain words as the overview; the drawing didn't show this tab |
| 1, 1b–1d, 40, 42 | Filters sit on the right of the header and the date chip reads "Period: all dates" | Shared header and date-filter behaviour on every page |
| 1 | The register views use the app's tier-two tabs (icons, filled active tab) | DESIGN.md lists the plain underline strip as an anti-pattern |
| 1, 40 | No "Columns" button | No column chooser exists yet |
| 1d | No "Take ownership" button ("Assign someone" opens the assign dialog); an "Open incident" button is added; the sub-line reads "Oldest first"; the age is days since it happened | Backend gaps (`ownership.can_take`, `owner_needed_since`); the queue covers every incident the viewer can see, not only "Sites you manage" |
| 2–2i | The owner card shows no role or Site; the manager review shows no due time | Backend gaps (`ownership.owner.role`/`site`, `review_due_at`) |
| 2 | "Minute by minute" lists recorded events only, not story steps such as "House and grounds searched" | No invented steps |
| 2c | Adds "Handover to Health & safety" and "Investigation" surfaces | The page already shows that information |
| 2, 2e | Safeguarding, evidence and privacy wording stays the code's ("This does not retrieve or preserve footage" …) | Word-for-word rule |
| 2f | Adds "File added" rows; no "safety review opened" or "safeguarding raised" rows | Those have no timestamps yet (backend gap) |
| 2j | The compact header that sticks when scrolling isn't built | Not built yet; the state only appears after a lost save response |
| 3, 4 | Type is a list of incident types (an older free-text type is kept as "(saved type)"); severity has "No default"; blank rows are dropped on save | Safer, consistent choices |
| 40 | "Where it's up to" uses the existing WorkSafe labels | Same labels as the safety review |
| 41, 41j, 41k | WorkSafe wording stays the code's ("Notification to WorkSafe NZ is still pending.", "Notify ASAP", "Keep records ≥ 5 years") | Word-for-word rule |
| 41b | "From the Control room" uses the shared linked-evidence block; the playbook shows as label–value rows | The playbook has no timestamps for a log |
| 41c | The lifecycle buttons (Submit for review …) sit under the stage line | They belong to the stage, not one block |
| 41 | No "Hand over" link on the Owner card | No route or permission to reassign a review owner yet (backend gap) |
| 41h | The title reads "Hazard — Aurora House" | The hazard's own name isn't sent (backend gap) |
| 41j, 41k | Cancel and submit stay at the bottom of each pane | The panes are shared with the dialog |
| 41d, 43 | Uploading, downloading and removing an action's evidence happens on that action's Evidence tab, not in the review's action table | One place per action; same permissions |
| 42 | The third number box is "Reviews in monitoring", not "On time 86%"; cards come from the current page and a note says how many the board shows | Backend gap (`on_time_rate_30d`, first cards per stage, `closed_30d`) |
| 43 | Linked records list only the safety review, not sibling actions; the due date has no weekday | Backend gap (`review.actions`); the app's date-only format is safe across time zones |
| 43g | "I have reviewed the current owner" sits in the warning note | Keeps the header's buttons for the page's own actions |
| 21, 21b | The header rail shows the hub's pages (Incidents · Safeguarding · Fleet incidents) and the register views sit in a tier-two strip; External referrals, Closed and Assigned to me are in More filters | Same layout as Incidents, its neighbour in the hub, so the two link to each other; the drawing put the views in the header and left no way across |
| 21 | The referral warning and the table's sub-line keep the code's wording ("flagged for external referral with no report logged …", "visible only to those who need to know") | Safeguarding referral and need-to-know wording stays word for word |
| 21 | "Critical concerns" opens Open concerns filtered to Critical | The old tile had no link; every number box is a link |
| 22 | The header line leaves out the subject's name (it's in the title) and uses the recorded location, not the Site | A header line doesn't repeat the title; the concern payload carries no Site name (backend gap: `site.name`) |
| 22 | "Where things stand" also shows the current risk level, and a "No lead yet" line when nobody leads it | Both are on the record; the drawing showed neither |
| 22 | A risk review due today reads "due today"; the next step turns red only once the review date has passed | The review date is a calendar day |
| 22–22h | A "Sensitive" fact chip shows when the concern is marked sensitive | The old dialog had no sign of it; the drawing didn't show the case |
| 22b | The timeline also lists "Accessed by · need-to-know" (recent viewings) under the steps | The access log was on the old timeline; kept |
| 22d, 22e | The outcome and reply forms keep the code's labels and helper text ("Record authority acknowledgement" …) | The forms are unchanged |
| 22f | Each open action's kebab and right-click carry "Complete" | Same one-step action as the old Complete button |
| 22g | Access reads "Sensitive", "Restricted" or "Not marked sensitive", not "Everyone working on the concern" | No access rule is claimed that the code doesn't enforce |
| 22h | "Client record" shows only for a client subject; the incident link opens the incident's own page | The old link opened the legacy register dialog |
| 22i | The restricted page keeps the code's wording ("It is visible only to the assigned lead, the reporter, and staff cleared to view sensitive concerns.") | Need-to-know wording stays word for word |
| 23–23e | Next, Back and Raise concern stay in the footer under the step; the step's fields keep the wizard's own layout | The capture wizard is unchanged (its checks decide when Next is allowed); only its page frame follows the drawing |
| 23–23e | Each step on the stage line is a button, so any step can be reopened | The old step rail allowed it; kept |
| 25 | Times keep "· Pacific/Auckland", and the two invitation notes keep the code's wording; "Back to My day" sits under Your invitation | Invitation wording and its tests stay as they are; the drawing left the way back to the back arrow only |
| 104–104c | The number boxes keep the code's captions ("67% recorded coverage · 2/3 sites · 1 without a rep", "N unfinished action items across these meetings" …) and the label "Meetings with outstanding actions" | They say exactly what the records show; the drawing's shorter captions dropped "recorded" |
| 104 | "Representatives at each site" isn't shown | The page has no per-site counts or next-meeting dates (backend gap: `site_coverage` per Site with active reps and next committee date) |
| 104 | Rep cards say "N training days recorded", not "training this year"; an inactive rep shows "inactive" in the sub-line and "Site ownership not confirmed" as the flag | Training days are cumulative and annual training isn't confirmed; ownership wording stays the code's |
| 104 | The tally under the cards is "Committees and training": All meetings, and Rep training below 2 days | The two numbers beyond the header's four lead the body (header rule); the drawing's tally had only committees and meetings this quarter |
| 104 | Every rep card has a kebab and right-click menu, and opens its record | DESIGN.md entity rows |
| 104 | "Schedule meeting", More (New consultation, Export board report) and "Add representative" show only to people who manage participation | Same gate as the old New menu; Export was hidden in a right-click menu before |
| 104b | Meetings are "Coming up" and "Held" (completed or cancelled), each row with View, a kebab and right-click | The view follows the period filter, so "this quarter" isn't promised |
| 104d | The unavailable state keeps the code's wording ("Participation is unavailable" …) | Access wording stays as it is |
| 100, 100b | Restraint states keep the code's words: "Reported outside plan" / "Reported within plan", "Current revision reviewed", the "Source revision" column, "Published version recorded" / "No retained published version" | Restraint wording stays word for word; the drawing's "Within the plan", "Latest version reviewed", "Version 4" paraphrased it |
| 100, 100b | Both numbers (Needs review, Plan reviews due) show on both views; the 30-day and injury counts stay on the tabs | One header for the register; the drawing dropped the plan number on the plans view |
| 100b | The primary button reads "Add behaviour-support plan" (was "Create plan") | Drawing wording |
| 101 | While a plan is under review, the primary button is "Record approval decision" for people who can approve, matching the next step | The drawing showed "Record plan review" as primary |
| 101 | Facts read "Current candidate", "Published version" and "Review due" | The code's labels; "In use" would claim more than the publication record says |
| 101 | The stage line's decision step shows done only when a decision is on record ("Not on record" otherwise); archived plans have no stage line | Approval is never inferred |
| 101 | Overview adds "On record" (status, developer, workflow, publication) and the server's approval explanation as the note | The code showed these; the drawing's note paraphrased the explanation |
| 101d | Each approval decision is a card with every fact on record (exact candidate, approver, origin, workflow, effective dates, entered by, basis, evidence) | Nothing about an approval is dropped |
| 101b–101g | Reviews, history and connections keep the code's wording ("Actual reviewer", "Recorded status", "Identity retained", "Intervention 9") | Approval and reviewer wording stays word for word; connected interventions carry only an id |
| 102 | Facts add Severity and open follow-ups; the review-due fact shows only while a review is needed | The drawing showed the intervention and review due only |
| 102 | The side "Against the plan" is a short list (reported plan use, recorded authorization, reason for difference) rather than a "No" ring | Restraint wording stays the record's; a ring counted checks the record doesn't hold |
| 102 | "What happened" keeps the code's labels ("Alternatives attempted"), and "On record" adds actual start/end, staff involved and authorised by | Restraint wording stays word for word |
| 102b–102d, 102g | Reviews, observations and debriefs are cards listing every recorded fact with who recorded it and the source revision; "Correct …" sits in the card's head | Their fields vary by command, so none is guessed as the headline |
| 102e, 102f | Follow-ups and evidence are tables with kebab and right-click carrying each allowed command (record outcome, change owner, correct, cancel; download, remove) | DESIGN.md entity rows; same gates as before |
| 102h | Connected work lists every link, with "No accessible connection" where the viewer can't open one | The code's honesty about access is kept |
| 103, 103b | The capture forms keep their own single-column layout and Save button; the header switch between Intervention and Behaviour-support plan shows only to people who may create plans | The forms are unchanged; the old switch never worked (it passed `onChange` instead of `onSelect`) and now does |
| 60 | Flags show every flag that applies ("Rated critical", "N open actions"); "Due in 7 days" reads "Today and the next 7 days"; the other hazard views are under More filters | Every flag is kept; the caption says exactly what is counted |
| 61 | The risk map and "Highest risks first" show only when every active and under-review assessment is on the current page | They are counted from the rows; a page-only count must not pose as a total (backend gap: `risk_map`, `highest_risks`) |
| 62–62f | "Record residual risk" / "Residual risk · Not recorded" instead of "Score risk after controls" / "Not scored yet"; controls are a story (existing / additional), not a "2 in place, 2 planned" ring | Residual-risk wording stays the code's; controls aren't stored as items with a state (backend gap) |
| 64–64i | The sign-off ring turns done only after acknowledgement; "Scope" keeps its label; history adds "Recorded versions" | Reading isn't tracked; every version stays openable |
| 65, 66–66i | Findings read "N open · overdue work"; run labels keep "Completion recorded", "Improvements identified"; per-tab actions sit in the header | Drill wording stays the code's (backend gap: `findings_overdue` as a count) |
| 81–82f | Check-in and caveat wording stays the code's ("Check-in is old", "Starting is not a check-in.", "A session does not confirm worker safety…"); the overdue stage reads "Was due 9:15 am"; the next step offers only actions the viewer can take | Lone-worker safety wording stays word for word |
| 84, 87 | The treatment and injury wizards stay pop-ups over the page; the header's stage line follows the wizard's step; saving stays on the wizard's review step | Wizards are unchanged |
| 85–85g | The overview timeline is built from the treatment, its history and the safety-review delivery (no ambulance or family steps) | Those exist only inside the notes (backend gap: timed events) |
| 86, 88–88j | No "Needs an owner" note on register rows; the overview leaves out "Straight after" (it stays on Treatment behind medical access); stage dates come from history | Medical access is kept; backend gaps: coordinator per row, `reported_by`, `status_changed_at` |
