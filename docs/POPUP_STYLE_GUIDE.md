# Popup / Dialog Style Guide

Every modal popup in Oblivion Findings — create dialogs, edit dialogs, confirmation
dialogs, detail viewers — follows the same look-and-feel so users can recognise
them instantly. The reference implementation is
[`resources/js/pages/sites/contacts/_dialogs.tsx`](../resources/js/pages/sites/contacts/_dialogs.tsx)
(Site Contact dialogs) and
[`resources/js/pages/Governance/Resolutions/_dialogs.tsx`](../resources/js/pages/Governance/Resolutions/_dialogs.tsx)
(New Resolution dialog). When you build a new popup, follow this guide.

## When to use a popup vs. a full page

| Use a popup | Use a full page |
| --- | --- |
| Single resource create / edit (≤ 8 fields) | Multi-step wizard or > 8 fields |
| Quick confirmation (delete, archive) | Complex relations editor (e.g. assigning users to roles + permissions) |
| In-context detail view (contact card, KPI drill-in) | Anything that needs its own URL for sharing |
| Picking from a small set of options | List/index views |

If the form needs to scroll more than ~600 px even on desktop, it's a page, not a
popup.

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
  `form.processing` is true.
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
