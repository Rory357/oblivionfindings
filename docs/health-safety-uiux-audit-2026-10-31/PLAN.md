# Health and safety — UI/UX fix plan (v8, 31 Oct 2026, after audit rounds 1–7)

Every fix names the problems it fixes (H1–H25 in `REPORT.md`) and the check that proves it. Fixes are grouped into build
packages Stephan can approve one at a time. What changed in each version is in `AUDIT-LOG.md`. Open questions for
Stephan are numbered Q1–Q13 (Q5 was merged into Q12, so there is no Q5); D1–D4 always mean the approved Part 2 drawings.

**Rules for every package**
- **Mockup first.** A package starts only after its mockups are approved; it is then built view for view and compared side
  by side at 1440 px, and checked at 1280 px and 200 % zoom.
- **Page by page.** Shared primitives (`components/ui`, `components/page`, `components/signature`, `DeferredPanel`, the
  shells) change only where listed under "Shared and backend work" and approved by Stephan (Q9).
- **Never loosen** safety, privacy or permissions. Anything that changes who can see or do something is a decision.
- **The server decides, the page shows.** Every button, disabled state, reason and approver name comes from the server for
  this viewer and this record, never from the page guessing by role name.
- **Text from a record shows only to people who can read that record.** A safety review's title uses its source's words
  only for viewers who can read the source. Reviews from an incident show kind and person ("Near miss — Tane Wineera") to
  H&S viewers at that site who can't read the incident (Stephan's 25 Oct rule, "event yes, incident no"). Chemical
  exposures and safeguarding show kind and site only ("Chemical exposure — Kauri House"). Restraint, injury and first-aid
  reviews stay out of lists, titles and counts for anyone who can't read their source, as today
  (`HsEventAccessService.php:209–346`); a first-aid review never names the person to them (for example when they follow a direct link to it). Anything wider is a decision.
- **Buttons only where the server allows them.** A mockup or page never shows a button the server would refuse for that
  viewer. A button that waits on an open question is drawn with a dashed outline and its number (for example Q2), and is
  never built until that question is answered.
- Brand only through `--primary`; status through `<StatusBadge>`; dates through `lib/datetime.ts`; words through
  `hs-labels.ts` and `vocabulary.md`; pop-ups on the kit at 480 / 720 / 900; wizards on `WizardShell` 1100; dates and times
  on the approved calendar and clock pickers.
- Per-role tests run on a fixture copied from the **live** grants, not the seeder (they differ; see Q12).
- One gate and one merge request per package.

## The interaction rules (every H&S page)

| Rule | What it means | Check |
|---|---|---|
| IR1 Rows open | Every row and card opens its record on click. ⋯ and right-click carry the same items from one `MenuItem[]`; Shift+F10 opens it | Vitest per list: click navigates; menu items equal |
| IR2 Next step on the row | A row shows the next step as a button only when the server says this viewer can do it now. Rows never show disabled buttons | Per role, row buttons equal the server's allowed verbs |
| IR3a Blocked for now shows why | On the record and in ⋯ / right-click menus, a step blocked for now is disabled with the server's reason and who can unblock it | Each blocked state has a test with its reason |
| IR3b Never-for-you stays out | A step this viewer's access never allows is not shown; the section says once who acts on it | Read-only render per role has no disabled clutter |
| IR4 Counts click through | Every number opens its filtered list: header boxes, tallies, tab and rail counts | Type check for header boxes; href tests for tallies, tabs and rails |
| IR5 Status has a next action | Every status says what happens next and who has it. A date shows only where a real field holds one (an action's due date, a review date); none is invented, and a "not required" item shows no date | Every stage label has a "next" line; no date without a field |
| IR6 Empty states act | Every empty state says why and offers the action, or says nothing is needed (including an empty owner picker) | Vitest: no bare "No data" |
| IR7 Nothing hover-only | No action or fact only on hover; no "right-click" help text | grep for "right-click" in user-facing strings = 0 |
| IR8 One name per thing | A list has one name in rail, title, breadcrumb and /tasks; a hub one name in sidebar and breadcrumb; filters are not rail tabs; tabs mean the same on the incident and the review ("History" on both) | Labels fixture test |
| IR9 Loading is honest | Loading never reads as 0, empty or failed | Vitest per pending state |
| IR10 Telling WorkSafe comes first | Sorts first, in the block "WorkSafe: decide or tell": a decided notifiable event not yet told to WorkSafe; an acknowledgement or site-preservation step due; and a review with **no signed decision** that has the preliminary notifiable flag, severity High or Critical, or an injury rated Serious or Critical. On screen: the severity word plus a "WorkSafe: decide" or "Tell WorkSafe" chip. If it came from an incident and nobody has taken it on, its step is "Take it on" (the server refuses a decision before that handover is accepted); a review from another source can have its decision recorded straight away by anyone who manages health and safety there. Other unsigned decisions sit in the close list. No new deadline is invented. These facts come in the first response | Sort test with each case and an older overdue action; K2 counts the same cases; first-response time measured as admin and H&S officer, no slower than today (injury ratings come from the slower source reads) |

---

## Build packages (in order)

### B9 · Demo data tidy · S · needs MASTER's go-ahead (data change on the .com) · fixes H24
- Find out why the 29 Oct Part 3 clean-up missed "[H&S-DEMO-SEED]" on ~20 incidents; list and fix the rest ("dfghdfgh",
  "Seed draft incident", "Final-pass flag test", guardians and a board member as action owners), with a dump first.

### B5 · Hubs as tabs (finish approved D4) · S · no decisions from you (B5.4 needs the Sites owner's OK through MASTER) · fixes H9 H10
| # | Fix | Check |
|---|---|---|
| B5.1 | The 22 breadcrumb links to `/health-safety/registers?view=…` open the **viewer's first visible tab** in that hub (`workspace-navigation.ts:421–437`). Viewers who can't open the H&S Overview (HR with wellbeing access only) get no "Health and safety" crumb link. Crumbs read "Health and safety" | grep finds 0 breadcrumb links to `registers?view=`; HR render has no refused link |
| B5.2 | Each rail tab shows its attention count, saying what it counts ("3 to review"), from a new count (K9). Where a header box and a tab (a rail tab or a status tab under the header) would show the same number, the tab keeps it and the box goes (PAGE_HEADER_STYLE_GUIDE H8; M6, M7, M9) | href tests (IR4) |
| B5.3 | Filters leave the rails ("Investigations", "Near misses" become filters of their lists). "WorkSafe notifications" shows only with board access (`governance.view`), as today; it stays in its hub instead of jumping into Reports, and H&S officers use the Safety reviews filter "WorkSafe: decide or tell" (no permission change). "Raise a safeguarding concern" leaves the rail: it is already a choice in "Report something". Each rail shows only places the viewer can open (M6); the support worker's has no Fleet incidents | Labels fixture test per role |
| B5.4 | Inspections: a link (drawn with ↗, not a tab) from Risks and prevention to the Sites inspections page for people who can open it; it stays a Sites page (Sites owner's OK through MASTER) | Hidden without `checklists.view` |

### B1 · Tell the truth (status, next step, blocked actions, words) · M (+ backend M) · fixes H2 H3 H4 H14 H19 H20
| # | Fix | Check |
|---|---|---|
| B1.1 | One status per record on every page: Overview, board, list, record and /tasks read from `hs-labels.ts`. The labels and the one title builder (from B7) land here first | One action rendered on all five surfaces; labels equal |
| B1.2 | Stuck reviews ("handover not required" + "accept the handover"): count them, then **Q8** | Test: every open review has a way to close |
| B1.3 | The incident page shows the review's progress only to viewers who can read the review | Tests with and without review access |
| B1.4 | Every next step comes with its button for viewers the server allows; otherwise it says who does it (needs S1) | Per role |
| B1.5 | Server abilities and reasons for record pages (K1a): the team lead's Close is left out where they don't own the review, instead of live and then refused (IR3b); Start while the owner needs replacing; a no-owner review says who can close it (anyone who may close at that site); a close-anyway request says the asker can't approve it and when the approval expires | No POST from a disabled control |
| B1.6 | "Choose a new owner" next step gets its button (mockup M13); Start disabled with "Choose a new owner first"; an empty owner picker says why and who to ask | CA-2026-9004 browser check |
| B1.7 | **Fixed separately:** the false "could not be loaded" on "Needs attention" (H7) went to the H&S session on 31 Oct (MASTER confirmed it on main; round R3) | Its own test |
| B1.8 | The status chip on the header sky is readable (shared chip change S3, under Q9) | Contrast ≥ 4.5:1 at 1440 |

### B2 · The Overview fits the person (as approved in D1), and waiting is designed · L (+ backend M) · fixes H6 H7 H16 H18 H21 H22 H23
| # | Fix | Check |
|---|---|---|
| B2.1 | Starts chosen by what the person can do, with D1's names: **Your queue** (manage H&S across sites: admin, H&S officer), **Your site** (manage H&S at their sites: team lead), **Your sites, read only** (read H&S only: provider manager, maintenance coordinator), the **compliance lead's** queue led by close-anyway requests. Support workers stay on My Day | Render per role on the live-grants fixture |
| B2.2 | The start's work blocks follow D1, with the first block widened to cover telling WorkSafe: **WorkSafe: decide or tell** · **Need an owner** · **Actions to check** · **Close-anyway requests** (approvers only) · then **Other things due** (overdue actions, restraint reviews, injury follow-ups, drill findings, review flags). Rows come from the /tasks H&S providers, filtered to what this viewer can act on; things they can never act on fold under "Health and safety are handling", except owner-less reviews, which stay in "Need an owner" and say who takes them on, so the block and its count agree. "Need an owner" counts Open reviews nobody has taken on; reviews further along with no owner say "No owner" on their row (Q2) | Per role: no row offers a verb the server refuses; IR10 order |
| B2.3 | Header numbers from D1: Need an owner · WorkSafe: decide or tell · Actions overdue · Actions to check, counted by K2 with IR10's meaning. Work numbers are counts **now** and ignore the period; only the trend, harm and stage tallies follow the "Period" chip, and the captions say which. Read-only starts show numbers of things the viewer can open (provider manager: Incidents need an owner · Open incidents · Actions overdue · Open hazards; maintenance coordinator: Open hazards · Checks due · Actions overdue · Drills due). Compliance lead: Close-anyway requests · WorkSafe: decide or tell · Actions overdue · Open safety reviews. "Actions to check" counts only actions this viewer may check. "Days since a lost-time injury" sits on the queue start where the viewer may see injury figures (D1); the rates live on "Harm and injuries" | Every box links (IR4) |
| B2.4 | Period stays the approved "Last 30 days"; an empty period says when the last one was ("Last incident: 7 Jul") | Empty-period render |
| B2.5 | Waiting: page, start, the WorkSafe block and box (in the first response) and the other work blocks first; header boxes keep labels with "Counting…" bars (the `DeferredPanel` fallback); after 6 s one calm line; a real failure shows the standard panel error with "Try again". No shared change | Throttled browser check as admin and manager |
| B2.6 | One privacy line in the subline: "Counts only records you can open"; the disclaimer sentence and per-card "it may not be everything" go | grep = 0 |
| B2.7 | Zeros fold into one sentence, only for lists this viewer can read (a hidden list never reads as zero; it joins the "Hidden — you don't have access" line); "Sites side by side" becomes a compact table only with 2+ sites (its caveat kept, short); "Access restricted" tiles become one muted line: "Hidden — you don't have access: Fleet incidents" | Single-site render |
| B2.8 | "Checks due" grouped by kind, most overdue first; drills "Overdue", PPE keeps "Expired"; the "doesn't confirm gear is fit to use" line stays, shorter | Labels map covers every kind |
| B2.9 | Trend only with 3+ months of data; current month in the sentence | Test with 1 and 4 months |
| B2.10 | The Overview's tabs follow the same rules; "Duties" becomes "Legal duties"; every start has "All tasks" | Tab render per role |
| B2.11 | Board reports and "Actions and their safety reviews" need board access (`governance.view`): they stay hidden for H&S officers and the compliance lead (granting it would be a decision) | Render per role |

### B3 · One accident, told the same way (finish approved D3) · L (+ backend M) · fixes H1 H2 H12 H13 H17 (display)
D3 was approved as "one place … closed once". The incident and its safety review keep their own owners, access and
closes underneath, so B3 departs from "closed once" and "one line": see **Q10**.
| # | Fix | Check |
|---|---|---|
| B3.1 | Both pages share one header (mockups M5 and M14): the same title (source words only for viewers who can read the source), the same severity word, both references small | Same title across pages, lists and /tasks; restricted-viewer test shows kind + person |
| B3.2 | **One strip per header (DESIGN.md):** each page's header shows its own record's track (review: Passed to Health and safety › Investigated › Actions done › Checked it worked › Closed; incident: Reported › Owner › Manager review › Closed). The other record shows in the body as a compact "The incident" or "The safety review" card with its owner and step, only to viewers who can read it. Reviews from other sources show their source in that card. WorkSafe is a chip, never a step | Tests: incident in manager review while the review is investigated; a review with no incident |
| B3.3 | "What happens next": one sentence, who, a date only from a real field, and one main button for this viewer, or who does it | Per role |
| B3.4 | "Before this can close" in two groups: **Can't be skipped** (WorkSafe decision, notification and acknowledgement, site preservation, protective work, record checks — a failing record check says "Needs fixing — ask an administrator"; the live Control room alert check names the Control room, which clears it) and **"Close anyway" can cover these** (handover, investigation, recommendations, actions). Each item says who clears it; both closes are named, with names from the server (the owner, plus anyone else who may close it there: close-any holders for the review, incident approvers at the site for the incident); a no-owner review says who can close it. **Close anyway works only when exactly one of these is still open:** one request covers one item, and closing needs every such item covered (`HsClosureReadiness.php:83–104`). With two or more open, the list names what to finish first and offers no "Ask to close anyway" (a page choice: it narrows what the server accepts). Asking stays offered while can't-be-skipped items are open, with the line "You can close only after these are done" | A failing record check shows unfolded; with two items open no request is offered |
| B3.5 | Both owners show with who assigns each; setting or changing the review owner needs **Q2** and K3 | Per role |
| B3.6 | Viewers without incident access see "Hidden — you don't have access. Managers at this site can read the incident." (**Q1**), and their Handover tab stays visible | Team lead render |
| B3.7 | Tabs mean the same on both pages: incident keeps its built set; the review has Overview · Investigation · Actions · Files · History · More (Risk, Linked records, Handover — Handover moves out of More for viewers without incident access) | Tab test |
| B3.8 | The close pop-up uses the agreed words, keeps the two groups, says what closing does (for example "This closes the safety review; the incident stays open until its owner closes it") and, from the server, whether it can be undone, and names the approvers from the server | String test |
| B3.9 | **Close anyway, both sides, on the server's rules.** It is offered only when exactly one item it can cover is still open (B3.4), so the pop-up names that item instead of asking which. Who may ask: the owner, or someone who can close any review; with no owner, anyone at the site who may ask. It asks for a reason (at least 20 characters), an evidence reference (at least 5), the date the request lapses and a review date on or before it. Both dates mean the end of that day, NZ time; the server refuses a lapse later than 30 days from the moment of asking, so the picker offers up to 29 days ahead. Deciding: "Approve" or "Decline — keep it open", with a reason of at least 10 characters. An approver can withdraw an approval later ("Withdraw approval", the existing revoke route, with a reason). Approving records a decision and closes nothing; the owner (with no owner, anyone who may close reviews at that site) or someone who can close any review can then close it while both dates are ahead, so until the review date. Nobody decides their own request; approvers' names come from the server | Request, decision and withdraw tests on the existing routes, including a review date after the lapse date and a lapse date 30 days out both being refused, and an approval still working at 11 pm on the review date |

### B4 · Lists and actions · L (+ shared S, backend M) · fixes H5 H10 H11 H15 H25 (with Q11)
| # | Fix | Check |
|---|---|---|
| B4.1 | Safety reviews, Incidents, Actions and every register: IR1, IR2, IR3, IR7, with row abilities from K1b and a query-count test per list (the speed work must hold); shared `RowList` row button (S2) | Per list Vitest + query count |
| B4.2 | Row title as B3.1; reference small and last; WorkSafe chip only for IR10 cases | 25 rows have no sentence repeated on every row |
| B4.3 | Actions board: To do · In progress · Done — waiting to be checked · Checked. Checked cards carry the vocabulary's words, "Checked — it worked" (next step: Close action) or "Checked — it didn't work" (next step: add a new action; whether that blocks closing the review is Q11). Real counts already exist; cards show the first N per column with "Show all" (K4). "Owner needed" is a chip and a filter; "Add action" picks the review first (K5). Start and Mark done show wherever the server allows them today (any H&S manager; Q13) | Column counts equal list totals |
| B4.4 | "Check it worked" (480) on the existing routes: "Checked — it worked" (then "Close action" is its next step), "Checked — it didn't work" (kept on record; **Q11** decides whether it blocks closing until a new action exists), "Not finished — send it back"; the evidence tick stays; the main button is named for the chosen outcome. The four people who can never check an action (who owned, did or added it, or opened the review) get no button; the record says once "Someone who didn't add, own or do it checks it" | Each outcome hits its route |
| B4.5 | One name per thing (IR8): "Safety reviews", "Actions", "Incidents"; "Traceability report" → "Actions and their safety reviews"; /tasks "H&S Events" → "Safety reviews" | Labels fixture test |

### B6 · Reporting and the frontline · M · fixes H8 H16
| # | Fix | Check |
|---|---|---|
| B6.1 | My Day: "Something happened?" strip at the top with "Report something" and two shortcuts; the duplicate "Report incident" leaves Record care | Render vs mockup M10 |
| B6.2 | After a report, the success pane says plainly where it went (names a person only when the routing knows one) and returns to where you started | Each report page returns to its `return_to` |
| B6.3 | "What you reported" (drawn in approved D1): own reports, never safeguarding, restricted, HR-confidential or independently handled ones. Sources: incidents, workplace injuries and hazards the person may report (restraint reports are restricted, so they stay out). One status set for all: Draft · Sent · Being followed up · Closed. Needs **Q3** and read paths K7 | Support worker sees only their own, in those four words |
| B6.4 | Support worker's Incidents list: no management boxes they can't act on | Render |
| B6.5 | Dead ends: hazard choice opens the hazard form; working alone returns to where you started; the staff shell's "Report incident" opens the report form, not the list (bug fix, shared change S4) | Click-through per entry |

### B10 · Restraints and injuries told the same way · L · after B3 · fixes H1 for those records
- The restraint record (its own review owner, monitoring, debrief, review) and its safety review; the workplace injury and
  return-to-work record and its safety review. Same header and tracks as B3, walked as team lead, H&S officer and HR
  (`hr.wellbeing.view`). Mockups are drawn and approved before it starts.

### B7 · One vocabulary · M · runs inside B1–B6 · fixes H10 H13 H14 H18 H19
- The labels file and title builder go first, in B1. Then each package's pages get the vocabulary sweep. Page list:
  Overview and its tabs, Safety reviews, Incidents, Actions, the review and incident records, every register, My Day's
  H&S parts. Safety and legal wording is made plainer, never removed.
- The sidebar group keeps "Health & Safety", like its siblings ("Sites & Locations", "Fleet & Assets"); breadcrumbs and
  titles say "Health and safety". IR8 treats the two as one name.
- Check: labels fixture test on both sides; per page, a string audit finds none of the removed strings.

### B8 · Hierarchy and pickers, remaining pages · M · fixes H20 H21 H23
- Page list: every register, worker participation, Reports. One lead block per page; quiet sections as rows; "Accept now"
  becomes "Take it on"; red chips grouped; tab counts labelled.
- The 33 native date and time boxes in the H&S and incident pages move to the approved pickers.
- Check: per page, side by side with an approved mockup or the gold-standard Risk assessments page at 1440 px.

## Shared and backend work

| # | What | Package | Decided by |
|---|---|---|---|
| S1 | `PageHeaderNextStep` optional action slot | B1, B3 | Q9 |
| S2 | `RowList` optional row button; `Log` row click | B2, B4 | Q9 |
| S3 | `PageHeaderStatusChip` readable on the brand sky (used in 239 files) | B1 | Q9 |
| S4 | Staff shell "Report incident" link points at the form | B6 | Q9 |
| K1a | Server abilities and reasons per record page | B1 | in package |
| K1b | Server abilities per row, batched, with a query-count test | B4 | in package |
| K2 | Header counts with IR10's WorkSafe meaning; "Where safety reviews are up to" counts | B2 | in package |
| K3 | Set or change a review owner, any source | B3 | Q2 first |
| K4 | First N cards per board column (counts already exist) | B4 | in package |
| K5 | Review picker and owner candidates for "Add action" | B4 | in package |
| K6 | Close-anyway requests: a worklist, a /tasks provider, and a notice to approvers and the asker | B2, B3 | in package |
| K7 | Read paths for "What you reported" | B6 | Q3 first |
| K8 | Guard against new stuck reviews (the "not required" default and the reconciler re-pointing) | B1 | Q8 |
| K9 | Rail tab attention counts (B5.2), from the counts K2 already reads | B5 | in package |

## Decisions for Stephan (first option = recommended)

**Needed before B1–B3**
| # | Question | Options |
|---|---|---|
| Q2 | Who can set or change a safety review's owner? Today only accepting an incident handover sets one, so 12 open reviews from other sources can never get an owner. Under the first option a chemical exposure review can be owned only by someone with wellbeing access (an admin today); for everyone else its row names who takes it on | People who manage health and safety at that site **and can read the review's source** (for example wellbeing access for a chemical exposure), any review, any time · The same, without the source rule · Keep today's rule |
| Q8 | Reviews stuck by "handover not required" + "accept the handover". "Close anyway" can clear one only once the handover is its last open item | Repair the data (MASTER) and add a guard so no new ones appear · Let the close check honour "not required" |
| Q9 | Four small shared changes (S1–S4), invisible to other modules except S3's readability fix | Allow · Wait |
| Q10 | Departures from the approved Part 2 drawings: D3 said "closed once" and one progress line; D1 drew a support worker start on the Overview, which support workers can't open; the header chip says the severity word ("Medium severity") where D3 and today's incident page say "harm" | Each page shows its own record's track, the other record in a card, both closes named; the support worker start lives on My Day · Build "closed once" as one Close that closes both when the same person may close both |
| Q11 | "Checked — it didn't work" counts today as a finished fix, so the review can close with a failed fix | Block closing until a new action is added · Keep today's rule |
| Q12 | Live grants differ from the seeder's policy: live admin can close, close any, ask for and approve "close anyway"; the seeder says admin never should. A reseed would also give provider managers and maintenance coordinators restraint read access, which live doesn't | Make the seeder match live for restraints, and ask separately about admin · Make live match the seeder |

**Needed before B2 and B6**
| # | Question | Options |
|---|---|---|
| Q1 | Should a team lead who decides WorkSafe see what happened? (Team leads have read clients at their sites since 30 Oct) | Show the incident's "what happened" and "what we did" text, except HR-confidential and independently handled incidents · Keep today's rule |
| Q3 | Should people see the status of what they reported themselves (approved D1 draws it)? | Status only (Draft · Sent · Being followed up · Closed), their own reports, never safeguarding, restricted, HR-confidential or independently handled ones · No |
| Q4 | Provider managers and H&S items | Read only, "Health and safety are handling" (today) · Let them take on H&S items at their sites (a reseed would also give restraint review access) |
| Q6 | The top bar's "Report incident" (app-wide) | Open the "Report something" chooser · Keep |
| Q7 | Support workers see other people's draft incidents for their clients | Only their own drafts · Keep |
| Q13 | Any H&S manager can start or finish anyone's action today | Only the owner (or a lead acting for them) · Keep |

## Build order
B9 → B5 → B1 (with B7's labels) → B2 → B3 → B4 → B6 → B10 → B8. Each starts with its mockups approved. Decisions Q2, Q8,
Q9, Q10, Q11 and Q12 come before B1; Q1, Q3, Q4, Q6, Q7 and Q13 before B2 and B6.
