# Lone Worker Safety redesign — UI Kits audit (03)

The redesign of `/health-safety/lone-workers` must **compose** the existing H&S gold-standard
kits, not reinvent any primitive. This document is the exact API contract for each kit the
build will use: paths, exports, full TS prop signatures, and minimal usage snippets — plus the
canonical end-to-end composition observed in `health-safety/events/index.tsx` (the closest
sibling register: hero + ribbon + tabs + register table + context menu + detail modal).

All kits use **semantic design tokens only** (no raw oklch/hex), the **app-primary gradient**
only (no per-site brand tint), and are **NZ-only / web-only**.

---

## 1. Hero kit — `resources/js/pages/health-safety/components/hs-hero-kit.tsx`

Import via `@/pages/health-safety/components/hs-hero-kit`. The single source of the H&S hero
chrome (eyebrow pill, medallion, stat clusters, NZ compliance badges, segmented controls,
summary strip). `/health-safety` and `/health-safety/analytics` compose the *identical*
primitives — Lone Workers must too.

### Exports

| Export | Kind |
|---|---|
| `type Tone` | `'success' \| 'warning' \| 'critical' \| 'neutral'` |
| `DOT_CLASS: Record<Tone, string>` | const map (dot bg classes) |
| `fmt(value: number \| null \| undefined, suffix = ''): string` | em-dash for null/undefined, else `${value}${suffix}` |
| `HeroShell` | gradient banner wrapper + optional footer band |
| `HeroStatusPill` | animated green ping dot + uppercase eyebrow |
| `HeroMedallion` | 72–80px circular icon medallion (hidden < sm) |
| `HeroClusterTile` | one KPI tile (optional link + delta) |
| `HeroCluster` | labelled cluster card wrapping tiles |
| `type BadgeTone` (not exported) | `'success'\|'warning'\|'critical'` (internal) |
| `HeroComplianceBadges` | the 5 canonical NZ compliance chips |
| `type HeroSegItem` | `{ key: string; label: string; popover?: ReactNode }` |
| `HeroSegmented` | period/lens segmented control (`pill` or `segmented`) |
| `HeroSummaryMetric` | one dot-led metric for the summary strip |
| `HeroSummaryStrip` | dot-led summary strip with optional Hide toggle |

### Prop signatures

```ts
function HeroShell({ children, footer }: { children: ReactNode; footer?: ReactNode })
function HeroStatusPill({ children }: { children: ReactNode })
function HeroMedallion({ icon }: { icon: LucideIcon })

function HeroClusterTile({
    href?: string;        // makes the tile a <Link>; omit for static tile
    label: string;
    value: string;        // pre-formatted; pair with fmt()
    caption: string;
    tone: Tone;           // colours the leading dot
    delta?: string;       // optional ▲/▼ trend line (analytics)
    deltaTone?: Tone;     // default 'neutral'
})
function HeroCluster({ title: string; icon: LucideIcon; children: ReactNode })

function HeroComplianceBadges({
    worksafeAwaiting: number;            // >0 → warning chip
    sdsExpiring: number;                 // >0 → warning chip
    drillsDue?: number;                  // default 0 → warning
    drillsOverdue?: number;              // default 0 → critical (outranks drillsDue)
    ngaPaerewaCertified?: boolean;       // default true
    firstAidOk?: boolean;                // default true
})

type HeroSegItem = { key: string; label: string; popover?: ReactNode };
function HeroSegmented({
    label?: string;
    items: readonly HeroSegItem[];
    value: string;
    onChange: (key: string) => void;
    ariaLabel: string;                   // REQUIRED
    variant?: 'pill' | 'segmented';      // default 'segmented'
})

function HeroSummaryMetric({ tone: Tone; children: ReactNode })
function HeroSummaryStrip({
    label?: string;
    children: ReactNode;
    collapsed?: boolean;                 // default false
    onToggle?: () => void;               // present → renders Hide/Show affordance
    toggleLabel?: string;                // default 'summary'
})
```

Notes:
- `HeroComplianceBadges` is fed **counts/booleans, never pre-formatted strings**. It renders 5
  fixed chips: WorkSafe notifiable, Ngā Paerewa NZS 8134:2021, Hazardous substances (SDS),
  Fire (drills overdue/due/current — overdue critical outranks due warning), First aid cover.
  Lone Workers will likely keep this same set (it is the module-wide compliance row).
- `HeroSegmented` `variant='segmented'` renders as a **fragment** (label + bordered box as
  siblings) so it sits inline beside another control in the caller's flex row. `variant='pill'`
  is a self-contained container (used for the Period control in footers).
- `Tone` here is the SAME union as in `register-row-kit.tsx` — they compose without casts.

### Minimal usage

```tsx
<HeroShell footer={<HeroSegmented variant="pill" ariaLabel="Date range" items={RANGE_ITEMS} value={range} onChange={setRange} />}>
    <WorkflowRibbon current="report" />
    <div className="flex flex-wrap items-start justify-between gap-4">
        <div className="flex items-start gap-4">
            <HeroMedallion icon={UserCheck} />
            <div className="flex flex-col gap-1.5">
                <HeroStatusPill>Lone worker safety · live monitoring</HeroStatusPill>
                <h1 className="text-2xl font-bold tracking-tight text-primary-foreground md:text-[28px]">Lone Worker Safety</h1>
                <p className="max-w-xl text-sm text-primary-foreground/70">…subtitle…</p>
            </div>
        </div>
        {/* optional CTA popover (e.g. Board reports) on the right */}
    </div>
    <div className="grid gap-3 lg:grid-cols-2">
        <HeroCluster title="Live · on shift" icon={Activity}>
            <HeroClusterTile href="/health-safety/lone-workers?tab=active" label="Lone now" value={fmt(live.active)} caption="checked in" tone="neutral" />
            {/* …4 tiles per cluster… */}
        </HeroCluster>
        <HeroCluster title="Needs attention" icon={AlertTriangle}>{/* …4 tiles… */}</HeroCluster>
    </div>
    <HeroComplianceBadges worksafeAwaiting={c.worksafe} sdsExpiring={c.sds} drillsDue={c.drillsDue} drillsOverdue={c.drillsOverdue} />
</HeroShell>
```

---

## 2. Workflow ribbon — `resources/js/pages/health-safety/components/workflow-ribbon.tsx`

A slim "you-are-here" breadcrumb stepper that renders **inside `HeroShell`** at the top
(first child), on the primary gradient. Names the safety spine: H&S command centre →
Report & respond → Investigate → Resolve → Analyse.

### Exports
- `type WorkflowStage = 'report' | 'investigate' | 'resolve' | 'analyse'`
- `function WorkflowRibbon({ current }: { current: WorkflowStage })`

The internal `STEPS` array hard-links: report → `/incidents`, investigate →
`/health-safety/events`, resolve → `/health-safety/corrective-actions`, analyse →
`/health-safety/analytics`. The leading H&S crumb links `/health-safety`. There is **no
href/label override prop** — only `current`.

**For Lone Workers:** there is no `lone-workers` stage. Lone Worker monitoring is a
report-and-respond surface, so pass `current="report"` (matches how incidents / safeguarding /
fleet front-doors all pass `report`). The active stage just gets highlighted; the page itself
is not one of the four hard-coded hrefs, which is fine.

### Usage
```tsx
<HeroShell footer={…}>
    <WorkflowRibbon current="report" />
    {/* …rest of hero… */}
</HeroShell>
```

---

## 3. Register row kit — `resources/js/pages/health-safety/components/register-row-kit.tsx`

Import via `@/pages/health-safety/components/register-row-kit`. Neutral, presentational
ROW-level helpers shared by the H&S registers. Hero/tab/footer chrome comes from `hs-hero-kit`
+ `@/components/rostering`; this file holds ONLY row helpers.

### Exports

| Export | Signature / value |
|---|---|
| `type Tone` | `'success' \| 'warning' \| 'critical' \| 'neutral'` (same union as hs-hero-kit) |
| `TONE_BG: Record<Tone, string>` | tinted bg+fg classes (`bg-status-*-bg text-status-*`; neutral = `bg-muted text-muted-foreground`) — for severity/priority chips |
| `TONE_DOT: Record<Tone, string>` | dot bg classes (`bg-status-*`; neutral = `bg-muted-foreground`) |
| `titleCase(s: string): string` | replaces `_`/`-` with space, capitalises each word |
| `initials(label: string \| null \| undefined): string` | up to 2 chars uppercased; `'HS'` fallback |
| `entityTone(id: number): string` | deterministic avatar tone class keyed off id (4-colour cycle) |
| `FlagBadge` | compact tinted flag chip |
| `RegisterTableHeader` | card-header strip (accent-tiled title + optional hint) |

### Prop signatures
```ts
function FlagBadge({
    icon: LucideIcon;
    children: ReactNode;
    tone: 'critical' | 'warning' | 'success' | 'info' | 'neutral';   // NOTE: adds 'info' vs Tone
    title: string;                                                     // tooltip
})

function RegisterTableHeader({
    icon: LucideIcon;
    title: string;
    subtitle?: string;        // rendered as "· {subtitle}"
    hint?: string;            // right-aligned hint, e.g. "Right-click a row for governance actions"
    hintIcon?: LucideIcon;    // e.g. MousePointer2
})
```

Note `FlagBadge` tone union includes `'info'` (maps to `bg-status-info-bg text-status-info`),
which the base `Tone` does not.

### Usage
```tsx
<section className="overflow-hidden rounded-2xl border border-border bg-card shadow-sm">
    <RegisterTableHeader icon={UserCheck} title="Lone workers on shift" subtitle="live" hint="Right-click a row for actions" hintIcon={MousePointer2} />
    {/* <table> … rows render severity chips with TONE_BG, dots with TONE_DOT, avatars with initials()/entityTone(id) … */}
</section>

<FlagBadge icon={AlertTriangle} tone="critical" title="Check-in overdue">Overdue</FlagBadge>
```

---

## 4. Rostering kit — `resources/js/components/rostering/*`

Barrel: `resources/js/components/rostering/index.ts` (import everything from
`@/components/rostering`). This kit supplies the **TabStrip, EntityFilter, ShiftContextMenu**
the registers use. (Bonus: `MultiEntityFilter`, `SiteFilter`, many dialogs/panes also exported.)

### 4a. TabStrip — `tab-strip.tsx`

```ts
export type RosterTabTone = 'primary' | 'warning' | 'success' | 'info' | 'violet' | 'critical';
export type RosterTabItem = {
    id: string;
    label: string;
    icon: ComponentType<{ className?: string }>;   // Lucide icon component
    tone: RosterTabTone;
    badge?: ReactNode;                              // count chip; pass `count || undefined` to hide 0
};
export function TabStrip({
    value: string;
    onChange: (next: string) => void;
    items: RosterTabItem[];
    className?: string;
    ariaLabel?: string;                            // default 'Roster views'
})
```
Full keyboard nav (Arrow/Home/End), `role=tablist`, active underline bar. `violet` maps to the
same classes as `primary`.

```tsx
const TABS: RosterTabItem[] = [
    { id: 'all', label: 'All', icon: LayoutList, tone: 'primary', badge: counts.all || undefined },
    { id: 'overdue', label: 'Overdue check-ins', icon: AlertTriangle, tone: 'critical', badge: counts.overdue || undefined },
];
<TabStrip value={tab} items={TABS} onChange={setTab} ariaLabel="Lone worker views" />
```

### 4b. EntityFilter — `entity-filter.tsx`

```ts
export type EntityFilterOption = { id: number; name: string; description?: string | null };
export type EntityFilterProps = {
    label: string;                 // singular, e.g. "Site"
    allLabel: string;              // e.g. "All sites"
    items: EntityFilterOption[];
    value: number | null;          // null = All
    onChange: (next: number | null) => void;
    onDark?: boolean;              // default false; use true inside the hero footer (gradient)
    className?: string;
    pluralLabel?: string;          // override for uncountables ("staff")
};
export function EntityFilter(props: EntityFilterProps)
```
Command/Popover combobox pill with search + clear. **Single-select.** For multi-select use
`MultiEntityFilter` (same shape; `value: number[]`).

```tsx
<EntityFilter label="Site" allLabel="All sites" items={sites} value={filters.site_id} onChange={(id) => go({ site_id: id })} onDark />
```

### 4c. ShiftContextMenu — `shift-context-menu.tsx`

The right-click context menu used by every register row (mirrors the detail dialog's Options
bar). Portal-rendered, viewport-clamped, Esc/outside-click to close.

```ts
export type ShiftCtxItem =
    | { sep: true }
    | {
          sep?: false;
          icon: ReactNode;                       // e.g. <Shield className="h-3.5 w-3.5" />
          label: string;
          sub?: string;                          // secondary line (e.g. reference number)
          kbd?: string;                          // keyboard hint chip
          tone?: 'primary' | 'critical';
          onClick?: () => void;                  // menu auto-closes after onClick
      };
export type ShiftCtxState = {
    x: number; y: number;                        // anchor at e.clientX / e.clientY
    tag: string;                                 // small uppercase badge (e.g. severity)
    tagBg?: string; tagColor?: string;           // optional inline colours for the tag
    meta: string;                                // muted line beside the tag
    items: ShiftCtxItem[];
};
export function ShiftContextMenu({ ctx: ShiftCtxState; onClose: () => void })
```

Canonical pattern (state + handler + render):
```tsx
const [ctx, setCtx] = useState<ShiftCtxState | null>(null);

const openRowCtx = (e: ReactMouseEvent, row: Row) => {
    e.preventDefault();
    const items: ShiftCtxItem[] = [
        { icon: <Shield className="h-3.5 w-3.5" />, label: 'View', sub: row.reference, tone: 'primary', onClick: () => openRow(row.id) },
        { sep: true },
        { icon: <CheckCircle2 className="h-3.5 w-3.5" />, label: 'Acknowledge', onClick: () => openRow(row.id, { action: 'ack' }) },
    ];
    setCtx({ x: e.clientX, y: e.clientY, tag: 'HIGH', meta: row.reference, items });
};
// on each <tr>: onContextMenu={(e) => openRowCtx(e, row)}
// once near root:
{ctx ? <ShiftContextMenu ctx={ctx} onClose={() => setCtx(null)} /> : null}
```

---

## 5. Wizard shell — `resources/js/components/wizard/shell.tsx`

Import via `@/components/wizard/shell`. Multi-step wizard dialog chrome extracted from the
Add-Client modal (the reference contract for every popup workflow): 248px stepper rail
(collapses < sm), "Step x of y" header + close, 3px progress strip, scrollable body, muted
footer band, green-check success pane.

### Exports
```ts
export type WizardStep = {
    key: string;
    label: string;
    blurb: string;                              // shown in rail + header
    icon: ComponentType<{ className?: string }>;
};

export function WizardShell({
    open: boolean;
    onClose: () => void;
    title: string;                              // sr-only dialog title
    description: string;                        // sr-only dialog description
    railIcon: ComponentType<{ className?: string }>;
    railTitle: string;
    railSub: string;
    steps: readonly WizardStep[];
    stepIndex: number;
    onStepClick: (index: number) => void;       // rail step click (caller decides if allowed)
    pct?: number | null;                        // optional rail completeness %; omit/null to hide
    pctLabel?: string;                          // default 'Completeness'
    footerStart?: ReactNode;                    // left of footer (e.g. Back)
    footerEnd?: ReactNode;                      // right of footer (e.g. Cancel / Continue)
    success?: ReactNode;                        // when set, REPLACES the whole body (use WizardSuccessPane)
    children?: ReactNode;                       // the active step body
})

export function WizardStepPane({ children: ReactNode })   // 300ms fade/slide-in wrapper per step
export function WizardSuccessPane({ title: string; blurb: ReactNode; actions: ReactNode })
export function ReviewCard({ icon; title; onEdit?; span?; children })
export function ReviewRow({ label: string; value?: ReactNode })   // em-dash for empty
```

`ReviewCard` full sig:
```ts
function ReviewCard({
    icon: ComponentType<{ className?: string }>;
    title: string;
    onEdit?: () => void;       // renders an Edit link that jumps back to the owning step
    span?: boolean;            // sm:col-span-2
    children: ReactNode;
})
```

Dialog is `min(94vw, 980px)` wide, body `min(88vh, 760px)` tall. Caller owns step state,
validation, and submit; the shell is pure chrome.

### Usage
```tsx
const STEPS: WizardStep[] = [
    { key: 'who', label: 'Worker & shift', blurb: 'Who and where', icon: UserCheck },
    { key: 'plan', label: 'Safety plan', blurb: 'Check-in cadence', icon: ShieldCheck },
    { key: 'review', label: 'Review', blurb: 'Confirm & start', icon: Check },
];
<WizardShell
    open={open} onClose={close}
    title="Start lone-worker session" description="Begin monitoring a lone worker"
    railIcon={UserCheck} railTitle="Lone worker" railSub="New session"
    steps={STEPS} stepIndex={i} onStepClick={setI} pct={pct}
    footerStart={i > 0 ? <Button variant="ghost" onClick={back}>Back</Button> : null}
    footerEnd={<Button onClick={next}>{last ? 'Start' : 'Continue'}</Button>}
    success={done ? <WizardSuccessPane title="Session started" blurb="…" actions={<Button onClick={close}>Done</Button>} /> : undefined}
>
    <WizardStepPane>{/* fields for STEPS[i] */}</WizardStepPane>
</WizardShell>
```

---

## 6. Wizard primitives — `resources/js/components/wizard/primitives.tsx`

Import via `@/components/wizard/primitives`. Field/section/input building blocks (Add-Client
contract). Use these for the wizard step bodies and any focused action-modal forms.

### Exports
```ts
export type IconType = ComponentType<{ className?: string }>;

// chrome constants (class strings):
export const WIZARD_RAIL_CLASS, WIZARD_PROGRESS_TRACK_CLASS, WIZARD_PROGRESS_BAR_CLASS, WIZARD_FOOTER_CLASS;

export function FieldErr({ children?: ReactNode })   // renders nothing if empty; red + AlertTriangle
export function Field({
    label?: string;
    required?: boolean;        // appends red *
    hint?: string;             // muted inline hint after label
    error?: string;            // renders a FieldErr below
    span?: boolean;            // sm:col-span-2
    children: ReactNode;
})
export function SubHead({ icon: IconType; children: ReactNode })       // col-span-full section label
export function StepHead({ icon: IconType; title: string; blurb: string })

export function InfoCard({ icon: IconType; tone?: 'info' | 'warn' | 'crit'; children: ReactNode })

export function SelectInput({
    value: string;
    onChange: (v: string) => void;
    placeholder: string;
    options: { value: string; label: string }[];
})

export function Segmented<T extends string>({
    value: T;
    onChange: (v: T) => void;
    options: { value: T; label: string; icon?: IconType }[];
})

export function ChipMulti({
    values: string[];
    onChange: (v: string[]) => void;
    options: string[];
})

export function TilePicker({
    value: string;
    onChange: (v: string) => void;
    options: { key: string; label: string; description?: string; icon?: IconType; accent?: string; meta?: string }[];
    cols?: 2 | 3;              // default 2
})

export function Ring({ pct: number; size?: number })   // default size 56; SVG completeness ring
```

`Field` wraps a single control; grid layout is the caller's (`grid grid-cols-1 sm:grid-cols-2
gap-4` is the Add-Client convention). `TilePicker` is the "type tile picker" pattern (the
Send-Kudos / Add-Client tile selector).

### Usage
```tsx
<StepHead icon={ShieldCheck} title="Safety plan" blurb="How often must they check in?" />
<div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
    <Field label="Check-in cadence" required error={errors.cadence}>
        <SelectInput value={cadence} onChange={setCadence} placeholder="Choose…" options={CADENCE_OPTS} />
    </Field>
    <Field label="Risk level" span>
        <Segmented value={risk} onChange={setRisk} options={[{ value: 'low', label: 'Low' }, { value: 'high', label: 'High' }]} />
    </Field>
</div>
<InfoCard icon={AlertTriangle} tone="warn">Overdue check-ins escalate to the on-call lead.</InfoCard>
```

---

## 7. Laravel pagination — `resources/js/components/ui/laravel-pagination.tsx`

Import via `@/components/ui/laravel-pagination`.

```ts
interface PaginationLink { url: string | null; label: string; active: boolean }
interface LaravelPaginationProps {
    links: PaginationLink[];          // Laravel paginator .links array
    lastPage?: number;
    className?: string;
    preserveState?: boolean;          // default true
}
export function LaravelPagination(props: LaravelPaginationProps)
```
Renders nothing if `links.length <= 3` or `lastPage <= 1`. Navigates via Inertia
`router.get(url, {}, { preserveState })`. Prev/Next become icon buttons; numbers are shadcn
`Button`s (`default` for active).

```tsx
{rows.last_page > 1 ? <LaravelPagination links={rows.links} /> : null}
```

---

## 8. Premium file upload — `resources/js/components/ui/file-dropzone.tsx`

Import via `@/components/ui/file-dropzone`. The shared premium document upload (drag-drop zone +
staged-file cards). Used by Add Site, Safeguarding Evidence, Incident attachments.

### Exports
```ts
export function formatFileSize(bytes: number): string   // "12 KB" / "1.4 MB" / "" for 0

export function FileDropzone({
    onFiles: (files: File[]) => void;     // emits chosen files (drag/drop or browse); does NOT upload
    accept?: string;                      // input accept attr
    multiple?: boolean;                   // default true
    title?: string;                       // default 'Drag & drop files here'
    hint?: string;                        // default 'PDF, Word, images'
    disabled?: boolean;
})

export function StagedFileCard({
    file: File;
    onRemove: () => void;
    children?: ReactNode;                 // optional per-file metadata row (note input, sensitive toggle)
})

export function AttachmentUploader({
    endpoint: string;                     // single-file POST endpoint
    noteField?: string | null;            // default null; form field for per-file note (omit to hide input)
    sensitive?: { field: string; label: string } | null;  // optional per-file sensitive checkbox
    accept?: string;
    hint?: string;
})
```

### How callers POST staged files
`AttachmentUploader` is the turnkey "record already exists" uploader. It stages files as
`StagedFileCard`s (with optional note + sensitive flag), then on **Upload** posts them
**sequentially** to a **single-file endpoint**, each file dropping out of the staged list as it
lands (read as a progress queue). For each file it builds:
```ts
const fd = new FormData();
fd.append('file', it.file);
if (noteField) fd.append(noteField, it.note);
if (sensitive) fd.append(sensitive.field, it.sensitive ? '1' : '0');
router.post(endpoint, fd, {
    preserveScroll: true, preserveState: true,
    onSuccess: () => { remove(it.id); next(i + 1); },
    onError:   () => setError('Upload failed — check the file size and type, then try again.'),
});
```
So the backend needs a **single-file** attachment store endpoint accepting `file` (+ optional
`note` / sensitive field). For wizard "stage-then-submit-with-the-form" flows, use the lower
`FileDropzone` + `StagedFileCard` directly and append the `File[]` to the wizard's own
`FormData` submit.

### Usage
```tsx
// turnkey (record exists):
<AttachmentUploader endpoint={`/health-safety/lone-workers/${id}/attachments`} noteField="note" accept=".pdf,.jpg,.png" hint="Welfare-check evidence" />

// manual stage-into-wizard:
<FileDropzone onFiles={(f) => setStaged((p) => [...p, ...f])} accept=".pdf" />
{staged.map((f, i) => <StagedFileCard key={i} file={f} onRemove={() => removeAt(i)} />)}
```

---

## 9. Date/time — `@/lib/datetime` (`resources/js/lib/datetime.ts`)

Locale `en-NZ`, timezone `Pacific/Auckland`. All exports take `string | number | Date | null |
undefined` and return the fallback `'—'` for nullish/invalid input.

| Export | Output example |
|---|---|
| `formatDate(v, fallback?)` | `"Fri 17 Apr"` |
| `formatTime(v, fallback?)` | `"8:00 pm"` (lowercase am/pm) |
| **`formatDateTime(v, fallback?)`** | `"Fri 17 Apr, 8:00 pm"` — the default frontline timestamp |
| `formatDateLong(v, fallback?)` | `"17 April 2026"` |
| `formatDateTimeLong(v, fallback?)` | `"17 April 2026, 8:00 pm"` |
| `formatRelative(v, now?, fallback?)` | `"just now"`, `"12m ago"`, `"in 15m"`, `"2h ago"`, `"3d ago"` (→ `formatDate` past a week) |
| consts | `WORKER_LOCALE = 'en-NZ'`, `WORKER_TIMEZONE = 'Pacific/Auckland'` |

Use `formatDateTime` for register row timestamps, `formatRelative` for freshness/overdue chips
(very relevant to lone-worker check-in "Xm ago" / "due in Ym" displays).

```tsx
import { formatDateTime, formatRelative } from '@/lib/datetime';
<td>{formatDateTime(row.last_check_in_at)}</td>
<span>{formatRelative(row.next_check_in_due_at)}</span>
```

---

## 10. Canonical end-to-end composition (reference: `health-safety/events/index.tsx`)

The closest sibling register. Lone Workers should mirror this assembly exactly:

1. **Imports** — rostering barrel (`ShiftContextMenu, EntityFilter, TabStrip, type RosterTabItem,
   type ShiftCtxItem, type ShiftCtxState`), `hs-hero-kit` (`HeroShell, HeroStatusPill,
   HeroMedallion, HeroCluster, HeroClusterTile, HeroSegmented, fmt, type Tone`),
   `WorkflowRibbon`, `register-row-kit` (`FlagBadge, RegisterTableHeader, TONE_BG, TONE_DOT,
   titleCase, initials, entityTone`), `LaravelPagination`, `formatDateTime`, and the
   page-specific detail dialog.
2. **State**: `const [ctx, setCtx] = useState<ShiftCtxState | null>(null);` plus
   `pendingSection` / `pendingAction` for opening the detail modal at a specific
   pane/action.
3. **Inertia helpers**: `go(partialFilters)` (filter nav, `preserveState/Scroll, replace`),
   `setTab(id)`, `openRow(id, { section?, action? })` (fetches only `detail` via
   `only: ['detail']` and opens the modal without navigating), `closeDetail()` (drops the
   `detail` param), `clearFilters()`.
4. **`TABS: RosterTabItem[]`** with `badge: count || undefined` per tab.
5. **Hero** = `<HeroShell footer={…HeroSegmented period + EntityFilter site + native selects +
   search + Clear…}>` containing `<WorkflowRibbon current="report" />`, then the
   medallion+title+subtitle row (optional right-side CTA popover), then two `<HeroCluster>`s of
   4 `<HeroClusterTile>` each, then optionally `<HeroComplianceBadges …>`.
6. **`<TabStrip value={tab} items={TABS} onChange={setTab} ariaLabel="…">`** below the hero.
7. **Register table** in a `rounded-2xl border bg-card` `<section>` with
   `<RegisterTableHeader … hint="Right-click a row for …" hintIcon={MousePointer2} />`. Each
   `<tr>` has `onClick={() => openRow(id)}` (left-click → detail modal) and
   `onContextMenu={(e) => openRowCtx(e, row)}` (right-click → context menu). Severity/priority
   chips use `TONE_BG`/`TONE_DOT`; avatars use `initials()` + `entityTone(id)`; timestamps use
   `formatDateTime`.
8. **Footer**: `{rows.last_page > 1 ? <LaravelPagination links={rows.links} /> : null}`.
9. **Portals at root**: `{ctx ? <ShiftContextMenu ctx={ctx} onClose={() => setCtx(null)} /> :
   null}` and `{detail ? <DetailDialog detail={detail} open onClose={closeDetail}
   initialSection={pendingSection} initialAction={pendingAction} /> : null}`.

`openRowCtx` builds a `ShiftCtxItem[]` that **mirrors the detail dialog's Options bar** (gated
by a `can` object), with `{ sep: true }` separators and a final "Open full page" item, then
`setCtx({ x: e.clientX, y: e.clientY, tag, meta, items })`.

---

## Summary of import paths

```ts
import { HeroShell, HeroStatusPill, HeroMedallion, HeroCluster, HeroClusterTile,
         HeroComplianceBadges, HeroSegmented, HeroSummaryStrip, HeroSummaryMetric,
         fmt, type Tone, type HeroSegItem } from '@/pages/health-safety/components/hs-hero-kit';
import { WorkflowRibbon, type WorkflowStage } from '@/pages/health-safety/components/workflow-ribbon';
import { RegisterTableHeader, FlagBadge, TONE_BG, TONE_DOT, titleCase, initials, entityTone,
         type Tone as RowTone } from '@/pages/health-safety/components/register-row-kit';
import { TabStrip, EntityFilter, MultiEntityFilter, ShiftContextMenu,
         type RosterTabItem, type EntityFilterOption, type ShiftCtxItem, type ShiftCtxState } from '@/components/rostering';
import { WizardShell, WizardStepPane, WizardSuccessPane, ReviewCard, ReviewRow,
         type WizardStep } from '@/components/wizard/shell';
import { Field, FieldErr, SubHead, StepHead, InfoCard, SelectInput, Segmented, ChipMulti,
         TilePicker, Ring, type IconType } from '@/components/wizard/primitives';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { FileDropzone, StagedFileCard, AttachmentUploader, formatFileSize } from '@/components/ui/file-dropzone';
import { formatDate, formatTime, formatDateTime, formatRelative, formatDateLong } from '@/lib/datetime';
```
