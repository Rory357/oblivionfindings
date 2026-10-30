# Popup / Dialog Style Guide

Every modal popup in Oblivion Findings — create dialogs, edit dialogs, confirmation
dialogs, detail viewers — follows the same look-and-feel so users can recognise
them instantly. There are **two tiers** of dialog, and every popup is one of them:

1. **Simple dialog** — one screen of fields, or a confirmation/detail viewer,
   built on the shared `Dialog` primitives and `ConfirmDialog`.
2. **Entity wizard dialog** — the `WizardShell` multi-step modal (stepper rail,
   step header, progress strip, completeness meter, success pane). **This is the
   default for adding and editing entity records** — sites, clients, staff,
   assets, incidents, risk assessments, and anything else with more than one
   section of fields. Built on `WizardShell`
   (`components/wizard/shell.tsx`).

When you build a new popup, follow this guide.

**Slate look (approved by Stephan 2026-10-20).** Every pop-up uses the
Slate palette in DESIGN.md: white surface (dark `#1E293B`) with a
`#E2E8F0` border and a soft shadow, a `#F1F5F9` footer band and nested
sections (dark `#273449`), muted text `#475569`, brand tint `#F3EEFF` for
selected tiles and the active wizard step, and the flatter Slate buttons.
Widths stay 480 / 720 / 900 / 1100 (wizards 1100) — never others. Every
template is drawn in light and dark on the "Oblivion colours & look"
canvas (claude.ai/artifact/CPsdrsDkwjpYwscYf8jHbY), page 3:
- wizard: step, review with Edit links, step with an error, success,
  "Discard this draft?", the narrow-screen strip;
- form at each width, with an error and while saving;
- confirm, delete confirm and confirm with a reason;
- file preview and its states, read-only detail, settings pop-up and
  edit side panel;
- pickers, menus, Ctrl K, notifications and the user menu.

Page 4 is the date and time pickers, and pages 8–9 show all 1,539
pop-ups in the app on these templates.

## The pop-up kit — 8 types, 21 blocks, 4 widths (approved by Stephan 2026-10-20)

Every overlay in the app is one of eight types, built from the same blocks, at one
of four widths. A new module brings its own words and fields, never a new frame,
width or button order. The two tiers below are the kit's two most common types:
the simple dialog is the **Form**, and `WizardShell` is the **Wizard**. Drawn in
light and dark in the design viewer (The pop-up kit:
claude.ai/artifact/S2FWVebvqtxrVL5LHahzHy).

| Type | Use for | Width (fixed) |
|---|---|---|
| **Wizard** | A main record with 3 or more sections, or more than about 12 fields | 1100 only |
| **Form** | Every other add, edit, record or send | 480 (up to 3 fields) · 720 (up to about 10) · 900 (sections inside) |
| **Confirm** | Yes / no: plain, destructive, with a reason, or a one-button notice | 480 |
| **Viewer** | A read-only record, event or file | 480 · 720 · 900 |
| **Side panel** | Look at or manage a record beside the list you keep working in | 480 · 640, from the right |
| **Settings** | Switch rows and config cards (the Fleet settings pattern) | 720 (900 for who-gets-what tables) |
| **Picker & menu** | Search pickers; one item list shared by the ⋮ menu and right-click | Popover |
| **Toast & selection bar** | "It worked", with Undo; act on ticked rows | — |

**Which type: ask in this order.**
1. Yes / no → Confirm.
2. Read-only → Viewer.
3. Beside a list you keep working in → Side panel.
4. A setting → Settings.
5. A main record with 3+ sections or 12+ fields → Wizard.
6. Anything else → Form, sized by field count.

A one-step wizard is a Form. A wizard rail used as tabs is a Side panel. No other
width exists: never 440, 560, 600, 640 (except the side panel), 760, `max-w-*` or
`92vw` variants.

**The 21 blocks.** Every pop-up is assembled only from these:
- header (icon in its lens, title, one line, ✕) · context row (a locked parent, read-only) · callout · section;
- text field · choose & numbers · date & time (the approved pickers only) · choice tiles · segmented choice · chips · switch & tick · people;
- upload (always the shared drop zone) · repeating rows · step rail · review card · success;
- footers (form, wizard, destructive, viewer) · "Discard this draft?";
- states (loading, couldn't load, no access, nothing yet) · saving.

**Words.**
- Sentence case.
- The title is verb + thing ("Add risk"). Use "Add …" for a new record; the verb rules are in `WRITING_STYLE_GUIDE.md`.
- The one line under the title says what will happen.
- A confirm asks a question that names the thing ("Delete document?").
- The main button names the action ("Save risk"), never "Submit" or "OK".
- Cancel comes first and the main button last, on the right.
- "Delete" means gone for good. "Remove" means it stays in history.
- One wording for the discard guard everywhere: "Discard this draft?".

**Colour.** Red (destructive) only when data is destroyed or a safety check is
loosened (pausing an alert, marking a check "not required"). Resolving,
recording, approving and agreeing stay plain. So do actions that keep the record
in history: cancel, reverse, archive and close.

**Behaviour every pop-up keeps.**
- Closing with unsaved typing asks "Discard this draft?".
- Every upload is the shared drop zone.
- Dates and times only through the approved pickers.
- A required reason is required in the confirm itself.
- When the server enforces separation of duties (approver ≠ creator), the confirm says so.
- Pop-ups used to record on shift (doses, checks, handovers, incidents) use the frontline size: 44 px targets.
- Safety, privacy, consent and legal wording is kept word for word; the kit changes the frame, never that text.
- No browser `alert()`, `confirm()` or `prompt()`.
- No button for a feature that isn't built.

**Tokens only.** Pop-ups read design tokens (`--card`, `--border`, `--primary`,
the status pairs). Change a token or a block once and every pop-up follows. Never
put a hex value or a raw palette class in a pop-up.

## When to use which

| Simple dialog                                       | Wizard dialog (WizardShell)                                  | Full page                                                        |
| --------------------------------------------------- | ------------------------------------------------------------ | ---------------------------------------------------------------- |
| Single-section create/edit (≤ ~8 fields)            | Add/edit of an entity record (site, client, staff, asset, …) | List/index views                                                 |
| Quick confirmation (delete, archive)                | Any form with 2+ logical sections or steps                   | Anything that needs its own URL for sharing                      |
| In-context detail view (contact card, KPI drill-in) | Structured report flows (incident, injury, inspection)       | Free-form canvas/editor surfaces (plan builder, rostering board) |
| Picking from a small set of options                 | Detail viewers with multiple SECTIONS (use `headerLabel`)    |                                                                  |

Do **not** send a long or multi-step form to a full page any more — that was
the old rule from before `WizardShell` existed. New create/edit flows for
entity records are wizard dialogs opened from the index page. Remaining
full-page wizards are migration targets, not precedents.

## Entity wizard dialogs (WizardShell)

The shared chrome lives in
[`resources/js/components/wizard/shell.tsx`](../resources/js/components/wizard/shell.tsx):
`WizardShell` plus its companions `WizardStepPane`, `WizardSuccessPane`,
`ReviewCard`, and `ReviewRow`. Never rebuild this chrome by hand — ~90 dialogs
already compose it.

Anatomy (all provided by the shell — you supply content only):

- **Stepper rail** (248px column from a 1024 CSS px viewport): entity icon
  tile, `railTitle` / `railSub` ("Add site" / "New location"), one button per
  step with icon, label, and one-line `blurb` ("Type, name & lead"). Completed
  steps show a green check; the active step is primary-tinted and carries
  `aria-current="step"`. Clickable via `onStepClick`. Below 1024 CSS px (200 %
  zoom on a 1440 px screen is 720) the shell collapses the rail into a one-line
  top stepper so the body keeps its width: icon tile + title, 44 px step
  buttons with only the current step's label visible (the others keep label +
  blurb as their accessible name), the completeness percentage, and
  `railExtra` moved to the end of the body. Don't hide or rebuild the rail per
  dialog.
- **Completeness meter** (`pct` + `pctLabel`) pinned to the rail's bottom —
  compute it from the fields worth filling in, not just required ones.
- **Header**: "Step x of y · Label" (automatic) and the close button. Detail
  dialogs whose rail entries are sections, not sequential steps, pass
  `headerLabel` instead.
- **Progress strip**: 3px primary bar under the header (automatic).
- **Scrollable body**: wrap each step's content in `<WizardStepPane>` for the
  motion-safe fade/slide transition.
- **Footer band**: `footerStart` (Cancel / Back) and `footerEnd`
  (Continue / submit). Same button rules as simple dialogs: primary filled
  submit, `Loader2` while processing, sentence-case verbs.
- **Review step**: last step before submit summarises entries with
  `<ReviewCard>` / `<ReviewRow>`, each card's Edit link jumping back to its
  step.
- **Success pane**: on create, pass `success={<WizardSuccessPane …/>}` with
  follow-up actions ("View site", "Add another") instead of closing abruptly.

Wizard-specific rules:

- Steps are **skippable by default** (rail navigation is free); validate
  hard-required fields on Continue and on submit, and jump to the offending
  step on server-side errors.
- **Edit reuses the same wizard** as Add — same steps, prefilled, with the
  save verb changed. Don't build a separate edit layout.
- Guard against accidental loss: if the form is dirty, closing asks for
  confirmation ("Discard this draft?" pattern).
- The first step opens with the type/tile picker when the entity has a
  categorical type (see "Type picker" below).

## File layout convention

```
resources/js/pages/<module>/<feature>/_dialogs.tsx
```

- Files prefixed with an underscore (`_dialogs.tsx`, `_helpers.ts`,
  `_canvas.tsx`) are **co-located helpers**, not routable Inertia pages.
- Each dialog set lives in one `_dialogs.tsx` next to the index/show pages that
  open it.
- Type registries (e.g. `CONTACT_TYPES`, `RESOLUTION_TYPES`) live in either
  `_helpers.ts` or at the top of `_dialogs.tsx` and are re-exported.

## File anatomy

A typical `_dialogs.tsx` has these sections, in this order:

1. Imports — shadcn primitives, lucide icons, `useForm`, helpers.
2. **Type registry** — Send-Kudos-style picker definitions (see §"Type picker").
3. **Form value type** — exported `*FormValues`.
4. **Field error helper** — local `FieldError`.
5. **Type picker component** — local picker grid.
6. **Shared form body component** — the field group that's reused between
   Add and Edit dialogs.
7. **Add dialog** — exported `<Add*Dialog>` shell + internal `<Add*Body>` form.
8. **Edit dialog** — exported `<Edit*Dialog>` shell + internal `<Edit*Body>`.
9. **Show / read-only dialog** — when applicable.
10. **Delete confirmation dialog** — when applicable.

Always split the dialog into an outer **shell** (open/close + outer `Dialog`)
and an inner **body** (`useForm`, fields, submit). The shell renders the body
inside `{isOpen && (...)}` so the form state resets cleanly between open/close
cycles.

## Shell pattern

```tsx
export function NewFooDialog({
    isOpen,
    onClose,
    /* required data: parent ids, options, locked values */
}: NewFooDialogProps) {
    return (
        <Dialog open={isOpen} onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-w-xl">
                {isOpen && <NewFooBody onClose={onClose} /* … */ />}
            </DialogContent>
        </Dialog>
    );
}
```

Width — **use an inline `style` prop, not Tailwind utilities**.
`DialogContent` ships with two baked-in width utilities
(`max-w-[calc(100%-2rem)]` and `sm:max-w-lg`) and the Tailwind JIT does
not always pick up arbitrary-value overrides like `!max-w-[56rem]` from
ad-hoc dialog files. Inline `style` always wins:

```tsx
<DialogContent
    className="max-h-[90vh] overflow-y-auto"
    style={{ maxWidth: 'min(92vw, 1100px)', width: 'min(92vw, 1100px)' }}
>
```

Width tokens (pick one — use **px** because this app uses a 14px root
font, so `rem` values are ~12.5% smaller than expected):

- **Confirm / read-only detail** — `min(92vw, 480px)`
- **Standard create / edit form** — `min(92vw, 720px)`
- **Long form, multiple sections** — `min(92vw, 900px)`
- **Multi-tab dialog with structured fields** — `min(92vw, 1100px)`

`min(92vw, …)` narrows gracefully on small screens; the rem cap is the
desktop maximum. For long forms add `max-h-[90vh] overflow-y-auto` so the
body scrolls without pushing the dialog off-screen.

**Anti-pattern**: `<DialogContent className="max-w-3xl">` / `sm:max-w-3xl`
/ `!max-w-[56rem]`. Tailwind utilities are unreliable here because the
default `DialogContent` already ships its own width utilities. Use the
inline `style` form above.

## Body pattern

```tsx
function NewFooBody({ onClose /* … */ }: NewFooBodyProps) {
    const form = useForm<FooFormValues>({
        /* initial */
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post('/route', {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => onClose(),
        });
    };

    return (
        <form onSubmit={handleSubmit}>
            <DialogHeader>
                <DialogTitle className="flex items-center gap-2">
                    <Icon className="h-4 w-4 text-primary" />
                    New Foo
                </DialogTitle>
                <DialogDescription>
                    One-line plain-English explanation of what this dialog does.
                </DialogDescription>
            </DialogHeader>

            <div className="mt-3">
                <FooFields form={form} />
            </div>

            <DialogFooter className="mt-4">
                <Button type="button" variant="outline" onClick={onClose}>
                    Cancel
                </Button>
                <Button type="submit" disabled={form.processing}>
                    {form.processing && (
                        <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                    )}
                    Save foo
                </Button>
            </DialogFooter>
        </form>
    );
}
```

## Type picker (Send-Kudos style)

When the dialog includes a categorical choice (contact type, resolution type,
incident severity), use the tile picker — never a `<Select>` for these. The
picker visually communicates the choice and shows a short description for each
option.

```tsx
<div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
    {TYPES.map((t) => {
        const Icon = t.icon;
        const active = value === t.key;
        return (
            <button
                key={t.key}
                type="button"
                onClick={() => onChange(t.key)}
                className={cn(
                    'group flex items-start gap-2 rounded-xl border bg-card/40 p-3 text-left transition-all',
                    'hover:border-primary/50 hover:bg-card focus:outline-none focus-visible:ring-2 focus-visible:ring-primary',
                    active
                        ? 'border-primary bg-primary/10 ring-1 ring-primary/40'
                        : 'border-border',
                )}
                aria-pressed={active}
            >
                <span className="mt-0.5 shrink-0 rounded-lg bg-background/60 p-1.5">
                    <Icon className={cn('h-4 w-4', t.accent)} />
                </span>
                <span className="min-w-0">
                    <span className="block truncate text-sm font-medium">
                        {t.label}
                    </span>
                    <span className="block text-xs text-muted-foreground">
                        {t.description}
                    </span>
                </span>
            </button>
        );
    })}
</div>
```

Picker rules:

- 2 columns mobile (`grid-cols-2`), 3 on `sm:`. Never 4+ — break into rows.
- Each tile shows an icon, a label, and a one-line description.
- Active state uses **primary** ring + tint, never status colours (a "selected"
  tile is not warning/critical).
- `aria-pressed` on every tile.

## Dropdowns — modern, and searchable when the list is long (Stephan 2026-10-26)

Applies to every dropdown in the app: pop-ups, page filters, settings and forms.

**Pick the control by the number of choices.**

| Choices | Control |
|---|---|
| 2–4 fixed choices | Segmented choice, or choice tiles when each needs a line of explanation |
| 5–7 fixed choices | A dropdown (no search needed) |
| 8 or more, or a list that can grow | **A searchable dropdown** |
| Several at once | The multi-select combobox (chips in the field, searchable list) |
| Existing records (people, clients, Sites, vehicles, assets, accounts, suppliers) | A searchable record selector — the rules in the next section |

A list "can grow" when it comes from data or settings, not from code: categories,
codes, templates, accounts, teams, roles, countries. Those are always
searchable, even if they are short today.

**The modern look (every dropdown, searchable or not).**
- The trigger is the same height, radius, border and focus ring as a text field.
  It shows the chosen option, or a muted placeholder such as "Choose a category", with a chevron on the right.
- The open list is a popover on `--popover`, with the hairline border and soft shadow, at least the trigger's width (min 240 px).
  It is at most about 320 px tall and scrolls inside.
- Options are comfortable rows (32–36 px):
  - a leading icon, colour dot or avatar only when it helps tell options apart;
  - a muted second line for extra detail;
  - a tick on the selected option.
- Long lists can be grouped under small muted headings. Put a short "Recent" or "Suggested" group first when it helps.
- Disabled options say why, in their second line.

**Searchable dropdowns also have:**
- A search box at the top of the open list, focused as it opens ("Search categories…").
- Matching on the option's name and any useful code. Results narrow as you type.
- A quiet "No matches" line, with "Clear search" if useful. Never an empty white box.
- Keyboard support: type to search, arrows to move, Enter to choose. Escape closes the list only; it never closes the pop-up or discards the form.
- No custom value unless the field allows one. Then "Add “…”" is the last row, and it never auto-selects the first match.

**Lists people can add to** (configurable catalogues such as types, purposes,
categories and presets):
- Search the existing values first, then offer a clearly labelled "Add “…”".
- A new value is trimmed and given tidy spacing, a duplicate in different case is refused, and length is limited. Errors say why.
- The new value is selectable straight away and in every future use of the list.
- Adding to the list is its own action, separate from saving the form. If the new value survives cancelling the form, say so.
- A field that must point to a real record (a person, client, Site, vehicle or provider) never accepts free text. Creating such a record belongs to its own workflow.
- Small fixed lists (status, yes / no, answer type) never gain new values.
- Review steps show readable names, never bare IDs.

**Build it once.** Build on the shared `Popover` + `Command` primitives
(`components/ui/popover.tsx`, `components/ui/command.tsx`), with the same look as
`MultiSelectCombobox`.
- One shared single-select searchable dropdown lives in `components/ui/`. A module never builds its own.
- New work does not use a native `<select>`.
- The Radix `Select` stays only for the short fixed lists in the table above.

## Searchable record selectors

Approved addition by Stephan, 2026-09-20. Applies to record selection in simple
dialogs and wizard steps; it does not change the two dialog tiers or type-picker
rules above.

**Use search for a directory that can grow** — affected assets, vehicles, staff,
clients, sites, suppliers or similar existing records. Do not require scrolling
through a long dropdown. Small fixed enum choices keep their existing select or
tile control. A record locked by the parent context stays a read-only context
card; do not add a search control that can silently change its parent.

Reuse the shared `Popover` and `Command` primitives from
[`components/ui/popover.tsx`](../resources/js/components/ui/popover.tsx) and
[`components/ui/command.tsx`](../resources/js/components/ui/command.tsx).
[`MultiSelectCombobox`](../resources/js/components/ui/multi-select-combobox.tsx)
is an existing option for bounded multi-selection; it is not a server directory
or a single-record selector. For scoped server-search behavior, inspect
[`CatalogueEntityPicker`](../resources/js/components/it/catalogue-entity-picker.tsx)
and [`ProvisioningPicker`](../resources/js/components/it/provisioning-picker.tsx).
Their IT endpoints, permissions and field schemas remain IT-owned: reuse the
interaction pattern with the target module's authorised source, not its transport
or business rules. Do not fork the visual primitives into each modal.

- Search by the displayed name and useful permitted identifiers, such as asset
  tag, registration or reference. Show name first, with reference and site/type
  where they distinguish similar records. Store the canonical record ID; typed
  search text does not create a record or become its identity. Keep custom-value
  entry disabled for existing-record relationships.
- Show the current selection clearly, including when it is outside the current
  result page. Provide Change and, only for optional fields, Clear. Cancelling
  search leaves the prior selection intact; never auto-select the first match.
- Small, complete authorised lists may filter locally. Large directories use
  bounded, paged server search and an explicit way to obtain more results.
  Search the permitted directory, not just the first loaded page. Debounce and
  cancel/ignore obsolete requests so an old query cannot replace current results.
- Distinguish loading, no matching records, load failure with Retry, and loss of
  access. A failed request is not an empty directory. Do not imply that a limited
  result page is the entire list. Retain valid selection/form entries on transient
  failure; if access or eligibility changes, make the invalid selection explicit
  and require a valid choice rather than silently substituting another record.
- Scope both results and selected-record resolution to the action's roles,
  permissions, approved sites and canonical ownership. Submission revalidates
  that scope. Hidden options or a read-only field are not authorisation checks.
- Label the control and search field; support keyboard open, typing, arrow-key
  navigation and Enter selection, with visible focus and announced selection.
  Escape closes the open picker first and returns focus to its trigger; it must
  not accidentally discard the parent form. Keep the result list scrollable
  within the modal and visible above its overlay without escaping its focus trap.
  Associate errors with the selector and focus it on failed validation.

## Premium attachment uploads

Approved addition by Stephan, 2026-09-20. Invoice, check/evidence photo and
document attachment fields use the existing premium upload appearance in both
simple dialogs and wizard steps. A plain visible file input or a one-off dashed
box is not the attachment design contract.

Reuse [`FileDropzone` and `StagedFileCard`](../resources/js/components/ui/file-dropzone.tsx):
the shared dashed drop area, upload icon tile, browse affordance, semantic token
colours, image thumbnail/file glyph, filename, size and remove action. Keep the
same treatment across modules; labels and per-file metadata can describe the
owning record. Existing specialist image-editing/cropping flows keep their purpose.

`FileDropzone` supplies selection chrome and emits `File[]`; it does not validate
an upload policy, store files or certify a successful upload. `StagedFileCard`
shows a local selection. `AttachmentUploader` composes them for an existing record
with a compatible single-file endpoint and optional note/category/sensitivity
fields. Where the module's transport differs, compose these same primitives with
that module's adapter. Do not invent an endpoint or copy another module's access
rules to make the appearance work.

- Offer both drag/drop and Browse; Enter/Space must open file selection. Name the
  field, associate instructions/errors with it, and give each file action an
  accessible name identifying the file. Long filenames must remain identifiable
  without pushing the dialog or footer outside the viewport.
- State accepted types and file/count/size limits from the owning module's
  validated contract. Do not invent a universal quota or promise unsupported
  formats. Apply the same selection checks to dropped and browsed files; the
  server remains authoritative. Show actionable per-file or batch errors and
  retain the valid earlier selections when another file is rejected.
- For a new-record form, stage files and metadata until its established submit
  flow attaches them to the intended record; explain when upload occurs. For an
  existing record, make the upload action and target clear. Show staged,
  uploading, saved and failed states honestly: selection or a local thumbnail
  is not "Uploaded", and progress percentages require real progress data.
- Preserve text, selected files and supported metadata through Review/Back and
  failed saves within the form's supported lifetime. Explain when a file must be
  selected again; do not promise cross-session recovery of browser file objects.
  A partial failure distinguishes confirmed files from pending/failed files and
  retries only unresolved work using the owning module's recovery contract.
- Removing a staged file affects that local selection only. Existing attachment
  deletion/replacement follows the record's permissions, confirmation and audit
  rules; removing a card does not silently delete saved evidence. Guard close or
  navigation when it would lose staged files, and explain any upload still in
  progress. Cancelling a dialog does not imply a completed server upload rolled
  back.
- After server confirmation, show the persisted attachment identity and permitted
  view/download actions, with filename/type/size and author/time where available.
  Open/download access stays with the canonical record and approved site/privacy
  rules. A preview URL, sensitivity toggle or hidden button does not grant access.
  An uploaded invoice is evidence, not financial approval/posting; a new photo or
  document does not rewrite a submitted check or release a safety restriction.

These are required interaction and integration outcomes for callers. Importing
the shared visual components alone is not evidence that validation, recovery,
storage or access control has been implemented.

## File preview and download

Approved by Stephan 2026-09-27. Use the shared `FilePreviewDialog` for one
PDF/image, with the 900 px simple-dialog token, a bounded scrollable viewer,
page/zoom controls, accessible page text and a reachable Download action for
the exact original version. Multiple business sections retain WizardShell.
Metadata-only covers must not be called file previews. Loading, missing,
blocked, unsupported and failed/retry states are explicit, and each source
rechecks access on preview and download. See
[File preview and download](FILE_PREVIEW_STYLE_GUIDE.md) for the full contract.

## Calendar date and range selection

Approved addition by Stephan, 2026-09-20. Operational planning forms such as
Report a problem and Plan appointment share a consistent calendar interaction
and visible selected-date/range summary in simple dialogs and wizard steps.
Keep the current dialog shell and the separate approved full-calendar display
pattern; this section governs date entry inside the form.

Inspect the existing [`LeaveCalendarRange`](../resources/js/components/hr/leave-calendar-range.tsx)
and its [`leave-request-dialog`](../resources/js/components/hr/leave-request-dialog.tsx)
caller for month navigation, day selection, endpoint/range highlighting and
summary treatment. Reuse that interaction where the field contract fits. The
current HR component has required-date/holiday wording; module-specific labels
and optional mode need supported composition or an explicitly approved shared
component change. Do not fork a separate visual calendar per modal, copy HR
entitlement/working-hour rules, or claim the current component already supplies
every caller's required behavior.

- Label the purpose clearly, such as Estimated maintenance window or Appointment
  dates. Show the chosen day or both endpoints in a readable summary, including
  month/year where needed. Explain how to select a single day or range. Keep
  selection visible while navigating months, and distinguish an incomplete range
  from a complete one. Do not fabricate an end date from a partial selection.
- Required and optional states follow the owning workflow. An optional estimate
  may offer Choose dates / Not known yet; choosing unknown clears the submitted
  estimate with an explicit summary. An appointment that requires dates must
  validate them and cannot inherit an unknown-date option merely for consistency.
  Any earliest/latest-date restriction comes from the module's real contract.
- For timed appointments, present separate labelled start and end time fields
  below the calendar, with the applicable organisation/site timezone visible.
  Support a same-day or multi-day interval as permitted. Validate the resulting
  end after start and explain invalid/missing dates or times next to the controls.
- Preserve date-only values as local calendar dates; avoid UTC conversions that
  shift the chosen day. Timed values use the established timezone utilities and
  explicit handling of ambiguous/nonexistent daylight-saving times. A browser's
  local timezone must not silently change the meaning of a scheduled interval.
  State inclusive range/day-count or timed-duration semantics accurately; calendar
  days are not automatically paid hours, working days or confirmed downtime.
- Provide labelled month navigation, keyboard-operable day controls, visible
  focus and announced selection. Associate instructions and errors with the
  relevant field/group and focus it after failed validation. Keep the calendar
  within the modal's scroll/focus bounds and the action footer reachable.
- Retain valid dates, times and other entries through Review/Back, supported
  draft/close recovery and failed saves. Review shows the same interval and
  timezone before recording. If the parent resource changes, make the retained
  or cleared range explicit and submit against the current canonical parent.
- The owning source decides storage, calendar projection, permissions and any
  availability effect. Estimated windows, internal appointments, provider
  confirmation and actual restrictions stay distinguishable. Calendar selection
  alone does not confirm a provider booking, approve an expense or release a hold.

### Single-date observation and deadline fields

Approved clarification by Stephan, 2026-09-20. Observation timestamps and
next-action deadlines use the same calendar-selection visual with a single-day
contract. Label the field's purpose, show one selected date and a readable
single-date summary, and avoid range/day-count instructions on these fields.
Appointment or estimated-window fields keep ranges where their workflow needs
them; single-date selection does not inherit HR policy or required-date rules.

For a popover, open from the current value with a local pending selection. Month
navigation must not select a date. Use date applies the chosen day; Cancel,
Escape or dismissal without Apply retain the prior value and leave the parent
open, with focus returned to its trigger. Opening with no value must not silently
assign today; require selection before Apply. Keyboard opening focuses the
selected day, or an appropriate available day when no day is selected.

Applying a date preserves its paired time, and applying a time preserves its
date. Follow the Clock and manual time entry contract below for canonical values,
exact-minute input, timezone/DST, validation and recovery rather than inventing
another contract. A partial pair is a draft and must not be saved as a complete
timestamp. Both blank is valid only where the owning field permits it. Any clear
action must explain its actual effect, including when blank means keep the
current deadline. Retain applied values through Review/Back, draft resume and
failed-save recovery. Reuse or adapt approved primitives; the isolated prototype
does not authorise a production component change.

## Clock and manual time entry

Approved addition by Stephan, 2026-09-20, following the PKG-01 v6 clock/manual
appointment picker and the subsequent request for consistency across the mockup.
Operational date/time fields use the same interaction, including observed-at
timestamps, next-action deadlines and appointment Start/End. Keep the existing
dialog/wizard shell and the owning workflow's date control, required/optional
state and timestamp/interval meaning. An observation time is not an estimated
maintenance window, and a deadline is not a provider appointment.

Compose approved shared Popover/Button primitives, labelled input controls and
semantic design tokens. Use an existing suitable shared time component when one
is available and verified. The isolated v6 reference is a design composition,
not proof that a production shared time component exists. Creating or extending
one requires the applicable implementation approval; do not copy a separate
visual time picker into every modal or introduce a new design system.

- Show the current time and an obvious clock/typing affordance. Provide Hours
  and Minutes clock faces, editable hour/minute fields, AM/PM controls and a
  visible Type time / Clock switch. Selecting an hour can advance to minutes.
  Five-minute dial marks are a convenience; manual entry accepts every minute
  from 00 to 59 and must never silently round an exact value.
- Keep the field's canonical `HH:mm` value separate from its display format.
  In the 12-hour picker, accept hours 1–12 and map 12 AM to 00 and 12 PM to 12.
  Show AM/PM clearly in both the picker and selected-value summary. Reopening
  must represent the saved/current canonical value without changing it.
- Opening creates a local edit draft. Use time applies a valid draft; Cancel,
  Escape and dismissal without Apply retain the previously applied value.
  Cancelled picker edits must not mark the parent form dirty. Handle Escape at
  the picker first, keep the parent dialog open and return focus to its trigger.
  Support Enter to apply valid typed values without submitting the parent form.
- Give both time triggers and each hour/minute field meaningful labels. Clock
  marks and mode/AM-PM controls must be keyboard operable with visible focus and
  announced selection; a visual clock hand is decorative, not the only control.
  Provide keyboard adjustments and manual input without requiring pointer drag.
- Reject empty, nonnumeric and out-of-range values with an associated inline
  error and focus the affected input; never silently coerce them to another time.
  The parent still validates required dates/times and its full start/end interval
  before saving, including same-day, overnight or multi-day rules as applicable.
- Show the applicable organisation/site timezone. `HH:mm` alone is not an instant:
  combine it with the selected local date and timezone through established
  utilities, explicitly resolving or rejecting ambiguous/nonexistent DST times.
  Never let the browser's timezone silently change the scheduled interval.
- Preserve applied dates/times and other valid entries through Review/Back,
  supported draft/close recovery and failed saves. Review must show the same
  interval/timezone. Retrying a failed save must follow the owning workflow's
  duplicate-prevention contract rather than creating another appointment.
- Use collision-aware placement and appropriate bounds so the digits, clock,
  errors and Use time/Cancel footer remain reachable at the approved viewport
  sizes and supported zoom. Verify focus with the parent dialog; positioning
  must not trap controls outside the viewport or introduce horizontal overflow.

The reference prototype demonstrates UI and local state only. Its lexical
wall-clock comparison does not resolve Auckland DST ambiguity/nonexistent times;
that remains an implementation requirement. This pattern does not supply server
validation, permissions, persistence, provider confirmation, availability rules
or release authority, and it does not approve changes to those contracts.

## Field group rules

### Compact date and time fields in modals

Inside dialogs, wizards and sheets, the shared date/time controls inherit compact
presentation from the shared shell. Use `compact` explicitly for an inline form
that needs the same presentation. A timestamp
is an ordinary field group: one purpose legend with its timezone, short Date and
Time labels, and single-line calendar/clock triggers at least 44 CSS pixels high.
Do not put a second padded card around the pair or repeat the purpose in each
visible label. Keep the full purpose in each trigger's accessible name. Let the
pair stack according to the space inside the form, not just the screen width.

Compact changes presentation only. Preserve exact-minute entry, the calendar and
clock, pending selection and Cancel/Escape behaviour, required/optional clearing,
inline errors and the paired value. Keep picker footers reachable on a 320 CSS
pixel viewport. eMAR dialog shells and confirmations opt into `frontline` so
smaller fields never mean smaller touch targets. Footer actions wrap when needed.

Compact switches and checkboxes keep the shared primitive's pill or square
shape. Radix renders these controls as buttons: never apply the generic 44px
button minimum to their drawn marks. In frontline dialogs, use the expanded
hit area with sufficient row spacing, and verify it remains clickable outside
the mark. Show On/Off beside switches. Review-card rows stack when the card
itself is narrow, including cards in desktop two-column wizards.

Use Tailwind's `grid gap-3 sm:grid-cols-2` for the body, then `sm:col-span-2`
on full-width fields. Group related fields in the same row.

- Required fields show a red asterisk in the `<Label>`:
  `Name <span className="text-status-critical">*</span>`.
- Errors render via the `FieldError` helper directly under the input —
  `mt-1 text-xs text-status-critical`.
- Placeholders are realistic examples (`+64 21 …`, `e.g. Approval of Annual Budget 2026`),
  not field repetitions.
- Operational date/time fields follow Clock and manual time entry above, plus
  Calendar date and range selection where applicable; the native input defaults
  below apply to fields outside those scoped patterns.
- Date inputs use `type="datetime-local"` for date+time, `type="date"` for
  date-only.

## Locked context

When a dialog is opened from a parent context (e.g. "New Resolution" from a
specific meeting, "Add Contact" from an Overview row), show the locked value as
an info card instead of an editable select:

```tsx
<div className="flex items-start gap-3 rounded-xl border border-primary/40 bg-primary/10 p-3">
    <span className="mt-0.5 shrink-0 rounded-lg bg-background/60 p-1.5">
        <Icon className="h-4 w-4 text-primary" />
    </span>
    <div className="min-w-0 flex-1">
        <div className="flex flex-wrap items-center gap-2">
            <span className="text-sm font-medium">{lockedLabel}</span>
            <Badge variant="outline" className="text-[10px]">
                From meeting
            </Badge>
        </div>
        <p className="mt-0.5 text-xs text-muted-foreground">
            Locked from the meeting you opened.
        </p>
    </div>
</div>
```

## Submit button

- Always include a `<Loader2 className="mr-2 h-4 w-4 animate-spin" />` while
  `form.processing` is true. (Interim: once the Event Horizon ring-only
  spinner ships — see `LOADER_STYLE_GUIDE.md` — it replaces `Loader2` as the
  inline button spinner.)
- Use sentence-case verbs: "Save contact", "Create resolution", "Approve
  minutes" — not "Submit", "OK", "Save".
- The submit button is the **primary** filled button. Cancel is `variant="outline"`.
- Destructive actions (delete) use `variant="destructive"`.

## Confirmation dialogs

For delete / archive / discard:

```tsx
<Dialog open={isOpen} onOpenChange={(open) => !open && onClose()}>
    <DialogContent className="max-w-md">
        <DialogHeader>
            <DialogTitle>Delete contact?</DialogTitle>
            <DialogDescription>
                <span className="font-medium">{name}</span> will be removed from
                this site. This cannot be undone.
            </DialogDescription>
        </DialogHeader>
        <DialogFooter>
            <Button variant="outline" onClick={onClose}>
                Cancel
            </Button>
            <Button
                variant="destructive"
                onClick={handleDelete}
                disabled={submitting}
            >
                {submitting && (
                    <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                )}
                Delete contact
            </Button>
        </DialogFooter>
    </DialogContent>
</Dialog>
```

The title ends with a question mark and the destructive verb appears in the
button label, not the title.

## Read-only / show dialog

When the dialog is a detail viewer (not a form), build the header with an icon
tile + name + subtitle and the body with `ContactDetailRow` style key/value
rows. Footer offers `Close` plus contextual `Edit` / `Delete` buttons when the
user has permission.

## Accessibility

- Dialog inherits focus trap + ESC-to-close from the shadcn Radix wrapper —
  don't reinvent it.
- Always set `DialogTitle` and `DialogDescription`. Radix will pull them into
  `aria-labelledby` / `aria-describedby` automatically.
- Icon-only buttons inside the dialog (e.g. "Remove section") need `aria-label`.
- Tile pickers expose `aria-pressed`.

## Permissions

Gate the **trigger button**, not the dialog. If a user can't perform the action,
they shouldn't see the button. Inside the dialog, server-side validation is the
final check.

## Inertia integration

- Always use `useForm` + `form.post(...)` / `form.put(...)`.
- Pass `preserveScroll: true, preserveState: true` so the parent list doesn't
  jump or unmount.
- Close the dialog on success via `onSuccess: () => onClose()`.
- For optional `onCreated` / `onUpdated` callbacks (e.g. refreshing a counter
  on the parent), call them inside `onSuccess` before `onClose`.

## What NOT to do

- ❌ Don't navigate to a full create page when a popup will do.
- ❌ Don't build multi-step chrome by hand (steppers, progress bars, success
  screens) — compose `WizardShell` from `components/wizard/shell.tsx`.
- ❌ Don't use the legacy `components/wizard-stepper.tsx` horizontal chip
  stepper in new work — it survives only in the old full-page site wizard.
- ❌ Don't render a dialog inline using `useState` toggles that re-mount the
  whole `Dialog` — use the shell pattern.
- ❌ Don't use a `<Select>` for choice-of-category — use the tile picker.
- ❌ Don't omit the `DialogDescription`. Even a one-liner is required.
- ❌ Don't use icon-only labels in the title. Always include a text label.
- ❌ Don't put validation messages in `toast()` — they belong inline under the
  field.

## Quick checklist for a new dialog

- [ ] File viewers use the shared preview pattern and display real permitted
      bytes, with original-version download and truthful failure states.

- [ ] Lives in `_dialogs.tsx` next to the page that opens it.
- [ ] Shell + body split with `{isOpen && (...)}`.
- [ ] Width uses one of the canonical tokens (`max-w-md / max-w-xl / max-w-2xl`).
- [ ] Header has icon + title + one-line description.
- [ ] Tile picker for any category choice (not `<Select>`).
- [ ] Growing record lists use searchable, scoped selectors with visible selection,
      keyboard support and distinct loading / no-results / error states.
- [ ] Attachment fields reuse the premium upload pattern, state the actual file
      limits and distinguish staged files from confirmed uploads.
- [ ] Search/upload failures and Review/Back preserve valid entries; dirty-close
      behavior, error focus and attachment access follow the owning record.
- [ ] Planning dates use the shared calendar-selection pattern and visible summary;
      required/optional behavior, separate times/timezone, validation and recovery
      follow the owning workflow without importing HR policy.
- [ ] Operational time fields provide clock/manual exact-minute entry, correct
      AM/PM conversion, local draft with Cancel/Apply, picker-first Escape/focus,
      associated validation, visible timezone and a reachable action footer.
- [ ] Observation/deadline calendars select one day, preserve the paired time,
      and use local Cancel/Use date; ranges and blank/clear semantics follow the
      owning field. Month browsing must not silently change the selection.
- [ ] Required fields marked with `*` and validated server-side.
- [ ] `Loader2` shown while processing.
- [ ] Cancel button on the left, primary submit on the right.
- [ ] `preserveScroll` and `preserveState` set on the Inertia request.
- [ ] Trigger button gated by permission.
- [ ] Accessible (`DialogTitle`, `DialogDescription`, `aria-label`, `aria-pressed`).
