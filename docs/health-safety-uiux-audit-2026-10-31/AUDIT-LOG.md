# Audit log — the fix plan, audited by fresh reviewers

Each round used a new helper with no earlier context. It read the report, the plan, the code facts, the 22 screenshots,
DESIGN.md, the H&S vocabulary and the earlier Part 2 decisions, then re-walked every role and job against the plan in the
code. After each round I checked the strongest claims myself (code or live data) before changing anything.

## Round 1 — plan v1 → v2 · 12 major, 8 minor findings

**What the reviewer found (major)**
1. Safety reviews that didn't come from an incident can never get an owner: the only way to set one is accepting an
   incident handover. "Take it on" and the "Need an owner" count can't work for them.
2. "Handover not required" + "accept the handover before closing" is a real dead end, not a wording mismatch.
   HS-2026-0068 can only close through "close anyway".
3. The blocked-action reasons were guessed from role names, and the live admin contradicts them: admin can close.
4. "Never hidden" (IR3) clashed with folding read-only items away.
5. Starts are chosen by permission, not role; the compliance lead (the person who approves "close anyway") had no queue.
6. Team leads and H&S officers do have restraint access; the restraint review job wasn't walked.
7. The plan invented stage words ("Owner", "Looked into") instead of the approved D3 steps, and renamed built tabs.
8. Two owners and two closes stay in the backend; the plan hid that instead of saying it plainly.
9. Showing the review's stage on the incident page, and "What you reported", would show people things they can't read
   today. Support workers already see other people's draft incidents.
10. "Check it worked" didn't match the real verify route (evidence tick, yes/no, no file; "send back" is a different route
    that loses the "didn't work" record), and missed two of the four people barred from checking.
11. Several fixes secretly needed shared-block or backend changes (next-step button slot, row buttons, per-box loading,
    "Add action" endpoints, real board counts, new header counts).
12. Sorting "overdue first" would push WorkSafe notifications down; the law says notify straight away.

**Minor:** "Last 3 months" departs from the approved D1 "Last 30 days"; the card page is reached through 23 breadcrumb
trails, not the sidebar; PPE "Expired" and "doesn't confirm equipment is fit to use" are safety wording; the staff shell is
a shell; "Hidden — you don't have access" is the approved wording; 33 native date/time boxes remain; the WorkSafe chip would
still repeat on every row; H17 should be P1; the demo data clean-up should go first.

**What I checked myself**
- Live permissions on the .com (read-only query, 31 Oct): admin holds `events.close`, `closeAny`, closure-exception approve
  and all restraint keys; team leads and H&S officers hold restraint view, review and manage; the provider manager holds
  `hazards.view` only; admin and the compliance lead approve "close anyway". The first code reading was wrong on admin and
  restraints. 12 open reviews on the .com have no incident source.
- The approved D1 drawing: numbers "Need an owner · WorkSafe decisions · Actions overdue · Actions to check", period "Last
  30 days".

**What changed in v2**
- New rule "the server decides, the page shows": every button, disabled state and reason comes from the server (K1).
- IR3 split into IR3a (blocked for now: disabled with reason) and IR3b (never for you: left out, one line says who acts).
- New IR10: WorkSafe first, everywhere.
- B9 demo data moved first. B1.2 now names the close dead end and makes it decision Q8. B1.5–B1.6 fixed (team lead close,
  Start while owner needs replacing).
- B2 starts defined by what the person can do; compliance lead's queue added; D1 numbers and "Last 30 days" adopted;
  waiting state uses the standard panel error (no shared change); "Checks due" keeps PPE "Expired" and a shorter safety line.
- B3 renamed "told the same way on both pages"; approved D3 steps; built incident tabs kept; both owners and both closes
  shown plainly; approved "Hidden — you don't have access" wording; close pop-up words follow the vocabulary.
- B4.4 "Check it worked" rebuilt on the real routes with three outcomes and the evidence tick; all four barred people named.
- B5.1 repoints 23 breadcrumb trails; inspections become a link that stays a Sites page.
- New section "Shared and backend work" (S1–S2, K1–K5). New decisions Q2 (owners for every review), Q7 (others' drafts),
  Q8 (stuck reviews), Q9 (shared blocks).
- Report corrected: H3 no longer claims admins can't close; H6 adds the owner gap; H4 adds the dead end; H17 moved to P1;
  restraint review added to the workflow map; live permission corrections added to the code facts.

## Round 2 — plan v2 → v3 · 11 major, 10 minor findings

**What the reviewer found (major)**
1. B3 and B2 departed from the approved D3 ("one place … closed once") and D1 (separate work blocks) without saying so.
2. One merged progress line can't show the real lifecycles: the incident has its own Owner and Manager review steps and
   runs alongside the review; non-incident reviews have no "passed to Health and safety".
3. The two-records problem also applies to about ten other sources (restraints, injuries, first aid, exposures, drills,
   hazards, inspections, safeguarding, fleet); restraint review/debrief and return to work had no designed path.
4. New record titles like "Fall in the lounge" come from protected incident text: showing them would leak it to team leads
   and others without source access.
5. "Checked — it didn't work" is stored as verified and counts as resolved, so a review can close with a failed fix. The
   action's own Close step had no design. Confirmed in `HsCorrectiveActionService.php` (status set to verified either way).
6. Dropping "Hard safety blockers" hid which close items "close anyway" can never skip (WorkSafe, site preservation,
   record checks).
7. Seeder and live grants differ (admin close and close-anyway rights; restraint read on reseed), so tests and the
   "today" option of the close-anyway decision were wrong.
8. "Needs you now" had no defined sources; the compliance lead's queue had no backend (no worklist, provider or notice).
   Confirmed live: compliance lead holds `hazards.view`, `viewAllSites` and close-anyway approval.
9. "WorkSafe first" was too broad (almost every open review lacks a signed decision) and assumed a deadline that doesn't
   exist.
10. Server abilities per row are heavy (separation-of-duties checks across 25 rows) and sizes were optimistic.
11. The recommended option for the team lead's story didn't exclude HR-confidential or independently handled incidents.

**Minor:** the data repair for stuck reviews won't stop new ones; the maintenance coordinator's numbers pointed at lists
they can't open; board counts already exist (only cards are limited); the header chip fix is a shared change; breadcrumb
targets must follow the viewer's first visible tab (22 links; HR can't open the Overview); filters sit in rails; rows
should show allowed verbs only; edge states (no owner, expiring approvals, self-approval, empty owner picker) undesigned;
decision wording; build order and page lists.

**What changed in v3**
- "Mockup first" made a rule; a new rule keeps record text away from people who can't read the record.
- IR2 rows show allowed verbs only; IR10 narrowed to "notifiable and not yet told" and "preliminary trigger or serious
  harm without a signed decision"; IR4 covers tallies, tabs and rails; IR8 adds filters and tab meaning.
- B2 follows D1's blocks and names (Your queue · Your site · Your sites, read only) and builds rows from the /tasks
  providers; compliance lead queue with K6; maintenance coordinator numbers fixed.
- B3 now draws two tracks, splits the close list into "Can't be skipped" and "Close anyway can cover these", keeps
  Handover visible for restricted viewers and names the departure from D3 as decision Q10.
- B4 board keeps a "Checked" column with "worked / didn't work" chips and a Close step; "didn't work" closing is Q11.
- New B10 (restraints and injuries told the same way) after B3, with its own mockups.
- K1 split into record (K1a) and row (K1b) work with query-count tests; K4 rescoped; K6–K8 added; sizes raised.
- Decisions: Q5 merged into Q12 (live vs seeder grants); Q10, Q11, Q13 added; Q1 wording narrowed; decisions split into
  "before B1–B3" and "before B2 and B6".
- Build order: B9 → B5 → B1 → B2 → B3 → B4 → B6 → B10 → B8; B7's labels land inside B1.

## Round 3 — plan v3 → v4 and mockups · 8 major (plan) + 11 major (mockups)

This round audited the plan and the 12 rendered boards together.

**Plan (major)**
1. The most urgent legal case — decided notifiable, WorkSafe not told yet — had no block, box or row.
2. The server refuses a WorkSafe decision before the handover is accepted, so "decide" can't be the first step on an
   untaken review.
3. "Kind and person" titles still leak for private sources (chemical exposures need wellbeing access; safeguarding).
4. B4.4 showed a disabled check button to the four people who can never check, against IR2 and IR3b.
5. Two tracks in one header breaks DESIGN.md's one-strip rule and would need a shared header change.
6. The "close anyway" request side wasn't designed, and approval was wrongly described as closing.
7. The plan didn't say which numbers follow "Last 30 days"; backlog counts were captioned as new.
8. Q3's recommended option lacked the restricted, HR-confidential and independent-handling exclusions and a fixed
   status set.

**Mockups (major):** the repo copies of the boards were older than the renders; buttons the server would refuse (decide
before take-on, board reports for people without board access, owner changes waiting on Q2); a private name shown to the
H&S officer; the close-anyway pop-up misstated approval; nine records disagreed between boards; example people broke the
permission rules (a support worker owning actions, a team lead owning an incident); a disabled check button on a row;
three report buttons back on M3 and M4; inconsistent words (waiting to be checked, Open, Expired, house/site); M7's
pop-up claimed checking closes the action; M8 invented a field and weakened the site-preservation wording.

**What changed (plan v4 and the boards)**
- IR10 and B2.2/B2.3: the block and box are now "WorkSafe: decide or tell", covering telling, acknowledging and site
  preservation; an untaken review shows "Take it on" first; WorkSafe facts come in the first response.
- New rule: private sources show kind and site only; buttons appear only where the server allows them, with decision
  dependencies in the board notes, not on screen.
- B3.2 keeps one strip per header: each page shows its own record's track; the other record is a card in the body.
- B3.9 designs both sides of "close anyway" with the server's fields; approval records a decision and closes nothing.
- B4.4: no button for people who can never check; the main button follows the chosen outcome; "Close action" follows a
  successful check.
- B2.3: work numbers are counts now; only tallies and the trend follow the period. B2.11 hides board reports for roles
  without board access.
- Q3 adds the exclusions and one status set (Draft · Sent · Being followed up · Closed). Q10 now lists every departure from
  the approved drawings, including the support worker start moving to My Day.
- Boards: a canonical example-data sheet (`mockups/EXAMPLE-DATA.md`); every board rewritten or corrected to it; M8's
  WorkSafe pop-up is now "Record that WorkSafe was told" with the route's own fields; new M12 for asking to close anyway
  and recording the decision; the build now copies board sources into the repo so they can't go stale again.

## Round 4 — plan v4 → v5 and mockups · 3 major (plan) + 8 major (mockups) · "not ready yet"

**Plan (major):** "serious harm" isn't a term in the code (the WorkSafe trigger had to be defined in the code's own
severity scales); the "close anyway" rules were wrong (review date on or before the lapse date, approval valid only while
both dates are ahead, one request covers exactly one item, only the owner or someone who can close any review may ask);
the open decisions shared names with the approved drawings (D1–D4).

**Mockups (major):** counts still disagreed between boards and the data sheet (owners needed, WorkSafe items, per-site
counts); HS-0095 was left out of the WorkSafe count; "212 days since a lost-time injury" contradicted Sam Wong's injury;
the close-anyway dates would be refused by the server; "Was the site left as it was?" weakened the s.55 duty; the example
WorkSafe reason taught the wrong s.23 test; buttons waiting on Q2 weren't marked on the boards; M5 invented a due date.

**Minor:** board viewers vs private titles, Start buttons hidden by an unstated Q13 assumption, block counts while numbers
were still loading, close reasons naming the wrong people, "hand-rail" vs "grab rail", severity words from two scales,
M11 overflowing, M12 asking two questions for one choice, missing boards for the action record and the incident page,
compliance lead numbers, "Tallies" as a label, and more (listed in the round 4 notes).

**What I checked myself:** the s.23 notifiable list (a fracture alone isn't on it; hospital admission for immediate
treatment is) and the WorkSafe notification route's fields (`notified_at`, `method`, `reference`, `site_preserved`).

**What changed (plan v5 and the boards)**
- Open decisions renamed Q1–Q13 everywhere; D1–D4 now only mean the approved drawings.
- IR10 defines the WorkSafe trigger in code terms (no signed decision + preliminary flag, severity High/Critical, or injury
  Serious/Critical); boards show the severity word plus a "WorkSafe: decide" or "Tell WorkSafe" chip.
- B3.9 rewritten on the server's close-anyway rules; M11 and M12 redrawn to match (review on Fri 20 Nov, lapses Fri 27 Nov,
  one item, approvers named from the server).
- Buttons waiting on a question are drawn with a dashed outline and its number (Q2, Q13), and never built before it's
  answered. IR5 and B3.3: dates only from real fields.
- The data sheet is now the single source for every count; all boards were checked against it (Need an owner 4, WorkSafe
  3, Kauri House 8 open reviews and 3 overdue actions, 17 days since a lost-time injury). The near-miss example became
  HS-2026-0099 so it isn't confused with the real stuck review HS-2026-0068.
- M8's site question now keeps the s.55 duty ("Is the site being kept as it was until WorkSafe releases it?"); M12's
  reason uses the Act's test (admitted to hospital for immediate treatment).
- M1, M2 and M6 are drawn as an H&S officer; new M13 (action record, H2's example) and M14 (incident page side of "one
  accident"). Q2 gains a source-access option; B2.3 adds the compliance lead's numbers; "Period" replaces "Tallies".

## Round 5 — plan v5 → v6 and mockups · 2 major (plan) + 6 major (mockups) · "not ready yet"

**Plan (major):** the private-title rule would have shown restraint, injury and first-aid reviews (kind and site) to
people who can't see them at all today, which loosens privacy; "close anyway" only works when exactly one item it can
cover is still open (one request covers one item, and closing needs every such item covered), which the plan didn't say.

**Mockups (major):** M11's WorkSafe number (2, not 3) and its request item (an action, not the investigation) disagreed
with the data sheet; M11 showed the top bar's "Report incident" to a compliance lead, who can't report incidents; M6's
rail showed three tabs Dana can't open (WorkSafe notifications, Safeguarding concerns, Fleet incidents); M1 left out
Dana's own overdue action; HS-0083 and HS-0067 had owners the code can't give them (only accepting an incident handover
sets one); M3 counted a restraint review the provider manager can't see.

**Minor:** the WorkSafe notifications tab is board-only; "Raise a safeguarding concern" still in a rail; B1.5 vs IR3b on
the team lead's Close; who may ask when there's no owner; what time the lapse and review dates mean; first-response speed
for IR10; the missing Q5; B5's "no decisions" vs the Sites owner's OK; tab counts needing backend work; the sidebar's "&";
no maintenance coordinator on the data sheet; tab counts vs cards (M1, M4); numbers repeated on header and tab (M1, M7,
M9); M8's old site wording, no undo line, calendar covering fields and a half-done rename; M10's order, shortcut and
missing Q3 mark; M3's "most recent" rows and drill count; M4's count including Q2 rows; M2's title; M14's "harm" chip.

**What I checked myself:** `HsEventAccessService.php:209–346` (restraint, injury and first-aid reviews are filtered out
for viewers who can't read the source; incident-sourced reviews stay visible without incident read, per Stephan's 25 Oct
rule — the reviewer's wider claim was trimmed to this); `HsClosureReadiness.php:83–104` with the request rule
`scope max:1` (`HsEventController.php:456`); `workspace-navigation.ts` tab rules; there is no reopen route for a closed
safety review.

**What changed (plan v6 and the boards)**
- The private-title rule now matches the code: incident reviews show kind and person, exposures and safeguarding kind and
  site, restraint, injury and first-aid reviews stay out of lists and counts for people who can't read them.
- B3.4 and B3.9: "close anyway" is offered only when exactly one item it can cover is left; the pop-up names it (M12
  redrawn); dates mean the end of that day, NZ time; with no owner, anyone at the site who may ask or close can.
- HS-0083 and HS-0067 have no owner (not from an incident; Q2). "Need an owner" counts Open reviews nobody has taken on.
- M6's rail shows only Dana's tabs; B5.3 keeps WorkSafe notifications board-only and moves "Raise a safeguarding concern"
  into "Report something". M11 has the right numbers, item and top bar. M1 adds CA-2026-9001 (11 things). M3 counts 12,
  lists the newest three, adds the drill and names Ana Folau, maintenance coordinator. M5b drops the Close it could never
  use. M4, M7, M9 and M10 counts, order and Q3 marks fixed. M8 laid out without overlaps, says closing can't be undone, and
  adds a new action instead of an existing one.
- New K9 (tab counts); Q8 and Q10 widened; the report's HS-2026-0068 note corrected.

## Round 6 — plan v6 → v7 and mockups · 1 major (plan) + 4 major (mockups) · "not ready yet"

**Round 5's fixes held:** the reviewer confirmed in the code that restraint, injury and first-aid reviews drop out for
viewers who can't read their source; that "close anyway" needs every coverable item covered by a one-item request; that
an owner-less review can be acted on by anyone with the permission at that site; and that M6 and M7 show each viewer's
real tabs.

**Plan (major):** "end of that day" for the lapse date breaks the server's 30-day check (it compares to the moment of
asking), so a request lapsing on day 30 is always refused.

**Mockups (major):** the dashed Q2 buttons on the chemical exposure (M1, M4, M6) only work under Q2's second option,
because Dana and Hemi can't read wellbeing records; M11 and M12's example request (lapses 27 Nov, asked 28 Oct, 4:12 pm)
would be refused; M4 showed a Kauri House drill the data sheet doesn't have; M10's "What you reported" showed more than
Q3's "status only" (who is following it up, and an outcome).

**Minor:** M1 and M4 counted "things you can move on" differently; M2's tab counts; M5's close list (record-check count,
two closers worded two ways, no "Ask to close anyway"); M6's menu covering rows and HS-0099's "With Hemi Walker" when Dana
can record its decision; M8's clock covering the s.55 answer and the close pop-up merging the two groups; M4's duplicate
title; "All tasks" missing on M3 and M11; incident numbers out of date order; the take-on time used as the "when"; M7's To
do order; "Potential: Medium" colour; "deliveries"; the plan's reading of PAGE_HEADER_STYLE_GUIDE H8 (backwards);
"Withdraw approval" and the 10-character decision reason; IR10's "Take it on" applying only to incident handovers; the
Control room check's owner; restraint reports under Q3.

**What I checked myself:** `HsEventClosureService.php:284–287` (the 30-day check against now),
`ChemicalExposureAccessService.php` (`hazards.view` + `hr.wellbeing.view`), `HsEventService.php:350` and `:785–789` (any
`hazards.manage` holder may record a WorkSafe decision; "not required" handovers allow it), the revoke route and the
10-character reason, and H8's wording in PAGE_HEADER_STYLE_GUIDE.

**What changed (plan v7 and the boards)**
- B3.9: the lapse picker offers up to 29 days ahead; withdraw approval and the reason lengths added. M11 and M12 now lapse
  on Thu 26 Nov.
- Chemical exposure rows show no button for Dana or Hemi; a dashed line says an admin takes it on (Q2). Q2 says so.
- M4's checks are two protective gear items at Kauri House; the data sheet says so. M10's reports show date, kind and
  status only.
- IR10, B3.4, B5.2 (H8 the right way round: M7 and M9 move the count to the tab; the Overview keeps D1's boxes, listed in
  Q10), B6.3 (restraint reports stay out) updated.
- M1 says "9 things you can move on now"; M5's close list agrees with itself and points to "Ask to close anyway" in More;
  M6's menu opens under the last row and HS-0099 offers "Record WorkSafe decision"; M8's clock is drawn beside the pop-up
  and the close pop-up keeps both groups; M3 and M11 gain "All tasks"; incident numbers follow their dates.

## Round 7 — plan v7 → v8 and mockups · 0 major (plan) + 2 major (mockups) · "ready once two board lines are fixed"

**Round 6's fixes held:** the 29-day lapse date fits the server's check; chemical exposure rows give Dana and Hemi no
button; IR10's "Take it on" now applies only to incident handovers (other sources start "not required"); M10 shows status
only. The reviewer also confirmed every other drawn button against the server for its viewer, and found no privacy leaks.

**Mockups (major):** M1 said "nothing waiting" in chemical exposure follow-ups, a list Dana can't see (hidden must never
read as zero); M5b told the team lead to record the WorkSafe decision but gave him no button or "What happens next".

**Minor:** the H8 clause in Q10 wasn't a real departure; B5.2 should cover status tabs too; B2.7's zeros only for
readable lists; B2.2's fold rule vs owner-less rows in "Need an owner"; closers named from the server (incident approvers,
close-any holders); IR5 and "not required" dates; B4 and H25; M7's column counts; M4's report card; M6 and M9 numbers
repeated in status tabs; M5b's close list missing "Passed to Health and safety"; M3's procedures, double-counted drill,
missing tab count and start name; "Assign someone" (IR8); M10's hazard promise and safeguarding label; M13's steps vs the
action vocabulary; "not a serious injury" (the old test); M11's item marker; evidence text differing between M11 and M12.

**What I checked myself:** `ChemicalExposureFollowupProvider` and `ChemicalExposureDeliveryProvider` (both need
`hr.wellbeing.view`); `ClientIncidentPolicy` approve and close (`incidents.approve`, no owner check).

**What changed (plan v8 and the boards)**
- M1: chemical exposure follow-ups move to a "Hidden — you don't have access" line; the exposure row says nobody can take
  it on today (the dashed Q2 line says who could).
- M5b: "What happens next" with "Record WorkSafe decision" for Hemi; the band and close list say who else can; "Passed to
  Health and safety" added (3 of 5 done).
- Plan: B2.2 (owner-less rows stay in "Need an owner"), B2.3 ("Actions to check" means ones you may check), B2.7 (hidden
  lists never read as zero), B3.4 (closers from the server), B5.2 (H8 covers status tabs), IR5, B4 and H25, Q10 trimmed.
- Boards: M6 and M9 drop boxes their tabs repeat; M7 To do 7 and "Due in the next 7 days 1"; M4 report card shows kind
  only with an incident row; M3 "Your sites, read only", "Prevention 3 due", "Assign someone", checks and drills counted
  once; M10 and M13 use the agreed words; M5, M8 and M14 name the other people who can close; M11 and M12 agree.

## Round 8 — confirmation · 0 major · "ready to show Stephan: yes"

A fresh reviewer re-checked the round-7 fixes and scanned the plan and all 15 boards for anything major. All round-7
fixes were in place. They checked in the code: the close-anyway rules (one item per request, 20 / 5 / 10-character
minimums, the 30-day check, the review date on or before the lapse), M14's incident close list against
`IncidentJourneyService::buildCloseGate`, the WorkSafe notification fields, that there is no reopen route, the compliance
lead's grants, and every drawn button against its viewer. They found no refused buttons, no leaks, and counts and dates
that agree with the data sheet.

**Minor (all fixed):** M12's take-on time clashed with M1, M2 and M6 (now "today"); M14's "Start manager review" became "Do
the manager review"; M8's owner field shows its search; M5's header button says which action it checks first; the
first-aid name rule says where it applies; the data sheet names the open hazards and who does HS-0087's restraint review;
README and REPORT updated. M2's skeleton timing was left as drawn (the note explains the order).
