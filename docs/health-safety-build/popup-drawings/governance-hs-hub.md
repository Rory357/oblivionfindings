# Pop-up inventory: Governance, Company hub, Health & safety, Compliance, Privacy, Safeguarding, Incidents, Roadmap

Date: 19 October 2026. Branch `claude/oblivion-colors-design-5ff161`, HEAD `1a9150d64`. Read-only audit; no source file was changed.

Scope (all under `resources/js/`): `pages/Governance/**`, `components/governance/**`, `pages/company-hub/**` (`components/company-hub/` holds no overlay), `pages/health-safety/**`, `components/health-safety/**`, `components/worker-participation/**`, `pages/compliance/**`, `components/privacy/**`, `pages/privacy/**`, `components/safeguarding/**` (plus its two pages in `pages/safeguarding/**`), `components/incidents/**`, `pages/incidents/**`, `pages/Roadmap/**`. All File:line paths in the tables are relative to `resources/js/`; the line is the overlay's opening tag (or the component that renders it). Test files were skipped.

How the list was built: a tag scan for `WizardShell`, `Dialog`/`DialogContent`, `AlertDialog`, `ConfirmDialog`, `ReasonDialog`, `Sheet`/`SheetContent`, `Popover`/`PopoverContent`, `CommandDialog`, `FilePreviewDialog`, `SettingsModal`, plus `*Modal`/`*Drawer`/`*Panel`/`*Dialog` component names, plus `window.confirm`/`prompt`/`alert`. Every hit was opened, mounted-ness was checked, and every `*Panel` was checked for overlay versus inline. Only overlays are in the tables; look-alikes that render inline and code that no page mounts are in Appendices A and B.

## Totals

**219 distinct pop-ups listed** (219 rows). Counting rule: one row per distinct dialog, wizard, sheet or popover instance in code. Config-driven wizards get one row per config (HsFormWizard 7, PrivacyWizard 5). Components that take a `kind` prop and only change fields stay one row with the variants named (PrivacyActionModal 12 variants, LoneWorkerActionModal 7, RaWizardDialog confirmations 4, DeclareConflict and Meetings minutes confirms 1 each). The shared leave-guard ConfirmDialog is one row (HUB-28).

### By Type

| Type | Count | Notes |
|---|---|---|
| Wizard (WizardShell) | 49 | 48 on `WizardShell` or a clone plus 1 hand-rolled (HUB-10). By module: 13 Governance, 7 HsFormWizard configs, 14 dedicated H&S (3 of them PPE clones), 3 worker participation, 5 Privacy, 4 Compliance, 1 safeguarding, 1 incident, 1 hub |
| Form dialog | 45 | includes 4 PPE actions built as single-step `WizardShell`s, plus the lone-worker (7 variants) and privacy (12 variants) action modals |
| Detail viewer | 34 | 21 are `WizardShell` with a section rail (H&S, worker participation, safeguarding, incidents, privacy) |
| Confirm | 28 | 25 are Governance ConfirmDialogs; the others are HUB-25, HUB-27 and the RA confirmation shell HS-48 |
| Destructive confirm | 19 | 5 are the same "Discard this draft?" prompt built five ways |
| Side sheet | 7 | all in the Company hub |
| Picker | 5 | 2 date-range popover forms, 1 media library, 1 launcher tile chooser, 1 searchable popover |
| Alert | 2 | AlertDialog or 3-way keep/discard/save prompts |
| Other | 30 | 14 popover menus, 4 info popovers, 1 navigation flyout, 1 options dialog, 1 help dialog, 9 native browser `prompt()`/`confirm()` |
| Settings modal | 0 | none in this area (Birthday sharing and notification preferences are settings-style but built from Dialog/Sheet) |
| File preview | 0 | none in this area (hub PDF reader and event photo viewer are bespoke; see HUB-21 and Appendix A) |

### By module

| Module | Rows |
|---|---|
| Governance (GOV-01 to GOV-96) | 96 |
| Company hub (HUB-01 to HUB-31) | 31 |
| Health & safety incl. worker participation (HS-01 to HS-62) | 62 |
| Compliance (CMP-01 to CMP-04) | 4 |
| Privacy (PRV-01 to PRV-17) | 17 |
| Safeguarding (SAF-01 to SAF-03) | 3 |
| Incidents (INC-01 to INC-04) | 4 |
| Roadmap (RDM-01 to RDM-02) | 2 |

## The 5 most common inconsistencies

Counts are rows whose Inconsistencies cell names the issue (a floor, not a ceiling).

1. **No discard-on-close guard on wizards: 34 of 49 wizards.** Only the 13 Governance wizards (shared `DiscardDraftDialog`, plus three local copies and one ConfirmDialog), the incident wizard (INC-02) and the idea composer (inline band) ask before throwing away a dirty form. Every H&S, worker participation, safeguarding, compliance, privacy, PPE and HsFormWizard wizard closes immediately, although POPUP_STYLE_GUIDE requires the guard.
2. **Native form controls inside pop-ups: 30 rows** (plus native `type="date"` / `datetime-local` in about 40 files of this area). Raw `<input>`, `<textarea>`, `<select>`, `<button>`, a `type="range"` slider (GOV-03), hand-rolled file inputs instead of FileDropzone (HS-10, CMP-02), and `GuardrailButton unstyled` buttons with raw colour classes (HS-30, HS-31, HS-32). Date and time entry never uses the approved calendar or clock pattern.
3. **Hand-rolled chrome instead of the shared shells: 26 rows.** Examples: `PpeWizard` is a copy of the wizard shell (3 wizards), `RaDetailDialog`, lone-worker modals and `PrivacyActionModal` rebuild header and footer on a bare `DialogContent p-0`, the idea composer draws its own stepper, 7 hub sheets restyle `SheetContent` (600/640 px, lighter backdrop, custom header/footer), and 7 hub confirmations are plain `Dialog`s with a primary button even for destructive actions.
4. **Width outside the tokens (480 / 720 / 900 / 1100 px inline style): 27 rows.** Tailwind `sm:max-w-md/xl/2xl/3xl/5xl` and `max-w-lg/2xl` (Clinical, Roadmap, hub, launcher), numeric `maxWidth: 720`, and inline widths of 520, 540, 560, 600, 620, 800, 860 and 1040 px (Governance Budgets, Risks, Performance, Strategy, Spend approvals, My work, Audit log; hub birthday and event photo). Sheets are 600/640 px against the standard `sm:max-w-sm`.
5. **Four different wizard footer layouts and mixed button wording: all 49 wizards.** Footer A (Ring left; Back, Cancel, Continue right), Footer B (Back left; Cancel, Continue right), Footer C (Back or Cancel left), Footer D (everything right). Five wizards have no Cancel button (hazard log, restraint event, behaviour support plan, raise concern, incident report), two say "Next" instead of "Continue", Cancel is `outline` in most wizards and `ghost` in others, three worker-participation wizards use `size="sm"` footer buttons, and at least 26 titles carry no header icon (15 Governance dialogs, most hub dialogs, every hub sheet except two, both Roadmap dialogs).

Other cross-cutting issues worth a designer's attention:

- **Confirmations are built seven ways**: ConfirmDialog (Governance), shared DiscardDraftDialog, hand-rolled `Dialog`/`AlertDialog` (hub, Governance Sign minutes, attachments), a full single-step `WizardShell` used as a confirm (RA approve/archive, PPE return/inspect/condemn/dispose; 8 rows), in-pane confirm panels (first aid archive), local two-click `ArmedButton` and two-phase "arming" (event and incident evidence), and native `prompt()`/`confirm()` (9 triggers, 11 calls on three Privacy pages). Attachment trash buttons delete immediately, with no confirmation, in 8 detail viewers (HS-16, HS-19, HS-22, HS-25, HS-45, HS-49, SAF-03, PRV-06).
- **ConfirmDialog defaults to the destructive style**: every non-destructive Governance call has to pass `variant="default"`; the hub's shared leave guard (HUB-28) does not, so "Discard and continue" for a harmless "Leave this idea?" is red.
- **Duplicates (about two dozen rows mention one)**: "Discard this draft?" exists five ways (GOV-29, GOV-61, GOV-72, GOV-91, GOV-93); Compliance dashboard wizards duplicate the Governance › Compliance dialogs (CMP-01 to CMP-03 versus GOV-28 to GOV-31); the 7 `HsFormWizard` configs duplicate seven dedicated H&S wizards; date-range filter popover is copied (GOV-11, GOV-49); Risk "Mark this action as done?" duplicates the Action one (GOV-76 versus GOV-07).
- **Popovers used as menus, help or mini-forms (21 rows)**: 14 "Board reports / Reports / New / Add to register" menus made of raw buttons or links in a `Popover` (should be DropdownMenu), 4 static-help popovers, 1 navigation flyout, 2 date-range filter forms.
- **Form then confirm stacked** on one action (5 pairs): Mark as done (GOV-07/08), board assessment (GOV-57/58), voting rules (GOV-79/80, GOV-81/82), CEO report submit (GOV-24/25).
- **Whole workflows rendered as inline pages** where a modal is expected: hub "Start with a purpose" create flow (Appendix A).
- **Two surfaces for the same record** (page and dialog): `EventBody` (hub events), `SafeguardingConcernDialog` (rendered over a blank AppLayout at `/safeguarding/{id}`), `HubAnnouncementDialog` (dialog code path never used).

## Legend

- **Footer A** (Add-Client reference): completeness `Ring` in `footerStart`; Back, Cancel and Continue/submit in `footerEnd`.
- **Footer B**: Back (ghost, only after step 1) in `footerStart`; Cancel (outline) and Continue/submit in `footerEnd`. Used by all Governance, Compliance and Privacy wizards, Add H&S representative and the RA wizard.
- **Footer C**: Back (outline) or Cancel (ghost) in `footerStart`; Continue/Save in `footerEnd`. Used by `HsFormWizard`.
- **Footer D**: Back, Cancel (ghost) and Continue all in `footerEnd`, nothing in `footerStart`.
- **No dirty-close guard**: closing a wizard with unsaved input asks nothing.
- **Token widths**: 480 (confirm/detail), 720 (standard), 900 (long form), 1100 (multi-tab) px, set with the inline `style` form from POPUP_STYLE_GUIDE.
- **Hub sheet recipe**: see the Company hub section intro.
- "Opened from" names the button, menu item or page that opens the pop-up; "row menu" means the row kebab and the right-click context menu (they carry the same items).

## Governance

Governance is the most disciplined module: every wizard uses `WizardShell` with Footer B and a discard guard (shared `DiscardDraftDialog` in 9 wizards, a local copy in 3, a ConfirmDialog in 1), most simple dialogs use the inline-style width pattern and a lucide icon in the title, and confirmations use `ConfirmDialog` with `variant="default"`. Deviations are listed per row.

### Governance › Board members

| # | Title the user sees | Type | Steps/sections | Opened from | File:line | Inconsistencies |
|---|---|---|---|---|---|---|
| GOV-01 | Appoint board member / Edit board appointment | Wizard | Person and role → Term → Review | Board members page: "Appoint member" (header and empty state); row action Edit | pages/Governance/Admin/_dialogs.tsx:304 | Footer B; custom `maxHeight` 640; closes through shared Discard dialog (GOV-93) |
| GOV-02 | Remove this person from the board? | Destructive confirm | n/a | Board members row action "Remove" | pages/Governance/Admin/BoardMembers.tsx:372 | None (ConfirmDialog, destructive) |

### Governance › Actions

| # | Title the user sees | Type | Steps/sections | Opened from | File:line | Inconsistencies |
|---|---|---|---|---|---|---|
| GOV-03 | Update progress | Form dialog | How far along (slider), what has happened so far | Action detail: "Update progress" | pages/Governance/Actions/_dialogs.tsx:81 (shell), :184 (title) | Native `<input type="range">` (:200); local `ActionDialogShell` copies the standard shell (720 px) |
| GOV-04 | Mark as blocked | Form dialog | What is stopping the work | Action detail: "Mark as blocked" | pages/Governance/Actions/_dialogs.tsx:81, :290 | Shares local `ActionDialogShell` |
| GOV-05 | Raise with the board | Form dialog | Why the board needs to look at this | Action detail: "Raise with the board" | pages/Governance/Actions/_dialogs.tsx:81, :378 | Shares local `ActionDialogShell` |
| GOV-06 | Change owner | Form dialog | New owner (select) | Action detail: "Change owner" | pages/Governance/Actions/_dialogs.tsx:81, :479 | Shares local `ActionDialogShell` |
| GOV-07 | Mark as done | Form dialog | What was done, evidence upload (inline ActionEvidencePanel) | Action detail: "Mark as done" (hero and body) | pages/Governance/Actions/_dialogs.tsx:81, :623 | Stacks a second confirm (GOV-08) on top; inline evidence panel with file list and per-file remove |
| GOV-08 | Mark this action as done? | Confirm | n/a | Submit button of GOV-07 | pages/Governance/Actions/_dialogs.tsx:689 | Double confirmation (form dialog then confirm with the same wording) |
| GOV-09 | Remove this file? | Destructive confirm | n/a | Evidence panel: trash icon on a file | pages/Governance/Actions/_evidence.tsx:256 | None |

### Governance › Audit log

| # | Title the user sees | Type | Steps/sections | Opened from | File:line | Inconsistencies |
|---|---|---|---|---|---|---|
| GOV-10 | What changed | Detail viewer | Sentence, before/after, link to record | Audit log table row click | pages/Governance/AuditLog/Index.tsx:151 | No icon in title; width 620 px is not a token (480/720/900/1100) and only `maxWidth` set; footer is Open record + Close |
| GOV-11 | Date range filter (From / To) | Picker (popover form) | From date, To date; Clear and Show these dates | Page header filter chip "Filter by date" | pages/Governance/AuditLog/Index.tsx:472 | Native `<input type="date">`; popover used as a mini form (w-64) |

### Governance › Budgets

| # | Title the user sees | Type | Steps/sections | Opened from | File:line | Inconsistencies |
|---|---|---|---|---|---|---|
| GOV-12 | New budget / Edit budget | Wizard | Budget → Budget lines → Total → Review | Budgets index "New budget"; Budget detail "Edit budget" | pages/Governance/Budgets/_dialogs.tsx:485 | Footer B |
| GOV-13 | Add a budget line / Edit budget line | Form dialog | Category, account code, description, amount budgeted, forecast, notes | Budget detail: "Add a budget line" (empty state and table); row menu Edit | pages/Governance/Budgets/Show.tsx:1815 | No icon in title; width 560 px not a token; dialogs defined inline inside a 2,500-line page |
| GOV-14 | Record actual spend | Form dialog | Actual amount per line | Budget detail: "Record actual spend" | pages/Governance/Budgets/Show.tsx:1978 | No icon; width 560 px not a token |
| GOV-15 | Request a budget change | Form dialog | Budget line, change, amount, why | Budget detail: "Request a budget change"; row menu | pages/Governance/Budgets/Show.tsx:2099 | No icon; width 600 px not a token |
| GOV-16 | Decline this budget change? | Form dialog | Reason for declining | Change-request row menu "Decline" | pages/Governance/Budgets/Show.tsx:2245 | No icon; width 520 px not a token; destructive confirm built as a form dialog (not ConfirmDialog) |
| GOV-17 | Add a monthly amount | Form dialog | Month, site, budget line, amount | Row menu "Add monthly amount" | pages/Governance/Budgets/Show.tsx:2342 | No icon; width 560 px not a token |
| GOV-18 | Send this budget to the board? / Send the updated budget to the board? | Confirm | n/a | Budget detail "Send to the board" | pages/Governance/Budgets/Show.tsx:1551 | None |
| GOV-19 | Record the board's approval? | Confirm | n/a | Budget detail "Record the board's approval" | pages/Governance/Budgets/Show.tsx:1568 | None |
| GOV-20 | Return this budget to drafting? | Confirm | n/a | Budget detail "Return to drafting" | pages/Governance/Budgets/Show.tsx:1577 | None |
| GOV-21 | Remove this budget line? | Destructive confirm | n/a | Line row menu "Remove" | pages/Governance/Budgets/Show.tsx:1588 | None |
| GOV-22 | Approve this budget change? / Record the board's approval? | Confirm | n/a | Change-request row menu "Approve" | pages/Governance/Budgets/Show.tsx:1606 | Title swaps with `needs_board` |
| GOV-23 | Remove this monthly amount? | Destructive confirm | n/a | Monthly-amount row menu "Remove" | pages/Governance/Budgets/Show.tsx:1639 | None |

### Governance › CEO reports

| # | Title the user sees | Type | Steps/sections | Opened from | File:line | Inconsistencies |
|---|---|---|---|---|---|---|
| GOV-24 | New CEO report / Edit CEO report | Wizard | Meeting and period → Executive summary → Operations and people → Finance and risk → Strategy and decisions → Matters arising → Attachments → Review | CEO reports index "New report"; report detail "Edit report" | pages/Governance/CeoReports/_dialogs.tsx:724 | Footer B; custom `maxHeight` 820; 8 steps (longest wizard in Governance); embeds a Submit confirm (GOV-25) |
| GOV-25 | Submit your report to the board? | Confirm | n/a | Wizard review step "Submit to board" (_dialogs.tsx:1134); report detail "Submit to board" (Show.tsx:742) | pages/Governance/CeoReports/_dialogs.tsx:1134, pages/Governance/CeoReports/Show.tsx:742 | Same dialog duplicated in two files (identical wording) |
| GOV-26 | Mark this report as presented? | Confirm | n/a | Report detail "Mark as presented" | pages/Governance/CeoReports/Show.tsx:751 | None |

### Governance › Clinical

| # | Title the user sees | Type | Steps/sections | Opened from | File:line | Inconsistencies |
|---|---|---|---|---|---|---|
| GOV-27 | Medication error target | Form dialog | Monthly target (number) | Clinical dashboard, HCG-001 card: "Set / Change organisation target" | pages/Governance/Clinical/_medication-target.tsx:42 | Tailwind `sm:max-w-md` (guide says inline style); no icon in title |

### Governance › Compliance register

| # | Title the user sees | Type | Steps/sections | Opened from | File:line | Inconsistencies |
|---|---|---|---|---|---|---|
| GOV-28 | Add requirement / Edit requirement | Wizard | Requirement → Details → Schedule → Review | Compliance index "Add requirement"; requirement detail "Edit" | pages/Governance/Compliance/_dialogs.tsx:505 | Footer B; has its own Discard dialog (GOV-29) rather than the shared one |
| GOV-29 | Discard this draft? | Destructive confirm | n/a | Closing GOV-28 with unsaved input | pages/Governance/Compliance/_dialogs.tsx:884 | Hand-rolled Dialog duplicates shared `DiscardDraftDialog`; no icon |
| GOV-30 | Upload evidence | Form dialog | Evidence kind (tile picker), title, valid-until date, file dropzone | Requirement detail "Upload evidence" | pages/Governance/Compliance/_dialogs.tsx:959 | None (uses FileDropzone) |
| GOV-31 | Mark this requirement as done? | Form dialog | How it was met, evidence list | Requirement detail "Mark as done" | pages/Governance/Compliance/_dialogs.tsx:1181 | Title phrased as a question but is a full form; near-duplicate of Compliance wizards (CMP-03 Complete obligation) |

### Governance › Documents

| # | Title the user sees | Type | Steps/sections | Opened from | File:line | Inconsistencies |
|---|---|---|---|---|---|---|
| GOV-32 | Upload document | Form dialog | Title, document type, description, file | Documents index "Upload document" | pages/Governance/Documents/_dialogs.tsx:74 | None |
| GOV-33 | Remove this document? | Destructive confirm | n/a | Document detail "Remove" | pages/Governance/Documents/Show.tsx:190 | None |

### Governance › Evaluations

| # | Title the user sees | Type | Steps/sections | Opened from | File:line | Inconsistencies |
|---|---|---|---|---|---|---|
| GOV-34 | New board evaluation / Edit board evaluation | Wizard | Details → Period → Questions → Review | Evaluations index "New evaluation"; detail "Edit draft" | pages/Governance/Evaluations/_dialogs.tsx:362 | Footer B |
| GOV-35 | Close this evaluation? / Open this evaluation for responses? | Confirm | n/a | Evaluation detail "Close evaluation" / "Open for responses" | pages/Governance/Evaluations/Show.tsx:574 | One ConfirmDialog, two states |

### Governance › Interests

| # | Title the user sees | Type | Steps/sections | Opened from | File:line | Inconsistencies |
|---|---|---|---|---|---|---|
| GOV-36 | Declare an interest / Update this declaration | Form dialog | Kind of interest (tile picker), organisation or person, role, how it could affect decisions, start date, ended? | Interests index and My interests: "Declare an interest"; row menu Edit | pages/Governance/Interests/_dialogs.tsx:126 | Native `<input type="date">`; same dialog mounted from two pages |
| GOV-37 | This interest has ended | Form dialog | When did it end (date) | Row menu "End" (Interests and My interests) | pages/Governance/Interests/_dialogs.tsx:327 | Title is a statement, not a question; native date input |
| GOV-38 | Interest details (title = organisation or nature) | Detail viewer | Read-only summary | Row click (Interests and My interests) | pages/Governance/Interests/_dialogs.tsx:413 | None |

### Governance › Meetings

| # | Title the user sees | Type | Steps/sections | Opened from | File:line | Inconsistencies |
|---|---|---|---|---|---|---|
| GOV-39 | Schedule meeting / Edit meeting | Wizard | Meeting type → Purpose → When and where → Chair and quorum → Review | Meetings index "Schedule meeting"; Calendar page slot; meeting detail "Edit meeting" | pages/Governance/Meetings/_dialogs.tsx:553 | Footer B |
| GOV-40 | Cancel this meeting? | Destructive confirm | n/a | Saving the edit wizard with status set to cancelled | pages/Governance/Meetings/_dialogs.tsx:935 | Confirm launched from inside a wizard |
| GOV-41 | Reply to the meeting invitation / Change your reply | Form dialog | Will you be there, apology reason, dietary or access needs | Meeting detail "Reply to the invitation" | pages/Governance/Meetings/_workspace-dialogs.tsx:122 | None |
| GOV-42 | Record attendance | Form dialog | Per-member attendance and apology reasons | Meeting detail "Record attendance" | pages/Governance/Meetings/_workspace-dialogs.tsx:277 | None |
| GOV-43 | Add an agenda item | Form dialog | Title, kind, what it is about, time needed, presenter | Meeting detail "Add agenda item" | pages/Governance/Meetings/_workspace-dialogs.tsx:440 | None |
| GOV-44 | Write the minutes / Edit the minutes (version N) | Form dialog | Heading sections (add and remove), text per section | Meeting detail "Write the minutes" / "Edit minutes" | pages/Governance/Meetings/_workspace-dialogs.tsx:596 | 900 px "long" token; long form kept as a dialog (guide would call for a wizard or page) |
| GOV-45 | Sign the minutes? | Confirm | InfoCard disclaimer only | Meeting detail "Sign minutes" | pages/Governance/Meetings/_workspace-dialogs.tsx:726 | Hand-rolled Dialog for a no-field confirm (not ConfirmDialog) |
| GOV-46 | Start a correction? | Form dialog | What needs correcting (reason) | Meeting detail "Start a correction" | pages/Governance/Meetings/_workspace-dialogs.tsx:802 | None |
| GOV-47 | Remove this agenda item? | Destructive confirm | n/a | Agenda item row "Remove" | pages/Governance/Meetings/Show.tsx:1789 | None |
| GOV-48 | Send the minutes for approval? / Approve these minutes? / Archive these minutes? | Confirm | n/a | Meeting detail "Send for approval", "Approve minutes", "Archive minutes" | pages/Governance/Meetings/Show.tsx:1831 | One ConfirmDialog, three states |
| GOV-49 | Meeting date range filter | Picker (popover form) | From date, To date; Clear and Show these dates | Meetings index header filter chip | pages/Governance/Meetings/Index.tsx:407 | Near-duplicate of GOV-11; native date inputs |

### Governance › My work

| # | Title the user sees | Type | Steps/sections | Opened from | File:line | Inconsistencies |
|---|---|---|---|---|---|---|
| GOV-50 | Your vote is recorded / Record of completion | Detail viewer | Receipt: what, when, vote, paper version, receipt id | My work completed row: "View record", row click, right-click "View record of completion" | pages/Governance/MyWork/Index.tsx:748 | Width 520 px not a token; footer has Copy receipt, link and Close |

### Governance › Board packs

| # | Title the user sees | Type | Steps/sections | Opened from | File:line | Inconsistencies |
|---|---|---|---|---|---|---|
| GOV-51 | Generate a board pack | Form dialog | Choose a meeting | Packs index "Generate board pack" (header and empty state) | pages/Governance/Packs/_dialogs.tsx:66 | None |
| GOV-52 | Confirm you've read the pack? | Confirm | n/a | Pack detail "I've read this pack" | pages/Governance/Packs/Show.tsx:871 | None |
| GOV-53 | Send the pack to N board members? | Confirm | n/a | Pack detail "Send the pack" | pages/Governance/Packs/Show.tsx:880 | None |
| GOV-54 | Create version N? | Confirm | n/a | Pack detail "Create new version" | pages/Governance/Packs/Show.tsx:889 | None |

### Governance › Performance reviews

| # | Title the user sees | Type | Steps/sections | Opened from | File:line | Inconsistencies |
|---|---|---|---|---|---|---|
| GOV-55 | New performance review | Wizard | Who → Review period → Review | Performance index "New review" | pages/Governance/Performance/_dialogs.tsx:307 | Footer B |
| GOV-56 | Send your self-assessment to the board? | Confirm | n/a | Review detail self-assessment card "Send" | pages/Governance/Performance/Show.tsx:862 | None |
| GOV-57 | The board's assessment | Form dialog | Overall rating, board decision, overall assessment, notes, per-goal score and comments | Review detail "Board assessment" | pages/Governance/Performance/Show.tsx:977 | No icon; width 860 px not a token; long form in a dialog; stacks a second confirm (GOV-58) |
| GOV-58 | Save the board's assessment? | Confirm | n/a | Save button of GOV-57 | pages/Governance/Performance/Show.tsx:1169 | Nested confirm over a dialog |
| GOV-59 | Complete this review? | Form dialog | Optional linked resolution | Review detail "Complete review" | pages/Governance/Performance/Show.tsx:1217 | No icon; width 540 px not a token |

### Governance › Policies

| # | Title the user sees | Type | Steps/sections | Opened from | File:line | Inconsistencies |
|---|---|---|---|---|---|---|
| GOV-60 | New policy / Edit policy / Start new version | Wizard | Details → Policy content → Dates and confirming → Review | Policies index "New policy"; detail "Edit", "Start new version" | pages/Governance/Policies/_dialogs.tsx:483 | Footer B; rail title is the dialog title |
| GOV-61 | Discard this draft? | Destructive confirm | n/a | Closing GOV-60 with unsaved input | pages/Governance/Policies/_dialogs.tsx:850 | Uses ConfirmDialog while sibling wizards use shared DiscardDraftDialog (4 variants of the same prompt) |
| GOV-62 | Approve and publish this policy? | Confirm | n/a | Policy detail "Approve" | pages/Governance/Policies/Show.tsx:687 | None |

### Governance › Resolutions

| # | Title the user sees | Type | Steps/sections | Opened from | File:line | Inconsistencies |
|---|---|---|---|---|---|---|
| GOV-63 | New resolution / Edit resolution | Wizard | Title and meeting → Wording and purpose → Options → Effects → How it passes → Review (steps vary by resolution type) | Resolutions index "New resolution"; resolution detail "Edit resolution"; Meeting detail "New resolution" (NewResolutionDialog wrapper) | pages/Governance/Resolutions/_dialogs.tsx:1087 | Footer B; 2,374-line file; step list changes per type |
| GOV-64 | Open voting? | Confirm | n/a | Resolution detail "Publish & open voting" | pages/Governance/Resolutions/Show.tsx:1153 | None |
| GOV-65 | Publish to board members? | Confirm | n/a | Resolution detail "Publish" | pages/Governance/Resolutions/Show.tsx:1165 | None |
| GOV-66 | Close voting? | Confirm | n/a | Resolution detail "Close voting" | pages/Governance/Resolutions/Show.tsx:1180 | None |
| GOV-67 | Mark as done? | Confirm | n/a | Resolution detail "Mark as done" | pages/Governance/Resolutions/Show.tsx:1195 | None |
| GOV-68 | Archive this resolution? | Confirm | n/a | Resolution detail "Archive" | pages/Governance/Resolutions/Show.tsx:1215 | Archive worded as a non-destructive confirm |
| GOV-69 | Cast your vote? | Confirm | n/a | Ballot "Cast vote" (resolution detail and meeting paper workspace) | components/governance/ResolutionBallot.tsx:434 | None |
| GOV-70 | Tell the board about a conflict of interest / Update your conflict of interest | Form dialog | Kind of conflict, what the conflict is, step aside from voting / discussion (checkboxes) | Ballot "Declare a conflict of interest" (resolution detail, meeting paper workspace) | components/governance/DeclareConflictDialog.tsx:79 | None |

### Governance › Risks

| # | Title the user sees | Type | Steps/sections | Opened from | File:line | Inconsistencies |
|---|---|---|---|---|---|---|
| GOV-71 | Add risk / Edit risk | Wizard | The risk → How serious → Response → Review | Risks index "Add risk"; risk detail "Edit risk" | pages/Governance/Risks/_dialogs.tsx:531 | Footer B; own Discard dialog (GOV-72) |
| GOV-72 | Discard this draft? | Destructive confirm | n/a | Closing GOV-71 with unsaved input | pages/Governance/Risks/_dialogs.tsx:893 | Hand-rolled Dialog duplicates shared `DiscardDraftDialog`; no icon |
| GOV-73 | Add an action to reduce this risk | Form dialog | Action, who will do it, due date, expected score reduction | Risk detail "Add action" | pages/Governance/Risks/_dialogs.tsx:942 | None |
| GOV-74 | Accept this risk? | Form dialog | Board resolution, why the board accepts it, conditions, accepted-for months | Risk detail "Accept risk" | pages/Governance/Risks/_dialogs.tsx:1170 | None |
| GOV-75 | Close this risk? | Form dialog | Why it is being closed | Risk detail "Close risk" | pages/Governance/Risks/_dialogs.tsx:1419 | Width 560 px not a token |
| GOV-76 | Mark this action as done? | Form dialog | What was done (optional) | Treatment row action | pages/Governance/Risks/_dialogs.tsx:1516 | Width 560 px not a token; near-duplicate of Actions "Mark as done" (GOV-07) |
| GOV-77 | Change the due date | Form dialog | New due date, why (optional) | Treatment row action | pages/Governance/Risks/_dialogs.tsx:1610 | Native date input |
| GOV-78 | How risk scores work | Other (info popover) | Three short explainer paragraphs | Risks page buttons "How risk scores work" | pages/Governance/Risks/_shared.tsx:213 | Popover used for static help text (w-80) |

### Governance › Settings (voting rules)

| # | Title the user sees | Type | Steps/sections | Opened from | File:line | Inconsistencies |
|---|---|---|---|---|---|---|
| GOV-79 | Record the board's approval of these voting rules | Form dialog | Date approved, meeting (optional), where it is recorded | Settings page: voting rules card (record approval button) | pages/Governance/Settings/_dialogs.tsx:81 | None |
| GOV-80 | Switch on board voting? | Confirm | n/a | Submit of GOV-79 | pages/Governance/Settings/_dialogs.tsx:277 | Nested confirm over a dialog |
| GOV-81 | Switch on the changed voting rules | Form dialog | Resolution that approves the rules | Settings: "Use a passed resolution instead" / activate rules | pages/Governance/Settings/_dialogs.tsx:310 | None |
| GOV-82 | Switch on the new voting rules? | Confirm | n/a | Submit of GOV-81 | pages/Governance/Settings/_dialogs.tsx:454 | Nested confirm over a dialog |

### Governance › Spend approvals

| # | Title the user sees | Type | Steps/sections | Opened from | File:line | Inconsistencies |
|---|---|---|---|---|---|---|
| GOV-83 | New spend request / Edit spend request | Wizard | Request → Amount → Reason → Review | Spend approvals index "New request"; detail "Edit request" | pages/Governance/SpendApprovals/_dialogs.tsx:331 | Footer B |
| GOV-84 | Send this request for a decision? | Confirm | n/a | Request detail "Send for a decision" | pages/Governance/SpendApprovals/Show.tsx:489 | None |
| GOV-85 | Approve this spend request? / Decline this spend request? | Form dialog | Board resolution, reason for your decision | Request detail "Approve" / "Decline" | pages/Governance/SpendApprovals/Show.tsx:607 | No icon; width 560 px not a token; confirm-style title but a form |
| GOV-86 | Who approves what | Other (info popover) | Spend limits per category | Spend approvals header "Who approves what" | pages/Governance/SpendApprovals/Index.tsx:185 | Popover for static help (w-96) |

### Governance › Strategy

| # | Title the user sees | Type | Steps/sections | Opened from | File:line | Inconsistencies |
|---|---|---|---|---|---|---|
| GOV-87 | New strategic plan / Edit strategic plan | Wizard | Plan → Direction → Values → Goals → Review | Strategy index "New plan"; plan detail "Edit plan" and "Edit goals" (opens on that step) | pages/Governance/Strategy/_dialogs.tsx:586 | Footer B |
| GOV-88 | Record board approval | Form dialog | Resolution that approved the plan | Plan detail "Record board approval" | pages/Governance/Strategy/Show.tsx:931 | No icon; width 520 px not a token |
| GOV-89 | Create version N | Form dialog | Why a new version is needed | Plan detail "Create version N" | pages/Governance/Strategy/Show.tsx:1023 | No icon; width 520 px not a token |

### Governance › Te Tiriti

| # | Title the user sees | Type | Steps/sections | Opened from | File:line | Inconsistencies |
|---|---|---|---|---|---|---|
| GOV-90 | Add commitment / Edit commitment | Wizard | Principle → Commitment → Evidence → Review | Te Tiriti index "Add commitment"; edit from detail dialog | pages/Governance/TeTiriti/_dialogs.tsx:455 | Footer B; own Discard dialog (GOV-91) |
| GOV-91 | Discard this draft? | Destructive confirm | n/a | Closing GOV-90 with unsaved input | pages/Governance/TeTiriti/_dialogs.tsx:716 | Hand-rolled Dialog duplicates shared `DiscardDraftDialog`; no icon |
| GOV-92 | Commitment detail (title = commitment) | Detail viewer | Status, description, owner, evidence, target date | Te Tiriti index card or row click | pages/Governance/TeTiriti/_dialogs.tsx:765 | Title builds its own icon tile (`rounded-[10px] bg-primary/15`) instead of plain lucide icon |

### Governance › Shared components

| # | Title the user sees | Type | Steps/sections | Opened from | File:line | Inconsistencies |
|---|---|---|---|---|---|---|
| GOV-93 | Discard this draft? / Discard your changes? | Destructive confirm | n/a | Closing any Governance wizard with unsaved input (Admin, Budgets, CEO reports, Evaluations, Meetings, Performance, Resolutions, Spend approvals, Strategy) | components/governance/DiscardDraftDialog.tsx:37 | Reference implementation; `frontline` variant also exists |
| GOV-94 | Remove attachment? | Destructive confirm | n/a | Attachments panel trash icon on a file (Spend approvals, CEO reports, Risks, Resolutions, Packs detail pages) | components/governance/GovernanceAttachmentsPanel.tsx:348 | Hand-rolled AlertDialog inside each file row (no ConfirmDialog); button reads "Remove" |
| GOV-95 | Term hint (title = glossary term) | Other (info popover) | Term and definition | Dotted-underline terms or "?" icon next to Governance terms | components/governance/GovernanceTermHint.tsx:86 | Popover for static help (w-72), used app-wide in Governance |
| GOV-96 | Why this matters | Other (info popover) | One paragraph | Cockpit priority card "Why this matters" | components/governance/BoardPriorityCard.tsx:185 | Popover for static help; trigger is a raw `<button>` |

## Company hub

The Company hub is the least standard module. It has its own design language (warm conversational titles such as "A little more you", uppercase eyebrow labels, 28 px gutters `px-7`, rounded-2xl cards) and almost none of its overlays use the shared shells: sheets are restyled (wider, lighter backdrop, no standard header), confirmations are hand-rolled `Dialog`s with a primary button even for destructive actions, and the only wizard-like flow (Share an idea) draws its own stepper. Shared hub conventions (so rows can stay short):

- **Hub sheet recipe** (HUB-02 to HUB-05, HUB-07 to HUB-09): `SheetContent side="right"` with `p-0 gap-0 overflow-hidden sm:max-w-[600px]` (640 px for HUB-07), `overlayClassName="bg-black/30 backdrop-blur-[2px]"` (standard is black/80), custom `closeButtonClassName`, hand-rolled `SheetHeader` (`px-7 pt-7 pr-14 pb-6 border-b`, optional uppercase eyebrow, `text-2xl` title, no icon in title), scroll body `px-7 py-6`, own footer band (`SheetFooter` with `justify-between`: ghost Cancel pinned left, primary Save pinned right, instead of right-aligned Cancel then primary), and an inline "Discard unsaved changes?" band inside the sheet. Standard sheet is `sm:max-w-sm` with default padding.
- **Leave guard**: most hub overlays also render the shared leave-confirmation ConfirmDialog (HUB-28) for route changes.

### Company hub › Personal settings (opened from the hub header user menu)

| # | Title the user sees | Type | Steps/sections | Opened from | File:line | Inconsistencies |
|---|---|---|---|---|---|---|
| HUB-01 | Birthday sharing | Form dialog | Two panes: colleague search list and per-person sharing choice; each preference saved individually | Hub header user menu "Birthday settings" | pages/company-hub/_birthday-settings.tsx:229 (Dialog), :235 (content) | Hand-rolled chrome: `p-0 gap-0` content, uppercase eyebrow "Celebrating our people", `px-7` header and `DialogFooter` with `border-t`; width 800 px not a token; no icon in title; settings-style dialog (not a SettingsModal) |
| HUB-02 | Your useful shortcuts | Side sheet | Shortcut list editor (label, link, order); inline discard band | Hub header user menu "My shortcuts"; shortcuts card "Edit" | pages/company-hub/_shortcuts.tsx:192 | Hub sheet recipe (600 px, lighter backdrop, hand-rolled header/footer, inline discard band); Footer Cancel (ghost, left) and Save shortcuts (right) |
| HUB-03 | Follow your interests | Side sheet | Topics (choice chips), Teams (choice chips); inline discard band | Updates page "Manage interests" / "Choose interests"; link inside the notification sheet (HUB-04) | pages/company-hub/_interests.tsx:320 | Hub sheet recipe; footer is Cancel (ghost) and Save interests |
| HUB-04 | A useful amount of staying in touch | Side sheet | Grouped switch cards of Hub update types | Hub header user menu "Notification preferences" | pages/company-hub/_notification-preferences.tsx:244 | Hub sheet recipe; icon tile + uppercase eyebrow "Your Company Hub" in header; each option is a custom bordered label card (`min-h-[90px]`, icon tile) wrapping a `Switch`; inline discard band in footer |
| HUB-05 | A little more you / Preview your profile | Side sheet | About (introduction), skills; preview mode | Person profile page "Edit shared profile" | pages/company-hub/_person-profile.tsx:179 | Hub sheet recipe; title toggles to preview inside the same sheet |
| HUB-06 | Discard profile changes? / Reload your current profile? | Destructive confirm | n/a | Close or reload inside HUB-05 | pages/company-hub/_person-profile.tsx:449 | Hand-rolled `Dialog` (not ConfirmDialog); confirm button is the default primary style although it discards; no icon |

### Company hub › Reading and discussion

| # | Title the user sees | Type | Steps/sections | Opened from | File:line | Inconsistencies |
|---|---|---|---|---|---|---|
| HUB-07 | A conversation worth joining | Side sheet | Tabs: Discussion, Hidden comments (moderators); comment thread and composer | Story page discussion card: "Join the discussion" / "View discussion" / "Continue writing" | pages/company-hub/_discussion.tsx:320 | Hub sheet recipe at 640 px; uppercase eyebrow "Story discussion"; comment composer with its own dirty guard; opens automatically from `#hub-conversation` hash |
| HUB-08 | Help keep this resource useful | Side sheet | Report an issue form; "Your reports" history view | Resource page "Report an issue", "Your reports" | pages/company-hub/_resource-reports.tsx:168 | Hub sheet recipe; icon in header; two views inside one sheet |
| HUB-09 | Review a correction / Reopen a correction | Side sheet | Response, status, review date; inline discard band | Resource care list: review a reported correction | pages/company-hub/_resource-review.tsx:151 | Hub sheet recipe; title swaps by state |
| HUB-10 | Share an idea / Review your idea / Thank you for sharing it | Wizard (hand-rolled) | Write → Review and share → Thank-you (own numbered `ol` stepper in header) | Ideas board "Share an idea" | pages/company-hub/_idea-composer.tsx:171 (Dialog), :177 (content) | Not `WizardShell`: own stepper, own header close button (`showCloseButton={false}`), `rounded-2xl p-0`, tinted header, numeric `maxWidth: 720`; success pane re-implemented; discard handled by an inline band, not a dialog |
| HUB-11 | Idea detail (title = the idea) | Detail viewer | Idea body, support button, progress, discussion thread, moderator progress editor | Ideas board: click an idea (also deep link `?idea=`) | pages/company-hub/_community.tsx:366 (Dialog), :372 (content) | No icon in title; numeric `maxWidth: 720`; long scrolling thread inside a dialog; acts as a form (moderator editor) and a viewer |
| HUB-12 | A small thank-you can make someone's day. | Detail viewer | Sample shout-out and explanation (demo showcase) | Recognition "Say thanks" actions while the hub is in demo mode | pages/company-hub/index.tsx:1435 | Marketing-style dialog with a primary-tinted sample card; default `DialogContent` width; single "Back to the showcase" button |

### Company hub › Page editor and designer

| # | Title the user sees | Type | Steps/sections | Opened from | File:line | Inconsistencies |
|---|---|---|---|---|---|---|
| HUB-13 | Draft and design options (steps: Start from a page layout / Discard the saved draft? / Recover the discarded draft?) | Other (multi-mode options dialog) | Options list (Make a copy, Starting layout, Saved draft) that switches to a reset, discard or recover step | Page editor "Draft options" and "Review recovery options" | pages/company-hub/_draft-tools.tsx:136 (Dialog), :142 (content) | Title and body change per internal step; Tailwind `sm:max-w-2xl`; embeds confirm steps instead of using ConfirmDialog; no icon in title |
| HUB-14 | Duplicate this page / Your private copy is ready | Form dialog | Choose version to copy (draft or published radio cards), what is copied | Draft options › "Duplicate page" | pages/company-hub/_duplicate-page.tsx:174 | Tailwind `sm:max-w-xl`; native radio inputs as cards; footer has up to three buttons incl. "Save latest changes" |
| HUB-15 | A few useful shortcuts | Other (help dialog) | List of drag, resize, keyboard and undo tips | Page editor "Designer shortcuts" | pages/company-hub/_designer-tools.tsx:144 | No footer; single "Got it" button placed in body; no icon in title |
| HUB-16 | Remove {section}? | Destructive confirm | n/a | Designer: trash icon on a section | pages/company-hub/_designer.tsx:279 | Hand-rolled `Dialog` (not ConfirmDialog); buttons "Keep section" and "Remove section and blocks" (non-standard verbs); no icon |
| HUB-17 | Keep your latest changes? | Alert (3-way) | Keep editing, Leave without changes, Save and leave | Leaving the page editor or collections editor with unsaved changes (route or history back) | pages/company-hub/_editor-leave.tsx:145 | Hand-rolled 3-way dialog (ghost, outline, primary order); duplicates the shared leave guard (HUB-28) for the same situation |
| HUB-18 | Archive this page? | Destructive confirm | n/a | Page editor "Archive page" | pages/company-hub/editor.tsx:2148 | Hand-rolled `Dialog`; "Archive page" is a primary button; "Keep page" cancel wording |
| HUB-19 | Discard this hero preview? | Destructive confirm | n/a | Hero workspace "Cancel" with unapplied preview changes | pages/company-hub/_hero-workspace.tsx:200 | Hand-rolled `Dialog`; "Discard preview" is a primary button |
| HUB-20 | Event detail (title = event name) | Detail viewer | Event story, attachments, registration roster, invitation aside with RSVP, date chooser; inline "Cancel this event?" band | Page editor preview: click an event in an events block | pages/company-hub/_dialogs.tsx:369 (Dialog), :373 (content) | Width `min(94vw, 1100px)` set inline (guide token is 92vw); eyebrow plus `text-2xl` title; contains an inline hand-rolled confirm band (`_event-cancellation.tsx:228`) rather than a dialog; same `EventBody` is also used as a full page |
| HUB-21 | Event photo (title = photo title) | Detail viewer | Photo, caption, details; arrow-key paging | Event story gallery: click a photo | pages/company-hub/_event-gallery.tsx:120 (Dialog), :126 (content) | Lightbox built from `DialogContent p-0`; width 1040 px not a token; custom `onKeyDown` paging; not the shared FilePreviewDialog |

### Company hub › Media library

| # | Title the user sees | Type | Steps/sections | Opened from | File:line | Inconsistencies |
|---|---|---|---|---|---|---|
| HUB-22 | Choose from the media library | Picker | Library grid, albums, file details with crop and description, choose | Block editor, block content settings and hero workspace: "Media library" button next to an image or file field | pages/company-hub/_media-picker.tsx:185 | Title is `sr-only`; custom CSS class `.hub-media-library-dialog` with `max-width: none !important`, 1120 px and 30 px padding; contains the whole MediaLibrary workspace; blocks Esc and outside click while dirty |
| HUB-23 | New album | Form dialog | Album name | Media library "New album" (also in the Editorial › Media tab) | pages/company-hub/_media-library.tsx:583 | Default width; no icon; no Cancel button (single "Create album" button in the body, no `DialogFooter`) |
| HUB-24 | Upload to the hub | Form dialog | Page that owns the file, FileDropzone, per-file progress | Media library "Upload" | pages/company-hub/_media-library.tsx:636 | Default width; no icon; no footer buttons at all (upload starts on drop) |

### Company hub › Navigation and documents

| # | Title the user sees | Type | Steps/sections | Opened from | File:line | Inconsistencies |
|---|---|---|---|---|---|---|
| HUB-25 | Publish the shared navigation? / Reload the latest menu? / Discard navigation changes? | Confirm (3 modes) | n/a | Navigation designer: "Publish navigation", reload, "Discard navigation draft" | pages/company-hub/_navigation.tsx:1014 | Hand-rolled `Dialog`; "Discard draft" uses the default primary style; "Keep editing" cancel |
| HUB-26 | Navigation group menu (title = group label) | Other (navigation flyout popover) | Child pages with description and unread dot | Hub navigation rail: click a group tab | pages/company-hub/_navigation.tsx:205 | Popover used as a menu with `Button ghost` rows (should be DropdownMenu); `w-80 p-2` |
| HUB-27 | Publish this document? | Confirm | n/a | Document editor "Publish" | pages/company-hub/document-editor.tsx:992 | None (ConfirmDialog, variant default) |
| HUB-28 | Leave / discard confirmations (17 hub callers, 17 different titles such as "Leave this idea?", "Discard shortcut changes?", "Leave document changes?"; button always "Discard and continue"; full list in Appendix D) | Destructive confirm | n/a | Any route change or browser back while an overlay or editor is dirty (`useSettingsLeaveConfirmation`) | hooks/use-settings-leave-confirmation.tsx:75 (hook); used by pages/company-hub/_birthday-settings.tsx, _community.tsx, _dialogs.tsx, _discussion.tsx, _document-upload.tsx, _idea-composer.tsx, _interests.tsx, _notification-preferences.tsx, _person-profile.tsx, _planning.tsx, _publication-workspace.tsx, _recognition-conversation.tsx, _resource-reports.tsx, _resource-review.tsx, _revision-history.tsx, _shortcuts.tsx, document-editor.tsx | ConfirmDialog with default (destructive) variant for non-destructive "leave" prompts; the same prompt is also rebuilt as an inline band in HUB-02, HUB-03, HUB-04, HUB-09; coexists with HUB-17 |

### Company hub › Recognition and people

| # | Title the user sees | Type | Steps/sections | Opened from | File:line | Inconsistencies |
|---|---|---|---|---|---|---|
| HUB-29 | Remove this spotlight? | Destructive confirm | n/a | Recognition board: "Remove spotlight", "Clear selection" | pages/company-hub/_recognition.tsx:818 | Hand-rolled `Dialog`; "Remove spotlight" is a primary button; two different triggers share one prompt |
| HUB-30 | People & Culture contacts | Detail viewer | Contact cards with email and message actions | Staff services card "People & Culture" (the card without a link) | pages/company-hub/_staff-services.tsx:102 | Tailwind `sm:max-w-2xl` and `max-h-[85vh]`; footer order is Close (ghost) then link button (outline) |
| HUB-31 | Taking it forward (owner picker; label = field label) | Picker (popover with search list) | Command list with name and description, empty text "No matching colleagues." | Idea progress editor: "Taking it forward" owner field | pages/company-hub/_search-select.tsx:70 | Popover plus Command pattern (matches the guide's searchable selector); `min-h-11` rows custom; only one caller |

### Company hub › Opened from the hub but defined elsewhere (not counted here)

| Pop-up | Defined in | Opened from |
|---|---|---|
| EventWizardDialog (create and edit events) | components/hr/calendar/event-wizard-dialog.tsx | pages/company-hub/_event-drafts.tsx:541, _event-workspace.tsx:153 |
| RecognitionWizard (send recognition) | components/recognition/recognition-wizard.tsx | pages/company-hub/index.tsx:1507 |
| TicketDrawer (IT ticket peek) | components/it/ticket-drawer.tsx | pages/company-hub/_services.tsx:280 |
| HubCommandResults (hub group inside the global command palette) | components/company-hub-command-search.tsx | global search; only a `CommandGroup`, not an overlay |

## Health & safety (including worker participation)

H&S is built almost entirely on `WizardShell` (create wizards plus "detail-as-modal" viewers where the rail lists sections). The deviations are: (1) most create wizards close without a discard guard, (2) several near-duplicate wizards exist for the same record (config-driven `HsFormWizard` versus dedicated wizards), (3) several dialogs hand-roll the wizard chrome instead of using `WizardShell` (`PpeWizard` for three PPE wizards, the risk assessment viewer, the lone-worker alert viewer and action modal), (4) small confirmations and one-field actions are built as full 980 px wizard shells (risk assessment approve/archive, four PPE actions), (5) destructive actions use several patterns (in-pane confirm panel, `ArmedButton`, immediate attachment delete, restyled red buttons) and no H&S dialog uses `ConfirmDialog`, and (6) report menus are `Popover`s of raw buttons. Footer layouts are defined in the legend at the top of this file.

### Health & safety › Dashboard and report launcher

| # | Title the user sees | Type | Steps/sections | Opened from | File:line | Inconsistencies |
|---|---|---|---|---|---|---|
| HS-01 | Report a health & safety event | Picker | Nine workflow tiles (incident, hazard, first aid, restraint, drill, injury to return-to-work, substance, lone worker, worker participation) | H&S dashboard hero "Report" | pages/health-safety/components/report-launcher.tsx:112 | Tailwind `max-w-2xl`; no icon in title; tiles are raw `<button>`s (`text-[11px]`) with an eslint-disable; closes then opens a second dialog (launch chain) |
| HS-02 | Record emergency drill | Wizard | Drill → Detail → Review | Launcher tile "Record emergency drill" | pages/health-safety/components/form-wizard.tsx:325 (config wizard-configs.tsx:45) | Generic `HsFormWizard` (Footer C); duplicates dedicated "Schedule drill" (HS-20) with different steps; no dirty-close guard |
| HS-03 | Log restraint event | Wizard | Event → Justification & debrief → Plan & follow-up → Review | Launcher tile "Log restraint event" | pages/health-safety/components/form-wizard.tsx:325 (wizard-configs.tsx:180) | Footer C; duplicates dedicated "Record a restraint event" (HS-24) with 7 steps versus 4; no dirty-close guard |
| HS-04 | Report injury → return-to-work | Wizard | Injury → Treatment & ACC → Review | Launcher tile "Injury → return-to-work" | pages/health-safety/components/form-wizard.tsx:325 (wizard-configs.tsx:320) | Footer C; duplicates dedicated "Record workplace injury" (HS-15); no dirty-close guard |
| HS-05 | Add hazardous substance | Wizard | Substance → Controls → Review | Launcher tile (unreachable: the dashboard routes this key to the dedicated substance wizard) | pages/health-safety/components/wizard-configs.tsx:430 | Dead config (unreachable); duplicate of HS-50; no dirty-close guard |
| HS-06 | Lone-worker check-in | Wizard | Session (single step) | Launcher tile "Lone-worker check-in" | pages/health-safety/components/form-wizard.tsx:325 (wizard-configs.tsx:520) | Title says "check-in" but it starts a session; duplicates "Start lone-worker session" (HS-29); Footer C; no dirty-close guard |
| HS-07 | Log hazard + risk assessment | Wizard | Hazard → Risk assessment → Controls & follow-up → Review | Launcher tile "Log hazard + risk assessment" | pages/health-safety/components/form-wizard.tsx:325 (wizard-configs.tsx:608) | Duplicates dedicated "Log hazard" (HS-12) with different steps; Footer C; no dirty-close guard |
| HS-08 | Add H&S representative | Wizard | Representative → Review | Launcher tile "Worker participation / committee" | pages/health-safety/components/form-wizard.tsx:325 (wizard-configs.tsx:707) | Duplicates dedicated "Add H&S representative" (HS-53) with different steps; Footer C; no dirty-close guard |
| HS-09 | Export board summary | Other (popover menu) | Links to board reports | H&S dashboard hero "Export board summary" | pages/health-safety/components/command-centre-hero.tsx:320 | Popover of `Link`s used as a menu (should be DropdownMenu); raw `<button>` trigger |

The dashboard launcher also opens the incident wizard (INC-01, via `ReportIncidentDialog`) and the substance wizard (HS-50).

### Health & safety › Events and corrective actions

| # | Title the user sees | Type | Steps/sections | Opened from | File:line | Inconsistencies |
|---|---|---|---|---|---|---|
| HS-10 | Event {reference} | Detail viewer | Overview → Handover → Investigation → Corrective actions → Risk → Timeline → Evidence | Events register row click or right-click "View event"; Corrective actions register "View parent event"; `/health-safety/events/{id}` auto-opens it; Sites calendar | components/health-safety/event-detail-dialog.tsx:1015 | 5,300-line file; in-pane workflow forms (WorkSafe decision, notify, verify action, recommendations) with native `<input>`/`<select>` (:1722, :1750, :1904, :2349, :3596) and a hand-rolled file `<input>` (:3401) instead of FileDropzone; destructive actions use a local two-click `ArmedButton` (:3744) |
| HS-11 | Board reports (menu) | Other (popover menu) | Board summary and report links | Events register hero "Board reports" | pages/health-safety/events/index.tsx:774 | Popover of raw `<button>`s (eslint-disable) used as a menu |

### Health & safety › Hazards

| # | Title the user sees | Type | Steps/sections | Opened from | File:line | Inconsistencies |
|---|---|---|---|---|---|---|
| HS-12 | Log hazard (success shell titled "Hazard logged") | Wizard | Site & type → Risk rating → Detail → Assign & due → Review | Hazard register "Log hazard" (mounted on Compliance › Hazards and Sites › Hazards pages) | components/health-safety/hazard-create-dialog.tsx:291 (success :225) | No Cancel button (Back + Continue only); `pct={null}` so no completeness meter; footerStart holds a blocker message; raw `<button>` chips (:358, :394); success rendered as a second `WizardShell`; no dirty-close guard |
| HS-13 | Hazard {reference} | Detail viewer | Overview → Risk → Corrective actions → Evidence → History | Hazard register row click or "View hazard"; client Risk management tab | components/health-safety/hazard-detail-dialog.tsx:339 | `pct={null}`; native date inputs |
| HS-14 | Board reports (menu) | Other (popover menu) | Report links | Hazard register hero "Board reports" | components/health-safety/hazard-register.tsx:669 | Popover menu |

### Health & safety › Injuries and return to work

| # | Title the user sees | Type | Steps/sections | Opened from | File:line | Inconsistencies |
|---|---|---|---|---|---|---|
| HS-15 | Record workplace injury / Edit workplace injury (success shell "Injury recorded") | Wizard | Worker & site → Injury → Treatment & ACC → Review & record | Injuries register "Record injury"; Edit from detail dialog | components/health-safety/injury-wizard-dialog.tsx:405 (success :308) | Footer A; duplicates HsFormWizard "Report injury → return-to-work" (HS-04); no dirty-close guard |
| HS-16 | {reference} · {worker} (injury detail) | Detail viewer | Overview → RTW plans → Capacity → Evidence → History | Injuries register row click or "View injury" | components/health-safety/injury-detail-dialog.tsx:250 | Seven native date inputs; raw `<button>`s (:522, :592, :877) and attachment delete with no confirmation (:881); ACC pane native `<input>` (:1079) |
| HS-17 | Export & reports (menu) | Other (popover menu) | Export CSV, analytics links | Injuries hero "Export & reports" | pages/health-safety/injuries/index.tsx:625 | Popover menu with raw trigger button |

### Health & safety › First aid

| # | Title the user sees | Type | Steps/sections | Opened from | File:line | Inconsistencies |
|---|---|---|---|---|---|---|
| HS-18 | Record a first-aid treatment | Wizard | Who & where → Injury / illness → Treatment & outcome → Incident & notes → Review | First-aid register "Record first aid"; dashboard launcher tile (navigates with `?report=1`) | components/health-safety/first-aid-report-dialog.tsx:346 | Reference Footer A (Ring, Back, Cancel, Save & add another); no dirty-close guard |
| HS-19 | First aid {reference} | Detail viewer | Overview → Injury & treatment → Incident link → Follow-ups → Evidence → History | First-aid register row, "View record" | components/health-safety/first-aid-detail-dialog.tsx:314 | "Archive record" is an in-pane confirm (`DeletePane` :1224) with its own Cancel/destructive buttons; attachment delete is immediate (:672) |

### Health & safety › Emergency drills

| # | Title the user sees | Type | Steps/sections | Opened from | File:line | Inconsistencies |
|---|---|---|---|---|---|---|
| HS-20 | Schedule drill | Wizard | Site & type → Scenario & schedule → Wardens & people → Review | Drills register "Schedule drill" | components/health-safety/drill-schedule-dialog.tsx:211 | Back, Cancel (ghost) and Continue all in `footerEnd`; `pct={null}`; duplicates HsFormWizard "Record emergency drill" (HS-02); no dirty-close guard |
| HS-21 | Complete drill | Wizard | Timings → Roll-call → Outcome → Review | Drill row action "Complete drill"; drill page | components/health-safety/drill-complete-dialog.tsx:180 | `pct={null}`; no dirty-close guard |
| HS-22 | Drill {reference} | Detail viewer | Overview → Run & timings → Participants → Findings → Evidence → History | Drills register row, "View drill"; `/health-safety/drills/{id}` | components/health-safety/drill-detail-dialog.tsx:298 | Raw trash `<button>` deletes an attachment immediately (:753); native date inputs |
| HS-23 | Board reports (menu) | Other (popover menu) | Drill analytics and dashboard links | Drills hero "Board reports" | pages/health-safety/drills/index.tsx:605 | Popover menu |

### Health & safety › Restraints and behaviour support plans

| # | Title the user sees | Type | Steps/sections | Opened from | File:line | Inconsistencies |
|---|---|---|---|---|---|---|
| HS-24 | Record a restraint event | Wizard | Person & context → The episode → Trigger & de-escalation → Restraint & response → Injury & aftercare → Plan adherence → Review & record | Restraints register "Record restraint event"; row "Record event under this plan"; Respite workspace | components/health-safety/restraint-event-wizard.tsx:454 | No Cancel button (Ring, Back, Continue); 7 steps; duplicates HsFormWizard "Log restraint event" (HS-03); no dirty-close guard |
| HS-25 | Restraint event {reference} | Detail viewer | Overview → Response → Injury & aftercare → Evidence → Review | Restraints register row, "View event" | components/health-safety/restraint-event-detail-dialog.tsx:217 | Raw `<button>`s (:337, :389, :399, :549); attachment delete is immediate with no confirm (:552) |
| HS-26 | Create a behaviour support plan | Wizard | Person & plan → Triggers & de-escalation → Interventions → Practice & review → Review & create | Restraints register "Create behaviour support plan" | components/health-safety/bsp-wizard.tsx:303 | No Cancel button; `size="sm"` mixed with default buttons; no dirty-close guard |
| HS-27 | Behaviour support plan {reference} | Detail viewer | Overview → Plan content → Lifecycle → Reviews | Restraints register plan row, "View plan" | components/health-safety/bsp-detail-dialog.tsx:199 | `pct={null}` |
| HS-28 | Export / Board reports (menu) | Other (popover menu) | Report links | Restraints hero | pages/health-safety/restraints/index.tsx:707 | Popover menu |

### Health & safety › Lone workers

| # | Title the user sees | Type | Steps/sections | Opened from | File:line | Inconsistencies |
|---|---|---|---|---|---|---|
| HS-29 | Start lone-worker session | Wizard | Choose the shift → Monitoring plan → Review & start | Lone workers register "Start session" | components/health-safety/lone-worker-wizard.tsx:310 | Native `<input>`/`<textarea>` (:547, :560, :594, :616); duplicates HsFormWizard "Lone-worker check-in" (HS-06); no dirty-close guard |
| HS-30 | Lone worker session #{id} | Detail viewer | Overview → Check-ins → Alerts; lifecycle actions replace the body | Lone workers register row, "View session" | components/health-safety/lone-worker-detail-dialog.tsx:222 | Restyled red buttons (`bg-status-critical text-white` raw classes :197, :383); `text-white` on coloured status dots |
| HS-31 | {alert type} alert | Detail viewer | Summary rows and Acknowledge / Resolve actions | Session dialog "Alerts" tab; alert row, "View alert" | components/health-safety/lone-worker-detail-dialog.tsx:643 | Hand-rolled chrome: `p-0 [&>button]:hidden`, 460 px, `sr-only` title, own header with `XCircle` close; full-width custom red "Resolve" button (:821) |
| HS-32 | Record check-in / Extend / edit session / End session / Remove session / Trigger emergency / Acknowledge alert / Resolve alert (7 variants) | Form dialog | Status tiles and notes; new expected end and interval; resolution notes | Lone workers register row menu or right-click (check-in, extend, end, emergency); alert rows (acknowledge, resolve); session dialog | components/health-safety/lone-worker-action-form.tsx:395 | Hand-rolled chrome (`p-0 [&>button]:hidden`, 460 px, header bar shows the subject, visible title only inside the body); buttons are `GuardrailButton unstyled` with raw classes; native `<textarea>` and `datetime-local`; "Remove session" and "Trigger emergency" confirm inside the same form (no ConfirmDialog) |
| HS-33 | Reports (menu) | Other (popover menu) | Analytics, board summary, export CSV | Lone workers hero "Reports" | pages/health-safety/lone-workers/index.tsx:653 | Popover menu with raw on-dark trigger |

### Health & safety › PPE

| # | Title the user sees | Type | Steps/sections | Opened from | File:line | Inconsistencies |
|---|---|---|---|---|---|---|
| HS-34 | PPE item {serial} | Detail viewer | Overview → Allocation → Inspections → History | PPE register row, "View item" | pages/health-safety/ppe/ppe-detail-dialog.tsx:208 | `pct={null}` |
| HS-35 | Allocation — {worker} | Detail viewer | Allocation (single section) | PPE allocations tab row, "View allocation" | pages/health-safety/ppe/ppe-detail-dialog.tsx:646 | Full wizard shell with rail for one section |
| HS-36 | Add inventory item / Edit inventory item | Wizard | Type & site → Identification → Condition & dates → Review | PPE hero "Add to register" › Add inventory; right-click empty area "Add inventory"; type row menu "Add inventory of this type" | pages/health-safety/ppe/ppe-create-wizards.tsx:240 (shell ppe-wizard.tsx:66) | Not `WizardShell`: `PpeWizard` is a hand-built copy of the Add-Client chrome (`p-0 [&>button]:hidden`, own rail, header and progress); no dirty-close guard |
| HS-37 | Allocate PPE | Wizard | Worker & item → Fit-test → Training & sign-off → Review | PPE hero "Add to register" › Allocate PPE; inventory row menu "Allocate to worker" | pages/health-safety/ppe/ppe-create-wizards.tsx:714 | Same hand-built `PpeWizard` copy; no dirty-close guard |
| HS-38 | Add PPE type / Edit PPE type | Wizard | Identity → Standards & lifecycle → Guidance → Review | PPE hero "Add to register" › Add PPE type; type row menu "Edit type" | pages/health-safety/ppe/ppe-create-wizards.tsx:1043 | Same hand-built `PpeWizard` copy; no dirty-close guard |
| HS-39 | Return PPE | Form dialog | Condition on return, notes | Allocation detail or row "Return PPE" | pages/health-safety/ppe/ppe-action-dialogs.tsx:56 | One-field action built as a full single-step `WizardShell` (rail, header, 980 px) |
| HS-40 | Record inspection | Form dialog | Result, condition, notes | Inventory row menu "Record inspection"; item detail | pages/health-safety/ppe/ppe-action-dialogs.tsx:56 | Single-step `WizardShell` for a small form |
| HS-41 | Condemn item | Form dialog | Reason | Inventory row menu "Condemn"; item detail | pages/health-safety/ppe/ppe-action-dialogs.tsx:56 | Destructive action built as a single-step `WizardShell` with a primary button |
| HS-42 | Dispose item | Form dialog | Disposal method, notes | Inventory row menu "Dispose" (condemned items); item detail | pages/health-safety/ppe/ppe-action-dialogs.tsx:56 | Single-step `WizardShell`; irreversible action with no ConfirmDialog |
| HS-43 | Add to register (menu) | Other (popover menu) | Add type, add inventory item, allocate PPE | PPE hero "Add to register" | pages/health-safety/ppe/index.tsx:1348 | Popover of raw `<button>`s (eslint-disable) acting as a launcher menu |

### Health & safety › Safe work procedures

| # | Title the user sees | Type | Steps/sections | Opened from | File:line | Inconsistencies |
|---|---|---|---|---|---|---|
| HS-44 | New safe work procedure / Edit {reference} | Wizard | Basics → Hazards & PPE → Steps → Applies to → Review cycle → Review & save | Procedures register "New procedure"; edit from detail | components/health-safety/procedure-wizard-dialog.tsx:389 | Footer A; no dirty-close guard |
| HS-45 | {reference} — {title} | Detail viewer | Overview → Steps → PPE & hazards → Applies to → Documents → History | Procedures register row, "View procedure" | components/health-safety/procedure-detail-dialog.tsx:377 | Document delete is immediate with no confirm (:923) |
| HS-46 | Library reports (menu) | Other (popover menu) | Report links and review-due filter | Procedures hero "Library reports" | pages/health-safety/procedures/index.tsx:628 | Popover menu |

### Health & safety › Risk assessments

| # | Title the user sees | Type | Steps/sections | Opened from | File:line | Inconsistencies |
|---|---|---|---|---|---|---|
| HS-47 | New risk assessment / Edit draft / Supersede — new version | Wizard | Context → Inherent risk → Controls → Residual risk → Evidence → Review & ownership → Review & create | Risk assessments register "New" button; embedded register section "New assessment" (Sites › Risk assessments tab, Client profile); detail footer actions (edit, supersede) | components/health-safety/risk-assessments/ra-wizard-dialog.tsx:631 | Custom `maxWidth` 1080 and `maxHeight` 860; Footer B; no dirty-close guard |
| HS-48 | Approve & activate / Mark for review / Record review / residual / Archive assessment (4 variants) | Confirm | Single step: confirmation fields | Risk assessment detail footer actions | components/health-safety/risk-assessments/ra-wizard-dialog.tsx:631 | Confirmations rendered as a 1080 px `WizardShell` with a rail for one action; "Archive" uses a destructive primary button |
| HS-49 | Risk assessment (title = assessment title) | Detail viewer | Single scrolling view with rail of meta cards; actions: edit, approve, review, supersede, archive | Risk assessments register row | components/health-safety/risk-assessments/ra-detail-dialog.tsx:165 | Hand-rolled wizard chrome (own 248 px rail, header, `p-0 [&>button]:hidden`, `sr-only` title) instead of `WizardShell`; attachment delete is immediate with no confirm (:159) |

### Health & safety › Chemical register (substances)

| # | Title the user sees | Type | Steps/sections | Opened from | File:line | Inconsistencies |
|---|---|---|---|---|---|---|
| HS-50 | Add hazardous substance / Edit {name} | Wizard | Substance → Controls → Review | Chemical register "Add substance"; H&S dashboard launcher; detail edit | components/health-safety/substance-wizard-dialog.tsx:441 | No dirty-close guard; also has an unreachable duplicate config (HS-05) |
| HS-51 | Substance {name} | Detail viewer | Overview → Safety & handling → SDS → Storage → Exposures → History (action panes: Add SDS, Add storage, Record exposure) | Chemical register row, "View substance"; `?substance=ID&action=` deep link | components/health-safety/substance-detail-dialog.tsx:515 | Native date inputs |
| HS-52 | Board reports (menu) | Other (popover menu) | Board summary link | Chemical register hero "Board reports" | pages/health-safety/substances/index.tsx:658 | Popover menu |

### Health & safety › Worker participation

| # | Title the user sees | Type | Steps/sections | Opened from | File:line | Inconsistencies |
|---|---|---|---|---|---|---|
| HS-53 | Add H&S representative | Wizard | Who → Election → Review | Worker participation "New" menu or row menu "Add representative" | components/worker-participation/add-representative-wizard.tsx:352 | Footer B but with `size="sm"` buttons; duplicates HsFormWizard "Add H&S representative" (HS-08); no dirty-close guard |
| HS-54 | Schedule a committee meeting | Wizard | Committee → Schedule → Attendees → Review & schedule | "New" menu; row menu "Schedule meeting" | components/worker-participation/schedule-meeting-wizard.tsx:453 | Footer A with `size="sm"` buttons; no dirty-close guard |
| HS-55 | New consultation | Wizard | Topic & type → Scope → Documents → Review | "New" menu; row menu "New consultation" | components/worker-participation/new-consultation-wizard.tsx:359 | `size="sm"` buttons; native `<input>`/`<textarea>`/`<button>` (:389, :426, :467, :496); no dirty-close guard |
| HS-56 | Representative · {name} | Detail viewer | Overview → History | Worker participation list row, "View representative" | components/worker-participation/representative-detail-dialog.tsx:206 | `size="sm"` actions |
| HS-57 | Committee meeting · {committee} | Detail viewer | Overview → Attendees → Agenda & actions → Minutes | Row, "View meeting" | components/worker-participation/meeting-detail-dialog.tsx:241 | Native date inputs; `size="sm"` action buttons |
| HS-58 | Consultation — {title} | Detail viewer | Overview → Lifecycle → Documents | Row, "View consultation" | components/worker-participation/consultation-detail-dialog.tsx:185 | Four native `<textarea>`s in action panes (:632, :714, :785, :800) |
| HS-59 | New (menu: Add representative / Schedule meeting / New consultation) | Other (popover menu) | Three choices with sub-text | Worker participation hero "New" | pages/health-safety/worker-participation/index.tsx:629 | Popover launcher built from raw buttons |

### Health & safety › Analytics and worklists

| # | Title the user sees | Type | Steps/sections | Opened from | File:line | Inconsistencies |
|---|---|---|---|---|---|---|
| HS-60 | Detail — {selection} | Detail viewer | Selection → Range → Site → Records → Notifiable (read-only drill-in) | Analytics: click a chart, tile or table cell | pages/health-safety/analytics.tsx:668 | Read-only drill-in built with a full `WizardShell` and rail; footer Close, Export CSV, Open register |
| HS-61 | Board reports (menu) | Other (popover menu) | Report links | Analytics hero "Board reports" | pages/health-safety/analytics.tsx:1199 | Popover menu |
| HS-62 | Record summary (title = record) | Detail viewer | Single "Detail" step with a ReviewCard of rows | Worklist row click or right-click "View detail" | pages/health-safety/components/hs-detail-dialog.tsx:57 | Single-step `WizardShell` for a summary; Print uses `window.print()`; close button sits in `footerStart` |

## Compliance

`pages/compliance/index.tsx` mounts four `WizardShell` wizards (all Footer B, all closing without a discard guard). They overlap in purpose with the Governance › Compliance register dialogs (GOV-28 to GOV-31), so the app has two parallel obligation/evidence flows with different wording ("obligation" versus "requirement").

### Compliance › Dashboard wizards

| # | Title the user sees | Type | Steps/sections | Opened from | File:line | Inconsistencies |
|---|---|---|---|---|---|---|
| CMP-01 | Log compliance obligation | Wizard | Obligation → Details → Schedule → Review & create | Compliance dashboard hero "Log obligation" | pages/compliance/wizards/log-obligation-dialog.tsx:274 (success :233) | Footer B; near-duplicate of GOV-28 "Add requirement" (different steps and wording); no dirty-close guard |
| CMP-02 | Record compliance evidence | Wizard | Obligation → Document → Review & save | Hero "Record evidence"; due-list row menu "Record evidence"; link "Record evidence instead" inside CMP-03 | pages/compliance/wizards/record-evidence-dialog.tsx:436 (success :389) | Own `DocumentDropzone` with raw `<input type="file">` (:231, :278) instead of shared FileDropzone; near-duplicate of GOV-30 "Upload evidence"; no dirty-close guard |
| CMP-03 | Complete obligation | Wizard | Obligation → Confirm | Due-list row menu "Complete obligation" | pages/compliance/wizards/complete-obligation-dialog.tsx:149 (success :113) | Two-step wizard used for a simple confirmation; near-duplicate of GOV-31; no dirty-close guard |
| CMP-04 | Log notifiable incident | Wizard | Event → Notification → Review & log | Hero "Log notifiable" | pages/compliance/wizards/log-notifiable-dialog.tsx:276 (success :234) | Native date inputs; no dirty-close guard |

## Privacy

Privacy has the cleanest recent pattern (config-driven `PrivacyWizard`, `PrivacyDetailDialog` on `WizardShell`) but also the only native browser `prompt()` / `confirm()` calls in this inventory, on three legacy detail pages.

### Privacy › Dashboard wizards and viewers

| # | Title the user sees | Type | Steps/sections | Opened from | File:line | Inconsistencies |
|---|---|---|---|---|---|---|
| PRV-01 | Log data breach | Wizard | What happened → Impact → Response → Review | Privacy dashboard right-click menu "Log data breach"; `?new=breach` deep link | components/privacy/privacy-wizard.tsx:263 (config privacy-wizard-configs.tsx:59, success :227) | Footer B; config-driven fields; no dirty-close guard |
| PRV-02 | New legal hold | Wizard | Basis → Scope → Review | Right-click menu "New legal hold"; `?new=hold` | components/privacy/privacy-wizard.tsx:263 (configs.tsx:180) | Footer B; no dirty-close guard |
| PRV-03 | New retention policy | Wizard | Policy → Periods → Review | Right-click menu "New retention policy"; `?new=retention` | components/privacy/privacy-wizard.tsx:263 (configs.tsx:251) | Footer B; no dirty-close guard |
| PRV-04 | New DPIA | Wizard | Assessment → Processing → Risk → Review | Right-click menu "New DPIA"; `?new=dpia` | components/privacy/privacy-wizard.tsx:263 (configs.tsx:385) | Footer B; no dirty-close guard |
| PRV-05 | New privacy request | Wizard | Request → Data subject → Scope & assignment → Review | Dashboard hero "New privacy request"; right-click menu "New privacy request"; `?new=request` | components/privacy/privacy-wizard.tsx:263 (configs.tsx:528) | Footer B; no dirty-close guard |
| PRV-06 | Record detail (title = reference; rail by kind) | Detail viewer | Request: Overview → Subject & verification → Timeline & deadline → Documents → History. Breach: Overview → Assessment → Notification → Documents → History. Hold: Overview → Documents → History. DPIA: Overview → Processing → Risk → Documents → History | Privacy dashboard worklist row click or right-click menu | components/privacy/privacy-detail-dialog.tsx:159 | `footerStart` holds status chips; `footerEnd` holds up to five `size="sm"` action buttons plus "Open full page"; attachment delete is immediate (privacy-attachments-pane.tsx:61) |
| PRV-07 | Verify identity / Extend deadline / Mark complete / Refuse request / Export data package / Notify OPC / Notify affected individuals / Resolve breach / Release hold / Approve DPIA / Send for Privacy Officer review / Execute approved retention (12 variants) | Form dialog | Per variant: select, text, textarea, date or an irreversible-delete checkbox; icon tile and blurb | Footer buttons of PRV-06; dashboard row right-click menu ("Execute approved retention"); Retention review page "Execute" | components/privacy/privacy-action-modal.tsx:344 | Hand-rolled chrome: `max-w-md gap-0 overflow-hidden p-0`, `sr-only` title with its own icon-tile `<h2>` header, own footer band (`bg-muted/30`), native checkbox; Tailwind width (not a token); "critical" tone variants vs primary |
| PRV-08 | Compliance reports (menu) | Other (popover menu) | OPC breach register, Access-request SLA report, Retention compliance, Full compliance report | Privacy dashboard hero "Compliance reports" | pages/privacy/dashboard.tsx:619 | Popover of anchor links used as a menu |

### Privacy › Legacy detail pages using native browser dialogs

These are in-page buttons on full-page "show" screens; each opens a native `prompt()` / `confirm()` instead of a pop-up from this app. They duplicate actions that PRV-07 already provides.

| # | Title the user sees | Type | Steps/sections | Opened from | File:line | Inconsistencies |
|---|---|---|---|---|---|---|
| PRV-09 | "Enter OPC reference number (if available):" | Other (native prompt) | One text answer | Breach page action "Record OPC Notification" | pages/privacy/breaches/show.tsx:300 | Browser `prompt()`; no validation, no cancel feedback, unstyled |
| PRV-10 | "Enter notification method (e.g., email, letter):" | Other (native prompt) | One text answer | Breach page action "Record Subject Notification" | pages/privacy/breaches/show.tsx:321 | Browser `prompt()` |
| PRV-11 | "Enter resolution notes:" | Other (native prompt) | One text answer | Breach page action "Mark Resolved" | pages/privacy/breaches/show.tsx:343 | Browser `prompt()` |
| PRV-12 | "Approve this PIA?" | Other (native confirm) | OK / Cancel | DPIA page action "Approve" | pages/privacy/dpia/show.tsx:279 | Browser `confirm()` in place of ConfirmDialog |
| PRV-13 | "Enter review notes:" | Other (native prompt) | One text answer | DPIA page action "Request Privacy Officer Review" | pages/privacy/dpia/show.tsx:294 | Browser `prompt()` |
| PRV-14 | "Enter verification method (e.g., ID document, phone verification):" | Other (native prompt) | One text answer | Request page action "Verify Identity" | pages/privacy/requests/show.tsx:302 | Browser `prompt()` |
| PRV-15 | "Enter completion notes:" | Other (native prompt) | One text answer | Request page action "Mark Complete" | pages/privacy/requests/show.tsx:337 | Browser `prompt()` |
| PRV-16 | "Enter extension reason:" then "Enter new due date (YYYY-MM-DD):" | Other (native prompt) | Two chained prompts | Request page action "Extend Deadline" | pages/privacy/requests/show.tsx:356 | Two chained `prompt()`s; date typed as free text |
| PRV-17 | "Enter rejection reason:" then "Enter legal basis for rejection:" | Other (native prompt) | Two chained prompts | Request page action "Refuse Request" (destructive button) | pages/privacy/requests/show.tsx:379 | Two chained `prompt()`s for an irreversible, regulated action; no confirm |

## Safeguarding

### Safeguarding › Concerns

| # | Title the user sees | Type | Steps/sections | Opened from | File:line | Inconsistencies |
|---|---|---|---|---|---|---|
| SAF-01 | Raise a safeguarding concern | Wizard | Subject & type → What happened → Severity & category → Immediate response → External referral → Review | Safeguarding register "Raise concern"; `?raise=1` deep link | components/safeguarding/raise-wizard.tsx:340 | Footer A without Cancel; button reads "Next" (rest of app says "Continue"); native `<input>`s (:513, :857); no dirty-close guard |
| SAF-02 | Concern {reference} (restricted view) | Detail viewer | Restricted (single locked section) | Register row when the viewer has need-to-know only access | components/safeguarding/concern-dialog.tsx:296 | Full wizard shell with one locked step to show an access-limited message |
| SAF-03 | Concern {reference} | Detail viewer | Overview → Timeline → Risk → Investigation → External reports → Action plan → Evidence → Linked records | Register row click or right-click "View concern"; `/safeguarding/{id}` page (dialog shown over empty layout) | components/safeguarding/concern-dialog.tsx:557 | 2,700-line file; attachment delete is immediate (:2691); five native date inputs; deep-link page renders the dialog over a blank AppLayout |

## Incidents

### Incidents › Register

| # | Title the user sees | Type | Steps/sections | Opened from | File:line | Inconsistencies |
|---|---|---|---|---|---|---|
| INC-01 | Report an incident / Report a near miss | Wizard | Incident: Type & people → What happened → Severity & actions → WorkSafe check → Follow-ups → Review. Near miss: Who & where → What happened → What could have happened → Dangerous occurrence → Follow-ups → Review | Incidents register hero "Report" menu (INC-04); H&S dashboard launcher "Report incident" | components/incidents/incident-report-dialog.tsx:774 | Footer A without Cancel, button reads "Next"; `frontline-tap` classes; encrypted draft recovery; the only non-Governance wizard with a discard guard (INC-02) |
| INC-02 | Keep this incident report? | Alert | Keep editing, Discard draft (destructive), Save and close | Closing INC-01 with unsaved input | components/incidents/incident-report-dialog.tsx:976 | Hand-rolled `AlertDialog` with three actions, destructive in the middle; not ConfirmDialog or shared DiscardDraftDialog |
| INC-03 | Incident {reference} | Detail viewer | Overview → Timeline → Official incident attachments → Incident follow-ups → Investigation → Linked records | Incidents register row click or "View incident"; `/incidents/{id}` | components/incidents/incident-detail-dialog.tsx:415 | Attachment removal uses an inline two-phase "arming" control (:1163) rather than a dialog; three native date inputs |
| INC-04 | Report (menu: An incident / A near miss) | Other (popover menu) | Two choices with sub-text | Incidents register hero "Report" | pages/incidents/index.tsx:652 | Popover launcher of `Button unstyled` rows (should be DropdownMenu) |

## Roadmap

### Roadmap › Dashboard

| # | Title the user sees | Type | Steps/sections | Opened from | File:line | Inconsistencies |
|---|---|---|---|---|---|---|
| RDM-01 | Quarterly Plan Detail | Detail viewer | Plan badges (fiscal year, quarter, revision, status, preset) and ranked-initiatives cost table | Quarterly plans table "Open" | pages/Roadmap/Dashboard.tsx:2411 | Tailwind `sm:max-w-5xl` (guide says inline style); no icon in title; defined inline in a 2,900-line page |
| RDM-02 | Triage Suggestion Details | Detail viewer | Title, source, current owner (assign select), notes, technical payload toggle; actions Save notes, Convert to Initiative, Accept, Reject, Close | Suggestions table "View" | pages/Roadmap/Dashboard.tsx:2457 | Tailwind `sm:max-w-3xl`; no icon; hand-rolled action row (`div`) of five buttons instead of `DialogFooter`; "Reject" is an outline button with no confirmation; actions fire without ConfirmDialog |

## Appendix A: Look-alikes that render inline (not counted above)

These are named like pop-ups, or sit where a pop-up is expected, but render as part of the page.

| Component | File:line | What the user sees | Inconsistency |
|---|---|---|---|
| CreateHubPageDialog ("Start with a purpose") | pages/company-hub/_dialogs.tsx:27 | An in-canvas card (`<section class="rounded-2xl border bg-card">`) with Back to hub, title field, page type, starter tiles, sticky footer "Create draft & open designer". Mounted from pages/company-hub/index.tsx:775, _discovery.tsx:170, _department.tsx:347, _insights.tsx:645, _resource-library.tsx:884 | An entity create flow drawn as a page card with its own inline "Discard this page setup?" band (:287) and the leave guard, although POPUP_STYLE_GUIDE sends entity create flows to wizard dialogs; component name says Dialog |
| HubAnnouncementDialog ("Company announcement") | pages/company-hub/_announcement.tsx:33 | A full-width card with Back to updates, read-confirm and attachments. The `Dialog` branch (:313) is never used: the only caller passes `inline` (index.tsx:785) | Dual-mode component (dialog or page); the dialog variant is dead code with its own `max-w-3xl` and `text-2xl` title |
| HubPdfReader ("Document preview") | pages/company-hub/_pdf-reader.tsx:309 | Inline PDF viewer section on the document page and event attachments | FILE_PREVIEW_STYLE_GUIDE says one PDF/image uses the shared FilePreviewDialog; also has `bg-white` page canvas |
| "Cancel this event?" confirm band | pages/company-hub/_event-cancellation.tsx:228 | An inline confirmation panel (heading, scope radios, buttons) replacing the "Cancel event" button inside HUB-20 | Confirmation built as an in-dialog panel with native radio inputs and its own Esc handling |
| Inline "Discard unsaved choices?" bands | pages/company-hub/_interests.tsx:393, _shortcuts.tsx:504, _resource-review.tsx:283, _notification-preferences.tsx:429 (the profile sheet asks with a dialog instead, HUB-06) | A bordered band above the sheet footer with Keep editing and Discard changes | Fourth way of asking "discard?" in the hub; discard is a primary button |
| Governance evidence and attachment panels | pages/Governance/Actions/_evidence.tsx:62, components/governance/GovernanceAttachmentsPanel.tsx | File lists with upload and per-file remove inside pages and dialogs; only their remove prompts are overlays (GOV-09, GOV-94) | `GovernanceAttachmentsPanel` has a raw `<input type="file">` (:267) |
| In-pane destructive confirms | components/health-safety/first-aid-detail-dialog.tsx:1224 (`DeletePane`), components/health-safety/event-detail-dialog.tsx:3744 (`ArmedButton`), components/incidents/incident-detail-dialog.tsx:1163 (two-phase remove) | Panels or buttons inside a detail viewer that ask twice before archiving or removing | Three bespoke confirm patterns instead of ConfirmDialog |
| LoneWorkerActionForm inside the session dialog | components/health-safety/lone-worker-detail-dialog.tsx:237 | The same 7 forms as HS-32, rendered inline when you act from the session viewer | Same form reachable as a standalone modal and as a viewer pane |
| Other `*Panel` components checked and found inline | ClosureExceptionPanel, ApplicableProceduresPanel, MeetingReadinessPanel, AssuranceSummaryPanel, PriorityOverviewPanel, LeadingPanel / LaggingPanel / CompliancePanel, DiscussionPanel (the trigger card for HUB-07), ResourceReportPanel (trigger for HUB-08) | Cards and page sections | Not overlays |

## Appendix B: In the code but not mounted by any page

| Component | File:line | Note |
|---|---|---|
| ModerationReview sheet "Hidden comments" | pages/company-hub/_moderation.tsx:352 | Only `moderation.test.tsx` renders it. The same review appears as the "Hidden comments" tab inside HUB-07. Hub sheet recipe, 600 px |
| SiteAddStorageDialog "Add storage at {site}" | components/health-safety/site-chemical-storage-dialog.tsx:96 | Only imported by `site-chemicals-panel.tsx`, which no page imports. `max-w-lg` Tailwind width, `sr-only` dialog title with a `StepHead` as the visible heading, Cancel plus "Add storage" buttons in the body |
| HsFormWizard "substance" config (HS-05) | pages/health-safety/components/wizard-configs.tsx:430 | Listed in the table for completeness; the dashboard routes the key to the dedicated substance wizard, so the config is unreachable |

## Appendix C: Overlays that this area opens or shares but that are defined elsewhere (not counted)

| Overlay | Defined in | Used by this area at |
|---|---|---|
| Right-click context menu `ShiftContextMenu` (the "row menu") | components/rostering/shift-context-menu.tsx | pages/compliance/index.tsx, pages/privacy/dashboard.tsx, pages/safeguarding/index.tsx, pages/incidents/index.tsx, and 17 H&S files (events, corrective actions, drills, injuries, first aid, lone workers, PPE, procedures, restraints, risk assessments, substances, worker participation, analytics, worklists, register-row-kit, hazard-register, ra-register-section) |
| Row kebab menus (DropdownMenu) | components/ui/dropdown-menu.tsx | Every register and list in this area |
| Hub header user menu (DropdownMenu) with My shortcuts, Notification preferences, Birthday settings | pages/company-hub/index.tsx:469 (items at :469, :475, :484) | Opens HUB-02, HUB-04, HUB-01 |
| EventWizardDialog | components/hr/calendar/event-wizard-dialog.tsx | pages/company-hub/_event-drafts.tsx:541, _event-workspace.tsx:153 |
| RecognitionWizard | components/recognition/recognition-wizard.tsx | pages/company-hub/index.tsx:1507 |
| TicketDrawer | components/it/ticket-drawer.tsx | pages/company-hub/_services.tsx:280 |
| HubCommandResults (a `CommandGroup` inside the global command palette) | components/company-hub-command-search.tsx | Not an overlay by itself |
| RestraintEventWizard, EventDetailDialog, HazardRegister, HazardDetailDialog, RaRegisterSection, ClientPrivacyPanel | components/health-safety/*, components/privacy/client-privacy-panel.tsx | Also mounted by components/respite/workspace.tsx:478, pages/sites/calendar/SiteCalendar.tsx:1898, pages/sites/hazards/index.tsx, pages/compliance/hazards/index.tsx, pages/operations/clients/tabs/risk-management.tsx:608, pages/sites/tabs/risk-assessments.tsx, pages/operations/clients/show.tsx (same dialogs, other modules) |
| A different `AlertDetailDialog` | components/fleet-assets/vehicle-workspace/map-alerts.tsx:447 | Name clash with HS-31; belongs to Fleet |
| Toasts (`toast.success` / `toast.error`) | Sonner | Used by Compliance, Roadmap and others; feedback family, not pop-ups |

## Appendix D: Company hub leave and discard prompts (HUB-28)

`useSettingsLeaveConfirmation` renders one `ConfirmDialog` (button "Discard and continue", default destructive style). Titles passed in by the 17 hub callers:

| Caller | Title |
|---|---|
| pages/company-hub/document-editor.tsx:306 | Leave document changes? |
| pages/company-hub/_birthday-settings.tsx:134 | Discard birthday-sharing changes? |
| pages/company-hub/_community.tsx:323 | Leave this idea? |
| pages/company-hub/_dialogs.tsx:73 | Discard this page setup? |
| pages/company-hub/_discussion.tsx:81 | Leave this conversation? |
| pages/company-hub/_document-upload.tsx:83 | Leave document uploads? |
| pages/company-hub/_idea-composer.tsx:74 | Leave this idea? |
| pages/company-hub/_interests.tsx:215 | Discard interest changes? / Leave while your interests are saving? |
| pages/company-hub/_notification-preferences.tsx:122 | Leave notification preferences? |
| pages/company-hub/_person-profile.tsx:76 | Discard profile changes? / Leave while your profile is saving? |
| pages/company-hub/_planning.tsx:508 | Leave this editorial plan? |
| pages/company-hub/_publication-workspace.tsx:100 | Leave these publication choices? |
| pages/company-hub/_recognition-conversation.tsx:150 | Leave this comment? |
| pages/company-hub/_resource-reports.tsx:58 | Leave this correction? |
| pages/company-hub/_resource-review.tsx:49 | Discard this response? |
| pages/company-hub/_revision-history.tsx:851 | Leave this saved-version review? |
| pages/company-hub/_shortcuts.tsx:101 | Discard shortcut changes? |
