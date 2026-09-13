# My Day desktop redesign review

Prepared 12 September 2026. Deliverable: an interactive design concept, not an application implementation. All names, times, tasks and counts in the concept are synthetic. Interactions change preview memory only.

## Evidence and limits

The real URL `https://oblivionfindings.test/my-day` redirected to login in the available browser session. Findings below come from the current checkout, not an authenticated observation of the populated live page. No application code, records or protected design guides were changed.

Read sources: `DESIGN.md`; Rory's page-header, shell, list, popup and button guides; `resources/js/pages/my-day/index.tsx`; its hero, work rail, stream rows and digest components; `app/Models/ShiftTask.php`; `app/Http/Controllers/ShiftTaskController.php`; the My Day actions and route definitions.

## Highest-value corrections

1. **Make creating work obvious.** `whats-next-rail.tsx` has no task-create prop or action. Its heading offers the care-plan link when scoped, followed by the work stream and a right-click tip. Put **Add task** in the page header and beside **What needs to happen today**, opening the same form. Keep the section action visible in empty and filtered states. Prefer a direct action over a generic Add menu.

2. **Use the current page contract.** `my-day-hero.tsx` imports the superseded `PageHero` and `PageTabs`. `index.tsx` supplies a custom `StaffHeader`, including global search/live/notification chrome, and omits a Home-rooted breadcrumb. Migrate to the existing `AppLayout` and `PageHeader`: ring identity, fact subline, scoped search, one white primary action, four meaningful clickable meters, real filters, connected view rail and Find. Keep the single 20px shell gutter and 20px section gaps. The right column currently uses `gap-4`.

3. **Show the action before interaction.** `stream-item.tsx` gates task and medication actions on hover. Use permanent **Open task** / **Open meds** controls. Retain both kebab and right-click access to the same permitted secondary actions. Keep source, person, due time and owner visible. Never replace a dose-recording workflow with a generic task checkbox.

4. **Make priorities explicit.** The current rail groups the stream by time, while the digest keeps alerts/follow-ups in a separate tab. Present urgent exceptions first, then one recommended next action, other due/overdue work, later timed work and **Any time today**. Completed work is expandable. A person filter must not silently conceal a critical site/shift alert. The concept demonstrates an ordinary shift with one due support task; it does not demonstrate every emergency state.

5. **Keep supporting information predictable.** Put shift controls, the latest handover and recording shortcuts in a compact right column. Use **Today**, **Handover** and **My shift** as the main views. Distinguish a future task from a record of care already provided. Keep incomplete/returned paperwork visible as an action when required.

## Quick-add behaviour

- Single-section form: **What needs to happen?**, **Who is it for?**, **When does it need doing?** The current shift, approved site and worker are prefilled. A person filter prefills the person. “Everyone” does not silently select a client; require a person or an explicit whole-site task.
- Timing: **Any time today**, **Now**, or **At a set time**. Explain the destination group before saving. Support overnight shifts using the existing worker timezone/date helpers; the concept's example shift is 7 am–3 pm.
- Use Rory's simple-dialog pattern for this bounded one-section quick task. If the form grows into an entity workflow or multiple sections, use `WizardShell` with review and success instead of expanding the quick form indefinitely.
- Keep values on validation/server failure. Prevent duplicate submission; insert one confirmed canonical task into its proper position. Show a clear save confirmation. “Keep draft” / “Discard draft” preserves interrupted work. Preview drafts are memory-only; durable recovery still needs implementation.
- A normal reversible support-task completion can offer undo through the existing safe mechanism. Clinical records, handover acknowledgement, and clock-out require their established outcome/correction rules.

## Backend work required

The inspected `ShiftTask` model stores shift, label, scheduled time, ordering and completion metadata. It has no per-task client, assignee, description or blocked-outcome fields. The inspected `ShiftTaskController` updates completion; existing task creation happens through shift/calendar services. No dedicated quick-create path was found in the inspected My Day routes.

Before implementation, settle the canonical record mapping and add a narrow worker-authorized creation operation. Reuse `ShiftTaskSupport` and existing task/workstream capabilities where their semantics fit. Derive the worker and assigned shift server-side, verify approved-site and client access, reject completed shifts, and record who created the task. Do not give workers broad shift-edit or manager permissions just to enable quick-add. Do not build a parallel task database or introduce tenant boundaries.

Per-person tasks, shared ownership and “I need help” must have real stored meaning before those controls ship. “Visible to staff with access to this shift” in the concept is a proposed visibility rule requiring implementation and authorization tests.

## Further improvements, in order

1. **I need help with this:** capture a reason and responsible recipient, keep work visible, and show whether help has actually been requested/accepted. Do not mark blocked care complete or silently postpone medication.
2. **Handover to follow-up:** create a linked task from a handover entry, prefill context, and expose an existing follow-up to prevent duplicates. Reading the handover and completing its task remain separate actions.
3. **Finish shift review:** reuse the existing end-of-shift checklist to review unfinished work, outgoing handover, breaks and time allocation. Provide an explicit supported handover/escalation outcome where appropriate.
4. **Interruption and connection recovery:** durable drafts, section-level loading/retry, clear last-updated status and stale-data messaging. Never show a successful save or healthy state when a source failed.
5. **Ordinary-worker usability check:** ask representative support workers to identify their next action, add a timed task, find a person's remaining work, ask for help and finish a shift without coaching.

## Preserve when implementing

Retain permission-dependent medication actions, active medication rounds, shift checklists, lone-worker check-ins, first-aid follow-ups, PPE acknowledgement, returned timesheets, availability, pending claims, next-shift briefing and other existing worker features. Put safety/time-critical exceptions into the priority area; put supporting detail in its appropriate view. The clean synthetic concept omits conditional examples, not the production capabilities.

Use the actual shared components and semantic tokens in production. The standalone concept visually represents their design; it does not import or replace the application component library. Make meter values match real sources and filters, including person-avatar deep links where the guide requires them.

## Verification performed

The concept was opened in the in-app desktop browser. Checked empty-title validation, creation of an anytime task, person filtering, opening task details, completion/undo, keeping/resuming a draft, creating a 2 pm task, and selecting the next shift. The final reload had no captured console warnings/errors and no overflowing controls at the browser's natural desktop width. The real authenticated page, production writes, backend permissions and every conditional clinical state were not tested. Mobile acceptance is outside the requested scope.
