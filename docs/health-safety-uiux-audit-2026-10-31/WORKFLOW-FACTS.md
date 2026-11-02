# H&S workflow facts from the code (helper read, 31 Oct 2026)

A helper read the routes, controllers, seeders and pages on `main` at `fe28a7968`. These are facts, not opinions; the
report (`REPORT.md`) draws on them. File references are relative to the repo root.

## Live corrections (permissions read from the .com database, 31 Oct, after audit round 1)
- **admin** holds `healthSafety.events.close`, `closeAny`, `healthSafety.closureExceptions.approve`, `healthSafety.overrideClosure` and `restraints.view/review/manage`. The seeder reading below said otherwise; the live grants win.
- **team_lead** and **health_safety_officer** hold `restraints.view`, `restraints.review` and `restraints.manage` (copied from `hazards.view` / `hazards.manage` by `RbacSeeder.php:1104–1122` and migration `2026_06_20_000002`).
- **provider_manager** holds `hazards.view` only among the hazard keys, plus `incidents.owner.assign`; no restraint keys.
- **compliance_lead** holds `healthSafety.closureExceptions.approve` and `restraints.view`.
- `healthSafety.closureExceptions.request`: admin, team_lead, health_safety_officer.

## What each role holds (seeder reading)
Sources: `database/seeders/RbacSeeder.php` (provider_manager 703+, support_worker 848–890, team_lead 963–1008,
health_safety_officer 1011–1023, admin 686–691) and grant migrations `2026_10_30_090000`, `_110000`, `_120000`,
`2026_10_25_090000`.
- **admin:** every key except `RESTRICTED_INDEPENDENT_AUTHORITY` (RbacSeeder.php:41–66), which includes
  `healthSafety.events.close`, `.closeAny`, `.closureExceptions.request`, `.closureExceptions.approve`, `restraints.approve`.
- **provider_manager:** `hazards.view` only (read); `incidents.*` including `incidents.owner.assign`;
  `safeguarding.viewAny`/`create`; `hr.wellbeing.view`. No `governance.view`.
- **team_lead:** `hazards.view/create/manage/assign`, `healthSafety.events.close`, `closureExceptions.request`,
  `incidents.create`, `clients.viewAny`. No incident register; no `restraints.*`.
- **health_safety_officer:** `hazards.*` + `hazards.close`, `healthSafety.viewAllSites`, `events.close`, `closeAny`,
  `closureExceptions.request`, `incidents.create` + `viewAny`, `safeguarding.create`. No `restraints.*`,
  `governance.view`, `hr.wellbeing.view`.
- **support_worker:** `incidents.viewAssigned/create`, `restraints.report`, `injuries.report`, `safeguarding.create`,
  `procedures.view`. No `hazards.*`.

## 1. Report something
- Chooser: `resources/js/pages/health-safety/components/report-launcher.tsx`; each tile shows only if its permission
  passes (incident `incidents.create`; injury `hazards.create|manage` or `injuries.report`; first aid
  `hazards.create|manage`; restraint `restraints.create|manage|report`; hazard `hazards.create`; exposure
  `hazards.create|manage`; working alone `hazards.view` + `hazards.manage`; safeguarding `safeguarding.create`).
- `components/report-pages.ts` sends most tiles to their own pages; incident opens `ReportIncidentDialog` in place on the
  Overview; hazard opens the wizard "Log hazard + risk assessment" (`wizard-configs.tsx:610`).
- Entry points: Overview header (`command-centre-hero.tsx:349–353`); "Your start" tiles (`role-start.tsx:46–79`); My Day
  "Record care" (`my-day/components/record-care-actions.tsx`: "Report incident" 105–112 always, "Report something"
  113–122 when the person can report more than an incident); top bar "Report incident" → `/incidents/create`
  (`app-header.tsx:185–195`, `incidents.create`, hidden below `lg`); staff shell "Report incident"
  (`layouts/staff-page-shell.tsx:110`) → `/incidents` (the list, not a form).
- `/incidents/create` → `/incidents?report=` for register readers, `/incidents/report` for others
  (`IncidentController.php:510–533`); `/incidents/report` returns to `/my-day` for non-readers (580).
- Stuck: My Day hazard tile falls back to `/compliance/hazards` (a list; `record-care-actions.tsx:55`); exposure goes to the
  chemical register "to pick the chemical"; working alone (`/lone-workers?start=1`) has no `return_to`; the H&S officer
  and team lead never see a restraint tile.

## 2. Triage and ownership
- Waiting handovers: governance start card "Waiting for Health & safety to accept", pill "Accept now"
  (`worklists.tsx:240, 520`); other starts "Needs you now" row "Accept for Health & safety — …" with "Accept"
  (`dashboard.tsx:688–700`); Safety reviews tab "Awaiting handover", number "Handover to accept", row menu "Review H&S
  handover" (`events/index.tsx:343, 540, 407`). All link to `/health-safety/events/{id}?action=accept-handover`.
- `can_accept` needs `hazards.manage`, an incident source, submitted and not draft (`HsEventController.php:1031–1035`).
  Pane "Accept H&S handover", optional owner (blank = "Assign me"), posts `events.handover.accept`. Owner must hold
  `hazards.manage` + `hazards.view` (`canOwnEvent`, 1782).
- "Needs an owner" in header facts and next step (`event-detail-dialog.tsx:1434, 1790`); side card "Assign someone"
  (1731) opens the accept pane. **No route to change a review owner after acceptance** (`routes/health-safety.php`).
- The acceptance worklist isn't filtered by `hazards.manage` (`HsDashboardService.php:229–260`): provider managers see
  "Accept" rows and land read-only (`event-detail-dialog.tsx:1699`).
- "Where incidents are up to" never renders: no controller sends `incident_stages` (`dashboard.tsx:408`).
- Incident owner: `/incidents/{id}` header "Assign someone / Change owner / Choose a new owner"
  (`incidents/show.tsx:443–468`, `incident-ownership-panel.tsx:63`); needs `incidents.owner.assign` (admin,
  provider_manager only).

## 3. Investigate
- `/health-safety/events/{id}` → `events/show.tsx` → `EventRecordWorkspace` in
  `components/health-safety/event-detail-dialog.tsx` (7,827 lines).
- Tabs (1306–1314): Overview · Handover · Investigation · Corrective actions · Risk · Evidence · Timeline.
- Stages (`PageHeaderStages`, 1799): Open › Investigating › Corrective action › Monitoring › Closed.
- Header: first allowed action = primary (with a + icon even for "Accept handover"); next two glass; rest under ⋯ "More
  safety review actions" (1578–1626). "Update WorkSafe decision" always under More.
- Steps (all `hazards.manage`, review open): Start investigation (2535) → Record findings → Submit for review (posts with
  no confirm, 5170) → Review / Return for rework → Approve and complete → per recommendation "Choose outcome / Change
  outcome" (2750) → "Raise a corrective action". Read-only viewers see no controls.

## 4. Corrective actions
- Add: on the review "Add corrective action" (2909/2925); on the register only via a row menu that opens the parent
  review (`corrective-actions/index.tsx:549–560`). No Add button on the register header.
- Owner: record page header "Assign owner" / "Hand over ownership" (`corrective-actions/show.tsx:283`); owner must hold
  `hazards.manage` (`HsCorrectiveActionController.php:283`), so in practice team_lead, health_safety_officer, admin.
- Lifecycle: Start action (needs an owner) → Mark complete → Verify action (a different person;
  `HsCorrectiveActionWorkspace.php:99–103`) → Close action; Return for rework. All `hazards.manage`.
- On the review page these steps are only in the row ⋮ / right-click (`event-detail-dialog.tsx:2983, 3082`); row click
  opens the action record.
- Register: Board default, five columns Needs an owner · To do · In progress · Waiting to be checked · Checked and closed
  (935–981); first page only, "N more" switches to List; lifecycle right-click or ⋮ only; the rail calls it "Actions".

## 5. Close and WorkSafe
- WorkSafe chain: Record WorkSafe decision (needs `hazards.manage` and handover accepted or not required) → Record
  WorkSafe notification → Record acknowledgement → Review Site preservation → Record Site release.
- Close: "Close safety review" in the header (1517), "Close event" in the dialog footer, pane title and submit (1186,
  3424, 3495); needs `healthSafety.events.close`. Pane: gate list, "Hard safety blockers", closure summary, closure
  exception panel; request ("Send for independent decision", 3639) needs `closureExceptions.request`; approve needs
  `closureExceptions.approve` (compliance_lead only). "Close anyway" not found in code (`vocabulary.md:65` prescribes it).
- Server lets only the recorded owner close unless `closeAny` (`HsEventClosureService.php:697–705`); the UI doesn't check,
  so a non-owner team lead is refused after submitting. Admins lack `events.close` but the register row menu offers
  "Close safety review" on `hazards.manage` (`events/index.tsx:485–491`).

## 6. Registers
| Register | Route (read) | Page | Hub |
|---|---|---|---|
| Risk assessments | `/health-safety/risk-assessments` (`hazards.view`) | `risk-assessments/index`, `show` | Risks and prevention |
| Hazards | `/compliance/hazards` (`hazards.view`) | `compliance/hazards/index.tsx` | Risks and prevention |
| Inspections | `/sites/inspections` (`checklists.view`) | `sites/inspections/global.tsx` | **none** |
| Emergency drills | `/health-safety/drills` | `drills/index`, `show` | Risks and prevention |
| First aid | `/health-safety/first-aid`; report `hazards.manage|create` | `first-aid/index`, `report`, `show` | Injuries and care |
| PPE | `/health-safety/ppe` | `ppe/index.tsx` | Risks and prevention ("Protective gear") |
| Working alone | `/health-safety/lone-workers` | `lone-workers/index`, `show` | Risks and prevention |
| Workplace injuries | `/health-safety/injuries` (`hazards.view` or `hr.wellbeing.view`); create also `injuries.report` | `injuries/workspace-index`, `create`, `show` | Injuries and care |
| Restraints | `/health-safety/restraints` (`restraints.view`); report also `restraints.report` | `restraints/workspace-index`, `events/report` | Injuries and care |
| Chemicals | `/health-safety/substances` | `substances/index.tsx` | Risks and prevention |
| Chemical exposures | `/health-safety/chemical-exposures` | `chemical-exposure-index.tsx` | Injuries and care |
Hub config: `resources/js/lib/health-safety/workspace-navigation.ts:81–419`.

## 7. Overview start and Tasks
- `startFor` (`HealthSafetyDashboardController.php:59–69`): `viewAllSites` or `governance.view` → "Your queue" (H&S
  officer, admin); `hazards.manage` → "Your site" (team lead); else "Your start" (provider_manager). Support workers can't
  open `/health-safety` (needs `hazards.view`).
- "Your queue" (`dashboard.tsx:414–421`): Needs attention, handovers, overdue actions, notifiable events, trend; no "All
  tasks" link; the H&S officer gets no Board reports (needs `governance.view`).
- "Your site" / "Your start": "What needs you now" (+ "All tasks", 722) and incident bars; "Your start" drops header
  numbers and filters and adds "Open My Day" (379–389); its tiles are incident and safeguarding only.
- `/tasks` H&S sources gated by `hazards.view` (events, investigations, actions, hazards, first aid follow-ups, drills,
  drill findings), injuries (`hazards.view` or `hr.wellbeing.view`), restraint reviews (`restraints.review|manage`), private
  follow-ups (`hr.wellbeing.view`), exposure deliveries (`hazards.manage` + `hr.wellbeing.view`). The label "H&S Events"
  (`HsEventProvider.php:34`) disagrees with "Safety reviews".

## 8. Support worker
- My Day "Report incident" → `/incidents/create` → the register report form (they hold `viewAssigned`); "Report something"
  offers incident, workplace injury, restraint, safeguarding; report pages return to `/my-day`.
- Sidebar: "Incidents and safety reviews" (Incidents, Raise a safeguarding concern) and "Risks and prevention"
  (Procedures). No sidebar path to report an injury or restraint.
- My HR Injuries tab (`/hr/my/injuries`) shows when any injury is on record (`BuildsMyHrShell.php:137`;
  `my-hr-tabs.tsx:115–120`); My Day "Your workplace injury" card only while one is open (`MyTasksController.php:242`).

## Help text that tells people to right-click (shown to users)
`pages/health-safety/analytics.tsx:1078`, `chemical-exposure-index.tsx:92`, `components/worklists.tsx:536`,
`corrective-actions/index.tsx:827`, `first-aid/index.tsx:532`, `ppe/_record-panels.tsx:253`,
`ppe/_register-list.tsx:119, 152`, `restraints/workspace-index.tsx:567`,
`worker-participation/participation-views.tsx:280`, `components/health-safety/first-aid/record-panels.tsx:572`.

## Same thing, different words
- Actions: "Actions" (rail, `workspace-navigation.ts:144`) vs "Corrective actions" (page title and tab,
  `corrective-actions/index.tsx:622`, `event-detail-dialog.tsx:1310`); "Traceability report" (`corrective-actions/index.tsx:639`)
  vs "Actions and their safety reviews" (`workspace-navigation.ts:386`).
- Waiting for a check: "Waiting for verification" (`dashboard.tsx:672`), "Awaiting verification"
  (`corrective-actions/index.tsx:450`, `event-detail-dialog.tsx:652`), "Waiting to be checked"
  (`corrective-actions/index.tsx:961`, `events/index.tsx:527`), "Actions awaiting verification" (`events/index.tsx:663`),
  "Done — waiting to be checked" (`hs-labels.ts:37`); stage dots say "Checked" for a done but unchecked action
  (`event-detail-dialog.tsx:2788`).
- Verify: "Verify action" (`event-detail-dialog.tsx:2857`, `corrective-actions/show.tsx:241`), "Verify…" and "Verify"
  (`corrective-actions/index.tsx:510, 1357`); "Check it worked" not found.
- Accept: "Accept now", "Accept H&S handover" (`worklists.tsx:240, 252`), "Accept" (`dashboard.tsx:699`), "Accept
  handover" (`event-detail-dialog.tsx:1475, 3839`), "Accept H&S handover" (3800), "Accept this H&S handover"
  (`HsEventController.php:1060`), "Review H&S handover" (`events/index.tsx:407`), "Waiting for Health & safety to accept"
  (`worklists.tsx:520`) vs "Awaiting H&S acceptance" (`HsDashboardService.php:255`).
- Report: "Report incident" (`app-header.tsx:193`, `record-care-actions.tsx:111`, `incidents/index.tsx:775`) vs "Report
  something"; "Report a hazard" opens "Log hazard + risk assessment"; "Report a workplace injury" vs "Report workplace
  injury" (`injuries/create.tsx:36`).
- Close: "Close safety review" vs "Close event".
- Stages: "Investigating", "Corrective action", "Monitoring" (`event-detail-dialog.tsx:599–623`) vs the vocabulary.

## Role × job
| Job | admin | provider_manager | health_safety_officer | team_lead | support_worker |
|---|---|---|---|---|---|
| Report | Do (all 8) | Do (incident, safeguarding) | Do (no restraint) | Do (no restraint) | Do via My Day (4 types) |
| Accept / own review | Do | See | Do | Do | None |
| Incident owner | Do | Do | See | None | None |
| Investigate | Do | See | Do | Do | None |
| Corrective actions | Do | See | Do | Do | None |
| WorkSafe | Do | See | Do | Do | None |
| Close review | Do (live: `closeAny`) | See | Do (`closeAny`) | Do if owner | None |
| Approve "close anyway" | Do (live) | None | None | None | None (compliance lead: Do) |
| Restraint register | Do | None | Do (live) | Do (live) | Report only |
| Overview | Your queue | Your start (read) | Your queue | Your site | None (403) |
| My HR injuries | Own | Own | Own | Own | Own |
