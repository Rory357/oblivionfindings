# PKG-01 v2 — modal and content revision

Owner: DESIGNER ASTRA. Updated 2026-09-19 11:43 UTC. Design-only revision requested directly by Stephan; not approval to implement.

## Request and outcome

Stephan asked whether v1 was feature complete, requested consistent modals throughout and better UI below the tabs, and asked whether the task numbers would appear in All Tasks.

v2 preserves the bounded maintenance journey and changes its presentation:

- Report, check, work-action and source-viewer dialogs now use the actual shared `WizardShell`, the same 1100px maximum width, 800px body-height cap, scroll region, header, rail and footer rhythm. Work-action forms follow **Details → Review → record**, using the shared ReviewCard/ReviewRow. Compact discard confirmations remain the appropriate confirmation tier with consistent styling.
- A preview-only `RecordDialog` adapter supplies the shared frame to existing form/viewer content. Work forms protect dirty entries on Escape/Cancel/close. Review receives keyboard focus; failed validation returns to the details and focuses the field. Dates and other entries persist across Review/Back.
- Below the tabs, a compact navigation strip leads into a named section with a short purpose statement. Work overview gives the problem/source a clear summary, places the next action/owner/target alongside it, separates responsibility, and groups appointment, repair evidence and completion into a visible sequence. Release requirements remain distinct. Evidence/release/booking/Finance/history share consistent section headers and content spacing.
- The queue keeps the canonical EntityTable/Card and its row contract. Work-order references are easier to distinguish and active holds have an explicit icon/text label alongside work status. No replacement table or KPI system was introduced.
- The unexplained synthetic `TASK-0412` is removed from v2. A bounded **WO-0264 in All Tasks** viewer shows the same work reference, owner, target and proposed next action, with a return to Maintenance and visibility caveats. It is a synthetic source projection, not a new All Tasks module or duplicate task record.

## Feature-completeness answer

This is **not feature-complete application behavior**. The principal WO-0264 report/check/work/release journey is interactive with synthetic state and failure cases. Other queue rows have bounded summaries. Shell navigation, integration persistence, durable drafts, full data selection/search, all record combinations, and real permissions/transactions are not implemented by this mockup. A feature inventory or screenshot does not certify a backend.

The four unresolved operational-policy groups from [v1 contracts](../contracts-and-scope-v1.md) remain: approved check/restriction rules, release/custody authority, accountable response/routing, and Finance evidence/approval/posting. The normal release stays blocked. The hypothetical eligible fixture grants no real authority.

## All Tasks — verified existing source versus proposed design

Read-only source validation at e62b569 confirms:

- [FleetMaintenanceProvider](../../../../../app/Services/Tasks/Providers/FleetMaintenanceProvider.php) uses `fleet_work_order-{id}` as the internal source identity, `reference_number` as the visible reference (lines 111 and 114), the source assignee, due date and canonical maintenance link. It does not create a second Task record for the same work order.
- [TaskAggregator](../../../../../app/Services/Tasks/TaskAggregator.php) registers the provider at line 86. [All Tasks index](../../../../../resources/js/pages/tasks/index.tsx) renders `item.ref` at line 874; its detail dialog renders the same reference.
- Current eligibility requires a due date that is overdue or within seven days. Undated work is excluded. Completed/cancelled work is excluded by default, and source permission, approved-site/asset access and selected task filters still apply. The provider also has a bounded query limit. This is not a promise that every maintenance record is always visible in every task view.

**Answer to Stephan:** yes, eligible maintenance work orders use their same work-order number in All Tasks; no second task number is needed. The v2 All Tasks view illustrates that relationship without contacting the real application. The richer next-action projection remains part of the proposed implementation contract; this revision does not claim it has been wired into the current All Tasks feed.

## Exact artifact and unchanged scope

- **PKG-01 desktop mockup v2**, frozen 2026-09-19T11:42:17.119Z.
- URL: http://127.0.0.1:4318/.
- Composite SHA-256: `dd0686d6d0aa519c978ee999443c8a340ecb7d768cd451640869c1a594d0753e`.
- [Manifest](artifact-manifest-v2.json): ten executable/source files and 18 native JPEG captures. README/evidence prose are outside the executable identity. Filenames describe CSS test viewports; native capture raster dimensions can differ around scrollbars/full-page captures.
- Existing v1's seven files and 24 screenshots were rehashed against its frozen manifest with zero differences. Its original URL and files remain available.
- Actual continuation: 2026-09-19T11:25:13.83Z, turn `01a0b969-cc74-71f0-8b4d-e6578e68afce`, same Designer task, `gpt-6-astra / xhigh` in both recorded effort fields, verified before writes.
- Main was informed of Stephan's direct design request. Main recorded it in global register revision 8 and review revision 4. No approval, scope/master amendment, application-code writer or Implementer was inferred.
- Same detached baseline `e62b569ff42ab471300fb6713a68758b647b2c32`. Application and reference guides unchanged. Only isolated preview/evidence/package-record additions/edits. Existing runtime/dependencies reused; no installation, migration, commit, push or deployment.

## Observed QA of changed surfaces

- Final isolated esbuild/Tailwind build succeeded using the existing dependency installation. Formatter applied to preview sources only, without repository plugins. No application build/typecheck is claimed.
- At 1440×1000, All Tasks viewer, original check viewer and report wizard all measured 1100×802 including their outer border, confirming the common frame. Action forms use that same frame. Their content remains scroll-contained with visible footer actions.
- Missing action reason: Review → Record returns to Details, shows the required inline error and focuses `action-reason`. Review itself focuses its heading. No hidden validation message remained in a non-visible step.
- Triage next action and target survived Review/Back and unsaved-change Keep editing. Escape prompted for discard. Successful triage changed the source next action shown in the All Tasks viewer.
- Handover acknowledgement changed the accountable owner to Priya Shah; the All Tasks viewer showed the same owner. No notification or provider message was sent.
- Completion review explicitly retained the hold and Finance separation. Recording completion left the hold active and Finance Awaiting approval.
- Failed retest save retained entered result/reason. Review → Retry same request succeeded once in the fixture; the retest remained Failed and release remained disabled.
- Default release remained blocked. The explicitly hypothetical eligible fixture required a reason/review and recorded a release while Finance remained Awaiting approval; both bookings still said Recheck required.
- Original CHK-0082 remained failed and displayed its exact sample questions, rules and correction history in the unified viewer.
- Report missing-asset focus and error association remain correct. Report draft close returned focus to Resume report. Missing check answer still returned focus to `check-tyres`, with an associated required-evidence error.
- At 1280×900, overview document width was 1280 (no page-wide overflow). Appointment fields retained a changed 10:00–11:30 window across Review/Back and saved it. The modal stayed inside the viewport with its footer visible. Larger overview and smaller appointment captures were visually inspected.
- View-only access hid triage and repair-completion actions. Shift+Tab remained inside the All Tasks modal; Escape returned focus to its exact opener. These are UI behavior checks, not real authorisation tests.
- Final browser warning/error query returned an empty array.

v1's unchanged behavior/evidence and limitations remain documented in [verification-v1.md](../verification-v1.md). v2 does not extend its acceptance claims. Browser zoom and rendered reduced-motion emulation remain unverified; no mobile, full screen-reader, complete contrast, cross-browser or production integration certification is claimed.

## Changed files

Only `previews/PKG-01/v2/` (preview source, three helper components, layout, build/server/HTML and generated JS/CSS, README), `evidence/PKG-01/v2/` (this record, manifest, screenshots), and the canonical package page. v1 remains immutable.

The three helpers compose existing UI primitives: `dialog-frame.tsx`, `content-primitives.tsx`, `work-overview.tsx`. They are isolated mockup adapters, not implementation scaffolding or approved application abstractions.

## Screenshot index

- [Queue](01-queue-1440.jpg), [full work overview](02-work-overview-1440.jpg), [overview first screen](18-overview-screen-1440.jpg).
- [Triage details](03-triage-dialog-1440.jpg), [action review](04-action-review-1440.jpg), [unsaved changes](05-unsaved-changes-1440.jpg).
- [Same reference in All Tasks](06-all-tasks-same-reference-1440.jpg), [original check viewer](07-immutable-check-dialog-1440.jpg), [evidence tab](08-evidence-tab-1440.jpg).
- [Blocked release](09-release-blocked-1440.jpg), [completion review](10-completion-review-1440.jpg), [retained save failure](11-retained-failure-1440.jpg), [hypothetical release review](14-release-review-dialog-1440.jpg).
- [Report wizard](12-report-dialog-1440.jpg), [check wizard](13-check-dialog-1440.jpg).
- [1280 queue](15-queue-1280.jpg), [1280 full overview](16-work-overview-1280.jpg), [1280 appointment](17-appointment-dialog-1280.jpg).

## Next gate

Return v2 for Main's design/scope review and Stephan's explicit exact mockup/scope approval. Same Designer remains open/pinned. No implementation selection, worker, app-code write or later-phase approval is authorised. The direct design-revision request does not consume an Implementer correction budget.
