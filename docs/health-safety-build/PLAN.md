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
