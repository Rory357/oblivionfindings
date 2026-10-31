# Pop-up spec format (one JSON object per pop-up drawing)

Every pop-up in the app is drawn from ONE of the kit templates below, filled with
its REAL content from the code: title, one-line description, fields (labels,
kinds, required, hints, options), steps, and button labels. Use plain NZ English,
sentence case ("Add client", not "Add Client"). Example values use the shared
demo world: client **Hemi Walker** (Aurora House, Room 3, key worker Rāwiri
Kingi, GP Dr Anna Lee · Hamilton East Medical, NHI ZAC5961), other people Mere
Tane, Aroha Ngata, Jordan Peri, Ana Ruha; Sites Aurora House, Kōwhai Lodge.
Dates like "Mon 2 Nov 2026", times like "9:30 am".

## Templates (the whole kit — pick one)

| template | size | use when |
|---|---|---|
| `wizard` | `xl` (1100) | creates or edits a main record AND has 3+ real sections or more than ~12 fields (Add client, care plan, ABC entry, incident) |
| `form` | `sm` 480 (≤ 3 fields) · `md` 720 (≤ ~10 fields) · `lg` 900 (sections inside) | any other add / edit / record / send. A 1-step or short 2-step "wizard" becomes a `form` (sections become `sections`) |
| `confirm` | `sm` 480 | yes / no. `variant`: `plain`, `destructive` (data removed), `reason` (a reason is captured), `alert` (one OK button) |
| `viewer` | `sm` / `md` / `lg` | read-only: a record's details, a dose, a file |
| `panel` | `sm` 480 · `md` 640 (slides in from the right) | quick look / manage a record beside a list, often with `tabs` |
| `settings` | `md` | settings with switch rows (Fleet pattern) |
| `picker` | popover | search pickers, people pickers |
| `menu` | popover | kebab / right-click menus (one item list) |
| `bar` | floating | selection / bulk-action bar |

## Object shape

```json
{
  "id": "clients-17",
  "inv": [17],                     // inventory row numbers this drawing covers (merges list several)
  "template": "form",
  "size": "lg",
  "variant": null,                 // confirm only
  "icon": "shield-alert",          // lucide icon name (kebab-case) — use the one the code uses when it has one
  "title": "Add risk",
  "sub": "One line under the title: what this does, plain words.",
  "source": "components/clients/profile/flows.tsx:325",
  "was": "2-step wizard (The risk · Controls), 7 fields",   // what it is today, short
  "change": "Becomes a large form with two sections",       // what changes in the kit, short
  "context": { "title": "Hemi Walker", "tag": "Aurora House", "note": "From the profile you opened" }, // optional locked row
  "callout": { "tone": "info|warn|crit", "text": "..." },   // optional banner at top of body

  // wizard only:
  "steps": [
    { "title": "Basics", "blurb": "Identity, placement & contact", "icon": "user",
      "question": "Who are we adding?", "line": "Identity, where they're supported, and how to reach them.",
      "sections": [ { "title": "Identity", "icon": "id-card", "fields": [ ...field ] } ] }
  ],

  // form / settings / panel body:
  "sections": [ { "title": "The risk", "icon": "alert-triangle", "fields": [ ...field ] } ],
  "fields": [ ...field ],          // use when there are no sections

  // confirm only:
  "body": "<b>Hemi's GP letter</b> will be deleted. This can't be undone.",
  "reason": { "label": "Reason", "required": true, "placeholder": "e.g. ..." },

  // viewer only:
  "rows": [ ["Given by", "Rāwiri Kingi"], ["When", "Mon 2 Nov, 8:04 am"] ],

  // panel only:
  "ref": "GOAL · HEMI WALKER", "badges": [["info","In progress"],["neutral","60%"]], "tabs": ["Progress","Sub-goals","Hurdles","Details"],

  // menu / picker only:
  "items": [ { "label": "Edit details", "icon": "pencil" }, { "sep": true }, { "label": "Archive", "icon": "archive", "danger": true } ],

  "footer": { "primary": "Add risk", "secondary": null, "left": null, "cancel": "Cancel" }
}
```

### field

```json
{ "kind": "text", "label": "First name", "required": true, "hint": "what they like to be called",
  "value": "Hemi", "placeholder": "e.g. Walker", "options": ["A","B"], "unit": "ml", "on": true, "wide": false }
```

`kind` is one of:
- `text`, `textarea`, `number` (with `unit`), `select` (shows chosen `value` or `placeholder`),
- `date`, `time`, `datetime`, `daterange` (drawn with the approved date & time pickers — never a plain browser box),
- `tiles` (big choice tiles — `options` as `["Label|small line|icon", ...]`, `value` = chosen label),
- `segment` (short one-of buttons — `options`, `value`),
- `chips` (multi-choice tags — `options`, `value` as array),
- `switch` (`label`, `hint`, `on`),
- `checkbox` (`label`, `on`),
- `person` (one person picker — `value` "Rāwiri Kingi", `hint`),
- `people` (several people — `value` array),
- `upload` (file drop zone — `hint` e.g. "PDF, JPG or PNG, up to 10 MB"),
- `rows` (repeating rows, e.g. conditions or contacts — `value` array of short strings, `add` label),
- `readonly` (shown, not editable — `value`),
- `note` (an info line inside the form — `text`).

`wide: true` makes a field span the full width (textareas, uploads, tiles usually are).

Keep REAL labels, options and button words exactly as the code has them,
except: fix Title Case to sentence case, and name the main button for what it
does ("Save risk", not "Submit"). Never invent fields the code doesn't have.
If a field is conditional, include it and add `"hint": "only when …"`.
