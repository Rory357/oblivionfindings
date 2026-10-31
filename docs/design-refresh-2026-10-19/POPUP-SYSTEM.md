# One shared pop-up system — proposal (19 October 2026)

Status: **proposal for Stephan**. Nothing in the code has changed.

Stephan's question: *"is it possible to make a shared thing so that if we make
a change in the future the design will feel consistent across the
application?"* — yes. This is how.

## The problem in numbers (counted 19 Oct, tests excluded)

| What | Instances | Files |
|---|---|---|
| `WizardShell` wizards | 337 | 298 |
| `ConfirmDialog` confirms | 253 | 155 |
| Hand-built `DialogContent` dialogs | 468 | 323 |
| `AlertDialog` confirms | 48 | 44 |
| `Sheet` side slide-outs | 23 | 24 |
| `SettingsModal` (Fleet-style settings) | 63 | — |
| Other bespoke overlays (`TicketDrawer`, `MedicationEventDrawer`, `EventRecordSheet`, `ReportErrorModal`, `InsightModal`, `RunModal`, `StocktakeModal`, `PrivacyActionModal`, …) | ~60 | — |

Dialog widths in use: **more than 20 different values** (`max-w-md` 45×,
`max-w-lg` 44×, `max-w-2xl` 28×, `max-w-xl` 30×, `max-w-3xl` 10×, plus 480,
520, 560, 640, 720, 760, 900, 980, 1100 px and `92vw` variants). The
"original" Add client wizard (`components/clients/add-client-dialog.tsx`) has
its own hand-built frame instead of `WizardShell`, so even the reference
wizard doesn't match the other 337.

**Good news:** ~590 pop-ups already sit on two shared parts (`WizardShell`,
`ConfirmDialog`). Restyling those two files changes ~590 pop-ups at once. The
work is moving the other ~650 onto shared templates.

## The kit: 8 types, 21 building blocks (drawn 20 Oct 2026)

Stephan, 20 Oct: *"can we have re-usable things instead of having so many
different? help me to make it uniform"* — yes. Every one of the 1,539 pop-ups
is one of these eight types, filled with its own words. Viewer page "4 · The
pop-up kit" (<https://claude.ai/artifact/S2FWVebvqtxrVL5LHahzHy>) draws them,
the blocks and the rules, in light and dark.

Every overlay in the app is built from one of these (one folder:
`components/popup/`). Nothing else may render `DialogContent`, `SheetContent`
or `AlertDialogContent` directly.

| Type | Use for | Sizes (fixed) | Today |
|---|---|---|---|
| **Wizard** | A main record with 3+ sections or more than ~12 fields (client, care plan, ABC entry, incident, staff, Site, asset, ticket) | `xl` 1100 only | 400 (337 on WizardShell) |
| **Form** | Every other add / edit / record / send | `sm` 480 (≤ 3 fields) · `md` 720 (≤ ~10) · `lg` 900 (sections inside) | 320 + short wizards |
| **Confirm** | Yes / no: plain, destructive, with a reason, one-button alert | `sm` 480 | 214 + 121 + 27 alerts |
| **Viewer** | Read-only record, dose, file | `sm` 480 · `md` 720 · `lg` 900 | 171 + 2 file previews |
| **Side panel** | Look at or manage a record beside its list (ticket, dose event, goal) | 480 · 640, from the right | 21 |
| **Settings** | Fleet pattern: switch rows and config cards | `md` 720 (`lg` 900 for who-gets-what tables) | 47 |
| **Picker & menu** | Search pickers; one item list for ⋮ and right-click | popover | 84 + 20 + 4 |
| **Toast & selection bar** | "It worked" with Undo; act on ticked rows | — | — |

**Which type — ask in this order:** yes / no → Confirm · read-only → Viewer ·
beside a list you keep working in → Side panel · a setting → Settings · main
record with 3+ sections or 12+ fields → Wizard · anything else → Form (size by
field count). A one-step wizard is a Form; a wizard rail used as tabs is a Side
panel.

**The 21 building blocks:** header (icon in a lens, title, one line, ✕) ·
context row · callout · section · text field · choose & numbers · date & time
(the approved pickers only) · choice tiles · segmented choice · chips · switch
& tick · people · upload · repeating rows · step rail · review card · success ·
footers (form, wizard, destructive, viewer) · "Discard this draft?" · states
(loading, couldn't load, no access, nothing yet) · saving.

**The words:** sentence case; title = verb + thing ("Add risk"); confirms ask a
question that names the thing ("Delete document?"); one line under the title
says what happens; the main button names the action ("Save risk", never
"Submit"/"OK"); Cancel then the main button, last on the right; red only when
data is destroyed or a safety check is loosened; "Delete" when gone for good, "Remove" when kept in history;
one "Discard this draft?" wording everywhere.

All of them read only design tokens (`--card`, `--border`, `--primary`,
status pairs, plus new `--popup-radius`, `--popup-icon-tile`,
`--popup-footer`). Change a token or a block once → every pop-up follows.

> Correction (20 Oct): an earlier version of this table listed 440 / 600 /
> 760 / 1140. The approved widths are **480 / 720 / 900 / 1100**.

## Module by module — all eight drawn (20 Oct 2026)

**In total:** the inventories list 1,541 pop-ups (1,518 live). They hold **1,772 separate screens**, because one component often runs several commands. On the kit they become **1,555 drawings** after 93 merges: 72 wizards (400 today), 662 forms, 454 confirms, 229 viewers and side panels, 112 pickers and menus, 15 settings, 11 toasts and bars. Every one is built from the 8 types and 21 blocks; 23 dead pop-ups are listed for deletion. The totals table is on the kit's rules board.

Each module is drawn from its real code (titles, fields, options, buttons),
sorted onto the kit, with look-alikes merged. Every drawing's caption names
the inventory row, the file:line, what it is today and what changes.

| Module | Today | Drawings | Merged / removed | Status |
|---|---|---|---|---|
| Clients | 59 (58 live) | 51 (6 wizards, 28 forms, 11 confirms, 3 viewers, 2 side panels, 1 bar) | Quick note + timeline note → **Add note**; two Upload document → one; two Record consent → one; three Schedule appointment → one; Add relationship + Invite to family portal → one (portal = a switch); Manage goal + Edit goal → **Manage goal** side panel; dead "Record administration" deleted; the "Complete profile" loading pop-up becomes the wizard's own loading state | ✅ drawn 20 Oct, awaiting Stephan |
| Sites, operations, respite, catering, control room… | 223 (221 live) | 192 (81 forms, 47 confirms, 20 viewers, 17 menus, 12 pickers, 7 wizards, 4 side panels, 2 toasts, 1 settings, 1 bar) | 18 discard guards with 15 different titles → **one "Discard this draft?"**; New/Edit Site contact → one; two Add ledger entry (6 vs 8 categories) → one; two Discharge stay → one; meal-planner + Catering product and tag forms → one each; Start new shift pasted twice → one. Shared with other modules: Site documents ×5 = the Clients document pop-ups; shift and respite incident forms = Clients › Log incident; the stay restraint wizard = Governance HS-24. Not pop-ups: house-plan builder (full page), legacy full-page Add/Edit site (use the wizard), print documents, two loading screens. Dead: `ShowRoomDialog`, `FlagIncidentDialog` | ✅ drawn 20 Oct, awaiting Stephan |
| Medication & clinical | 233 rows holding 292 screens | 248 (13 wizards; recording pop-ups at frontline 44 px) | 23 discard prompts with 21 titles and 6 Cancel wordings → **one guard**; three ways to record a dose (Record dose, the old MAR-chart wizard, the as-needed wizard) → **one Record dose**, keeping the MAR wizard's five-rights ticks; two dose pickers, two INR forms, two order-entry wizards, three covert-stop prompts, two pharmacy-order forms, four Receive stock pop-ups, two stock counts (blind count kept), two pharmacy-order viewers, three person pickers, three report-period popovers, two downtime packs, two witness-PIN resets, two observation recorders (NEWS2 kept), two clinical-event recorders, round sheet + MAR chart export → one each. Not pop-ups: 5 loading shells, 1 pass-through link | ✅ drawn 20 Oct, awaiting Stephan |
| HR & rostering | 229 (227 live) holding 258 screens | 234 (clock in / out, breaks and staff timesheets at frontline 44 px) | Six discard prompts → **one guard**; three "Cancel this leave request?" → one; two Create offer → one (the safety-check conditions — right to work, Police vetting, Children's Act check, references — always filled in); two exit-interview pop-ups → one (confidential shown and on); two performance-review editors, two candidate uploads, two HR document uploads, two archive-document prompts, two policy attestations, three people pickers, two date-jump pickers → one each. Shared with Operations: the five shift browser confirms, Cancel future occurrences, the week picker; Broadcast cover request merges into the Rostering one. Dead: calendar day detail, work-restriction approvers | ✅ drawn 20 Oct, awaiting Stephan |
| Finance & fleet | 288 (286 live) holding 327 screens | 276 (drivers' checks, fuel, handovers, incidents and transport logging at frontline 44 px) | 13 discard prompts → **one guard**; seven date-range filters → one; three as-at date pickers → one; nine copies of the searchable picker → one. The vehicle workspace and the Fleet pages each had their own copy: bookings, documents (×3), Finance review requests (×3), report a problem, appointments, evidence uploads (×5), Finance evidence, checklists, service schedules, reminders, acknowledgements, Send to Control Room, the Finance review decision, the vehicle check, alert response and boundaries → one each. Not pop-ups: 7 loading stubs, the expanded map (full screen), the unbuilt crash-detection storyboard (hide its button). Dead: the old Create geofence page, an unused picker | ✅ drawn 20 Oct, awaiting Stephan |
| Governance, H&S & hub | 219 (218 live) holding 299 screens | 276 (reporting events, hazards, injuries, restraints, incidents and concerns, and lone-worker check-ins at frontline 44 px) | Ten discard prompts → **one guard**; the H&S dashboard launcher had its own copy of the drill, restraint, injury, lone-worker, hazard and H&S-rep pop-ups → one each (restraint keeps all 7 steps and opens from Respite stays too); five board-report menus → one; Governance and Compliance each had add obligation, evidence and mark complete → one each; two date-range filters, two mark-as-done, and the old privacy pages' browser prompts (refuse, extend, notify OPC) merged into the privacy workspace forms. Dead: an old hazardous-substance config | ✅ drawn 20 Oct, awaiting Stephan |
| IT & support | 96 (93 live) holding 117 screens | 103 (raise a ticket, confirm the fix, rate IT's help and the requester's quick peek at frontline 44 px) | Twelve discard prompts (plus the merge, approval and task drafts' own) → **one guard**; three receipt viewers → one; single and bulk provisioning actions → one; macro and reply-template archive → one. The quick peek is the kit side panel (640) with Conversation / Activity tabs; More filters is one popover, not a popover in a popover. Not pop-ups: the full-screen diagram editor. Dead: Fulfil provisioning request, Record provisioning failure (only tests open them), Request files (its list no longer has a route) | ✅ drawn 20 Oct, awaiting Stephan |
| Shell, settings & devices | 194 (182 live) holding 198 screens | 175 (My Day and end shift at frontline 44 px) | Eight discard and leave prompts → **one guard**; three palette builds (+ the Company Hub variant) → **one Ctrl K**; notifications + announcements → one panel with two tabs, and their two detail dialogs → one; ⋮ and right-click menus → one item list; two evidence viewers, two colour pickers (brand + accent), create / customise role, two camera set-ups (Hikvision, UniFi), two equipment pickers, two bulk reviews, three Request a visit forms, three My Day resident pickers, two task details → one each. Dead: 12 (theme toggle, shortcuts, a second Delete account, shift notes sheet, mobile sidebar, undo toast, purge / delete-organisation stubs, the legacy group-mapping page, the unrouted users page) | ✅ drawn 20 Oct, awaiting Stephan |

Found while drawing Clients (for the build, not changed yet):
- "Remove ABC entry?", "Remove goal?", "Remove personal asset?" and "Remove
  risk?" are permanent deletes → drawn as "Delete …?".
- "Remove sign-off?" keeps history but has a red button and never asks the
  optional reason the server accepts → drawn as a reason confirm, not red.
- "Care plan review" (`profile/flows.tsx:846`) has no button that opens it.
- Condition severity is Mild / Moderate / Severe in Add client but free text
  in Add condition; goal status is "Achieved" in Manage goal but "Completed" in
  Edit goal.
- Most Clients pop-ups close without asking when there is typing.

Found while drawing Shell, settings & devices (for the build, not changed yet):
- **Role delete can't work:** the server always refuses it — hide the trash button.
- **Unreachable red buttons:** "Purge all deleted records" and "Delete organisation"
  can't open (`dangerZoneAvailable = false`).
- **No confirm:** Suspend user and Impersonate (Suspend is fixed on the
  confirms branch `claude/modest-wilson-4245bf`, not merged), template "Reset to
  default", data-processor Remove, the diagram-studio page delete (undo only).
- **Brand hard-coded:** the Appearance placeholder uses the `#7c3aed` fallback.
- **Server gaps:** board-member Notes are never stored.
- **Family portal:** the calendar copy of Request a visit lacks "The care team will
  review and confirm."; deleting a portal message uses a browser `confirm()`.
- **Unrouted / unwired:** `pages/system/users/Index.tsx` (no controller); the
  keyboard-shortcuts hook (only the dead component uses it); "More tabs" only on
  the design demo page.
- **Words:** `ReasonDialog` is red by default; the header palette repeats
  "Sites & Locations › Sites & Locations"; 24-hour times ("08:00") in My Day badges.

Found while drawing IT & support (for the build, not changed yet):
- **Red by default:** Reject request, Cancel approval request, the Close ticket
  button and about a dozen confirms with no `variant` are red though nothing is
  destroyed; drawn plain. Revoke API identity stays red.
- **Raw alert boxes** instead of the shared confirm: Save draft before closing,
  Close before the result is confirmed, Apply macro, Delete saved filter, the
  revision Publish / Restore / Discard.
- **Unreachable:** "Edit article" (nothing passes an article to the wizard).
- **Words:** "Close this work?" closes the form, not the work; booking dialogs
  have lower-case verb titles; "Keep published / Keep draft" as Cancel labels.
- **Cancel scheduled restart** opens from the Security & devices computer
  record, not from Remote support.

Found while drawing Governance, H&S & hub (for the build, not changed yet — legal items need Stephan):
- **Privacy Commissioner notification recorded when cancelled:** on the old
  breach page, cancelling the browser prompt still records the OPC notification.
- **Support workers can't record injuries or restraints:** the routes need
  `hazards.create` / `hazards.manage`, which support workers don't hold.
- **No confirm:** archiving a behaviour support plan acts at once; cancel a
  committee meeting; mark an H&S rep stood down; reject a roadmap suggestion;
  deleting evidence or documents in risk assessments, privacy, safeguarding and
  incidents. Confirms drawn for the plan archive, the meeting and the suggestion.
- **No Cancel button:** Record a restraint event and Create a behaviour support plan.
- **Words:** "CheckRiskAssessmentReviewsJob" shows on screen; "PIA" vs "DPIA";
  the lone-worker launcher tile says "check-in" but starts a session; one board
  report menu says "Board safety summary", the others "Board summary".
- **Company hub:** drawn from the code; Birthday sharing and Notification
  preferences are settings pop-ups here but a page tab and a side panel in
  Codex's approved hub design — the build follows Codex's design.

Found while drawing Finance & fleet (for the build, not changed yet):
- **Location-tracking consent:** "Grant consent" in Devices posts straight away —
  no pop-up and no privacy notice. "Unassign" tracker has no confirm; Delete trip
  asks no reason; removing incident photos has no confirm.
- **Separation of duties not shown:** the server refuses a creator approving
  their own payment run, but the approve confirm never says so.
- **Loosening a check is red, keeping a record is not:** "Mark WoF / rego / COF /
  RUC as not required" and "Pause existing monitoring" stay red; cancel bill,
  reverse journal, close period, dispose asset, archive vehicle document,
  disconnect feed and retire asset lose their red buttons (the record stays).
- **Words that differ:** bill evidence says 20 MB, the file box allows 10 MiB;
  the IRD number placeholder is 8 digits but the field allows 11; one estimate
  never says whether GST is included; "Responsible role" picks a person.
- **No confirm:** "Remove photo" on a vehicle; "Place approved restriction" saves
  a hold at once, apart from the form's own Save.
- **Unbuilt feature behind a button:** the telematics crash-detection storyboard —
  hide the button until it exists.

Found while drawing HR & rostering (for the build, not changed yet — privacy and employment-law items need Stephan):
- **Vetting disclosures in general Notes:** Add vetting check writes the outcome,
  disclosures and consent method into Notes that everyone who can open the check
  sees, while the "Disclosure information" card is restricted.
- **No support-person notice** on the performance improvement plan and the
  probation review (the disciplinary pop-ups have it).
- **"Who's out"** shows colleagues the leave type (sick, parental, bereavement).
- **Hard-coded client name:** the e-sign legal text says "Kauri Care" — must come
  from Settings › Branding.
- **Recognition insights** names people on a leaderboard; the "anyone who can
  view the feed" rule is only a code comment.
- **No confirm:** Escalate in leave detail escalates *all* overdue approvals;
  Regenerate calendar-feed URL revokes the old link at once; the email template
  bin deletes a saved template; cancelling the browser prompt still cancels a
  training session; net pay settles silently when a reference exists.
- **Leave balance:** the debit preview can show a negative balance the server
  never saves (it caps at the balance).
- **Close case** offers 2 of the 5 outcomes the server accepts; the disciplinary
  "Outcome document" is a free-text path, not an upload.
- **Reject from the candidate profile** records no reason (the hub does).
- **"Enroll"** → NZ "Enrol"; the timesheet viewer hard-codes "Support worker".

Found while drawing Medication & clinical (for the build, not changed yet — safety items need Stephan):
- **Five rights:** the older MAR-chart dose wizard asks five required ticks (right
  person, medication, dose, route, time); the unified Record dose does not. The
  merged Record dose keeps them — decide before the old wizard is retired.
- **Void destruction record** asks only a reason; the controlled-drug "Void a
  destruction" asks witness + PIN as well. Drawn with witness + PIN + reason.
- **Observation from the shift card** has no NEWS2 inputs or review flag (the
  clinical wizard does). The merged recorder keeps them for every route.
- **No confirmation:** Approve correction posts at once; Prescriptions "Confirm
  order" posts at once; the Reject reason on Verify orders is typed but never sent.
- **Loosening a safety check is red:** Pause due and late dose alerts, Loosen
  staff support (twice), Pause rule, Pause round template — red in the drawings.
  `ConfirmDialog` is red by default today, so 34 call sites are red for the wrong
  reason (resolve, record, agree); drawn plain.
- **Witness wording:** "Your own witness PIN" with help text "Their own"
  (paper entry); the on-call contact is a hard-coded "Not configured" chip.
- **Frontline (44 px)** is missing on Record dose itself and about 39 recording
  pop-ups, and is set on manager screens that don't need it.
- **Dead code** (Appendix B): ten old controlled-drug and review dialogs —
  delete them.

Found while drawing Sites & operations (for the build, not changed yet):
- **Deletes with no confirm at all:** right-click "Delete meal", the meal planner's
  "Copy to next week", the Shifts list "Cancel shift", the calendar "Reset link",
  the house-plan canvas Delete; a personal calendar entry Delete only shows an
  Undo toast.
- **Red when nothing is destroyed:** `ConfirmAction` forces a red button — Unlink
  client, Change damage status, Remove asset, Deactivate resource, Mark list
  received, Terminate agreement, Discharge stay, Archive product, End emergency
  access, and the six browser `confirm()` shift actions. Drawn plain.
- **"Remove" that is a permanent delete:** coverage requirement, staff
  requirement, meal, shopping-list item, qualification requirement, vendor →
  drawn "Delete …?".
- **"Restore credential"** is painted red in the credential row menu.
- **Words that differ for the same thing:** "Caregiver" (coverage rules) vs
  "Support worker" (Add site); Site document categories differ between the Add
  site wizard and the Documents tab; stock units "box" vs "bunch"; Edit location
  Region is free text, the wizard's is a list.
- **Two routes to one action:** Apply template has its own replace/add choice,
  but "Plan week" goes straight to a different confirm.
- Developer wording on screen: "in one transaction" (Place an existing client).

The drawing source (specs with the real content, the kit renderer, and the
merge plan) is in [popup-kit/](popup-kit/).

## Upload audit (21 Oct 2026)

Stephan, 21 Oct: *"the upload files on all the modals etc. are they in because i saw one in sites vendors that does not have the upload ui drag drop"*.

Every file upload in the code (113 — `<FileDropzone>`, `<input type="file">`, `useDropzone`) was matched to its drawing by two helpers (`popup-kit/upload-audit-a.json`, `-b.json`).

- **Where they are:** 74 in pop-ups, 19 on pages, 6 on tabs, 14 in shared components.
- **How they're built:** 53 use the shared `FileDropzone` (drag and drop). About 60 are a bare file button; 14 of those have a home-made drop area; about 6 say "drop a file" but have no drop handler.
- **Drawings:** 73 already showed the drop zone. Fixed on 21 Oct (Version 16): 8 drawings were missing it (Stocktake QR image, Add personal item photo, the diagram file Import, Release vehicle retest evidence, New vehicle inspection evidence, and the Evidence tabs of the control-room alert and the injury record — drawn as extra tab drawings). 6 upload pop-ups were never drawn and now are: Upload a document (consultation), Upload minutes (committee meeting), Complete action (H&S event), Return for destruction (controlled drugs, witness and PIN kept), Link CSV data (diagram shape data) and Upload photo (client Photos tab, starting at Staff only).
- **The one in the question — Add agreement:** the app says "Upload Word or PDF files after saving"; the files go on the agreement's own record, which was drawn with its drop zone. Proposed (K6) and drawn: attach them in Add agreement, uploading straight after the save.
- **Kit:** the drop zone no longer invents "PDF, JPG or PNG, up to 10 MB" when the code sets no types or size; it says so plainly.
- **Not drawn yet:** 21 uploads on HR, IT, Fleet, Settings, Reporting, Company hub and careers pages — they arrive with those page modules. Two are dead code (`ImportCsvDialog`, `pages/clients/documents.tsx`).
- **Found:** daily note attachments are never uploaded (S21, checked). Branding's favicon hint says "OPC" (should be ICO), and logos over 2 MB are silently ignored. The client photo form in `operations/clients/show.tsx:6829` is never opened.

## How it stays consistent in future

1. **One folder, one owner.** `components/popup/` is the only place pop-up
   chrome lives. Feature code passes title, icon, fields and actions — never
   widths, paddings, colours or footers.
2. **ESLint guardrails** (blocks the build, message points to DESIGN.md):
   - no `DialogContent` / `SheetContent` / `AlertDialogContent` import outside
     `components/popup/`;
   - no `max-w-*` / `w-[…]` on a pop-up — pick `size="sm|md|lg|xl"`;
   - no hand-rolled stepper or progress bar inside a pop-up;
   - existing guardrails (raw colours, recoloured buttons) keep applying.
3. **A living gallery page** (`/design/gallery`, admins only): every template
   in light and dark, every state (empty, loading, error, long text,
   destructive). Any change is checked there first, at 1440 px.
4. **Rory's rules** (`DESIGN.md` + `design_styles/POPUP_STYLE_GUIDE.md`) name
   the templates, the sizes and the anatomy; the anti-pattern list records
   each correction with the date.
5. **The mockup canvas is the picture of the rules.** The approved boards stay
   on the canvas so the rule, the gallery and the code can be compared.

## Order of work (each step needs Stephan's OK)

1. ✅ Choose the colour direction — D · Slate (20 Oct).
2. 🔄 Approve the kit: 8 types, 21 blocks, the rules (drawn 20 Oct).
3. ✅ Draw every pop-up from the inventory on the kit, module by module —
   Clients (58 live → 51) and Sites & operations (221 live → 192) drawn 20 Oct;
   Medication & clinical (233 rows, 292 screens → 248) and HR & rostering (258 screens → 234)
   Finance & fleet (327 screens → 276) and Governance, H&S & hub (299 → 276)
   IT & support (117 → 103) and Shell, settings & devices (198 → 175) drawn 20 Oct —
   all eight modules done: 1,772 screens → 1,555 drawings.
4. Write the approved templates into Rory's rules + ESLint.
5. Build `components/popup/`, restyle `WizardShell` and `ConfirmDialog`
   (~590 pop-ups change at once), then move the hand-built dialogs module by
   module, checking each against its mockup at 1440 px in light and dark.
