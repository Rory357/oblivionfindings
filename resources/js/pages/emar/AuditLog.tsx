/* eslint-disable no-restricted-syntax -- the audit timeline/table/gaps surfaces + hero footer are
   custom-layout bordered rows / chip buttons (not Card/Button); all colours are semantic tokens. */
/* DESIGN REVIEW: docs/emar-redesign/audit-design-review.md — design spec, intended look,
   deliberate deviations, and a fidelity checklist for reviewing this page's design. */
import { PageHero, type PageHeroBadge, type PageHeroMetaItem, type PageHeroStat } from '@/components/page';
import { EntityFilter, ShiftContextMenu, TabStrip, type RosterTabItem, type ShiftCtxItem, type ShiftCtxState } from '@/components/rostering';
import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { addDays, DayPickerChip, parseYmd, toYmd } from '@/components/meds/day-picker-chip';
import AppLayout from '@/layouts/app-layout';
import { eventMeta, eventPrimaryLink, FLAG_META, MedicationEventDrawer, type AuditEvent } from '@/components/emar/medication-event-drawer';
import { Head, router } from '@inertiajs/react';
import {
    Activity,
    AlertOctagon,
    AlertTriangle,
    ChevronLeft,
    ChevronRight,
    ClipboardCheck,
    Clock,
    Copy,
    Download,
    Eye,
    FileText,
    Fingerprint,
    History,
    Lock,
    Package,
    Pill,
    Printer,
    Search,
    ShieldAlert,
    ShieldCheck,
    Table,
    User,
    Users,
} from 'lucide-react';
import { type MouseEvent as ReactMouseEvent, useMemo, useState } from 'react';
import { toast } from 'sonner';

type Stats = { total: number; this_week: number; this_month: number; open_gaps: number };
type Props = {
    events: AuditEvent[];
    stats: Stats;
    clients: { id: number; name: string }[];
    staff: { id: number; name: string }[];
    sites: { id: number; name: string }[];
    active_site: { id: number; name: string } | null;
    site_brand_colour: string | null;
    user_first_name: string | null;
};

const CATEGORIES = [
    { id: 'all', label: 'All events', icon: History, tone: 'primary' as const },
    { id: 'doses', label: 'Doses', icon: Pill, tone: 'success' as const },
    { id: 'controlled', label: 'Controlled', icon: Lock, tone: 'primary' as const },
    { id: 'clinical', label: 'Clinical', icon: ClipboardCheck, tone: 'info' as const },
    { id: 'stock', label: 'Stock', icon: Package, tone: 'warning' as const },
    { id: 'errors', label: 'Errors', icon: AlertOctagon, tone: 'critical' as const },
];
const RANGES = [{ v: '7', l: '7 days' }, { v: '30', l: '30 days' }, { v: '90', l: '90 days' }];

// Right-click menu tag colours (token-based; mirrors ControlledDrugs' CTX_TAG).
const CTX_TAG: Record<'critical' | 'warning' | 'info' | 'success' | 'muted', { bg: string; color: string }> = {
    critical: { bg: 'var(--status-critical-bg)', color: 'var(--status-critical)' },
    warning: { bg: 'var(--status-warning-bg)', color: 'var(--status-warning)' },
    info: { bg: 'var(--status-info-bg)', color: 'var(--status-info)' },
    success: { bg: 'var(--status-success-bg)', color: 'var(--status-success)' },
    muted: { bg: 'var(--muted)', color: 'var(--muted-foreground)' },
};
const ctxToneFor = (e: AuditEvent): keyof typeof CTX_TAG => {
    if (e.flags.length > 0) return 'critical';
    switch (e.category) {
        case 'doses': return 'success';
        case 'stock': return 'warning';
        case 'errors': return 'critical';
        case 'controlled':
        case 'clinical':
        default: return 'info';
    }
};
const fmtTime = (iso: string) => new Date(iso).toLocaleTimeString('en-NZ', { hour: '2-digit', minute: '2-digit' });
const dayKey = (iso: string) => new Date(iso).toLocaleDateString('en-NZ', { weekday: 'long', day: 'numeric', month: 'long' });
const fmtShort = (d: Date) => d.toLocaleDateString('en-NZ', { day: 'numeric', month: 'short' });
const stepLabel = (ymd: string) => parseYmd(ymd).toLocaleDateString('en-NZ', { weekday: 'short', day: 'numeric' });
const initials = (n: string) => n.split(' ').filter(Boolean).slice(0, 2).map((p) => p[0]).join('').toUpperCase() || '?';

export default function AuditLog({ events, stats, clients, staff, sites, active_site: activeSite, site_brand_colour: brandColour, user_first_name: userFirstName }: Props) {
    const [view, setView] = useState('timeline');
    const [cat, setCat] = useState('all');
    const [search, setSearch] = useState('');
    const [clientId, setClientId] = useState('');
    const [staffName, setStaffName] = useState('');
    const [range, setRange] = useState('90');
    const [source, setSource] = useState('');
    const [anchor, setAnchor] = useState(() => toYmd(new Date()));
    const [siteFilter, setSiteFilter] = useState<number | null>(activeSite?.id ?? null);
    const [selected, setSelected] = useState<AuditEvent | null>(null);
    const [selectedSection, setSelectedSection] = useState<string | undefined>(undefined);
    const [ctx, setCtx] = useState<ShiftCtxState | null>(null);

    // Open the read-only detail drawer; `section` focuses a panel (e.g. integrity).
    const openEvent = (e: AuditEvent, section?: string) => { setSelectedSection(section); setSelected(e); };

    // Read-only / navigational right-click menu for an event row (parity with PRN/CD).
    // No record/edit/delete items — this surface is append-only.
    const openRowCtx = (ev: ReactMouseEvent, e: AuditEvent) => {
        ev.preventDefault();
        const link = eventPrimaryLink(e);
        const med = typeof e.details?.medication === 'string' ? e.details.medication : null;
        const items: ShiftCtxItem[] = [
            { icon: <Eye className="h-3.5 w-3.5" />, label: 'View record', sub: e.description, tone: 'primary', onClick: () => openEvent(e) },
            ...(e.client_id ? [{ icon: <User className="h-3.5 w-3.5" />, label: 'View client', onClick: () => router.visit(`/operations/clients/${e.client_id}/care`) } satisfies ShiftCtxItem] : []),
            { icon: <FileText className="h-3.5 w-3.5" />, label: `Open on ${link.label}`, onClick: () => router.visit(link.href) },
            { icon: <Fingerprint className="h-3.5 w-3.5" />, label: 'Verify integrity', sub: 'Tamper-evidence check', onClick: () => openEvent(e, 'integrity') },
            { sep: true },
            { icon: <Download className="h-3.5 w-3.5" />, label: 'Export this event', sub: 'CSV', onClick: () => window.open(`/emar/audit/event/${encodeURIComponent(e.id)}/export`, '_blank') },
            { icon: <Copy className="h-3.5 w-3.5" />, label: 'Copy event ID', onClick: () => { void navigator.clipboard?.writeText(e.id).then(() => toast.success('Event ID copied')).catch(() => toast.error('Could not copy')); } },
        ];
        const t = CTX_TAG[ctxToneFor(e)];
        const meta = [e.client_name, med, fmtTime(e.timestamp)].filter(Boolean).join(' · ');
        setCtx({ x: ev.clientX, y: ev.clientY, tag: eventMeta(e.event_type).label.toUpperCase(), tagBg: t.bg, tagColor: t.color, meta, items });
    };

    const todayYmd = toYmd(new Date());
    const isToday = anchor === todayYmd;
    const sources = useMemo(() => [...new Set(events.map((e) => e.source))].sort(), [events]);

    const filtered = useMemo(() => {
        const q = search.trim().toLowerCase();
        // The day-stepper sets the window's *end* (anchor); the range select sets
        // how far back it reaches — "the {range} days ending {anchor}".
        const end = parseYmd(anchor);
        end.setHours(23, 59, 59, 999);
        const windowEnd = end.getTime();
        const windowStart = windowEnd - Number(range) * 86400000;
        return events.filter((e) => {
            if (cat !== 'all' && e.category !== cat) return false;
            if (clientId && String(e.client_id) !== clientId) return false;
            if (staffName && e.performed_by !== staffName) return false;
            if (source && e.source !== source) return false;
            const t = new Date(e.timestamp).getTime();
            if (t < windowStart || t > windowEnd) return false;
            if (q && !`${e.description} ${e.client_name} ${e.performed_by ?? ''}`.toLowerCase().includes(q)) return false;
            return true;
        });
    }, [events, cat, clientId, staffName, source, range, search, anchor]);

    const gaps = useMemo(() => filtered.filter((e) => e.flags.length > 0), [filtered]);
    const rows = view === 'gaps' ? gaps : filtered;
    const hasFilters = !!(search || clientId || staffName || source || range !== '90' || cat !== 'all' || !isToday);
    const clearFilters = () => { setSearch(''); setClientId(''); setStaffName(''); setSource(''); setRange('90'); setCat('all'); setAnchor(todayYmd); };

    const missingWitness = useMemo(() => events.filter((e) => e.flags.includes('missing_witness')).length, [events]);

    const catCounts = useMemo(() => {
        const base = events.filter((e) => {
            if (clientId && String(e.client_id) !== clientId) return false;
            if (staffName && e.performed_by !== staffName) return false;
            if (source && e.source !== source) return false;
            return true;
        });
        return Object.fromEntries(CATEGORIES.map((c) => [c.id, c.id === 'all' ? base.length : base.filter((e) => e.category === c.id).length]));
    }, [events, clientId, staffName, source]);

    const byDay = useMemo(() => {
        const groups = new Map<string, AuditEvent[]>();
        rows.forEach((e) => { const k = dayKey(e.timestamp); if (!groups.has(k)) groups.set(k, []); groups.get(k)!.push(e); });
        return [...groups.entries()];
    }, [rows]);

    const onSite = (id: number | null) => { setSiteFilter(id); router.get('/emar/audit', id ? { site_id: id } : {}, { preserveState: true, preserveScroll: true }); };

    const VIEW_TABS: RosterTabItem[] = [
        { id: 'timeline', label: 'Timeline', icon: Activity, tone: 'primary' },
        { id: 'table', label: 'Table', icon: Table, tone: 'primary' },
        { id: 'gaps', label: 'Compliance gaps', icon: ShieldAlert, tone: 'critical', badge: gaps.length || undefined },
    ];
    const CAT_TABS: RosterTabItem[] = CATEGORIES.map((c) => ({ id: c.id, label: c.label, icon: c.icon, tone: c.tone, badge: catCounts[c.id] || undefined }));

    const heroStats: PageHeroStat[] = [
        { label: 'Total events', value: stats.total },
        { label: 'This week', value: stats.this_week },
        { label: 'This month', value: stats.this_month },
        { label: 'Open gaps', value: stats.open_gaps, tone: stats.open_gaps > 0 ? 'warning' : 'neutral' },
    ];

    const windowFrom = fmtShort(new Date(parseYmd(anchor).getTime() - Number(range) * 86400000));
    const windowTo = fmtShort(parseYmd(anchor));
    const heroMeta: PageHeroMetaItem[] = [
        { icon: Clock, label: `${range}-day window · ${windowFrom} – ${windowTo}` },
        { icon: ShieldCheck, label: 'Append-only · immutable source records' },
        { icon: Users, label: `${stats.total} actions · ${staff.length} staff · ${sites.length} site${sites.length === 1 ? '' : 's'}` },
    ];

    const heroBadges: PageHeroBadge[] = [];
    if (stats.open_gaps > 0) {
        heroBadges.push({ icon: AlertTriangle, label: `${stats.open_gaps} unexplained MAR gap${stats.open_gaps === 1 ? '' : 's'}`, tone: 'critical', onClick: () => setView('gaps'), 'aria-label': 'View compliance gaps' });
    }
    if (missingWitness > 0) {
        heroBadges.push({ icon: Lock, label: `${missingWitness} CD ${missingWitness === 1 ? 'entry' : 'entries'} missing witness`, tone: 'critical', onClick: () => { setCat('controlled'); setView('gaps'); }, 'aria-label': 'View controlled-drug entries missing a witness' });
    }

    const refreshed = new Date().toLocaleTimeString('en-NZ', { hour: '2-digit', minute: '2-digit' });

    return (
        <AppLayout breadcrumbs={[{ title: 'eMAR', href: '/emar' }, { title: 'Audit Trail', href: '/emar/audit' }]}>
            <Head title="eMAR - Audit Trail" />
            <div className="flex flex-col gap-6 p-6">
                <PageHero
                    variant="hero"
                    category="ops"
                    brandColour={brandColour}
                    icon={History}
                    meta={heroMeta}
                    badges={heroBadges}
                    title={
                        <span>
                            <span className="flex items-center gap-2 text-[10.5px] font-semibold uppercase tracking-wide text-primary-foreground/80">
                                <span aria-hidden className="relative inline-flex h-2 w-2">
                                    <span className="absolute inset-0 animate-ping rounded-full bg-status-success/70" />
                                    <span className="relative inline-flex h-2 w-2 rounded-full bg-status-success" />
                                </span>
                                Append-only audit trail · live · refreshed {refreshed}
                            </span>
                            <span className="mt-1 block text-[26px] font-bold leading-tight">
                                {userFirstName ? <span className="font-normal text-primary-foreground/80">Kia ora {userFirstName} — </span> : null}
                                {userFirstName ? 'every' : 'Every'} medication action across{' '}
                                <span className="border-b-2 border-primary-foreground/40">{activeSite?.name ?? 'your services'}</span>
                            </span>
                        </span>
                    }
                    description={`A complete, time-stamped record of every dose, controlled-drug movement, prescriber order and review — append-only for CQC and internal governance.${stats.open_gaps > 0 ? ` ${stats.open_gaps} unexplained gap${stats.open_gaps === 1 ? '' : 's'} need a clinician.` : ''}`}
                    stats={heroStats}
                    actions={
                        <>
                            <a href="/emar/audit/export"><Button className="bg-primary-foreground text-primary hover:bg-primary-foreground/90"><Download className="h-4 w-4" />Export audit pack</Button></a>
                            <a href="/emar/reports"><Button variant="outline" className="border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground hover:bg-primary-foreground/20"><Printer className="h-4 w-4" />Print MAR &amp; CD register</Button></a>
                        </>
                    }
                    footer={
                        <div className="flex flex-col gap-3 py-3 lg:flex-row lg:items-center lg:justify-between">
                            <div className="flex flex-wrap items-center gap-1.5">
                                <button type="button" onClick={() => setAnchor(addDays(anchor, -1))} className="inline-flex items-center gap-1 rounded-md border border-primary-foreground/20 bg-primary-foreground/10 px-3 py-1.5 text-xs font-semibold text-primary-foreground hover:bg-primary-foreground/20">
                                    <ChevronLeft className="h-3.5 w-3.5" />{stepLabel(addDays(anchor, -1))}
                                </button>
                                <DayPickerChip date={anchor} isToday={isToday} onPick={setAnchor} label="audit window" caption="The audit window ends on the selected day; the range filter sets how far back it reaches." />
                                <button type="button" onClick={() => setAnchor(addDays(anchor, 1))} disabled={isToday} className="inline-flex items-center gap-1 rounded-md border border-primary-foreground/20 bg-primary-foreground/10 px-3 py-1.5 text-xs font-semibold text-primary-foreground hover:bg-primary-foreground/20 disabled:cursor-not-allowed disabled:opacity-40">
                                    {stepLabel(addDays(anchor, 1))}<ChevronRight className="h-3.5 w-3.5" />
                                </button>
                                {!isToday ? (
                                    <button type="button" onClick={() => setAnchor(todayYmd)} className="inline-flex items-center gap-1 rounded-md border border-primary-foreground/35 bg-primary-foreground/20 px-3 py-1.5 text-xs font-semibold text-primary-foreground hover:bg-primary-foreground/30">
                                        Back to today
                                    </button>
                                ) : null}
                            </div>
                            <div className="flex flex-wrap items-center gap-2">
                                <div className="flex items-center gap-2 rounded-full bg-primary-foreground px-3 py-1.5">
                                    <Search className="h-3.5 w-3.5 text-muted-foreground" />
                                    <input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search client, medication, staff or NHI…" className="w-64 bg-transparent text-sm text-foreground outline-none placeholder:text-muted-foreground" />
                                </div>
                                {sites.length > 0 && <EntityFilter label="Site" allLabel="All sites" items={sites} value={siteFilter} onChange={onSite} onDark />}
                            </div>
                        </div>
                    }
                />

                <div className="flex flex-wrap items-center justify-between gap-3">
                    <TabStrip value={view} onChange={setView} items={VIEW_TABS} ariaLabel="Audit views" />
                    <div className="flex items-center gap-2 text-sm text-muted-foreground">
                        {view === 'gaps' ? `${rows.length} gap${rows.length === 1 ? '' : 's'} detected` : `Showing ${rows.length} of ${stats.total} events`}
                        {hasFilters && <Button size="sm" variant="ghost" onClick={clearFilters}>Clear</Button>}
                    </div>
                </div>

                <div className="overflow-hidden rounded-2xl border bg-card shadow-sm">
                    {view !== 'gaps' && (
                        <div className="flex flex-col gap-3 border-b p-4">
                            <TabStrip value={cat} onChange={setCat} items={CAT_TABS} ariaLabel="Event categories" />
                            <div className="flex flex-wrap gap-2">
                                <Select value={clientId || 'all'} onValueChange={(v) => setClientId(v === 'all' ? '' : v)}>
                                    <SelectTrigger className="h-9 w-[160px]"><SelectValue placeholder="All clients" /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">All clients</SelectItem>
                                        {clients.map((c) => <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>)}
                                    </SelectContent>
                                </Select>
                                <Select value={staffName || 'all'} onValueChange={(v) => setStaffName(v === 'all' ? '' : v)}>
                                    <SelectTrigger className="h-9 w-[160px]"><SelectValue placeholder="All staff" /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">All staff</SelectItem>
                                        {staff.map((s) => <SelectItem key={s.id} value={s.name}>{s.name}</SelectItem>)}
                                    </SelectContent>
                                </Select>
                                <Select value={range} onValueChange={setRange}>
                                    <SelectTrigger className="h-9 w-[130px]"><SelectValue /></SelectTrigger>
                                    <SelectContent>
                                        {RANGES.map((r) => <SelectItem key={r.v} value={r.v}>{r.l}</SelectItem>)}
                                    </SelectContent>
                                </Select>
                                <Select value={source || 'all'} onValueChange={(v) => setSource(v === 'all' ? '' : v)}>
                                    <SelectTrigger className="h-9 w-[150px]"><SelectValue placeholder="All sources" /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">All sources</SelectItem>
                                        {sources.map((s) => <SelectItem key={s} value={s}>{s}</SelectItem>)}
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>
                    )}

                    {rows.length === 0 ? (
                        <div className="px-5 py-16 text-center text-sm text-muted-foreground">{view === 'gaps' ? 'No compliance gaps — every record is attributed and witnessed.' : 'No events match the current filters.'}</div>
                    ) : view === 'timeline' ? (
                        <div className="flex flex-col gap-4 p-4">
                            {byDay.map(([day, items]) => (
                                <div key={day}>
                                    <div className="mb-2 flex items-center gap-3"><span className="text-xs font-bold">{day}</span><span className="h-px flex-1 bg-border" /><span className="text-xs text-muted-foreground">{items.length} events</span></div>
                                    <div className="flex flex-col gap-2">
                                        {items.map((e) => <TimelineRow key={e.id} e={e} onOpen={() => openEvent(e)} onCtx={(ev) => openRowCtx(ev, e)} />)}
                                    </div>
                                </div>
                            ))}
                        </div>
                    ) : view === 'table' ? (
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[920px] text-sm">
                                <thead>
                                    <tr className="bg-muted/50 text-left text-[11px] uppercase tracking-wide text-muted-foreground">
                                        <th className="px-4 py-2.5">Time</th><th className="px-4 py-2.5">Event</th><th className="px-4 py-2.5">Client</th><th className="px-4 py-2.5">Outcome</th><th className="px-4 py-2.5">Performed by</th><th className="px-4 py-2.5">Witness</th><th className="px-4 py-2.5">Source</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {rows.map((e) => { const m = eventMeta(e.event_type); const Icon = m.icon; return (
                                        <tr key={e.id} className="cursor-pointer border-b last:border-b-0 hover:bg-muted/30" onClick={() => openEvent(e)} onContextMenu={(ev) => openRowCtx(ev, e)}>
                                            <td className="px-4 py-3 font-mono text-xs">{fmtTime(e.timestamp)}</td>
                                            <td className="px-4 py-3"><span className="inline-flex items-center gap-1.5"><span className={`flex h-5 w-5 items-center justify-center rounded ${m.cls}`}><Icon className="h-3 w-3" /></span>{m.label}</span></td>
                                            <td className="px-4 py-3">{e.client_name}</td>
                                            <td className="px-4 py-3 text-muted-foreground">{e.outcome ?? '—'}</td>
                                            <td className="px-4 py-3">{e.performed_by ?? <span className="rounded-full bg-status-warning-bg px-1.5 py-0.5 text-[10px] font-semibold text-status-warning">Not captured</span>}</td>
                                            <td className="px-4 py-3">{e.witness_required ? (e.witness ? <span className="text-status-success">{e.witness}</span> : <span className="rounded-full bg-status-critical-bg px-1.5 py-0.5 text-[10px] font-semibold text-status-critical">Required — missing</span>) : '—'}</td>
                                            <td className="px-4 py-3"><span className="rounded-full bg-muted px-2 py-0.5 text-[11px] text-muted-foreground">{e.source}</span></td>
                                        </tr>
                                    ); })}
                                </tbody>
                            </table>
                        </div>
                    ) : (
                        <div className="flex flex-col gap-4 p-4">
                            <div className="rounded-xl border border-status-critical/30 bg-status-critical-bg/50 px-4 py-3 text-sm text-status-critical">
                                <span className="font-semibold">Why this matters:</span> a blank MAR slot or an unwitnessed controlled-drug transaction is a medication error in CQC's view (NICE SC1). Each gap below needs a clinician to reconcile or countersign.
                            </div>
                            <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                                {rows.map((e) => { const m = eventMeta(e.event_type); const Icon = m.icon; return (
                                    <div key={e.id} className="rounded-2xl border border-status-critical/30 bg-card p-4 shadow-sm" onContextMenu={(ev) => openRowCtx(ev, e)}>
                                        <div className="flex items-center gap-2"><span className={`flex h-8 w-8 items-center justify-center rounded-lg ${m.cls}`}><Icon className="h-4 w-4" /></span><div className="text-sm font-semibold">{e.flags.map((f) => FLAG_META[f]?.label ?? f).join(' · ')}</div></div>
                                        <div className="mt-2 text-sm">{e.description}</div>
                                        <div className="mt-1 text-xs text-muted-foreground">{e.performed_by ?? 'Unattributed'} · {new Date(e.timestamp).toLocaleDateString('en-NZ')}</div>
                                        <div className="mt-3"><Button size="sm" variant="outline" onClick={() => openEvent(e)}>View record</Button></div>
                                    </div>
                                ); })}
                            </div>
                        </div>
                    )}
                </div>
            </div>

            {selected && (
                <MedicationEventDrawer
                    key={`${selected.id}-${selectedSection ?? 'what'}`}
                    event={selected}
                    initialSection={selectedSection}
                    onClose={() => { setSelected(null); setSelectedSection(undefined); }}
                />
            )}
            {ctx && <ShiftContextMenu ctx={ctx} onClose={() => setCtx(null)} />}
        </AppLayout>
    );
}

function TimelineRow({ e, onOpen, onCtx }: { e: AuditEvent; onOpen: () => void; onCtx: (ev: ReactMouseEvent) => void }) {
    const m = eventMeta(e.event_type);
    const Icon = m.icon;
    const isGap = e.flags.length > 0;
    return (
        <button onClick={onOpen} onContextMenu={onCtx} className={`flex w-full items-center gap-3 rounded-[14px] border bg-card px-3 py-2.5 text-left transition hover:border-primary/40 hover:bg-muted/30 ${isGap ? 'border-dashed border-status-critical/50' : ''}`}>
            <span className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-full ${m.cls}`}><Icon className="h-4 w-4" /></span>
            <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-center gap-1.5">
                    <span className="text-xs font-semibold">{m.label}</span>
                    <span className="font-mono text-[11px] text-muted-foreground">{fmtTime(e.timestamp)}</span>
                    <span className="rounded-full bg-muted px-1.5 py-0.5 text-[10px] text-muted-foreground">{e.source}</span>
                    {e.flags.map((f) => <span key={f} className={`rounded-full px-1.5 py-0.5 text-[10px] font-semibold ${FLAG_META[f]?.cls ?? ''}`}>{FLAG_META[f]?.label ?? f}</span>)}
                </div>
                <div className="truncate text-sm font-medium">{e.description}</div>
                <div className="flex items-center gap-1.5 text-xs text-muted-foreground">
                    <span className="flex h-4 w-4 items-center justify-center rounded-full bg-primary/10 text-[8px] font-bold text-primary">{e.performed_by ? initials(e.performed_by) : '?'}</span>
                    {e.performed_by ?? <span className="text-status-warning">Not attributed</span>}{e.site_name ? ` · ${e.site_name}` : ''}
                </div>
            </div>
            <span className="shrink-0 text-xs font-medium text-primary">View record ›</span>
        </button>
    );
}
