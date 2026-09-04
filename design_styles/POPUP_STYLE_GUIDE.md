# Popup / Dialog Style Guide

Every modal popup in Oblivion Findings — create dialogs, edit dialogs, confirmation
dialogs, detail viewers — follows the same look-and-feel so users can recognise
them instantly. There are **two tiers** of dialog, and every popup is one of them:

1. **Simple dialog** — one screen of fields, or a confirmation/detail viewer.
   Reference implementations:
   [`resources/js/pages/sites/contacts/_dialogs.tsx`](../resources/js/pages/sites/contacts/_dialogs.tsx)
   (Site Contact dialogs) and
   [`resources/js/pages/Governance/Resolutions/_dialogs.tsx`](../resources/js/pages/Governance/Resolutions/_dialogs.tsx)
   (New Resolution dialog).
2. **Entity wizard dialog** — the `WizardShell` multi-step modal (stepper rail,
   step header, progress strip, completeness meter, success pane). **This is the
   default for adding and editing entity records** — sites, clients, staff,
   assets, incidents, risk assessments, and anything else with more than one
   section of fields. Reference implementations:
   [`resources/js/components/clients/add-client-dialog.tsx`](../resources/js/components/clients/add-client-dialog.tsx)
   (the original contract) and
   [`resources/js/components/sites/add-site-dialog.tsx`](../resources/js/components/sites/add-site-dialog.tsx).

When you build a new popup, follow this guide.

## When to use which

| Simple dialog | Wizard dialog (WizardShell) | Full page |
| --- | --- | --- |
| Single-section create/edit (≤ ~8 fields) | Add/edit of an entity record (site, client, staff, asset, …) | List/index views |
| Quick confirmation (delete, archive) | Any form with 2+ logical sections or steps | Anything that needs its own URL for sharing |
| In-context detail view (contact card, KPI drill-in) | Structured report flows (incident, injury, inspection) | Free-form canvas/editor surfaces (plan builder, rostering board) |
| Picking from a small set of options | Detail viewers with multiple SECTIONS (use `headerLabel`) | |

Do **not** send a long or multi-step form to a full page any more — that was
the old rule from before `WizardShell` existed. New create/edit flows for
entity records are wizard dialogs opened from the index page. (The remaining
full-page wizards — e.g. `pages/sites/create.tsx` / `edit.tsx` on the legacy
`wizard-stepper` — are migration targets, not precedents.)

## Entity wizard dialogs (WizardShell)

The shared chrome lives in
[`resources/js/components/wizard/shell.tsx`](../resources/js/components/wizard/shell.tsx):
`WizardShell` plus its companions `WizardStepPane`, `WizardSuccessPane`,
`ReviewCard`, and `ReviewRow`. Never rebuild this chrome by hand — ~90 dialogs
already compose it.

Anatomy (all provided by the shell — you supply content only):

- **Stepper rail** (248px, hidden below `sm`): entity icon tile, `railTitle` /
  `railSub` ("Add site" / "New location"), one button per step with icon,
  label, and one-line `blurb` ("Type, name & lead"). Completed steps show a
  green check; the active step is primary-tinted. Clickable via `onStepClick`.
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
function NewFooBody({ onClose, /* … */ }: NewFooBodyProps) {
    const form = useForm<FooFormValues>({ /* initial */ });

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
                    {form.processing && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
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
                    <span className="block truncate text-sm font-medium">{t.label}</span>
                    <span className="block text-xs text-muted-foreground">{t.description}</span>
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

## Field group rules

Use Tailwind's `grid gap-3 sm:grid-cols-2` for the body, then `sm:col-span-2`
on full-width fields. Group related fields in the same row.

- Required fields show a red asterisk in the `<Label>`:
  `Name <span className="text-status-critical">*</span>`.
- Errors render via the `FieldError` helper directly under the input —
  `mt-1 text-xs text-status-critical`.
- Placeholders are realistic examples (`+64 21 …`, `e.g. Approval of Annual Budget 2026`),
  not field repetitions.
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
            <Badge variant="outline" className="text-[10px]">From meeting</Badge>
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
                <span className="font-medium">{name}</span> will be removed from this site. This
                cannot be undone.
            </DialogDescription>
        </DialogHeader>
        <DialogFooter>
            <Button variant="outline" onClick={onClose}>Cancel</Button>
            <Button variant="destructive" onClick={handleDelete} disabled={submitting}>
                {submitting && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
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

- [ ] Lives in `_dialogs.tsx` next to the page that opens it.
- [ ] Shell + body split with `{isOpen && (...)}`.
- [ ] Width uses one of the canonical tokens (`max-w-md / max-w-xl / max-w-2xl`).
- [ ] Header has icon + title + one-line description.
- [ ] Tile picker for any category choice (not `<Select>`).
- [ ] Required fields marked with `*` and validated server-side.
- [ ] `Loader2` shown while processing.
- [ ] Cancel button on the left, primary submit on the right.
- [ ] `preserveScroll` and `preserveState` set on the Inertia request.
- [ ] Trigger button gated by permission.
- [ ] Accessible (`DialogTitle`, `DialogDescription`, `aria-label`, `aria-pressed`).
