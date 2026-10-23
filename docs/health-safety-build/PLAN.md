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
