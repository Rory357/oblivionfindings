/* eslint-disable no-restricted-syntax -- error table, summary/analytics cards, filter toolbar and
   hero month stepper are custom-layout bordered surfaces / chip buttons (not Card/Button); colours
   are semantic tokens. */
import { PageHero, type PageHeroStat } from '@/components/page';
import { EntityFilter, TabStrip, type RosterTabItem } from '@/components/rostering';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import {
    CloseErrorDialog,
    ERROR_TYPES,
    ResolveErrorDialog,
    ReviewErrorDialog,
    SEVERITIES,
    severityMeta,
    statusMeta,
    TriageDialog,
    type ErrorRow,
    type TriageAction,
    typeLabel,
} from '@/pages/emar/_error-dialogs';
import { ReportErrorModal } from '@/pages/emar/components/report-error-modal';
import { Head, router } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, ChevronLeft, ChevronRight, Link2, ListChecks, Paperclip, Plus, Search } from 'lucide-react';
import { useMemo, useState } from 'react';

type Trend = { week: string; count: number; near_miss: number };
type Stats = { total_open: number; critical: number; this_month: number; resolved_this_month: number; near_miss: number; trend: Trend[]; by_type: Record<string, number>; by_severity: Record<string, number> };

type Props = {
    errors: ErrorRow[];
    stats: Stats;
    clients: { id: number; first_name: string; last_name: string }[];
    staff: { id: number; name: string }[];
    sites: { id: number; name: string }[];
    active_site: { id: number; name: string } | null;
    site_brand_colour: string | null;
};

type Modal = { type: 'report' } | { type: 'triage' | 'review' | 'resolve' | 'close'; error: ErrorRow } | null;

const initials = (n: string) => n.split(' ').filter(Boolean).slice(0, 2).map((p) => p[0]).join('').toUpperCase() || '?';
const fmtDate = (iso: string | null) => (iso ? new Date(iso).toLocaleDateString('en-NZ', { day: 'numeric', month: 'short' }) : '—');
const SEV_DOT: Record<string, string> = { near_miss: 'bg-muted-foreground', minor: 'bg-status-info', moderate: 'bg-status-warning', major: 'bg-status-warning', critical: 'bg-status-critical' };

export default function MedicationErrors({ errors, stats, clients, staff, sites, active_site: activeSite, site_brand_colour: brandColour }: Props) {
    const [activeTab, setActiveTab] = useState('all');
    const [search, setSearch] = useState('');
    const [clientFilter, setClientFilter] = useState<number | null>(null);
    const [severityFilter, setSeverityFilter] = useState<number | null>(null);
    const [typeFilter, setTypeFilter] = useState<number | null>(null);
    const [reporterFilter, setReporterFilter] = useState<number | null>(null);
    const [month, setMonth] = useState<number | null>(null); // null = all time; else offset from current month
    const [siteFilter, setSiteFilter] = useState<number | null>(activeSite?.id ?? null);
    const [modal, setModal] = useState<Modal>(null);

    const monthLabel = useMemo(() => { const d = new Date(); d.setMonth(d.getMonth() + (month ?? 0)); return d.toLocaleDateString('en-NZ', { month: 'long', year: 'numeric' }); }, [month]);

    const filtered = useMemo(() => {
        const q = search.trim().toLowerCase();
        const sev = severityFilter !== null ? SEVERITIES[severityFilter]?.value : null;
        const typ = typeFilter !== null ? ERROR_TYPES[typeFilter]?.value : null;
        return errors.filter((e) => {
            if (clientFilter && e.client_id !== clientFilter) return false;
            if (sev && e.severity !== sev) return false;
            if (typ && e.error_type !== typ) return false;
            if (reporterFilter && e.reported_by_user?.id !== reporterFilter) return false;
            if (month !== null && e.reported_at) { const d = new Date(); d.setDate(1); d.setMonth(d.getMonth() + month); const ed = new Date(e.reported_at); if (ed.getMonth() !== d.getMonth() || ed.getFullYear() !== d.getFullYear()) return false; }
            if (q) { const name = e.client ? `${e.client.first_name} ${e.client.last_name}` : ''; if (!`${name} ${e.medication?.name ?? ''} ${e.ref}`.toLowerCase().includes(q)) return false; }
            return true;
        });
    }, [errors, search, clientFilter, severityFilter, typeFilter, reporterFilter, month]);

    const byTab = (tab: string) => filtered.filter((e) =>
        tab === 'open' ? ['reported', 'investigating'].includes(e.status)
            : tab === 'critical' ? e.severity === 'critical'
                : tab === 'nearmiss' ? e.severity === 'near_miss'
                    : tab === 'resolved' ? ['resolved', 'closed'].includes(e.status)
                        : true);
    const rows = byTab(activeTab);
    const hasFilters = search || clientFilter || severityFilter !== null || typeFilter !== null || reporterFilter || month !== null;
    const clearFilters = () => { setSearch(''); setClientFilter(null); setSeverityFilter(null); setTypeFilter(null); setReporterFilter(null); setMonth(null); };
    const onSite = (id: number | null) => { setSiteFilter(id); router.get('/emar/errors', id ? { site_id: id } : {}, { preserveState: true, preserveScroll: true }); };

    const TABS: RosterTabItem[] = [
        { id: 'all', label: 'All errors', icon: ListChecks, tone: 'primary', badge: filtered.length || undefined },
        { id: 'open', label: 'Open', icon: AlertTriangle, tone: 'warning', badge: byTab('open').length || undefined },
        { id: 'critical', label: 'Critical', icon: AlertTriangle, tone: 'critical', badge: byTab('critical').length || undefined },
        { id: 'nearmiss', label: 'Near misses', icon: CheckCircle2, tone: 'info', badge: byTab('nearmiss').length || undefined },
        { id: 'resolved', label: 'Resolved', icon: CheckCircle2, tone: 'success', badge: byTab('resolved').length || undefined },
    ];
    const heroStats: PageHeroStat[] = [
        { label: 'Open', value: stats.total_open, tone: stats.total_open > 0 ? 'warning' : 'neutral' },
        { label: 'Critical', value: stats.critical, tone: stats.critical > 0 ? 'critical' : 'neutral' },
        { label: 'Near miss · 30d', value: stats.near_miss },
        { label: 'Resolved · 30d', value: stats.resolved_this_month },
    ];

    const trendMax = Math.max(1, ...stats.trend.map((t) => t.count));
    const trendTotal = stats.trend.reduce((s, t) => s + t.count, 0);
    const trendNearMiss = stats.trend.reduce((s, t) => s + t.near_miss, 0);
    const topTypes = Object.entries(stats.by_type ?? {});
    const typeMax = Math.max(1, ...topTypes.map(([, n]) => n));
    const sevEntries = Object.entries(stats.by_severity ?? {});
    const sevTotal = Math.max(1, sevEntries.reduce((s, [, n]) => s + n, 0));

    return (
        <AppLayout breadcrumbs={[{ title: 'eMAR', href: '/emar' }, { title: 'Medication Errors', href: '/emar/errors' }]}>
            <Head title="eMAR - Medication Errors" />
            <div className="flex flex-col gap-6 p-6">
                <PageHero
                    variant="hero"
                    category="ops"
                    brandColour={brandColour}
                    icon={AlertTriangle}
                    title={
                        <span>
                            <span className="flex items-center gap-2 text-[10.5px] font-semibold uppercase tracking-wide text-primary-foreground/80">
                                <span aria-hidden className="relative inline-flex h-2 w-2">
                                    <span className="absolute inset-0 animate-ping rounded-full bg-status-success/70" />
                                    <span className="relative inline-flex h-2 w-2 rounded-full bg-status-success" />
                                </span>
                                Medication-safety register · live
                            </span>
                            <span className="mt-1 block text-[26px] font-bold leading-tight">
                                Medication errors for{' '}
                                <span className="border-b-2 border-primary-foreground/40">{activeSite?.name ?? 'your services'}</span>
                            </span>
                        </span>
                    }
                    description="Report, triage and resolve medication errors and near misses. A no-blame register — every report strengthens the system."
                    stats={heroStats}
                    actions={
                        <Button className="bg-primary-foreground text-primary hover:bg-primary-foreground/90" onClick={() => setModal({ type: 'report' })}>
                            <Plus className="h-4 w-4" />
                            Report an error
                        </Button>
                    }
                    footer={
                        <div className="flex flex-col gap-3 py-3 lg:flex-row lg:items-center lg:justify-between">
                            <div className="flex items-center gap-2">
                                <button onClick={() => setMonth((m) => (m ?? 0) - 1)} className="rounded-full border border-primary-foreground/20 bg-primary-foreground/10 p-1.5 text-primary-foreground hover:bg-primary-foreground/20"><ChevronLeft className="h-3.5 w-3.5" /></button>
                                <span className="rounded-full border border-primary-foreground/30 bg-primary-foreground/15 px-3 py-1 text-xs font-medium text-primary-foreground">{month === null ? 'All time' : monthLabel}</span>
                                <button onClick={() => setMonth((m) => (m ?? 0) + 1)} className="rounded-full border border-primary-foreground/20 bg-primary-foreground/10 p-1.5 text-primary-foreground hover:bg-primary-foreground/20"><ChevronRight className="h-3.5 w-3.5" /></button>
                                {month !== null && <button onClick={() => setMonth(null)} className="rounded-full bg-primary-foreground px-3 py-1 text-xs font-medium text-primary">All time</button>}
                            </div>
                            {sites.length > 0 && <EntityFilter label="Site" allLabel="All sites" items={sites} value={siteFilter} onChange={onSite} onDark />}
                        </div>
                    }
                />

                <div className="grid gap-4 lg:grid-cols-[1.5fr_1fr_1fr]">
                    <Card title="Reports · last 8 weeks">
                        <div className="flex h-20 items-end gap-1.5">
                            {stats.trend.map((t, i) => (
                                <div key={i} className="flex flex-1 flex-col items-center gap-1">
                                    <div className={`w-full rounded-t ${i === stats.trend.length - 1 ? 'bg-primary' : 'bg-primary/30'}`} style={{ height: `${Math.max(4, (t.count / trendMax) * 64)}px` }} title={`${t.week}: ${t.count}`} />
                                </div>
                            ))}
                        </div>
                        <div className="mt-2 text-xs text-muted-foreground">{trendTotal} total · {trendNearMiss} near miss</div>
                    </Card>
                    <Card title="Top error types">
                        {topTypes.length === 0 ? <div className="py-4 text-center text-xs text-muted-foreground">No data.</div> : (
                            <div className="flex flex-col gap-2">
                                {topTypes.map(([t, n]) => (
                                    <div key={t} className="flex items-center gap-2 text-xs">
                                        <span className="w-28 shrink-0 truncate">{typeLabel(t)}</span>
                                        <div className="h-2 flex-1 overflow-hidden rounded-full bg-muted"><div className="h-full rounded-full bg-primary" style={{ width: `${(n / typeMax) * 100}%` }} /></div>
                                        <span className="w-5 text-right tabular-nums text-muted-foreground">{n}</span>
                                    </div>
                                ))}
                            </div>
                        )}
                    </Card>
                    <Card title="By severity">
                        <div className="flex h-2.5 overflow-hidden rounded-full">
                            {sevEntries.map(([s, n]) => n > 0 && <div key={s} className={SEV_DOT[s]} style={{ width: `${(n / sevTotal) * 100}%` }} />)}
                        </div>
                        <div className="mt-2 flex flex-col gap-1">
                            {sevEntries.map(([s, n]) => (
                                <div key={s} className="flex items-center gap-2 text-xs"><span className={`h-2 w-2 rounded-full ${SEV_DOT[s]}`} /><span className="flex-1 capitalize text-muted-foreground">{s.replace('_', ' ')}</span><span className="tabular-nums">{n}</span></div>
                            ))}
                        </div>
                    </Card>
                </div>

                <TabStrip value={activeTab} onChange={setActiveTab} items={TABS} ariaLabel="Error views" />

                <div className="flex flex-wrap items-center gap-2">
                    <div className="flex items-center gap-2 rounded-full border bg-card px-3 py-1.5">
                        <Search className="h-3.5 w-3.5 text-muted-foreground" />
                        <input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search client, medication or ref…" className="w-48 bg-transparent text-sm outline-none placeholder:text-muted-foreground" />
                    </div>
                    <EntityFilter label="Client" allLabel="All clients" items={clients.map((c) => ({ id: c.id, name: `${c.first_name} ${c.last_name}` }))} value={clientFilter} onChange={setClientFilter} />
                    <EntityFilter label="Severity" allLabel="All severities" items={SEVERITIES.map((s, i) => ({ id: i, name: s.label }))} value={severityFilter} onChange={setSeverityFilter} />
                    <EntityFilter label="Type" allLabel="All types" items={ERROR_TYPES.map((t, i) => ({ id: i, name: t.label }))} value={typeFilter} onChange={setTypeFilter} />
                    <EntityFilter label="Reporter" allLabel="Any reporter" items={staff} value={reporterFilter} onChange={setReporterFilter} />
                    {hasFilters && <Button size="sm" variant="ghost" onClick={clearFilters}>Clear</Button>}
                </div>

                <div className="overflow-hidden rounded-2xl border bg-card shadow-sm">
                    {rows.length === 0 ? <div className="px-5 py-12 text-center text-sm text-muted-foreground">No errors match the current filters.</div> : (
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[940px] text-sm">
                                <thead>
                                    <tr className="bg-muted/50 text-left text-[11px] uppercase tracking-wide text-muted-foreground">
                                        <th className="px-4 py-2.5">Date</th><th className="px-4 py-2.5">Client</th><th className="px-4 py-2.5">Medication</th><th className="px-4 py-2.5">Type</th><th className="px-4 py-2.5">Severity</th><th className="px-4 py-2.5">Reported by</th><th className="px-4 py-2.5">Status</th><th className="px-4 py-2.5 text-right">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {rows.map((e) => {
                                        const sev = severityMeta(e.severity); const st = statusMeta(e.status);
                                        return (
                                            <tr key={e.id} className="cursor-pointer border-b last:border-b-0 hover:bg-muted/30" onClick={() => setModal({ type: 'triage', error: e })}>
                                                <td className="px-4 py-3"><div>{fmtDate(e.reported_at)}</div><div className="font-mono text-[10px] text-muted-foreground">{e.ref}</div></td>
                                                <td className="px-4 py-3"><div className="flex items-center gap-2"><span className="flex h-7 w-7 items-center justify-center rounded-full bg-primary/10 text-[10px] font-bold text-primary">{initials(e.client ? `${e.client.first_name} ${e.client.last_name}` : '?')}</span><div><div className="font-medium">{e.client ? `${e.client.first_name} ${e.client.last_name}` : 'Unknown'}</div><div className="text-xs text-muted-foreground">{e.site_name ?? ''}</div></div></div></td>
                                                <td className="px-4 py-3 text-muted-foreground">{e.medication?.name ?? '—'}</td>
                                                <td className="px-4 py-3"><span className="rounded-full border px-2 py-0.5 text-xs text-muted-foreground">{typeLabel(e.error_type)}</span></td>
                                                <td className="px-4 py-3"><span className={`rounded-full px-2 py-0.5 text-[11px] font-semibold ${sev.cls}`}>{sev.label}</span></td>
                                                <td className="px-4 py-3 text-muted-foreground">{e.reported_by_user?.name ?? '—'}</td>
                                                <td className="px-4 py-3"><div className="flex items-center gap-1.5"><span className={`rounded-full px-2 py-0.5 text-[11px] font-semibold ${st.cls}`}>{st.label}</span>{e.attachments.length > 0 && <span className="flex items-center gap-0.5 text-[10px] text-muted-foreground"><Paperclip className="h-3 w-3" />{e.attachments.length}</span>}{e.incident && <Link2 className="h-3 w-3 text-status-critical" />}</div></td>
                                                <td className="px-4 py-3" onClick={(ev) => ev.stopPropagation()}>
                                                    <div className="flex items-center justify-end gap-1">
                                                        <Button size="sm" variant="ghost" onClick={() => setModal({ type: 'triage', error: e })}>View</Button>
                                                        {e.status === 'reported' && <Button size="sm" variant="outline" onClick={() => setModal({ type: 'review', error: e })}>Review</Button>}
                                                        {(e.status === 'reported' || e.status === 'investigating') && <Button size="sm" onClick={() => setModal({ type: 'resolve', error: e })}>Resolve</Button>}
                                                        {e.status === 'resolved' && <Button size="sm" onClick={() => setModal({ type: 'close', error: e })}>Close out</Button>}
                                                    </div>
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                    )}
                </div>
            </div>

            <ReportErrorModal open={modal?.type === 'report'} onClose={() => setModal(null)} clients={clients.map((c) => ({ id: c.id, name: `${c.first_name} ${c.last_name}`, site: null }))} />
            {modal?.type === 'triage' && <TriageDialog error={modal.error} onDismiss={() => setModal(null)} onAction={(a: TriageAction) => setModal({ type: a, error: modal.error })} />}
            {modal?.type === 'review' && <ReviewErrorDialog error={modal.error} onClose={() => setModal(null)} />}
            {modal?.type === 'resolve' && <ResolveErrorDialog error={modal.error} onClose={() => setModal(null)} />}
            {modal?.type === 'close' && <CloseErrorDialog error={modal.error} onClose={() => setModal(null)} />}
        </AppLayout>
    );
}

function Card({ title, children }: { title: string; children: React.ReactNode }) {
    return (
        <div className="rounded-2xl border bg-card p-4 shadow-sm">
            <div className="mb-3 text-sm font-semibold">{title}</div>
            {children}
        </div>
    );
}
