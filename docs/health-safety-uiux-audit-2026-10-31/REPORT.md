# Health and safety — why it feels hidden and weird (UI/UX audit, 31 Oct 2026)

Walked live on oblivionfindings.com on 31 Oct 2026 at a 1440 × 900 viewport, light mode, as Demo Admin (50), Demo Manager (51,
provider_manager), Demo Team Lead – Kauri House (207) and Support Worker 1 (56), using the app's own audited
"Impersonate" for the last three. Screenshots are named by role: `a` admin, `m` manager, `t` team lead, `s` support worker
(`shots/`). Code facts come from a helper's read of the routes, controllers and pages (file:line in `WORKFLOW-FACTS.md`).
Starting list: the H&S build session's hand-over (30 Oct). Demo data is fictional; nothing here depends on a real house.

---

## The five-minute version

**What is wrong, in one line each**

1. **One accident lives in two records that disagree.** The incident and its "safety review" each have their own owner,
   progress line, close list, title and severity words. For the same fall, the incident says "Waiting for an owner" and
   "Safety review: done"; the review says "Corrective action, stage 3 of 5" and "Awaiting independent verification".
2. **The next step often has no button.** An action says "Choose a new owner" but only offers "Start action". A review
   says "No safety review owner yet" with no way to assign one. A manager is told "You can read this review" and nothing
   else.
3. **Pages offer things you can't do, and list work that isn't yours.** A team lead's Close button is live, but the server
   refuses anyone except the owner, after they've filled it in. Managers get "Accept" rows that open read-only. Some
   reviews can't close at all without "close anyway".
4. **The everyday steps are hidden.** Start, mark done and check an action live only in ⋮ and right-click menus. Eleven
   pages print "right-click a row" as instructions.
5. **Ownership is the bottleneck.** Most safety reviews and incidents say "Needs an owner". The only way to give a review
   an owner is to accept an incident handover, so chemical exposure, restraint and drill reviews can never get one, and
   no review's owner can be changed later.
6. **It talks like the system, not a person.** Disclaimers on every page, close checks such as "Linked Control Room alert
   has the same Site scope", raw names like "Fire_evacuation drill", and five different words for "waiting to be
   checked".
7. **Everything has the same weight.** Every block is the same white card with a purple top line. Twelve site cards each
   say "0 safety reports"; a whole tile says "Access restricted"; the page opens on an empty month.
8. **Hubs are directories.** "Injuries and care" opens a page of four plain cards with no numbers: an extra click that
   tells you nothing.
9. **A failed fix can close a review.** Checking an action as "it didn't work" still counts as done, so the review can close
   with no replacement action (found in audit round 2).
10. **The waiting state cries wolf.** While the Overview loads, "Needs attention could not be loaded" shows for a few
   seconds although nothing failed (being fixed separately by the H&S session), and the headline numbers arrive last
   (4–5 s for admin and manager).

**Why the workflows feel weird (root causes)**

- **R1 Built from the tables outward, not from the job inward.** Incident, safety review, investigation, action and
  WorkSafe each got their own page and lifecycle. "Deal with this fall" spans three or four pages that don't agree.
- **R2 Permissions are enforced but not designed.** Pages show the whole feature and let the server say no. They never
  say who can do a step.
- **R3 Privacy shows up as disclaimers instead of design.** "Records you can access", "Restricted", "Not available"
  appear as text on every page rather than as quiet, consistent states.
- **R4 Copy was written to be legally complete, not to guide.** It explains the system ("closing the incident and closing
  this review are separate steps") instead of telling the person what to do next.
- **R5 No hierarchy.** One card style for urgent work, zero counts and help text alike, so nothing leads.
- **R6 Approved fixes landed half-built.** Part 2 approved "one accident, one place" (D3) and "hubs as tabs" (D4). Only
  D3's privacy and tab parts and D4's rail were built; the single progress line, the "What happens next" card and
  removing the card pages were not.
- **R7 Each page works out status and titles on its own.** Nobody checks one record across every page, so they drift.

**Why Parts 1–4 missed it** (the H&S session's own view, plus mine)

- Fixes went page by page from a problem list, inside layouts inherited from Codex. Nobody redrew a whole job.
- The Part 4 "user test" was a walk with screenshots that checked "it works and it's allowed", not "what do I do next".
- Most time went on permissions, safety and speed; the UI was fitted around them, and slow pages hid what improved.
- Copy was written to be safe and complete, so it hedges.
- The big structural drawings (D3, D4) were approved but only partly built (R6).
- The demo data still reads like test data ("dfghdfgh", "Seed draft incident", "[H&S-DEMO-SEED]", a guardian as an action
  owner), so every page looks broken however good the layout is.

**How this round avoids repeating that:** start from each role's next step, not a page list; check one record across every
page it appears on; every fix has a check that proves it; eight rounds of fresh reviewers audited the plan and boards (round 8 found nothing major); the mockups are drawn
view for view as they will be built.

---

## The detail, ranked

Rank: **P1** stops the job or misleads. **P2** slows people down or confuses. **P3** polish; makes it read as generated.

### P1 — stops the job or misleads

**H1 · One accident, two records that disagree** · all roles · `a09` `a10` `a07` `m02`
- INC-2026-0062 (incident page): "Needs an owner · waiting 116 days"; progress line Reported › **Owner (current)** ›
  Manager review › **Safety review (done)** › Actions › Closed. Title "**Near miss** — Tane Wineera", "Medium **harm**".
- HS-2026-0068 (its safety review): "**Incident** — Tane Wineera", chip "Corrective action", progress Open › Investigating ›
  **Corrective action (current)** › Monitoring › Closed, "Medium **Severity**". Side card: "Status: Awaiting independent
  verification".
- Two owners (incident owner, safety review owner), two close lists ("Finish these before you close it" 2 of 4;
  "To close: 5 of 12 done"), and a third status strip "How it got here — Control room, incident and Health & safety each
  keep their own status".
- Approved in Part 2 as D3 "one accident, one place"; only the privacy and tabs parts were built.

**H2 · The next step has no button** · admin, manager, team lead · `a08` `a07` `m02`
- Action CA-2026-9004: next step "OWNER NEEDS REPLACING — Choose a new owner"; the only header button is "Start action".
- Review HS-2026-0068: next step "No safety review owner yet"; the Owner card says "No safety review owner is recorded."
  with no Assign button. There is no route to change a review owner after acceptance (backend gap).
- Manager on the same review: "Next step — You can read this review. Specialist decisions show here if you're allowed to
  make them."
- The Overview's "Where incidents are up to" (with its "Needs an owner" note) never renders; nothing sends
  `incident_stages`.

**H3 · Offered what you can't do; listed work that isn't yours** · manager, team lead · `t02` `m01` `m02`
- Team lead: "Close safety review" is live (`t02`), but the server lets only the recorded owner close unless the person
  holds `closeAny` (admin and H&S officer). The page's `can.close` ignores that rule (`HsEventController.php:1254`), so the
  error comes after submitting.
- Manager: "Needs you now" lists "Accept for Health & safety" rows; the review opens read-only (`m01`, `m02`).
- An action whose owner has to be replaced still offers "Start action" (`a08`).
- "Close anyway" can be requested by admin, H&S officers and team leads, and approved by admin and the compliance lead
  (live permissions, 31 Oct). The page doesn't say who approves it.
- Corrected after audit round 1: the code reading said admins can't close; on the .com they can (`healthSafety.events.close`
  and `closeAny`).

**H4 · Statuses contradict each other** · all roles · `a07` `m02` `a03` `a06` `a10`
- On one page: chip "Corrective action" vs side card "Awaiting independent verification"; "Handover: Not Required" vs
  close list "Accept the H&S handover before closing this event". Round 1 found this is a real dead end, not just words:
  the close check demands acceptance (`HsEventClosureService.php:34–49`) but a "not required" handover can't be accepted,
  so HS-2026-0068 can only close through "close anyway", and only once the handover is its last open item (one request
  covers one item, and closing needs every such item covered; corrected after audit round 5).
- Across pages: CA-2026-9008 is "Waiting for verification" on the Overview and in "Needs an owner" on the Actions board.
- The incident's progress line marks "Safety review" done while the review is open.

**H5 · The everyday steps are hidden** · admin, team lead · `a03` `a06`
- Start, mark done, check ("verify") and close an action are only in the row ⋮ or right-click, on the review's action
  table and on the Actions board and list. Clicking a card opens the record, where the header has one button.
- Help text tells people to right-click on 11 pages ("Past their due date · right-click a row for options", "click a
  review to open it, or use ⋮ for options").
- The Actions register has no "Add action" button; adding is only from inside a review.

**H6 · Ownership is the bottleneck** · admin, manager, team lead · `a05` `a09`
- Most rows on Safety reviews and Incidents read "Needs an owner"; the Overview's admin header shows 15 investigations
  needed and 3 handovers waiting.
- Taking one on means opening the review and finding the accept pane; the "Accept now" chip looks like a red status
  badge, not a button.
- Incident owners can be assigned only by admin and provider_manager; review owners must hold `hazards.manage`.
- A review's owner is set only by accepting an incident handover (`HsEventService.php:294`; accepting needs an incident
  source). Reviews from chemical exposures, restraints, drills and other sources (12 open on the .com) can never get an
  owner, and no owner can be changed later.

**H7 · The waiting state cries wolf** · admin, manager · `a01`
- "Needs attention" waits for two background groups (work and headline). When the faster one finishes, the panel shows
  "Needs attention could not be loaded — This does not mean there is nothing here. Try again." until the slower one
  arrives (`dashboard.tsx:300`, `deferred-panel.tsx` missing-keys rule).
- The four headline boxes show grey bars captioned "Loading" for 4–5 s and fill last, after the lists below them.
- **Fixed separately:** MASTER confirmed the false error on main on 31 Oct and passed it to the H&S session (round R3). The waiting state below is designed without it.

**H17 · Team lead decides WorkSafe without the story** · team lead · `t02` · **decision Q1 for Stephan** (moved up to P1
in audit round 1)
- "What happened" is replaced by "The originating incident is linked. Your access does not include its details." The team
  lead is still asked to record the WorkSafe decision and close the review. This follows decision 9 (25 Oct). Changing it
  is a privacy decision, not a UI fix.

**H25 · A failed fix can close a review** · admin, H&S officer, team lead · found in audit round 2
- Checking an action as "it didn't work" still stores it as verified (`HsCorrectiveActionService.php`, status set to verified
  either way). Verified actions count as resolved, so the review moves on and passes its close check with a failed fix and
  no replacement action. **Decision Q11.**

**H1 also applies beyond incidents** (audit round 2): restraints, workplace injuries, first aid, chemical exposures, drills,
hazards, inspections, safeguarding and fleet incidents each create a safety review with its own record and lifecycle.

### P2 — slows people down or confuses

**H8 · Three ways to report on one screen; reporting hidden on My Day** · all roles · `a02` `a13` `s02` `s03`
- Top bar "Report incident", Overview header "Report something", and "Raise a safeguarding concern" card, all at once.
- My Day: "Record care" (Observation · Report incident · Report something) is the last card, bottom right, below the
  fold.
- The staff-shell "Report incident" opens the incident list, not a form; from My Day the hazard choice falls back to the
  hazard list.
- The "Report something" chooser itself is good (approved D2): keep it.

**H9 · Hubs are directories** · admin, team lead · `a11`
- "Injuries and care", "Risks and prevention", "People and participation" open "Safety registers": plain cards with a
  sentence each, no counts, no urgency, two-thirds of the screen empty. The registers behind them already have the hub
  tab rail (`a12`), so there are two routes to the same place.
- Inspections belong to no hub.

**H10 · Names drift between sidebar, rail and page** · all roles · `a05` `a06`
- Sidebar "Incidents and safety reviews" → page "Safety reviews"; rail "Actions" → page "Corrective actions"; report
  "Traceability report" vs "Actions and their safety reviews"; /tasks "H&S Events".
- The team lead's rail mixes places and an action: "Safety reviews · Actions · Raise a safeguarding concern". A support
  worker's rail has "Fleet incidents".

**H11 · Lists repeat themselves and have no real titles** · admin · `a05` `a09`
- Safety reviews: nearly every row reads "Needs an owner · Open (stage 1 of 5) · WorkSafe: Decision not recorded".
- Row titles are system labels: "Chemical exposure — Substance exposure #3", "Restraint — Restraint event #4",
  "Incident — Reported by Demo Admin".
- "Site / client" column order flips between rows (site first on some, person first on others).

**H12 · The close list reads like a system audit** · admin, team lead · `a07` `a10`
- 12 items, "Problem:" / "Done:" labels, including "Linked incident and H&S record identify the same Control Room
  alert", "Linked Control Room alert has the same Site scope". Under it: "Resolving the response, closing the incident and
  closing this review are separate steps".
- A different list on the incident page. Neither says who can clear each item.

**H13 · One record, many names** · manager, team lead · `m01` `t01` `a07` `a10`
- HS-2026-0023 is "Medication incident (reopened)" for the manager and "Incident event" for the team lead.
- "Near miss — Tane Wineera" (incident) vs "Incident — Tane Wineera" (review); "Medium harm" vs "Medium Severity".

**H14 · Stage words don't match the agreed vocabulary** · all roles · `a07`
- Review stages show Investigating · Corrective action · Monitoring; `vocabulary.md` says Being investigated · Actions under
  way · Checking it worked.
- "Waiting for verification", "Awaiting verification", "Waiting to be checked", "Actions awaiting verification", "Done —
  waiting to be checked"; "Verify action" vs the agreed "Check it worked"; seven wordings of "accept".

**H15 · The Actions board is cramped and partial** · admin, team lead · `a06`
- Five narrow columns; "To do 5 … 2 more"; "Waiting to be checked 1 — Not on this page — 1 more".
- "Reviews in monitoring — With matching actions 0" header box nobody can read.

**H16 · The Overview doesn't fit the role** · manager, team lead, support worker · `m01` `t01` `s03`
- Manager (provider_manager, read-only H&S) gets the frontline "Your start" card (two report tiles), no numbers, and a
  queue of items they can't act on.
- Team lead: three of four header boxes are dashes ("Injury figures not shown for your role", "No actions due this
  period", "No training records yet").
- Support worker on Incidents: management boxes "WorkSafe to notify" and "Control room alerts", 24 rows including other
  people's drafts; nothing says "what you reported".

### P3 — polish; makes it read as generated

**H18 · Disclaimers on every page** — "These measures cover records you can access. The full picture may be incomplete."
· "it may not be everything" · "This isn't a safety rating: fewer reports don't mean a safer site." · "A due list only —
it doesn't confirm equipment is fit to use." · "Completing this action does not close its incident or safety review."

**H19 · Raw and awkward words** — "Fire_evacuation drill", "171d overdue", "1 open hazards", "PPE expiry expired",
"Expired 281 days ago" (a drill), "Traceability report", "Find a safety destination", "Private exposures", "Complete
qualified H&S sign-off against the current WorkSafe decision tree before notification or closure.", "Corrective action
arising from event HS-2026-0067."

**H20 · The record's status chip is invisible** on the header sky (`a07b`): pale purple on purple.

**H21 · Zeros and "can't see" take full space** — "Sites side by side" as 12 cards of "0 safety reports" (admin) or a
comparison of one house (manager, team lead); "Fleet incidents — Access restricted…" tile; "0 Procedures" with a red dot.

**H22 · Opens on an empty month** — default period is this month; the demo records are April–July, so headline boxes read
0 and dashes. The trend card says "4 so far in July" on 31 October. One-month charts are one bar across half a screen.

**H23 · Same weight everywhere** — every block is a white card with the purple top line; "Accept now" and "Notification
pending" look alike though one is a button; a wall of red "Expired N days ago" chips with no grouping; tab counts
("Harm & injuries 4", "Duties 6") don't say what they count.

**H24 · Demo data reads like test data** — "dfghdfgh", "xvxcvxcvxcvcxv", "Seed draft incident (editable until
submitted)", "Final-pass flag test", "[H&S-DEMO-SEED]" still on ~20 incidents, guardians and a board member as action
owners. A data tidy needs MASTER's go-ahead (data change on the .com).

---

## Workflow map — each role, each core job

Clicks count from the H&S Overview or My Day. "Page changes" counts full page moves. Target = after the build packages.

### Admin (and the health and safety officer: "Your queue")

| Job | Today | Where people get lost | Target |
|---|---|---|---|
| Take on a new handover | Overview → scroll to "Waiting for H&S to accept" → "Accept now" chip → review page → accept pane → owner (blank = me) → Accept. 3 clicks, 1 page change | Chip looks like a status; can't change the owner later | "Needs you now" row → **Take it on** → pop-up with owner = me → Take it on. 2 clicks, 0 page changes |
| Investigate | Review → Investigation tab → Start → Record findings → Submit (no confirm) → Review → Approve → outcome per recommendation | Tab hunting; stage words don't match | Case page "What happens next" names the step and its button; Submit asks to confirm |
| Get actions done and checked | Overview → overdue row "Open" → action page → one header button; start / done / check otherwise only in ⋮ / right-click | "Choose a new owner" with no button; checks hidden | Row button shows the next verb (Start, Mark done, **Check it worked**); same items in ⋯ and right-click |
| Close and decide WorkSafe | Review → Record WorkSafe decision → … → Close | 12-item system list; some reviews can't close without "close anyway" (H4) | Close disabled with the server's reason until ready; plain "Before this can close: 2 of 5 done"; both closes named |
| Approve "close anyway" (admin, compliance lead) | No queue; found only by opening the review | Nobody is told a request is waiting | Compliance lead's start leads with "Close anyway requests to decide" |
| Review a restraint (Ngā Paerewa) | Restraint register → event → review, debrief, plan check | Card page first; "right-click" help text | Register opens from the hub; review and debrief as row steps |
| Registers (risk, drills, first aid, PPE, working alone, injuries, restraints, chemicals) | Sidebar hub → "Safety registers" card page → register with rail. 2 clicks | Card page tells nothing; inspections not in a hub | Sidebar hub → first register directly, rail across; counts on tabs |
| Dashboard and Tasks | Overview loads in parts; false error; 0s for this month | Numbers last; "could not be loaded" | Queue first, numbers count in place, no false error; last 30 days with "last one on …" when empty |

### Manager (provider_manager — reads H&S, assigns incident owners)

| Job | Today | Where people get lost | Target |
|---|---|---|---|
| See what's happening at my houses | "Your start": two report tiles, no numbers, queue of items they can't act on | Looks like a support worker's page | "Your houses": 4 linked numbers, incidents waiting for an owner first |
| Get incidents owned | Incidents list → row → incident page → "Assign someone" | Overview never points to it ("Where incidents are up to" never shows) | Overview row "Assign owner" → searchable picker pop-up |
| H&S items (accept, WorkSafe, close) | Listed as "Accept" rows → read-only page "You can read this review" | Work shown as theirs that isn't | Shown in "For your information" with status and owner, no Accept button |
| Report | Report something (incident, safeguarding) | Three report buttons | One chooser |

### Team lead (hazards.manage at their house; no incident register)

| Job | Today | Where people get lost | Target |
|---|---|---|---|
| Report an incident | Top bar Report incident → report page → submit → back to My Day | Can't see it afterwards (no register) | Success pane says what happens next; "Your reports" with status (**decision Q3**) |
| Take on and work a review | "Your site" queue → Accept → review page without the story | WorkSafe decision without "what happened" (**decision Q1**) | Case page with next step and one button; the hidden part says one quiet line |
| Actions | Board → ⋮ → Start / Mark done | Hidden steps | Row verb buttons |
| Close | Close button → refused if not owner | Error after submitting | Disabled with the server's reason: "Only Jordan Peri, the owner, or the health and safety lead can close this" |
| Review a restraint | Team leads hold restraint review access (live) | Same card-page path | As for admin |

### Support worker (reports only; no H&S Overview)

| Job | Today | Where people get lost | Target |
|---|---|---|---|
| Report something (incident, injury, restraint, safeguarding) | My Day → scroll to "Record care" bottom right → Report incident or Report something; top bar also has Report incident | Reporting below the fold; two buttons | "Something happened?" strip at the top of My Day → one chooser |
| Know what happens next | Back to My Day; incident list with management numbers and 24 rows | Nothing says "what you reported" | "What you reported" card on My Day with plain status ("Health and safety are looking into it") |
| Own injury | My HR → Injuries tab (only once an injury exists) | Fine by design | Unchanged |

### Register jobs (all roles that can open them)

| Register | Today | Target |
|---|---|---|
| Risk assessments | Good page (gold standard); reached via card page | Sidebar → register directly |
| Hazards, chemicals, PPE, procedures, drills, working alone | Card page → register; drill names raw ("Fire_evacuation drill"); "Expired" for drills | Direct; labels map; "Overdue by N days" |
| Inspections | Not in any hub (`/sites/inspections`) | Tab in "Risks and prevention" |
| First aid, workplace injuries, restraints, chemical exposures | Card page → register; "right-click" help text | Direct; row verbs; no right-click text |
