import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import {
    HeroShell,
    HeroStatusPill,
    HeroMedallion,
    HeroCluster,
    HeroClusterTile,
    HeroSegmented,
    fmt,
    type Tone,
} from '@/pages/health-safety/components/hs-hero-kit';
import {
    EntityFilter,
    ShiftContextMenu,
    TabStrip,
    type RosterTabItem,
    type ShiftCtxItem,
    type ShiftCtxState,
} from '@/components/rostering';
import { SafeguardingConcernDialog, type ConcernDetail } from '@/components/safeguarding/concern-dialog';
import { formatDateTime } from '@/lib/datetime';
import { Head, router } from '@inertiajs/react';
import { useState, type MouseEvent as ReactMouseEvent } from 'react';
import {
    Activity,
    Bell,
    Calendar,
    CheckCircle2,
    CircleSlash,
    ClipboardCheck,
    ClipboardList,
    Clock,
    Eye,
    Landmark,
    LayoutList,
    ListTodo,
    Lock,
    Plus,
    RadioTower,
    Search,
    Shield,
    ShieldAlert,
    User as UserIcon,
    UserCheck,
    X,
} from 'lucide-react';

/* ------------------------------------------------------------------ */
/*  Types                                                               */
/* ------------------------------------------------------------------ */

type ConcernRow = {
    id: number;
    reference_number: string;
    occurred_at: string | null;
    reported_at: string | null;
    concern_type: string;
    abuse_category: string | null;
    severity: string;
    status: string;
    current_risk_level: string | null;
    restricted: boolean;
    subject: { name: string | null; site: string | null } | null;
    assigned_to: { name: string } | null;
    flags: {
        has_alert: boolean;
        requires_referral: boolean;
        referral_overdue: boolean;
        review_due: boolean;
        action_overdue: boolean;
    };
    related_incident_id: number | null;
    control_room_alert_id: number | null;
};

type ReviewRow = {
    id: number;
    reference_number: string;
    restricted: boolean;
    subject: string | null;
    kind: 'risk' | 'ack';
    detail: string;
    due_at: string | null;
    overdue: boolean;
};

type Paginated<T> = { data: T[]; links: { url: string | null; label: string; active: boolean }[]; last_page: number };

type Filters = {
    q: string | null;
    tab: string;
    severity: string | null;
    category: string | null;
    site_id: number | null;
    subject_id: number | null;
    from: string | null;
    to: string | null;
};

type Props = {
    filters: Filters;
    tab: string;
    tabCounts: Record<string, number>;
    rows: Paginated<ConcernRow | ReviewRow>;
    rowsKind: 'concerns' | 'reviews';
    hero: {
        openWork: {
            open: { value: number };
            awaitingTriage: { value: number };
            investigating: { value: number };
            referred: { value: number };
        };
        attention: {
            overdueActions: { value: number };
            reviewsDue: { value: number };
            acksAwaited: { value: number };
            criticalOpen: { value: number };
        };
    };
    referralOverdueCount: number;
    sites: Array<{ id: number; name: string }>;
    subjects: Array<{ id: number; name: string }>;
    can: { create: boolean };
    detail: ConcernDetail | null;
};

/* ------------------------------------------------------------------ */
/*  Token maps                                                          */
/* ------------------------------------------------------------------ */

const SEV: Record<string, { tone: Tone; label: string }> = {
    low: { tone: 'success', label: 'Low' },
    medium: { tone: 'warning', label: 'Medium' },
    high: { tone: 'critical', label: 'High' },
    critical: { tone: 'critical', label: 'Critical' },
};

const TONE_BG: Record<Tone, string> = {
    success: 'bg-status-success-bg text-status-success',
    warning: 'bg-status-warning-bg text-status-warning',
    critical: 'bg-status-critical-bg text-status-critical',
    neutral: 'bg-muted text-muted-foreground',
};
const TONE_DOT: Record<Tone, string> = {
    success: 'bg-status-success',
    warning: 'bg-status-warning',
    critical: 'bg-status-critical',
    neutral: 'bg-muted-foreground',
};

const STATUS: Record<string, { label: string; cls: string; icon: typeof Clock }> = {
    reported: { label: 'Awaiting triage', cls: 'bg-status-warning-bg text-status-warning', icon: ClipboardList },
    triaged: { label: 'Triaged', cls: 'bg-status-info-bg text-status-info', icon: ClipboardCheck },
    investigating: { label: 'Under investigation', cls: 'bg-primary/10 text-primary', icon: Search },
    action_plan: { label: 'Action plan', cls: 'bg-status-info-bg text-status-info', icon: ListTodo },
    monitoring: { label: 'Monitoring', cls: 'bg-status-success-bg text-status-success', icon: Activity },
    referred_external: { label: 'Referred external', cls: 'bg-status-critical-bg text-status-critical', icon: Landmark },
    closed: { label: 'Closed', cls: 'bg-status-success-bg text-status-success', icon: CheckCircle2 },
    no_action_required: { label: 'No further action', cls: 'bg-muted text-muted-foreground', icon: CircleSlash },
};

const CATEGORY_OPTIONS = [
    'physical',
    'sexual',
    'emotional',
    'psychological',
    'financial',
    'discriminatory',
    'institutional',
    'neglect',
    'self-neglect',
    'domestic_violence',
    'modern_slavery',
    'other',
];

function titleCase(s: string): string {
    return s.replace(/[_-]/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

/* date helpers (browser-local) */
const todayStr = () => {
    const d = new Date();
    return new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
};
const daysAgoStr = (n: number) => {
    const d = new Date();
    d.setDate(d.getDate() - n);
    return new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
};

/* ------------------------------------------------------------------ */
/*  Page                                                               */
/* ------------------------------------------------------------------ */

export default function SafeguardingIndex({
    filters,
    tab,
    tabCounts,
    rows,
    rowsKind,
    hero,
    referralOverdueCount,
    sites,
    subjects,
    can,
    detail,
}: Props) {
    const [ctx, setCtx] = useState<ShiftCtxState | null>(null);

    const go = (next: Partial<Filters>) =>
        router.get('/safeguarding', { ...filters, ...next }, { preserveState: true, preserveScroll: true, replace: true });

    const setTab = (id: string) => router.get('/safeguarding', { ...filters, tab: id }, { preserveScroll: true });

    // Detail-over-list: fetch only the `detail` prop and open the dialog without
    // navigating away; closing drops the param so `detail` comes back null.
    const openConcern = (id: number) =>
        router.get('/safeguarding', { ...filters, concern: id }, { preserveState: true, preserveScroll: true, only: ['detail'] });
    const closeDetail = () =>
        router.get('/safeguarding', { ...filters }, { preserveState: true, preserveScroll: true, only: ['detail'] });

    const clearFilters = () =>
        router.get('/safeguarding', { tab }, { preserveState: true, preserveScroll: true, replace: true });

    const hasFilters = !!(
        filters.q ||
        filters.severity ||
        filters.category ||
        filters.subject_id ||
        filters.site_id ||
        filters.from ||
        filters.to
    );

    const ow = hero.openWork;
    const at = hero.attention;

    const TABS: RosterTabItem[] = [
        { id: 'all', label: 'All', icon: LayoutList, tone: 'primary', badge: tabCounts.all || undefined },
        { id: 'triage', label: 'Awaiting triage', icon: ClipboardList, tone: 'warning', badge: tabCounts.triage || undefined },
        { id: 'investigation', label: 'Under investigation', icon: Search, tone: 'primary', badge: tabCounts.investigation || undefined },
        { id: 'action_plan', label: 'Action plan', icon: ListTodo, tone: 'info', badge: tabCounts.action_plan || undefined },
        { id: 'monitoring', label: 'Monitoring', icon: Activity, tone: 'success', badge: tabCounts.monitoring || undefined },
        { id: 'referrals', label: 'External referrals', icon: Landmark, tone: 'critical', badge: tabCounts.referrals || undefined },
        { id: 'reviews', label: 'Reviews due', icon: Clock, tone: 'warning', badge: tabCounts.reviews || undefined },
        { id: 'closed', label: 'Closed', icon: CheckCircle2, tone: 'success', badge: tabCounts.closed || undefined },
        { id: 'mine', label: 'Assigned to me', icon: UserCheck, tone: 'info', badge: tabCounts.mine || undefined },
    ];

    /* ---- date range (footer pills) ---- */
    const activeRange = !filters.from
        ? ''
        : filters.from === daysAgoStr(7)
          ? 'week'
          : filters.from === daysAgoStr(30)
            ? '30d'
            : filters.from === daysAgoStr(90)
              ? 'quarter'
              : 'custom';
    const RANGE_ITEMS = [
        { key: 'week', label: 'This week' },
        { key: '30d', label: '30 days' },
        { key: 'quarter', label: 'Quarter' },
    ];
    const onRange = (key: string) => {
        const map: Record<string, number> = { week: 7, '30d': 30, quarter: 90 };
        go({ from: daysAgoStr(map[key]), to: todayStr() });
    };

    const SEVERITY_ITEMS = [
        { key: 'all', label: 'All' },
        { key: 'low', label: 'Low' },
        { key: 'medium', label: 'Medium' },
        { key: 'high', label: 'High' },
        { key: 'critical', label: 'Critical' },
    ];

    /* ---- right-click context menu (concern rows) ---- */
    const openRowCtx = (e: ReactMouseEvent, c: ConcernRow) => {
        e.preventDefault();
        if (c.restricted) return; // need-to-know: no actions exposed on a restricted row
        const sev = SEV[c.severity] ?? SEV.low;
        const subjectName = c.subject?.name ?? 'Subject withheld';
        const items: ShiftCtxItem[] = [
            { icon: <Eye className="h-3.5 w-3.5" />, label: 'View concern', sub: c.reference_number, tone: 'primary', onClick: () => openConcern(c.id) },
            { sep: true },
            ...(c.subject ? [{ icon: <UserIcon className="h-3.5 w-3.5" />, label: 'View subject', sub: subjectName, onClick: () => openConcern(c.id) } satisfies ShiftCtxItem] : []),
            ...(c.related_incident_id ? [{ icon: <ShieldAlert className="h-3.5 w-3.5" />, label: 'View linked incident', sub: `INC-${c.related_incident_id}`, onClick: () => router.visit(`/incidents/${c.related_incident_id}`) } satisfies ShiftCtxItem] : []),
            ...(c.control_room_alert_id ? [{ icon: <RadioTower className="h-3.5 w-3.5" />, label: 'View Control Room alert', onClick: () => router.visit(`/control-room/alerts/${c.control_room_alert_id}`) } satisfies ShiftCtxItem] : []),
        ];
        setCtx({ x: e.clientX, y: e.clientY, tag: sev.label.toUpperCase(), meta: `${c.reference_number} · ${titleCase(c.concern_type)}`, items });
    };

    const concernRows = rowsKind === 'concerns' ? (rows.data as ConcernRow[]) : [];
    const reviewRows = rowsKind === 'reviews' ? (rows.data as ReviewRow[]) : [];

    return (
        <AppLayout breadcrumbs={[{ title: 'Health & Safety', href: '/health-safety' }, { title: 'Safeguarding', href: '/safeguarding' }]}>
            <Head title="Safeguarding" />

            <div className="flex flex-col gap-6 p-6">
                {/* ---- Hero ---- */}
                <HeroShell
                    footer={
                        <div className="flex flex-wrap items-center gap-x-4 gap-y-2">
                            <HeroSegmented label="Period" variant="pill" ariaLabel="Date range" items={RANGE_ITEMS} value={activeRange} onChange={onRange} />
                            {sites?.length ? (
                                <EntityFilter label="Site" allLabel="All sites" items={sites} value={filters.site_id} onChange={(id) => go({ site_id: id })} onDark />
                            ) : null}
                            {subjects?.length ? (
                                <EntityFilter label="Subject" allLabel="All subjects" items={subjects} value={filters.subject_id} onChange={(id) => go({ subject_id: id })} onDark />
                            ) : null}
                            <HeroSegmented
                                label="Severity"
                                variant="pill"
                                ariaLabel="Severity"
                                items={SEVERITY_ITEMS}
                                value={filters.severity ?? 'all'}
                                onChange={(key) => go({ severity: key === 'all' ? null : key })}
                            />
                            <label className="inline-flex items-center gap-1.5">
                                <span className="text-[11px] font-semibold tracking-wide text-primary-foreground/60 uppercase">Category</span>
                                <select
                                    value={filters.category ?? ''}
                                    onChange={(e) => go({ category: e.target.value || null })}
                                    className="rounded-lg border border-primary-foreground/20 bg-primary-foreground/10 px-2 py-1 text-xs text-primary-foreground focus-visible:ring-2 focus-visible:ring-primary-foreground/40 focus-visible:outline-none [&>option]:text-foreground"
                                >
                                    <option value="">All categories</option>
                                    {CATEGORY_OPTIONS.map((c) => (
                                        <option key={c} value={c}>
                                            {titleCase(c)}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <div className="relative ml-auto">
                                <Search className="pointer-events-none absolute top-1/2 left-2.5 h-3.5 w-3.5 -translate-y-1/2 text-primary-foreground/60" />
                                <input
                                    type="search"
                                    placeholder="Search concerns…"
                                    defaultValue={filters.q ?? ''}
                                    onKeyDown={(e) => {
                                        if (e.key === 'Enter') go({ q: (e.target as HTMLInputElement).value || null });
                                    }}
                                    className="w-48 rounded-lg border border-primary-foreground/20 bg-primary-foreground/10 py-1.5 pr-2.5 pl-8 text-xs text-primary-foreground placeholder:text-primary-foreground/50 focus-visible:ring-2 focus-visible:ring-primary-foreground/40 focus-visible:outline-none"
                                />
                            </div>
                            {hasFilters ? (
                                // eslint-disable-next-line no-restricted-syntax -- onDark clear affordance on the hero footer
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
                    <div className="flex flex-wrap items-start justify-between gap-4">
                        <div className="flex items-start gap-4">
                            <HeroMedallion icon={ShieldAlert} />
                            <div className="flex flex-col gap-1.5">
                                <HeroStatusPill>Safeguarding register · need-to-know</HeroStatusPill>
                                <h1 className="text-2xl font-bold tracking-tight text-primary-foreground md:text-[28px]">Safeguarding</h1>
                                <p className="max-w-xl text-sm text-primary-foreground/70">
                                    Protecting people at risk of abuse, neglect or harm. Every concern is triaged, risk-assessed and worked through to a safe close — confidential, blame-free, and visible only to those who need to know.
                                </p>
                            </div>
                        </div>

                        {can.create ? (
                            <Button size="sm" onClick={() => router.visit('/safeguarding/create')} className="bg-primary-foreground text-primary hover:bg-primary-foreground/90">
                                <Plus className="mr-1.5 h-4 w-4" /> Raise concern
                            </Button>
                        ) : null}
                    </div>

                    {/* counts-only stat clusters (never subject identities) */}
                    <div className="grid gap-3 lg:grid-cols-2">
                        <HeroCluster title="Open work" icon={Shield}>
                            <HeroClusterTile href="/safeguarding?tab=all" label="Open" value={fmt(ow.open.value)} caption="not yet closed" tone="neutral" />
                            <HeroClusterTile href="/safeguarding?tab=triage" label="Awaiting triage" value={fmt(ow.awaitingTriage.value)} caption="needs triage" tone={ow.awaitingTriage.value > 0 ? 'warning' : 'success'} />
                            <HeroClusterTile href="/safeguarding?tab=investigation" label="Under investigation" value={fmt(ow.investigating.value)} caption="in progress" tone="neutral" />
                            <HeroClusterTile href="/safeguarding?tab=referrals" label="Referred external" value={fmt(ow.referred.value)} caption="with an authority" tone={ow.referred.value > 0 ? 'critical' : 'neutral'} />
                        </HeroCluster>
                        <HeroCluster title="Needs attention" icon={Bell}>
                            <HeroClusterTile href="/safeguarding?tab=action_plan" label="Overdue actions" value={fmt(at.overdueActions.value)} caption={at.overdueActions.value > 0 ? 'past due' : 'all on track'} tone={at.overdueActions.value > 0 ? 'critical' : 'success'} />
                            <HeroClusterTile href="/safeguarding?tab=reviews" label="Risk reviews due" value={fmt(at.reviewsDue.value)} caption="review now" tone={at.reviewsDue.value > 0 ? 'warning' : 'success'} />
                            <HeroClusterTile href="/safeguarding?tab=reviews" label="Acks awaited" value={fmt(at.acksAwaited.value)} caption="from authorities" tone={at.acksAwaited.value > 0 ? 'warning' : 'success'} />
                            <HeroClusterTile label="Critical · open" value={fmt(at.criticalOpen.value)} caption="highest risk" tone={at.criticalOpen.value > 0 ? 'critical' : 'success'} />
                        </HeroCluster>
                    </div>
                </HeroShell>

                {/* ---- Tabs ---- */}
                <TabStrip value={tab} onChange={setTab} items={TABS} ariaLabel="Safeguarding views" />

                {/* ---- External-referral banner ---- */}
                {referralOverdueCount > 0 && (tab === 'all' || tab === 'referrals') ? (
                    <div className="flex items-center gap-3 rounded-xl border border-status-critical/30 bg-status-critical-bg/50 px-4 py-3">
                        <Landmark className="h-5 w-5 shrink-0 text-status-critical" />
                        <p className="text-sm text-foreground">
                            <b>
                                {referralOverdueCount} concern{referralOverdueCount === 1 ? '' : 's'} flagged for external referral with no report logged.
                            </b>{' '}
                            A referral was indicated at triage but no authority has been notified — log the report to advance the concern.
                        </p>
                    </div>
                ) : null}

                {/* ---- Rows ---- */}
                <Card>
                    <CardContent className="p-0">
                        {rowsKind === 'concerns' ? (
                            <ConcernTable rows={concernRows} onRowCtx={openRowCtx} onOpen={openConcern} />
                        ) : (
                            <ReviewTable rows={reviewRows} onOpen={openConcern} />
                        )}
                    </CardContent>
                </Card>

                {rows.last_page > 1 ? <LaravelPagination links={rows.links} /> : null}
            </div>

            {ctx ? <ShiftContextMenu ctx={ctx} onClose={() => setCtx(null)} /> : null}

            {detail ? <SafeguardingConcernDialog detail={detail} open onClose={closeDetail} /> : null}
        </AppLayout>
    );
}

/* ------------------------------------------------------------------ */
/*  Concern table                                                      */
/* ------------------------------------------------------------------ */

function ConcernTable({
    rows,
    onRowCtx,
    onOpen,
}: {
    rows: ConcernRow[];
    onRowCtx: (e: ReactMouseEvent, c: ConcernRow) => void;
    onOpen: (id: number) => void;
}) {
    if (!rows.length) {
        return (
            <div className="px-4 py-16 text-center">
                <Shield className="mx-auto mb-3 h-10 w-10 text-muted-foreground/40" />
                <p className="font-medium text-muted-foreground">No concerns here</p>
                <p className="mt-1 text-sm text-muted-foreground/70">Nothing matches this tab and filters.</p>
            </div>
        );
    }
    return (
        <div className="overflow-x-auto">
            <table className="w-full text-sm">
                <thead>
                    <tr className="border-b text-left text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">
                        <th className="px-4 py-2.5">Reference</th>
                        <th className="px-4 py-2.5">Concern</th>
                        <th className="px-4 py-2.5">Subject</th>
                        <th className="px-4 py-2.5">Severity</th>
                        <th className="px-4 py-2.5">Stage</th>
                        <th className="px-4 py-2.5">Assigned</th>
                        <th className="px-4 py-2.5">Flags</th>
                    </tr>
                </thead>
                <tbody className="divide-y">
                    {rows.map((c) => {
                        const sev = SEV[c.severity] ?? SEV.low;
                        const stat = STATUS[c.status] ?? STATUS.reported;
                        const StatusIcon = stat.icon;
                        return (
                            <tr
                                key={c.id}
                                onClick={() => (c.restricted ? undefined : onOpen(c.id))}
                                onContextMenu={(e) => onRowCtx(e, c)}
                                className={
                                    c.restricted
                                        ? 'bg-[repeating-linear-gradient(45deg,transparent,transparent_9px,var(--muted)_9px,var(--muted)_10px)]'
                                        : 'cursor-pointer transition-colors hover:bg-muted/40'
                                }
                            >
                                <td className="px-4 py-3 align-top whitespace-nowrap">
                                    <div className="font-medium">{c.reference_number}</div>
                                    <div className="mt-0.5 flex items-center gap-1 text-[11px] text-muted-foreground/70">
                                        <Calendar className="h-3 w-3" />
                                        {c.occurred_at ? formatDateTime(c.occurred_at) : '—'}
                                    </div>
                                </td>
                                {c.restricted ? (
                                    <td className="px-4 py-3 align-top" colSpan={2}>
                                        <div className="flex items-center gap-2 text-muted-foreground">
                                            <Lock className="h-4 w-4" />
                                            <span className="text-sm font-medium">Restricted · need-to-know</span>
                                        </div>
                                        <p className="mt-0.5 text-xs text-muted-foreground/70">Visible to the assigned lead and reporter only.</p>
                                    </td>
                                ) : (
                                    <>
                                        <td className="px-4 py-3 align-top">
                                            <div className="flex items-center gap-2">
                                                <span className={`h-2 w-2 shrink-0 rounded-full ${TONE_DOT[sev.tone]}`} />
                                                <span className="font-medium">{titleCase(c.concern_type)}</span>
                                            </div>
                                            {c.abuse_category ? <p className="mt-0.5 text-xs text-muted-foreground">{titleCase(c.abuse_category)}</p> : null}
                                        </td>
                                        <td className="px-4 py-3 align-top">
                                            {c.subject?.name ? (
                                                <div className="flex items-center gap-2">
                                                    <span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-primary/10 text-[11px] font-semibold text-primary">
                                                        {c.subject.name
                                                            .split(' ')
                                                            .map((n) => n[0])
                                                            .slice(0, 2)
                                                            .join('')}
                                                    </span>
                                                    <span className="min-w-0">
                                                        <span className="block truncate font-medium">{c.subject.name}</span>
                                                        {c.subject.site ? <span className="block truncate text-xs text-muted-foreground">{c.subject.site}</span> : null}
                                                    </span>
                                                </div>
                                            ) : (
                                                <span className="text-xs text-muted-foreground">—</span>
                                            )}
                                        </td>
                                    </>
                                )}
                                <td className="px-4 py-3 align-top">
                                    <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium ${TONE_BG[sev.tone]}`}>{sev.label}</span>
                                </td>
                                <td className="px-4 py-3 align-top">
                                    <span className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-medium ${stat.cls}`}>
                                        <StatusIcon className="h-3 w-3" />
                                        {stat.label}
                                    </span>
                                </td>
                                <td className="px-4 py-3 align-top">
                                    {c.assigned_to ? (
                                        <span className="text-xs text-foreground">{c.assigned_to.name}</span>
                                    ) : (
                                        <span className="text-xs text-muted-foreground">Unassigned</span>
                                    )}
                                </td>
                                <td className="px-4 py-3 align-top">
                                    <div className="flex items-center gap-1.5 text-muted-foreground">
                                        {c.flags.has_alert ? <RadioTower className="h-3.5 w-3.5 text-status-info" aria-label="Active alert" /> : null}
                                        {c.flags.referral_overdue ? (
                                            <span className="inline-flex h-5 w-5 items-center justify-center rounded-full ring-2 ring-status-critical" aria-label="Referral indicated, none logged">
                                                <Landmark className="h-3 w-3 text-status-critical" />
                                            </span>
                                        ) : c.flags.requires_referral ? (
                                            <Landmark className="h-3.5 w-3.5 text-status-warning" aria-label="External referral" />
                                        ) : null}
                                        {c.flags.review_due ? <Clock className="h-3.5 w-3.5 text-status-warning" aria-label="Risk review due" /> : null}
                                        {c.flags.action_overdue ? <ListTodo className="h-3.5 w-3.5 text-status-critical" aria-label="Overdue action" /> : null}
                                    </div>
                                </td>
                            </tr>
                        );
                    })}
                </tbody>
            </table>
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Reviews / monitoring worklist                                      */
/* ------------------------------------------------------------------ */

function ReviewTable({ rows, onOpen }: { rows: ReviewRow[]; onOpen: (id: number) => void }) {
    if (!rows.length) {
        return (
            <div className="px-4 py-16 text-center">
                <CheckCircle2 className="mx-auto mb-3 h-10 w-10 text-status-success/50" />
                <p className="font-medium text-muted-foreground">Nothing to review</p>
                <p className="mt-1 text-sm text-muted-foreground/70">No risk reviews are due and every authority has acknowledged.</p>
            </div>
        );
    }
    return (
        <div className="overflow-x-auto">
            <table className="w-full text-sm">
                <thead>
                    <tr className="border-b text-left text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">
                        <th className="px-4 py-2.5">Reference</th>
                        <th className="px-4 py-2.5">What's due</th>
                        <th className="px-4 py-2.5">Subject</th>
                        <th className="px-4 py-2.5">Due</th>
                        <th className="px-4 py-2.5"></th>
                    </tr>
                </thead>
                <tbody className="divide-y">
                    {rows.map((r, i) => (
                        <tr key={`${r.id}-${r.kind}-${i}`} onClick={() => onOpen(r.id)} className="cursor-pointer transition-colors hover:bg-muted/40">
                            <td className="px-4 py-3 align-top font-medium whitespace-nowrap">{r.reference_number}</td>
                            <td className="px-4 py-3 align-top">
                                <div className="flex items-start gap-2">
                                    {r.kind === 'risk' ? (
                                        <Clock className="mt-0.5 h-3.5 w-3.5 shrink-0 text-status-warning" />
                                    ) : (
                                        <Landmark className="mt-0.5 h-3.5 w-3.5 shrink-0 text-status-info" />
                                    )}
                                    <span>{r.detail}</span>
                                </div>
                            </td>
                            <td className="px-4 py-3 align-top">
                                {r.restricted ? (
                                    <span className="inline-flex items-center gap-1 text-xs text-muted-foreground">
                                        <Lock className="h-3 w-3" /> Restricted
                                    </span>
                                ) : (
                                    r.subject ?? '—'
                                )}
                            </td>
                            <td className="px-4 py-3 align-top whitespace-nowrap">
                                {r.due_at ? (
                                    <span className={`inline-flex items-center gap-1 text-xs ${r.overdue ? 'font-semibold text-status-critical' : 'text-muted-foreground'}`}>
                                        <Clock className="h-3.5 w-3.5" />
                                        {formatDateTime(r.due_at)}
                                        {r.overdue ? ' · overdue' : ''}
                                    </span>
                                ) : (
                                    <span className="text-xs text-muted-foreground">—</span>
                                )}
                            </td>
                            <td className="px-4 py-3 align-top text-right">
                                <span className="inline-flex items-center gap-1 text-xs text-primary">
                                    <Eye className="h-3.5 w-3.5" /> Open
                                </span>
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
