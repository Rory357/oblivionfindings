# My Day: current desktop audit and calendar direction

Reviewed 13 September 2026. Scope: the current `/my-day` page, its Today / Handover / My shift tabs, and the proposed calendar treatment of “What needs to happen today”. This is an audit and design proposal; no application behaviour was changed for this review.

## Recommendation

Use a **shift agenda by default**, with an optional **Day** view of the same work. Keep the existing Event Horizon hero, Today / Handover / My shift navigation, and Your shift sidebar. Reuse the shared Site Calendar surfaces, date treatment, source styling and view primitives. Add an embedded worker configuration to those components where necessary; do not build a competing calendar skin.

The agenda should show, in order:

1. **Needs attention** across the whole shift, with named people and direct actions. This stays visible when filtering a person or moving through calendar hours.
2. **Up next** with a due time and person, including when nothing is overdue. Scheduled work follows on a clear time rail.
3. **Any time today**, including tasks with no scheduled time. Whole-site tasks remain explicitly labelled.
4. **Being followed up**, retaining the colleague and open state. Delegation must not look like completion.
5. **Completed / recorded**, collapsed by default with individual outcomes retained.

Keep **Add task** visible above the agenda and beside the anytime section. Add a labelled time-context action such as **Add at 10:30 am**. These open the same existing task workflow with person and time prefilled; the worker can still change them and add optional steps.

The hourly alternative is useful for seeing the shape of a busy shift. Start at the shift/current time rather than an empty midnight grid, and support shifts crossing midnight with full dates. A due time is a point, not a duration. Do not give a task a fabricated 45-minute block or allow dragging a medication to reschedule it. Real appointments may have duration blocks when that duration exists in their owning record.

Month, Week and broader planning remain available in My Calendar. My Day's embedded work view does not need a second full PageHeader or another five-tab navigation rail.

## Findings, ranked by impact

### 1. High — calendar presentation must not conceal differences in the work feeds

**Evidence:** My Day renders its task/dose stream through `day-work-list.tsx`. My Calendar's “Tasks” source is `AlertTask`, while shift tasks are carried inside a shift's `extendedProps`; its adapter turns timed tasks into description text. Medication rounds are given a one-hour end time in the controller. Alert tasks with a due time are emitted as all-day events.

**Risk:** copying the My Calendar feed would make some My Day work disappear from the schedule, obscure actual due times, and suggest durations that are not recorded task durations.

**Change:** adapt My Day's canonical, permission-filtered work stream into the shared calendar presentation. Preserve stable occurrence IDs, person/site scope, exact due times, steps, assistance state, source links and recorded outcomes. Reuse owning workflows for edits and medication recording. Include appointments only after their authoritative feed and worker visibility have been established.

References: `app/Http/Controllers/MyCalendarController.php:44`, `:66`, `:79`, `:130`; `resources/js/lib/my-calendar-adapter.ts:79`; `resources/js/pages/sites/calendar/_parts.tsx:548` (45-minute fallback for entries without an end).

### 2. High — shift-note edits are vulnerable to interruption before the final save

**Evidence:** the person-by-person editor tracks dirty state, warns before closing, and saves through the final **Save draft** action on Review notes. It explicitly displays “Changes are not saved yet.” Quick task entry already has server-backed draft saving.

**Risk:** the close guard helps with an intentional close, but unsaved notes can still be lost during a PC crash or browser termination. This is particularly relevant to the interruption described in this task.

**Change:** save a private draft as the worker types using the existing encrypted, versioned ownership boundary; display **Saving… / Saved at … / Not saved — retry** accurately. Never send the handover automatically. Keep **Review and send** explicit. Show progress per person as **Not started / Draft saved**, plus an explicit **Not supported this shift** option where appropriate, so a blank note is not ambiguous. Do not infer care was delivered from a tick or require invented notes.

Reference: `resources/js/pages/my-day/components/shift-notes-dialog.tsx:73`, `:119`, `:159`; `quick-add-task.tsx:80`.

### 3. High — calendar feed failures can look like a genuinely empty day

**Evidence:** My Day already displays unavailable information and a retry action. The calendar controller catches errors for medication rounds, leave and alert tasks and returns the remaining event array without source availability metadata.

**Risk:** the calendar can present zero items for an unavailable source. This review did not induce a failure; the finding is based on the controller's error paths.

**Change before reuse:** preserve per-source availability and last-success information. Say **Medication schedule unavailable — retry** rather than presenting zero as confirmed. A new embedded calendar must retain My Day's existing warning behaviour.

References: `app/Http/Controllers/MyCalendarController.php:104`, `:125`, `:158`; `resources/js/pages/my-day/index.tsx:675`.

### 4. Medium — “Do this next” does not show the next future task

**Evidence:** `next` is `groups.due[0]`; future work is listed separately in a table. Priority places due medication before other due tasks. That is a sorting rule, not a complete assessment of urgency. Non-completed past-time rows receive the generic “Due” label.

**Change:** distinguish **Needs attention**, **Due now**, and **Up next at 9:30 am**. Use **Up next** for schedule order, and explain the due time. Keep incidents and other urgent actions visible independently rather than suggesting that the first scheduled item overrides worker judgement. Use **Overdue** when the owning task/dose rules establish it; preserve medication-specific outcome and timing rules.

References: `resources/js/pages/my-day/components/day-work-list.tsx:47`, `:232`, `:286`; `resources/js/pages/my-day/lib/work-priority.ts:26`.

### 5. Medium — quick entry is now visible, but has no time-context entry point

**Evidence:** Add task exists in the hero and the work-section heading when the worker can create a task. QuickAddTask takes `initialPerson`, starts with `when: 'anytime'`, and has no initial-time prop.

**Change:** add visible **Add at [time]** and **Add anytime task** entry points. Prefill the selected person and actual shift date/time; do not require double-clicking or right-clicking an empty slot. Respect an existing saved draft rather than overwriting it with the new slot. On creation, reveal the new task with **Task added**, retained steps and an edit action. Keep the single canonical creation flow.

References: `resources/js/pages/my-day/components/quick-add-task.tsx:43`, `:54`, `:84`; `day-work-list.tsx:181`.

### 6. Medium — the no-roster view needs a direct next action in the main panel

**Observed:** Demo Admin is clocked in but has no current rostered shift. The main panel explains personal scope but directs the worker to another tab. View my roster exists in the sidebar. An empty work list in this session does not mean an administrator should see everyone's care work.

**Change:** put **View my roster** directly in the empty panel. Where the existing workflow supports it, add **My shift is missing** leading to the appropriate roster-help process. Use **No rostered work to show** for relevant meter captions in this state; avoid “No tasks added” implying that someone should populate a shift that does not exist. Keep attendance status separate from roster status.

References: `resources/js/pages/my-day/components/day-work-list.tsx:211`; `shift-summary.tsx:63`; `my-day-header.tsx:146`.

### 7. Medium — decimal hours are harder for a worker to interpret

**Observed:** My shift currently shows **10.1 paid hours**. The panel also repeats Review my timesheet as a tile, a sidebar action and a row action.

**Change:** display **10 hr 6 min** for that decimal value, calculating from authoritative paid minutes where available rather than rounding an already-rounded display value. Keep payroll decimal detail in the review if needed. Show the date, people supported, and status beside the duration. Make the prominent tile today's summary/action; keep older/returned rows for the actual outstanding records so repeated actions are distinguishable.

Reference: `resources/js/pages/my-day/components/paperwork-panel.tsx:78`.

### 8. Medium — finishing notes and sending handover still span several surfaces

**Evidence:** the note editor saves a draft, then **Review and send** opens the operations handover URL. My shift separately offers time review and handover actions. These are useful functions, but do not give a single overview of what remains before leaving.

**Change:** add a compact **Before you finish** summary in My shift: notes by named person, unresolved work assigned/followed up, handover draft/sent status, and timesheet review status. Link directly to each existing action and return to the same My Day context. Keep unpaid/unpaid-break and time recording rules intact; do not make paperwork a new barrier to recording an actual clock-out.

References: `resources/js/pages/my-day/index.tsx:803`, `:830`; `shift-notes-dialog.tsx:175`.

## What is already working and should be retained

- The current local page now shows the agreed soft-depth buttons and more readable Your shift card; the screenshots from before those corrections are not the current visual state.
- Separate named-person notes, Whole site notes, explicit draft versus sent status, and shared WizardShell treatment are already present in the code.
- The main task heading has an Add task action when authorised. Optional subtasks and saved task drafts already exist.
- Urgent information remains visible above person-filtered work. Delegated tasks remain open until an outcome is recorded. Completed/recorded work is collapsible.
- My Day distinguishes clocked-in attendance from a rostered shift and includes an information-unavailable warning.

## Design rules and validation boundary

Follow `DESIGN.md` and `design_styles/CALENDAR_STYLE_GUIDE.md`, including the shared Site Calendar presentation and clear full date. The proposal changes the embedded work panel, retaining My Day's existing hero and shell. Desktop is the acceptance target requested by the user; do not introduce a separate mobile redesign.

This audit inspected the current `.test` page as Demo Admin, all three My Day tabs, My Calendar's Day view, the supplied screenshot and the relevant source paths. The live account has no rostered shift, so populated and interrupted worker states above are source-reviewed findings and proposed behaviours, not newly exercised real-care journeys. No clinical records, attendance, task outcomes, handovers or timesheets were changed. The comparison uses illustrative data and local-only prototype interactions.

The comparison was browser-checked at its 736px panel width: Agenda/Day switching, named-person filtering with the shift alert retained, opening and ticking subtasks, person/time prefill, adding a task, and opening two tasks due at the same time. This validates the proposal's interactions, not the application implementation.

Before implementation is accepted, verify a staffed three-person shift, quiet and busy shifts, an overnight shift, filtered urgent work, duplicate same-time items, interrupted note drafts, expired permissions, unavailable feeds and keyboard use. The optional Day and default agenda must show the same underlying work, and medication actions must stay in their owning workflow.
