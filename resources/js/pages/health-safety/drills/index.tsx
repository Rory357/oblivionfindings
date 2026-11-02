/**
 * Emergency drills (board G4-12, approved by Stephan 1 Nov 2026): the G2 header — four boxes, then one line with the
 * Risks and prevention pages, the site filter, the period and Find — and the drill views as tier-two tabs (the bar
 * stays under the picked tab, decision 8). Then one lead block ("Needs doing now": overdue planned drills and overdue
 * things to fix, most overdue first, only the first button filled), the quiet rows beside it, and every drill with
 * "Where it's up to" as the shared stage cell (a status chip and who does it). "Plan a drill" is the kit's 720 pop-up
 * with the approved calendar and clock pickers.
 */
import { DrillPlanningForm } from '@/components/health-safety/drills/drill-planning-form';
import type { DrillPickers } from '@/components/health-safety/drills/types';
import {
    LeadBlock,
    QuietRows,
    RegisterBlocks,
    RegisterBoxes,
    RegisterMoreFilters,
    RegisterRailLine,
    overdueNote,
    shortDay,
    type LeadRow,
} from '@/components/health-safety/register-kit';
import { SafetyWorkspaceRail } from '@/components/health-safety/workspace-header';
import {
    RecordIdentity,
    RecordTable,
    type RecordTableColumn,
} from '@/components/incidents/record-table';
import { compactMenu, type MenuItem } from '@/components/lists/entity-menu';
import {
    PageHeader,
    PageHeaderFilterButton,
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
    PageHeaderPrimaryButton,
    PageHeaderSearch,
    PageLayout,
} from '@/components/page';
import {
    TierTwoTabs,
    type GroupedProfileNavTab,
} from '@/components/page/grouped-profile-nav';
import { Ring } from '@/components/signature/blocks';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { StatusBadge } from '@/components/ui/status-badge';
import {
    SafetyFormDraftContext,
    useSafetyWorkspaceDrafts,
} from '@/hooks/use-safety-workspace-drafts';
import AppLayout from '@/layouts/app-layout';
import { formatDate, toDateInput } from '@/lib/datetime';
import {
    DRILL_REGISTER,
    drillHref,
    drillReturn,
} from '@/lib/health-safety/drill-navigation';
import { safetyHubCrumbs } from '@/lib/health-safety/workspace-navigation';
import {
    DRILL_TYPE_OPTIONS,
    drillWhereUpTo,
    outcomeMeta,
    typeMeta,
    type DrillFilters,
    type DrillHero,
    type DrillRow,
} from '@/pages/health-safety/drills/shared';
import type { SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    BarChart3,
    CalendarDays,
    CheckCircle2,
    Clock,
    Copy,
    Eye,
    FileText,
    Home,
    LayoutList,
    Plus,
    RefreshCw,
    Siren,
    X,
} from 'lucide-react';
import { useEffect, useState } from 'react';

type Row = DrillRow & { attended_count: number; href: string };

/** G4-12: the header counts and the lead block for the selected Site, whatever the period or view. */
export type DrillLeadRow =
    | { kind: 'drill'; id: number; title: string; site: string | null; site_id: number | null; due: string | null; last_run_at: string | null }
    | { kind: 'finding'; id: number; drill_id: number; title: string | null; site: string | null; drill_type: string | null; drill_on: string | null; owner: string | null; due: string | null };
export type DrillRegisterSummary = {
    boxes: { things_to_fix_overdue: number; evacuation_reviews_due: number; drills_run_90d: number; sites_drilled_90d: number };
    lead: DrillLeadRow[];
    more_overdue: { count: number; sites: string[] };
};

type Props = {
    drills: {
        data: Row[];
        links: { url: string | null; label: string; active: boolean }[];
        last_page: number;
        total: number;
        from: number | null;
        to: number | null;
    };
    filters: DrillFilters;
    tab: string;
    tabCounts: Record<string, number>;
    hero: DrillHero;
    pickers: DrillPickers;
    can: { manage: boolean; schedule: boolean };
    exports: { register_href: string };
    register_summary?: DrillRegisterSummary;
};

const VIEWS: {
    key: string;
    label: string;
    icon: GroupedProfileNavTab['icon'];
}[] = [
    { key: 'all', label: 'All', icon: LayoutList },
    { key: 'scheduled', label: 'Planned', icon: CalendarDays },
    { key: 'overdue', label: 'Overdue', icon: Clock },
    { key: 'in_progress', label: 'In progress', icon: RefreshCw },
    { key: 'completed', label: 'Completed', icon: CheckCircle2 },
    { key: 'findings', label: 'Things to fix', icon: AlertTriangle },
];

const PERIODS = [
    { value: '30d', label: 'last 30 days' },
    { value: 'quarter', label: 'last 90 days' },
    { value: '6mo', label: 'last 6 months' },
    { value: 'all', label: 'any time' },
];

/** "Thu 12 Nov" — a drill's day on the worker's NZ calendar ("Due 14 Jul" while it's overdue). */
function drillDay(iso: string): string {
    const day = toDateInput(iso);
    const weekday = new Date(`${day}T00:00:00Z`).toLocaleDateString('en-NZ', { weekday: 'short', timeZone: 'UTC' });
    return `${weekday} ${formatDate(iso)}`;
}

/** The lead block's rows (G4-12): overdue planned drills and overdue things to fix, oldest due date first. */
export function drillLeadRows(
    rows: DrillLeadRow[],
    options: { canPlan: boolean; returnTo: string; onPlan: (siteId: number | null) => void },
): LeadRow[] {
    return rows.map((row): LeadRow => {
        if (row.kind === 'finding') {
            const title = row.title ?? 'Thing to fix';
            const drill = row.drill_type ? typeMeta(row.drill_type === 'fire' ? 'fire_evacuation' : row.drill_type).label.toLowerCase() : null;
            return {
                key: `finding-${row.id}`,
                when: row.due ? { day: shortDay(row.due), note: overdueNote(row.due) } : null,
                title,
                sub: [row.site, drill ? `from the ${drill} drill${row.drill_on ? ` on ${shortDay(row.drill_on)}` : ''}` : null, row.owner ? `owner ${row.owner}` : 'No owner yet']
                    .filter(Boolean).join(' · '),
                chip: { label: 'Thing to fix overdue', tone: 'critical' },
                action: { label: 'Open', href: drillHref(row.drill_id, { section: 'findings', returnTo: options.returnTo }), ariaLabel: `Open: ${title}` },
            };
        }
        return {
            key: `drill-${row.id}`,
            when: row.due ? { day: shortDay(row.due), note: overdueNote(row.due) } : null,
            title: row.title,
            sub: [row.site, row.last_run_at ? `not run since ${shortDay(toDateInput(row.last_run_at))}` : 'no drill run yet'].filter(Boolean).join(' · '),
            chip: { label: 'Overdue', tone: 'critical' },
            action: options.canPlan ? { label: 'Plan a drill', onClick: () => options.onPlan(row.site_id), ariaLabel: `Plan a drill: ${row.title}` } : null,
            who: options.canPlan ? null : 'Health and safety plan it',
        };
    });
}

export default function DrillsIndex({
    drills,
    filters,
    tab,
    tabCounts,
    pickers,
    can,
    exports,
    register_summary: summary,
}: Props) {
    const { url } = usePage();
    const safetyCan = usePage<SharedData>().props.auth.can;
    const returnTo = drillReturn(url);
    const drafts = useSafetyWorkspaceDrafts();
    const [search, setSearch] = useState(filters.q ?? '');
    const [planSite, setPlanSite] = useState<number | null>(null);
    const [creating, setCreating] = useState(
        new URLSearchParams(url.split('?')[1]).get('schedule') === '1' &&
            can.schedule,
    );
    useEffect(() => setSearch(filters.q ?? ''), [filters.q]);
    useEffect(
        () =>
            setCreating(
                new URLSearchParams(url.split('?')[1]).get('schedule') ===
                    '1' && can.schedule,
            ),
        [url, can.schedule],
    );
    const params = (next: Partial<DrillFilters> = {}) =>
        Object.fromEntries(
            Object.entries({ ...filters, ...next }).filter(
                ([, value]) => value != null && value !== '',
            ),
        ) as Record<string, string | number>;
    const go = (next: Partial<DrillFilters>) =>
        router.get(DRILL_REGISTER, params(next), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    // Header and quiet-row links keep the Site only, as the header counts do.
    const siteLink = (next: Record<string, string> = {}) => {
        const query = new URLSearchParams(next);
        if (filters.site_id) query.set('site_id', String(filters.site_id));
        return DRILL_REGISTER + (query.size ? `?${query}` : '');
    };
    const openCreation = (siteId: number | null = null) =>
        drafts.request(() =>
            router.replace({
                url:
                    returnTo +
                    (returnTo.includes('?') ? '&' : '?') +
                    'schedule=1',
                preserveState: true,
                preserveScroll: true,
                onSuccess: () => {
                    setPlanSite(siteId);
                    setCreating(true);
                },
            }),
        );
    const closeCreation = () =>
        router.replace({
            url: returnTo,
            preserveState: true,
            preserveScroll: true,
            onSuccess: () => setCreating(false),
        });
    const views: GroupedProfileNavTab[] = VIEWS.map((view) => ({
        key: view.key,
        label: view.label,
        icon: view.icon,
        count: tabCounts[view.key] ?? 0,
    }));
    const filtered = !!(
        filters.q ||
        filters.site_id ||
        filters.drill_type ||
        filters.outcome ||
        filters.period !== '6mo'
    );

    const open = (row: Row, section?: 'findings') =>
        router.visit(drillHref(row.id, { section, returnTo }));
    const actionsFor = (row: Row): MenuItem[] =>
        compactMenu([
            { label: 'Open drill', icon: Eye, onClick: () => open(row) },
            {
                label: 'Open things to fix',
                icon: AlertTriangle,
                onClick: () => open(row, 'findings'),
            },
            { separator: true },
            {
                label: 'Copy link',
                icon: Copy,
                onClick: () =>
                    void navigator.clipboard?.writeText(
                        window.location.origin + drillHref(row.id),
                    ),
            },
        ]);

    const columns: RecordTableColumn<Row>[] = [
        {
            key: 'drill',
            label: 'Drill',
            className: 'min-w-[14rem] max-w-[20rem]',
            cell: (row) => (
                <RecordIdentity
                    title={
                        <Link
                            href={drillHref(row.id, { returnTo })}
                            onClick={(event) => event.stopPropagation()}
                            className="rounded-sm underline-offset-4 hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        >
                            {row.title}
                        </Link>
                    }
                    sub={`${row.reference} · ${row.type_label}`}
                />
            ),
        },
        {
            key: 'site',
            label: 'Site',
            nowrap: true,
            cell: (row) =>
                row.site?.name ?? (
                    <span className="text-muted-foreground">Not recorded</span>
                ),
        },
        {
            key: 'date',
            label: 'Date',
            nowrap: true,
            cell: (row) =>
                row.scheduled_at
                    ? row.status === 'overdue'
                        ? `Due ${formatDate(row.scheduled_at)}`
                        : drillDay(row.scheduled_at)
                    : <span className="text-muted-foreground">Not recorded</span>,
        },
        {
            key: 'stage',
            label: 'Where it’s up to',
            nowrap: true,
            cell: (row) => {
                const stage = drillWhereUpTo(row, (iso) => shortDay(toDateInput(iso)));
                return (
                    <span className="inline-grid justify-items-start gap-1">
                        <StatusBadge variant={stage.tone}>{stage.label}</StatusBadge>
                        {stage.who ? <span className="text-caption">{stage.who}</span> : null}
                    </span>
                );
            },
        },
        {
            key: 'outcome',
            label: 'Outcome',
            nowrap: true,
            cell: (row) => {
                const outcome = outcomeMeta(row.outcome);
                return outcome ? (
                    <StatusBadge variant={outcome.tone}>
                        {outcome.label}
                    </StatusBadge>
                ) : (
                    <span className="text-muted-foreground">—</span>
                );
            },
        },
        {
            key: 'attendance',
            label: 'Attendance',
            nowrap: true,
            cell: (row) =>
                row.raw_status === 'completed' || row.attended_count > 0
                    ? `${row.attended_count} confirmed`
                    : <span className="text-muted-foreground">—</span>,
        },
        {
            key: 'findings',
            label: 'Things to fix',
            nowrap: true,
            cell: (row) => (
                <Link
                    href={drillHref(row.id, { section: 'findings', returnTo })}
                    onClick={(event) => event.stopPropagation()}
                    aria-label={`${row.findings_open} things to fix for ${row.reference}${row.flags.finding_overdue ? ', overdue' : ''}`}
                    className="rounded-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                >
                    {row.findings_open > 0 ? (
                        <StatusBadge variant={row.flags.finding_overdue ? 'critical' : 'warning'}>
                            {row.flags.finding_overdue ? `${row.findings_open} · overdue` : `${row.findings_open} open`}
                        </StatusBadge>
                    ) : (
                        <span className="text-foreground underline-offset-4 hover:underline">
                            None
                        </span>
                    )}
                </Link>
            ),
        },
    ];

    const pager = (
        <>
            <p className="text-subtle">
                {drills.total
                    ? `Showing ${drills.from ?? 1}–${drills.to ?? drills.data.length} of ${drills.total}`
                    : 'Showing 0 of 0'}
            </p>
            {drills.last_page > 1 ? (
                <LaravelPagination
                    links={drills.links}
                    lastPage={drills.last_page}
                    preserveScroll
                />
            ) : null}
        </>
    );
    const lead = summary
        ? drillLeadRows(summary.lead, { canPlan: can.schedule, returnTo, onPlan: (siteId) => openCreation(siteId) })
        : [];
    const more = summary?.more_overdue;
    const period = PERIODS.find((item) => item.value === filters.period) ?? PERIODS[2];

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                ...safetyHubCrumbs('prevention', safetyCan),
                { title: 'Emergency drills', href: DRILL_REGISTER },
            ]}
        >
            <Head title="Emergency drills" />
            <SafetyFormDraftContext.Provider value={drafts.register}>
                <PageLayout
                    hero={
                        <PageHeader
                            wrapTitle
                            icon={Siren}
                            title="Emergency drills"
                            subline="Plan drills, record how they went and fix what they find"
                            actions={
                                <>
                                    <PageHeaderSearch
                                        value={search}
                                        onChange={setSearch}
                                        onKeyDown={(event) => {
                                            if (event.key === 'Enter')
                                                go({
                                                    q: search.trim() || null,
                                                });
                                        }}
                                        placeholder="Search drills"
                                        ariaLabel="Search drills"
                                    />
                                    <PageHeaderGlassButton asChild>
                                        <a href={exports.register_href}>
                                            Export
                                        </a>
                                    </PageHeaderGlassButton>
                                    {can.schedule && (
                                        <PageHeaderPrimaryButton
                                            icon={Plus}
                                            onClick={() => openCreation()}
                                        >
                                            Plan a drill
                                        </PageHeaderPrimaryButton>
                                    )}
                                </>
                            }
                            strip={
                                summary ? (
                                    <RegisterBoxes
                                        boxes={[
                                            { key: 'things_to_fix_overdue', label: 'Things to fix overdue', value: summary.boxes.things_to_fix_overdue, alert: true, icon: Clock, href: siteLink({ tab: 'findings', period: 'all' }) },
                                            { key: 'evacuation_reviews_due', label: 'Evacuation-scheme reviews due', value: summary.boxes.evacuation_reviews_due, icon: FileText, href: siteLink() },
                                            { key: 'drills_run_90d', label: 'Drills run · last 90 days', value: summary.boxes.drills_run_90d, icon: CheckCircle2, href: siteLink({ tab: 'completed', period: 'quarter' }) },
                                            { key: 'sites_drilled_90d', label: 'Sites with a drill · last 90 days', value: summary.boxes.sites_drilled_90d, icon: Home, href: siteLink({ tab: 'completed', period: 'quarter' }) },
                                        ]}
                                    />
                                ) : undefined
                            }
                            rail={
                                <RegisterRailLine
                                    rail={<SafetyWorkspaceRail hub="prevention" current="drills" />}
                                    filters={
                                        <>
                                            <PageHeaderFilterSelect
                                                label={`All sites · ${pickers.sites.length}`}
                                                allValue=""
                                                value={filters.site_id ? String(filters.site_id) : ''}
                                                onChange={(value) => go({ site_id: value ? Number(value) : null })}
                                                options={[
                                                    { value: '', label: 'All sites' },
                                                    ...pickers.sites.map((site) => ({ value: String(site.id), label: site.name })),
                                                ]}
                                            />
                                            <PageHeaderFilterSelect
                                                icon={CalendarDays}
                                                label={`Period: ${period.label}`}
                                                allValue="6mo"
                                                value={filters.period}
                                                onChange={(value) => go({ period: value })}
                                                options={PERIODS.map((item) => ({ value: item.value, label: `Period: ${item.label}` }))}
                                            />
                                            <RegisterMoreFilters
                                                groups={[
                                                    {
                                                        key: 'type',
                                                        label: 'Drill type',
                                                        value: filters.drill_type ?? '',
                                                        allValue: '',
                                                        options: [{ value: '', label: 'All drill types' }, ...DRILL_TYPE_OPTIONS],
                                                        onChange: (value) => go({ drill_type: value || null }),
                                                    },
                                                    {
                                                        key: 'outcome',
                                                        label: 'Outcome',
                                                        value: filters.outcome ?? '',
                                                        allValue: '',
                                                        options: [
                                                            { value: '', label: 'All outcomes' },
                                                            ...pickers.outcomes.map((value) => ({ value, label: outcomeMeta(value)?.label ?? value })),
                                                        ],
                                                        onChange: (value) => go({ outcome: value || null }),
                                                    },
                                                ]}
                                                onClear={() => go({ drill_type: null, outcome: null })}
                                            />
                                            {filtered && (
                                                <PageHeaderFilterButton
                                                    icon={X}
                                                    onClick={() =>
                                                        go({ q: null, site_id: null, drill_type: null, outcome: null, period: '6mo' })
                                                    }
                                                >
                                                    Clear filters
                                                </PageHeaderFilterButton>
                                            )}
                                        </>
                                    }
                                />
                            }
                        />
                    }
                    tabs={
                        <TierTwoTabs
                            tabs={views}
                            activeTab={tab}
                            onTab={(value) => {
                                if (value !== tab) go({ tab: value });
                            }}
                            testIdPrefix="drill-register"
                            ariaLabel="Drill views"
                            panelId="drill-register-panel"
                            // Views carry no href, so the strip renders its own buttons.
                            renderLink={() => null}
                        />
                    }
                >
                    <div
                        id="drill-register-panel"
                        role="tabpanel"
                        aria-label={
                            VIEWS.find((view) => view.key === tab)?.label ??
                            'Drills'
                        }
                        className="grid min-w-0 gap-5"
                    >
                        {summary ? (
                            <RegisterBlocks
                                lead={
                                    <LeadBlock
                                        rows={lead}
                                        empty="No drills or things to fix are overdue."
                                        more={
                                            more && more.count > 0
                                                ? {
                                                      text: `${more.count} more ${more.count === 1 ? 'drill is' : 'drills are'} overdue${more.sites.length ? ` (${more.sites.join(', ')})` : ''}`,
                                                      action: { label: 'See all', href: siteLink({ tab: 'overdue', period: 'all' }) },
                                                  }
                                                : null
                                        }
                                    />
                                }
                                side={
                                    <QuietRows
                                        rows={[
                                            { key: 'trends', icon: BarChart3, title: 'Drill trends', sub: 'Drills run and how they went, by site', href: '/health-safety/analytics' },
                                        ]}
                                    />
                                }
                            />
                        ) : null}
                        <RecordTable
                            title="Drills"
                            sub="Newest first"
                            ariaLabel="Drills table"
                            rows={drills.data}
                            rowKey={(row) => row.id}
                            onOpen={(row) => open(row)}
                            actionsFor={actionsFor}
                            menuTitle={(row) => `${row.reference} · ${row.title}`}
                            menuIcon={Siren}
                            columns={columns}
                            footer={drills.data.length ? pager : undefined}
                            empty={
                                <p className="sg-quiet">
                                    <Ring tone="none" />
                                    <span>No drills match these filters.</span>
                                </p>
                            }
                        />
                    </div>
                </PageLayout>
                {creating && can.schedule && (
                    <DrillPlanningForm
                        popup
                        detail={null}
                        pickers={pickers}
                        mode="create"
                        returnTo={returnTo}
                        initialSiteId={planSite ?? filters.site_id ?? null}
                        onSaved={() => setCreating(false)}
                        onCancel={closeCreation}
                    />
                )}
                {drafts.confirmation}
            </SafetyFormDraftContext.Provider>
        </AppLayout>
    );
}
