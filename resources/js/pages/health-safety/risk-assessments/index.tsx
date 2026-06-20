/* eslint-disable no-restricted-syntax -- Register chrome: the RA lifecycle ribbon, the
 * on-dark "Due for review" toggle and the hero search input are bespoke hero affordances
 * (styled native controls on the primary gradient) using semantic design tokens only,
 * matching the Incidents / Analytics registers. */
import { ShiftContextMenu, type ShiftCtxState, EntityFilter, TabStrip, type RosterTabItem } from '@/components/rostering';
import { RaDetailDialog } from '@/components/health-safety/risk-assessments/ra-detail-dialog';
import { RIBBON_STAGES } from '@/components/health-safety/risk-assessments/ra-kit';
import { buildRaCtxItems, RaTable, type RaCtxHandlers } from '@/components/health-safety/risk-assessments/ra-table';
import { RaWizardDialog } from '@/components/health-safety/risk-assessments/ra-wizard-dialog';
import type { AttachType, RaDetail, RaModalKind, RaPickers, RaRow } from '@/components/health-safety/risk-assessments/types';
import { Button } from '@/components/ui/button';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import {
    HeroCluster,
    HeroClusterTile,
    HeroComplianceBadges,
    type HeroComplianceBadge,
    HeroMedallion,
    HeroSegmented,
    HeroShell,
    HeroStatusPill,
} from '@/pages/health-safety/components/hs-hero-kit';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    Archive,
    Bell,
    CheckCircle2,
    ChevronRight,
    CircleDot,
    Clock,
    FileDown,
    FileEdit,
    LayoutGrid,
    Plus,
    Search,
    ShieldAlert,
    ShieldCheck,
    TriangleAlert,
    X,
} from 'lucide-react';
import { useState, type MouseEvent } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Health & Safety', href: '/health-safety' },
    { title: 'Risk Assessments', href: '/health-safety/risk-assessments' },
];

const BASE = '/health-safety/risk-assessments';

interface Filters {
    tab: string;
    status: string | null;
    risk_level: string | null;
    due_for_review: string | null;
    risk_acceptable: string | null;
    site_id: number | null;
    client_id: number | null;
    hs_event_id: number | null;
    search: string | null;
}

interface Hero {
    total: number;
    active: number;
    high_extreme_active: number;
    drafts: number;
    under_review: number;
    due_for_review: number;
    residual_not_acceptable: number;
    awaiting_approval: number;
    compliance: {
        reviews_overdue: number;
        high_extreme_without_approval: number;
        residual_not_acceptable: number;
        pct_active_scheduled: number;
    };
}

interface Paginated<T> {
    data: T[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
    current_page: number;
    last_page: number;
    total: number;
    from: number | null;
    to: number | null;
}

interface Props {
    assessments: Paginated<RaRow>;
    tabCounts: { all: number; active: number; drafts: number; due: number; high: number; closed: number };
    hero: Hero;
    detail: RaDetail | null;
    pickers: RaPickers;
    can: { manage: boolean; viewReports?: boolean };
    filters: Filters;
}

const STATUS_ITEMS = [
    { key: '', label: 'All' },
    { key: 'active', label: 'Active' },
    { key: 'under_review', label: 'Under review' },
    { key: 'draft', label: 'Draft' },
    { key: 'superseded', label: 'Superseded' },
    { key: 'archived', label: 'Archived' },
];
const LEVEL_ITEMS = [
    { key: '', label: 'All' },
    { key: 'low', label: 'Low' },
    { key: 'medium', label: 'Medium' },
    { key: 'high', label: 'High' },
    { key: 'extreme', label: 'Extreme' },
];

export default function RiskAssessmentsIndex({ assessments, tabCounts, hero, detail, pickers, can, filters }: Props) {
    const [ctx, setCtx] = useState<ShiftCtxState | null>(null);
    const [modal, setModal] = useState<{
        kind: RaModalKind;
        detail: RaDetail | null;
        initialAttach?: { type: AttachType; id: number } | null;
    } | null>(null);

    /* -------- navigation -------- */
    const paramsFrom = (f: Partial<Filters>): Record<string, string | number> => {
        const merged = { ...filters, ...f };
        const out: Record<string, string | number> = {};
        (Object.keys(merged) as (keyof Filters)[]).forEach((k) => {
            const v = merged[k];
            if (v !== null && v !== '' && v !== undefined) out[k] = v;
        });
        return out;
    };
    const go = (next: Partial<Filters>) => router.get(BASE, paramsFrom(next), { preserveState: true, preserveScroll: true, replace: true });
    const setTab = (id: string) => router.get(BASE, paramsFrom({ tab: id }), { preserveScroll: true });
    const clearFilters = () => router.get(BASE, { tab: filters.tab }, { preserveState: true, preserveScroll: true, replace: true });
    const hasFilters = !!(filters.status || filters.risk_level || filters.site_id || filters.client_id || filters.due_for_review || filters.search);

    /* -------- detail (deep-linkable via ?assessment=) -------- */
    const openDetail = (id: number) =>
        router.get(BASE, { ...paramsFrom({}), assessment: id }, { only: ['detail'], preserveState: true, preserveScroll: true });
    const closeDetail = () => router.get(BASE, paramsFrom({}), { only: ['detail'], preserveState: true, preserveScroll: true });

    /* -------- ctx-menu actions need the full record (JSON fetch) -------- */
    const fetchDetail = async (id: number): Promise<RaDetail | null> => {
        try {
            const res = await fetch(`${BASE}/${id}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            if (!res.ok) return null;
            return ((await res.json()) as { detail: RaDetail }).detail;
        } catch {
            return null;
        }
    };
    const ctxAction = (kind: RaModalKind, id: number) => {
        setCtx(null);
        void fetchDetail(id).then((d) => d && setModal({ kind, detail: d }));
    };

    const copyLink = (r: RaRow) => {
        void navigator.clipboard?.writeText(`${window.location.origin}${BASE}?assessment=${r.id}`);
        setCtx(null);
    };

    const handlers: RaCtxHandlers = {
        onView: (r) => {
            setCtx(null);
            openDetail(r.id);
        },
        onEdit: (r) => ctxAction('edit', r.id),
        onApprove: (r) => ctxAction('approve', r.id),
        onReview: (r) => ctxAction('review', r.id),
        onResidual: (r) => ctxAction('residual', r.id),
        onSupersede: (r) => ctxAction('supersede', r.id),
        onArchive: (r) => ctxAction('archive', r.id),
        onCopyLink: copyLink,
        onOpenCurrent: (id) => openDetail(id),
    };

    const onCtx = (e: MouseEvent, row: RaRow) => {
        e.preventDefault();
        setCtx({
            x: e.clientX,
            y: e.clientY,
            tag: row.status.replace('_', ' ').toUpperCase(),
            meta: `${row.reference_number} · ${row.title}`,
            items: buildRaCtxItems(row, can.manage, handlers),
        });
    };

    const openNew = () =>
        setModal({
            kind: 'new',
            detail: null,
            // Creating from an event-filtered register pre-attaches that event.
            initialAttach: filters.hs_event_id ? { type: 'event', id: filters.hs_event_id } : null,
        });

    /* -------- tabs -------- */
    const TABS: RosterTabItem[] = [
        { id: 'all', label: 'All', icon: LayoutGrid, tone: 'primary', badge: tabCounts.all || undefined },
        { id: 'active', label: 'Active', icon: CircleDot, tone: 'success', badge: tabCounts.active || undefined },
        { id: 'drafts', label: 'Drafts', icon: FileEdit, tone: 'warning', badge: tabCounts.drafts || undefined },
        { id: 'due', label: 'Due for review', icon: Clock, tone: 'critical', badge: tabCounts.due || undefined },
        { id: 'high', label: 'High/Extreme', icon: TriangleAlert, tone: 'critical', badge: tabCounts.high || undefined },
        { id: 'closed', label: 'Superseded/Archived', icon: Archive, tone: 'info', badge: tabCounts.closed || undefined },
    ];

    /* -------- compliance badges -------- */
    const c = hero.compliance;
    const badges: HeroComplianceBadge[] = [
        {
            icon: c.reviews_overdue > 0 ? AlertTriangle : CheckCircle2,
            tone: c.reviews_overdue > 0 ? 'critical' : 'success',
            label: `Reviews · ${c.reviews_overdue} overdue`,
        },
        { icon: ShieldCheck, tone: 'success', label: 'Ngā Paerewa NZS 8134:2021 · Certified' },
        {
            icon: c.high_extreme_without_approval > 0 ? AlertTriangle : CheckCircle2,
            tone: c.high_extreme_without_approval > 0 ? 'critical' : 'success',
            label: `High/extreme · ${c.high_extreme_without_approval} without approved plan`,
        },
        {
            icon: c.residual_not_acceptable > 0 ? AlertTriangle : CheckCircle2,
            tone: c.residual_not_acceptable > 0 ? 'warning' : 'success',
            label: `Residual · ${c.residual_not_acceptable} not acceptable`,
        },
        {
            icon: c.pct_active_scheduled >= 80 ? CheckCircle2 : AlertTriangle,
            tone: c.pct_active_scheduled >= 80 ? 'success' : 'warning',
            label: `Scheduled review · ${c.pct_active_scheduled}% of active`,
        },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Risk Assessments" />
            <div className="flex flex-col gap-5 p-4 md:p-6">
                <HeroShell
                    footer={
                        <div className="flex flex-wrap items-center gap-x-4 gap-y-2">
                            <HeroSegmented
                                label="Status"
                                variant="pill"
                                ariaLabel="Status"
                                items={STATUS_ITEMS}
                                value={filters.status ?? ''}
                                onChange={(k) => go({ status: k || null })}
                            />
                            <HeroSegmented
                                label="Level"
                                variant="pill"
                                ariaLabel="Risk level"
                                items={LEVEL_ITEMS}
                                value={filters.risk_level ?? ''}
                                onChange={(k) => go({ risk_level: k || null })}
                            />
                            {pickers.sites.length ? (
                                <EntityFilter label="Site" allLabel="All sites" items={pickers.sites} value={filters.site_id} onChange={(id) => go({ site_id: id })} onDark />
                            ) : null}
                            {pickers.clients.length ? (
                                <EntityFilter label="Client" allLabel="All clients" items={pickers.clients} value={filters.client_id} onChange={(id) => go({ client_id: id })} onDark />
                            ) : null}
                            <button
                                type="button"
                                onClick={() => go({ due_for_review: filters.due_for_review === 'true' ? null : 'true' })}
                                aria-pressed={filters.due_for_review === 'true'}
                                className={cn(
                                    'inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-xs font-semibold transition-colors',
                                    filters.due_for_review === 'true'
                                        ? 'border-primary-foreground bg-primary-foreground text-primary'
                                        : 'border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground hover:bg-primary-foreground/20',
                                )}
                            >
                                <Clock className="h-3.5 w-3.5" /> Due for review
                            </button>
                            <div className="relative ml-auto">
                                <Search className="pointer-events-none absolute top-1/2 left-2.5 h-3.5 w-3.5 -translate-y-1/2 text-primary-foreground/60" />
                                <input
                                    type="search"
                                    aria-label="Search risk assessments"
                                    placeholder="Search assessments…"
                                    defaultValue={filters.search ?? ''}
                                    onKeyDown={(e) => {
                                        if (e.key === 'Enter') go({ search: (e.target as HTMLInputElement).value || null });
                                    }}
                                    className="w-52 rounded-lg border border-primary-foreground/20 bg-primary-foreground/10 py-1.5 pr-2.5 pl-8 text-xs text-primary-foreground placeholder:text-primary-foreground/50 focus-visible:ring-2 focus-visible:ring-primary-foreground/40 focus-visible:outline-none"
                                />
                            </div>
                            {hasFilters ? (
                                <button
                                    type="button"
                                    onClick={clearFilters}
                                    className="inline-flex items-center gap-1 rounded-md px-2 py-1 text-[11px] font-medium text-primary-foreground/70 transition-colors hover:text-primary-foreground"
                                >
                                    <X className="h-3 w-3" /> Clear
                                </button>
                            ) : null}
                        </div>
                    }
                >
                    {/* lifecycle ribbon */}
                    <nav aria-label="Risk assessment lifecycle" className="flex flex-wrap items-center gap-0.5 text-xs">
                        <Link href="/health-safety" className="inline-flex items-center gap-1.5 rounded-md px-2 py-1 font-medium text-primary-foreground/70 transition-colors hover:text-primary-foreground">
                            <LayoutGrid className="h-3.5 w-3.5" /> H&amp;S
                        </Link>
                        {RIBBON_STAGES.map((stage, i) => (
                            <span key={stage} className="inline-flex items-center">
                                <ChevronRight className="mx-0.5 h-3.5 w-3.5 text-primary-foreground/40" />
                                <span className={cn('rounded-md px-2 py-1 font-medium', i === 1 ? 'bg-primary-foreground/20 font-semibold text-primary-foreground' : 'text-primary-foreground/70')}>
                                    {stage}
                                </span>
                            </span>
                        ))}
                    </nav>

                    {/* title row */}
                    <div className="flex flex-wrap items-start justify-between gap-4">
                        <div className="flex items-start gap-4">
                            <HeroMedallion icon={ShieldAlert} />
                            <div className="flex flex-col gap-1.5">
                                <HeroStatusPill>Risk register · synced just now</HeroStatusPill>
                                <h1 className="text-2xl font-bold tracking-tight md:text-[28px]">Risk Assessments</h1>
                                <p className="max-w-xl text-sm leading-relaxed text-primary-foreground/75">
                                    Identify, score and control hazards across sites, clients and the H&amp;S backbone — the ISO 31000 / SafePlus
                                    5×5 register, from draft through approval, review and supersession.
                                </p>
                            </div>
                        </div>
                        <div className="flex items-center gap-2">
                            {can.viewReports ? (
                                <a
                                    href="/health-safety/reports/risk-assessment-register"
                                    className="inline-flex items-center gap-1.5 rounded-lg border border-primary-foreground/25 bg-primary-foreground/10 px-3 py-1.5 text-xs font-semibold text-primary-foreground transition-colors hover:bg-primary-foreground/20"
                                >
                                    <FileDown className="h-3.5 w-3.5" /> Board export
                                </a>
                            ) : null}
                            {can.manage ? (
                                <Button size="sm" onClick={openNew} className="bg-primary-foreground text-primary hover:bg-primary-foreground/90">
                                    <Plus className="h-4 w-4" /> New risk assessment
                                </Button>
                            ) : null}
                        </div>
                    </div>

                    {/* clusters */}
                    <div className="grid gap-3 md:grid-cols-2">
                        <HeroCluster title="Live · register" icon={Activity}>
                            <HeroClusterTile href={`${BASE}?tab=all`} label="Total" value={String(hero.total)} caption="all assessments" tone="neutral" />
                            <HeroClusterTile href={`${BASE}?tab=active`} label="Active" value={String(hero.active)} caption="in force" tone="success" />
                            <HeroClusterTile href={`${BASE}?tab=high`} label="High/extreme" value={String(hero.high_extreme_active)} caption="active register" tone="critical" />
                            <HeroClusterTile href={`${BASE}?tab=drafts`} label="Drafts" value={String(hero.drafts)} caption="not yet active" tone="warning" />
                        </HeroCluster>
                        <HeroCluster title="Needs attention" icon={Bell}>
                            <HeroClusterTile href={`${BASE}?tab=due`} label="Due for review" value={String(hero.due_for_review)} caption="overdue / soon" tone="critical" />
                            <HeroClusterTile href={`${BASE}?status=under_review`} label="Under review" value={String(hero.under_review)} caption="being revised" tone="warning" />
                            <HeroClusterTile href={`${BASE}?risk_acceptable=0`} label="Residual not OK" value={String(hero.residual_not_acceptable)} caption="needs action" tone="critical" />
                            <HeroClusterTile href={`${BASE}?tab=drafts`} label="Awaiting approval" value={String(hero.awaiting_approval)} caption="drafts to approve" tone="warning" />
                        </HeroCluster>
                    </div>

                    <HeroComplianceBadges items={badges} />
                </HeroShell>

                <TabStrip value={filters.tab} onChange={setTab} items={TABS} ariaLabel="Risk assessment views" />

                <RaTable
                    rows={assessments.data}
                    countLabel={`${assessments.from ?? 0}–${assessments.to ?? 0} of ${assessments.total}`}
                    onOpen={openDetail}
                    onCtx={onCtx}
                    emptyCta={
                        can.manage ? (
                            <Button onClick={openNew}>
                                <Plus className="h-4 w-4" /> New risk assessment
                            </Button>
                        ) : undefined
                    }
                />

                {assessments.last_page > 1 ? (
                    <div className="flex items-center justify-between">
                        <p className="text-sm text-muted-foreground">
                            Showing {assessments.from}–{assessments.to} of {assessments.total}
                        </p>
                        <LaravelPagination links={assessments.links} />
                    </div>
                ) : null}
            </div>

            {ctx ? <ShiftContextMenu ctx={ctx} onClose={() => setCtx(null)} /> : null}

            {detail ? (
                <RaDetailDialog
                    detail={detail}
                    open
                    onClose={closeDetail}
                    onAction={(kind) => {
                        const d = detail;
                        setModal({ kind, detail: d });
                        closeDetail();
                    }}
                    onOpenAssessment={openDetail}
                />
            ) : null}

            {modal ? (
                <RaWizardDialog
                    kind={modal.kind}
                    detail={modal.detail}
                    pickers={pickers}
                    initialAttach={modal.initialAttach}
                    onClose={() => setModal(null)}
                    onSuccess={(id) => {
                        setModal(null);
                        if (id) openDetail(id);
                    }}
                />
            ) : null}
        </AppLayout>
    );
}
