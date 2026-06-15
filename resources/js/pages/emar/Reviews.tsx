/* eslint-disable no-restricted-syntax -- review tables, KPI cards, kanban cards and the cycle
   stepper are custom-layout bordered surfaces / chip buttons (not Card/Button); colours are tokens. */
import { PageHero, type PageHeroStat } from '@/components/page';
import { EntityFilter, ShiftContextMenu, TabStrip, type RosterTabItem, type ShiftCtxItem, type ShiftCtxState } from '@/components/rostering';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import {
    actionTone,
    ConductReviewDialog,
    RescheduleReviewDialog,
    ReviewDetailDialog,
    ScheduleReviewDialog,
    REVIEW_TYPES,
    type ClientOpt,
    type ReviewRow,
    type StaffOpt,
} from '@/pages/emar/_review-dialogs';
import { Head, router } from '@inertiajs/react';
import { AlertTriangle, ArrowRight, Calendar, CheckCircle, ClipboardCheck, Eye, FileText, LayoutGrid, List, Pill, Plus, RefreshCw, Search, Stethoscope, User, X } from 'lucide-react';
import { useMemo, useState, type MouseEvent as ReactMouseEvent } from 'react';

type Pipeline = { review_id: number; index: number; drug: string; action: string; rationale: string | null; gp_status: string; stage: string; client_name: string; reviewer_name: string | null };
type Kpis = { overdue: number; due_30: number; completed_quarter: number; gp_acceptance: number | null; in_monitoring: number; awaiting_gp: number };

type Props = {
    reviews: ReviewRow[];
    deprescribing: Pipeline[];
    kpis: Kpis;
    clients: ClientOpt[];
    staff: StaffOpt[];
    sites: { id: number; name: string }[];
    active_site: { id: number; name: string } | null;
    site_brand_colour: string | null;
};

type Modal =
    | { type: 'schedule'; clientId?: number }
    | { type: 'conduct'; review: ReviewRow }
    | { type: 'detail'; review: ReviewRow }
    | { type: 'reschedule'; review: ReviewRow }
    | null;

const CYCLES = ['Jan–Mar', 'Apr–Jun', 'Jul–Sep', 'Oct–Dec'];
const STAGES: { id: string; label: string; next: string | null; tone: string }[] = [
    { id: 'gp', label: 'Awaiting GP', next: 'Mark accepted', tone: 'text-status-warning' },
    { id: 'implemented', label: 'Implemented', next: 'Start monitoring', tone: 'text-primary' },
    { id: 'monitor', label: 'Monitoring', next: 'Close', tone: 'text-status-info' },
    { id: 'done', label: 'Closed', next: null, tone: 'text-status-success' },
];
const initials = (n: string) => n.split(' ').filter(Boolean).slice(0, 2).map((p) => p[0]).join('').toUpperCase() || '?';
const fmtDate = (iso: string | null) => (iso ? new Date(iso).toLocaleDateString('en-NZ', { day: 'numeric', month: 'short', year: 'numeric' }) : '—');
const quarterOf = (iso: string | null) => (iso ? Math.floor(new Date(iso).getMonth() / 3) : -1);
const typeLabel = (t: string | null) => REVIEW_TYPES.find((x) => x.value === t)?.label ?? t ?? '—';

function dueBadge(r: ReviewRow): { label: string; cls: string } | null {
    if (r.status !== 'scheduled' || !r.scheduled_date) return null;
    const days = Math.round((new Date(r.scheduled_date).getTime() - Date.now()) / 86400000);
    if (days < 0) return { label: `${-days}d overdue`, cls: 'bg-status-critical-bg text-status-critical' };
    if (days === 0) return { label: 'Due today', cls: 'bg-status-warning-bg text-status-warning' };
    return { label: `Due in ${days}d`, cls: 'bg-status-info-bg text-status-info' };
}
function statusPill(s: string): { label: string; cls: string } {
    return s === 'completed'
        ? { label: 'Completed', cls: 'bg-status-success-bg text-status-success' }
        : s === 'cancelled'
          ? { label: 'Cancelled', cls: 'bg-muted text-muted-foreground' }
          : { label: 'Scheduled', cls: 'bg-status-info-bg text-status-info' };
}

export default function Reviews({ reviews, deprescribing, kpis, clients, staff, sites, active_site: activeSite, site_brand_colour: brandColour }: Props) {
    const [activeTab, setActiveTab] = useState('overview');
    const [search, setSearch] = useState('');
    const [siteFilter, setSiteFilter] = useState<number | null>(activeSite?.id ?? null);
    const [reviewerFilter, setReviewerFilter] = useState<number | null>(null);
    const [clientFilter, setClientFilter] = useState<number | null>(null);
    const [cycle, setCycle] = useState<number | null>(null);
    const [modal, setModal] = useState<Modal>(null);
    const [ctx, setCtx] = useState<ShiftCtxState | null>(null);
    const [dismissed, setDismissed] = useState<Set<string>>(() => new Set());

    const visible = useMemo(() => {
        const q = search.trim().toLowerCase();
        return reviews.filter((r) => {
            if (reviewerFilter && r.reviewer_user_id !== reviewerFilter) return false;
            if (clientFilter && r.client_id !== clientFilter) return false;
            if (cycle !== null && quarterOf(r.scheduled_date) !== cycle) return false;
            if (q && !`${r.client_name} ${r.reviewer_name ?? ''} ${(r.actions ?? []).map((a) => a.drug).join(' ')}`.toLowerCase().includes(q)) return false;
            return true;
        });
    }, [reviews, search, reviewerFilter, clientFilter, cycle]);

    const clientItems = useMemo(() => clients.map((c) => ({ id: c.id, name: `${c.first_name} ${c.last_name}`.trim() })), [clients]);

    const dueList = visible.filter((r) => r.status === 'scheduled' && (r.is_overdue || (r.scheduled_date && new Date(r.scheduled_date).getTime() - Date.now() <= 30 * 86400000)));
    const scheduledList = visible.filter((r) => r.status === 'scheduled');
    const completedList = visible.filter((r) => r.status === 'completed');
    const upcoming = scheduledList.filter((r) => !r.is_overdue).sort((a, b) => (a.scheduled_date ?? '').localeCompare(b.scheduled_date ?? '')).slice(0, 8);
    const recentlyCompleted = completedList.slice().sort((a, b) => (b.completed_date ?? '').localeCompare(a.completed_date ?? '')).slice(0, 8);

    const onSite = (id: number | null) => { setSiteFilter(id); router.get('/emar/reviews', id ? { site_id: id } : {}, { preserveState: true, preserveScroll: true }); };
    const advanceRec = (p: Pipeline) => router.post(`/emar/reviews/${p.review_id}/actions/advance`, { index: p.index }, { preserveScroll: true, only: ['reviews', 'deprescribing', 'kpis'] });
    const reviewById = (id: number) => reviews.find((r) => r.id === id);

    // Off-page jumps reused by both the context menu and the detail modal footer.
    const viewClient = (id: number | null) => id != null && router.visit(`/operations/clients/${id}/care`);
    const openMar = (url: string | null | undefined) => url && router.visit(url);

    // Right-click menu header tag (status-coloured pill) for a review row.
    const ctxTag = (r: ReviewRow): { tag: string; bg: string; color: string } => {
        if (r.status === 'completed') return { tag: 'Completed', bg: 'var(--status-success-bg)', color: 'var(--status-success)' };
        if (r.status === 'cancelled') return { tag: 'Cancelled', bg: 'var(--muted)', color: 'var(--muted-foreground)' };
        if (r.is_overdue) return { tag: 'Overdue', bg: 'var(--status-critical-bg)', color: 'var(--status-critical)' };
        const days = r.scheduled_date ? Math.round((new Date(r.scheduled_date).getTime() - Date.now()) / 86400000) : null;
        if (days === 0) return { tag: 'Due today', bg: 'var(--status-warning-bg)', color: 'var(--status-warning)' };
        return { tag: 'Scheduled', bg: 'var(--status-info-bg)', color: 'var(--status-info)' };
    };
    const actionCtxTag = (a: string): { bg: string; color: string } =>
        a === 'Stop' ? { bg: 'var(--status-critical-bg)', color: 'var(--status-critical)' }
            : a === 'Reduce' ? { bg: 'var(--status-warning-bg)', color: 'var(--status-warning)' }
            : a === 'Monitor' ? { bg: 'var(--status-info-bg)', color: 'var(--status-info)' }
            : a === 'Switch' ? { bg: 'var(--accent)', color: 'var(--primary)' }
            : { bg: 'var(--muted)', color: 'var(--muted-foreground)' };

    // Context menu for a review row — mirrors PRN's openRowCtx (View → Conduct →
    // Reschedule → sep → View client → MAR). Scheduled-only actions are hidden
    // on completed/cancelled rows so the menu never offers a dead action.
    const openRowCtx = (e: ReactMouseEvent, r: ReviewRow) => {
        e.preventDefault();
        e.stopPropagation();
        const t = ctxTag(r);
        const scheduled = r.status === 'scheduled';
        const items: ShiftCtxItem[] = [
            { icon: <Eye className="h-3.5 w-3.5" />, label: 'View detail', sub: `${typeLabel(r.review_type)}${r.scheduled_date ? ` · ${fmtDate(r.scheduled_date)}` : ''}`, tone: 'primary', onClick: () => setModal({ type: 'detail', review: r }) },
            ...(scheduled ? [{ icon: <Stethoscope className="h-3.5 w-3.5" />, label: 'Conduct review', sub: 'Findings & sign-off', onClick: () => setModal({ type: 'conduct', review: r }) } satisfies ShiftCtxItem] : []),
            ...(scheduled ? [{ icon: <RefreshCw className="h-3.5 w-3.5" />, label: 'Reschedule', onClick: () => setModal({ type: 'reschedule', review: r }) } satisfies ShiftCtxItem] : []),
            { sep: true },
            ...(r.client_id != null ? [{ icon: <User className="h-3.5 w-3.5" />, label: 'View client', onClick: () => viewClient(r.client_id) } satisfies ShiftCtxItem] : []),
            ...(r.mar_url ? [{ icon: <FileText className="h-3.5 w-3.5" />, label: 'Open on MAR chart', onClick: () => openMar(r.mar_url) } satisfies ShiftCtxItem] : []),
        ];
        setCtx({ x: e.clientX, y: e.clientY, tag: t.tag, tagBg: t.bg, tagColor: t.color, meta: `${r.client_name} · ${typeLabel(r.review_type)} · ${fmtDate(r.scheduled_date)}`, items });
    };

    // Context menu for a deprescribing kanban card — adds the stage-advance action.
    const openCardCtx = (e: ReactMouseEvent, p: Pipeline) => {
        e.preventDefault();
        e.stopPropagation();
        const r = reviewById(p.review_id);
        const t = actionCtxTag(p.action);
        const stage = STAGES.find((s) => s.id === p.stage);
        const items: ShiftCtxItem[] = [
            ...(r ? [{ icon: <Eye className="h-3.5 w-3.5" />, label: 'View detail', sub: p.drug, tone: 'primary', onClick: () => setModal({ type: 'detail', review: r }) } satisfies ShiftCtxItem] : []),
            { sep: true },
            ...(r?.client_id != null ? [{ icon: <User className="h-3.5 w-3.5" />, label: 'View client', onClick: () => viewClient(r!.client_id) } satisfies ShiftCtxItem] : []),
            ...(r?.mar_url ? [{ icon: <FileText className="h-3.5 w-3.5" />, label: 'Open on MAR chart', onClick: () => openMar(r!.mar_url) } satisfies ShiftCtxItem] : []),
            ...(stage?.next ? [{ sep: true } satisfies ShiftCtxItem, { icon: <ArrowRight className="h-3.5 w-3.5" />, label: stage.next, sub: 'Advance deprescribing', onClick: () => advanceRec(p) } satisfies ShiftCtxItem] : []),
        ];
        setCtx({ x: e.clientX, y: e.clientY, tag: p.action, tagBg: t.bg, tagColor: t.color, meta: `${p.drug} · ${p.client_name}`, items });
    };

    // Stacked, dismissible alert strip (mirrors /emar/controlled) built from the
    // already-computed KPIs — each jumps to the tab that resolves it.
    const alerts = ([
        kpis.overdue > 0 ? { id: 'overdue', tone: 'critical' as const, icon: AlertTriangle, msg: `${kpis.overdue} review${kpis.overdue === 1 ? '' : 's'} overdue against the 3-monthly chart cycle.`, tab: 'due' } : null,
        kpis.due_30 > 0 ? { id: 'due_30', tone: 'warning' as const, icon: Calendar, msg: `${kpis.due_30} review${kpis.due_30 === 1 ? '' : 's'} due within the next 30 days.`, tab: 'scheduled' } : null,
        kpis.awaiting_gp > 0 ? { id: 'awaiting_gp', tone: 'info' as const, icon: Pill, msg: `${kpis.awaiting_gp} deprescribing action${kpis.awaiting_gp === 1 ? '' : 's'} awaiting GP sign-off.`, tab: 'deprescribing' } : null,
    ].filter(Boolean) as { id: string; tone: 'critical' | 'warning' | 'info'; icon: typeof Pill; msg: string; tab: string }[]).filter((a) => !dismissed.has(a.id));
    const dismissAlert = (id: string) => setDismissed((prev) => new Set(prev).add(id));

    const TABS: RosterTabItem[] = [
        { id: 'overview', label: 'Overview', icon: LayoutGrid, tone: 'primary' },
        { id: 'due', label: 'Due & overdue', icon: AlertTriangle, tone: 'critical', badge: dueList.length || undefined },
        { id: 'scheduled', label: 'Scheduled', icon: Calendar, tone: 'info', badge: scheduledList.length || undefined },
        { id: 'completed', label: 'Completed', icon: CheckCircle, tone: 'success', badge: completedList.length || undefined },
        { id: 'deprescribing', label: 'Deprescribing', icon: Pill, tone: 'info', badge: deprescribing.length || undefined },
        { id: 'all', label: 'All', icon: List, tone: 'primary', badge: reviews.length || undefined },
    ];

    const heroStats: PageHeroStat[] = [
        { label: 'Overdue', value: kpis.overdue, tone: kpis.overdue > 0 ? 'critical' : 'neutral' },
        { label: 'Due 30d', value: kpis.due_30, tone: kpis.due_30 > 0 ? 'warning' : 'neutral' },
        { label: 'Completed (Q)', value: kpis.completed_quarter },
        { label: 'GP accept %', value: kpis.gp_acceptance === null ? '—' : `${kpis.gp_acceptance}%` },
    ];
    const description = `${kpis.overdue + kpis.due_30} review${kpis.overdue + kpis.due_30 === 1 ? '' : 's'} need attention — ${kpis.overdue} overdue against the 3-monthly chart cycle. Pharmacist-led, GP-signed, whānau-informed.`;

    return (
        <AppLayout breadcrumbs={[{ title: 'eMAR', href: '/emar' }, { title: 'Medication Reviews', href: '/emar/reviews' }]}>
            <Head title="eMAR - Medication Reviews" />
            <div className="flex flex-col gap-6 p-6">
                <PageHero
                    variant="hero"
                    category="ops"
                    brandColour={brandColour}
                    icon={ClipboardCheck}
                    title={
                        <span>
                            <span className="flex items-center gap-2 text-[10.5px] font-semibold uppercase tracking-wide text-primary-foreground/80">
                                <span aria-hidden className="relative inline-flex h-2 w-2">
                                    <span className="absolute inset-0 animate-ping rounded-full bg-status-success/70" />
                                    <span className="relative inline-flex h-2 w-2 rounded-full bg-status-success" />
                                </span>
                                Medication governance · live
                            </span>
                            <span className="mt-1 block text-[26px] font-bold leading-tight">
                                Medication reviews for{' '}
                                <span className="border-b-2 border-primary-foreground/40">{activeSite?.name ?? 'your services'}</span>
                            </span>
                        </span>
                    }
                    description={description}
                    stats={heroStats}
                    actions={
                        <Button className="bg-primary-foreground text-primary hover:bg-primary-foreground/90" onClick={() => setModal({ type: 'schedule' })}>
                            <Plus className="h-4 w-4" />
                            Schedule review
                        </Button>
                    }
                    footer={
                        <div className="flex flex-col gap-3 py-3 lg:flex-row lg:items-center lg:justify-between">
                            <div className="flex flex-wrap items-center gap-1.5">
                                <button onClick={() => setCycle(null)} className={`rounded-full px-3 py-1 text-xs font-medium transition ${cycle === null ? 'bg-primary-foreground text-primary' : 'border border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground hover:bg-primary-foreground/20'}`}>All year</button>
                                {CYCLES.map((c, i) => (
                                    <button key={c} onClick={() => setCycle(i)} className={`rounded-full px-3 py-1 text-xs font-medium transition ${cycle === i ? 'bg-primary-foreground text-primary' : 'border border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground hover:bg-primary-foreground/20'}`}>{c}</button>
                                ))}
                            </div>
                            <div className="flex flex-wrap items-center gap-2">
                                <div className="flex items-center gap-2 rounded-full bg-primary-foreground px-3 py-1.5">
                                    <Search className="h-3.5 w-3.5 text-muted-foreground" />
                                    <input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search resident, reviewer or drug…" className="w-52 bg-transparent text-sm text-foreground outline-none placeholder:text-muted-foreground" />
                                </div>
                                {sites.length > 0 && <EntityFilter label="Site" allLabel="All sites" items={sites} value={siteFilter} onChange={onSite} onDark />}
                                <EntityFilter label="Reviewer" allLabel="All reviewers" items={staff} value={reviewerFilter} onChange={setReviewerFilter} onDark />
                                <EntityFilter label="Client" allLabel="All clients" items={clientItems} value={clientFilter} onChange={setClientFilter} onDark />
                            </div>
                        </div>
                    }
                />

                {alerts.length > 0 && (
                    <div className="flex flex-col gap-2">
                        {alerts.map((a) => {
                            const tone = {
                                critical: 'border-status-critical/30 bg-status-critical-bg/60 text-status-critical',
                                warning: 'border-status-warning/30 bg-status-warning-bg/60 text-status-warning',
                                info: 'border-status-info/30 bg-status-info-bg/60 text-status-info',
                            }[a.tone];
                            const Icon = a.icon;
                            return (
                                <div key={a.id} className={`flex items-center justify-between gap-3 rounded-xl border px-4 py-3 ${tone}`}>
                                    <span className="flex items-center gap-2 text-sm font-medium">
                                        <Icon className="h-4 w-4" />
                                        {a.msg}
                                    </span>
                                    <div className="flex items-center gap-1.5">
                                        <Button size="sm" variant="outline" onClick={() => setActiveTab(a.tab)}>Review</Button>
                                        <button type="button" aria-label="Dismiss alert" onClick={() => dismissAlert(a.id)} className="grid h-7 w-7 place-items-center rounded-md text-current/70 transition-colors hover:bg-current/10">
                                            <X className="h-4 w-4" />
                                        </button>
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                )}

                <TabStrip value={activeTab} onChange={setActiveTab} items={TABS} ariaLabel="Review views" />

                {activeTab === 'overview' && (
                    <div className="flex flex-col gap-4">
                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            <KpiCard icon={AlertTriangle} label="Overdue reviews" value={kpis.overdue} tone="critical" />
                            <KpiCard icon={Calendar} label="Upcoming · 30 days" value={kpis.due_30} tone="warning" />
                            <KpiCard icon={CheckCircle} label="Completed this quarter" value={kpis.completed_quarter} tone="success" />
                            <KpiCard icon={Pill} label="Live deprescribing actions" value={deprescribing.filter((d) => d.stage !== 'done').length} tone="info" />
                        </div>
                        <div className="grid gap-4 lg:grid-cols-[1.5fr_1fr]">
                            <div className="overflow-hidden rounded-2xl border border-status-critical/30 bg-card shadow-sm">
                                <div className="border-b bg-status-critical-bg/40 px-4 py-3 text-sm font-semibold text-status-critical">Overdue &amp; due now</div>
                                {dueList.length === 0 ? <div className="px-4 py-10 text-center text-sm text-muted-foreground">Nothing due right now.</div> : (
                                    <div className="flex flex-col">
                                        {dueList.slice(0, 8).map((r) => {
                                            const due = dueBadge(r);
                                            return (
                                                <div key={r.id} role="button" tabIndex={0} onClick={() => setModal({ type: 'detail', review: r })} onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); setModal({ type: 'detail', review: r }); } }} onContextMenu={(e) => openRowCtx(e, r)} className="flex cursor-pointer items-center justify-between gap-3 border-b px-4 py-3 last:border-b-0 hover:bg-muted/40 focus:bg-muted/40 focus:outline-none">
                                                    <div className="flex items-center gap-3">
                                                        <span className="flex h-9 w-9 items-center justify-center rounded-full bg-primary/10 text-xs font-bold text-primary">{initials(r.client_name)}</span>
                                                        <div>
                                                            <div className="text-sm font-medium">{r.client_name}</div>
                                                            <div className="text-xs text-muted-foreground">{typeLabel(r.review_type)}{r.trigger_reason ? ` · ${r.trigger_reason}` : ''}</div>
                                                        </div>
                                                    </div>
                                                    <div className="flex items-center gap-2">
                                                        {due && <span className={`rounded-full px-2 py-0.5 text-[11px] font-semibold ${due.cls}`}>{due.label}</span>}
                                                        <Button size="sm" onClick={(e) => { e.stopPropagation(); setModal({ type: 'conduct', review: r }); }}>Conduct</Button>
                                                    </div>
                                                </div>
                                            );
                                        })}
                                    </div>
                                )}
                                <button onClick={() => setActiveTab('deprescribing')} className="flex w-full items-center justify-between border-t px-4 py-3 text-left text-xs text-muted-foreground hover:bg-muted/40">
                                    <span className="font-medium text-foreground">Deprescribing pipeline</span>
                                    <span>Awaiting GP {kpis.awaiting_gp} · Monitoring {kpis.in_monitoring} <ArrowRight className="ml-1 inline h-3 w-3" /></span>
                                </button>
                            </div>
                            <div className="flex flex-col gap-4">
                                <OverviewList title="Upcoming · next 30 days" rows={upcoming} empty="No upcoming reviews." onOpen={(r) => setModal({ type: 'detail', review: r })} onCtx={openRowCtx} />
                                <OverviewList title="Recently completed" rows={recentlyCompleted} empty="No completed reviews yet." onOpen={(r) => setModal({ type: 'detail', review: r })} onCtx={openRowCtx} completed />
                            </div>
                        </div>
                    </div>
                )}

                {['due', 'scheduled', 'completed', 'all'].includes(activeTab) && (
                    <ReviewTable
                        rows={activeTab === 'due' ? dueList : activeTab === 'scheduled' ? scheduledList : activeTab === 'completed' ? completedList : visible}
                        onConduct={(r) => setModal({ type: 'conduct', review: r })}
                        onView={(r) => setModal({ type: 'detail', review: r })}
                        onReschedule={(r) => setModal({ type: 'reschedule', review: r })}
                        onSchedule={() => setModal({ type: 'schedule' })}
                        onCtx={openRowCtx}
                    />
                )}

                {activeTab === 'deprescribing' && (
                    <div className="flex flex-col gap-4">
                        <div className="rounded-xl border border-status-info/30 bg-status-info-bg/50 px-4 py-3 text-sm text-status-info">
                            Deprescribing pipeline — pharmacist recommends → GP accepts → implemented → monitored (HQSC frailty guides). Surfaces the recommendations recorded on completed reviews.
                        </div>
                        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                            {STAGES.map((col) => {
                                const cards = deprescribing.filter((d) => d.stage === col.id);
                                return (
                                    <div key={col.id} className="flex flex-col gap-3 rounded-2xl border bg-muted/30 p-3">
                                        <div className={`flex items-center justify-between text-sm font-semibold ${col.tone}`}>
                                            <span className="flex items-center gap-2"><span className="h-2 w-2 rounded-full bg-current" />{col.label}</span>
                                            <span className="tabular-nums">{cards.length}</span>
                                        </div>
                                        {cards.length === 0 && <div className="rounded-lg border border-dashed bg-card px-3 py-6 text-center text-xs text-muted-foreground">Empty</div>}
                                        {cards.map((p) => (
                                            <div key={`${p.review_id}-${p.index}`} onContextMenu={(e) => openCardCtx(e, p)} className="rounded-lg border bg-card p-3 shadow-sm">
                                                <div className="flex items-center justify-between gap-2">
                                                    <span className={`rounded-full px-2 py-0.5 text-[10px] font-semibold ${actionTone(p.action)}`}>{p.action}</span>
                                                    <span className="text-[10px] capitalize text-muted-foreground">{p.gp_status}</span>
                                                </div>
                                                <div className="mt-1.5 text-sm font-medium">{p.drug}</div>
                                                <div className="text-xs text-muted-foreground">{p.client_name}{p.reviewer_name ? ` · ${p.reviewer_name}` : ''}</div>
                                                {p.rationale && <div className="mt-1 text-xs text-muted-foreground">{p.rationale}</div>}
                                                <div className="mt-2 flex items-center justify-between">
                                                    <button onClick={() => { const r = reviewById(p.review_id); if (r) setModal({ type: 'detail', review: r }); }} className="text-[11px] text-primary underline">Open review</button>
                                                    {col.next && <Button size="sm" variant="outline" onClick={() => advanceRec(p)}>{col.next}</Button>}
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                );
                            })}
                        </div>
                    </div>
                )}
            </div>

            {modal?.type === 'schedule' && <ScheduleReviewDialog clients={clients} staff={staff} defaultClientId={modal.clientId} onClose={() => setModal(null)} />}
            {modal?.type === 'conduct' && <ConductReviewDialog review={modal.review} onClose={() => setModal(null)} />}
            {modal?.type === 'detail' && <ReviewDetailDialog review={modal.review} onClose={() => setModal(null)} onConduct={() => setModal({ type: 'conduct', review: modal.review })} onReschedule={() => setModal({ type: 'reschedule', review: modal.review })} />}
            {modal?.type === 'reschedule' && <RescheduleReviewDialog review={modal.review} onClose={() => setModal(null)} />}

            {ctx && <ShiftContextMenu ctx={ctx} onClose={() => setCtx(null)} />}
        </AppLayout>
    );
}

function KpiCard({ icon: Icon, label, value, tone }: { icon: typeof Pill; label: string; value: number | string; tone: 'critical' | 'warning' | 'success' | 'info' }) {
    const cls = { critical: 'bg-status-critical-bg text-status-critical', warning: 'bg-status-warning-bg text-status-warning', success: 'bg-status-success-bg text-status-success', info: 'bg-status-info-bg text-status-info' }[tone];
    return (
        <div className="flex items-center gap-3 rounded-2xl border bg-card p-4 shadow-sm">
            <span className={`flex h-10 w-10 items-center justify-center rounded-xl ${cls}`}><Icon className="h-5 w-5" /></span>
            <div>
                <div className="text-2xl font-bold tabular-nums">{value}</div>
                <div className="text-xs text-muted-foreground">{label}</div>
            </div>
        </div>
    );
}

function OverviewList({ title, rows, empty, onOpen, onCtx, completed }: { title: string; rows: ReviewRow[]; empty: string; onOpen: (r: ReviewRow) => void; onCtx: (e: ReactMouseEvent, r: ReviewRow) => void; completed?: boolean }) {
    return (
        <div className="overflow-hidden rounded-2xl border bg-card shadow-sm">
            <div className="border-b bg-muted/40 px-4 py-3 text-sm font-semibold">{title}</div>
            {rows.length === 0 ? <div className="px-4 py-8 text-center text-sm text-muted-foreground">{empty}</div> : (
                <div className="flex flex-col">
                    {rows.map((r) => (
                        <button key={r.id} onClick={() => onOpen(r)} onContextMenu={(e) => onCtx(e, r)} className="flex items-center justify-between gap-3 border-b px-4 py-2.5 text-left last:border-b-0 hover:bg-muted/40">
                            <div className="flex items-center gap-2">
                                <span className="flex h-7 w-7 items-center justify-center rounded-full bg-primary/10 text-[10px] font-bold text-primary">{initials(r.client_name)}</span>
                                <div>
                                    <div className="text-sm font-medium">{r.client_name}</div>
                                    <div className="text-xs text-muted-foreground">{completed ? fmtDate(r.completed_date) : fmtDate(r.scheduled_date)}</div>
                                </div>
                            </div>
                            {completed && (r.actions?.length ?? 0) > 0 && <span className="rounded-full bg-accent px-2 py-0.5 text-[10px] font-semibold text-primary">{r.actions.length} recs</span>}
                        </button>
                    ))}
                </div>
            )}
        </div>
    );
}

function ReviewTable({ rows, onConduct, onView, onReschedule, onSchedule, onCtx }: { rows: ReviewRow[]; onConduct: (r: ReviewRow) => void; onView: (r: ReviewRow) => void; onReschedule: (r: ReviewRow) => void; onSchedule: () => void; onCtx: (e: ReactMouseEvent, r: ReviewRow) => void }) {
    return (
        <div className="overflow-hidden rounded-2xl border bg-card shadow-sm">
            <div className="flex items-center justify-between border-b bg-muted/40 px-4 py-3">
                <span className="text-sm font-semibold">{rows.length} review{rows.length === 1 ? '' : 's'}</span>
                <Button size="sm" onClick={onSchedule}><Plus className="h-3.5 w-3.5" />Schedule</Button>
            </div>
            {rows.length === 0 ? <div className="px-5 py-12 text-center text-sm text-muted-foreground">No reviews match the current filters.</div> : (
                <div className="overflow-x-auto">
                    <table className="w-full min-w-[820px] text-sm">
                        <thead>
                            <tr className="bg-muted/50 text-left text-[11px] uppercase tracking-wide text-muted-foreground">
                                <th className="px-4 py-2.5">Resident</th>
                                <th className="px-4 py-2.5">Type</th>
                                <th className="px-4 py-2.5">Scheduled</th>
                                <th className="px-4 py-2.5">Reviewer</th>
                                <th className="px-4 py-2.5">Status</th>
                                <th className="px-4 py-2.5">Next</th>
                                <th className="px-4 py-2.5 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((r) => {
                                const due = dueBadge(r);
                                const sp = statusPill(r.status);
                                return (
                                    <tr key={r.id} role="button" tabIndex={0} onClick={() => onView(r)} onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); onView(r); } }} onContextMenu={(e) => onCtx(e, r)} className="cursor-pointer border-b last:border-b-0 hover:bg-muted/40 focus:bg-muted/40 focus:outline-none">
                                        <td className="px-4 py-3">
                                            <div className="flex items-center gap-2">
                                                <span className="flex h-7 w-7 items-center justify-center rounded-full bg-primary/10 text-[10px] font-bold text-primary">{initials(r.client_name)}</span>
                                                <span className="font-medium">{r.client_name}</span>
                                            </div>
                                        </td>
                                        <td className="px-4 py-3"><span className="rounded-full border px-2 py-0.5 text-xs text-muted-foreground">{typeLabel(r.review_type)}</span></td>
                                        <td className="px-4 py-3">{fmtDate(r.scheduled_date)}{due && <span className={`ml-2 rounded-full px-2 py-0.5 text-[10px] font-semibold ${due.cls}`}>{due.label}</span>}</td>
                                        <td className="px-4 py-3 text-muted-foreground">{r.reviewer_name ?? '—'}</td>
                                        <td className="px-4 py-3"><span className={`rounded-full px-2 py-0.5 text-[11px] font-semibold ${sp.cls}`}>{sp.label}</span></td>
                                        <td className="px-4 py-3 text-muted-foreground">{fmtDate(r.next_review_date)}</td>
                                        <td className="px-4 py-3">
                                            <div className="flex items-center justify-end gap-1">
                                                {r.status === 'completed' ? <Button size="sm" variant="outline" onClick={(e) => { e.stopPropagation(); onView(r); }}>View</Button> : <Button size="sm" onClick={(e) => { e.stopPropagation(); onConduct(r); }}>Conduct</Button>}
                                                {r.status === 'scheduled' && <Button size="sm" variant="ghost" onClick={(e) => { e.stopPropagation(); onReschedule(r); }} title="Reschedule"><RefreshCw className="h-3.5 w-3.5" /></Button>}
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
    );
}
