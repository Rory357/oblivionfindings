# 04 — Reference Implementation Audit (Lone Worker Safety redesign)

Three reference implementations the Lone Workers page copies structurally:

1. **`resources/js/pages/incidents/index.tsx`** — the register page (hero + ribbon + filter bar + tabs + table + pagination + URL-driven detail/report state). Closest structural analogue.
2. **`resources/js/components/incidents/incident-detail-dialog.tsx`** — the left-click detail modal (`?param` partial reload, section rail, footer Options bar, action/edit panes).
3. **`resources/js/components/clients/add-client-dialog.tsx`** — the wizard contract (WizardShell, 248px rail, Step x/y, progress, completeness Ring, ReviewCard/Row, sticky footer + Save & add another, validateStep, success pane).

Shared chrome lives in:
- `resources/js/pages/health-safety/components/hs-hero-kit.tsx` — `HeroShell`, `HeroStatusPill`, `HeroMedallion`, `HeroCluster`, `HeroClusterTile`, `HeroSegmented`, `fmt`, type `Tone`.
- `resources/js/pages/health-safety/components/workflow-ribbon.tsx` — `WorkflowRibbon`, type `WorkflowStage`.
- `resources/js/components/rostering` (barrel) — `EntityFilter`, `ShiftContextMenu`, `TabStrip`, types `RosterTabItem`, `ShiftCtxItem`, `ShiftCtxState`, `EntityFilterOption`.
- `resources/js/components/wizard/shell.tsx` — `WizardShell`, `WizardStepPane`, `WizardSuccessPane`, `ReviewCard`, `ReviewRow`, type `WizardStep`.
- `resources/js/components/wizard/primitives.tsx` — `Field`, `FieldErr`, `SubHead`, `StepHead`, `InfoCard`, `SelectInput`, `Segmented`, `ChipMulti`, `TilePicker`, `Ring`, type `IconType`, plus chrome constants `WIZARD_RAIL_CLASS` / `WIZARD_PROGRESS_*_CLASS` / `WIZARD_FOOTER_CLASS`.

---

## Shared component prop signatures (verbatim from source)

### hs-hero-kit.tsx
```ts
type Tone = 'success' | 'warning' | 'critical' | 'neutral';        // exported
fmt(value: number | null | undefined, suffix = ''): string
HeroShell({ children, footer }: { children: ReactNode; footer?: ReactNode })
HeroStatusPill({ children }: { children: ReactNode })
HeroMedallion({ icon: Icon }: { icon: LucideIcon })
HeroCluster({ title, icon: Icon, children }: { title: string; icon: LucideIcon; children: ReactNode })
HeroClusterTile({ href?, label, value, caption, tone, delta?, deltaTone='neutral' })
  // value is a STRING (use fmt(n)); tone/deltaTone: Tone; href makes it an Inertia <Link>
HeroSegmented({ label?, items, value, onChange, ariaLabel, variant='segmented' })
  // variant: 'pill' | 'segmented'; items: HeroSegItem[] = { key; label; popover? }
```

### workflow-ribbon.tsx
```ts
type WorkflowStage = 'report' | 'investigate' | 'resolve' | 'analyse';
WorkflowRibbon({ current }: { current: WorkflowStage })
// Renders INSIDE HeroShell as the first child. Report front-doors pass current="report".
```

### rostering barrel
```ts
type RosterTabItem = { id: string; label: string; icon: ComponentType<{className?:string}>;
                       tone: RosterTabTone; badge?: ReactNode };
// RosterTabTone = 'primary'|'warning'|'success'|'info'|'violet'|'critical'
TabStrip({ value, onChange, items, className?, ariaLabel='Roster views' })

type ShiftCtxItem = { sep: true }
  | { sep?: false; icon: ReactNode; label: string; sub?: string; kbd?: string;
      tone?: 'primary'|'critical'; onClick?: () => void };
type ShiftCtxState = { x: number; y: number; tag: string; tagBg?: string; tagColor?: string;
                       meta: string; items: ShiftCtxItem[] };
ShiftContextMenu({ ctx, onClose })   // render only when ctx != null; portal-based

type EntityFilterOption = { id: number; name: string; description?: string|null };
EntityFilter({ label, allLabel, items, value: number|null, onChange:(id|null)=>void,
               onDark?, className?, pluralLabel? })
```

### wizard/shell.tsx
```ts
type WizardStep = { key: string; label: string; blurb: string; icon: ComponentType<{className?:string}> };
WizardShell({ open, onClose, title, description,          // title/description are sr-only
              railIcon, railTitle, railSub,
              steps: readonly WizardStep[], stepIndex, onStepClick:(i)=>void,
              pct?, pctLabel='Completeness',               // pct null => no meter
              footerStart?, footerEnd?, success?, children? })
  // DialogContent width min(94vw, 980px); body h-[min(88vh,760px)]; rail 248px hidden <sm.
WizardStepPane({ children })          // 300ms fade/slide-in wrapper (motion-safe)
WizardSuccessPane({ title, blurb, actions })
ReviewCard({ icon, title, onEdit?, span?, children })
ReviewRow({ label, value? })          // em-dash for empty
```

### wizard/primitives.tsx
```ts
Field({ label?, required?, hint?, error?, span?, children })
StepHead({ icon, title, blurb })       SubHead({ icon, children })
InfoCard({ icon, tone?='info'|'warn'|'crit', children })
SelectInput({ value, onChange:(v)=>void, placeholder, options:{value,label}[] })
Segmented<T extends string>({ value, onChange, options:{value,label,icon?}[] })
ChipMulti({ values:string[], onChange:(v[])=>void, options:string[] })
TilePicker({ value, onChange, options:{key,label,description?,icon?,accent?,meta?}[], cols?=2|3 })
Ring({ pct, size?=56 })   // SVG completeness ring (stroke=var(--primary))
```

---

## A. Register page — `pages/incidents/index.tsx` (closest analogue)

### Page composition order (exact, from `IncidentsIndex` return, lines 352–505)
```
AppLayout (breadcrumbs=[H&S → Incidents]) + <Head title>
  div.flex.flex-col.gap-6.p-6
    HeroShell                                   // footer = filter bar (see below)
      WorkflowRibbon current="report"          // FIRST child of HeroShell
      div  (medallion + title block + Report popover)   // justify-between
        HeroMedallion icon={AlertTriangle}
        HeroStatusPill + <h1> + <p>            // eyebrow / title / blurb
        Popover (Report launcher)  -> openReport('incident'|'near_miss')   // gated can.create
      div.grid.lg:grid-cols-2                   // KPI clusters
        HeroCluster "This period · last 30 days"  -> 4x HeroClusterTile (href=tab links)
        HeroCluster "Needs attention"             -> 4x HeroClusterTile
    TabStrip value={tab} onChange={setTab} items={TABS} ariaLabel="Incident views"
    {tab==='near_misses' && <NearMissInsights/>}            // optional per-tab strip
    Card > CardContent.p-0
      {rowsKind==='incidents' ? <IncidentTable .../> : <FollowupTable .../>}   // register table
    {rows.last_page > 1 && <LaravelPagination links={rows.links} />}
  {ctx && <ShiftContextMenu ctx onClose={()=>setCtx(null)} />}     // right-click menu
  {detail && <IncidentDetailDialog detail open onClose={closeDetail} />}   // left-click modal
  {reportMode && reportClients && <IncidentReportDialog ... />}    // wizard
```

### Footer filter bar (HeroShell `footer` prop, lines 359–406)
A single `div.flex.flex-wrap.items-center.gap-x-4.gap-y-2` containing, in order:
- `HeroSegmented label="Period" variant="pill"` with RANGE_ITEMS (This week / 30 days / Quarter / Custom-with-popover), `onChange={onRange}`.
- `EntityFilter label="Site" onDark` (conditional on `sites?.length`).
- `EntityFilter label="Client" onDark` (conditional on `clients?.length`; items mapped to `{id, name}`).
- `HeroSegmented label="Source" variant="pill"` (all/manual/control_room/sensor).
- Search input pushed right with `ml-auto` (raw `<input type="search">` on-dark, fires `go({q})` on Enter via `onKeyDown`).
- Clear button (only when `hasFilters`) → `clearFilters()`.

### URL / Inertia state drivers (lines 237–260) — THE PATTERN TO COPY
```ts
const go = (next: Partial<Filters>) =>
  router.get('/incidents', { ...filters, ...next },
             { preserveState: true, preserveScroll: true, replace: true });
const setTab = (id) => router.get('/incidents', { ...filters, tab: id }, { preserveScroll: true });

// Detail-over-list: fetch ONLY the `detail` prop; closing drops the param so detail=null.
const openDetail = (id) =>
  router.get('/incidents', { ...filters, incident: id },
             { preserveState: true, preserveScroll: true, only: ['detail'] });
const closeDetail = () =>
  router.get('/incidents', { ...filters },
             { preserveState: true, preserveScroll: true, only: ['detail'] });

const clearFilters = () =>
  router.get('/incidents', { tab }, { preserveState: true, preserveScroll: true, replace: true });
```
- Filters live entirely in the URL (server returns `filters`, `tab`, `tabCounts`, paginated `rows`, `detail`). No client filter state.
- `only: ['detail']` is the key: detail open/close is a **partial reload of just the `detail` prop** — list & hero don't re-fetch; closing simply omits `incident`.
- `report` mode is local React state seeded from a `report` prop (`useState(report ?? null)`), plus `reportPrefill`.

### `actionsFor(entity)` wiring — single builder powers right-click ONLY here
In incidents the kebab/Options bar lives in the **detail dialog**, not the table; the table only has the right-click menu. The builder is `openRowCtx(e, i: IncidentRow)` (lines 334–347):
```ts
const openRowCtx = (e, i) => {
  e.preventDefault();
  const items: ShiftCtxItem[] = [
    { icon:<Eye/>, label:'View incident', sub:..., tone:'primary', onClick:()=>openDetail(i.id) },
    ...(i.status==='draft' ? [{ icon:<FileEdit/>, label:'Continue draft', onClick:()=>openDetail(i.id) }] : []),
    { sep: true },
    ...(i.client ? [{ icon:<User/>, label:'View client', onClick:()=>router.visit(`/operations/clients/${i.client.id}/care`) }] : []),
    ...(i.control_room_alert_id ? [{ ... onClick:()=>router.visit(`/control-room/alerts/${...}`) }] : []),
    ...(i.status==='draft' ? [{sep:true}, { icon:<Send/>, label:'Submit for review', onClick:()=>router.post(`/incidents/${i.id}/submit`) }] : []),
  ];
  setCtx({ x: e.clientX, y: e.clientY, tag: sev.label.toUpperCase(), meta:`${clientName} · ${type}`, items });
};
```
Conditional spreads use `satisfies ShiftCtxItem` to keep types. **For Lone Workers, factor this into a reusable `actionsFor(row): ShiftCtxItem[]`** so the SAME array drives both the right-click `ShiftContextMenu` and any in-table kebab (the `incident-detail-dialog` Options bar is a separate hand-rolled footer — see B).

### Register table rows (lines 596–600) — left-click + right-click
```tsx
<tr key={i.id}
    onClick={() => onOpen(i.id)}                 // left-click → openDetail
    onContextMenu={(e) => onRowCtx(e, i)}        // right-click → setCtx
    className="cursor-pointer transition-colors hover:bg-muted/40">
```
- Table is plain `<table className="w-full text-sm">` inside `Card > CardContent.p-0`, wrapped in `div.overflow-x-auto`.
- `<thead>` row: `border-b text-left text-[11px] font-semibold tracking-wide text-muted-foreground uppercase`; `<th className="px-4 py-2.5">`.
- `<tbody className="divide-y">`; cells `px-4 py-3 align-top`.
- Empty state: centered block with muted icon + heading + subtext (lines 563–571).
- Severity/status/source rendered via token maps (`SEV`, `STATUS`, `SOURCE`, `TONE_DOT/BG/TEXT`) at top of file (lines 145–183) — copy this token-map approach.

### Copy-this skeleton (register page)
```tsx
export default function LoneWorkersIndex({ filters, tab, tabCounts, rows, hero, sites, clients, can, detail }: Props) {
  const [ctx, setCtx] = useState<ShiftCtxState | null>(null);
  const go = (next) => router.get('/health-safety/lone-workers', {...filters, ...next}, {preserveState:true,preserveScroll:true,replace:true});
  const setTab = (id) => router.get('/health-safety/lone-workers', {...filters, tab:id}, {preserveScroll:true});
  const openDetail = (id) => router.get('/health-safety/lone-workers', {...filters, session:id}, {preserveState:true,preserveScroll:true,only:['detail']});
  const closeDetail = () => router.get('/health-safety/lone-workers', {...filters}, {preserveState:true,preserveScroll:true,only:['detail']});
  const TABS: RosterTabItem[] = [/* {id,label,icon,tone,badge:tabCounts.x||undefined} */];
  const actionsFor = (r): ShiftCtxItem[] => [/* View / View staff / lifecycle, conditional spreads + satisfies */];
  const openRowCtx = (e, r) => { e.preventDefault(); setCtx({ x:e.clientX, y:e.clientY, tag, meta, items: actionsFor(r) }); };
  return (
    <AppLayout breadcrumbs={[{title:'Health & Safety',href:'/health-safety'},{title:'Lone workers',href:'/health-safety/lone-workers'}]}>
      <Head title="Lone Worker Safety" />
      <div className="flex flex-col gap-6 p-6">
        <HeroShell footer={<FilterBar .../>}>
          <WorkflowRibbon current="report" />
          {/* medallion + HeroStatusPill + h1 + p + (gated) primary action */}
          <div className="grid gap-3 lg:grid-cols-2">
            <HeroCluster .../> <HeroCluster .../>
          </div>
        </HeroShell>
        <TabStrip value={tab} onChange={setTab} items={TABS} ariaLabel="Lone worker views" />
        <Card><CardContent className="p-0"><Table rows={rows.data} onOpen={openDetail} onRowCtx={openRowCtx} /></CardContent></Card>
        {rows.last_page > 1 ? <LaravelPagination links={rows.links} /> : null}
      </div>
      {ctx ? <ShiftContextMenu ctx={ctx} onClose={()=>setCtx(null)} /> : null}
      {detail ? <LoneWorkerDetailDialog detail={detail} open onClose={closeDetail} /> : null}
    </AppLayout>
  );
}
```

---

## B. Detail modal — `components/incidents/incident-detail-dialog.tsx`

### Open/close = `?param` partial reload (no internal open state)
The dialog has **no Dialog open-state of its own**. The PAGE renders `{detail && <IncidentDetailDialog detail open onClose={closeDetail} />}`. `detail` is a server prop; `openDetail(id)` adds `?incident=id` with `only:['detail']`, `closeDetail()` drops the param. So mount/unmount IS the open/close.

### Component contract (line 148)
```ts
export function IncidentDetailDialog({ detail, open, onClose }:
  { detail: IncidentDetail; open: boolean; onClose: () => void })
```
`IncidentDetail` (exported type, lines 40–111) mirrors the server's `buildIncidentDetail()` — flat scalars + nested `client`, `reporter`, `attachments[]`, `followups[]`, related records, a `can: {...}` permission map, and `assignable_staff[]`.

### Internal state (lines 149–151)
```ts
const [section, setSection] = useState<SectionKey>('overview');   // which rail section
const [action, setAction]   = useState<LifecycleAction | null>(null); // review|close|reopen pane
const [editing, setEditing] = useState(false);                    // edit pane
```

### Structure: it wraps `WizardShell` (NOT a fresh dialog) — lines 218–248
```tsx
<WizardShell
  open={open} onClose={onClose}
  title={`Incident INC-${d.id}`} description={`${type} — ${clientName}`}   // sr-only
  railIcon={isNearMiss ? ShieldAlert : AlertTriangle}
  railTitle={clientName} railSub={`INC-${d.id} · ${type}`}
  steps={SECTIONS}                       // SectionKey[] reused as WizardStep[] (key/label/blurb/icon)
  stepIndex={stepIndex} onStepClick={(i)=>setSection(SECTIONS[i].key)}
  footerStart={footerStart}              // severity pill + status text
  footerEnd={footerEnd}                  // Options bar (see below) — null while action/editing
>
  {editing ? <EditPane/> : action ? <ActionPane/> : (
    <>{section==='overview' && <OverviewSection/>}{section==='timeline' && <TimelineSection/>}…</>
  )}
</WizardShell>
```
- `SECTIONS` (lines 158–165): overview / timeline / photos / followups / investigation / linked — each `{ key, label, blurb, icon }`; `blurb` shows live counts (e.g. `${openFollowups} open`). `stepIndex = SECTIONS.findIndex(s=>s.key===section)`.
- Body switches on `section`, but `editing`/`action` panes **take over the whole body**.

### Footer Options bar (`footerEnd`, lines 175–206) — the gated action bar
```tsx
const footerEnd = action || editing ? null : (
  <div className="flex flex-wrap items-center gap-2">
    <Link href={`/incidents/${d.id}`}>Open full page</Link>
    {d.can.update && d.status==='draft' && <Button variant="outline" onClick={()=>setEditing(true)}>Edit</Button>}
    {d.can.submit && d.status==='draft' && <Button onClick={submit}>Submit for review</Button>}
    {d.can.review && d.status==='submitted' && <Button onClick={()=>setAction('review')}>Review</Button>}
    {d.can.close  && d.status==='reviewed'  && <Button onClick={()=>setAction('close')}>Close</Button>}
    {d.can.reopen && d.status==='closed'    && <Button variant="outline" onClick={()=>setAction('reopen')}>Reopen</Button>}
  </div>
);
```
Each button is gated by BOTH a `can.*` flag AND the current `status`. Suppressed (null) while a pane is active so the pane owns its own buttons.

### Action / Edit panes = inline `useForm`, back() redirect refresh
`ActionPane` (lines 261–320) and `EditPane` (351–422) each build an Inertia `useForm`, render `StepHead` + `Field`s, and a Cancel / submit button pair. Submit pattern (CRITICAL — guardrail failures arrive as `flash.error` on a 302, i.e. Inertia `onSuccess`, NOT a 422):
```ts
form.post(`/incidents/${id}/${action}`, {
  preserveScroll: true,
  onSuccess: (page) => { if (!(page.props as {flash?:{error?:string}}).flash?.error) onDone(); },
});
```
Lifecycle endpoints (`/submit`, `/review`, `/close`, `/reopen`, `/followups/{id}/complete`) use `router.post(..., {}, { preserveScroll:true })` and rely on the controller's `back()` → Inertia re-follows the current URL (which still has `?incident=`) so **dialog + list refresh together**.

### Sections compose shared primitives
`OverviewSection` uses `InfoCard` (escalation/WorkSafe banners) + `ReviewCard`/`ReviewRow` grids. `TimelineSection` builds an `events[]` from timestamps and renders an `<ol>` with dot markers. `PhotosSection` uses `AttachmentUploader` (from `@/components/ui/file-dropzone`) when `status==='draft' && can.update`. Embedded mini-forms (`AddFollowupForm`, `RaiseCorrectiveActionForm`) toggle open with local `useState(false)` and their own `useForm`.

### Copy-this skeleton (detail dialog)
```tsx
export type LoneWorkerDetail = { id; status; ...; can: {...}; sessions/attachments/...; assignable_staff };
type SectionKey = 'overview' | 'timeline' | 'checkins' | 'escalations' | 'linked';
export function LoneWorkerDetailDialog({ detail: d, open, onClose }) {
  const [section, setSection] = useState<SectionKey>('overview');
  const [action, setAction]   = useState<LifecycleAction | null>(null);
  const [editing, setEditing] = useState(false);
  const SECTIONS = [{key:'overview',label:'Overview',blurb:'…',icon:FileText}, /* … */];
  const footerEnd = action || editing ? null : (
    <div className="flex flex-wrap items-center gap-2">
      <Link href={`/health-safety/lone-workers/${d.id}`}>Open full page</Link>
      {d.can.x && d.status==='…' && <Button onClick={()=>setAction('…')}>…</Button>}
    </div>
  );
  return (
    <WizardShell open={open} onClose={onClose} title=… description=… railIcon=… railTitle=… railSub=…
                 steps={SECTIONS} stepIndex={SECTIONS.findIndex(s=>s.key===section)}
                 onStepClick={(i)=>setSection(SECTIONS[i].key)} footerStart={…} footerEnd={footerEnd}>
      {editing ? <EditPane d={d} onDone={()=>setEditing(false)}/> :
       action  ? <ActionPane id={d.id} action={action} onDone={()=>setAction(null)}/> :
       <>{section==='overview' && <OverviewSection d={d}/>}{/* … */}</>}
    </WizardShell>
  );
}
// Action submit: form.post(url, { preserveScroll:true, onSuccess:(p)=>{ if(!p.props.flash?.error) onDone(); } });
```

---

## C. Wizard — `components/clients/add-client-dialog.tsx`

> NB: this file predates the extracted `wizard/shell.tsx` and rebuilds the shell **inline** (lines 936–1129) — it IS the canonical contract those shared components were extracted from. New wizards should prefer `WizardShell` + `WizardSuccessPane` + `ReviewCard`/`ReviewRow` from `wizard/shell.tsx`, but the structural recipe is identical.

### Outer dialog (lines 769–789)
```tsx
<Dialog open={isOpen} onOpenChange={(o)=>!o && onClose()}>
  <DialogContent className="overflow-hidden p-0 [&>button]:hidden"
                 style={{ maxWidth:'min(94vw,1080px)', width:'min(94vw,1080px)' }}>
    <DialogTitle className="sr-only">…</DialogTitle>
    <DialogDescription className="sr-only">…</DialogDescription>
    {isOpen ? <AddClientBody {...props}/> : null}   // body only mounted while open
  </DialogContent>
</Dialog>
```
(`WizardShell` uses `min(94vw,980px)` — slightly narrower.)

### Body layout (lines 936–1129)
```
div.flex.h-[min(92vh,860px)].min-h-0.overflow-hidden          // WizardShell uses h-[min(88vh,760px)]
  aside  w-[248px] shrink-0 ... border-r border-sidebar-border bg-sidebar p-4  (hidden sm:flex)
    header chip: rounded-lg bg-primary (UserPlus icon) + title + "New intake"
    STEPS.map → rail button:
       span (h-[26px] w-[26px] rounded-full): Check if complete, else step Icon;
         active=bg-primary text-primary-foreground / complete=bg-status-success-bg / else bg-muted
       label (active=font-bold) + truncated blurb
    mt-auto: "Profile completeness" + {pct}% + 1.5px bar (bg-primary, width pct%)
  div.flex.flex-1.flex-col
    header (border-b px-5 py-3.5): "Step {stepIndex+1} of {STEPS.length} · {cur.label}" + X close
    div.h-[3px].bg-muted > div.bg-primary width=((stepIndex+1)/len)*100%      // 3px progress strip
    div.flex-1.overflow-y-auto.px-6.py-6 : {isReview ? <ReviewStep/> : <StepBody/>}
    footer (border-t bg-muted/30 px-5 py-3.5):
       left: Back (ghost, only stepIndex>0)
       right: Cancel + (isReview ? [Save & add another (secondary, !isEditMode) + Create/Save] : Continue)
```

### State + flow (lines 807–902)
```ts
const form = useForm<ClientWizardForm>(formWithInitialValues(...));   // Inertia useForm holds ALL data
const [stepIndex, setStepIndex] = useState(0);
const [errors, setErrors] = useState<Record<string,string>>({});     // client-side step errors
const [done, setDone] = useState(false);                             // success pane toggle
const pct = useMemo(() => completionPct(data), [data]);
const next = () => { const e = validateStep(cur.key, data); setErrors(e); if (Object.keys(e).length) return; setStepIndex(i=>Math.min(i+1,len-1)); };
```
- **`validateStep(key, data)`** (lines 710–749) returns `Record<field,msg>` for the CURRENT step only; `next()` blocks advance + shows errors.
- **Submit re-validates EVERY step and jumps to the first failure** (lines 861–869):
```ts
const submit = (addAnother) => {
  const all = {}; for (const s of STEPS) Object.assign(all, validateStep(s.key, data));
  if (Object.keys(all).length) { setErrors(all); goToStep(stepForError(Object.keys(all)[0])); return; }
  form.post('/operations/clients', {
    forceFormData:true, preserveScroll:true, preserveState:true,
    onSuccess: () => addAnother ? resetAll() : setDone(true),
    onError: (errs) => { const first=Object.keys(errs)[0]; if(first) goToStep(stepForError(first)); },  // server errors → jump too
  });
};
```
- **`stepForError(field)`** (lines 595–600) maps a field name to its owning `StepKey` via `STEP_FOR_PREFIX` (lines 567–593) — both client and server errors route to the right step.
- **`fieldError(name)`** merges client `errors` with `form.errors` (server): `errors[name] ?? form.errors[name]`.
- Edit mode (`clientId != null`) transforms payload with `_method:'put'`, posts to `/operations/clients/{id}`, title becomes "Complete profile".

### Completeness meter (lines 372–431)
`COMPLETION_FIELDS` + `COMPLETION_MEDICAL` arrays + `isFilled()` → `completionPct()` returns 0–100. Drives BOTH the rail bar and the review-step `Ring`.

### Review step (lines 2464–2706)
- Top banner: `Ring pct` + "Profile {pct}% complete" + tiered message (≥80 / ≥50 / else).
- `div.grid.gap-3.5.sm:grid-cols-2` of `ReviewCard`s, each `onEdit={()=>goToStep('basics')}` etc., containing `ReviewRow label value` lines. Values can be JSX (`Badge`, `StatusBadge`).

### Success pane (`SuccessPane`, lines 2708–2765 / shared `WizardSuccessPane`)
Centered: green-check circle (`bg-status-success-bg`, `Check h-10`) + decorative `PartyPopper`/`Sparkles`, `<h2>` "{name} added", blurb, action buttons (Add another + Go to profile / Done). Shown when `done===true` (replaces the whole shell body). `created_client_id` read from `usePage().props.flash`.

### Copy-this skeleton (wizard — using shared WizardShell)
```tsx
const STEPS: WizardStep[] = [{key:'details',label:'Session',blurb:'…',icon:User}, /* … */, {key:'review',label:'Review & start',blurb:'Confirm',icon:CheckCircle2}];
function validateStep(key, d): Record<string,string> { /* per-step required checks */ }
const STEP_FOR_PREFIX = [{prefix:'…', step:'…'}]; const stepForError = (f) => …;

export function LoneWorkerWizard({ open, onClose, staff }: Props) {
  const form = useForm<Shape>({ /* all fields */ });
  const [stepIndex, setStepIndex] = useState(0);
  const [errors, setErrors] = useState<Record<string,string>>({});
  const [done, setDone] = useState(false);
  const cur = STEPS[stepIndex]; const isReview = cur.key === 'review';
  const pct = useMemo(()=>completionPct(form.data), [form.data]);
  const fieldError = (n) => errors[n] ?? (form.errors as any)[n];
  const next = () => { const e=validateStep(cur.key,form.data); setErrors(e); if(!Object.keys(e).length) setStepIndex(i=>i+1); };
  const submit = (addAnother) => { const all={}; STEPS.forEach(s=>Object.assign(all,validateStep(s.key,form.data)));
    if(Object.keys(all).length){ setErrors(all); goToStep(stepForError(Object.keys(all)[0])); return; }
    form.post('/health-safety/lone-workers', { preserveScroll:true,
      onSuccess:()=> addAnother ? resetAll() : setDone(true),
      onError:(errs)=>{ const f=Object.keys(errs)[0]; if(f) goToStep(stepForError(f)); } }); };

  return (
    <WizardShell open={open} onClose={onClose} title="Start lone-worker session" description="…"
      railIcon={ShieldCheck} railTitle="New session" railSub="Lone worker"
      steps={STEPS} stepIndex={stepIndex} onStepClick={setStepIndex}
      pct={pct} pctLabel="Completeness"
      footerStart={stepIndex>0 ? <Button variant="ghost" onClick={back}><ChevronLeft/>Back</Button> : null}
      footerEnd={isReview
        ? <><Button variant="outline" onClick={onClose}>Cancel</Button>
            <Button variant="secondary" onClick={()=>submit(true)}>Save & add another</Button>
            <Button onClick={()=>submit(false)} disabled={form.processing}>Start session</Button></>
        : <><Button variant="outline" onClick={onClose}>Cancel</Button>
            <Button onClick={next}>Continue <ChevronRight/></Button></>}
      success={done ? <WizardSuccessPane title="Session started" blurb="…" actions={<Button onClick={onClose}>Done</Button>}/> : undefined}
    >
      {isReview
        ? <ReviewStep .../* Ring + ReviewCard/ReviewRow grid */ />
        : <WizardStepPane>{/* StepHead + Field/SelectInput/Segmented/ChipMulti per cur.key */}</WizardStepPane>}
    </WizardShell>
  );
}
```

---

## Cross-cutting conventions to carry into Lone Workers
- **Tokens only** (never hex): severity/status maps → `text-status-*`, `bg-status-*-bg`, `bg-primary`, `text-muted-foreground`, `border-border`. Files carry `eslint-disable no-restricted-syntax` only for the bespoke on-dark/native controls.
- **On-dark hero controls** use `primary-foreground/NN` translucency (search input, EntityFilter `onDark`, segmented pills).
- **Inertia 302 + flash.error** is the success-with-guardrail-failure path → gate `onDone()`/success UI on `!page.props.flash?.error` (NOT on a 422). See `reference_inertia_flash_error.md`.
- **Partial reload** `only:['detail']` keeps detail-over-list cheap; lifecycle mutations `back()` to the same `?param` URL so list + modal refresh together.
- **One `actionsFor(row) => ShiftCtxItem[]`** should feed both the right-click `ShiftContextMenu` (and any kebab) so menus never drift.
- **Conditional `ShiftCtxItem` spreads** must use `satisfies ShiftCtxItem` to stay typed.
- Register table: `Card > CardContent.p-0` → `div.overflow-x-auto` → `<table className="w-full text-sm">`; rows `onClick`=open + `onContextMenu`=ctx, `cursor-pointer hover:bg-muted/40`; explicit empty-state block; `LaravelPagination` only when `rows.last_page > 1`.
- Wizard rail width **248px**, hidden `<sm`; **3px** top progress strip; footer band `bg-muted/30`; "Save & add another" only on the review step and only when NOT edit mode.
