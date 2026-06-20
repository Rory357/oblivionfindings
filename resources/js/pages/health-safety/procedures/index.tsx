/* Safe Work Procedures register — the controlled SWMS document library. Shares the
 * gold-standard hs-hero-kit hero chrome + rostering TabStrip/EntityFilter/ShiftContextMenu
 * with /health-safety/events, /incidents, /safeguarding and the other H&S registers so the
 * whole safety workflow reads as one product. Left-click a row → detail-as-modal; right-click
 * → ShiftContextMenu; create/edit → the Add-client-style modal wizard. NZ-only, web-only. */
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { Button } from '@/components/ui/button';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { EntityFilter, ShiftContextMenu, TabStrip, type RosterTabItem, type ShiftCtxItem, type ShiftCtxState } from '@/components/rostering';
import {
    ProcedureDetailDialog,
    categoryMeta,
    reviewFlag,
    statusMeta,
    type ProcedureActionKey,
    type ProcedureDetail,
    type ProcedureFormData,
    type ProcedureSectionKey,
} from '@/components/health-safety/procedure-detail-dialog';
import { ProcedureWizardDialog, type ProcedureWizardOptions } from '@/components/health-safety/procedure-wizard-dialog';
import {
    HeroCluster,
    HeroClusterTile,
    HeroComplianceBadges,
    HeroMedallion,
    HeroSegmented,
    HeroShell,
    HeroStatusPill,
    fmt,
    type HeroComplianceBadge,
} from '@/pages/health-safety/components/hs-hero-kit';
import { WorkflowRibbon } from '@/pages/health-safety/components/workflow-ribbon';
import { FlagBadge, RegisterTableHeader, TONE_BG, entityTone, initials } from '@/pages/health-safety/components/register-row-kit';
import { formatDateLong } from '@/lib/datetime';
import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import { useEffect, useState, type MouseEvent as ReactMouseEvent } from 'react';
import {
    AlertTriangle,
    Archive,
    ArchiveRestore,
    BarChart3,
    CalendarCheck,
    CheckCircle2,
    Clock,
    Download,
    ExternalLink,
    Eye,
    FilePlus2,
    FileText,
    HardHat,
    LayoutList,
    MousePointer2,
    Pencil,
    Plus,
    RotateCcw,
    Search,
    Send,
    ShieldCheck,
    X,
} from 'lucide-react';

/* ------------------------------------------------------------------ */
/*  Types                                                              */
/* ------------------------------------------------------------------ */

type ProcedureRow = {
    id: number;
    reference_number: string;
    title: string;
    purpose: string | null;
    category: string;
    status: string;
    version: number;
    review_date: string | null;
    owner: { id: number; name: string } | null;
    approved_by: { id: number; name: string } | null;
    sites_count: number;
    roles_count: number;
    documents_count: number;
};

type Paginated<T> = { data: T[]; links: { url: string | null; label: string; active: boolean }[]; last_page: number };

type Filters = {
    q: string | null;
    tab: string;
    category: string | null;
    status: string | null;
    site_id: number | null;
    review_state: string | null;
};

type Props = {
    procedures: Paginated<ProcedureRow>;
    tab: string;
    tabCounts: Record<string, number>;
    hero: {
        library: { approved: number; under_review: number; draft: number; archived: number };
        attention: { review_due_soon: number; review_overdue: number; unapproved: number; coverage_gaps: number };
        nz: { worksafe_approved: number; nga_paerewa_documented: boolean; review_overdue: number; coverage_gaps: number; high_risk_covered: boolean };
    };
    filters: Filters;
    sites: { id: number; name: string }[];
    roles: { id: number; name: string; label: string }[];
    trainingCourses: { id: number; name: string; code: string | null }[];
    owners: { id: number; name: string }[];
    categories: { value: string; label: string }[];
    detail: ProcedureDetail | null;
    can: { view: boolean; create: boolean; manage: boolean; approve: boolean };
};

const URL = '/health-safety/procedures';

const REVIEW_ITEMS = [
    { key: 'all', label: 'All' },
    { key: 'overdue', label: 'Overdue' },
    { key: 'due_soon', label: 'Due ≤30d' },
    { key: 'current', label: 'Current' },
];

type WizardState = { mode: 'create' } | { mode: 'edit'; form: ProcedureFormData } | null;

/* ------------------------------------------------------------------ */
/*  Page                                                              */
/* ------------------------------------------------------------------ */

export default function ProceduresIndex({ procedures, tab, tabCounts, hero, filters, sites, roles, trainingCourses, owners, categories, detail, can }: Props) {
    const [ctx, setCtx] = useState<ShiftCtxState | null>(null);
    const [pendingSection, setPendingSection] = useState<ProcedureSectionKey>('overview');
    const [pendingAction, setPendingAction] = useState<ProcedureActionKey | null>(null);
    const [wizard, setWizard] = useState<WizardState>(null);

    const wizardOptions: ProcedureWizardOptions = { sites, roles, trainingCourses, owners, categories };

    // Deep-link fallbacks: /create and /{id}/edit redirect here with ?new / ?edit.
    useEffect(() => {
        const params = new URLSearchParams(window.location.search);
        if (params.get('new') === '1') setWizard({ mode: 'create' });
        else if (params.get('edit') === '1' && detail) setWizard({ mode: 'edit', form: detail.form });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const go = (next: Partial<Filters>) => router.get(URL, { ...filters, ...next }, { preserveState: true, preserveScroll: true, replace: true });
    const setTab = (id: string) => router.get(URL, { ...filters, tab: id }, { preserveScroll: true });

    const openProcedure = (id: number, opts?: { section?: ProcedureSectionKey; action?: ProcedureActionKey }) => {
        setPendingSection(opts?.section ?? 'overview');
        setPendingAction(opts?.action ?? null);
        router.get(URL, { ...filters, procedure: id }, { preserveState: true, preserveScroll: true, only: ['detail'] });
    };
    const closeDetail = () => router.get(URL, { ...filters }, { preserveState: true, preserveScroll: true, only: ['detail'] });

    // Edit from a row context-menu: load the procedure's editable form, then open the wizard.
    const openEdit = (id: number) =>
        router.get(URL, { ...filters, procedure: id }, {
            only: ['detail'],
            preserveState: true,
            preserveScroll: true,
            onSuccess: (page) => {
                const det = (page.props as { detail?: ProcedureDetail | null }).detail;
                if (det) setWizard({ mode: 'edit', form: det.form });
            },
        });

    const closeWizard = () => {
        setWizard(null);
        if (detail) closeDetail();
    };

    const clearFilters = () => router.get(URL, { tab }, { preserveState: true, preserveScroll: true, replace: true });
    const hasFilters = !!(filters.q || filters.category || filters.site_id || filters.review_state);

    const TABS: RosterTabItem[] = [
        { id: 'all', label: 'All', icon: LayoutList, tone: 'primary', badge: tabCounts.all || undefined },
        { id: 'draft', label: 'Draft', icon: Pencil, tone: 'info', badge: tabCounts.draft || undefined },
        { id: 'under_review', label: 'Under review', icon: Clock, tone: 'warning', badge: tabCounts.under_review || undefined },
        { id: 'approved', label: 'Approved', icon: CheckCircle2, tone: 'success', badge: tabCounts.approved || undefined },
        { id: 'review_due', label: 'Review due', icon: CalendarCheck, tone: 'critical', badge: tabCounts.review_due || undefined },
        { id: 'archived', label: 'Archived', icon: Archive, tone: 'info', badge: tabCounts.archived || undefined },
    ];

    const activeReview = filters.review_state ?? 'all';
    const onReview = (key: string) => go({ review_state: key === 'all' ? null : key });

    const exportUrl = () => {
        const params = new URLSearchParams();
        if (filters.tab && filters.tab !== 'all') params.set('tab', filters.tab);
        if (filters.q) params.set('q', filters.q);
        if (filters.category) params.set('category', filters.category);
        if (filters.site_id) params.set('site_id', String(filters.site_id));
        if (filters.review_state) params.set('review_state', filters.review_state);
        const qs = params.toString();
        return `${URL}/export${qs ? `?${qs}` : ''}`;
    };

    // Hero right-click → library quick actions (mirrors the Library reports popover).
    const openHeroCtx = (e: ReactMouseEvent) => {
        e.preventDefault();
        const items: ShiftCtxItem[] = [];
        if (can.create) items.push({ icon: <Plus className="h-3.5 w-3.5" />, label: 'New procedure', tone: 'primary', onClick: () => setWizard({ mode: 'create' }) });
        items.push(
            { icon: <Download className="h-3.5 w-3.5" />, label: 'Export register (CSV)', onClick: () => { window.location.href = exportUrl(); } },
            { icon: <CalendarCheck className="h-3.5 w-3.5" />, label: 'Review-due list', onClick: () => setTab('review_due') },
        );
        setCtx({ x: e.clientX, y: e.clientY, tag: 'LIBRARY', meta: `${tabCounts.all ?? 0} procedures`, items });
    };

    const openRowCtx = (e: ReactMouseEvent, p: ProcedureRow) => {
        e.preventDefault();
        const st = statusMeta(p.status);
        const items: ShiftCtxItem[] = [{ icon: <Eye className="h-3.5 w-3.5" />, label: 'View procedure', sub: p.reference_number, tone: 'primary', onClick: () => openProcedure(p.id) }];
        if (can.manage && p.status !== 'archived') {
            items.push({ icon: p.status === 'approved' ? <FilePlus2 className="h-3.5 w-3.5" /> : <Pencil className="h-3.5 w-3.5" />, label: p.status === 'approved' ? 'New version' : 'Edit', onClick: () => openEdit(p.id) });
        }
        if (can.manage && p.status === 'draft') items.push({ icon: <Send className="h-3.5 w-3.5" />, label: 'Submit for review', onClick: () => openProcedure(p.id, { action: 'submit_review' }) });
        if (can.approve && p.status === 'under_review') items.push({ icon: <CheckCircle2 className="h-3.5 w-3.5" />, label: 'Approve', tone: 'primary', onClick: () => openProcedure(p.id, { action: 'approve' }) });
        if (can.manage && (p.status === 'under_review' || p.status === 'approved')) items.push({ icon: <RotateCcw className="h-3.5 w-3.5" />, label: 'Request changes', onClick: () => openProcedure(p.id, { action: 'request_changes' }) });
        if (can.manage && p.status === 'approved') items.push({ icon: <CalendarCheck className="h-3.5 w-3.5" />, label: 'Record review', onClick: () => openProcedure(p.id, { action: 'record_review' }) });
        if (can.manage && p.status !== 'archived') items.push({ icon: <Archive className="h-3.5 w-3.5" />, label: 'Archive', tone: 'critical', onClick: () => openProcedure(p.id, { action: 'archive' }) });
        if (can.manage && p.status === 'archived') items.push({ icon: <ArchiveRestore className="h-3.5 w-3.5" />, label: 'Restore', tone: 'primary', onClick: () => openProcedure(p.id, { action: 'restore' }) });
        items.push({ sep: true }, { icon: <ExternalLink className="h-3.5 w-3.5" />, label: 'Open full page', onClick: () => router.visit(`${URL}/${p.id}`) });
        setCtx({ x: e.clientX, y: e.clientY, tag: st.label.toUpperCase(), meta: `${p.reference_number} · ${categoryMeta(p.category).label}`, items });
    };

    const lib = hero.library;
    const at = hero.attention;
    const nz = hero.nz;
    const complianceItems: HeroComplianceBadge[] = [
        { icon: nz.worksafe_approved > 0 ? CheckCircle2 : AlertTriangle, tone: nz.worksafe_approved > 0 ? 'success' : 'warning', label: `WorkSafe-aligned · ${nz.worksafe_approved} approved` },
        { icon: ShieldCheck, tone: nz.nga_paerewa_documented ? 'success' : 'warning', label: `Ngā Paerewa NZS 8134:2021 · ${nz.nga_paerewa_documented ? 'Documented' : 'Review due'}` },
        { icon: nz.review_overdue > 0 ? AlertTriangle : CheckCircle2, tone: nz.review_overdue > 0 ? 'critical' : 'success', label: nz.review_overdue > 0 ? `Review cycle · ${nz.review_overdue} overdue` : 'Review cycle · On track' },
        { icon: nz.coverage_gaps > 0 ? AlertTriangle : CheckCircle2, tone: nz.coverage_gaps > 0 ? 'warning' : 'success', label: nz.coverage_gaps > 0 ? `Coverage gaps · ${nz.coverage_gaps}` : 'Coverage · Complete' },
        { icon: HardHat, tone: nz.high_risk_covered ? 'success' : 'warning', label: `High-risk categories · ${nz.high_risk_covered ? 'Covered' : 'Gaps'}` },
    ];

    const tableTitle = { all: 'All procedures', draft: 'Drafts', under_review: 'Under review', approved: 'Approved & in force', review_due: 'Review due', archived: 'Archived' }[tab] ?? 'Procedures';

    return (
        <AppLayout breadcrumbs={[{ title: 'Health & Safety', href: '/health-safety' }, { title: 'Safe Work Procedures', href: URL }]}>
            <Head title="Safe Work Procedures" />

            <div className="flex flex-col gap-6 p-6">
                <HeroShell
                    footer={
                        <div className="flex flex-wrap items-center gap-x-4 gap-y-2">
                            <HeroSegmented label="Review" variant="pill" ariaLabel="Review window" items={REVIEW_ITEMS} value={activeReview} onChange={onReview} />
                            {sites?.length ? <EntityFilter label="Site" allLabel="All sites" items={sites} value={filters.site_id} onChange={(id) => go({ site_id: id })} onDark /> : null}
                            <label className="inline-flex items-center gap-1.5">
                                <span className="text-[11px] font-semibold tracking-wide text-primary-foreground/60 uppercase">Category</span>
                                <select
                                    value={filters.category ?? ''}
                                    onChange={(e) => go({ category: e.target.value || null })}
                                    aria-label="Category filter"
                                    className="rounded-lg border border-primary-foreground/20 bg-primary-foreground/10 px-2.5 py-1.5 text-xs font-medium text-primary-foreground focus-visible:ring-2 focus-visible:ring-primary-foreground/40 focus-visible:outline-none [&>option]:text-foreground"
                                >
                                    <option value="">All categories</option>
                                    {categories.map((c) => (
                                        <option key={c.value} value={c.value}>
                                            {c.label}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <div className="relative ml-auto">
                                <Search className="pointer-events-none absolute top-1/2 left-2.5 h-3.5 w-3.5 -translate-y-1/2 text-primary-foreground/60" />
                                <input
                                    type="search"
                                    placeholder="Search procedures…"
                                    defaultValue={filters.q ?? ''}
                                    onKeyDown={(e) => {
                                        if (e.key === 'Enter') go({ q: (e.target as HTMLInputElement).value || null });
                                    }}
                                    className="w-48 rounded-lg border border-primary-foreground/20 bg-primary-foreground/10 py-1.5 pr-2.5 pl-8 text-xs text-primary-foreground placeholder:text-primary-foreground/50 focus-visible:ring-2 focus-visible:ring-primary-foreground/40 focus-visible:outline-none"
                                />
                            </div>
                            {hasFilters ? (
                                // eslint-disable-next-line no-restricted-syntax -- onDark clear affordance on the hero footer
                                <button type="button" onClick={clearFilters} className="inline-flex items-center gap-1 rounded-md px-2 py-1 text-[11px] font-medium text-primary-foreground/70 transition-colors hover:text-primary-foreground">
                                    <X className="h-3 w-3" /> Clear
                                </button>
                            ) : null}
                        </div>
                    }
                >
                    <WorkflowRibbon current="document" />

                    <div className="flex flex-wrap items-start justify-between gap-4" onContextMenu={openHeroCtx}>
                        <div className="flex items-start gap-4">
                            <HeroMedallion icon={FileText} />
                            <div className="flex flex-col gap-1.5">
                                <HeroStatusPill>Procedure library · synced just now</HeroStatusPill>
                                <h1 className="text-2xl font-bold tracking-tight text-primary-foreground md:text-[28px]">Safe Work Procedures</h1>
                                <p className="max-w-xl text-sm text-primary-foreground/70">
                                    The controlled library of safe-work documents — drafted, reviewed, approved and kept on a recurring review cycle.
                                    Every version is traceable; every procedure maps to the roles, sites, training and hazards it governs.
                                </p>
                            </div>
                        </div>

                        <div className="flex items-center gap-2">
                            {can.create ? (
                                <Button size="sm" onClick={() => setWizard({ mode: 'create' })} className="border border-primary-foreground/25 bg-primary-foreground/10 text-primary-foreground hover:bg-primary-foreground/20">
                                    <Plus className="mr-1.5 h-4 w-4" /> New procedure
                                </Button>
                            ) : null}
                            <Popover>
                                <PopoverTrigger asChild>
                                    <Button size="sm" className="border border-primary-foreground/25 bg-primary-foreground/10 text-primary-foreground hover:bg-primary-foreground/20">
                                        <BarChart3 className="mr-1.5 h-4 w-4" /> Library reports
                                        <span aria-hidden className="ml-1">▾</span>
                                    </Button>
                                </PopoverTrigger>
                                <PopoverContent align="end" className="w-60 p-1.5">
                                    <a href={exportUrl()} className="flex w-full items-center gap-2.5 rounded-md p-2.5 text-left text-sm font-medium transition-colors hover:bg-muted">
                                        <Download className="h-4 w-4 shrink-0 text-primary" /> Export register (CSV)
                                    </a>
                                    {/* eslint-disable-next-line no-restricted-syntax -- popover menu item, not a form control */}
                                    <button type="button" onClick={() => setTab('review_due')} className="flex w-full items-center gap-2.5 rounded-md p-2.5 text-left text-sm font-medium transition-colors hover:bg-muted">
                                        <CalendarCheck className="h-4 w-4 shrink-0 text-primary" /> Review-due list
                                    </button>
                                </PopoverContent>
                            </Popover>
                        </div>
                    </div>

                    <div className="grid gap-3 lg:grid-cols-2">
                        <HeroCluster title="Library status" icon={FileText}>
                            <HeroClusterTile href={`${URL}?tab=approved`} label="Approved" value={fmt(lib.approved)} caption="in force" tone="success" />
                            <HeroClusterTile href={`${URL}?tab=under_review`} label="Under review" value={fmt(lib.under_review)} caption="in progress" tone="warning" />
                            <HeroClusterTile href={`${URL}?tab=draft`} label="Draft" value={fmt(lib.draft)} caption="not yet live" tone="neutral" />
                            <HeroClusterTile href={`${URL}?tab=archived`} label="Archived" value={fmt(lib.archived)} caption="superseded" tone="neutral" />
                        </HeroCluster>
                        <HeroCluster title="Needs attention" icon={AlertTriangle}>
                            <HeroClusterTile href={`${URL}?tab=review_due`} label="Review due" value={fmt(at.review_due_soon)} caption="within 30 days" tone="warning" />
                            <HeroClusterTile href={`${URL}?tab=review_due`} label="Overdue" value={fmt(at.review_overdue)} caption="past review" tone="critical" />
                            <HeroClusterTile href={`${URL}?tab=draft`} label="Unapproved" value={fmt(at.unapproved)} caption="awaiting sign-off" tone="neutral" />
                            <HeroClusterTile label="Coverage gaps" value={fmt(at.coverage_gaps)} caption="categories at risk" tone="critical" />
                        </HeroCluster>
                    </div>

                    <HeroComplianceBadges items={complianceItems} />
                </HeroShell>

                <TabStrip value={tab} items={TABS} onChange={setTab} ariaLabel="Procedure views" />

                <section className="overflow-hidden rounded-2xl border border-border bg-card shadow-sm">
                    <RegisterTableHeader icon={FileText} title={tableTitle} subtitle="the procedure library" hint="Right-click a row for the full lifecycle" hintIcon={MousePointer2} />
                    <ProcedureTable rows={procedures.data} onRowCtx={openRowCtx} onOpen={openProcedure} />
                </section>

                {procedures.last_page > 1 ? <LaravelPagination links={procedures.links} /> : null}
            </div>

            {ctx ? <ShiftContextMenu ctx={ctx} onClose={() => setCtx(null)} /> : null}

            {detail && !wizard ? (
                <ProcedureDetailDialog key={detail.id} detail={detail} open onClose={closeDetail} onEdit={(form) => setWizard({ mode: 'edit', form })} initialSection={pendingSection} initialAction={pendingAction} />
            ) : null}

            {wizard ? (
                <ProcedureWizardDialog
                    open
                    onClose={closeWizard}
                    options={wizardOptions}
                    initial={wizard.mode === 'edit' ? wizard.form : null}
                    onOpenProcedure={(id) => openProcedure(id)}
                />
            ) : null}
        </AppLayout>
    );
}

/* ------------------------------------------------------------------ */
/*  Table                                                             */
/* ------------------------------------------------------------------ */

function ProcedureTable({ rows, onRowCtx, onOpen }: { rows: ProcedureRow[]; onRowCtx: (e: ReactMouseEvent, p: ProcedureRow) => void; onOpen: (id: number) => void }) {
    if (!rows.length) {
        return (
            <div className="px-4 py-16 text-center">
                <FileText className="mx-auto mb-3 h-10 w-10 text-muted-foreground/40" />
                <p className="font-medium text-muted-foreground">No procedures here</p>
                <p className="mt-1 text-sm text-muted-foreground/70">Nothing matches this tab and filters.</p>
            </div>
        );
    }
    return (
        <div className="overflow-x-auto">
            <table className="w-full min-w-[1040px] text-sm">
                <thead className="bg-muted/70">
                    <tr className="border-b border-border text-left text-[11px] font-bold tracking-wide text-muted-foreground uppercase">
                        <th className="px-4 py-3">Reference</th>
                        <th className="px-4 py-3">Procedure</th>
                        <th className="px-4 py-3">Category</th>
                        <th className="px-4 py-3">Status</th>
                        <th className="px-4 py-3">Ver</th>
                        <th className="px-4 py-3">Owner / approver</th>
                        <th className="px-4 py-3">Review date</th>
                        <th className="px-4 py-3">Applies to</th>
                    </tr>
                </thead>
                <tbody className="divide-y divide-border">
                    {rows.map((p) => {
                        const cat = categoryMeta(p.category);
                        const st = statusMeta(p.status);
                        const StatusIcon = st.icon;
                        const flag = reviewFlag(p.review_date);
                        const ownerName = p.owner?.name ?? p.approved_by?.name ?? null;
                        return (
                            <tr
                                key={p.id}
                                onClick={() => onOpen(p.id)}
                                onContextMenu={(e) => onRowCtx(e, p)}
                                tabIndex={0}
                                aria-label={`Open procedure ${p.reference_number}`}
                                onKeyDown={(e) => {
                                    if (e.key === 'Enter' || e.key === ' ') {
                                        e.preventDefault();
                                        onOpen(p.id);
                                    }
                                }}
                                className="cursor-pointer transition-colors hover:bg-muted/45 focus-visible:bg-muted/45 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring"
                            >
                                <td className="px-4 py-3 align-top whitespace-nowrap font-mono text-xs font-semibold text-muted-foreground">{p.reference_number}</td>
                                <td className="max-w-[300px] px-4 py-3 align-top">
                                    <div className="flex items-start gap-2">
                                        <span className={`mt-1 h-2 w-2 shrink-0 rounded-full ${cat.dot}`} />
                                        <span className="min-w-0">
                                            <span className="block truncate text-xs font-bold text-foreground">{p.title}</span>
                                            {p.purpose ? <span className="mt-0.5 block truncate text-[11px] text-muted-foreground">{p.purpose}</span> : null}
                                        </span>
                                    </div>
                                </td>
                                <td className="px-4 py-3 align-top">
                                    <span className={`inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-[11px] font-medium ${cat.chip}`}>{cat.label}</span>
                                </td>
                                <td className="px-4 py-3 align-top">
                                    <span className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-medium ${TONE_BG[st.tone]}`}>
                                        <StatusIcon className="h-3 w-3" />
                                        {st.label}
                                    </span>
                                </td>
                                <td className="px-4 py-3 align-top text-xs font-bold text-muted-foreground tabular-nums">v{p.version}</td>
                                <td className="px-4 py-3 align-top">
                                    {ownerName ? (
                                        <span className="flex items-center gap-2">
                                            <span className={`grid h-7 w-7 shrink-0 place-items-center rounded-md text-[10px] font-bold ${entityTone(p.id)}`}>{initials(ownerName)}</span>
                                            <span className="min-w-0">
                                                <span className="block truncate text-xs font-bold text-foreground">{ownerName}</span>
                                                <span className="block truncate text-[11px] text-muted-foreground">{p.approved_by ? `Appr. ${p.approved_by.name.split(' ')[0]}` : 'Owner'}</span>
                                            </span>
                                        </span>
                                    ) : (
                                        <span className="text-xs text-muted-foreground">—</span>
                                    )}
                                </td>
                                <td className="px-4 py-3 align-top">
                                    <div className="flex flex-col gap-1">
                                        <span className="text-xs text-foreground">{p.review_date ? formatDateLong(p.review_date) : '—'}</span>
                                        {flag ? (
                                            <FlagBadge icon={CalendarCheck} tone={flag.tone} title="Review window">
                                                {flag.label}
                                            </FlagBadge>
                                        ) : null}
                                    </div>
                                </td>
                                <td className="px-4 py-3 align-top">
                                    <span className="inline-flex items-center gap-1.5 rounded-md bg-muted px-2 py-1 text-[11px] font-medium text-muted-foreground">
                                        {p.sites_count > 0 ? `${p.sites_count} site${p.sites_count === 1 ? '' : 's'}` : 'All sites'} · {p.roles_count > 0 ? `${p.roles_count} role${p.roles_count === 1 ? '' : 's'}` : 'All roles'}
                                        {p.documents_count > 0 ? ` · ${p.documents_count} doc${p.documents_count === 1 ? '' : 's'}` : ''}
                                    </span>
                                </td>
                            </tr>
                        );
                    })}
                </tbody>
            </table>
        </div>
    );
}
