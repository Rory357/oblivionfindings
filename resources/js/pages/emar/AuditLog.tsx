/* eslint-disable no-restricted-syntax -- the audit timeline/table/gaps surfaces + hero footer are
   custom-layout bordered rows / chip buttons (not Card/Button); all colours are semantic tokens. */
import { PageHero, type PageHeroStat } from '@/components/page';
import { EntityFilter, TabStrip, type RosterTabItem } from '@/components/rostering';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { eventMeta, FLAG_META, MedicationEventDrawer, type AuditEvent } from '@/components/emar/medication-event-drawer';
import { Head, router } from '@inertiajs/react';
import { Activity, AlertOctagon, ClipboardCheck, Download, History, Lock, Package, Pill, Printer, Search, ShieldAlert, Table } from 'lucide-react';
import { useMemo, useState } from 'react';

type Stats = { total: number; this_week: number; this_month: number; open_gaps: number };
type Props = {
    events: AuditEvent[];
    stats: Stats;
    clients: { id: number; name: string }[];
    staff: { id: number; name: string }[];
    sites: { id: number; name: string }[];
    active_site: { id: number; name: string } | null;
    site_brand_colour: string | null;
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
const SELECT_CLASS = 'h-9 rounded-lg border border-input bg-card px-2.5 text-sm outline-none focus:border-ring';
const fmtTime = (iso: string) => new Date(iso).toLocaleTimeString('en-NZ', { hour: '2-digit', minute: '2-digit' });
const dayKey = (iso: string) => new Date(iso).toLocaleDateString('en-NZ', { weekday: 'long', day: 'numeric', month: 'long' });
const initials = (n: string) => n.split(' ').filter(Boolean).slice(0, 2).map((p) => p[0]).join('').toUpperCase() || '?';

export default function AuditLog({ events, stats, clients, staff, sites, active_site: activeSite, site_brand_colour: brandColour }: Props) {
    const [view, setView] = useState('timeline');
    const [cat, setCat] = useState('all');
    const [search, setSearch] = useState('');
    const [clientId, setClientId] = useState('');
    const [staffName, setStaffName] = useState('');
    const [range, setRange] = useState('90');
    const [source, setSource] = useState('');
    const [siteFilter, setSiteFilter] = useState<number | null>(activeSite?.id ?? null);
    const [selected, setSelected] = useState<AuditEvent | null>(null);

    const sources = useMemo(() => [...new Set(events.map((e) => e.source))].sort(), [events]);

    const filtered = useMemo(() => {
        const q = search.trim().toLowerCase();
        const cutoff = Date.now() - Number(range) * 86400000;
        return events.filter((e) => {
            if (cat !== 'all' && e.category !== cat) return false;
            if (clientId && String(e.client_id) !== clientId) return false;
            if (staffName && e.performed_by !== staffName) return false;
            if (source && e.source !== source) return false;
            if (new Date(e.timestamp).getTime() < cutoff) return false;
            if (q && !`${e.description} ${e.client_name} ${e.performed_by ?? ''}`.toLowerCase().includes(q)) return false;
            return true;
        });
    }, [events, cat, clientId, staffName, source, range, search]);

    const gaps = useMemo(() => filtered.filter((e) => e.flags.length > 0), [filtered]);
    const rows = view === 'gaps' ? gaps : filtered;
    const hasFilters = !!(search || clientId || staffName || source || range !== '90' || cat !== 'all');
    const clearFilters = () => { setSearch(''); setClientId(''); setStaffName(''); setSource(''); setRange('90'); setCat('all'); };

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

    return (
        <AppLayout breadcrumbs={[{ title: 'eMAR', href: '/emar' }, { title: 'Audit Trail', href: '/emar/audit' }]}>
            <Head title="eMAR - Audit Trail" />
            <div className="flex flex-col gap-6 p-6">
                <PageHero
                    variant="hero"
                    category="ops"
                    brandColour={brandColour}
                    icon={History}
                    title={
                        <span>
                            <span className="flex items-center gap-2 text-[10.5px] font-semibold uppercase tracking-wide text-primary-foreground/80">
                                <span aria-hidden className="relative inline-flex h-2 w-2">
                                    <span className="absolute inset-0 animate-ping rounded-full bg-status-success/70" />
                                    <span className="relative inline-flex h-2 w-2 rounded-full bg-status-success" />
                                </span>
                                Tamper-evident audit trail · live
                            </span>
                            <span className="mt-1 block text-[26px] font-bold leading-tight">
                                Every medication action across{' '}
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
                            <div className="flex items-center gap-2 rounded-full bg-primary-foreground px-3 py-1.5">
                                <Search className="h-3.5 w-3.5 text-muted-foreground" />
                                <input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search client, medication, staff or NHI…" className="w-64 bg-transparent text-sm text-foreground outline-none placeholder:text-muted-foreground" />
                            </div>
                            {sites.length > 0 && <EntityFilter label="Site" allLabel="All sites" items={sites} value={siteFilter} onChange={onSite} onDark />}
                        </div>
                    }
                />

                {stats.open_gaps > 0 && (
                    <div className="flex items-center justify-between gap-3 rounded-xl border border-status-critical/30 bg-status-critical-bg/60 px-4 py-3">
                        <span className="flex items-center gap-2 text-sm font-medium text-status-critical"><ShieldAlert className="h-4 w-4" />{stats.open_gaps} compliance gap{stats.open_gaps === 1 ? '' : 's'} (missing witness or MAR omission) need a clinician's attention.</span>
                        <Button size="sm" variant="outline" onClick={() => setView('gaps')}>Review gaps</Button>
                    </div>
                )}

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
                                <select className={SELECT_CLASS} value={clientId} onChange={(e) => setClientId(e.target.value)}>
                                    <option value="">All clients</option>
                                    {clients.map((c) => <option key={c.id} value={String(c.id)}>{c.name}</option>)}
                                </select>
                                <select className={SELECT_CLASS} value={staffName} onChange={(e) => setStaffName(e.target.value)}>
                                    <option value="">All staff</option>
                                    {staff.map((s) => <option key={s.id} value={s.name}>{s.name}</option>)}
                                </select>
                                <select className={SELECT_CLASS} value={range} onChange={(e) => setRange(e.target.value)}>
                                    {RANGES.map((r) => <option key={r.v} value={r.v}>{r.l}</option>)}
                                </select>
                                <select className={SELECT_CLASS} value={source} onChange={(e) => setSource(e.target.value)}>
                                    <option value="">All sources</option>
                                    {sources.map((s) => <option key={s} value={s}>{s}</option>)}
                                </select>
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
                                        {items.map((e) => <TimelineRow key={e.id} e={e} onOpen={() => setSelected(e)} />)}
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
                                        <tr key={e.id} className="cursor-pointer border-b last:border-b-0 hover:bg-muted/30" onClick={() => setSelected(e)}>
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
                                    <div key={e.id} className="rounded-2xl border border-status-critical/30 bg-card p-4 shadow-sm">
                                        <div className="flex items-center gap-2"><span className={`flex h-8 w-8 items-center justify-center rounded-lg ${m.cls}`}><Icon className="h-4 w-4" /></span><div className="text-sm font-semibold">{e.flags.map((f) => FLAG_META[f]?.label ?? f).join(' · ')}</div></div>
                                        <div className="mt-2 text-sm">{e.description}</div>
                                        <div className="mt-1 text-xs text-muted-foreground">{e.performed_by ?? 'Unattributed'} · {new Date(e.timestamp).toLocaleDateString('en-NZ')}</div>
                                        <div className="mt-3"><Button size="sm" variant="outline" onClick={() => setSelected(e)}>View record</Button></div>
                                    </div>
                                ); })}
                            </div>
                        </div>
                    )}
                </div>
            </div>

            {selected && <MedicationEventDrawer event={selected} onClose={() => setSelected(null)} />}
        </AppLayout>
    );
}

function TimelineRow({ e, onOpen }: { e: AuditEvent; onOpen: () => void }) {
    const m = eventMeta(e.event_type);
    const Icon = m.icon;
    const isGap = e.flags.length > 0;
    return (
        <button onClick={onOpen} className={`flex w-full items-center gap-3 rounded-[14px] border bg-card px-3 py-2.5 text-left transition hover:border-primary/40 hover:bg-muted/30 ${isGap ? 'border-dashed border-status-critical/50' : ''}`}>
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
