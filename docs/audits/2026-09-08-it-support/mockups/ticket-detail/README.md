# Ticket detail — interactive design study

Technician view for Oblivion Findings / Oblivion Care. Synthetic fixture only.
This directory is isolated from the production application and its build.

Open `index.html` directly, or run `node serve.cjs` from this directory and open
http://127.0.0.1:8793. The static server binds only to loopback. It has no write
endpoints. All assets are local; the page makes no application requests.

## Recommended layout

- Preserve the Event Horizon PageHeader: the primary colour’s exact shared ramp,
  circular identity mark, scoped section search, one white primary action,
  five linked meter blocks, working filters inside the band, connected rail,
  and the current shared tier-two navigation styling.
- Prioritise the latest exchange and the reply composer. Keep the original
  report and earlier exchanges in accessible disclosures. The full conversation
  remains available from the header’s History control.
- Use one supporting context panel for the next action, owner/team,
  requester, affected user, work contact details, priority and watchers. Disclose classification detail instead
  of repeating the title and multiple metadata cards beside every reply.
- Label public replies and internal notes at the mode control, editor audience,
  action button, message and file. Keep each audience’s draft and staged files
  independent when switching modes or sections.
- Keep Work, Details & links and Activity on the existing main rail. File
  evidence, approvals, clock evidence, related tickets and Knowledge remain
  easy to reach. Knowledge suggestions are not counted as linked records.
- Enter actual time in the note composer: start/end date and time, calculated
  duration, work type and an explicit after-hours checkbox. Save the note and
  its linked work entry together. Internal notes default to time logging;
  uncheck it for administrative notes with no worked time. Public replies can
  also carry a linked internal time record without exposing time to recipients.
- Keep planned technician visits separate from actual worked time. Search for
  additional technicians, check a proposed slot and review the work brief.

## Interactions you can try

1. Type a public reply, switch to Internal note, then visit Files and return.
   Both drafts remain separate, with a visible saved-in-this-tab reminder.
2. Open the original report and its synthetic printer image; open the internal
   port-check file from Files. Add/remove a sample attachment in a draft.
3. Insert a Knowledge reference into the active draft. Sending adds a clearly
   marked preview message, without sending a notification.
4. Edit waiting details or change the assigned owner. The context panel updates.
5. Open Resolve ticket from Ticket actions or Work. The unverified required task
   blocks resolution. Enter evidence and verify the last task to enable the
   outcome / verification / review wizard. Resolve, close with a reason, and
   reopen with a reason. Earlier evidence and resolution remain available.
6. Use Preview states for conversation loading, refresh failure, or a failed
   combined save with a retained draft and retry. Refresh the page to try recovery.
   Use Reset preview to clear the synthetic session and restore the fixture.
7. Use `/` to find a section; use arrow keys/Home/End on the tier-two tabs.
   Dialogs trap focus and Escape protects unsaved edits with a discard choice.
8. Choose Internal note or Log time. Enter the note once, add start/end times
   and mark after hours as appropriate. Save note & time creates both together.
   Open Work → Time entries to inspect totals, filter after-hours entries, edit
   an entry or open its linked note. Mixed periods use separate entries.
9. Use Schedule technician, search Avery or Casey, choose a date/time and work
   brief, then review and save the planned visit. Avery has a synthetic conflict
   on 13 September, 12:30–1:00 pm; Casey has one at 1:30–2:30 pm. Saved visits
   also participate in conflict checks. Reschedule and cancellation are local.
10. Use People & contact → Details to search and change the requester or affected
    user independently. Search supports names, email, roles and work phones.
    Owner selection also searches technicians. Feature guide opens the workflow coverage and production integration limits.

## Deliberate limits

- There is no server persistence, email, notification delivery, real upload,
  database access, permission implementation or real SLA calculation. Simulated
  lifecycle transitions show the resolution clock as unmeasured. Historical
  first-response evidence is kept separately. The default fixture is a frozen
  13 September 2026, 10:10 am NZST snapshot.
- The synthetic ticket and drafts are held in session storage for this browser
  tab. Refresh offers Resume or Discard for unfinished work. Reset preview clears
  this mockup’s session key. This is not server-backed persistence.
- People searches use three synthetic users and eligible fixture technicians;
  there is no directory query or production authorization implementation.
- Planned visits have requested, accepted, declined, completed and cancelled
  states. Responses are recorded as synthetic events. No invitation, real calendar
  mutation or live availability is implemented. Production must use SiteCalendar.
- Time and notes are saved together in this tab’s synthetic snapshot. Production needs an
  atomic write, permissions for edits, an audit trail and retry idempotency.
  Existing two time fixtures represent earlier work; newly added entries link
  directly to notes. New duration calculations resolve valid Auckland offsets. Ambiguous/nonexistent
  daylight-saving input is rejected for clarification. Timer pauses are excluded.
  A reviewed split uses an explicit sample Mon–Fri 8:30 am–5 pm support calendar.
  After-hours flags remain editable. Payroll and billing are not implemented.
- The role is a technician. Requester confirmation/rating remains a separate
  role-gated production workflow, not an action for the technician to perform.
- Task creation/reordering/editing/cancellation/reopening, complete property
  and reasoned routing editors, record link management, and ticket merge retain
  discoverable destinations but are explicitly not simulated. Approval starts with no required records; new after-hours time and qualifying
  costs create review requirements under clearly labelled sample rules.
- Application navigation, global search, incident reporting and clocking are
  contextual shell controls; they do not navigate to or mutate the real app.
- The attachment download generates a synthetic text sample. No real ticket
  file is fetched. The printer preview is inspectable HTML representing a
  synthetic attachment, not a screenshot of a real device.

## Design sources

Read-only references inspected: root `AGENTS.md`, `DESIGN.md`, the Page Header,
App Shell, Navigation, Button, Popup and Design Tokens guides; `app.css`;
`page-header.tsx` and `grouped-profile-nav.tsx`; the ticket show page, workspace
header, thread, audience composer, task work, related/linked work, SLA evidence,
waiting, resolution, close and reopen components.
Additional read-only coverage references: ticket controller and payload, User
contact fields, ItTicket, ItWorkTask and its input contract, shared calendar
models, and the Calendar guide. Contact fields exist on User but were absent
from the inspected ticket payload; task due dates do not reserve time.

`tokens.css` is a snapshot of the current shared semantic tokens and Event
Horizon utilities. `prepare-assets.cjs` can regenerate it and the Lucide icon
snapshot using read-only source/package access. Runtime has no application
imports. Instrument Sans is bundled from the public Bunny Fonts distribution.
The mockup uses the product’s required gradients and soft-depth buttons;
generic flat-canvas visual guidance does not replace the product contract.

## Focused verification

Previous browser review: existing Chrome desktop viewport, **1920 × 945**, unchanged.
No mobile emulation, viewport resizing, Laravel server, migrations, app build,
backend tests or application mutations.

Verified visually: initial page, internal composer, file presentation and the
resolution review dialog. Verified through UI interactions: section and meter
navigation, separate drafts, staged sample file retention, public reply retry,
task required-field validation and verification, resolution blocker, resolution
review, unsaved-dialog protection, close/read-only state, reopen and clock
evidence, section search, keyboard tier-two navigation, no-approval empty state,
details disclosure and deeper action destinations. The connected rail label
centres measured **23px above the band edge** in every state; the shell content
gutter and section spacing are **20px**. Final JavaScript syntax check passes.

Follow-up browser checks: contact presentation, after-hours checkbox and
duration calculation, editable start/end and totals, end-before-start and
overlap rejection, note/time draft retention across audiences, failed combined
save with no work entry created, successful retry with exactly one linked note
and entry, searchable technician selection, booking conflict and review/save,
user search including no results and email matching, and independent affected
user selection with updated contact details. No backend verification is claimed.

This is a reviewable layout recommendation, not an implementation approval.
Only files inside this new directory were authored. Nothing was committed,
pushed, deployed or communicated to real ticket participants.

## Complete workflow additions

- Combined note, time, status and follow-up save, with existing task and new approval gates.
- P1 Critical / P2 High / P3 Medium / P4 Low priority identification beside the title; changes require a reason and retain history.
- Start/pause/resume/stop timer, manual timing with today’s date, separate end date, breaks, travel and reviewed after-hours splitting.
- Named follow-up owner, exact due date/time, waiting party and reason; overdue presentation.
- Searchable day availability, multiple technicians per visit, individual responses, reschedule/cancel with undo and completion through actual work notes.
- Separate requester and affected-user contact cards, contact preferences, alternate contact and explicit public recipients.
- Collapsible diagnostic summary with impact, location, onset, affected count, workaround, access instructions and vendor/support references.
- Note and time corrections preserve before/after values, recorder and required reason. Approved time requires a correction request first.
- Parts and expenses with quantity/unit cost, example internal labour rates and approval review. Example rates are NZD 85 / 127.50, not agreed business rates.
- Example approval rules require after-hours time and costs of at least NZD 100 to be reviewed before resolution. Supervisor decisions are explicitly simulated; they do not grant technician approval powers.

The source is split between the original presentation in `mockup.js` and the
workflow extension in `workflow.js`. Both run only in this mockup.

Run `node verify-workflow.cjs` from this directory for isolated jsdom checks.
The 40 checks cover priority identification/change, note/time/follow-up saves,
split timing, failed-save retry without duplication, approval gates and locks,
correction history, multiple-technician scheduling/conflicts/responses/completion,
cost calculation, and draft recovery. The harness reads the already-installed
jsdom dependency without changing packages or running application tests.

## Final browser pass for the expanded workflow

Verified in a separate Chrome tab at its existing 3440 px desktop width, without
resizing. Inspected the five-meter header, combined note/time/status form and
scheduling dialog visually. Verified searchable availability, P1–P4 choices and
a saved P1 change appearing consistently in Classification. No browser console
errors or horizontal page overflow; hero shadow remains none and rail labels
remain centred 23 px above the band edge. The verification tab was closed.
