CURRENT RETURN REVIEW — 13 September 2026: [Independent audit](astra-verification.md) records **Not ready** and GOV-R01–GOV-R17. Read the [user-approved single-home/meeting-workspace decision](navigation-workflow-decision-2026-09-13.md) and [current Gemini fresh-context prompt](gemini-fresh-context-prompt.md). All GOV-W01–W24 / GOV-A01–A28 remain in scope. Older completion claims and navigation descriptions are historical where superseded by these current decisions.

# Governance implementation plan

Current correction order and evidence: [independent review](astra-progress-review-2026-09-12.md). Resume with [Gemini correction prompt](gemini-correction-prompt.md) before moving past the failed foundational acceptance. The original complete required scope and external D1/D2/D3 boundaries remain.

Status: specification retained; implementation partial and acceptance reopened by Astra on 12 September 2026. Current statuses: implementation-progress.md and acceptance-checklist.md. Repository: C:\Users\steph\Herd\oblivionfindings. Baseline and evidence limits: audit.md.

## Target outcome and scope
A member enters Board overview, sees their next authorised meeting and its current published pack, understands the main exceptions and changes, and can reach Vote, Read or Act without knowing an internal module. Secretariat/executive preparation is a distinct permission-appropriate queue. Keep useful underlying services and canonical records.

Required inventory and dependency order:
- Foundation and integrity: GOV-W01 reproducible verification, W02 record audiences, W03 board/committee rules and eligibility, W04 voting, W05 minutes, W06 packs.
- Truthful member experience: W07 obligations/full totals, W08 availability/provenance, W09 overview/navigation, W10 My work, W11 shared Sites calendar, W12 meeting workspace, W13 decision papers.
- Follow-through and oversight: W14 actions, W15 finance, W16 risk/compliance, W17 strategy, W18 executive contribution/performance, W19 policies/documents, W20 membership/interests/evaluations, W21 assurance/reporting.
- Conformance and release: W22 remaining design surfaces, W23 settings/reminders/help, W24 integrated verification.
Work in this order; W13 can follow W04/W06 without waiting on W12 if necessary, but no task may be skipped. Dependencies in implementation-tasks.md are authoritative. W01 begins immediately. GOV-W24 checks every GOV-A01–GOV-A28.
All W tasks are Required for this implementation, including P2. Deferred/excluded list in audit.md is binding; do not turn optional ideas into hidden scope.

## Decisions already made
D1/D2/D3 are specified with sources in evidence/governance-practice-research.md. They are the implementer's defaults. Live voting/approvals require recorded governing-document authority; unknown legal form is not solved by calling 50% “industry standard”. No placeholder constitutional values may activate live decisions. Build the rule profile, validation and synthetic test flow now; leave activation as an explicit external gate.
Use existing board role plus explicit voting appointment/committee membership. No invented treasurer super-role. The finance committee has finance oversight according to delegation; it does not gain all-site spend access or HR access by title.
Full-board private sessions and small restricted committees are distinct audiences; privacy does not mean always chair-only. Restrict raw CEO appraisal, provide the subject's own contribution and released assessment, and retain appropriate full-board decision summaries.
The owner's later explicit instruction: **reuse Sites calendar components and look/feel in Governance**. Shared calendar refactoring is in scope only as needed for reuse and must retain Sites behaviour. SiteCalendar.feedUrl is an ICS subscription URL, not a JSON data endpoint.

## Canonical ownership
- Governance owns meetings, papers/resolutions, board votes, meeting minutes, board-pack versions, board follow-up and governance approval records.
- Finance owns actual transactions, budget actuals, site variance and relevant reconciliation. Governance approves its annual budget/allocations and consumes Finance values. Refresh is a read; synchronisation remains the existing Finance job/authorised command.
- SpendApprovalCommandService remains the authority for spend transitions, canonical linked subject/amount, sites, expected version, idempotency and receipts.
- RiskRegisterEntry and ComplianceObligation own their records/evidence/recurrence. The general operational Compliance Centre retains its records; expose authorised summaries/links, not copied obligations.
- StrategicPlan/Goal describe board intent and approved goals. Existing Roadmap delivery records remain the execution source; linking a goal is not creating a parallel project tracker.
- Clinical, safeguarding, incident, HR, IT, Fleet and Te Tiriti owners retain detail records and site/privacy rules. Governance receives proportionate assurance and explicit source coverage; it does not inherit permission to open all source records.
- Shared Sites calendar owns calendar presentation/interaction. Governance adapter projects canonical records into CalendarItem; never copy Governance meetings into manual SiteCalendar events just to render them.

## Shared design and interaction contract (applies to every task)
Read the current protected guides before implementation: DESIGN.md; PAGE_HEADER_STYLE_GUIDE §§2,4–8; NAVIGATION_STYLE_GUIDE Rules 1–2; LIST_STYLE_GUIDE §§1–4; POPUP_STYLE_GUIDE “Entity wizard dialogs”, “File layout convention”, “Locked context”, “Confirmation dialogs”, “Accessibility”, “Permissions”, “Inertia integration”; APP_SHELL_STYLE_GUIDE; DESIGN_TOKENS; BUTTON_STYLE_GUIDE; LOADER_STYLE_GUIDE. This plan instantiates those rules, it does not override them.
Use AppLayout with Home → Governance → named collection → named record crumbs. Shell owns the one 20px gutter. PageLayout uses padding none and gap5; do not nest another p5 gutter.
Verified shared imports/APIs:
- resources/js/components/page/page-header.tsx: PageHeader({variant:'index'|'profile',icon,mark,backHref,title:string,wrapTitle,titleChip,subline,actions,meters,filters,rail}). Header has scoped search and one primary action; meters are real scoped links, omitted if not meaningful. No fake zero/delta/sparkline.
- PageHeaderRail({items:[{key,label,icon?,count?,alert?}],value,onSelect,onFind?,ariaLabel?}) supplies connected tabs and Find. Counts are full authorised view counts.
- resources/js/components/page/grouped-profile-nav.tsx: TierTwoTabs({tabs,activeTab,onTab,renderLink,testIdPrefix,ariaLabel,panelId}); use only record subnavigation where needed, under the header, following guide tone assignment.
- Shared EntityTable accepts rows,rowKey,identity(row),identityLabel/Width,columns({key,label,width,align?,cell}),actionsFor,hrefFor/onOpen and context-menu integration. Reuse the actual export discovered in repository, not invented prop names. Primary action must remain visible without a context menu.
- resources/js/components/wizard/shell.tsx: WizardShell({open,onClose,title,description,railIcon,railTitle,railSub,steps:[{key,label,blurb,icon}],stepIndex,onStepClick,footerStart,footerEnd,success,children,maxWidth?,maxHeight?}); WizardStepPane/WizardSuccessPane and review primitives. Caller enforces step validation; onStepClick must not bypass it. Add/edit dialogs co-located in _dialogs.tsx with reusable body. Direct create/edit URLs open the same dialog on a stable collection/detail background; cancel returns to opener.
- Use existing Button, StatusBadge, InputError, LoadingState and entity-menu primitives. No status raw strings, custom token palette or new decorative readiness bars on entity rows. Check actual exports before use; mismatch is a compatibility fix, not permission to redesign.
All dates use existing application timezone/date helpers. Timed events serialize ISO timestamps with offset/UTC; date-only due dates stay date-only. Display “15 Sep 2026, 10:00 am NZST” when timezone matters, not raw ISO or ambiguous US dates. Do not infer audit fixture's intended local time from a naive timestamp.
All consequential submissions disable only while pending, carry expected_version/idempotency where specified and receive durable server receipts. Inline errors retain values. On stale conflict show “This record changed. Review the latest version.” with Reload latest and keep a recoverable local draft; never silent overwrite. Permission changes conceal protected content, invalidate cached projections and give an appropriate return route.
State set for all screens: initial loading; successful empty (scope explained); filtered empty (Clear filters); failed (Retry + safe diagnostic reference); stale last-good (timestamp + Retry); denied (no private details); blocked (reason + responsible role/next step); saved/success receipt. Do not reuse empty for unavailable.
Keyboard: visible focus, labelled fields, roving tab behaviour from shared components, first invalid field, dialog focus trap/return and Escape dirty guard. At 1366×768 and 1920×1080, light/dark, 200% browser zoom and reduced motion: no page horizontal overflow; only approved table containers scroll horizontally. No mobile work.

The retained page-by-page columns, filters, actions and field parity specification is in [surface specification](evidence/surface-specification.md). It is required input for each owning task and GOV-W22.

## Annotated desktop layouts
These are content-order specifications using existing components, not new visual designs.

### L1 — Board overview, /governance/dashboard
PageHeader title “Board overview”; subline reporting period and last successful source capture; search navigates authorised Governance records; primary “My work” for member (live count), “Prepare meeting” for assigned secretary/chair if one exists. Header filters: reporting period and authorised committee; rail: Overview, My work, Calendar, Decisions (real routes/views). Optional meters: My pending work, Overdue board actions, Risks above appetite, Obligations overdue; each full-scope and navigable.
First body row: next authorised meeting (title/date/location/RSVP/current pack version/Read pack/Open meeting) and “Needs my attention” (up to five Vote/Read/Act items with total/view-all). At 1366 these two areas remain first; fewer priority items are preferable to hiding the meeting.
Next: “Board priorities” table with concern, why it matters, change/period, accountable owner, due date and visible action. At most eight preview rows; View all opens a matching scoped view, never the unrelated actions register. No repeated summary-card band.
Next: “What changed” (up to five verified events/deltas with source/baseline); no baseline means explicit unavailable comparison. Then compact assurance groups: quality/rights/equity, finance, risk/compliance, strategy. Detailed operational context can expand on request. Registers live in navigation/Find, not another tile wall.
Do not include a bespoke mini-calendar. “Calendar” opens L5, reusing Sites.

### L2 — My work, proposed /governance/my-work
PageHeader title “My work”; subline “Your board decisions, reading and follow-up”; scoped search; meters/counts for Vote, Read, Act, Know; rail All/Vote/Read/Act/Know with URL-backed selection. Filters Due, Status, Committee. Default pending actionable items sorted overdue, deadline, severity, deterministic ID; Know is awareness without false completion obligation.
EntityTable: identity plain-language work title and source/reference; Kind; Due; Status/blocker; Owner (“You” derived from ID); visible primary action. Pagination 25 rows, counts full filtered scope. Read pack uses exact immutable version. Completed view shows receipts with time, source and Return to work. Distinguish “Nothing pending for you” from “No results for these filters” and unavailable source.
No fallback to other members' obligations. Administrators may inspect another assignee through the permitted register, never by changing the current-user feed parameter.

### L3 — Meeting workspace
PageHeader profile title actual meeting; status chip; subline date/time/location/chair; primary changes by ability/state: member “Read pack”/“Respond to invitation”, secretary “Prepare pack”, chair “Review minutes”. Secondary Edit/Cancel only when allowed. Meters show actual invited responses, pack version/read count, decision count and open follow-up.
Connected rail: Prepare, Agenda, Decisions, Minutes, Follow-up, Workflow. Attendance is a Prepare/Run section for permitted recorder; optional sub-tabs use TierTwoTabs. Preserve old ?tab= links by mapping to new sections.
Prepare: personal RSVP (Attending/Apologies/Unsure; optional note), current published pack + acknowledgement, decision papers, interests reminder. Secretariat checklist lists owner/requirements/blocked reason; information-only meeting may explicitly mark a requirement not applicable with reason.
Workflow: phases Prepare → Meet → Record → Follow through; each transition describes current state, owner, evidence and allowed next action. Do not send an ordinary member to Record attendance. “Awaiting secretary” has a visible contact/help path. Quorum is per eligible roster/item, not decorative completion percent.
Minutes: version history, read-only approved/signed version, clear Submit for review / Approve minutes / Sign approved version / Archive controls as individually permitted. Corrections create new linked versions; no editing a signed version in place.

### L4 — Decision detail
PageHeader profile actual title/reference; Open/Closed/Implemented state and outcome separately; deadline with timezone and rule label; primary “Review and vote” only for eligible member. Rail Paper, Decision record, Follow-up.
Paper order: **Decision requested** (exact motion), Why now/context, Options (including do nothing where material), Recommendation with rationale, Consequences (financial, service-user/safety, risk, equity/Te Tiriti where applicable), Supporting evidence, Vote action. No voting before the member can reach these sections.
Vote section: eligibility/block reason, For/Against/Abstain with plain explanation, separate Declare a conflict (not auto-abstain), final confirmation naming motion/version/vote/consequence. On success show “Your vote: For · recorded [time]”; decision remains open until closed. Closed: authoritative frozen electorate/rules/quorum/tally/outcome and responsible follow-up; no fresh membership recalculation.
Draft create/edit Wizard: Motion → Options and recommendation → Implications and evidence → Voting and follow-up → Review. Drafts can be incomplete; Open voting validates publication requirements with field links.

### L5 — Governance calendar (required Sites reuse)
Use the same SiteCalendar Month/Week/Day/Agenda/Timeline rendering, date navigation/jump, density/source controls, event preview and keyboard affordances. Extend an optional adapter; do not fork the grid/toolbar. Governance meeting/compliance routes select appropriate source filter on the same workspace. Remove GovernanceCalendarRail's bespoke grid; overview uses a short upcoming-work list or Calendar link.
The current SiteCalendar uses context page/profile, scope global/site and hard-coded JSON URLs. Add optional data adapter and header/creation/open callbacks (proposed APIs detailed W11); preserve default Sites behaviour. Governance data source returns authorised CalendarItem records, distinct stable IDs, actual timezone/all-day semantics and canonical links. No ICS subscription for restricted Governance by default, no calendar event duplication, no dragging a compliance due date as if it were an ordinary event.

## Common backend/frontend contracts
All list DTOs must serialise as JSON arrays after authorisation filtering. GOV-F26 demonstrated a sparse timeline becoming an object and crashing the entire overview after pack activity. GOV-W08 normalises list indexes and validates payloads; GOV-W09 preserves local error containment during recomposition. Do not remove the access filter or manufacture a healthy empty result to avoid the error.
Proposed GovernanceWorkItem (W07): id stable string domain:record:obligation; kind vote/read/act/know; source {type,id,reference,href}; title; reason; priority; status; due_at nullable ISO; due_date nullable date-only; assignee_user_id; board_member_id when relevant; required_action {key,label,href,allowed,blocked_reason}; source_version; available_as_of. Do not place private strings in blocked/denied placeholders.
Feed response: items, totals per kind/state, pagination, scope {viewer,committee,filters}, availability per included source, generated_at. Derive authorised full totals before limiting visible rows. No client filtering of an all-board private payload.
Metric response: value nullable; unit; scope; period {start,end,timezone}; denominator/definition; source; observed_at; last_success_at; state available/empty/stale/unavailable/not_configured; reason_code; authorised drilldown. Health status is separate from transport availability.
Commands reuse existing routes where practical. Successful JSON/inertia flashes must identify resulting record version/state and next action; validation 422, denied 403/404 without detail, stale/closed conflict 409, async accepted 202 with job/status handle. New route names/DTOs in tasks are explicitly proposed, not assertions that they already exist.
Permission-scoped caches must include/invalidate permission and audience changes; never retain an old private payload because its 300-second cache remains valid.

## Migration and compatibility decisions
Only targeted additions: versioned voting profile/electorate snapshot; explicit restricted audience links; optimistic versions where absent; immutable pack revision lineage/read receipt uniqueness; immutable minute version content/approval identity; compliance recurrence lineage/unique cycle; optional document visibility metadata and decision paper implication fields absent from existing schema. First inspect existing columns/indexes; do not duplicate fields already present.
Keep legacy tenant_id/organization_id fields and unrelated schema. Backfill without invented signatures, votes, compliance evidence or constitutional approvals. Legacy signed minutes missing signer are marked “Legacy attribution unavailable”, not assigned to current chair. Existing closed resolution snapshots stay unchanged. Existing packs become legacy revision 1 with known hash/recipient facts; do not claim an unknown historical audience. Legacy restricted records default to review-required scope.
Require migration dry run on disposable MySQL, rollback/forward compatibility assessment and reconciliation counts. No destructive live migration/seed in this handoff.

## Verification and command catalogue
Commands are PowerShell, run from repository root. Audit-confirmed PHP executable:
`& 'C:\Users\steph\.config\herd\bin\php84\php.exe' vendor/bin/pest tests/Feature/Governance tests/Unit/Governance --compact`.
The repository uses a per-process disposable MySQL database from Tests/TestCase; inspect ownership/cleanup before running. The audit script evidence/audit-runtime.php is a one-off diagnostic harness, not the final e2e fixture. Never run its old state blindly.
Verified package scripts: `npm.cmd run types`, `npm.cmd run build`, `npm.cmd run test -- [explicit paths]`. Resolve npm/php on the current machine; installed node path in audit was C:\Users\steph\.hermes\node. These script definitions are verified; type/build/frontend checks were not executed during this audit.
Prefer local binaries `node.exe node_modules/vitest/vitest.mjs run <test files>` and `node.exe node_modules/eslint/bin/eslint.js <changed files> --max-warnings=0`; do not run npm lint for audit because it fixes files. Angle-bracket test/file notation here is command syntax guidance, not a claimed runnable named test. Each task lists exact existing suite command and explicitly proposed new test name.
Existing `npm.cmd run visual:test` uses playwright.config.ts with broad global setup and seeds; do not run against live/Herd data. W01 must create an isolated Governance browser config/fixture before W24 uses it. No automatic screenshot-baseline acceptance. Browser tools are the implementer's available tools, not a Codex dependency.
Run targeted tests after each consequential slice and the full Governance suite at final integration; rerun shared Sites calendar behaviour for W11, Finance/spend for W15, source-authorisation for W02/W21. Do not weaken assertions to obtain a pass; classify baseline numeric serialization carefully.

## Release gates and human validation
Engineering completion requires all W tasks implemented and every executable A check independently evidenced. D1 activation/actual appointments/service-content validation and representative-human sessions remain visible external gates until actually satisfied.
Recruit 3–5 representative board users including an infrequent/less confident member, chair/secretary and finance/committee member; use synthetic scenarios without coaching. Record unassisted completion, errors/backtracking, elapsed time and teach-back. Benchmarks: 30 seconds to identify meeting/concern/personal work; two minutes to find pack/awaiting decision; explain alternatives/consequences/conflict before voting; explain receipt/outcome/next owner afterwards. If missed, refine affected task and retest. Agent browser success does not pass GOV-A28.
No deployment, publishing, real mail, production votes or destructive data actions. Final implementation report must distinguish implemented from verified and include remaining gates. The subsequent Astra session is an independent audit only.

