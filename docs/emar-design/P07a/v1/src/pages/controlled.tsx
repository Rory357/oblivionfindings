/* Meds today › Controlled checks — the view P07a designs (P01 kept it
 * link-only). For people who can record or witness controlled medicines at
 * their houses: the shift-change count, controlled doses that need a witness,
 * witness requests, open discrepancies and the house lead's follow-up, and
 * the register. Real EntityTable + ListCaption + entity-menu (kebab AND
 * right-click from one MenuItem[]), StatusBadge, EmptyState, ErrorState,
 * SkeletonTable, LaravelPagination. */
import { EntityChip, PersonDisc } from '@/components/lists/entity-cells';
import { EntityContextMenu, compactMenu, type MenuItem } from '@/components/lists/entity-menu';
import { EntityTable } from '@/components/lists/entity-table';
import { ListCaption } from '@/components/lists/list-caption';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { SkeletonTable } from '@/components/ui/skeleton-table';
import { StatusBadge } from '@/components/ui/status-badge';
import {
    AlertOctagon,
    ArrowDownLeft,
    ArrowUpRight,
    CheckCircle2,
    ClipboardCheck,
    ClipboardList,
    Clock3,
    Flag,
    HelpCircle,
    History,
    Home,
    Inbox,
    LogIn,
    Package,
    Pill,
    RefreshCw,
    Send,
    ShieldCheck,
    UserCheck,
    Users,
    X,
} from 'lucide-react';
import { useState, type MouseEvent, type ReactNode } from 'react';
import { CD_MEDS, HOUSES, MORNING_OVERRIDE, PEOPLE, PERSONAS, PRN_CD, cdById, doseById, qty, type CdMed, type Dose, type Entry, type HouseKey } from '../data';
import { KIND_LABEL } from '../dialogs';
import { useOpen } from '../host';
import { CADENCE, CADENCE_NOT_SET, FOLLOW_UP, countBlock, countStatus, eligibleWitnesses, morningOverride, short } from '../model';
import { hrefFor, useStore, type Ask, type Discrepancy } from '../store';
import { CdMedicineCell, CountBadge, DesignNote, Notice, NotConfigured, PersonMark, StateLine } from '../ui';

/* ───────────── right-click menu hook (P01 v1 useRowContext) ───────────── */
export function useRowContext() {
    const [ctx, setCtx] = useState<{ x: number; y: number; title: string; items: MenuItem[] } | null>(null);
    const openAt = (e: MouseEvent, title: string, items: MenuItem[]) => {
        if (e.target instanceof Element && e.target.closest('a[href]')) return;
        e.preventDefault();
        const b = (e.currentTarget as HTMLElement).getBoundingClientRect();
        const kb = e.clientX === 0 && e.clientY === 0;
        setCtx({ x: kb ? b.left + 12 : e.clientX, y: kb ? b.top + 12 : e.clientY, title, items });
    };
    const node = ctx ? <EntityContextMenu x={ctx.x} y={ctx.y} icon={ShieldCheck} title={ctx.title} items={ctx.items} onClose={() => setCtx(null)} /> : null;
    return { openAt, node };
}

const stop = (fn: () => void) => (e: MouseEvent) => {
    e.stopPropagation();
    fn();
};

/** The houses the persona sees in this view (lead/manager House filter). */
export function useViewHouses(): HouseKey[] {
    const s = useStore();
    const mine = PERSONAS[s.route.persona].houses;
    const h = s.route.q.get('house') as HouseKey | null;
    return h && mine.includes(h) ? [h] : mine;
}

/** What needs this persona now in Controlled checks (the rail counter). */
export function useControlledAttention() {
    const s = useStore();
    const houses = PERSONAS[s.route.persona].houses;
    const me = PERSONAS[s.route.persona].name;
    const scn = s.route.scenario;
    if (['loading', 'unavailable', 'noCd', 'cadenceNotSet'].includes(scn)) return { count: 0, alert: false };
    const statuses = CD_MEDS.filter((m) => houses.includes(m.house)).map((m) => ({ m, st: countStatus(m, scn, s.counted) }));
    const countHouses = new Set(statuses.filter((x) => x.st.state === 'due' || x.st.state === 'overdue').map((x) => x.m.house));
    const overdue = statuses.some((x) => x.st.state === 'overdue');
    const asks = s.asks.filter((a) => a.to === me && a.status === 'sent').length;
    const follow = (s.route.persona === 'lead' || s.route.persona === 'pm') && s.followUp === 'open' ? 1 : 0;
    const ovr = s.route.persona === 'pm' && s.override === 'waiting' ? 1 : 0;
    return { count: countHouses.size + asks + follow + ovr, alert: overdue };
}

export function ControlledView({ search }: { search: string }) {
    const s = useStore();
    const open = useOpen();
    const r = s.route;
    const scn = r.scenario;
    const persona = PERSONAS[r.persona];
    const houses = useViewHouses();
    const show = r.q.get('show') ?? 'all';
    const who = r.q.get('person') ?? 'all';
    const q = search.trim().toLowerCase();
    const matchPerson = (pid: string, med: string) => (who === 'all' || who === pid) && (!q || `${PEOPLE[pid].pref} ${PEOPLE[pid].surname} ${med}`.toLowerCase().includes(q));
    const ctxCounts = useRowContext();
    const ctxDoses = useRowContext();
    const ctxAsks = useRowContext();
    const ctxOpen = useRowContext();
    const ctxReg = useRowContext();
    const block = countBlock(r.persona, scn, s.clockedIn);
    const kowhaiWitnesses = eligibleWitnesses(r.persona, 'kowhai', scn);
    const noWitness = kowhaiWitnesses.length === 0;
    const ovr = morningOverride(scn);

    if (scn === 'loading')
        return (
            <Card className="p-5" aria-busy="true" aria-label="Loading controlled checks">
                <SkeletonTable rows={6} columns={5} />
                <span className="sr-only">Loading controlled checks…</span>
            </Card>
        );
    if (scn === 'unavailable')
        return (
            <Card className="p-2">
                <ErrorState title="Couldn’t load controlled checks" message="Counts and witness requests may have changed since you last looked. Don’t count or witness from memory — try again, or ask the house lead." onRetry={() => s.toast('warning', 'Still unavailable (mockup).')} />
            </Card>
        );
    if (scn === 'noCd')
        return (
            <Card className="p-2">
                <EmptyState icon={ShieldCheck} title="No controlled medicines are kept at Kōwhai House" description="Nothing to count or witness here. If a controlled medicine arrives, it shows here once the house lead records it in the register. Updated 2:48 pm NZDT." />
            </Card>
        );

    /* ── counts ── */
    const meds = CD_MEDS.filter((m) => houses.includes(m.house) && matchPerson(m.pid, m.med));
    const statusOf = (m: CdMed) => countStatus(m, scn, s.counted);
    const countRows = meds.filter((m) => show === 'all' || ['due', 'overdue'].includes(statusOf(m).state));
    const countMenu = (m: CdMed): MenuItem[] =>
        compactMenu([
            { label: 'Count this medicine', icon: ClipboardCheck, onClick: () => open(block || noWitness || m.house !== 'kowhai' ? 'cantcount' : `count:${m.id}`) },
            m.house === 'kowhai' && !noWitness && { label: 'Ask someone to witness', icon: Send, onClick: () => open('ask:count') },
            { label: 'View counts and movements', icon: History, onClick: () => open(`hist:${m.id}`) },
            m.house === 'kowhai' && persona.perms.includes('cd.record') && { label: 'Record a movement', icon: Package, onClick: () => open(`move:${m.id}`) },
            { separator: true },
            { label: `Open ${PEOPLE[m.pid].pref}’s medication record`, icon: ClipboardList, onClick: () => s.toast('info', 'The person’s medication record is designed in P02 — outside this preview.') },
        ]);
    const countAction = (m: CdMed) => {
        const st = statusOf(m);
        if (st.state === 'counted')
            return (
                <Button data-return={`count-${m.id}`} size="sm" variant="ghost" className="frontline-tap" onClick={stop(() => open(`hist:${m.id}`))}>
                    View
                </Button>
            );
        if (block || m.house !== 'kowhai' || noWitness)
            return (
                <Button data-return={`count-${m.id}`} size="sm" variant="outline" className="frontline-tap" onClick={stop(() => open('cantcount'))}>
                    <HelpCircle className="size-4" /> Why can’t I count?
                </Button>
            );
        return (
            <Button data-return={`count-${m.id}`} size="sm" variant={st.state === 'notConfigured' ? 'outline' : 'default'} className="frontline-tap" onClick={stop(() => open(`count:${m.id}`))}>
                <ClipboardCheck className="size-4" /> Count
            </Button>
        );
    };
    const allCounted = meds.filter((m) => m.house === 'kowhai').every((m) => statusOf(m).state === 'counted');
    const kowhaiShown = houses.includes('kowhai');

    /* ── controlled doses today ── */
    type DoseRow = Dose | typeof PRN_CD;
    const isPrn = (d: DoseRow): d is typeof PRN_CD => d.id === PRN_CD.id;
    const doseRows: DoseRow[] = [
        ...DOSESOF(houses).filter((d) => matchPerson(d.pid, d.med) && (show === 'all' || d.state === 'due')),
        ...(houses.includes('kowhai') && matchPerson(PRN_CD.pid, PRN_CD.med) && show === 'all' ? [PRN_CD] : []),
    ];
    const underOverride = (d: DoseRow) => !isPrn(d) && MORNING_OVERRIDE.doses.includes(d.id);
    const doseMenu = (d: DoseRow): MenuItem[] =>
        compactMenu([
            !isPrn(d) && d.state === 'due' && (scn !== 'noWitness' || s.override === 'approved') && { label: 'Record dose', icon: CheckCircle2, onClick: () => s.toast('info', 'Opens P01’s approved recording dialog (the witness PIN is typed there) — outside this preview.') },
            isPrn(d) && { label: 'Record as-needed dose', icon: Pill, onClick: () => s.toast('info', 'Opens P01’s approved as-needed recording dialog — outside this preview.') },
            (isPrn(d) || d.state !== 'given') && !noWitness && d.pid !== 'ben' && { label: 'Ask someone to witness', icon: Send, onClick: () => open(`ask:${d.id}`) },
            !isPrn(d) && d.state === 'due' && noWitness && s.override === 'none' && { label: 'Ask a manager for a witness override', icon: Send, onClick: () => open(`ovr-request:${d.id}`) },
            underOverride(d) && { label: 'View the witness override', icon: ShieldCheck, onClick: () => open('ovr') },
            { separator: true },
            { label: `Open ${PEOPLE[d.pid].pref}’s medication record`, icon: ClipboardList, onClick: () => s.toast('info', 'The person’s medication record is designed in P02 — outside this preview.') },
            { label: 'Report a medication error', icon: Flag, onClick: () => s.toast('info', 'Opens the existing Report a medication error dialog (redesigned in P08b) — outside this preview.') },
        ]);
    const doseState = (d: DoseRow) => {
        if (isPrn(d))
            return (
                <span className="flex flex-col items-start gap-1 py-0.5">
                    <StatusBadge variant="neutral" className="rounded-[8px] font-semibold">
                        As needed
                    </StatusBadge>
                    <StateLine>0 of 2 in the last 24 hours · needs a witness when given</StateLine>
                </span>
            );
        const lines: ReactNode[] = [];
        if (d.state === 'given') {
            lines.push(<StateLine key="g">{d.line}</StateLine>);
            if (underOverride(d)) lines.push(<StateLine key="o" tone="warning" icon={ShieldCheck}>No witness — override by {MORNING_OVERRIDE.by}</StateLine>);
            else lines.push(<StateLine key="w" icon={Users}>Witnessed by {d.pid === 'ben' ? 'Losa Tuilagi' : 'Daniel Ahn'} (witness PIN)</StateLine>);
        } else if (d.state === 'due') {
            lines.push(<StateLine key="d">{d.line}</StateLine>);
            if (scn === 'noWitness') {
                if (s.override === 'waiting') lines.push(<StateLine key="ow" icon={Send}>Witness override requested 2:48 pm · waiting for a manager</StateLine>);
                else if (s.override === 'approved') lines.push(<StateLine key="oa" tone="warning" icon={ShieldCheck}>Witness override by Rangi Parata until 3:00 pm — can be recorded without a witness</StateLine>);
                else if (s.override === 'declined') lines.push(<StateLine key="od" tone="critical">Override declined by Rangi Parata: “{s.overrideDecline}” · record it as not given, or wait</StateLine>);
                else lines.push(<StateLine key="nw" tone="critical" icon={Users}>No eligible witness on shift · Jordan Tipene starts 3:00 pm (not clocked in yet)</StateLine>);
            } else {
                lines.push(<StateLine key="cw" tone="success" icon={UserCheck}>{kowhaiWitnesses.map((c) => c.staff.name).filter((n) => n !== persona.name).join(', ') || 'Nobody'} can witness now</StateLine>);
                if (ovr.active) lines.push(<StateLine key="oc" icon={ShieldCheck}>Also covered by the witness override until {ovr.until}</StateLine>);
            }
        } else {
            lines.push(<StateLine key="n">{d.line}</StateLine>);
            lines.push(<StateLine key="w" icon={Users}>Needs a witness · {d.pid === 'ben' ? 'Sione Taufa’s evening cover' : 'Jordan Tipene is on shift until 11:00 pm'}</StateLine>);
        }
        const badge = d.state === 'given' ? { v: 'success' as const, l: 'Given' } : d.state === 'due' ? { v: 'info' as const, l: 'Due' } : { v: 'neutral' as const, l: 'Not yet due' };
        return (
            <span className="flex flex-col items-start gap-1 py-0.5">
                <StatusBadge variant={badge.v} className="rounded-[8px] font-semibold">
                    {badge.l}
                </StatusBadge>
                {lines}
            </span>
        );
    };
    const doseAction = (d: DoseRow) => {
        if (isPrn(d) || d.state === 'notdue')
            return !noWitness && d.pid !== 'ben' ? (
                <Button size="sm" variant="outline" className="frontline-tap" onClick={stop(() => open(`ask:${d.id}`))}>
                    <Send className="size-4" /> Ask to witness
                </Button>
            ) : (
                <span className="text-caption">{d.pid === 'ben' ? 'Rimu House' : isPrn(d) ? 'No witness on shift now' : 'Later today'}</span>
            );
        if (d.state === 'given')
            return underOverride(d) ? (
                <Button size="sm" variant="ghost" className="frontline-tap" onClick={stop(() => open('ovr'))}>
                    View
                </Button>
            ) : (
                <span className="text-caption">Witnessed</span>
            );
        if (scn === 'noWitness' && s.override === 'none')
            return (
                <Button data-return="ovr-request" size="sm" variant="outline" className="frontline-tap" onClick={stop(() => open(`ovr-request:${d.id}`))}>
                    <Send className="size-4" /> Ask a manager
                </Button>
            );
        if (scn === 'noWitness' && s.override === 'waiting') return <span className="text-caption">Waiting for a manager</span>;
        return (
            <Button size="sm" className="frontline-tap" onClick={stop(() => s.toast('info', s.override === 'declined' ? 'Opens P01’s recording dialog at “Record not given” — outside this preview.' : 'Opens P01’s approved recording dialog (the witness PIN is typed there) — outside this preview.'))}>
                {s.override === 'declined' && scn === 'noWitness' ? 'Record not given' : 'Record'}
            </Button>
        );
    };

    /* ── requests ── */
    type ReqRow = { kind: 'ask'; a: Ask } | { kind: 'ovr' };
    const myAsks = s.asks.filter((a) => (a.to === persona.name || a.from === persona.name) && houses.includes(a.house));
    const reqRows: ReqRow[] = [
        ...(r.persona === 'pm' && ['waiting', 'approved', 'declined'].includes(s.override) ? [{ kind: 'ovr' as const }] : []),
        ...myAsks.filter((a) => show === 'all' || ['sent', 'coming'].includes(a.status)).map((a) => ({ kind: 'ask' as const, a })),
    ];
    const askBadge = (a: Ask) =>
        a.status === 'sent' ? (
            <StatusBadge variant="info">Waiting for an answer</StatusBadge>
        ) : a.status === 'coming' ? (
            <StatusBadge variant="success">On the way · {a.answeredAt}</StatusBadge>
        ) : a.status === 'cantcome' ? (
            <StatusBadge variant="warning">Can’t come now</StatusBadge>
        ) : a.status === 'done' ? (
            <StatusBadge variant="success">Counted together</StatusBadge>
        ) : (
            <StatusBadge variant="neutral">Cancelled</StatusBadge>
        );
    const reqMenu = (row: ReqRow): MenuItem[] => {
        if (row.kind === 'ovr') return compactMenu([{ label: s.override === 'waiting' ? 'Review the request' : 'View the request', icon: ShieldCheck, onClick: () => open('ovr-approve') }]);
        const a = row.a;
        const mine = a.from === persona.name;
        return compactMenu([
            !mine && a.status === 'sent' && { label: 'On my way / can’t come', icon: Send, onClick: () => open(`answer:${a.id}`) },
            mine && ['sent', 'coming'].includes(a.status) && { label: 'Cancel request', icon: X, danger: true, onClick: () => open(`cancelask:${a.id}`) },
            mine && a.status === 'cantcome' && { label: 'Ask someone else', icon: Send, onClick: () => open(`ask:${a.kind === 'count' ? 'count' : 'd8'}`) },
        ]);
    };

    /* ── discrepancies and follow-ups ── */
    type OpenRow = { kind: 'fu' } | { kind: 'disc'; d: Discrepancy };
    const fuVisible = houses.includes('kowhai') && (show === 'all' || s.followUp === 'open');
    const openRows: OpenRow[] = [
        ...(fuVisible ? [{ kind: 'fu' as const }] : []),
        ...s.discrepancies.filter((d) => houses.includes(cdById(d.cdMed).house) && matchPerson(cdById(d.cdMed).pid, cdById(d.cdMed).med)).map((d) => ({ kind: 'disc' as const, d })),
    ];
    const lead = r.persona === 'lead' || r.persona === 'pm';
    const openMenu = (row: OpenRow): MenuItem[] =>
        row.kind === 'fu'
            ? compactMenu([
                  lead && s.followUp === 'open' ? { label: 'Count and sign off', icon: ClipboardCheck, onClick: () => open('followup') } : { label: 'View the follow-up', icon: ShieldCheck, onClick: () => open('ovr') },
                  { label: 'View the witness override', icon: ShieldCheck, onClick: () => open('ovr') },
              ])
            : compactMenu([
                  { label: 'View the discrepancy', icon: AlertOctagon, onClick: () => open(`disc:${row.d.id}`) },
                  { label: 'View counts and movements', icon: History, onClick: () => open(`hist:${row.d.cdMed}`) },
                  lead && { label: 'Open in the controlled register', icon: ClipboardList, onClick: () => s.toast('info', 'Investigating and resolving a discrepancy is designed in P07b — outside this preview.') },
              ]);

    /* ── register ── */
    const range = r.q.get('range') ?? '24h';
    const page = Number(r.q.get('page') ?? '1');
    const entries = s
        .entries()
        .filter((e) => houses.includes(cdById(e.cdMed).house))
        .filter((e) => (range === 'today' ? e.day === 'Today' : true))
        .filter((e) => matchPerson(cdById(e.cdMed).pid, cdById(e.cdMed).med));
    const per = 10;
    const last = Math.max(1, Math.ceil(entries.length / per));
    const cur = Math.min(page, last);
    const shownEntries = entries.slice((cur - 1) * per, cur * per);
    const url = (p: number) => hrefFor('/meds/today', { view: 'controlled', page: String(p) }, r);
    const links = [{ url: cur > 1 ? url(cur - 1) : null, label: '&laquo; Previous', active: false }, ...Array.from({ length: last }, (_, i) => ({ url: url(i + 1), label: String(i + 1), active: i + 1 === cur })), { url: cur < last ? url(cur + 1) : null, label: 'Next &raquo;', active: false }];
    const regMenu = (e: Entry): MenuItem[] => compactMenu([{ label: 'View counts and movements', icon: History, onClick: () => open(`hist:${e.cdMed}`) }]);

    const houseLabel = houses.length > 1 ? 'your houses' : HOUSES[houses[0]];
    return (
        <>
            {/* ── notices at the top: only what changes what you do now ── */}
            {scn === 'stale' ? (
                <Notice
                    tone="warning"
                    title="Not updated since 2:20 pm NZDT (28 min ago)"
                    actions={
                        <Button size="sm" variant="outline" onClick={() => s.toast('info', 'Refreshed at 2:48 pm NZDT (mockup).')}>
                            <RefreshCw className="size-4" /> Refresh now
                        </Button>
                    }
                >
                    We couldn’t refresh. Someone may have counted or recorded a controlled dose since. Refresh before you count — saving always checks the register again.
                </Notice>
            ) : null}
            {scn === 'notClockedIn' && !s.clockedIn && r.persona === 'sw' ? (
                <Notice
                    tone="warning"
                    icon={LogIn}
                    title="You’re not clocked in"
                    actions={
                        <Button size="sm" className="frontline-tap" onClick={() => (s.clockIn(), s.toast('success', 'Clocked in at 2:48 pm · Kōwhai House. You can count now.'))}>
                            <LogIn className="size-4" /> Clock in
                        </Button>
                    }
                >
                    You can see what’s due, but you can’t count, witness or record a movement until you clock in on a shift at Kōwhai House.
                </Notice>
            ) : null}
            {r.persona === 'mere' ? (
                <Notice
                    tone="info"
                    icon={UserCheck}
                    title="You can’t witness controlled medicines yet"
                    actions={
                        <Button size="sm" variant="outline" onClick={() => open('elig')}>
                            <HelpCircle className="size-4" /> Why not?
                        </Button>
                    }
                >
                    The controlled drugs area wasn’t passed at your assessment, and you haven’t set a witness PIN. You can still count with an eligible witness.
                </Notice>
            ) : null}
            {kowhaiShown && ovr.active && r.persona !== 'pm' ? (
                <Notice
                    tone="warning"
                    icon={ShieldCheck}
                    title={`Witness override at Kōwhai House until ${ovr.until}`}
                    actions={
                        <Button size="sm" variant="outline" onClick={() => open('ovr')}>
                            View the override
                        </Button>
                    }
                >
                    {MORNING_OVERRIDE.by} allowed controlled doses here to be recorded without a witness from {MORNING_OVERRIDE.from}, because nobody else on the day shift can witness (from the roster). 2 doses were recorded under it. Jordan Tipene (house lead) follows them up by 11:00 pm.
                </Notice>
            ) : null}
            {kowhaiShown && scn === 'noWitness' ? (
                <Notice
                    tone="warning"
                    icon={Users}
                    title="Nobody on shift at Kōwhai House can witness right now"
                    actions={
                        <Button size="sm" variant="outline" onClick={() => open('cantcount')}>
                            See the roster
                        </Button>
                    }
                >
                    Controlled doses and the 3:00 pm count need a witness. Jordan Tipene starts at 3:00 pm and isn’t clocked in yet. For a dose that can’t wait, ask a manager for a witness override.
                </Notice>
            ) : null}

            {/* ── 1. counts ── */}
            <section className="flex flex-col gap-2.5" aria-label="Counts">
                <ListCaption
                    title={houses.length > 1 ? 'Shift-change counts' : `Shift-change count · ${HOUSES[houses[0]]}`}
                    caption={
                        scn === 'cadenceNotSet' ? (
                            <span>
                                {meds.length} controlled medicines · cadence <NotConfigured />
                            </span>
                        ) : (
                            `${meds.length} controlled ${meds.length === 1 ? 'medicine' : 'medicines'} · ${CADENCE.label.toLowerCase()} · overdue 1 hour after`
                        )
                    }
                    right={
                        kowhaiShown && persona.perms.includes('cd.record') && !allCounted ? (
                            <>
                                {!noWitness && !block ? (
                                    <Button size="sm" variant="outline" className="frontline-tap" onClick={() => open('ask:count')}>
                                        <Send className="size-4" /> Ask someone to witness
                                    </Button>
                                ) : null}
                                {block || noWitness ? (
                                    <Button data-return="count-all" size="sm" variant="outline" className="frontline-tap" onClick={() => open('cantcount')}>
                                        <HelpCircle className="size-4" /> Why can’t I count?
                                    </Button>
                                ) : (
                                    <Button data-return="count-all" size="sm" className="frontline-tap" onClick={() => open('count:all')}>
                                        <ClipboardCheck className="size-4" /> {scn === 'cadenceNotSet' ? 'Count all controlled medicines' : 'Start the 3:00 pm count'}
                                    </Button>
                                )}
                            </>
                        ) : null
                    }
                />
                {scn === 'cadenceNotSet' && kowhaiShown ? (
                    <Notice tone="neutral" title={<span>{CADENCE_NOT_SET.line} <NotConfigured /></span>}>
                        {CADENCE_NOT_SET.caption}
                    </Notice>
                ) : null}
                {countRows.length ? (
                    <EntityTable<CdMed>
                        rows={countRows}
                        rowKey={(m) => m.id}
                        rowHeight="content"
                        minWidth={1000}
                        identityLabel="Person"
                        identityWidth={houses.length > 1 ? '1.2fr' : '0.9fr'}
                        identity={(m) => ({ mark: <PersonMark pid={m.pid} />, name: PEOPLE[m.pid].pref, subline: PEOPLE[m.pid].surname })}
                        columns={[
                            { key: 'med', label: 'Medicine', width: '1.4fr', cell: (m) => <CdMedicineCell m={m} sub={houses.length > 1 ? `${HOUSES[m.house]} · ${m.use}` : undefined} /> },
                            { key: 'bal', label: 'Register', width: '0.7fr', cell: (m) => <span className="text-[13px] font-semibold tabular-nums">{qty(s.balanceOf(m), m)}</span> },
                            { key: 'last', label: 'Last count', width: '1.3fr', cell: (m) => { const st = statusOf(m); return <span className="flex flex-col gap-0.5 text-[12.5px]"><span>{st.lastCount}</span><span className={st.lastResult === 'matched' ? 'text-muted-foreground' : 'font-semibold text-status-critical'}>{st.lastResult === 'matched' ? 'Matched' : 'Didn’t match — discrepancy open'}</span></span>; } },
                            { key: 'st', label: 'State', width: '1.5fr', cell: (m) => { const st = statusOf(m); return <span className="flex flex-col items-start gap-1 py-0.5"><CountBadge state={st.state}>{st.title}</CountBadge><StateLine tone={st.state === 'overdue' ? 'critical' : undefined}>{st.line}</StateLine>{m.house === 'kowhai' && noWitness && st.state !== 'counted' ? <StateLine tone="warning" icon={Users}>Nobody on shift can witness yet</StateLine> : null}</span>; } },
                            { key: 'act', label: '', width: '180px', align: 'right', cell: (m) => countAction(m) },
                        ]}
                        actionsFor={countMenu}
                        onOpen={(m) => open(statusOf(m).state === 'counted' ? `hist:${m.id}` : block || noWitness || m.house !== 'kowhai' ? 'cantcount' : `count:${m.id}`)}
                        onRowContextMenu={(e, m) => ctxCounts.openAt(e, `${PEOPLE[m.pid].pref} · ${m.med}`, countMenu(m))}
                    />
                ) : (
                    <Card className="p-2">
                        <EmptyState icon={CheckCircle2} title={show === 'now' ? 'No counts due now' : 'No controlled medicines match'} description={show === 'now' ? 'Every count is done. The next is at the 11:00 pm shift change.' : 'Clear the search or choose “All people”.'} />
                    </Card>
                )}
                <p className="text-caption">
                    Counted at every shift change ({CADENCE.decided}): the outgoing and incoming staff count together, one counting and one witnessing. A count shows as due 30 minutes before the change and overdue 1 hour after it. A count that doesn’t match is counted again; if it still differs, a discrepancy starts.
                </p>
                {ctxCounts.node}
            </section>

            {/* ── 2. controlled doses today ── */}
            <section className="flex flex-col gap-2.5" aria-label="Controlled doses today">
                <ListCaption title={`Controlled doses today · ${houseLabel}`} caption={`${doseRows.length} shown`} right={<EntityChip icon={Pill}>Recorded in the dose dialog, witness PIN at the cupboard</EntityChip>} />
                {doseRows.length ? (
                    <EntityTable<DoseRow>
                        rows={doseRows}
                        rowKey={(d) => d.id}
                        rowHeight="content"
                        minWidth={1000}
                        identityLabel="Person"
                        identityWidth="0.9fr"
                        identity={(d) => ({ mark: <PersonMark pid={d.pid} />, name: PEOPLE[d.pid].pref, subline: PEOPLE[d.pid].surname })}
                        columns={[
                            { key: 'due', label: 'Due', width: '0.6fr', cell: (d) => <span className="text-[12.5px] font-semibold tabular-nums">{isPrn(d) ? 'As needed' : d.slot}</span> },
                            { key: 'med', label: 'Medicine', width: '1.3fr', cell: (d) => <CdMedicineCell m={cdById(d.cdMed!)} sub={isPrn(d) ? 'As needed' : `${cdById(d.cdMed!).use}`} /> },
                            { key: 'st', label: 'State and witness', width: '2fr', cell: (d) => doseState(d) },
                            { key: 'act', label: '', width: '176px', align: 'right', cell: (d) => doseAction(d) },
                        ]}
                        actionsFor={doseMenu}
                        onOpen={(d) => (underOverride(d) ? open('ovr') : !isPrn(d) && d.state === 'due' && scn === 'noWitness' && s.override === 'none' ? open(`ovr-request:${d.id}`) : s.toast('info', 'Dose detail and recording are P01’s approved dialog — outside this preview.'))}
                        onRowContextMenu={(e, d) => ctxDoses.openAt(e, `${PEOPLE[d.pid].pref} · ${d.med}`, doseMenu(d))}
                    />
                ) : (
                    <Card className="p-2">
                        <EmptyState icon={Pill} title="No controlled doses match" description="Nothing due now needs a witness. Choose “Everything” to see today’s other controlled doses." />
                    </Card>
                )}
                {ctxDoses.node}
            </section>

            {/* ── 3. witness requests ── */}
            <section className="flex flex-col gap-2.5" aria-label="Witness requests">
                <ListCaption title={r.persona === 'pm' ? 'Witness requests and override requests' : 'Witness requests'} caption={`${reqRows.length} shown`} />
                {reqRows.length ? (
                    <EntityTable<ReqRow>
                        rows={reqRows}
                        rowKey={(x) => (x.kind === 'ovr' ? 'ovr' : x.a.id)}
                        rowHeight="content"
                        minWidth={1000}
                        identityLabel="Request"
                        identityWidth="1.7fr"
                        identity={(x) =>
                            x.kind === 'ovr'
                                ? { icon: ShieldCheck, name: 'Priya Shah asks for a witness override', subline: 'Kōwhai House · sent 2:48 pm' }
                                : x.a.to === persona.name
                                  ? { mark: <PersonDisc name={x.a.from} size={30} />, name: `${x.a.from} asks you to witness`, subline: `${HOUSES[x.a.house]} · sent ${x.a.at}` }
                                  : { mark: <PersonDisc name={x.a.to} size={30} />, name: `You asked ${x.a.to}`, subline: `${HOUSES[x.a.house]} · sent ${x.a.at}` }
                        }
                        columns={[
                            { key: 'what', label: 'For', width: '1.3fr', cell: (x) => <span className="flex flex-col gap-0.5 text-[12.5px]"><span className="font-semibold">{x.kind === 'ovr' ? 'Aroha’s methylphenidate, 2:30 pm dose' : x.a.what.charAt(0).toUpperCase() + x.a.what.slice(1)}</span><span className="text-muted-foreground">{x.kind === 'ovr' ? 'Nobody else on shift can witness (from the roster)' : x.a.note}</span></span> },
                            { key: 'st', label: 'State', width: '1.3fr', cell: (x) => (x.kind === 'ovr' ? <span className="flex flex-col items-start gap-1">{s.override === 'waiting' ? <StatusBadge variant="info">Waiting for you</StatusBadge> : s.override === 'approved' ? <StatusBadge variant="success">Approved until 3:00 pm</StatusBadge> : <StatusBadge variant="warning">Declined</StatusBadge>}</span> : <span className="flex flex-col items-start gap-1">{askBadge(x.a)}{x.a.reason ? <StateLine>“{x.a.reason}”</StateLine> : null}</span>) },
                            {
                                key: 'act',
                                label: '',
                                width: '180px',
                                align: 'right',
                                cell: (x) =>
                                    x.kind === 'ovr' ? (
                                        <Button size="sm" variant={s.override === 'waiting' ? 'default' : 'ghost'} className="frontline-tap" onClick={stop(() => open('ovr-approve'))}>
                                            {s.override === 'waiting' ? 'Review' : 'View'}
                                        </Button>
                                    ) : x.a.to === persona.name && x.a.status === 'sent' ? (
                                        <Button size="sm" className="frontline-tap" onClick={stop(() => open(`answer:${x.a.id}`))}>
                                            Answer
                                        </Button>
                                    ) : x.a.from === persona.name && ['sent', 'coming'].includes(x.a.status) ? (
                                        <Button size="sm" variant="ghost" className="frontline-tap" onClick={stop(() => open(`cancelask:${x.a.id}`))}>
                                            Cancel request
                                        </Button>
                                    ) : x.a.from === persona.name && x.a.status === 'cantcome' ? (
                                        <Button size="sm" variant="outline" className="frontline-tap" onClick={stop(() => open(`ask:${x.a.kind === 'count' ? 'count' : 'd8'}`))}>
                                            Ask someone else
                                        </Button>
                                    ) : (
                                        <span className="text-caption">Nothing to do</span>
                                    ),
                            },
                        ]}
                        actionsFor={reqMenu}
                        onOpen={(x) => (x.kind === 'ovr' ? open('ovr-approve') : x.a.to === persona.name && x.a.status === 'sent' ? open(`answer:${x.a.id}`) : undefined)}
                        onRowContextMenu={(e, x) => ctxAsks.openAt(e, x.kind === 'ovr' ? 'Witness override request' : 'Witness request', reqMenu(x))}
                    />
                ) : (
                    <Card className="p-2">
                        <EmptyState icon={Inbox} title="No witness requests" description="Ask someone to witness from a count or a controlled dose. Requests to you show here and in the bell." />
                    </Card>
                )}
                {ctxAsks.node}
            </section>

            {/* ── 4. discrepancies and follow-ups ── */}
            <section className="flex flex-col gap-2.5" aria-label="Open discrepancies and follow-ups">
                <ListCaption title={`Discrepancies and follow-ups · ${houseLabel}`} caption={`${openRows.length} shown`} />
                {openRows.length ? (
                    <EntityTable<OpenRow>
                        rows={openRows}
                        rowKey={(x) => (x.kind === 'fu' ? 'fu' : x.d.id)}
                        rowHeight="content"
                        minWidth={1000}
                        identityLabel="What"
                        identityWidth="1.9fr"
                        identity={(x) =>
                            x.kind === 'fu'
                                ? { icon: ShieldCheck, name: FOLLOW_UP.title, subline: 'For the house lead' }
                                : { icon: AlertOctagon, name: `Discrepancy — ${cdById(x.d.cdMed).med.toLowerCase()}`, subline: <span>{PEOPLE[cdById(x.d.cdMed).pid].pref} · <span className="text-muted-foreground">{x.d.ref}</span></span> }
                        }
                        columns={[
                            { key: 'detail', label: 'Detail', width: '1.5fr', cell: (x) => <span className="text-[12.5px]">{x.kind === 'fu' ? `${MORNING_OVERRIDE.doses.length} doses given without a witness: ${MORNING_OVERRIDE.doses.map((id) => { const d = doseById(id); return `${PEOPLE[d.pid].pref}’s ${d.med.toLowerCase()} ${d.slot}`; }).join(', ')}` : `${qty(Math.abs(x.d.recount - x.d.register), cdById(x.d.cdMed))} ${x.d.recount < x.d.register ? 'short' : 'over'} · counted twice · started ${x.d.startedAt} by ${short(x.d.by)}`}</span> },
                            { key: 'owner', label: 'Owner', width: '0.9fr', cell: (x) => <span className="flex items-center gap-2 text-[12.5px]"><PersonDisc name={x.kind === 'fu' ? FOLLOW_UP.owner : x.d.owner} size={22} />{short(x.kind === 'fu' ? FOLLOW_UP.owner : x.d.owner)}</span> },
                            { key: 'st', label: 'State', width: '1fr', cell: (x) => (x.kind === 'fu' ? (s.followUp === 'open' ? <span className="flex flex-col items-start gap-1"><StatusBadge variant="warning"><Clock3 className="size-3" />Open · by {FOLLOW_UP.due}</StatusBadge><StateLine>Witnessed count, then sign off</StateLine></span> : <StatusBadge variant="success"><CheckCircle2 className="size-3" />Signed off {'2:48 pm'}</StatusBadge>) : <span className="flex flex-col items-start gap-1"><StatusBadge variant="critical"><AlertOctagon className="size-3" />Open</StatusBadge><StateLine>The house lead investigates</StateLine></span>) },
                            {
                                key: 'act',
                                label: '',
                                width: '190px',
                                align: 'right',
                                cell: (x) =>
                                    x.kind === 'fu' ? (
                                        lead && s.followUp === 'open' ? (
                                            <Button data-return="followup" size="sm" className="frontline-tap" onClick={stop(() => open('followup'))}>
                                                <ClipboardCheck className="size-4" /> Count and sign off
                                            </Button>
                                        ) : (
                                            <Button size="sm" variant="ghost" className="frontline-tap" onClick={stop(() => open('ovr'))}>
                                                View
                                            </Button>
                                        )
                                    ) : (
                                        <Button size="sm" variant="ghost" className="frontline-tap" onClick={stop(() => open(`disc:${x.d.id}`))}>
                                            View
                                        </Button>
                                    ),
                            },
                        ]}
                        actionsFor={openMenu}
                        onOpen={(x) => (x.kind === 'fu' ? open(lead && s.followUp === 'open' ? 'followup' : 'ovr') : open(`disc:${x.d.id}`))}
                        onRowContextMenu={(e, x) => ctxOpen.openAt(e, x.kind === 'fu' ? FOLLOW_UP.title : 'Discrepancy', openMenu(x))}
                    />
                ) : (
                    <Card className="p-2">
                        <EmptyState icon={CheckCircle2} title="Nothing open" description={`No open discrepancies or follow-ups at ${houseLabel}.`} />
                    </Card>
                )}
                {ctxOpen.node}
            </section>

            {/* ── 5. register ── */}
            {show === 'all' ? (
                <section className="flex flex-col gap-2.5" aria-label="Register">
                    <ListCaption
                        title={range === 'today' ? `Register today · ${houseLabel}` : `Register, last 24 hours · ${houseLabel}`}
                        caption={`${shownEntries.length} of ${entries.length} shown`}
                        right={
                            kowhaiShown && persona.perms.includes('cd.record') ? (
                                <Button data-return="move" size="sm" variant="outline" className="frontline-tap" onClick={() => open(block ? 'cantcount' : 'move')}>
                                    <Package className="size-4" /> Record a movement
                                </Button>
                            ) : null
                        }
                    />
                    <EntityTable<Entry>
                        rows={shownEntries}
                        rowKey={(e) => e.id}
                        minWidth={1000}
                        identityLabel="When"
                        identityWidth="0.8fr"
                        identity={(e) => ({ icon: e.kind === 'count' ? ClipboardCheck : e.kind === 'dose' ? Pill : e.kind === 'out' ? ArrowUpRight : ArrowDownLeft, name: e.at, subline: e.day })}
                        rowHeight="content"
                        columns={[
                            { key: 'kind', label: 'What', width: '0.7fr', cell: (e) => <span className="text-[12.5px]">{KIND_LABEL[e.kind]}</span> },
                            { key: 'med', label: 'Medicine', width: '1.2fr', cell: (e) => <span className="text-[12.5px]"><span className="font-semibold">{cdById(e.cdMed).med}</span> · {PEOPLE[cdById(e.cdMed).pid].pref}</span> },
                            { key: 'ch', label: 'Change', width: '0.5fr', cell: (e) => <span className="text-[12.5px] font-semibold tabular-nums">{e.change === 0 ? '—' : e.change > 0 ? `+${e.change}` : e.change}</span> },
                            { key: 'after', label: 'Balance after', width: '0.7fr', cell: (e) => <span className="text-[12.5px] tabular-nums">{qty(e.after, cdById(e.cdMed))}</span> },
                            { key: 'by', label: 'By and witness', width: '1.1fr', cell: (e) => <span className="text-[12.5px]">{short(e.by)}{e.witness ? ` · ${short(e.witness)}` : ' · no witness'}</span> },
                            { key: 'd', label: 'Detail', width: '1.7fr', cell: (e) => <span className="text-[12px] text-muted-foreground">{e.detail}</span> },
                        ]}
                        actionsFor={regMenu}
                        onOpen={(e) => open(`hist:${e.cdMed}`)}
                        onRowContextMenu={(ev, e) => ctxReg.openAt(ev, `${cdById(e.cdMed).med} · ${e.at}`, regMenu(e))}
                    />
                    <LaravelPagination links={links} lastPage={last} />
                    <p className="text-caption">Times in NZDT. 10 per page. Read-only here — corrections, receipts and destructions are in the controlled register (P07b).</p>
                    {ctxReg.node}
                </section>
            ) : null}
            <DesignNote>
                Controlled checks is shown only to people who can record or witness controlled medicines, and only for their houses. For anyone without controlled-medicine access the tab, its counts and every controlled row, search result, bell item and task leave no trace (P00 v5). Sign in as Tomasi Vea to see it.
            </DesignNote>
        </>
    );
}

/* controlled doses at the houses (scheduled), in time order */
function DOSESOF(houses: HouseKey[]) {
    return ['d2', 'd4', 'd8', 'd11', 'r1', 'r2'].map(doseById).filter((d) => houses.includes(d.house));
}

