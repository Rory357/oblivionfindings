/**
 * Emergency drills (approved H&S drawings pg-hsn-65, 65b — Stephan 23 Oct
 * 2026): one header with four filter chips, the drill views as a tier-two
 * strip, the planning form above the register when scheduling, then every
 * drill by name with where it is up to as the record's stages (scheduled,
 * run, completed, findings resolved) and the follow-up tally.
 */
import { DrillPlanningForm } from '@/components/health-safety/drills/drill-planning-form';
import type { DrillPickers } from '@/components/health-safety/drills/types';
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
import { Ring, StageDots, Surface, Tally } from '@/components/signature/blocks';
import { Button } from '@/components/ui/button';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { StatusBadge } from '@/components/ui/status-badge';
import {
    SafetyFormDraftContext,
    useSafetyWorkspaceDrafts,
} from '@/hooks/use-safety-workspace-drafts';
import AppLayout from '@/layouts/app-layout';
import { formatDate, formatTime, toDateInput } from '@/lib/datetime';
import {
    DRILL_REGISTER,
    drillHref,
    drillReturn,
} from '@/lib/health-safety/drill-navigation';
import {
    DRILL_TYPE_OPTIONS,
    drillStage,
    outcomeMeta,
    type DrillFilters,
    type DrillHero,
    type DrillRow,
} from '@/pages/health-safety/drills/shared';
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    AlarmClock,
    CalendarClock,
    CheckCircle2,
    ClipboardList,
    Copy,
    Eye,
    LayoutList,
    Play,
    Plus,
    Siren,
    X,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

type Row = DrillRow & { attended_count: number; href: string };
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
};

const VIEWS: {
    key: string;
    label: string;
    icon: GroupedProfileNavTab['icon'];
}[] = [
    { key: 'all', label: 'All', icon: LayoutList },
    { key: 'scheduled', label: 'Scheduled', icon: CalendarClock },
    { key: 'overdue', label: 'Overdue', icon: AlarmClock },
    { key: 'in_progress', label: 'In progress', icon: Play },
    { key: 'completed', label: 'Completed', icon: CheckCircle2 },
    { key: 'findings', label: 'Findings open', icon: ClipboardList },
];

/** "Thu 22 Oct 2026" — the drill's day, on the worker's NZ calendar. */
function drillDay(iso: string): string {
    return `${formatDate(iso)} ${toDateInput(iso).slice(0, 4)}`;
}

export default function DrillsIndex({
    drills,
    filters,
    tab,
    tabCounts,
    hero,
    pickers,
    can,
    exports,
}: Props) {
    const { url } = usePage();
    const returnTo = drillReturn(url);
    const drafts = useSafetyWorkspaceDrafts();
    const creationPanel = useRef<HTMLDivElement>(null);
    const [search, setSearch] = useState(filters.q ?? '');
    const [creating, setCreating] = useState(
        new URLSearchParams(url.split('?')[1]).get('schedule') === '1' &&
            can.schedule,
    );
    useEffect(() => {
        if (creating) {
            creationPanel.current?.focus();
            creationPanel.current?.scrollIntoView({ block: 'nearest' });
        }
    }, [creating]);
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
    const openCreation = () =>
        drafts.request(() =>
            router.replace({
                url:
                    returnTo +
                    (returnTo.includes('?') ? '&' : '?') +
                    'schedule=1',
                preserveState: true,
                preserveScroll: true,
                onSuccess: () => setCreating(true),
            }),
        );
    const closeCreation = () =>
        drafts.request(() =>
            router.replace({
                url: returnTo,
                preserveState: true,
                preserveScroll: true,
                onSuccess: () => setCreating(false),
            }),
        );
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
                label: 'Open findings',
                icon: ClipboardList,
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
                row.scheduled_at ? (
                    <RecordIdentity
                        title={drillDay(row.scheduled_at)}
                        sub={formatTime(row.scheduled_at)}
                    />
                ) : (
                    <span className="text-muted-foreground">Not recorded</span>
                ),
        },
        {
            key: 'stage',
            label: 'Where it’s up to',
            nowrap: true,
            cell: (row) => {
                const stage = drillStage({
                    status: row.status,
                    findings_open: row.findings_open,
                    finding_overdue: row.flags.finding_overdue,
                });
                return stage ? (
                    <StageDots
                        stage={stage.stage}
                        of={4}
                        label={stage.label}
                        note={stage.note}
                        tone={stage.tone}
                    />
                ) : (
                    <StatusBadge variant="neutral">Cancelled</StatusBadge>
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
            cell: (row) => (
                <RecordIdentity
                    title={`${row.attended_count} confirmed`}
                    sub={`${row.participants_count} assigned`}
                />
            ),
        },
        {
            key: 'findings',
            label: 'Findings',
            nowrap: true,
            cell: (row) => (
                <Link
                    href={drillHref(row.id, { section: 'findings', returnTo })}
                    onClick={(event) => event.stopPropagation()}
                    aria-label={`${row.findings_open} open findings for ${row.reference}${row.flags.finding_overdue ? ', overdue work' : ''}`}
                    className="rounded-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                >
                    {row.findings_open > 0 ? (
                        <StatusBadge
                            variant={
                                row.flags.finding_overdue
                                    ? 'critical'
                                    : 'warning'
                            }
                        >
                            {row.findings_open} open
                            {row.flags.finding_overdue ? ' · overdue work' : ''}
                        </StatusBadge>
                    ) : (
                        <span className="text-foreground underline-offset-4 hover:underline">
                            0 open
                        </span>
                    )}
                </Link>
            ),
        },
    ];

    const pager = (
        <>
            <p className="text-subtle">
                Showing {drills.data.length} of {drills.total}
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

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Health & safety', href: '/health-safety' },
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
                            subline="Plan drills, record what happened and follow through on findings"
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
                                            onClick={openCreation}
                                        >
                                            Schedule drill
                                        </PageHeaderPrimaryButton>
                                    )}
                                </>
                            }
                            filters={
                                <>
                                    <PageHeaderFilterSelect
                                        label="All sites"
                                        allValue=""
                                        value={
                                            filters.site_id
                                                ? String(filters.site_id)
                                                : ''
                                        }
                                        onChange={(value) =>
                                            go({
                                                site_id: value
                                                    ? Number(value)
                                                    : null,
                                            })
                                        }
                                        options={[
                                            { value: '', label: 'All sites' },
                                            ...pickers.sites.map((site) => ({
                                                value: String(site.id),
                                                label: site.name,
                                            })),
                                        ]}
                                    />
                                    <PageHeaderFilterSelect
                                        label="6 months"
                                        allValue="6mo"
                                        value={filters.period}
                                        onChange={(value) =>
                                            go({ period: value })
                                        }
                                        options={[
                                            { value: '30d', label: '30 days' },
                                            {
                                                value: 'quarter',
                                                label: 'Quarter',
                                            },
                                            { value: '6mo', label: '6 months' },
                                            {
                                                value: 'all',
                                                label: 'All dates',
                                            },
                                        ]}
                                    />
                                    <PageHeaderFilterSelect
                                        label="All drill types"
                                        allValue=""
                                        value={filters.drill_type ?? ''}
                                        onChange={(value) =>
                                            go({ drill_type: value || null })
                                        }
                                        options={[
                                            {
                                                value: '',
                                                label: 'All drill types',
                                            },
                                            ...DRILL_TYPE_OPTIONS,
                                        ]}
                                    />
                                    <PageHeaderFilterSelect
                                        label="All outcomes"
                                        allValue=""
                                        value={filters.outcome ?? ''}
                                        onChange={(value) =>
                                            go({ outcome: value || null })
                                        }
                                        options={[
                                            {
                                                value: '',
                                                label: 'All outcomes',
                                            },
                                            ...pickers.outcomes.map(
                                                (value) => ({
                                                    value,
                                                    label:
                                                        outcomeMeta(value)
                                                            ?.label ?? value,
                                                }),
                                            ),
                                        ]}
                                    />
                                    {filtered && (
                                        <PageHeaderFilterButton
                                            icon={X}
                                            onClick={() =>
                                                go({
                                                    q: null,
                                                    site_id: null,
                                                    drill_type: null,
                                                    outcome: null,
                                                    period: '6mo',
                                                })
                                            }
                                        >
                                            Clear filters
                                        </PageHeaderFilterButton>
                                    )}
                                </>
                            }
                            rail={
                                <SafetyWorkspaceRail
                                    hub="prevention"
                                    current="drills"
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
                        {creating && can.schedule && (
                            <Surface aria-label="Schedule drill">
                                <div
                                    ref={creationPanel}
                                    tabIndex={-1}
                                    className="p-[18px] outline-none"
                                >
                                    <DrillPlanningForm
                                        detail={null}
                                        pickers={pickers}
                                        mode="create"
                                        returnTo={returnTo}
                                        onSaved={() => setCreating(false)}
                                        onCancel={closeCreation}
                                    />
                                </div>
                            </Surface>
                        )}
                        <RecordTable
                            title="Drills"
                            sub={
                                (drills.total
                                    ? `Showing ${drills.from ?? 1}–${drills.to ?? drills.data.length} of ${drills.total}`
                                    : 'No drills match these filters') +
                                ' · Summary counts cover the selected Sites and period, before search, type and outcome filters.'
                            }
                            actions={
                                <Button variant="outline" size="sm" asChild>
                                    <Link href="/health-safety/analytics">
                                        Drill trends
                                    </Link>
                                </Button>
                            }
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
                        <Tally
                            title="Drills due and follow-up"
                            sub="Drill recency and recorded outcomes remain separate"
                            items={[
                                {
                                    value: hero.attention.sites_overdue,
                                    label: 'Sites overdue for a drill',
                                    tone:
                                        hero.attention.sites_overdue > 0
                                            ? 'critical'
                                            : undefined,
                                },
                                {
                                    value: hero.attention.findings_open,
                                    label: 'Open findings',
                                    href: `${DRILL_REGISTER}?${new URLSearchParams({ ...(params({ tab: 'findings' }) as Record<string, string>) })}`,
                                },
                                {
                                    value: hero.attention.findings_overdue,
                                    label: 'Overdue findings',
                                    tone:
                                        hero.attention.findings_overdue > 0
                                            ? 'critical'
                                            : undefined,
                                },
                                {
                                    value: hero.badges.fenz_reviews_due,
                                    label: 'Evacuation-scheme reviews due',
                                    tone:
                                        hero.badges.fenz_reviews_due > 0
                                            ? 'warning'
                                            : undefined,
                                },
                            ]}
                        />
                    </div>
                </PageLayout>
                {drafts.confirmation}
            </SafetyFormDraftContext.Provider>
        </AppLayout>
    );
}
