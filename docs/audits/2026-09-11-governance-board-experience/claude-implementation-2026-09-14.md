# Governance implementation pass — 14 September 2026 (Claude Code)

Implements the remaining findings of `astra-post-push-audit-2026-09-13.md` (UI first, then controls) plus the owner's navigation decision below. Branch `claude/audit-governance-review-7681c3` (worktree), based on `728099a72`. **Status: Implemented (not yet verified in a signed-in browser).** Automated checks are green; representative-member comprehension (GOV-A28) and the D1/D2/D3 external authority gates remain open.

## Owner decision — admin navigation (14 September 2026)

Chairs/secretaries/managers had 26 Governance sidebar links. The owner chose **11 entries**: Home, My work, Calendar | Meetings, Decisions & actions | Risk & assurance, Finance, Strategy & performance, Policies & records | Board & members, Settings & audit. Sibling registers are the hub's connected-tab header rail; every register keeps its canonical URL. Ordinary members keep exactly Home, My work, Calendar, Records. Cross-module links (Operational Compliance) are removed; Roadmap has no other entry, so it is a Strategy & performance tab. Single source: `resources/js/lib/governance-sections.ts` + `components/governance/GovernanceSectionRail.tsx`; recorded as a DESIGN.md anti-pattern ("One sidebar link per register").

## Finding dispositions

| Finding | Result | Where |
|---|---|---|
| GOV-R12/R17 meeting 500 with resolution follow-up | Fixed: `assignedTo` relation; typed audience-filtered `action_items` (+ `restricted_action_items_count`); attachments without storage paths; Open/Update + Download controls; `?return=` back to the same paper (`focus=follow-ups`) | `GovernanceMeetingController`, `MeetingPaperWorkspace.tsx`, `meeting-workspace-links.ts`, `ActionItemController` |
| GOV-R03 different-body rules approval | Fixed: explicit immutable bindings (`governance_resolution_bindings`), fingerprinted, draft-only, verify+consume under row locks; board profile only by board resolution | `GovernanceResolutionAuthorityService`, `GovernanceVotingProfileService` |
| GOV-R08 strategic plan title match / budget direction change | Fixed with the same bindings (plan id+version fingerprint; adjustment line/amount/direction/reason fingerprint). Extended to whole budgets (auto-bound on propose) and CEO performance reviews (keyed digest, no restricted content stored) | `StrategicPlan`, `GovernanceNestedMutationService`, `Budget`, `PerformanceReview` |
| GOV-R06 pack ID prefix collision | Fixed: typed `board_pack_contained_sources` index shared by discovery and `canView`/download; backfill migration | `BoardPackAccessService`, `BoardPackContainedSources` |
| GOV-R10 Show all capped at 100 | Fixed: server pagination (`/governance/dashboard/data?section=priorities&tab=&page=`) + "Load N more" | `GovernanceWorkflowService`, `PriorityOverviewPanel.tsx` |
| GOV-R02/R10 readiness counts hidden agenda | Fixed: `ExecutiveMeetingAccessService::visibleAgendaItems` used by meeting page, checklist and presenter | same |
| GOV-R13/R17 Rory form/workflow contract | Implemented: all full-page Create/Edit pages replaced by prefilled `WizardShell` dialogs (Meetings, Resolutions, Risks, Compliance, Te Tiriti, Policies, Evaluations, Board members, Budgets, Strategy, Spend approvals, CEO performance, CEO reports); retired `/create` `/edit` URLs redirect to `?create=1` / `?edit=1`. Hub pages: rail, header filters, Home-rooted breadcrumbs, EntityTable, gap-5, StatusBadge/EmptyState. Member Home rebuilt (Next meeting + My work + labelled board priorities + truthful assurance; admin checklist managers-only). Votes from Home/My work open the paper inside its meeting workspace | `pages/Governance/**` |
| GOV-R01 red suites | Fixed: audit-reader fixture; budget fixtures use real bindings; Sites credential/vendor tests act as an authorised reader (stale since `979538640`); vendor feed test matches restricted contract renewals; `today-retirement.test.tsx` types | tests |

## Additional defects found and fixed during this pass

- Evaluation results sent every member's answers tied to their name to any `evaluations.view` holder → answers now identity-detached and shuffled; respondents listed separately; anonymous responses counted only.
- Policy wording rendered with `dangerouslySetInnerHTML` (stored XSS by a policy manager) → rendered as text.
- `GET /governance/policies/create` 404 (route order) → `whereNumber('policy')`.
- Meeting page sent all approved users' emails to every attendee → wizard options only to paper authors, no emails.
- Meeting create/edit stored NZ wall time as UTC (9am read back as 9pm) → converted in the wizard.
- Others reported by implementers: dead Risk/Compliance search, heatmap double counting, CEO report "Submit to board" dropped on create, never-visible "New risk"/"New review" buttons, Pending spend filter empty, Settings `hasPermissionTo()` fatal, evaluation Results crash, interest self-declaration 403, resolution wizard purposes rejected by the server, dashboard 500 when the compliance widget failed.

## Verification (14 September 2026)

- `vendor/bin/pest tests/Feature/Governance tests/Unit/Governance tests/Feature/Sites/Calendar --compact`: **465 passed, 3 failed (4,308 assertions)**; the 3 were fixed and their files re-run: `GovernanceMeetingPaperFollowUpTest` + `SiteCalendarObligationProvidersTest` 9/9 then 5/5 after the vendor-test correction. Net: 468/468.
- Vitest (Governance pages/components, governance-sections, sidebar, wizard, today-retirement): **19 files, 66 tests passed**.
- `npx tsc --noEmit`: exit 0. ESLint on all 104 changed TS/TSX files: clean. `npm run build`: success.
- Dusk specs updated to the new Home heading/`@next-meeting-prepare` (not executed here).

## Not verified / remaining

- **No signed-in browser journey was run** in this pass (the agent cannot enter credentials). Desktop 1366×768 / 1920×1080, light/dark, keyboard/focus, 200% zoom and reduced motion remain Not tested.
- The shared Sites calendar header owns its five-view rail, so `/governance/calendar`, meeting and compliance calendars show no hub/home rail (sidebar still highlights the hub).
- Budgets proposed before this change have no binding; re-propose or bind from the draft resolution.
- Action evidence can only reference already-managed storage (no evidence upload route).
- Budgets `Show.tsx` retains some legacy stat-card typography.
- D1/D2/D3 governing-document authority and GOV-A28 representative-member comprehension remain external gates.
