# Health & safety build — plan (from 23 October 2026)

Stephan approved the redesigned H&S drawings (design viewer v35,
claude.ai/artifact/S2FWVebvqtxrVL5LHahzHy, "Health & safety — rebuilt · pages")
and asked to build them. His choices (23 Oct):

- **One merge at the end.** Everything is built and tested on branch
  `claude/hs-workspace-build`. Main and the .com test site change only once
  the whole H&S workspace passes tests and browser checks.
- **Scope: H&S and incident pages.** The 48 drawn pages and the incident and
  safeguarding code they need. Codex's Control Room, Workforce, devices/CCTV,
  broadcast and staff-inbox changes stay out (they have their own snapshots).
- Codex's holds stay held: incident source checks on investigation, corrective
  action, evidence and WorkSafe writes; the Client response wrapper; annual
  authority and meeting command repairs.

## Where the code comes from

`claude/hs-codex-snapshot-20261023` (f0e9b5c4a), squashed into the build
branch as commit 85ec8e89f and limited to the scope above. Shared files take
only their H&S parts:

| File | What came in |
| --- | --- |
| `app/Services/Tasks/TaskAggregator.php` | H&S task providers, contextual H&S provider reads, event-page badge projection |
| `app/Http/Middleware/HandleInertiaRequests.php` | event-page task badge projection |
| `app/Services/UserSiteAccessService.php` | optional `CurrentAuthorizationReads` parameter |
| `app/Services/AuditLogger.php` | optional explicit `recordedAt` |
| `database/seeders/RbacSeeder.php` | incident owner permissions, `healthSafety.settings.manage`, `restraints.approve` (needs a grant migration) |
| `bootstrap/app.php` | `ProtectIncidentRecordingCommands` |
| `routes/console.php` | H&S delivery recovery jobs (not the broadcast one) |
| `routes/web.php` | My Day meeting invitation route |

Control Room read services brought in because the incident page shows the
Control Room response: `ControlRoomHandoverReadAccessService`,
`ControlRoomHandoverSummaryProtocol`, `ControlRoomOperatorNoteReadService`,
`ControlRoomOriginalReadOuterQuery`, `ControlRoomGovernanceReadService`,
`ControlRoomSourceContentService`.

## Stages

1. **Port and baseline.** Scoped port (done), then the H&S, incident,
   safeguarding and task suites until green.
2. **Shared H&S page parts** from the drawings: the record header (fact chips,
   stage line with the next step) and list header (up to four numbers) on
   `PageHeader`; body parts — story, timeline, ring with checks, tally,
   people and places cards, stage dots, risk map, WorkSafe clock, flow,
   choices, routes, evidence windows, know list, queue.
3. **Pages, group by group**, each matched view for view against its drawing
   (render a drawing with `oblivionfindings-design-kit/preview/hsx/prev.mjs`):

   | Group | Drawings |
   | --- | --- |
   | Incidents | 1, 2, 3, 4 |
   | Overview, reports, settings | 20, 24, 44, 45, 80 |
   | Reviews and actions | 40, 41, 42, 43 |
   | Risk and prevention | 60–66 |
   | Safeguarding and participation | 21, 22, 23, 25, 104 |
   | People safety | 81–88, 100–103 |
   | Equipment and chemicals | 120–130 |

4. **Release gate.** Full H&S/incident/task suites, `npm run lint`, full `tsc`
   (8 GB heap, heavy-lock), Vitest for H&S, a browser walkthrough of every
   page as the demo admin, then one merge with Stephan's go-ahead.

Heavy commands (Pest, tsc, eslint, vitest, builds, installs) run only through
`bash ~/.claude/heavy-lock.sh "<label>" -- <cmd>`.

## Progress (23 Oct 2026, evening)

Built and committed on `claude/hs-workspace-build` (local only): every group
except Equipment and chemicals (120–130, a helper is porting it).

| Group | Commits |
| --- | --- |
| Shared parts, Overview, hubs, Settings, Trends | 00d547291 … 376455c67 |
| Reviews and actions (40–43) | ac0f259f7 |
| Incidents (1–4) | f419de2ac |
| Safeguarding and participation (21–25, 104) | ec7cc61cb, bb168f44b, 1c7c0f0c9 |
| Restraints (100–103) | 1eea79418, 257c2fe80 |
| Risk and prevention (60–66) | a0e8edc3f |
| People safety (81–88) | f6351bc6e |
| Port fixes | df39c9daf (upload guards), f480b1bb7 (shared-part merges), injury-form brace in f6351bc6e |

Grant migrations for the new permissions are already in the port
(`2026_10_07_190100_register_incident_ownership_permissions`,
`2026_10_08_180510_register_health_safety_approval_policy_permissions`):
incident-owner permissions to admin, provider_manager and coordinator
(coordinator without holdRestricted); approval-workflow settings to admin;
plan approval authority to nobody by default. Stephan confirms these before
the merge.

Still to do: Equipment and chemicals; backend gaps listed in
DRAWING-CHANGES.md (incident_stages, register/hub status, risk map, …);
baseline and full test runs (queued behind other sessions' heavy jobs);
Prettier on changed files once `npm ci` has run; browser walkthrough as the
demo admin; then ask Stephan before the single merge.

## Overnight log (24–25 Oct 2026)

Stephan approved merging and deploying overnight (relayed by the coordinator, 24 Oct 20:45). Choices made without him, safer option where safety or privacy was involved:

- **Control Room fixes on main** (`d6b01d2c8`, PR #26): auto-start procedures need at least one alert type and severity; staff records open only within the viewer's Sites.
  - Kept safer: HR's `hr.employees.viewAllSites` is **not** a bypass for `/staff` records (only `sites.viewAll`); staff with no HR profile (legacy only) open only for `sites.viewAll`.
  - Follow-up on main (`1f3a1e855`, PR #27): the `/staff` list and the credentials/assignments pages follow the same Site rule.
  - **Decision for Stephan:** `/staff/{user}/assignments` still lists, and accepts, clients from every Site. Scoping that to the viewer's Sites is a client-privacy change left for him.
- **Exposure delivery panel**: Codex's panel called endpoints that were never built (a 404 hid the private record). It now reads the real `delivery-attempts` endpoint, read-only, "Destination not checked". Backend gap: destination proof, inspect and retry.
- **Held (would loosen a rule):** Codex's `routes/sites.php` removed the `sites.viewAny` check from the Site hazards list and export, so `hazards.view` alone would open them. The export route is added, but inside the Site group's `sites.viewAny` check. Decision for Stephan.
- **Port gaps closed** (Codex classes the ported H&S/incident code needed but the scoped port left out, copied unchanged): restraint Control Room alert bridge, four Control Room read services used by incident linked evidence, the CCTV recording-choice reader used by the incident recording preview, `TaskSearch::applyRecordPredicate`, and the respite restraint report route.
- **Tests that fail the same way on main** (not touched by this build): client-profile family-chat test, device-group auto-rule tests. The incident journey status test also failed on main ("Target missed" vs "SLA breached"); fixed here.
- **Test speed**: committing/concurrency tests force a full test-database rebuild (~446 migrations past the committed schema dump). Test runs use a local full dump swapped in only while Pest runs; nothing about it is committed.
- **Overview speed (25 Oct, ~03:00–04:30)**: on demo data the H&S overview timed out (162 s, 9,725 queries). The menu's task badge, built on every page, re-checked each of ~250 incidents with a 530 KB query, and the H&S event rules repeated the viewer's permission checks in every linked-event block. Three changes rewrite the SQL into the same logical test, so access is unchanged and the same queries run: a one-incident re-check uses only that incident's admitted block (`IncidentIndependenceIntakeService::narrowedToIncident`, falling back to the full query if anything changed); the event parent no longer adds the same authority checks twice; and the list scopes check the viewer-wide facts once around all blocks (`A AND (X1 OR X2 …)`). Result: 16 s on first load locally, including the ~5 s badge (Redis on the .com caches it per user). The overview is still slow (~15 s); making it faster would mean redesigning Codex's per-query proof replay, so it's a follow-up.
- **Overview without a current HR profile**: the Site comparison (`siteLeague`) refuses viewers who aren't current staff with approved Sites, and that took down the whole overview (main's tests expect it to open). The comparison is now withheld (empty) for them, the same way the PPE numbers already were. A refused Site choice still refuses.
- **Walkthrough (demo admin, local)**: 70 H&S, incident, safeguarding and task pages swept through the real server with no server errors. A lone-worker session refused the demo admin (an access rule), and pages needing a URL key 404 without it. Spot-checked overview, incident register, event record and worker participation against the drawings.
- **Old substance register tests retired**: `HazardousSubstanceControllerTest` tested the old register, but every `/health-safety/substances` route is now the drawn chemical workspace (`ChemicalPublicWorkspaceController`; Codex's snapshot kept the old test, which fails there too). Each old case maps to a stricter new test:
  - register payload, SDS state, controlled/site-scoped counts and current-SDS filter → `ChemicalPublicWorkspaceControllerTest` (canonical register, Inertia shapes) and `ChemicalCatalogueStoragePrivacyTest`;
  - create, status reason, SDS supersede, storage location → `ChemicalPublicWorkspaceControllerTest`, `ChemicalSdsPhysicalTest`, `ChemicalStorageCommandTest`;
  - support worker can't create → permission-subgroup 403 and "create or manage never implies read";
  - exposure → high severity with WorkSafe left for a qualified decision, and escalation on later treatment → `ChemicalExposureHsEventDeliveryTest` (real commits; the old observer call inside a test transaction is now refused by design).
  The old `HazardousSubstanceController` is no longer routed. It stays until a cleanup because `NzsAssuranceStaticTruthBoundaryTest` lists its path.
- **Control Room alert record**: Codex's `HsVisibilityService` dropped `forControlRoomAlert()`, which main's alert page still calls, so that page returned 500. It's restored through Codex's access-checked `forEvent()`: H&S readers get the same summary as before, and others see only the workflow state (no reference, names, WorkSafe details or link). That's stricter than live main.
- **Stricter rule that main's tests trip over**: reading incident-linked or treatment-linked H&S data (first aid, injuries, restraints, chemical exposures, the Site comparison) now needs current staff, meaning an active HR profile with access to the Site, or a Site-wide bypass permission. Several of main's tests create an H&S officer with only a role and no HR profile; on this branch they see none of that data. Those fixtures are updated to give the officer a real HR profile; the rule is not loosened. **Decision for Stephan:** an H&S officer account on the .com without an HR profile will see an overview with those sections empty.
- **Left failing, never passed on Codex's base**: `IncidentRoutingSettingsHttpTest` "settings only actor…". Shared middleware computes the job board count on every page and touches `clients`. It's outside H&S scope, so left for later.
- **Test fixture decision**: `IncidentHandoverE2ESeeder` now removes its one synthetic person's routing, ownership, independence and saved-copy records before reseeding, the same way it already removes their medication evidence. This bypasses the models' retention guards, but only for that seeded demo fixture.
- **Report menu on the overview**: restraint, drill, return-to-work and lone-worker items now open their drawn report pages (the in-place wizard's post lacked the request key the new commands require, so it was refused). Wizards themselves are unchanged.
- **Helper B decisions left for Stephan** (tests left failing, not loosened):
  - Respite restraint pieces: Codex's respite restraint recording action and workspace restraint lists only work with Codex's respite frontend; main's respite form and main's newer respite privacy test contradict them. Restraint recording from respite keeps main's working path. Porting Codex's respite restraint frontend + backend together is Stephan's call (2 `RespiteRestraintReadTest` cases).
  - `SafeguardingCurrentReadHttpTest` (4): Codex's own docblock calls it an unapplied, unrun extraction; the register never mounts `SafeguardingConcernReadScope`, even in his snapshot.
  - `HazardWorklistHeaderTest` (4): the held `sites.viewAny` decision above.
- **Incident journey for alert-linked incidents**: the ported governance read passes its current-authorization reads to the Control Room alert scopes; main's copy ignored them, so every alert-linked incident showed "Journey needs review"/"blocked". The scopes now take the optional reads (Codex's change, backward compatible); checked on demo data ("Operational response active" → "Continue Control Room response").
- **Helper A decisions left for Stephan** (tests left failing, not loosened):
  - H&S officer client picker on the incident report (`health_safety_officer` has no client-read permission; passing would show client names without it or change RBAC).
  - H&S tasks for HR-confidential incidents (would loosen "incident-linked events need incident read access").
  - `HealthSafetyTaskContextTest` "exact task lookup" forbids any SQL naming `permission_user`; the query it catches is a deny-only equality check. Narrowing the assertion is a test-rule decision.
  - `HsRiskIncidentSourceCompositionTest` "client final page": Codex marked the wrapper as not built.
- **Behaviour change (Codex's, kept)**: an incident outside the viewer's scope now returns 404 (not found) instead of main's 403; missing permission is still 403.
- **Known gap, same as live main**: Codex rewrote `ControlRoomNotificationService` (sanitised status codes, proof before copied incident text in notifications). It stays main's version (Control Room is out of this build's scope), so two `IncidentJourneyServiceTest` cases fail; notifications behave exactly as they do on live main today.
- **Site calendar (live page) fixed before deploy**: the ported participation calendar provider refuses the plain `obligations()` call that main's aggregator made, so `/calendar` would have thrown. The aggregator now uses Codex's viewer read for participation and drill obligations (checked: 176 items incl. participation and drill).
- **Whole-app sweep (25 Oct, ~07:20, demo admin, page data without the build)**: 759 parameter-free pages, one server error: `/hr/my/payslips` (`PayslipController` reads an unset `$feedService`). That file is unchanged from main, so it's an existing main bug, outside this build.
