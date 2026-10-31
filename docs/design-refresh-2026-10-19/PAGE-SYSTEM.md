# One shared page system — proposal (20 October 2026)

Status: **proposal for Stephan**. Nothing in the code has changed.

Stephan, 20 Oct: *"Do the pages the same way as the pop-ups: a page kit first,
then every page mapped and drawn module by module."* This is that kit, and the
module-by-module map.

Viewer: <https://claude.ai/artifact/S2FWVebvqtxrVL5LHahzHy> — menu group
**The page kit** (templates, layouts, rules, every template drawn with demo
content, light and dark), then **Every page, by module**.

## The problem in numbers (counted 20 Oct)

| What | Count |
|---|---|
| `.tsx` files under `resources/js/pages/` | 1,542 |
| Pages a controller renders and that have a file (`Inertia::render` / `inertia()`) | 721 |
| Files nothing renders: dialogs, tabs, sections, tests kept next to pages | ~720 |
| Files that look like pages but nothing renders (legacy copies, old create pages) | ~100 — checked module by module |
| Page scenes drawn before this kit | ~20 |

Pages by module (routed): Clients 14 · Sites & operations 172 · Medication &
clinical 47 · HR & rostering 126 · Finance & fleet 128 · Governance, H&S & hub
104 · IT & support 19 · Shell, settings & devices 92 · Outside pages 19.

## The kit: 13 templates, 6 layouts, 16 blocks

Every staff page is **top bar · sidebar · breadcrumbs · the sky header · the
body**. The body is one of six layouts, built only from 16 blocks.

| # | Template | Use when | Layout |
|---|---|---|---|
| 1 | Hub | A module's home: big header with up to 6 numbers, what needs attention, the module's areas | Main + side |
| 2 | List | Records in a table (or cards when a picture helps); filters and search in the header | Full width |
| 3 | Record | One person or thing: identity, status and main action up top; tabs for the rest | Main + side |
| 4 | Workspace | A queue on the left, the selected item on the right (IT requests, alerts, chat, referrals) | Split |
| 5 | Calendar | Month / week / day / timeline; the event opens in a side panel | Full width |
| 6 | Map | A list beside a map; pick in either and the other follows | Split |
| 7 | Board | Columns of cards along a path (recruitment, maintenance jobs) | Full width |
| 8 | Dashboard | Numbers and charts on the chart system; every chart opens the list behind it | Full width |
| 9 | Settings | Settings menu, config cards with switches, a save bar only when changed (Fleet pattern) | Settings |
| 10 | Builder | Full-screen designer: tools, canvas, inspector — the only page without the sky header | Builder |
| 11 | Document | Read a policy, report or printout; facts and versions beside it | Main + side |
| 12 | Full-page form | Legacy create / edit pages — almost all become a pop-up from the pop-up kit | Full width |
| 13 | Outside page | No staff shell: sign-in, careers, family portal; brand-slot logo, the sky as hero | Outside |

**Layouts:** Main + side (content + 360 px column) · Full width (never capped) ·
Split (420 px list + the rest) · Settings (220 px menu + cards) · Builder
(tools 56 px · canvas · inspector 300 px) · Outside (own slim header).

**Blocks:** Table · Cards · Numbers · List · Facts · Timeline · Chart · Empty ·
Calendar · Map · Board · Settings card · Form · Note · Builder · Document.

## Rules (proposed — for Stephan's OK)

1. Header size: **full** (up to 6 numbers) on hubs, lists and records;
   **compact** (title row + tabs) everywhere else.
2. Header buttons: one white main button named for what it does, up to two glass
   buttons, the rest under "More".
3. Tabs: first level in the sky rail, a second row only under a tab, never a
   third level — a third level becomes its own page.
4. Width: the body fills the screen. Side column 360 px, split list 420 px,
   settings menu 220 px.
5. Rows: ⋮ on every row, the same menu on right-click, row click opens the
   record, a pager under every table.
6. Empty / error / loading: the kit's empty block with one action; errors and
   loading from the Errors & states board.
7. Full-page create / edit forms become pop-ups from the pop-up kit; full screen
   only for builders.
8. Brand only through `--primary` (the sky, tints, the active tab); the logo is
   the brand slot. Nothing hard-coded. Avatars are brand-tinted initials.

## Header rules (21 Oct)

Stephan, 21 Oct: *"i am not sure how we deviated so far hero banners and pages are starting to look inconsistant."*

**Why it drifted:** rule 1 above was written down but never enforced. The page spec let every drawing set its own header, and the check only rejected missing fields and stat tiles. Each module was drawn by different helpers on a different day, so each settled into its own habits:

- Sites put chips and back arrows on lists.
- HR drew half its records slim.
- Medication and HR put every filter in the header (up to 8 chips).
- Clients repeated the tab counts as header numbers.

Across 600 page drawings there were **116 different header shapes**.

**Now enforced:** `page-kit/heroes.mjs` runs on every drawing at build time, for all modules including the four still to draw. Header shapes went from **116 to 75**. What remains is real content: whether a page has tabs, filters or numbers at all.

| | Rule | What it means |
|---|---|---|
| H1 | Header size by template | Full (title row, up to 6 numbers, tabs) on hubs, lists and records. Compact (title row and tabs) on every other page; its numbers become a tally at the top of the body. |
| H2 | One mark | An initials avatar only when the page is about one person: a person's record and their own My HR pages. Every other page shows its icon. |
| H3 | Back arrow | Only on a record, a page that belongs to one record, a document and a full-page form. |
| H4 | Status chip | Only on records. On other pages the chip's words join the sub-line. |
| H5 | Search in the header | Only on hubs, lists and workspaces. |
| H6 | Buttons | One white main button, up to two glass buttons, the rest under More. |
| H7 | Filters | At most four chips in the header; the rest fold into one "More filters" chip. |
| H8 | A number appears once | A header number that a tab already counts is dropped; the tab keeps it. |
| H9 | One sub-line | The line under the title stays on one line; later parts open the first block. Privacy, safety and legal wording never moves. |
| H10 | Six numbers at most | A header shows up to six numbers; any more lead the body as one tally (16 drawings had been silently dropping them). |
| H11 | Nine tabs at most | Eight tabs and a "More" tab holding the rest, the open tab always in view (26 drawings had been silently dropping tabs). |

## Writing rules (22 Oct)

Stephan, 22 Oct: *"i cant go through everything i am relying on you to do a good job please"*. A cross-module audit (`page-kit/consistency.mjs`) of the 600 page drawings found more drift than the headers. `page-kit/tidy.mjs` now applies one way of writing to every drawing at build time. It changed 761 strings, and every check now reads 0.

| | Rule | Found before |
|---|---|---|
| W1 | Breadcrumbs start at Home | 18 drawings (Clients, Sites) |
| W2 | Te reo with macrons: whānau, Rāwiri, Kōwhai | "Family/Whanau" in 4 Sites drawings |
| W3 | One date format, "27 Oct 2026" or "Tue 27 Oct 2026" (as `lib/datetime.ts` `formatDate`) | ISO 51, slash 28, long month 41, month-first 6 |
| W4 | One time format, "9:30 am", with ranges as "10:00 am – 1:00 pm"; a date then a time takes a comma (as `formatDateTime`) | 31 strings of 24-hour times; 24 ranges with "-" or "—". Durations and countdowns (27:41 left) are untouched |
| W5 | Money as $84.60 | "NZD 84.60" |
| W6 | No full stop at the end of a header line | 103 headers |
| W7 | Sentence case | 27 Title Case labels ("Control Room", "First Aid Certificate", "Critical Alerts") in 159 strings. Names of people, places and products keep their capitals |
| W8 | One verb for making a record | Add, New and Create were used for the same thing: 14 objects had two verbs ("New agreement" and "Add agreement"). Now "Add …", "New …" only for a message or conversation, "Create …" only when built from other things (a report, a pack, a pay run), and incidents always "Report incident" as in the top bar |

**Boards:** eight screens per board, not four. The viewer holds at most 511 files, and drawing all nine modules at four per board would need about 630. Page boards went from 152 to 80.

Viewer: The look › **Headers: one per template** (where it drifted per module, five real headers before and after, the shapes per template). Also fixed: log rows now share one time column sized to the longest entry, so long dates no longer run under the state ring.

## How it's drawn

Same method as the pop-ups. Helpers read each page's code (page file, its tabs
and components, the controller's props) into a spec (`SPEC-PAGES.md` in the
kit source); one renderer (`page-kit.mjs` + `oc-page.css`) draws every page from
it. Each module gets a **map board** (every route → template, drawings, what
changes, pages not drawn and why, what we found while reading the code) and
drawing boards with four screens each, light and dark.

## Long pages (approved 21 Oct)

Stephan, 21 Oct: *"how will these long ones work?"* … *"yes and check the other ones as well"*.

- **Drawings** show every page full length; a dashed line marks where the first screen (1440 × 1000) ends. Before this, 98 of 103 page boards were silently cut at 1000 px (114 page bodies).
- **The slim bar:** when the sky scrolls away, a slim bar stays under the top bar — mark, title, tabs, main button, the horizon line under it; records keep the back arrow. (Today the hero simply scrolls away and only some pages make their tabs sticky.)
- **Tables:** column headings stick inside their card; after about 25 rows the pager takes over.
- **Side column** follows while the main column scrolls, when it fits the screen.
- **Records** use their tab groups so a page rarely runs past three screens.
- **Settings:** the save bar is pinned to the bottom as soon as something changes.
- **Pop-ups:** header and footer stay, only the body scrolls. All 94 pop-up boards were checked: none is cut.
- **Older scene boards:** a check of all 317 boards found real cuts in 10 hand-made scenes (Company hub, referral case, the Calendar scene, the Chat settings menu, the IT queue scene and four archive scenes, plus the audit board); they now grow to full length with the same fold line. Cut on purpose and kept: scrolling chat lists, image crops, Branding thumbnails, and the IT ticket drawer board, which shows the window at the moment the drawer is open over the page.
- Kit board: The page kit › **Page kit · long pages** (the rule, a record full length, the same record scrolled with the slim bar). Check tool: `page-kit/clipcheck.mjs`.

## Module by module

### Clients — drawn 20 Oct (viewer: Every page › Clients)

17 page objects → **52 drawings** on 13 boards, after a map board. The 13 Clients pages, the old Add client page (still live), and the client profile drawn tab by tab: 6 groups, 38 tabs, each its own drawing (one tab, Snapshot › Overview, is the page itself). 4 helpers read the code; the two safety findings marked checked were re-read by me in the code.

The profile header has 9 numbers; the kit holds 6 (decision P2). Three full-page forms retire into their pop-ups (decision P3).

| # | Page | Template | Drawings |
|---|---|---|---|
| 1 | `operations/clients/index` | list | 1 |
| 2 | `operations/clients/assignments` | form | 1 |
| 3 | `operations/clients/calendar` | calendar | 1 |
| 4 | `operations/clients/documents` | list | 1 |
| 5 | `operations/clients/incidents` | list | 1 |
| 6 | `operations/clients/risks` | list | 1 |
| 7 | `operations/clients/portal-users` | list | 1 |
| 8 | `operations/clients/visit-requests` | list | 1 |
| 9 | `operations/clients/consents/Index` | list | 1 |
| 10 | `operations/clients/consent-requests/Index` | list | 1 |
| 11 | `operations/clients/consent-requests/Show` | profile | 1 |
| 12 | `operations/clients/consent-requests/Create` | form | 1 |
| 13 | `clients/Financials` | dashboard | 1 |
| 14 | `operations/clients/create` | form | 1 |
| 20 | `operations/clients/show` — Snapshot › Overview | profile | 15 |
| 40 | `operations/clients/show` — Health & safety › Health monitoring | profile | 16 |
| 60 | `operations/clients/show` — Relationships & governance › Family tree | profile | 7 |

**Not drawn (8):** `clients/index`, `clients/assignments`, `clients/documents`, `clients/incidents`, `clients/portal-users`, `clients/risks`, `clients/create`, `clients/edit` — nothing routes to them; each is an older copy of an `operations/clients/*` page (or, for `clients/edit`, data for the Edit details pop-up).

**Safety (12)**

- `routes/portal.php:282-311 · app/Http/Controllers/ClientVisitRequestController.php:56, 91` — The staff Calendar and Visit requests pages and the approve / decline actions only need sign-in plus 'view' on the client, which linked family portal users also pass (ClientPolicy.php:42). A family member can open the staff pages and approve or decline visit requests, including their own. **Checked in the code 20 Oct** (decision S13).
- `operations/clients/consents/Index.tsx:250-253, 553, 691` — Rows read c.is_expired / c.is_expiring_soon, but ClientConsentController.php:43 only sets is_consumable. The Expired and Expiring soon tabs are always empty even though the header counts them, and an expired consent shows as 'Given' with a Withdraw button. **Checked in the code 20 Oct** (decision S14).
- `resources/js/components/clients/profile/overview-grid.tsx:487` — Overview always draws Risk register and Medication; when the viewer can't see risks or medical data the props are withheld and the cards say “No active risks recorded.” / “No active medications.” (line 571) — hidden is shown as none.
- `operations/clients/tabs/personal-details.tsx:674` — Emergency contacts come from the medical prop (show.tsx:4345); staff without medical access see “No emergency contacts on record” even when contacts exist.
- `resources/js/components/clients/profile/tabs/behaviour-abc.tsx:93` — ABC log load failure is swallowed and shows “No ABC entries yet”; restraints-bsp-panel.tsx:63 hides the restrictive-practice panel on any error.
- `resources/js/components/client-profile/recent-clients-strip.tsx:16` — Recently viewed client names are kept in localStorage under one shared key, not per user — the next person on a shared house PC sees them.
- `operations/clients/tabs/workers.tsx:310` — Workers tab lists each worker's sign-in email (users.email, ClientController.php:457 and 1958) to anyone with care access, not a work contact.
- `operations/clients/tabs/health-monitoring/index.tsx:463` — Add fluid / bowel / seizure / sleep forms show to anyone who can see the tab (clinical.observations view), but saving needs medications.administer.record or clients.update (routes/operations.php:474-528) — a support worker's seizure entry is refused with a 403.
- `app/Http/Controllers/ClientController.php:1369` — Incidents tab only gets the last 5 incidents, so the tab count and the Open and Notifiable tiles (incidents-tab.tsx:19-30) never count past 5 — an older open or notifiable incident drops off.
- `app/Http/Controllers/ClientRiskController.php:87` — Remove risk hard-deletes the care-risk row (risk-management.tsx:622 'cannot be undone') even though risks have an Inactive state.
- `operations/clients/tabs/legacy-profile-sections.tsx:2727` — Privacy defaults: Schedule appointment ticks 'Share with family portal' by default (GP and specialist visits included), and photo upload defaults to 'Family & Staff' (line 886).
- `operations/clients/show.tsx:6073` — Portal notice says all portal users can view medical, documents and timeline, but portal access is per person (can_view_medical etc., PortalClientSectionAccess.php). The notice may overstate access, and 'RAG assistant' is developer jargon. Drawn word for word; needs a wording decision.

**Bug (26)**

- `operations/clients/incidents.tsx:252, 770` — Reads i.reported_by?.name, but reported_by is the user id; the controller loads the person as 'reporter' (ClientIncidentController.php:31). 'by <name>' never shows and search can't find reporters.
- `operations/clients/calendar.tsx:120, 145, 153` — Calendar CSS uses hsl(var(--primary)), hsl(var(--card)) and hsl(var(--border)) but the tokens are oklch, so the colours are invalid: the today pill and the right-click menu background don't draw. Lines 123-124 also hard-code violet rgba(139, 92, 246) grid lines.
- `operations/clients/calendar.tsx:324-338` — Schedule appointment closes the dialog without checking the response, and the Schedule button shows for everyone though the route needs calendar.create (routes/portal.php:290). A 403 or validation error fails silently.
- `resources/js/components/operations/dashboard/kpi-cards.tsx:67` — Operations dashboard 'Add new client' opens the old full-page form (operations/clients/create, still live) with no NHI, room, key worker, health or consent steps, instead of the Add client wizard. That form also posts to the legacy /clients URL (create.tsx:42).
- `operations/clients/portal-users.tsx:459` — Unlink removes a family member's portal access in one click, with no confirm.
- `operations/clients/show.tsx:2091` — Ctrl/⌘K opens the profile section palette and the global search (global-nav-search.tsx:89) at the same time.
- `operations/clients/show.tsx:1059` — Onboarding tab count reads onboarding.total but the controller sends onboarding.checklist.total (ClientController.php:1241), so the count never shows.
- `operations/clients/show.tsx:4353` — Personal details Edit shows for can.edit (clients.update) but the dialog only opens with can.update_client (policy) — can be a dead button.
- `resources/js/components/client-location/location-workspace.tsx:476` — Location views “Today” and “Safe zones & schedule” render the same panel.
- `operations/clients/show.tsx:1826` — Header ‘Care plan goals’ and Overview read active_plan; Care & support plan and Goals path read working_plan (show.tsx:949) — numbers disagree while a review is open.
- `operations/clients/tabs/goals-path.tsx:275` — “in progress” = every goal not achieved, so on-hold, cancelled and not-started goals are counted.
- `operations/clients/tabs/communication-notes.tsx:221` — Date-only family-note due date is formatted with a time, so it shows about 1:00 pm in NZ.
- `operations/clients/tabs/food-meal.tsx:232` — Log meal form shows to read-only viewers; only Add preference checks canEdit.
- `operations/clients/show.tsx:4252` — Health monitoring 'Record observation' button is not gated; the same action in the Add / log menu needs can.record_observation (show.tsx:2304).
- `operations/clients/tabs/health-monitoring/index.tsx:177` — Sleep 'Night' defaults to new Date().toISOString() — the UTC date, so before 1 pm NZ it pre-fills yesterday and after it today.
- `operations/clients/tabs/first-aid-tab.tsx:123` — Reads r.firstAider.name but Laravel serialises the relation as first_aider, so the first-aider name never shows.
- `app/Http/Controllers/ClientController.php:1380` — First-aid records capped at 10, so Treatments / Ambulance / To hospital tiles undercount.
- `app/Http/Controllers/ClientController.php:1350` — Formal H&S risk assessments on the Risk management tab are loaded on the first-aid section (hazards.view), not on risks access.
- `operations/clients/tabs/legacy-profile-sections.tsx:2791` — Calendar Schedule appointment POST never checks the response; the dialog closes and a failed save is silently lost.
- `operations/clients/tabs/legacy-profile-sections.tsx:2947` — Right-click 'Schedule Appointment' uses new Date() instead of the clicked slot.
- `operations/clients/tabs/legacy-profile-sections.tsx:2991` — FullCalendar runs timeZone="local" (browser zone), not NZ time.
- `operations/clients/show.tsx:6335` — Transport booking 'Cancel' deletes the booking straight away with no confirm.
- `operations/clients/show.tsx:1772` — 'Needs attention' adds flagged notes + open actions + visit requests, but open actions (ActionsAggregator.php:43-54, ClientController.php:1324) already include flagged-note and visit-request items, so they count twice; the governance pill count (show.tsx:2046) double-counts visit requests the same way.
- `operations/clients/show.tsx:6092` — Portal tab reads is_legal_guardian, is_emergency_contact, status and is_active, but portal_users only sends id/name/email/relation (ClientController.php:1124). The Legal Guardian and Emergency chips never show, and every user shows 'Active'.
- `operations/clients/show.tsx:4615` — Audit 'Restricted' state depends on progress_notes.update (or having entries), and its message names 'audit.viewClient'. The tab is actually gated by audit.viewAny (ClientProfileSectionAccess.php:95).
- `operations/clients/tabs/family-tree.tsx:267` — Guardians filter doesn't exclude is_primary, so a primary guardian is listed under both Primary contacts and Guardians.

**Tidy (28)** — on the map board (raw colours, duplicate screens, legacy URLs, coming-soon copy, missing labels).

### Sites & operations — drawn 21 Oct (viewer: Every page › Sites & operations)

Stephan, 21 Oct: *"The page kit and Clients look right. Carry on with Sites & operations the same way."* 172 routed pages → 151 page objects → **243 drawings** on 61 boards, after a map board. 8 helpers read the code. Record pages are drawn tab by tab (the Site profile has 25 tabs; the control-room alert, shift record, roster planner, respite hub and stay each get their views). The renderer now enforces the header rule: the main button, at most 2 glass buttons, the rest under More.

| Area | Pages | Drawings |
|---|---|---|
| Sites | 29 | 57 |
| Control room | 21 | 34 |
| Operations & tools | 45 | 65 |
| Workforce | 23 | 42 |
| Respite | 33 | 45 |

**Not drawn (29):** list variants merged into tabs or filter chips (respite for-stay, with-concerns, overdue, my-tasks…), per-Site copies of all-Sites screens, redirect-only respite routes, and page files nothing routes to (old catering pages, sites/plan, sites/capacity). Each is listed with its reason on the map board.

**Safety (17)** — checked in the code marked ✓ (decisions S15–S19; the rest are S20)

- ✓ `app/Http/Controllers/Sites/SiteRoomController.php:23` — The Bedrooms page sends every occupant's name, status, risk level, safeguarding flag, key worker, personal items and room history to anyone who can view the Site (sites.viewAny + view policy) — no clients.viewAny / viewAssigned check, unlike Site › Residents (tabs/registry.ts:111).
- `app/Http/Controllers/Sites/SiteCalendarController.php:34` — /sites/{id}/calendar and its events feed (line 74) only check the Site view policy, not calendar.view (which /calendar and Site › Calendar need); the Respite source then shows ‘Respite — <guest name>’ (Providers/RespiteObligationProvider.php:59) to those viewers.
- ✓ `app/Http/Controllers/Sites/SiteReportingController.php:187-250` — Site detail report has no Site check (any reports.sites.view holder can open any Site) and sends the whole Site with its credentials, vendors, assets and hazards to the browser: every credential's label, username, URL, notes and OTP account ride in the page props, whatever the vault's visibility rules say.
- `app/Http/Controllers/Sites/SiteReportingController.php:281, 63, 103, 141` — Overdue corrective actions only lists status 'open', so an overdue hazard marked In progress drops off the report; the hazard model counts open + in progress as overdue (SiteHazard.php:121-125). The Houses / Facilities / Head office 'Open hazards' meters undercount the same way.
- `app/Http/Controllers/Sites/SiteReportingController.php:45, 85, 124, 281, 342` — Sites reports aren't limited to the Sites the user can access (only reports.sites.view and Site type): Overdue actions shows hazard descriptions and assignees, and Asset condition shows assets, from every Site.
- ✓ `app/Services/Operations/FundingClaimService.php:247-266 · resources/js/pages/operations/fu` — The person who submits a Whaikaha / ACC funding claim can approve it themselves — there is no independence check (service agreements have one), and Submit / Approve show without checking permissions.
- `app/Http/Controllers/Operations/FamilyPortalController.php:148-158 · resources/js/pages/op` — Family portal: Care plans and Medication status can be switched on for families without active family information consent — update() only forces Respite, Care notes and Incidents off.
- `operations/review-queue/index.tsx:148-157, 400-408 · app/Http/Controllers/Operations/Clien` — Review queue 'Mark reviewed' clears a flagged daily note in one click — no confirm and no record of what the manager did, only reviewed_at / reviewed_by.
- `operations/handovers/components/cards-view.tsx:147-156 · list-view.tsx:197 · board-view.ts` — Incoming worker can acknowledge a handover in one click from the card, list row, board card or right-click menu, without opening it. The card shows only 240 characters of the notes, and the live medication snapshot is only in the detail pop-up (Index.tsx:306).
- ✓ `app/Http/Controllers/Respite/RespiteReferralController.php:53` — New referral page lists every client in the organisation; the workspace and New booking request limit clients to the viewer’s houses (applyAccessibleClientScope).
- `respite/stays/show.tsx:119 (RespiteStayController.php:100)` — The stay record never shows the guest’s critical alerts (life-threatening allergies), admission med-rec status, restraint reviews, open incidents or complaints — only the workspace Stays card does.
- `app/Http/Controllers/Respite/RespiteProcedureRunController.php:333` — Runs read step 'is_stop_gate', but the template example (procedures/create.tsx:29) and template page (procedures/show.tsx:57) use 'stop_gate' — stop-gates set the documented way never become stop-gate tasks.
- ✓ `app/Http/Controllers/Respite/RespiteCommunicationLogController.php:19 · RespiteHandoverNot` — These four never apply RespiteStayScope (daily notes, evidence packs, stays and bookings do). Anyone with the view permission sees every Site's respite comms logs, handover notes (sensitive ones included), risk plans and tasks, can open any record by id, and the create pages list every active stay.
- `respite/risk-plan-activations/show.tsx:277, 299 · RespiteRiskPlanActivationController.php:` — Deactivate and Suspend send 'reason', but the server requires deactivation_reason / suspension_reason. Both always fail validation, so an active risk plan can't be stopped or suspended from the page.
- `respite/risk-plan-activations/show.tsx:189-204 · app/Models/RespiteRiskPlanActivation.php:` — Staff acknowledgments reads activation.acknowledgments / ack.user.name, but the model stores staff_acknowledgments with user_name. The card always says 'No acknowledgments yet.' even after staff have acknowledged.
- `app/Models/RespiteRiskPlanActivation.php:117-120 · resources/js/pages/respite/risk-plan-ac` — The page says 'Active risk plans you have not yet acknowledged.', but the scope only keeps plans nobody has acknowledged. Once one person acknowledges, the plan drops off everyone else's list.
- `respite/daily-notes/index.tsx:79, 213 · for-stay.tsx:130 · app/Models/RespiteDailyNote.php` — The pages read note.has_concerns, but the model has no such attribute (only a hasConcerns() method). The Concern badge never shows on the list, and the 'With concerns' header count is always 0.

**Bugs (59) and tidy items (76)** — on the map board (Funding page crashes once a claim exists; job board "Post position" and mileage "Submit" do nothing; evidence-pack add/remove call missing routes; SLA late steps show "met"; UTC dates in NZ; raw colours; duplicate screens between respite and Workforce).

### Medication & clinical — drawn 21 Oct (viewer: Every page › Medication & clinical)

Stephan, 21 Oct: *"P4 yes. Move all the page drawings to the Oblivion blocks, then carry on with Medication & clinical."* 47 routed pages → 43 page objects → **96 drawings** on 24 boards, the first module drawn with the Oblivion blocks from the start. The eMAR pages were built from the approved mockups P00–P11, so the drawings keep their structure; every safety step is word for word.

| Area | Pages | Drawings |
|---|---|---|
| eMAR | 30 | 82 |
| Health & clinical | 13 | 14 |

**Not drawn (4):** `emar/PrnRecords` — Same as emar/record/hub (As-needed history) — legacy page, rendered only when EMAR_PERSON_RECORD=legacy (config/medications.php:6, default p02); EmarController::prn() hands /emar/prn to MedicationRecordHubController.; `emar/MarCharts` — Same as emar/record/hub (MAR charts) and emar/record/show — legacy page, only when EMAR_PERSON_RECORD=legacy; EmarController::mar() sends /emar/mar to the hub and /emar/mar?client_id= to the person’s record.; `emar/Medications` — Same as emar/record/hub (Medicines) — legacy page, only when EMAR_PERSON_RECORD=legacy; EmarController::medications() hands /emar/medications to MedicationRecordHubController.; `emar/StockManagement` — Same as emar/stock/StockHub — the older 'Stock & pharmacy' page (All / Low / Expiring / Expired / Controlled drugs / Pharmacy orders). Still routed at /emar/stock (routes/emar.php:172, EmarController::stock) and opened by the Stock hub's 'Stock overview' button, but lib/emar-navigation.ts lists /emar/stock as an alias of the Stock view, so the live landing (approved P06) is drawn instead.

**Safety (5)** — ✓ checked in the code (decisions S22–S25)

- ✓ `emar/Index.tsx:1015` — Action centre × (Dismiss) hides an item — overdue dose, CD discrepancy, out-of-range INR — with no reason or record, and drops it from the filter counts (lines 502–513), so the card can read “All clear in this view” (line 906) while the work is still open; it only comes back on reload.
- ✓ `emar/Handovers.tsx:198-205, 597-612` — The critical alert '… submitted handover with controlled drugs but no count check recorded' can be dismissed for the whole session and has no header number, so after one click nothing on the page shows the unverified controlled-drug handover.
- ✓ `emar/Destructions.tsx:132-135` — The disposal-register CSV's csvCell has no formula guard (StaffEligibility.tsx:149-154 has one): a note, batch or void reason starting with = + - @ runs as a formula when the legal register is opened in a spreadsheet.
- ✓ `app/Domain/Clinical/Enums/News2Band.php:33,41-44 · app/Domain/Clinical/Services/` — The deterioration watch and the 'On watch (NEWS2 ≥ medium)' number leave out Low-medium, the band for a single parameter scoring 3, whose own advice is 'urgent review by a registered nurse'. Those clients never appear on the Overview watch list.
- `app/Domain/Clinical/Services/ClinicalAssessmentService.php (record) · app/Domain` — An IDDSI assessment saved in Assessments & risk is stored only as a clinical risk assessment; nothing passes the new food or drink level to the person's meal restrictions, so a changed swallowing level never reaches meals. FRAT, Braden and MUST results are also only visible on this register, not on the client profile.

**Bugs (9) and tidy items (25)** — on the map board.

### HR & rostering — drawn 21 Oct (viewer: Every page › HR & rostering)

Stephan, 21 Oct: *"Long pages look right. Carry on with HR & rostering the same way."* 126 routed pages → 125 page objects → **209 drawings** on 53 boards, plus a map and a found board. Oblivion blocks from the start, full length, with the real HR sidebar sections. Privacy and employment-law steps are word for word: confidentiality notices, support-person rights, notice periods, e-signature wording, police vetting, pay, tax and KiwiSaver figures.

| Area | Pages | Drawings |
|---|---|---|
| My HR | 17 | 18 |
| People & recruitment | 19 | 46 |
| Time & leave | 7 | 14 |
| Pay & benefits | 15 | 16 |
| Performance & development | 20 | 42 |
| Engagement | 5 | 13 |
| Compliance & records | 20 | 31 |
| Employee lifecycle | 11 | 15 |
| Reports, settings & import | 10 | 11 |
| Attendance (Workforce) | 1 | 3 |

**Not drawn (1):** `staff/index` is the same as `hr/employees/index`. It's the older /staff list, still routed (routes/staff.php:16), but nothing links to it.

**Found: 170 (55 safety, 66 bugs, 49 tidy)**, all on the board "HR & rostering · found". I checked these in the code myself (decisions S7 and S26–S29):

- ✓ `StaffController.php:310`: the old Edit staff page syncs whatever `role_ids` are posted, after only a `staff.update` check. Anyone who can edit staff can grant Administrator, including to themself.
- ✓ `BuildsMyHrOverview.php:351`: Who's out sends each colleague's leave type to everyone at the Site. The badge shows Sick, Bereavement, and Family Violence (through the fallback label).
- ✓ `PolicyController.php:413`: deleting an old policy version hard-deletes it. `hr_policy_attestations.policy_version_id` cascades, so every sign-off of that version is lost.
- ✓ `VettingController.php` `clear()`: "Mark cleared" sets any check to clear, flagged ones too, with no confirm and no risk decision. `show()` sends the whole check, including disclosure details and the risk assessment, to everyone with `hr.vetting.view` (provider manager, coordinator, HR, auditor).
- ✓ `PayslipController.php:169`: year-to-date totals run from 1 January, not 1 April, and include draft and void payslips.
- ✗ Narrower than reported: `CarePlanAttestationService.php:555`. A clinician sign-off accepts an unverified, free-text credential. But signing needs `care_plans.update`, which only admin holds, so a support worker can't reach it. Moved to tidy.

### Finance & fleet — drawn 22 Oct (viewer: Every page › Finance & fleet)

Stephan, 22 Oct: *"i cant go through everything i am relying on you to do a good job please"*. I checked this module myself before publishing: against the kit, the header and writing rules, and the four modules before it. Finance & fleet is the first module drawn with the header and writing rules built in, and it came out right first time: 2 button verbs fixed and 1 Title Case label, nothing else.

128 routed pages → 124 page objects → **187 drawings** on 25 boards (8 a board), plus a map and a found board.

| Area | Pages | Drawings |
|---|---|---|
| Finance › Overview | 6 | 6 |
| Finance › General ledger | 11 | 12 |
| Finance › Payables | 11 | 17 |
| Finance › Receivables | 11 | 11 |
| Finance › Banking | 13 | 13 |
| Finance › Tax & compliance | 8 | 10 |
| Finance › Reports | 10 | 10 |
| Finance › Settings | 4 | 4 |
| Fleet & assets › Overview | 3 | 7 |
| Fleet & assets › Fleet | 17 | 34 |
| Fleet & assets › Transport | 8 | 13 |
| Fleet & assets › Assets | 3 | 14 |
| Fleet & assets › Maintenance | 10 | 19 |
| Fleet & assets › Maps & boundaries | 4 | 10 |
| Fleet & assets › Reports | 5 | 7 |

**Not drawn (4):** Consolidation (index, run results, record) and Intercompany. Every route goes through `RejectUnsupportedConsolidation` and returns 404, and nothing links to them.

**Found: 161 (17 safety, 67 bugs, 77 tidy).** I checked ten in the code myself; the safety ones are decisions S30–S36:

- ✓ Wandering alerts never reach the Wandering alerts tab: public channel listened to, private channel sent.
- ✓ A pre-transport check is saved with failed or blank answers.
- ✓ A vehicle's alert rules are saved, but nothing reads them.
- ✓ Bills can be approved by their creator, and spend approval is off by default.
- ✓ The IRD GST filing sets Box 10 to Box 8, dropping the Box 9 adjustments; Boxes 6 and 13 are always 0.00.
- ✓ A vendor's full bank account number shows on the vendor page.
- ✓ The community access report leaves out residents with no outings.
- ✗ Narrower than reported, now bugs: client statements and the Summary page's client list aren't Site-scoped, but only the finance and auditor roles (all Sites) can open them.
- ✗ By design: a daily-check issue doesn't restrict the vehicle. Stephan decided on 24 Sep that daily checks are recorded, not gated. Still worth doing: the warning before saving.

### Governance, H&S & hub — drawn 22 Oct (viewer: Every page › Governance, H&S & hub)

104 routed pages → 97 page objects → **168 drawings** on 21 boards, plus a map and a found board. The helpers' specs met every header and writing rule before the tidy pass. Governance keeps its plain-language vocabulary, and every legal, privacy and safety line is word for word: quorum, conflicts of interest, minutes, the Privacy Act 2020 clocks, OPC notification, legal holds, WorkSafe, Ngā Paerewa and restraint.

| Area | Pages | Drawings |
|---|---|---|
| Governance (incl. Roadmap) | 54 | 67 |
| Reporting (board monthly, compliance status, top risks) | 3 | 3 |
| Health & safety | 18 | 42 |
| Health & safety › Privacy | 9 | 14 |
| Health & safety › Incidents & safeguarding | 6 | 21 |
| Company hub | 4 | 18 |
| System (audit logs, QA checklist) | 2 | 2 |
| Public privacy policy | 1 | 1 |

**Not drawn (7):** Governance/Meetings/Calendar, the same calendar as Governance/Calendar; and six older privacy lists (breaches, deletion logs, DPIA, legal holds, requests, retention) that repeat the live privacy dashboard's tabs.

**Found: 113 (23 safety, 34 bugs, 56 tidy).** I checked twelve in the code myself; they are decisions S37–S43:

- ✓ The OPC can be recorded as notified when the prompt is cancelled, and a breach can be resolved without the required notification.
- ✓ The public privacy policy is written for the UK, and its "Last updated" date is always today.
- ✓ DPIAs can be self-approved, and an edit keeps the approval.
- ✓ Fixed "compliant" badges: Ngā Paerewa certified, first-aid cover, lone-worker duty met, Privacy Act compliant. The WorkSafe "Notified" badge is worked out from the injury's status.
- ✓ The board's care-quality page never shows "Needs action" for medication errors that reached a person.
- ✓ Roadmap spending approvals ignore the approver limits.
- ✓ Applying an incident template overwrites the severity the worker chose.

### Decision P4 — the Oblivion blocks (approved 21 Oct)

All page drawings moved from the generic blocks to the Oblivion ones at build time (`page-kit/p4.mjs`): stat tiles → tally lines and closing rings (a number appears once; a zero is a sentence; people move to the sub-line), event lists → log rows, people and houses → person and place cards, small empties → quiet lines, bar charts → charts with a point, header boxes without arrows, repeated row and table icons dropped. Boxes that only restrict who can see move into the sub-line; confidentiality, privacy, consent and safety boxes stay. See AUDIT-GENERIC.md.

## Found while listing pages

- 11 controller methods render page files that don't exist
  (`hr/recruitment/jobs`, `hr/recruitment/kits`, `hr/training/index`,
  `operations/timesheets/approvals`, 7 × `training/*`). Checked against
  `routes/*.php`: none is reachable (no GET route, or the route is a redirect),
  so they are **dead code, not live errors**. Delete with the HR and Shell
  modules.
