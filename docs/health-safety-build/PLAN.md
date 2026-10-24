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
- **Old injury register tests mapped (`InjuriesControllerTest`, 31 cases)**: `/health-safety/injuries` is now `ReturnToWorkController` on `WorkplaceInjurySourceService`. The file is kept; 20 cases are rewritten against the keyed commands (UUID key, `expected_version`, staff with a current HR profile at the Site) and 11 are retired:
  - ported: register/export/record Site scope over HTTP (another Site's or a Site-less injury, `?injury=`, `?site_id=` and the export filter are 404, was 403); every nested command on another Site's injury 404; capture/update refusing another Site's worker (403), Site and incident (404); derived ACC and incident link; the WorkSafe seam (notifiable injury → pending `NotifiableIncident`, capture-only reporter escalates without signing the decision, downgrade retracts and resolves the alert, retraction keeps a serious alert, re-escalation keeps history, WorkSafe flip on a legacy injury with no `created_by`); Control Room projection failure rolls capture and update back; idempotent Site-linked journey; poisoned alert tuple (5) fails closed; illegal lifecycle jump; SVG upload refused. The three DB/model cases are unchanged.
  - covered: index/counts → `InjuryWorkspaceControllerTest` (permanent pages) and `InjuryRegisterPrivacyTest`; detail → `…legacy_detail_bookmark_redirects…`; field edit and "status not via update" → `…factual_update…`, `…noncreate_write_requires_version…`; RTW plan, capacity, modified duty → `…existing_plan_update_and_duty_routes…`, `…capacity_endpoint…`; private evidence, sandbox CSP, wrong-parent 404, soft delete → `…private_upload_download_removal…`, `…download_refuses_factual_readers…`; export → `…export_uses_current_factual_scope…`.
  - gone (drawn design): `/{id}` redirecting to a register modal (it is the record page now); "recovered" stamping `actual_return_date` (no invented return date, `…lifecycle_command_preserves_actual_return_uncertainty…`).
  - Safety rules: WorkSafe in this file; Site isolation here plus `InjuryRegisterPrivacyTest`/`InjuryWorkspacePrivacyTest`; medical access in `InjuryWorkspaceControllerTest`/`InjuryWorkspacePrivacyTest`. Incident provenance that disagrees on Site still saves nothing, but now fails as a server error (`LogicException`), not 403.
- **Old PPE register tests mapped (`PpeRegisterTest`, 24 cases)**: PPE is now the PPE workspace on `PpeCommandService`. The file is kept; 10 cases are rewritten against the keyed commands and 14 are retired:
  - ported: inventory tab counts; unknown catalogue category refused; new stock `available` with its creator; return releases stock; a "condemned" inspection condemns the item; condemn needs a reason and no active issue; dispose only after condemn; no second condemnation (all now 422 `status`/`reason`, was a flash error); private evidence upload, download with the sandbox CSP header, reasoned removal (this one case uses the guarded physical-commit fixture); compliance reminders unchanged.
  - covered: page shape → `PpeWorkspaceControllerTest::…permanent_inventory_allocation_and_inspection…`, `PpeRegisterReadPrivacyTest::…register_counts…`; RPE fit-test due → `…allocation_active_and_unacknowledged…`; detail → `…legacy_item_converges…`; retired types listed → `…catalogue_retirement…`; type edit/toggle → `…type_create_update_retire_and_restore…`; issue with acknowledgement → `PpeCommandLifecycleTest::…issue_owns_whole_record…`; acknowledge, return → `…manager_ack_and_return…`; condemn then dispose → `…condemnation_and_disposal…`; return never revives condemned → `…return_never_releases_condemned…`; wrong-parent download → `PpeEvidenceHttpTest::…download_requires_literal_parent_kind…`; view-only/no-permission → `…warmed_read_and_manage_permissions…`, `…auth_only_own_page_is_narrow…`; own acknowledgement and someone else's (404, was 403) → `…worker_acknowledgement_is_actor_bound…`, `…auth_only_own_page_denies_other_worker…`.
  - Safety rules: Site isolation and privacy in `PpeAccessPrivacyTest`, `PpeWorkspaceControllerTest`, `PpeRegisterReadPrivacyTest`, `PpeEvidenceReadPrivacyTest`.
- **Old restraint register tests mapped (`RestraintRegisterTest`, 22 cases)**: restraints are now `RestraintController@workspaceIndex`, `RestraintEventWorkspaceController` and `RestraintService`. The file is kept; 6 cases are rewritten and 16 are retired:
  - ported: two-Site register counts, pickers and export (foreign `site_id`/`client_id` 403); another Site's summary, event, review, incident link, plan activate/review and evidence upload/download/removal all refused with nothing changed (403; main answered 404); the all-Sites H&S bypass (now needs the H&S bypass and an independent all-Sites Client read together; either alone, or manage alone, sees and changes nothing); capture refuses a missing trigger, de-escalation or restraint description, an out-of-plan event without a reason and an injury without details; an in-plan event links only to the Client's approved, published plan version (`verified`), never an unapproved draft; an incident of another Client cannot be linked (404, was a validation error).
  - covered: register payload, plans lens, export, Client summary → `RestraintRegisterServiceTest` (`…register_pages_sources_counts…`, `…review_date_today_is_due…`, `…every_read_excludes_foreign…`, `…client_summary_is_five_recent_rows…`, `…http_register_and_client_summary…`); detail → `RestraintEntryParityTest::…legacy_event_and_plan_register_links_redirect…`; critical review and preserved first reviewer → `RestraintReviewOwnershipTest::…owner_is_distinct_from_first_historical_reviewer…`; link/unlink → `…current_review_capability_can_link_and_unlink…`; view/review/link/summary permissions → `…capture_only_and_review_only_pick_sources…`, `…fresh_rbac_client_and_hr_revocation…`, `RestraintEventAccessTest::…capture_only_incident_association…`; plan lifecycle (activation now waits for approval) → `BehaviourSupportPlanWorkspaceHttpTest::…submit_approve_activate_archive…`, `…requested_active_review_is_explicitly_pending…`; plan review → `…all_retained_review_outcomes…`; evidence (removal is now reasoned and kept, not a hard delete) → `RestraintEvidenceTest`.
  - Safety rules: authorisation and approval chain in `BehaviourSupportPlanApprovalTest`/`BehaviourSupportPlanVersionTest`/this file; justification and least-restrictive record in this file; debrief in `RestraintMonitoringDebriefTest` (no test yet refuses a not-done debrief without a reason, though the code does); Site isolation in this file and `RestraintEventAccessTest`.
- **Decision for Stephan: restraint refusals answer 403, not 404.** Codex's restraint access returns 403 for another Site's restraint records, where main answered 404. Access and content are still refused, but someone who already holds restraint permissions can tell that a record number exists at another Site. Injuries and PPE still answer 404. Switching restraints to 404 means changing Codex's access contract and its tests, so it's left as is for his call.
- **Injury capture with mismatched Site/Client**: still saves nothing, but now fails with an internal error (500) instead of main's 403.
- **Test gap (code enforces it)**: no test refuses a restraint debrief marked not done or declined without a reason; `RestraintService` enforces it.

## Stephan's answers (25 Oct ~08:40, relayed by the coordinator)

1. **Merge timing**: wait for a full pass. Every test passes or fails the same way on main; no known-failures merge.
2. **Site hazards list/export**: keep the house check (`sites.viewAny`) on both. `HazardWorklistHeaderTest` expects it.
3. **Restraint refusals**: keep Codex's 403 ("not allowed") for another Site's restraint records.
4. **Staff Assignments clients**: `/staff/{user}/assignments` lists and accepts only clients at Sites the viewer can access (`sites.viewAll` bypass), following main's staff Site rule (1f3a1e855).
5. **H&S data needs a current HR profile**: kept. The .com demo H&S officer accounts must have a current HR profile at their Sites (demo-only data step).
6. **HealthSafetyTaskContextTest**: narrow the "no SQL naming permission_user" rule so it still blocks anything that could grant access but allows the deny-only equality check.
7. **Respite restraint**: main's working respite restraint form stays for this merge; the 2 contradicting `RespiteRestraintReadTest` cases are updated to main's behaviour.
   - **Follow-up (logged so it isn't lost):** Codex's dedicated "record a restraint for this stay" page and its workspace restraint lists, rebuilt later on top of main's newer respite privacy rules (`RespiteWorkspaceMedicationPrivacyTest`).
   - **Stay report page unrouted (coordinator, 25 Oct ~11:00):** Codex's `/respite/stays/{stay}/restraints/report` (`RestraintEventWorkspaceController@reportStay`) is removed from `routes/respite.php` with its controller method, and the stay page's `restraints.report_href` is now null. Nothing linked to it, and its receipt recovery could not find a submission of main's form, so a reachable page that silently lost submissions was the less safe option. The stay page comes back with the follow-up; `RespiteRestraintReadService::reportContext` stays (with its service tests) for that rebuild. Generated Wayfinder files are left for the next build to regenerate.
   - **Restraint tests that expected Codex's keyed stay recorder** (receipt, `capture_entry` 'respite', UUID replay, parity between the two entries, the stay report page) are rewritten against main's form; the few that tested only the keyed receipt or the unrouted page are removed (marked below):
     - `RestraintEntryParityTest`: "both entries record…" keeps the shared entry's recorded tuple and now checks that main's form records the stay's own Client, Site and stay and still reaches H&S review (source-review delivery → H&S event). "Replays its exact UUID" is now the shared entry only; main's form keeps no receipt and sends its respite event once. "Same UUID cannot cross entries" is **removed** (there is no respite UUID to cross). "Other Client/Site/stay" is now: main's form ignores a forged Client/Site/stay in the payload and records the stay's own; another Site's stay is 404 on both entries. "Unapproved legacy plan": the shared entry still records it as `approval_unknown`; main's form refuses a plan past its review date (404). "Fresh revocation": main's form checks `respite.stays.manage` on the user loaded for each request.
     - `RestraintEventWorkspaceHttpTest`: the two respite capture cases now check main's form: the stay's own Client/Site/stay, one respite event per submission, no receipt, trigger/de-escalation/restraint description required, 403 without the stay grant, a forged Client/stay ignored, and 404 for a coordinator without that Site.
     - `RestraintReportRecoveryHttpTest`: "respite report is canonical…" is now "respite-only actor records through main's form": no shared capture, plan create, generic report or register, and the stay report URL is 404. "Respite permission cannot recover a former shared create" now checks the generic report (the only recovery entry) refuses. "Respite report rejects foreign Client/Site" and "explicit stay report never proves another stay's receipt" are **removed** with the page. "Stay-bound plan create" keeps its recorded stay, Client, no approval and no publication; its stay-report recovery is removed. "Generic report rejects stay context" now also checks the stay report URL is 404.
     - `RespiteRestraintReadTest`: the stay page's `report_href` is null; the capture-only coordinator posts main's form directly (stay report URL 404, generic report 403); the query-spoof cases are removed with the page, and a foreign stay or archived Site still has no capabilities and the service capture context refuses.
- **Restraint group fixes (25 Oct, helper C)**: one app fix and test fixtures that never matched the schema or PHP:
  - App: the event page asked for the review-only pickers whenever the viewer could capture, so a reader with `restraints.view` + `restraints.create` (no review) got 403 on the whole page. Pickers are now built only for review authority; capture adds evidence without them. Read and command rules are unchanged.
  - Fixtures: incident status `open` is not allowed (`chk_incident_status`; `submitted` instead); `audit_log_id` is NOT NULL with a restrict-on-delete key in Codex's schema too, so a lost audit is simulated with an unrelated audit row; H&S closes in fixtures go through `@hs_canonical_close_event_id`; a moved restraint goes to a real Site (foreign key); `external_clinical_account` is not mass-assignable (`forceFill`); a no-plan capture is `missing`, not `approval_unknown`; MySQL json reorders snapshot keys (`JsonEvidence::canonicalise`); arrow functions copied the escaped frame by value; the metric mutation now waits for the booking read it describes; a re-attach is idempotent; the handover fixture needs `submitted_at`; an out-of-scope incident page is 404 (kept Codex rule) as well as 403; the register redirect keeps the register as `return_to`.
  - Narrowed (decision 6 style): the review-task navigation check refuses a top-level `select *` from `restraint_events` and narrative columns, but allows the Client Site `EXISTS (select * from clients …)` guard, which returns no columns.
  - **Not ported, left failing (Control Room / Clinical are out of scope):** Codex's Control Room source-content and worklist changes (`restraint_content_access`, copied-note concealment as null, filtered reference search, alert journey state) and Codex's Clinical dashboard restraint lens (source-guarded `href`, current staff/Site recheck inside one read). Main's lens is unchanged and per request is fresh; it doesn't recheck a warmed user object.
8. **H&S officers read incidents** (25 Oct ~08:55): `health_safety_officer` gets `incidents.viewAny` (grant migration `2026_10_25_090000_grant_health_safety_officer_incident_read` + RbacSeeder). It's still limited by the incident Site rule (the role's existing `healthSafety.viewAllSites` decides Sites, as for its other H&S work) and the privacy rule (HR-confidential incidents still need `hr.cases.view`). Covered by `HealthSafetyOfficerIncidentReadTest`. The .com demo H&S officers already have current HR profiles, so no demo data step is needed.
- **Calendar PPE dates now need PPE access** (privacy tightening): the Site calendar listed PPE inspection/expiry dates for every requested Site without checking PPE access (main does the same today). They now come through Codex's PPE source read: PPE view access and the viewer's current Sites; no access shows no PPE items.
- **Worker-calendar timezone**: an empty worker timezone falls back to the app timezone, then UTC, across the H&S and incident read paths.
- **Overview client-name tests count only the H&S read** (`HsDashboardClientOptionsSourceReadTest`): Codex's collector counted every client name loaded in the request, so main's sidebar loads failed it: the portal user's own client list, and the task and meds badges for support workers. The collector now keeps loads from the shared sidebar props apart. Anything loaded by the option owner or the Overview controller still fails the test. No app code changed.
  - **Follow-up for Stephan (main behaviour, unchanged here):** the sidebar's portal client list doesn't apply Codex's portal rule that someone with an HR profile (even archived) isn't treated as a portal member. A former staff member who is also a family contact still sees their linked client's name in the sidebar.
- **Incident-linked H&S events were hidden and locked (fixed 25 Oct, `0e355ce90`)**: Codex's source scope refuses every incident-linked event unless the read runs inside the H&S read context. The two registers and every event command ran without it, so on demo data the admin saw 6 of 52 events and 1 of 6 actions. Close, closure exceptions, handover, WorkSafe, investigation, corrective-action and evidence commands all answered 404. Codex's controller is the same, and his tests for these paths were never run.
  - Both registers now read inside one context, with a short retry if the read's no-wait locks meet a save in progress.
  - Commands check the event through the event page's read and hold the event row for update first, so a double submit waits instead of deadlocking.
  - Corrective-action owners must be able to read the event, through the same read.
  - Rules unchanged: incident read and current staff are still required. Covered by `HsIncidentLinkedEventCommandsTest`.
- **Decision for Stephan (asked 25 Oct ~11:00): team leads and incident-linked H&S events.** On the .com, 22 team leads have `hazards.manage` but no incident read. Under Codex's rule they lose every incident-linked H&S event; on main they see them, with the incident link shown only to incident readers. Codex's tests expect main's behaviour. The options:
  - (A) a grant migration giving `team_lead` incident read, like decision 8;
  - (B) build the restricted view, where the event is visible and actionable without the incident link or narrative (recommended);
  - (C) keep Codex's rule.

  Under A or C, handover owners should also be limited to people who can read the event. `HsHandoverAcceptanceTest` waits on this answer.
- **Follow-up (performance)**:
  - The sidebar task badge recomputes in about 6 s cold for the demo admin (7,700 queries; it's cached for 60 s).
  - The H&S incident scope adds one SQL block per incident-linked event (about 4.7 KB each; the demo register query is about 218 KB). That's fine at demo scale but grows with the number of linked events.
- **PPE and chemical file checks** (`32936eb6b`): MySQL's json column hands back `{size, digest}`, so every fresh PPE upload was marked "repair needed" and its download answered 404. Byte proofs now compare by key, still strict on values and types.
- **Unapplied Codex extraction (same as main): the dashboard safeguarding count.** `HealthSafetyDashboardSafeguardingReadTest` expects the overview to withhold the open-safeguarding count from H&S viewers without `safeguarding.viewAny`, and to add a `safeguarding_source` link. Neither Codex's snapshot nor main has that code. The count stays as on main (open concerns at the viewer's H&S Sites). Withholding it is a privacy follow-up for Stephan, since it would hide the tile from H&S officers.
- **Client profile H&S props** (Codex's two hunks ported into main's `ClientController`): the H&S summary is read as the signed-in viewer, so incident- and treatment-linked events are counted. The H&S risk list is read once inside one H&S read and lists only risks the viewer may read. Before, one risk copied from another client's private treatment made the whole client profile answer 404.
  - **Follow-up (privacy, not ported):** Codex also narrowed the profile's recent-incidents list (`readableRecentIncidents`) and First Aid list (`FirstAidSourceProjectionService::forClient`). Both stay as on main.
- **Privacy follow-up for Stephan (existing main behaviour, found by helper C):** in main's Control Room alert search, someone without `restraints.view` can type a restraint's H&S event reference and still get the linked alert back. The alert stays visible as an operational response by design; what leaks is the link between that reference and the alert. Codex's Control Room fix (search intersected with the source read) is not ported, so `RestraintDerivedWorkspacePrivacyTest`'s filtered-search case fails the same way main behaves.
- **Restraint tests that need Codex's Control Room and Clinical changes (not ported):** 6 cases in `RestraintDerivedWorkspacePrivacyTest` and 3 in `RestraintDerivedSourcePrivacyTest`. In each, the restraint content stays hidden or the page shows main's wording; none discloses restraint content. They go on the list of tests for code this build doesn't include.
