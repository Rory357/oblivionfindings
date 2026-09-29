/* eslint-disable no-restricted-syntax -- the chart's dose cells are custom-styled <button> tap targets inside a time grid, exactly as today's components/emar/mar/mar-grid.tsx does (its own disable comment); everything else is a real primitive. */
/* Chart — the person's MAR. Replaces today's MarGrid + PageHero page with the
 * P00 dose vocabulary. Recording never happens here: a due cell opens P01's
 * approved recording dialog (the same one Meds today uses), and the one-click
 * "Mark given" is offered only for P01's simple doses. */
import { EntityContextMenu, EntityKebab, compactMenu, type MenuItem } from '@/components/lists/entity-menu';
import { EntityTable } from '@/components/lists/entity-table';
import { ListCaption } from '@/components/lists/list-caption';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { SkeletonTable } from '@/components/ui/skeleton-table';
import { StatusBadge } from '@/components/ui/status-badge';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { cn } from '@/lib/utils';
import { BellRing, ClipboardList, FileText, Flag, History, Image, Info, LockKeyhole, Pill, RefreshCw, ScrollText, ShieldAlert, Zap, ArrowUpRight } from 'lucide-react';
import { useState, type MouseEvent, type ReactNode } from 'react';
import { DAYS, FACTS, MEDICINES, P02_PERSONAS, medByKey, prnOf, recorderIn01, type Admin, type Medicine, type PersonId } from '../data';
import { OUTCOME_LABEL, short, slotsFor, useAdmins, useToday, type TodayCell } from '../model';
import { BLOCKS, fill, requirementsFor, type DoseState } from '../p01/contract';
import { PEOPLE } from '../p01/data';
import { useDoseMenu, useOpen, useRowOpen } from '../p01/doses';
import { useQuickGive } from '../p01/record-dialog';
import { useStore as useStore01 } from '../p01/store';
import { DesignNote, DoseBadge, Notice, SupportChip } from '../p01/ui';
import { useDlg, useP02 } from '../store';
import { AllergySummary, ConcealedCaption, ConcealedIdentity, CONCEALED, Wrap } from '../ui';

/* ───────────── medicine identity (row header) ───────────── */
export function MedIdentity({ m, sub = true }: { m: Medicine; sub?: boolean }) {
    return (
        <span className="flex min-w-0 flex-col gap-1">
            <span className="text-[13px] font-semibold">
                {m.name} <span className="font-normal text-muted-foreground">{m.strength}</span>
            </span>
            {sub ? (
                <span className="flex flex-wrap items-center gap-1.5">
                    <SupportChip support={m.support} />
                    {m.cd ? <StatusBadge variant="neutral" size="sm" className="rounded-[8px]">Controlled</StatusBadge> : null}
                    {m.covert ? <StatusBadge variant="neutral" size="sm" className="rounded-[8px]">Covert plan</StatusBadge> : null}
                    {m.rules ? <StatusBadge variant="neutral" size="sm" className="rounded-[8px]">Rules apply</StatusBadge> : null}
                    {m.inr ? <StatusBadge variant="info" size="sm" className="rounded-[8px]">Dose by INR</StatusBadge> : null}
                    {m.status === 'awaiting' ? <StatusBadge variant="warning" size="sm" className="rounded-[8px]">Waiting to be checked</StatusBadge> : null}
                    <span className="text-[12px] text-muted-foreground">
                        {m.amount} · {m.instructions}
                    </span>
                </span>
            ) : null}
        </span>
    );
}

export function useMedMenu() {
    const s = useP02();
    const dlg = useDlg();
    return (m: Medicine): MenuItem[] =>
        compactMenu([
            { label: 'View medicine details', icon: Info, onClick: () => dlg.open(`med:${m.key}`) },
            m.photos.length > 0 && { label: 'Photos of the pack', icon: Image, onClick: () => s.set({ tab: 'medicines', view: 'photos', med: m.key, dlg: undefined }) },
            { label: 'This medicine’s history', icon: History, onClick: () => s.set({ tab: 'history', view: undefined, med: m.key, dlg: undefined }) },
            { separator: true },
            s.can('orders.manage') && { label: 'Change or stop in Orders & reviews', icon: ScrollText, onClick: () => s.go('/emar/prescriptions', { client_id: String(FACTS[m.pid].clientId) }) },
        ]);
}

/* ───────────── a dose cell ───────────── */
function Cell({ state, line, extra, onOpen, onMenu, label, dataCell, compact = false }: { state: DoseState; line: string; extra?: ReactNode; onOpen: () => void; onMenu: (e: MouseEvent<HTMLButtonElement>) => void; label: string; dataCell: string; compact?: boolean }) {
    const open = state === 'due' || state === 'late';
    return (
        <button
            type="button"
            data-cell={dataCell}
            aria-label={label}
            onClick={onOpen}
            onContextMenu={onMenu}
            className={cn(
                'frontline-tap flex w-full flex-col items-start gap-1 rounded-lg border px-2 py-1.5 text-left outline-none transition-colors focus-visible:ring-2 focus-visible:ring-ring',
                compact ? 'min-w-[104px]' : 'min-w-[150px]',
                open ? 'border-dashed border-primary/50 bg-card hover:bg-primary/5' : 'border-border bg-muted/30 hover:bg-muted/60',
            )}
        >
            <DoseBadge state={state} size="sm" />
            <span className="text-[11.5px] leading-snug text-muted-foreground">{line}</span>
            {extra}
        </button>
    );
}
function CorrectionMark({ a }: { a?: Admin }) {
    if (!a?.correction) return null;
    const c = a.correction;
    return c.status === 'pending' ? (
        <StatusBadge variant="warning" size="sm" className="rounded-[8px]">
            <RefreshCw className="size-3" aria-hidden="true" /> Correction waiting
        </StatusBadge>
    ) : c.status === 'approved' ? (
        <StatusBadge variant="info" size="sm" className="rounded-[8px]">
            <RefreshCw className="size-3" aria-hidden="true" /> Corrected
        </StatusBadge>
    ) : (
        <StatusBadge variant="neutral" size="sm" className="rounded-[8px]">
            Correction declined
        </StatusBadge>
    );
}

/* Blocked reason and allergy match on an open dose — P01's row lines. A real
 * allergy match is always the critical surface, even in Warn mode (P01 Q11). */
function CellFlags({ c }: { c: TodayCell }) {
    const s01 = useStore01();
    if (!c.dose || (c.state !== 'due' && c.state !== 'late')) return null;
    const req = requirementsFor(c.dose, s01.ctx);
    const block = req.blockAll ?? req.blockGiven;
    return (
        <>
            {block ? (
                <span className="flex items-start gap-1 text-[11.5px] font-semibold text-status-warning">
                    <LockKeyhole className="mt-0.5 size-3 shrink-0" aria-hidden="true" /> {fill(BLOCKS[block].title, PEOPLE[c.dose.pid].pref, c.dose.med)}
                </span>
            ) : null}
            {req.allergy.match ? (
                <span className="flex items-start gap-1 text-[11.5px] font-semibold text-status-critical">
                    <ShieldAlert className="mt-0.5 size-3 shrink-0" aria-hidden="true" /> Possible allergy match — check before giving
                </span>
            ) : null}
            {c.dose.photo.state === 'changed' ? <span className="text-[11.5px] font-semibold text-status-warning">Pack or brand changed — check the label</span> : null}
        </>
    );
}

/* ───────────── menus for a cell ───────────── */
function useCellMenus(pid: PersonId) {
    const s = useP02();
    const s01 = useStore01();
    const dlg = useDlg();
    const doseMenu = useDoseMenu();
    const quick = useQuickGive();
    const recorder = !!recorderIn01(s.route.persona);
    const extras = (a: Admin | null, m: Medicine): (MenuItem | false | null | undefined)[] => [
        a && { label: 'View dose details', icon: Info, onClick: () => dlg.open(`dose:${a.id}`) },
        a && s.can('correct') && a.correction?.status !== 'pending' && !m.cd && { label: 'Request a correction', icon: RefreshCw, onClick: () => dlg.open(`correct:${a.id}`) },
        a && s.can('correct') && m.cd && { label: 'Why can’t I correct this here?', icon: Info, onClick: () => s.toast('info', 'Controlled-medicine records can’t be corrected here — a correction can’t change the controlled register. Raise a discrepancy in the controlled register (Stock & controlled drugs).') },
        { separator: true },
        { label: 'View medicine details', icon: Pill, onClick: () => dlg.open(`med:${m.key}`) },
        a && s.can('audit.view') && { label: 'Open in All changes', icon: FileText, onClick: () => s.set({ tab: 'history', view: 'changes', dlg: undefined }) },
        recorder && { label: 'Report a medication error', icon: Flag, onClick: () => s.toast('info', 'Opens the existing Report a medication error dialog (redesigned in P08b) — outside this preview.') },
    ];
    return {
        today: (c: TodayCell, a: Admin | null): MenuItem[] => {
            const open = c.state === 'due' || c.state === 'late';
            if (c.dose && recorder && open) {
                const req = requirementsFor(c.dose, s01.ctx);
                const simple = c.state === 'due' && req.notSimple.length === 0;
                const base = doseMenu(c.dose, 'mar').filter((i) => !i.label?.includes('medication record'));
                return compactMenu([
                    simple && { label: 'Mark given (one click, as ordered)', icon: Zap, onClick: () => quick(c.dose!) },
                    !simple && { label: 'Why no one-click “Mark given”?', icon: FileText, onClick: () => s.toast('info', `“Mark given” isn’t offered for this dose: ${req.notSimple.join('; ')}. Use “Record dose” — the same safety checks as Meds today.`) },
                    ...base,
                    { separator: true },
                    { label: 'View medicine details', icon: Pill, onClick: () => dlg.open(`med:${c.med.key}`) },
                ]);
            }
            return compactMenu(extras(a, c.med));
        },
        past: (a: Admin): MenuItem[] => compactMenu(extras(a, medByKey(a.med))),
    };
}

/* ───────────── the Chart section ───────────── */
export function ChartTab({ pid, view }: { pid: PersonId; view: string }) {
    const s = useP02();
    const dlg = useDlg();
    const state = s.route.state;
    const alertsToRead = s.alerts.filter((a) => a.pid === pid && a.onOpen && !a.resolved && (s.cdView || !a.cd));
    const read = s.alertsRead[`${s.route.persona}:${pid}`];
    if (state === 'loading')
        return (
            <Card className="p-4">
                <SkeletonTable rows={6} columns={5} />
            </Card>
        );
    if (state === 'empty')
        return (
            <Card className="p-2">
                <EmptyState
                    icon={ClipboardList}
                    title={`No medicines on ${PEOPLE[pid].pref}’s chart`}
                    description="Nothing is scheduled or available as needed. New orders are added and checked in Orders & reviews."
                    action={
                        s.can('orders.manage') ? (
                            <Button variant="outline" onClick={() => s.go('/emar/prescriptions', { client_id: String(FACTS[pid].clientId) })}>
                                Add a medicine in Orders & reviews <ArrowUpRight className="size-4" aria-hidden="true" />
                            </Button>
                        ) : undefined
                    }
                />
            </Card>
        );
    return (
        <>
            {alertsToRead.length && !read ? (
                <Notice
                    tone="warning"
                    icon={BellRing}
                    title={`${alertsToRead.length} chart ${alertsToRead.length === 1 ? 'alert' : 'alerts'} to read${recorderIn01(s.route.persona) ? ' before you record' : ''}: ${alertsToRead.map((a) => a.title).join(' · ')}`}
                    actions={
                        <Button size="sm" variant="outline" onClick={() => dlg.open('warnings')}>
                            Read {alertsToRead.length === 1 ? 'the alert' : 'the alerts'}
                        </Button>
                    }
                >
                    Shown when the chart opens, until you’ve read {alertsToRead.length === 1 ? 'it' : 'them'} today. Reading is recorded against your name.
                </Notice>
            ) : null}
            <AllergySummary pid={pid} compact />
            {view === 'asneeded' ? <AsNeeded pid={pid} /> : s.route.q.get('mode') === 'week' ? <WeekGrid pid={pid} /> : <DayGrid pid={pid} />}
        </>
    );
}

function DayGrid({ pid }: { pid: PersonId }) {
    const s = useP02();
    const dlg = useDlg();
    const rowOpen = useRowOpen();
    const cellsToday = useToday(pid);
    const admins = useAdmins(pid);
    const menus = useCellMenus(pid);
    const medMenu = useMedMenu();
    const [menu, setMenu] = useState<{ x: number; y: number; title: string; items: MenuItem[] } | null>(null);
    const day = s.route.q.get('day') ?? '2026-09-28';
    const isToday = day === '2026-09-28';
    const recorder = !!recorderIn01(s.route.persona);
    const meds = [...new Map(cellsToday.map((c) => [c.med.key, c.med])).values()];
    const slots = slotsFor(cellsToday);
    const hidden = s.cdView ? 0 : meds.filter((m) => m.cd).length;
    const dayMeta = DAYS.find((d) => d.iso === day)!;
    const openMenu = (e: MouseEvent<HTMLElement>, title: string, items: MenuItem[]) => {
        e.preventDefault();
        const b = e.currentTarget.getBoundingClientRect();
        const kb = e.clientX === 0 && e.clientY === 0;
        setMenu({ x: kb ? b.left + 12 : e.clientX, y: kb ? b.top + 12 : e.clientY, title, items });
    };
    const adminFor = (m: Medicine, slot: string) => admins.find((a) => a.day === day && a.med === m.key && a.slot === slot) ?? null;
    return (
        <section aria-label="Scheduled doses" className="flex flex-col gap-2.5">
            <ListCaption
                title={`Scheduled doses · ${dayMeta.long}`}
                caption={
                    <>
                        {meds.length} of {meds.length} medicines shown · times in NZDT{hidden ? ' · ' : ''}
                        <ConcealedCaption n={hidden} />
                    </>
                }
                right={
                    isToday && recorder ? (
                        <span className="text-caption">Click a due dose to record it · right-click or ⋯ for more</span>
                    ) : (
                        <span className="text-caption">{isToday ? 'Read only — click a dose for its details' : 'An earlier day — click a dose for its record'}</span>
                    )
                }
            />
            <Card className="gap-0 overflow-hidden p-0">
                <div className="overflow-x-auto">
                    <Table className="min-w-[900px]">
                        <TableHeader>
                            <TableRow className="bg-muted/40">
                                <TableHead className="h-8 w-[34%] text-[10px] font-semibold tracking-wide uppercase">Medicine</TableHead>
                                {slots.map((t) => (
                                    <TableHead key={t} className="h-8 text-[10px] font-semibold tracking-wide uppercase">
                                        {t}
                                    </TableHead>
                                ))}
                                <TableHead className="h-8 w-10">
                                    <span className="sr-only">Actions</span>
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {meds.map((m) => {
                                if (m.cd && !s.cdView)
                                    return (
                                        <TableRow key={m.key} data-row={m.key}>
                                            <TableCell className="align-top whitespace-normal">
                                                <ConcealedIdentity compact />
                                            </TableCell>
                                            <TableCell colSpan={slots.length} className="align-middle whitespace-normal">
                                                <span className="inline-flex items-center gap-1.5 text-[12.5px] text-muted-foreground">
                                                    <LockKeyhole className="size-3.5" aria-hidden="true" /> Doses hidden. {CONCEALED.ask}
                                                </span>
                                            </TableCell>
                                            <TableCell />
                                        </TableRow>
                                    );
                                return (
                                    <TableRow key={m.key} data-row={m.key} onContextMenu={(e) => openMenu(e, `${m.name} ${m.strength}`, medMenu(m))}>
                                        <TableCell className="align-top whitespace-normal">
                                            <MedIdentity m={m} />
                                        </TableCell>
                                        {slots.map((t) => {
                                            const c = cellsToday.find((x) => x.med.key === m.key && x.slot === t);
                                            if (!c)
                                                return (
                                                    <TableCell key={t} className="align-top">
                                                        <span className="text-muted-foreground" aria-hidden="true">·</span>
                                                        <span className="sr-only">No dose at {t}</span>
                                                    </TableCell>
                                                );
                                            if (!isToday) {
                                                const a = adminFor(m, t);
                                                return (
                                                    <TableCell key={t} className="align-top">
                                                        {a ? (
                                                            <Cell
                                                                dataCell={`${m.key}-${t}`}
                                                                state={a.outcome}
                                                                line={`${a.at} · ${short(a.by)}`}
                                                                extra={<CorrectionMark a={a} />}
                                                                label={`${m.name} ${t}, ${OUTCOME_LABEL[a.outcome]} ${a.at} by ${a.by}`}
                                                                onOpen={() => dlg.open(`dose:${a.id}`)}
                                                                onMenu={(e) => (e.stopPropagation(), openMenu(e, `${PEOPLE[pid].pref} · ${m.name} · ${t}`, menus.past(a)))}
                                                            />
                                                        ) : (
                                                            <span className="text-caption">Not on the chart then</span>
                                                        )}
                                                    </TableCell>
                                                );
                                            }
                                            const a = c.rec ? admins.find((x) => x.id === `today-${c.key}`) ?? null : null;
                                            return (
                                                <TableCell key={t} className="align-top">
                                                    <Cell
                                                        dataCell={`${m.key}-${t}`}
                                                        state={c.state}
                                                        line={c.line}
                                                        extra={
                                                            <>
                                                                <CorrectionMark a={a ?? undefined} />
                                                                <CellFlags c={c} />
                                                            </>
                                                        }
                                                        label={`${m.name} ${t}, ${c.state}`}
                                                        onOpen={() => {
                                                            const open = c.state === 'due' || c.state === 'late';
                                                            if (c.dose && recorder && (open || c.state === 'rejected')) return rowOpen(c.dose, 'mar');
                                                            if (a) return dlg.open(`dose:${a.id}`);
                                                            dlg.open(`med:${m.key}`);
                                                        }}
                                                        onMenu={(e) => (e.stopPropagation(), openMenu(e, `${PEOPLE[pid].pref} · ${m.name} · ${t}`, menus.today(c, a)))}
                                                    />
                                                </TableCell>
                                            );
                                        })}
                                        <TableCell className="w-10 align-top">
                                            <EntityKebab actions={medMenu(m)} label={`Actions for ${m.name}`} />
                                        </TableCell>
                                    </TableRow>
                                );
                            })}
                        </TableBody>
                    </Table>
                </div>
            </Card>
            {menu ? <EntityContextMenu x={menu.x} y={menu.y} icon={Pill} title={menu.title} items={menu.items} onClose={() => setMenu(null)} /> : null}
            <p className="text-caption">
                {recorder
                    ? `“Mark given” is offered only for a simple dose (due now, not controlled, no witness, no rule readings, support “Administer”, a fixed amount, not covert, allergies known with no match, the pack unchanged). Everything else opens the same recording steps as Meds today. Signed in as ${P02_PERSONAS[s.route.persona].name}.`
                    : `You can read this chart. Recording is for staff on shift with medication recording access.`}
            </p>
            <DesignNote title="Design note — the chart">
                <p>Replaces today’s MAR grid labels (Overdue, Missed) with the P00 dose words, so a dose past its window reads “Late” and a dose with no record stays “not yet recorded” rather than “missed”. The Day list goes back 7 days here; older days are in History.</p>
            </DesignNote>
        </section>
    );
}

function WeekGrid({ pid }: { pid: PersonId }) {
    const s = useP02();
    const dlg = useDlg();
    const rowOpen = useRowOpen();
    const cellsToday = useToday(pid);
    const admins = useAdmins(pid);
    const menus = useCellMenus(pid);
    const medMenu = useMedMenu();
    const recorder = !!recorderIn01(s.route.persona);
    const [menu, setMenu] = useState<{ x: number; y: number; title: string; items: MenuItem[] } | null>(null);
    const rows = cellsToday.map((c) => ({ m: c.med, slot: c.slot, c }));
    const hidden = s.cdView ? 0 : [...new Set(rows.filter((r) => r.m.cd).map((r) => r.m.key))].length;
    const openMenu = (e: MouseEvent<HTMLElement>, title: string, items: MenuItem[]) => {
        e.preventDefault();
        e.stopPropagation();
        const b = e.currentTarget.getBoundingClientRect();
        const kb = e.clientX === 0 && e.clientY === 0;
        setMenu({ x: kb ? b.left + 12 : e.clientX, y: kb ? b.top + 12 : e.clientY, title, items });
    };
    return (
        <section aria-label="Week chart" className="flex flex-col gap-2.5">
            <ListCaption
                title="Week chart · Tue 22 – Mon 28 September 2026"
                caption={
                    <>
                        {rows.length} dose times shown · times in NZDT{hidden ? ' · ' : ''}
                        <ConcealedCaption n={hidden} />
                    </>
                }
                right={<span className="text-caption">Each cell: outcome · time · who</span>}
            />
            <Card className="gap-0 overflow-hidden p-0">
                <div className="overflow-x-auto">
                    <Table className="min-w-[980px]">
                        <TableHeader>
                            <TableRow className="bg-muted/40">
                                <TableHead className="h-8 w-[170px] text-[10px] font-semibold tracking-wide uppercase">Medicine · time</TableHead>
                                {DAYS.map((d) => (
                                    <TableHead key={d.iso} className={cn('h-8 text-[10px] font-semibold tracking-wide uppercase', d.iso === '2026-09-28' && 'text-primary')}>
                                        {d.short}
                                        {d.iso === '2026-09-28' ? ' · today' : ''}
                                    </TableHead>
                                ))}
                                <TableHead className="h-8 w-10">
                                    <span className="sr-only">Actions</span>
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {rows.map(({ m, slot, c }) => {
                                if (m.cd && !s.cdView)
                                    return (
                                        <TableRow key={`${m.key}-${slot}`}>
                                            <TableCell className="whitespace-normal">
                                                <ConcealedIdentity compact />
                                            </TableCell>
                                            <TableCell colSpan={DAYS.length} className="text-[12.5px] whitespace-normal text-muted-foreground">
                                                Doses hidden. {CONCEALED.ask}
                                            </TableCell>
                                            <TableCell />
                                        </TableRow>
                                    );
                                return (
                                    <TableRow key={`${m.key}-${slot}`}>
                                        <TableCell className="align-top whitespace-normal">
                                            <span className="block text-[13px] font-semibold">{m.name}</span>
                                            <span className="block text-[11.5px] text-muted-foreground">
                                                {slot} · {m.amount.length > 24 ? 'dose by INR' : m.amount}
                                            </span>
                                        </TableCell>
                                        {DAYS.map((d) => {
                                            if (d.iso === '2026-09-28') {
                                                const a = c.rec ? admins.find((x) => x.id === `today-${c.key}`) ?? null : null;
                                                return (
                                                    <TableCell key={d.iso} className="align-top">
                                                        <Cell
                                                            dataCell={`w-${m.key}-${slot}-${d.iso}`}
                                                            compact
                                                            state={c.state}
                                                            line={c.rec ? `${c.rec.at} · ${short(c.rec.by)}` : c.state === 'notdue' ? `From ${c.line.replace('Window opens ', '')}` : c.state === 'due' ? 'Due now' : c.line}
                                                            label={`${m.name} ${slot} today, ${c.state}`}
                                                            onOpen={() => {
                                                                if (c.dose && recorder && (c.state === 'due' || c.state === 'late')) return rowOpen(c.dose, 'mar');
                                                                if (a) return dlg.open(`dose:${a.id}`);
                                                                dlg.open(`med:${m.key}`);
                                                            }}
                                                            onMenu={(e) => openMenu(e, `${PEOPLE[pid].pref} · ${m.name} · ${slot} today`, menus.today(c, a))}
                                                        />
                                                    </TableCell>
                                                );
                                            }
                                            const a = admins.find((x) => x.day === d.iso && x.med === m.key && x.slot === slot);
                                            if (!a)
                                                return (
                                                    <TableCell key={d.iso} className="align-top">
                                                        <span className="text-caption">{m.key === 'vitd' ? 'Not due' : '—'}</span>
                                                    </TableCell>
                                                );
                                            return (
                                                <TableCell key={d.iso} className="align-top">
                                                    <Cell
                                                        dataCell={`w-${m.key}-${slot}-${d.iso}`}
                                                        compact
                                                        state={a.outcome}
                                                        line={`${a.at} · ${short(a.by)}`}
                                                        extra={<CorrectionMark a={a} />}
                                                        label={`${m.name} ${slot} ${d.short}, ${OUTCOME_LABEL[a.outcome]}`}
                                                        onOpen={() => dlg.open(`dose:${a.id}`)}
                                                        onMenu={(e) => openMenu(e, `${PEOPLE[pid].pref} · ${m.name} · ${slot} · ${d.short}`, menus.past(a))}
                                                    />
                                                </TableCell>
                                            );
                                        })}
                                        <TableCell className="w-10 align-top">
                                            <EntityKebab actions={medMenu(m)} label={`Actions for ${m.name} ${slot}`} />
                                        </TableCell>
                                    </TableRow>
                                );
                            })}
                        </TableBody>
                    </Table>
                </div>
            </Card>
            {menu ? <EntityContextMenu x={menu.x} y={menu.y} icon={Pill} title={menu.title} items={menu.items} onClose={() => setMenu(null)} /> : null}
            <p className="text-caption">The week reads the same records as the day view and History — nothing is re-entered here.</p>
        </section>
    );
}

/* ───────────── As needed ───────────── */
const prnMed = (id: string) => MEDICINES.find((m) => m.prn === id)?.key ?? '';
function AsNeeded({ pid }: { pid: PersonId }) {
    const s = useP02();
    const dlg = useDlg();
    const open01 = useOpen();
    const recorder = !!recorderIn01(s.route.persona);
    const onlyLimit = s.route.q.get('limit') === '1';
    const all = prnOf(pid);
    const hidden = s.cdView ? 0 : all.filter((o) => o.cd).length;
    const rows = all.filter((o) => (s.cdView || !o.cd) && (!onlyLimit || o.last24h.length >= o.maxPer24h));
    const [ctx, setCtx] = useState<{ x: number; y: number; title: string; items: MenuItem[] } | null>(null);
    const menuFor = (o: (typeof all)[number]): MenuItem[] =>
        compactMenu([
            recorder && { label: 'Record as-needed dose', icon: Pill, onClick: () => open01(`prn:${o.id}`) },
            { label: 'View medicine details', icon: Info, onClick: () => dlg.open(`med:${prnMed(o.id)}`) },
            { label: 'This medicine’s history', icon: History, onClick: () => s.set({ tab: 'history', view: undefined, med: prnMed(o.id) }) },
        ]);
    return (
        <section aria-label="As needed" className="flex flex-col gap-2.5">
            <ListCaption
                title="As-needed medicines"
                caption={
                    <>
                        {rows.length} of {all.length - hidden} shown{hidden ? ' · ' : ''}
                        <ConcealedCaption n={hidden} listed={false} />
                    </>
                }
            />
            {rows.length ? (
                <EntityTable
                    rows={rows}
                    rowKey={(o) => o.id}
                    identityLabel="Medicine"
                    identity={(o) => ({ icon: Pill, name: `${o.med} ${o.strength}`, subline: <Wrap>{o.instructions}</Wrap> })}
                    identityWidth="2.2fr"
                    rowHeight="content"
                    minWidth={960}
                    columns={[
                        { key: 'for', label: 'For', width: '1fr', cell: (o) => <span className="text-[12.5px]">{o.reasons.join(' · ')}</span> },
                        { key: 'last', label: 'Last 24 hours', width: '1.4fr', cell: (o) => <span className="text-[12.5px]">{o.last24h.length} of {o.maxPer24h}{o.last24h[0] ? ` · last ${o.last24h[0].at} by ${o.last24h[0].by}` : ' · none'}</span> },
                        { key: 'st', label: 'Status', width: '0.9fr', cell: (o) => (o.last24h.length >= o.maxPer24h ? <StatusBadge variant="critical" className="rounded-[8px]">Limit reached</StatusBadge> : <StatusBadge variant="neutral" className="rounded-[8px]">Available</StatusBadge>) },
                        { key: 'act', label: '', width: '130px', align: 'right', cell: (o) => (recorder ? <Button size="sm" className="frontline-tap" onClick={(e) => (e.stopPropagation(), open01(`prn:${o.id}`))}>Record</Button> : null) },
                    ]}
                    actionsFor={menuFor}
                    onOpen={(o) => (recorder ? open01(`prn:${o.id}`) : dlg.open(`med:${prnMed(o.id)}`))}
                    onRowContextMenu={(e, o) => {
                        e.preventDefault();
                        const b = (e.currentTarget as HTMLElement).getBoundingClientRect();
                        const kb = e.clientX === 0 && e.clientY === 0;
                        setCtx({ x: kb ? b.left + 12 : e.clientX, y: kb ? b.top + 12 : e.clientY, title: `${o.med} · ${PEOPLE[pid].pref}`, items: menuFor(o) });
                    }}
                />
            ) : (
                <Card className="p-2">
                    <EmptyState variant="compact" icon={Pill} title={onlyLimit ? 'No as-needed medicine is at its limit' : `No as-needed medicines for ${PEOPLE[pid].pref}`} description={onlyLimit ? 'Clear the filter to see them all.' : 'As-needed orders are added in Orders & reviews.'} />
                </Card>
            )}
            {ctx ? <EntityContextMenu x={ctx.x} y={ctx.y} icon={Pill} title={ctx.title} items={ctx.items} onClose={() => setCtx(null)} /> : null}
            <p className="text-caption">Recording opens the same as-needed steps as Meds today, with the limit and last doses shown.</p>
        </section>
    );
}
