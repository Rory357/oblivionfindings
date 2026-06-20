# PPE Redesign — Component API Bible

**Purpose.** The exact, copy-ready API of every existing component the PPE register (`/health-safety/ppe`) must compose. **Do not invent new primitives.** Every signature below is transcribed from source (file + line). Where a prop is optional it is marked `?`. Snippets are minimal-but-correct PPE-flavoured usages.

**Hard rules (from HANDOFF + tokens):** semantic tokens only — zero raw hex/oklch/`bg-amber-500`/`border-l-red-600`. The only sanctioned `no-restricted-syntax` exceptions are on-dark hero buttons (copy the existing `eslint-disable` comments verbatim from the kit). NZ-only, web-only.

---

## 0. Import cheat-sheet

```ts
// Hero chrome
import {
  HeroShell, HeroStatusPill, HeroMedallion, HeroCluster, HeroClusterTile,
  HeroComplianceBadges, HeroSegmented, HeroSummaryStrip, HeroSummaryMetric,
  fmt, type Tone, type HeroComplianceBadge, type HeroSegItem,
} from '@/pages/health-safety/components/hs-hero-kit';

// Workflow ribbon
import { WorkflowRibbon, type WorkflowStage } from '@/pages/health-safety/components/workflow-ribbon';

// Row kit
import {
  RegisterTableHeader, FlagBadge, TONE_BG, TONE_DOT, titleCase, initials, entityTone,
  type Tone as RowTone,
} from '@/pages/health-safety/components/register-row-kit';

// Tabs + ctx-menu + filters (barrel)
import {
  TabStrip, type RosterTabItem, type RosterTabTone,
  ShiftContextMenu, type ShiftCtxItem, type ShiftCtxState,
  EntityFilter, type EntityFilterOption,
  MultiEntityFilter, type MultiEntityItem,
} from '@/components/rostering';

// Wizard field primitives
import {
  Field, FieldErr, SubHead, StepHead, InfoCard, SelectInput, Segmented, ChipMulti, TilePicker, Ring,
  type IconType,
  WIZARD_RAIL_CLASS, WIZARD_PROGRESS_TRACK_CLASS, WIZARD_PROGRESS_BAR_CLASS, WIZARD_FOOTER_CLASS,
} from '@/components/wizard/primitives';

// Single-step / detail shell
import {
  WizardShell, WizardStepPane, WizardSuccessPane, ReviewCard, ReviewRow, type WizardStep,
} from '@/components/wizard/shell';

// shadcn pieces the wizards lean on
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { useForm, router } from '@inertiajs/react';
```

> **Note on the detail-as-modal:** there is no generic "rich detail dialog" export. `HsDetailDialog` (`hs-detail-dialog.tsx`) is a one-card read-only wrapper; `EventDetailDialog` (`event-detail-dialog.tsx`) is the rich, rail-as-sections + workflow-pane reference. For PPE you will **author a new `PpeItemDialog`** that wraps `WizardShell` exactly the way `EventDetailDialog` does (it is not exported for reuse — it is a *pattern* to copy). See §C.

---

## A. `hs-hero-kit.tsx` (`resources/js/pages/health-safety/components/hs-hero-kit.tsx`)

### `type Tone` (L25)
```ts
type Tone = 'success' | 'warning' | 'critical' | 'neutral';
```
Shared with `register-row-kit`'s `Tone` (identical union — compose without casts).

### `fmt(value, suffix?)` (L45)
```ts
function fmt(value: number | null | undefined, suffix = ''): string
```
Em-dash for null/undefined; `fmt(12)` → `"12"`, `fmt(60, 'd')` → `"60d"`, `fmt(null)` → `"—"`.

### `HeroShell` (L55) — gradient banner + optional footer band
```ts
{ children: ReactNode; footer?: ReactNode }
```
Footer renders below the main content inside a `border-t` band (same padding). **The PPE filter bar goes in `footer`.** Snippet in §1.

### `HeroStatusPill` (L78)
```ts
{ children: ReactNode }
```
Green ping-dot + uppercase eyebrow. PPE: `<HeroStatusPill>PPE register · synced just now</HeroStatusPill>`.

### `HeroMedallion` (L91)
```ts
{ icon: LucideIcon }   // hidden < sm
```
PPE: `<HeroMedallion icon={ShieldCheck} />`.

### `HeroCluster` (L146) + `HeroClusterTile` (L106)
```ts
// HeroCluster — labelled card wrapping a 2/4-col grid of tiles
{ title: string; icon: LucideIcon; children: ReactNode }

// HeroClusterTile — one KPI tile; href makes it a <Link> to the matching tab
{
  href?: string;
  label: string;          // UPPERCASE eyebrow, e.g. "ALLOCATED"
  value: string;          // big number — wrap counts in fmt()
  caption: string;        // sub-line, e.g. "issued to workers"
  tone: Tone;             // dot colour
  delta?: string;         // optional "▲ 3" trend line
  deltaTone?: Tone;       // default 'neutral'
}
```
**`value` is a string** — always pass `fmt(count)`, never a raw number. Two clusters required (Live·register / Needs attention).

### `HeroComplianceBadges` (L181) — counts/booleans, NEVER strings
```ts
{
  items?: HeroComplianceBadge[];    // OPTIONAL override — render this exact chip set instead of the canonical 5 H&S chips
  worksafeAwaiting?: number;        // canonical-row inputs (ignored if items passed)
  sdsExpiring?: number;
  drillsDue?: number;
  drillsOverdue?: number;
  ngaPaerewaCertified?: boolean;
  firstAidOk?: boolean;
}
type HeroComplianceBadge = { icon: LucideIcon; tone: 'success' | 'warning' | 'critical'; label: string };
```
**PPE MUST use the `items` override** — the canonical defaults are dashboard chips (WorkSafe/Ngā Paerewa/SDS/Fire/First-aid), not PPE. Build the PPE chip array **from counts/booleans in the page**, then pass the resolved `{icon,tone,label}[]`. The component itself only formats the canonical row from numbers; when you override you compute tone yourself — but still **derive tone from a count/boolean, never hand a pre-baked string around** (HANDOFF §1 "fed counts/booleans not strings"). See §E for the canonical pattern.

### `HeroSegmented` (L279) — period/lens/source control (on-dark)
```ts
{
  label?: string;
  items: readonly HeroSegItem[];   // HeroSegItem = { key: string; label: string; popover?: ReactNode }
  value: string;
  onChange: (key: string) => void;
  ariaLabel: string;
  variant?: 'pill' | 'segmented';  // default 'segmented'
}
```
`variant="pill"` is a self-contained label+pills group (one item may carry a `popover` — e.g. a custom date range). `variant="segmented"` is a **fragment** (label + bordered box as siblings) — place it inside your own flex row. PPE uses `pill` for Category/Status quick-filters in the footer.

### `HeroSummaryStrip` (L375) + `HeroSummaryMetric` (L364) — optional dot-led strip
```ts
// HeroSummaryStrip
{ label?: string; children: ReactNode; collapsed?: boolean; onToggle?: () => void; toggleLabel?: string }
// HeroSummaryMetric
{ tone: Tone; children: ReactNode }
```
Optional. Use only if you want a one-line "X overdue · Y expiring" strip with a Hide toggle.

---

## B. `workflow-ribbon.tsx` (`…/components/workflow-ribbon.tsx`)

### `WorkflowRibbon` (L35)
```ts
{ current: WorkflowStage }
type WorkflowStage = 'report' | 'investigate' | 'drill' | 'resolve' | 'analyse';
```
⚠️ **The ribbon's stage set is the H&S lifecycle (Report→Investigate→Drill→Resolve→Analyse), hard-coded to incidents/events/drills/corrective-actions/analytics URLs.** PPE's handoff asks for a *Catalogue→Stock→Issue→Inspect→Retire* spine — **that is NOT this component**. Options:
- **Simplest / on-pattern:** render `<WorkflowRibbon current="resolve" />` (or omit it) to keep the shared H&S breadcrumb. The PPE-specific "Catalogue→Stock→Issue→Inspect→Retire" stage strip is **not provided by any existing component** — if the design requires it, it is a small bespoke nav inside the hero (copy the ribbon's exact on-dark classes: `text-primary-foreground/70`, `bg-primary-foreground/20` active, `ChevronRight … text-primary-foreground/40`). Flag to the implementer: do not add a new shared primitive; inline it in the PPE page only.
- Place it as the **first child of `HeroShell`** (above the medallion row), exactly like `incidents/index.tsx` L408.

---

## C. `register-row-kit.tsx` (`…/components/register-row-kit.tsx`)

### `TONE_BG` (L18) / `TONE_DOT` (L25)
```ts
const TONE_BG: Record<Tone, string>   // chip bg+fg, e.g. 'bg-status-success-bg text-status-success'
const TONE_DOT: Record<Tone, string>  // solid dot, e.g. 'bg-status-success'
```

### `titleCase` (L32) / `initials` (L43) / `entityTone` (L51)
```ts
titleCase(s: string): string            // 'high_visibility' → 'High Visibility'
initials(label?: string | null): string // 'Aroha Ngata' → 'AN'; null → 'HS'
entityTone(id: number): string           // deterministic avatar bg class keyed off id
```

### `FlagBadge` (L57) — tinted flag chip (used in Next-inspection / Expiry / Flags cols)
```ts
{
  icon: LucideIcon;
  children: ReactNode;
  tone: 'critical' | 'warning' | 'success' | 'info' | 'neutral';
  title: string;        // REQUIRED — every flag pairs icon + title, never colour-only (a11y)
}
```
PPE: `<FlagBadge icon={AlertTriangle} tone="critical" title="Inspection overdue">Overdue</FlagBadge>`.

### `RegisterTableHeader` (L87) — card-header strip above the table
```ts
{
  icon: LucideIcon;
  title: string;
  subtitle?: string;
  hint?: string;          // right-aligned hint, e.g. "Right-click a row for the full lifecycle"
  hintIcon?: LucideIcon;  // e.g. MousePointer2
}
```

> The row kit deliberately holds **only** these helpers — there is no `RegisterTable` / `RegisterRow` component. You hand-roll the `<table>` per the Incidents page (§F) and tint every cell via `TONE_BG`/`TONE_DOT`/`FlagBadge`.

---

## D. `@/components/rostering` barrel (`resources/js/components/rostering/index.ts`)

### `TabStrip` (`tab-strip.tsx` L36) + `RosterTabItem`
```ts
// TabStrip
{
  value: string;
  onChange: (next: string) => void;
  items: RosterTabItem[];
  className?: string;
  ariaLabel?: string;     // default 'Roster views' — pass 'PPE views'
}
// RosterTabItem
{
  id: string;
  label: string;
  icon: ComponentType<{ className?: string }>;
  tone: RosterTabTone;    // 'primary' | 'warning' | 'success' | 'info' | 'violet' | 'critical'
  badge?: ReactNode;      // server count; pass `count || undefined` so 0 hides the pill
}
```
Flat list, no separators. Keyboard arrow-nav + `role=tablist/tab` are built in. Active tone classes drive the chip + underline-bar via `[&_.chip]` / `[&_.underline-bar]`.

### `ShiftContextMenu` (`shift-context-menu.tsx` L29) + state machine
```ts
// ShiftContextMenu
{ ctx: ShiftCtxState; onClose: () => void }

// ShiftCtxState — the object you setState when a row/hero is right-clicked
type ShiftCtxState = {
  x: number;            // e.clientX
  y: number;            // e.clientY
  tag: string;          // small UPPERCASE pill, e.g. severity/category label
  tagBg?: string;       // optional inline bg (CSS colour string) — omit to use default
  tagColor?: string;    // optional inline fg
  meta: string;         // muted secondary line, e.g. "Aroha Ngata · Respirator"
  items: ShiftCtxItem[];
};

// ShiftCtxItem — discriminated union: separator OR action row
type ShiftCtxItem =
  | { sep: true }
  | {
      sep?: false;
      icon: ReactNode;                 // pass a rendered element: <Eye className="h-3.5 w-3.5" />
      label: string;
      sub?: string;                    // secondary line under the label
      kbd?: string;                    // optional keyboard hint chip
      tone?: 'primary' | 'critical';   // tints the row + icon tile
      onClick?: () => void;            // menu auto-closes after onClick
    };
```
**State machine:** the menu is fully controlled by a single `ShiftCtxState | null` you hold in page state. It self-positions (clamps to viewport via `offsetWidth/Height`), closes on outside-click / Esc, and **calls `onClick` then `onClose` automatically**. Render conditionally: `{ctx ? <ShiftContextMenu ctx={ctx} onClose={() => setCtx(null)} /> : null}`. Build `items` **contextually per row** (gate on `can.manage` + status) using `...(cond ? [item satisfies ShiftCtxItem] : [])` spreads — see §F / Incidents L334-347.

### `EntityFilter` (`entity-filter.tsx` L42) — single-select pill (Site)
```ts
{
  label: string;                       // 'Site'
  allLabel: string;                    // 'All sites'
  items: EntityFilterOption[];         // { id: number; name: string; description?: string | null }
  value: number | null;
  onChange: (next: number | null) => void;  // null = cleared
  onDark?: boolean;                    // true in the hero footer
  className?: string;
  pluralLabel?: string;                // override for uncountables ('staff')
}
```

### `MultiEntityFilter` (`multi-entity-filter.tsx` L51) — multi-select pill
```ts
{
  label: string; allLabel: string; pluralLabel?: string;
  items: MultiEntityItem[];            // same { id, name, description? } shape
  value: number[];                     // [] = All
  onChange: (next: number[]) => void;
  onDark?: boolean; className?: string;
}
```
PPE site filter is single-select → use `EntityFilter` (mirror Incidents). Reach for `MultiEntityFilter` only if a multi-select is wanted.

---

## E. `wizard/primitives.tsx` (`resources/js/components/wizard/primitives.tsx`)

### Chrome constants (the Add-Client contract)
```ts
WIZARD_RAIL_CLASS            // 'hidden w-[248px] … border-r border-sidebar-border bg-sidebar p-4 sm:flex'
WIZARD_PROGRESS_TRACK_CLASS  // 'h-[3px] shrink-0 bg-muted'
WIZARD_PROGRESS_BAR_CLASS    // 'h-full bg-primary transition-[width] duration-300'
WIZARD_FOOTER_CLASS          // 'flex … justify-between … border-t border-border bg-muted/30 px-5 py-3.5'
```
You only need these if you hand-roll the shell (the multi-step create wizards do — see §A-pattern). `WizardShell` already bakes them in.

### `Field` (L54) + `FieldErr` (L44)
```ts
// Field
{ label?: string; required?: boolean; hint?: string; error?: string; span?: boolean; children: ReactNode }
// FieldErr (used internally by Field; rarely direct)
{ children?: ReactNode }   // renders nothing when empty
```
`required` adds the red asterisk; `hint` is a muted inline note; `error` renders the `FieldErr`; `span` makes it `sm:col-span-2`.

### `StepHead` (L105) — the per-step heading block
```ts
{ icon: IconType; title: string; blurb: string }
```

### `SubHead` (L90) — uppercase divider inside a step
```ts
{ icon: IconType; children: ReactNode }
```

### `InfoCard` (L127) — inline callout (NZ standard hints)
```ts
{ icon: IconType; tone?: 'info' | 'warn' | 'crit'; children: ReactNode }   // default 'info'
```
PPE: respiratory fit-test note → `<InfoCard icon={ShieldAlert} tone="warn">AS/NZS 1715 requires a fit-test for RPE…</InfoCard>`.

### `SelectInput` (L155) — themed shadcn Select wrapper
```ts
{
  value: string;
  onChange: (v: string) => void;
  placeholder: string;
  options: { value: string; label: string }[];
}
```
Value is always a **string** — map ids with `String(id)`. Empty string shows the placeholder.

### `Segmented<T>` (L182) — inline segmented toggle (condition / result)
```ts
{
  value: T;                                          // T extends string
  onChange: (v: T) => void;
  options: { value: T; label: string; icon?: IconType }[];
}
```
PPE condition: `<Segmented value={form.data.condition} onChange={(v) => form.setData('condition', v)} options={[{value:'new',label:'New'},…]} />`.

### `ChipMulti` (L218) — multi-select pill row (string values)
```ts
{ values: string[]; onChange: (v: string[]) => void; options: string[] }
```

### `TilePicker` (L255) — big tile single-select (PPE category)
```ts
{
  value: string;
  onChange: (v: string) => void;
  options: {
    key: string;
    label: string;
    description?: string;
    icon?: IconType;
    accent?: string;     // inactive icon colour class (semantic token, e.g. 'text-status-info')
    meta?: string;       // highlighted line under description
  }[];
  cols?: 2 | 3;          // default 2
}
```
PPE category picker (head/eye/ear/respiratory/hand/foot/high_visibility/fall_protection).

### `Ring` (L335) — SVG completeness ring for review steps
```ts
{ pct: number; size?: number }   // default size 56
```

### `type IconType` (L21)
```ts
type IconType = ComponentType<{ className?: string }>;   // lucide icons satisfy this
```

---

## F. `wizard/shell.tsx` (`resources/js/components/wizard/shell.tsx`)

### `type WizardStep` (L20)
```ts
type WizardStep = { key: string; label: string; blurb: string; icon: ComponentType<{ className?: string }> };
```

### `WizardShell` (L27) — full-height modal chrome (rail + header + progress + body + footer)
```ts
{
  open: boolean;
  onClose: () => void;
  title: string;                 // sr-only DialogTitle
  description: string;           // sr-only DialogDescription
  railIcon: ComponentType<{ className?: string }>;
  railTitle: string;             // bold rail heading
  railSub: string;               // muted rail sub-line
  steps: readonly WizardStep[];  // rail items
  stepIndex: number;             // active step (controlled)
  onStepClick: (index: number) => void;
  pct?: number | null;           // rail completeness bar; null/omit hides it
  pctLabel?: string;             // default 'Completeness'
  footerStart?: ReactNode;       // left footer slot (Close / status chips)
  footerEnd?: ReactNode;         // right footer slot (Options/Continue buttons)
  success?: ReactNode;           // when set, REPLACES the whole body (rail+steps) — success pane
  children?: ReactNode;          // the active step/section body
}
```
- Dialog sizing is **`min(94vw, 980px)`** (note: narrower than the create wizards' `1080px`). `[&>button]:hidden` (no shadcn close). Body height `min(88vh, 760px)`.
- Header auto-renders `Step {stepIndex+1} of {steps.length} · {label}` + an X (calls `onClose`).
- A 3px top progress bar fills to `(stepIndex+1)/steps.length`.
- **The rail doubles as section nav** for detail modals (EventDetailDialog uses `onStepClick` to switch sections, `pct={null}`).

### `WizardStepPane` (L213) — per-step body wrapper (300ms slide-in)
```ts
{ children: ReactNode }
```

### `WizardSuccessPane` (L222) — green-check success pane
```ts
{ title: string; blurb: ReactNode; actions: ReactNode }
```
Pass into `WizardShell success={…}` (single-step) OR render standalone (create wizards do their own `SuccessPane`).

### `ReviewCard` (L252) + `ReviewRow` (L292) — review/detail label-value cards
```ts
// ReviewCard
{ icon: ComponentType<{ className?: string }>; title: string; onEdit?: () => void; span?: boolean; children: ReactNode }
// ReviewRow
{ label: string; value?: ReactNode }   // em-dash when value is null/''
```
`onEdit` renders an "Edit" link (jump back to the owning step in a review; omit in read-only detail).

---

## G. `hs-detail-dialog.tsx` (`HsDetailDialog`) — the *thin* read-only wrapper

```ts
// HsDetailDialog
{ detail: HsDetail; onClose: () => void }

type HsDetail = {
  title: string; description: string;
  railIcon: LucideIcon; railTitle: string; railSub: string;
  cardTitle: string; cardIcon: LucideIcon;
  rows: HsDetailRow[];                  // HsDetailRow = { label: string; value: ReactNode }
  registerUrl?: string | null; registerLabel?: string | null;
  clientId?: number | null; staffId?: number | null;
};
```
This is a **single-card** `WizardShell` (one "Detail" step, footer = Close + deep-links + Print). **Too thin for the PPE item record** (HANDOFF §5 wants Overview / Allocation / Inspections / History sections + lifecycle actions). Use it only as evidence of the minimal shape; PPE needs the richer §C pattern below.

---

## H. CANONICAL PATTERNS

### (a) Multi-step create wizard — built exactly like `add-client-dialog.tsx`

The create wizards (Add inventory, Allocate PPE, Add PPE type) **hand-roll the shell** (they need `Save & add another` + per-step validation + jump-to-failure, which `WizardShell` doesn't orchestrate). Mirror `add-client-dialog.tsx` 1:1. Skeleton:

```tsx
// ---- step model ----
type StepKey = 'type_site' | 'identification' | 'condition' | 'review';
const STEPS: { key: StepKey; label: string; icon: IconType; blurb: string }[] = [
  { key: 'type_site',      label: 'Type & site',     icon: Package,  blurb: 'What and where' },
  { key: 'identification', label: 'Identification',  icon: IdCard,   blurb: 'Brand, model, serial' },
  { key: 'condition',      label: 'Condition & dates', icon: Calendar, blurb: 'Grade & expiry' },
  { key: 'review',         label: 'Review',          icon: ClipboardCheck, blurb: 'Confirm & create' },
];

// ---- map a server field → its step, for jump-to-first-failure ----
const STEP_FOR_PREFIX: { prefix: string; step: StepKey }[] = [
  { prefix: 'ppe_type_id', step: 'type_site' }, { prefix: 'site_id', step: 'type_site' },
  { prefix: 'brand', step: 'identification' }, { prefix: 'serial_number', step: 'identification' },
  { prefix: 'condition', step: 'condition' }, { prefix: 'expiry_date', step: 'condition' },
];
function stepForError(field: string): StepKey {
  for (const { prefix, step } of STEP_FOR_PREFIX) if (field.startsWith(prefix)) return step;
  return STEPS[0].key;
}

// ---- per-step validation, mirroring the FormRequest ----
function validateStep(key: StepKey, d: FormShape): Record<string, string> {
  const e: Record<string, string> = {};
  if (key === 'type_site') {
    if (!d.ppe_type_id) e.ppe_type_id = 'Choose a PPE type';
    if (!d.site_id) e.site_id = 'Choose a site';
  }
  // …identification / condition rules…
  return e;
}

// ---- shell: Dialog sized exactly like Add-Client ----
export function AddInventoryDialog({ open, onClose, /* options + onSaved */ }: Props) {
  return (
    <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
      <DialogContent className="overflow-hidden p-0 [&>button]:hidden"
        style={{ maxWidth: 'min(94vw, 1080px)', width: 'min(94vw, 1080px)' }}>
        <DialogTitle className="sr-only">Add inventory item</DialogTitle>
        <DialogDescription className="sr-only">Register a physical PPE item at a site.</DialogDescription>
        {open ? <Body onClose={onClose} /> : null}
      </DialogContent>
    </Dialog>
  );
}

// ---- body: state + Inertia useForm ----
function Body({ onClose }: { onClose: () => void }) {
  const form = useForm<FormShape>({ ppe_type_id: '', site_id: '', /* … */ });
  const { data, setData, processing } = form;
  const [stepIndex, setStepIndex] = useState(0);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [done, setDone] = useState(false);
  const cur = STEPS[stepIndex];
  const isReview = cur.key === 'review';

  const goToStep = (k: StepKey) => { const i = STEPS.findIndex(s => s.key === k); if (i >= 0) setStepIndex(i); };
  const next = () => { const e = validateStep(cur.key, data); setErrors(e); if (!Object.keys(e).length) setStepIndex(i => Math.min(i+1, STEPS.length-1)); };
  const back = () => setStepIndex(i => Math.max(i-1, 0));
  const resetAll = () => { form.reset(); form.clearErrors(); setErrors({}); setStepIndex(0); setDone(false); };

  const submit = (addAnother: boolean) => {
    const all: Record<string,string> = {};
    for (const s of STEPS) Object.assign(all, validateStep(s.key, data));
    if (Object.keys(all).length) { setErrors(all); goToStep(stepForError(Object.keys(all)[0])); return; }
    setErrors({});
    form.post('/health-safety/ppe/inventory', {
      forceFormData: true, preserveScroll: true, preserveState: true,
      onSuccess: () => { addAnother ? resetAll() : setDone(true); },
      onError: (errs) => { const f = Object.keys(errs)[0]; if (f) goToStep(stepForError(f)); },
    });
  };

  if (done) return <SuccessPane onClose={onClose} onAddAnother={resetAll} />;
  // …render: aside rail (copy add-client L939-1023) + main column header + 3px bar +
  //   body {isReview ? <Review/> : <StepBody…/>} + footer (copy L1060-1125: Back · Cancel ·
  //   [Save & add another | Create]  OR  Continue)…
}
```
**Key contracts to preserve verbatim** (add-client line refs):
- Dialog `style={{ maxWidth:'min(94vw,1080px)', width:'min(94vw,1080px)' }}` + `[&>button]:hidden` (L774-779).
- Rail = `WIZARD_RAIL_CLASS` markup (L939); numbered/checked steps (`Check` when `i<stepIndex`), completeness bar at `mt-auto` (L1011).
- Header `Step {n} of {N} · {label}` + X close (L1027-1040); 3px progress bar (L1043-1050).
- Body scroll container `min-h-0 flex-1 overflow-x-hidden overflow-y-auto px-6 py-6` (L1052).
- Footer (`WIZARD_FOOTER_CLASS`): left=Back (only `stepIndex>0`), right=Cancel + (review ? `Save & add another`(secondary) + `Create`(primary) : `Continue`) (L1060-1125). `Save & add another` only when **not** edit-mode.
- `useForm` submit: `forceFormData:true, preserveScroll:true, preserveState:true`; on submit **re-validate every step**, jump via `stepForError` (L861-902).
- Step bodies dispatched by a `switch(stepKey)` (L1153) using `<StepHead/>` + `<Field/>`-wrapped primitives; **never hand-roll inputs**.
- Edit-mode (Edit inventory): same body, `_method:'put'` transform → `PUT …/inventory/{id}`, no `Save & add another`, `setDone(true)` on success (L891-897).

### (b) Single-step action modal — via `WizardShell`

For Return PPE, Record inspection, Acknowledge, Condemn, Dispose, Edit/Deactivate type. One step, a `<form>` that owns its own submit buttons in the body (or via `footerEnd`). Mirror the EventDetailDialog panes (e.g. `WorksafeAcknowledgePane`, L647).

```tsx
const STEP: WizardStep[] = [{ key: 'return', label: 'Return PPE', blurb: 'Condition on return', icon: RotateCcw }];

function ReturnPpeDialog({ allocation, onClose }: { allocation: AllocationLite; onClose: () => void }) {
  const form = useForm<{ returned_condition: string; notes: string }>({ returned_condition: 'good', notes: '' });
  const submit = (e: FormEvent) => {
    e.preventDefault();
    form.post(`/health-safety/ppe/allocations/${allocation.id}/return`, {
      preserveScroll: true,
      onSuccess: (page) => { if (!(page.props as { flash?: { error?: string } }).flash?.error) onClose(); },
    });
  };
  return (
    <WizardShell open onClose={onClose} title="Return PPE" description="Record the condition of returned PPE."
      railIcon={RotateCcw} railTitle="Return PPE" railSub={allocation.label}
      steps={STEP} stepIndex={0} onStepClick={() => {}} pct={null}
      footerStart={<Button variant="outline" onClick={onClose}>Cancel</Button>}
      footerEnd={<Button onClick={submit} disabled={form.processing}>Record return</Button>}>
      <form onSubmit={submit} className="flex flex-col gap-4">
        <StepHead icon={RotateCcw} title="Return PPE" blurb="Grade the item so condemned stock is flagged for disposal." />
        <Field label="Condition on return" required error={form.errors.returned_condition}>
          <Segmented value={form.data.returned_condition} onChange={(v) => form.setData('returned_condition', v)}
            options={[{value:'new',label:'New'},{value:'good',label:'Good'},{value:'fair',label:'Fair'},{value:'poor',label:'Poor'},{value:'condemned',label:'Condemned'}]} />
        </Field>
        <Field label="Notes" hint="Optional" error={form.errors.notes}>
          <Textarea rows={3} value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} />
        </Field>
      </form>
    </WizardShell>
  );
}
```
**Flash-error guard** (mandatory): a blocked action returns 302 + `flash.error` (fires Inertia `onSuccess`, not `onError`). Only close/advance when `!page.props.flash?.error` (see `reference_inertia_flash_error`; pattern at event-detail-dialog L500, L597, L655 etc.).

### (c) Detail-as-modal with left rail — author `PpeItemDialog` like `EventDetailDialog`

This is the richest pattern. **Copy the structure of `event-detail-dialog.tsx`** (do not reuse `HsDetailDialog` — too thin). Shape:

```tsx
export type PpeSectionKey = 'overview' | 'allocation' | 'inspections' | 'history';
export type PpeActionKey = 'allocate' | 'inspect' | 'condemn' | 'dispose' | 'return' | 'acknowledge';

// one ActivePane per workflow form that replaces the body + owns its buttons
type ActivePane =
  | { kind: 'allocate' } | { kind: 'inspect' } | { kind: 'condemn' } | { kind: 'dispose' }
  | { kind: 'return' } | { kind: 'acknowledge' };
function paneFromAction(a: PpeActionKey | null): ActivePane | null { /* map like L278 */ }

export function PpeItemDialog({
  detail, open, onClose,
  initialSection = 'overview',
  initialAction = null,            // ctx-menu "Record inspection" opens straight onto the pane
}: {
  detail: PpeItemDetail; open: boolean; onClose: () => void;
  initialSection?: PpeSectionKey; initialAction?: PpeActionKey | null;
}) {
  const [section, setSection] = useState<PpeSectionKey>(initialSection);
  const [pane, setPane] = useState<ActivePane | null>(() => paneFromAction(initialAction));

  // re-sync derived section/pane when the SAME item is re-targeted (copy L346-356 useEffect)
  useEffect(() => { setSection(initialSection); setPane(paneFromAction(initialAction)); },
    [initialSection, initialAction]); // eslint-disable-line react-hooks/exhaustive-deps

  const SECTIONS: { key: PpeSectionKey; label: string; blurb: string; icon: IconType }[] = [
    { key: 'overview',    label: 'Overview',     blurb: 'Spec & status',  icon: FileText },
    { key: 'allocation',  label: 'Allocation',   blurb: detail.current_allocation ? 'Issued' : 'Available', icon: User },
    { key: 'inspections', label: 'Inspections',  blurb: `${detail.inspections.length} on record`, icon: ClipboardCheck },
    { key: 'history',     label: 'History',      blurb: 'Audit trail',    icon: History },
  ];
  const stepIndex = Math.max(0, SECTIONS.findIndex(s => s.key === section));

  // footerStart = condition/status chips; footerEnd = lifecycle launchers, suppressed while a pane is open
  const footerEnd = pane ? null : (
    <div className="flex flex-wrap items-center gap-2">
      {detail.can.manage && detail.status !== 'condemned' ? <Button size="sm" variant="outline" onClick={() => setPane({ kind: 'allocate' })}>Allocate</Button> : null}
      {detail.can.manage ? <Button size="sm" variant="outline" onClick={() => setPane({ kind: 'inspect' })}>Record inspection</Button> : null}
      {detail.can.manage && detail.status !== 'condemned' ? <Button size="sm" variant="outline" className="border-status-critical/40 text-status-critical hover:text-status-critical" onClick={() => setPane({ kind: 'condemn' })}>Condemn</Button> : null}
    </div>
  );

  return (
    <WizardShell open={open} onClose={onClose}
      title={`PPE item ${detail.serial_number ?? detail.id}`} description={`${detail.type_name} — ${detail.status}`}
      railIcon={ShieldCheck} railTitle={detail.serial_number ?? detail.type_name} railSub={`${detail.type_name} · ${titleCase(detail.condition)}`}
      steps={SECTIONS as readonly WizardStep[]} stepIndex={stepIndex} onStepClick={(i) => setSection(SECTIONS[i].key)}
      pct={null} footerStart={/* chips */ undefined} footerEnd={footerEnd}>
      {pane ? <PaneRenderer pane={pane} d={detail} onDone={() => setPane(null)} />
            : section === 'overview' ? <OverviewSection d={detail} />
            : section === 'allocation' ? <AllocationSection d={detail} onPane={setPane} />
            : section === 'inspections' ? <InspectionsSection d={detail} onPane={setPane} />
            : <HistorySection d={detail} />}
    </WizardShell>
  );
}
```
Section bodies use `ReviewCard`/`ReviewRow` for the spec grid + status chips (copy `OverviewSection` event-detail L1268-1330). Inline workflow buttons inside sections (Allocate / Return / Mark acknowledged / Record inspection) set `pane`, exactly like `InvestigationControls` (L971) / `CorrectiveActionControls` (L1181). Panes are routed by a `PaneRenderer` `switch(pane.kind)` (copy L679-707).

**Server-driven open/close (page-level, copy Incidents L244-247):**
```ts
const openDetail = (id: number) => router.get('/health-safety/ppe', { ...filters, item: id }, { preserveState: true, preserveScroll: true, only: ['detail'] });
const closeDetail = () => router.get('/health-safety/ppe', { ...filters }, { preserveState: true, preserveScroll: true, only: ['detail'] });
// allocation rows use { ...filters, allocation: id } instead of item.
// render: {detail ? <PpeItemDialog detail={detail} open onClose={closeDetail} initialAction={…} /> : null}
```

### (d) `ShiftContextMenu` state machine + wiring rows

Hold `const [ctx, setCtx] = useState<ShiftCtxState | null>(null)`. Each row builds its menu on right-click and each mutating item **opens a modal** (never bare nav):

```tsx
const openRowCtx = (e: ReactMouseEvent, it: InventoryRow) => {
  e.preventDefault();
  const tone = entityTone(it.id); // or a category Tone for the tag
  const items: ShiftCtxItem[] = [
    { icon: <Eye className="h-3.5 w-3.5" />, label: 'View', sub: it.type_name, tone: 'primary', onClick: () => openDetail(it.id) },
    ...(can.manage ? [{ icon: <Pencil className="h-3.5 w-3.5" />, label: 'Edit item', onClick: () => setEdit(it.id) } satisfies ShiftCtxItem] : []),
    ...(can.manage && it.status === 'available' ? [{ icon: <UserPlus className="h-3.5 w-3.5" />, label: 'Allocate to worker', onClick: () => openDetailAt(it.id, 'allocate') } satisfies ShiftCtxItem] : []),
    ...(can.manage ? [{ icon: <ClipboardCheck className="h-3.5 w-3.5" />, label: 'Record inspection', onClick: () => openDetailAt(it.id, 'inspect') } satisfies ShiftCtxItem] : []),
    ...(can.manage && it.status !== 'condemned' ? [{ sep: true } satisfies ShiftCtxItem, { icon: <XCircle className="h-3.5 w-3.5" />, label: 'Condemn', tone: 'critical', onClick: () => openDetailAt(it.id, 'condemn') } satisfies ShiftCtxItem] : []),
    { sep: true },
    { icon: <LinkIcon className="h-3.5 w-3.5" />, label: 'Copy link', onClick: () => navigator.clipboard.writeText(`${location.origin}/health-safety/ppe?item=${it.id}`) },
  ];
  setCtx({ x: e.clientX, y: e.clientY, tag: titleCase(it.category), meta: `${it.type_name} · ${it.serial_number ?? '—'}`, items });
};
// row: <tr onClick={() => openDetail(it.id)} onContextMenu={(e) => openRowCtx(e, it)} tabIndex={0}
//        onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openDetail(it.id); } }}
//        className="cursor-pointer transition-colors hover:bg-muted/45 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary">
// page tail: {ctx ? <ShiftContextMenu ctx={ctx} onClose={() => setCtx(null)} /> : null}
```
`openDetailAt(id, action)` = `router.get('/health-safety/ppe', { ...filters, item: id, action }, { preserveState, preserveScroll, only: ['detail'] })` and the page passes `initialAction={filters.action}` into `PpeItemDialog`. (Incidents does plain `openDetail`; PPE adds the `action` param to deep-link onto a pane — mirror EventDetailDialog's `initialAction`.) Allocation rows build a different item set (View · Mark acknowledged · Return PPE · Record inspection · Copy link) per HANDOFF §4.

### (e) `HeroComplianceBadges` fed counts/booleans

Build the chip array **in the page** from the `hero` prop's PPE counts, derive tone from those numbers, and pass via `items`:

```tsx
const ppeBadges: HeroComplianceBadge[] = [
  { icon: hero.rpe_fit_test_due > 0 ? AlertTriangle : CheckCircle2,
    tone: hero.rpe_fit_test_due > 0 ? 'warning' : 'success',
    label: `RPE fit-test · ${hero.rpe_fit_test_due} due` },              // AS/NZS 1715
  { icon: hero.inspections_overdue > 0 ? AlertTriangle : CheckCircle2,
    tone: hero.inspections_overdue > 0 ? 'critical' : 'success',
    label: hero.inspections_overdue > 0 ? `Inspections · ${hero.inspections_overdue} overdue` : 'Inspections · Current' },
  { icon: hero.expiring > 0 ? AlertTriangle : CheckCircle2,
    tone: hero.expiring > 0 ? 'warning' : 'success',
    label: `Expiring ≤60d · ${hero.expiring}` },
  { icon: hero.condemned > 0 ? AlertTriangle : CheckCircle2,
    tone: hero.condemned > 0 ? 'critical' : 'success',
    label: hero.condemned > 0 ? `Condemned · ${hero.condemned} awaiting disposal` : 'Disposal · Clear' },
  { icon: ShieldCheck, tone: hero.hivis_footwear_ok ? 'success' : 'warning',
    label: hero.hivis_footwear_ok ? 'Hi-vis & footwear · Covered' : 'Hi-vis & footwear · Gaps' }, // AS/NZS 4602 / 2210
];
// <HeroComplianceBadges items={ppeBadges} />
```
**The numbers/booleans come from the controller's `hero` block.** The component never receives a formatted string for its decision-making — tone is computed from the count. (If you instead used the canonical props, they'd render dashboard chips, which is wrong for PPE — hence `items`.)

---

## I. Page-shell wiring (copy `incidents/index.tsx`)

`incidents/index.tsx` is the structural template. Reuse verbatim:
- **Filter navigation** — `const go = (next) => router.get('/health-safety/ppe', { ...filters, ...next }, { preserveState: true, preserveScroll: true, replace: true })` (L237).
- **Tab change** — `router.get('/health-safety/ppe', { ...filters, tab: id }, { preserveScroll: true })` (L240).
- **Detail open/close** — `only: ['detail']` partial reloads (L244-247).
- **Clear filters** — `router.get('/health-safety/ppe', { tab }, …)` (L249).
- **Hero footer filter bar** — `HeroSegmented(period/category/status)` + `EntityFilter`(site) + search `<input>` (copy classes L383-394) + Clear (copy the `eslint-disable no-restricted-syntax` onDark clear button L396-404).
- **Layout** — `<AppLayout breadcrumbs=[{title:'Health & Safety', href:'/health-safety'},{title:'PPE & Equipment', href:'/health-safety/ppe'}]>`, `<Head title="PPE & Equipment" />`, `flex flex-col gap-6 p-6`.
- **Table** — hand-rolled `<table className="w-full text-sm">` with the `thead` uppercase header + `tbody divide-y` rows; tint via `TONE_DOT`/`TONE_BG`/`FlagBadge`; empty-state block (L563-571). Inventory vs Allocation vs Catalogue are three separate table renderers chosen by `rowsKind`/`tab`.
- **Pagination** — `import { LaravelPagination } from '@/components/ui/laravel-pagination'`; `{rows.last_page > 1 ? <LaravelPagination links={rows.links} /> : null}`.
- **"Add to register" launcher** — `Popover` + `PopoverTrigger` Button `className="bg-primary-foreground text-primary hover:bg-primary-foreground/90"` + `PopoverContent` with the three create entries (copy L422-446).

### Existing PPE data shapes (current `ppe/index.tsx` L47-100 — reuse field names)
```ts
type PpeType = { id; name; category; description; hazards_addressed; standards_reference; inspection_frequency; typical_lifespan_months };
type InventoryItem = { id; ppe_type; site; brand; model; serial_number; purchase_date; expiry_date; quantity; location; condition; status; next_inspection_due };
type Allocation = { id; user; inventory_item; /* + fit_test/training/acknowledged/returned fields — confirm in controller */ };
```
The redesign **adds** server props: `filters`, `tab`, `tabCounts`, paginated `inventory`/`allocations`, `types`, `sites`, `staff`, `hero`, `detail`, `can: { manage }` (HANDOFF §State management + Backend). Keep `hazards.manage` gate; add `can: { manage }` alongside the existing `can_manage`.

---

## J. Gaps / risks for the implementer

1. **No PPE-specific workflow ribbon.** `WorkflowRibbon` is the fixed H&S spine (report→investigate→drill→resolve→analyse). The handoff's *Catalogue→Stock→Issue→Inspect→Retire* strip is **not a shared component** — either drop it for the standard ribbon (`current="resolve"`) or inline a bespoke on-dark nav in the PPE page (do not add a new shared primitive). Decide explicitly.
2. **No reusable rich detail dialog.** `HsDetailDialog` is one-card-only; `EventDetailDialog` is bespoke to events and **not exported as a generic**. PPE must author `PpeItemDialog` by copying the EventDetailDialog structure onto `WizardShell` (§H-c). Budget for ~400-600 lines.
3. **WizardShell width ≠ create-wizard width.** `WizardShell` hard-codes `min(94vw, 980px)`; the create wizards (Add-Client clone) use `min(94vw, 1080px)`. The detail/single-step modals will be 980px, the multi-step create wizards 1080px — intentional, matches the references. Don't "unify" them.
4. **No `Save & add another` in `WizardShell`.** That affordance only exists in the hand-rolled Add-Client shell. The create wizards (inventory, type) therefore **cannot** use `WizardShell` — they hand-roll. Only the single-step actions + detail use `WizardShell`.
5. **HeroComplianceBadges default row is wrong for PPE.** Must pass `items` (computed from `hero` counts). Easy to miss — the props are all optional and it renders silently with dashboard chips otherwise.
6. **Flash-error guard is mandatory** on every action `onSuccess` (302 + `flash.error` fires `onSuccess`, not `onError`). Without it a blocked condemn/dispose silently "succeeds".
7. **`ShiftCtxItem.icon` is a rendered element, not a component** — pass `<Eye className="h-3.5 w-3.5" />`, not `Eye`. (Contrast `RosterTabItem.icon`/`WizardStep.icon`/`StepHead.icon` which take the component.)
8. **`HeroClusterTile.value` and `FlagBadge` etc. need `fmt()` strings** — counts must be `fmt(n)`, not raw numbers (value is typed `string`).
9. **Right-click must be gated** (`can.manage` + status) and **every mutating menu item opens a modal**, never `router.visit` to a form page (HANDOFF Interactions). Read-only items (View/Copy link/View worker) may navigate.
10. **New endpoints the modals target** don't all exist yet (`updateType`, type activate/deactivate, `acknowledge`, `condemn`, `dispose`) — the controller work is a prerequisite for those panes. Existing & reusable: `storeType`, `storeInventory`, `updateInventory`, `allocate`, `returnPpe`, `storeInspection`.
