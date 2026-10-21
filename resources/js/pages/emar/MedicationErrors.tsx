import { EmarHubRail } from '@/components/emar/emar-hub-rail';
import { MedicationExportButton } from '@/components/emar/medication-export-button';
import {
    EntityContextMenu,
    EntityKebab,
    useEntityContextMenu,
    type MenuItem,
} from '@/components/lists/entity-menu';
import { EntityTable } from '@/components/lists/entity-table';
import { ListCaption } from '@/components/lists/list-caption';
import {
    PageHeader,
    PageHeaderFilterButton,
    PageHeaderFilterSelect,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
    PageHeaderSearch,
    PageHeaderStatusChip,
} from '@/components/page/page-header';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { StatusBadge } from '@/components/ui/status-badge';
import { useEmarBreadcrumbs } from '@/hooks/use-emar-breadcrumbs';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime, formatTime } from '@/lib/datetime';
import { Head, router } from '@inertiajs/react';
import { AlertTriangle, Clock, Eye, Plus, Shield, Siren } from 'lucide-react';
import { useState } from 'react';
import { ReportErrorModal } from './components/report-error-modal';
import { ErrorDetail } from './errors/_detail';
import {
    HARMS,
    labelFor,
    personName,
    REACH,
    Stage,
    TYPES,
    type ErrorRecord,
    type Person,
} from './errors/_shared';

type Filters = {
    tab: string;
    q: string;
    reach: string;
    site_id: number | null;
};
type ErrorMeters = {
    triage_overdue: number;
    triage_next_due: string | null;
    open_with_harm: number;
    open_moderate_or_worse: number;
    investigation_next_due: string | null;
    actions_due: number;
    actions_overdue: number;
    actions_next_due: string | null;
    incidents_ready: number;
    can_close_incidents: boolean;
    mine_open: number;
    mine_closed: number;
    closed_recent: number;
};
const NO_METERS: ErrorMeters = {
    triage_overdue: 0,
    triage_next_due: null,
    open_with_harm: 0,
    open_moderate_or_worse: 0,
    investigation_next_due: null,
    actions_due: 0,
    actions_overdue: 0,
    actions_next_due: null,
    incidents_ready: 0,
    can_close_incidents: false,
    mine_open: 0,
    mine_closed: 0,
    closed_recent: 0,
};
type Props = {
    errors: ErrorRecord[];
    detail: ErrorRecord | null;
    filters: Filters;
    pagination: {
        links: { url: string | null; active: boolean; label: string }[];
        total: number;
        from: number | null;
        to: number | null;
        last_page: number;
    };
    /** EA-154: the approved P08b meter figures. */
    meters?: ErrorMeters;
    stats: {
        triage: number;
        investigating: number;
        actions: number;
        closed: number;
        total_open: number;
        recent: number;
        reached: number;
        near_miss: number;
        unknown_reach: number;
        trend: { week: string; count: number; near_miss: number }[];
    };
    clients: { id: number; first_name: string; last_name: string }[];
    staff: Person[];
    sites: Person[];
    export_period: { date_from: string; date_to: string };
    can: {
        record: boolean;
        manage: boolean;
        all: boolean;
        export: boolean;
        controlled: boolean;
    };
};
type ErrorView =
    | 'triage'
    | 'investigating'
    | 'actions'
    | 'closed'
    | 'incidents'
    | 'trends'
    | 'mine';
const views: ReadonlyArray<readonly [ErrorView, string]> = [
    ['triage', 'To triage'],
    ['investigating', 'Investigating'],
    ['actions', 'Actions & close'],
    ['closed', 'Closed'],
    ['incidents', 'Incidents'],
    ['trends', 'Trends'],
    ['mine', 'Your reports'],
];
export default function MedicationErrors({
    errors,
    detail,
    filters,
    pagination,
    meters: m = NO_METERS,
    stats,
    clients,
    staff,
    sites,
    can,
    export_period,
}: Props) {
    const [report, setReport] = useState(false);
    const [query, setQuery] = useState(filters.q);
    // The approved P08b "As at" chip: when this view was read; it refreshes it.
    const [asAt, setAsAt] = useState(() => new Date().toISOString());
    const houseNames = sites.map((site) => site.name);
    const houses =
        houseNames.length > 3
            ? `${houseNames.slice(0, 3).join(', ')} and ${houseNames.length - 3} more`
            : houseNames.length > 1
              ? `${houseNames.slice(0, -1).join(', ')} and ${houseNames[houseNames.length - 1]}`
              : (houseNames[0] ?? 'your houses');
    // No error reported at all in this reader's scope (any status).
    const noneReported = stats.total_open + stats.closed === 0;
    const ctx = useEntityContextMenu<ErrorRecord>();
    const crumbs = useEmarBreadcrumbs();
    const visit = (next: Partial<Filters>, error?: number | null) =>
        router.get(
            '/emar/errors',
            { ...filters, ...next, error: error ?? null },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    const open = (e: ErrorRecord) => visit({}, e.id);
    const menu = (e: ErrorRecord): MenuItem[] => [
        { label: 'Open error record', icon: Eye, onClick: () => open(e) },
        ...(e.incident
            ? [
                  {
                      label: 'Open linked incident',
                      icon: Siren,
                      onClick: () =>
                          router.visit(`/incidents/${e.incident!.id}`),
                  },
              ]
            : []),
    ];
    const filtersNode = (
        <div className="flex flex-wrap items-center gap-2.5">
            {(can.all ? views : views.filter(([key]) => key === 'mine')).map(
                ([key, label]: readonly [ErrorView, string]) => (
                    <PageHeaderFilterButton
                        key={key}
                        active={filters.tab === key}
                        onClick={() => visit({ tab: key })}
                    >
                        {label}
                        {[
                            'triage',
                            'investigating',
                            'actions',
                            'closed',
                        ].includes(key)
                            ? ` · ${stats[key as 'triage']}`
                            : ''}
                    </PageHeaderFilterButton>
                ),
            )}
            <PageHeaderFilterSelect
                label="Reach"
                value={filters.reach}
                options={[
                    { value: 'all', label: 'All reach states' },
                    ...REACH.map(([value, label]) => ({ value, label })),
                ]}
                onChange={(reach) => visit({ reach })}
            />
            <PageHeaderFilterSelect
                label="House"
                value={filters.site_id ? String(filters.site_id) : 'all'}
                options={[
                    { value: 'all', label: 'All approved houses' },
                    ...sites.map((site) => ({
                        value: String(site.id),
                        label: site.name,
                    })),
                ]}
                onChange={(site) =>
                    visit({ site_id: site === 'all' ? null : Number(site) })
                }
            />
            <PageHeaderFilterButton
                icon={Clock}
                onClick={() =>
                    router.reload({
                        onSuccess: () => setAsAt(new Date().toISOString()),
                    })
                }
            >
                As at {formatTime(asAt)} · Pacific/Auckland
            </PageHeaderFilterButton>
        </div>
    );
    const trend = filters.tab === 'trends';
    const actionView = filters.tab === 'actions';
    return (
        <AppLayout breadcrumbs={crumbs}>
            <Head title="Medication errors" />
            <div className="flex flex-col gap-5">
                <PageHeader
                    icon={Shield}
                    title="Safety & oversight"
                    titleChip={
                        <PageHeaderStatusChip variant="neutral">
                            {sites.length}{' '}
                            {sites.length === 1 ? 'house' : 'houses'}
                        </PageHeaderStatusChip>
                    }
                    subline={`Medication errors at ${houses} · times in Pacific/Auckland`}
                    rail={<EmarHubRail />}
                    filters={filtersNode}
                    actions={
                        <>
                            <PageHeaderSearch
                                value={query}
                                onChange={setQuery}
                                onKeyDown={(e) => {
                                    if (e.key === 'Enter') visit({ q: query });
                                }}
                                placeholder="Search people or MED number"
                            />
                            {can.export && (
                                <MedicationExportButton
                                    type="errors"
                                    siteId={filters.site_id ?? undefined}
                                    dateFrom={export_period.date_from}
                                    dateTo={export_period.date_to}
                                >
                                    Export neutral CSV
                                </MedicationExportButton>
                            )}
                            {can.record && (
                                <PageHeaderPrimaryButton
                                    icon={Plus}
                                    onClick={() => setReport(true)}
                                >
                                    Report an error
                                </PageHeaderPrimaryButton>
                            )}
                        </>
                    }
                    meters={
                        // EA-154 (Lane C9): the approved P08b meters. The
                        // 90-day split lives on the Trends view.
                        can.all ? (
                            <>
                                <PageHeaderMeterBlock
                                    label="To triage"
                                    tone={
                                        m.triage_overdue
                                            ? 'critical'
                                            : stats.triage
                                              ? 'warning'
                                              : 'brand'
                                    }
                                    ariaLabel={`View ${stats.triage} errors to triage`}
                                    onClick={() => visit({ tab: 'triage' })}
                                >
                                    <PageHeaderMeterBig>
                                        {stats.triage}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        {m.triage_overdue
                                            ? `${m.triage_overdue} overdue`
                                            : stats.triage && m.triage_next_due
                                              ? `Due ${formatDateTime(m.triage_next_due)}`
                                              : 'None waiting'}
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Open with harm"
                                    tone={
                                        m.open_moderate_or_worse
                                            ? 'warning'
                                            : 'brand'
                                    }
                                    ariaLabel={`${m.open_with_harm} open errors that reached the person with harm`}
                                    onClick={() =>
                                        visit({ tab: 'investigating' })
                                    }
                                >
                                    <PageHeaderMeterBig>
                                        {m.open_with_harm}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        {m.open_with_harm
                                            ? `${m.open_moderate_or_worse} moderate or worse`
                                            : 'None open'}
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Investigating"
                                    ariaLabel={`View ${stats.investigating} errors being investigated`}
                                    onClick={() =>
                                        visit({ tab: 'investigating' })
                                    }
                                >
                                    <PageHeaderMeterBig>
                                        {stats.investigating}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        {stats.investigating &&
                                        m.investigation_next_due
                                            ? `Next due ${formatDateTime(m.investigation_next_due)}`
                                            : 'None'}
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Actions due"
                                    tone={
                                        m.actions_overdue ? 'critical' : 'brand'
                                    }
                                    ariaLabel={`View ${m.actions_due} actions due in the next 7 days or overdue`}
                                    onClick={() => visit({ tab: 'actions' })}
                                >
                                    <PageHeaderMeterBig>
                                        {m.actions_due}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        {m.actions_overdue
                                            ? `${m.actions_overdue} overdue`
                                            : m.actions_due &&
                                                m.actions_next_due
                                              ? `Next ${formatDateTime(m.actions_next_due)}`
                                              : 'None in 7 days'}
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Incidents to close"
                                    tone={
                                        m.incidents_ready &&
                                        m.can_close_incidents
                                            ? 'warning'
                                            : 'brand'
                                    }
                                    ariaLabel={`View ${m.incidents_ready} incidents ready to close`}
                                    onClick={() => visit({ tab: 'incidents' })}
                                >
                                    <PageHeaderMeterBig>
                                        {m.incidents_ready}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        {m.incidents_ready
                                            ? m.can_close_incidents
                                                ? 'Ready for you to close'
                                                : 'For a manager to close'
                                            : 'None ready'}
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Closed"
                                    ariaLabel={`View ${m.closed_recent} errors closed, from the last 90 days`}
                                    onClick={() => visit({ tab: 'closed' })}
                                >
                                    <PageHeaderMeterBig>
                                        {m.closed_recent}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        Last 90 days
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                            </>
                        ) : (
                            <>
                                <PageHeaderMeterBlock
                                    label="Your open reports"
                                    ariaLabel={`${m.mine_open} of your reports are open`}
                                    onClick={() => visit({ tab: 'mine' })}
                                >
                                    <PageHeaderMeterBig>
                                        {m.mine_open}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        Being looked into
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Closed"
                                    ariaLabel={`${m.mine_closed} of your reports are closed`}
                                    onClick={() => visit({ tab: 'mine' })}
                                >
                                    <PageHeaderMeterBig>
                                        {m.mine_closed}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        {m.mine_closed
                                            ? 'You were told the outcome'
                                            : 'None yet'}
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                            </>
                        )
                    }
                />
                {!can.controlled && (
                    <p className="text-caption">
                        Controlled-medicine records are omitted from this
                        cross-person register and its counts.
                    </p>
                )}
                {trend ? (
                    <Card className="gap-5 p-5">
                        <h2 className="text-section-title">
                            Reports by when they happened
                        </h2>
                        <p className="text-subtle">
                            The last 90 NZ calendar days: {stats.reached}{' '}
                            reached the person, {stats.near_miss} near misses,{' '}
                            {stats.unknown_reach} with reach not known.
                            Historical records use reported time only where
                            occurred time was not captured.
                        </p>
                        <ul className="flex flex-col gap-2.5">
                            {stats.trend.map((week) => (
                                <li
                                    key={week.week}
                                    className="flex flex-wrap items-center justify-between gap-2"
                                >
                                    <span>Week of {week.week}</span>
                                    <span className="text-sm tabular-nums">
                                        {week.count} reports · {week.near_miss}{' '}
                                        near misses
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </Card>
                ) : (
                    <>
                        <ListCaption
                            title={
                                views.find(
                                    ([key]) => key === filters.tab,
                                )?.[1] ?? 'Medication errors'
                            }
                            caption={`${pagination.from ?? 0}–${pagination.to ?? 0} of ${pagination.total} errors · ${can.all ? 'permitted houses and people' : 'your own reports'}`}
                        />
                        {errors.length ? (
                            <>
                                <div className="hidden md:block">
                                    <EntityTable
                                        rows={errors}
                                        rowKey={(e) => e.id}
                                        identityLabel="Person & error"
                                        identityWidth="1.6fr"
                                        rowHeight="content"
                                        minWidth={980}
                                        identity={(e) => ({
                                            icon: AlertTriangle,
                                            name: personName(e),
                                            subline: `${e.ref} · ${e.site_name}`,
                                        })}
                                        onOpen={open}
                                        actionsFor={menu}
                                        onRowContextMenu={ctx.open}
                                        columns={[
                                            {
                                                key: 'what',
                                                label: 'What happened',
                                                width: '1.6fr',
                                                cell: (e) => (
                                                    <div className="py-2">
                                                        <p className="text-sm font-medium">
                                                            {labelFor(
                                                                TYPES,
                                                                e.error_type,
                                                            )}
                                                        </p>
                                                        <p className="text-caption">
                                                            {e.medication
                                                                ?.name ??
                                                                'No chart medicine linked'}
                                                        </p>
                                                        <p className="text-caption">
                                                            {formatDateTime(
                                                                e.occurred_at,
                                                            )}
                                                        </p>
                                                    </div>
                                                ),
                                            },
                                            {
                                                key: 'reach',
                                                label: 'Reach & harm',
                                                width: '1.2fr',
                                                cell: (e) => (
                                                    <div className="py-2">
                                                        <StatusBadge
                                                            variant={
                                                                e.reached_client ===
                                                                'no'
                                                                    ? 'info'
                                                                    : [
                                                                            'severe',
                                                                            'death',
                                                                        ].includes(
                                                                            e.harm_level ??
                                                                                '',
                                                                        )
                                                                      ? 'critical'
                                                                      : [
                                                                              'minor',
                                                                              'moderate',
                                                                          ].includes(
                                                                              e.harm_level ??
                                                                                  '',
                                                                          )
                                                                        ? 'warning'
                                                                        : 'neutral'
                                                            }
                                                        >
                                                            {labelFor(
                                                                HARMS,
                                                                e.harm_level,
                                                            )}
                                                        </StatusBadge>
                                                        <p className="text-caption">
                                                            {labelFor(
                                                                REACH,
                                                                e.reached_client,
                                                            )}
                                                        </p>
                                                    </div>
                                                ),
                                            },
                                            {
                                                key: 'stage',
                                                label: 'Where it’s at',
                                                width: '1.5fr',
                                                cell: (e) => (
                                                    <div className="py-2">
                                                        <Stage
                                                            value={e.stage}
                                                        />
                                                        <p className="text-caption">
                                                            {e.owner?.name ??
                                                                'Owner to assign'}
                                                        </p>
                                                        <p className="text-caption">
                                                            {formatDateTime(
                                                                e.stage ===
                                                                    'triage'
                                                                    ? e.triage_due_at
                                                                    : e.investigation_due_at,
                                                            )}
                                                        </p>
                                                        {e.incident
                                                            ?.ready_to_close && (
                                                            <p className="text-caption">
                                                                Incident ready
                                                                for Incidents
                                                                closure
                                                            </p>
                                                        )}
                                                    </div>
                                                ),
                                            },
                                        ]}
                                    />
                                </div>
                                <ul className="flex flex-col gap-5 md:hidden">
                                    {errors.map((e) => (
                                        <li key={e.id}>
                                            <Card
                                                className="gap-3 p-4"
                                                onContextMenu={(ev) =>
                                                    ctx.open(ev, e)
                                                }
                                            >
                                                <div className="flex items-start justify-between gap-2">
                                                    <Button
                                                        className="frontline-tap min-w-0 flex-1 justify-start text-left whitespace-normal"
                                                        variant="ghost"
                                                        onClick={() => open(e)}
                                                    >
                                                        {personName(e)} ·{' '}
                                                        {e.ref}
                                                    </Button>
                                                    <EntityKebab
                                                        actions={menu(e)}
                                                    />
                                                </div>
                                                <p className="text-sm">
                                                    {labelFor(
                                                        TYPES,
                                                        e.error_type,
                                                    )}{' '}
                                                    ·{' '}
                                                    {formatDateTime(
                                                        e.occurred_at,
                                                    )}
                                                </p>
                                                <p className="text-subtle">
                                                    {labelFor(
                                                        REACH,
                                                        e.reached_client,
                                                    )}{' '}
                                                    ·{' '}
                                                    {labelFor(
                                                        HARMS,
                                                        e.harm_level,
                                                    )}
                                                </p>
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <Stage value={e.stage} />
                                                    <span className="text-caption">
                                                        {e.owner?.name ??
                                                            'Owner to assign'}
                                                    </span>
                                                </div>
                                                <Button
                                                    variant="outline"
                                                    className="frontline-tap"
                                                    onClick={() => open(e)}
                                                >
                                                    Open error
                                                </Button>
                                            </Card>
                                        </li>
                                    ))}
                                </ul>
                                {actionView && (
                                    <Card className="gap-3 p-5">
                                        <h2 className="text-section-title">
                                            Open actions on these errors
                                        </h2>
                                        {errors.flatMap((e) =>
                                            e.actions
                                                .filter((a) => !a.completed_at)
                                                .map((a) => (
                                                    <div
                                                        key={a.id}
                                                        className="flex flex-wrap items-center justify-between gap-2"
                                                    >
                                                        <div>
                                                            <p className="text-sm font-medium">
                                                                {a.description}
                                                            </p>
                                                            <p className="text-caption">
                                                                {e.ref} ·{' '}
                                                                {a.owner?.name}{' '}
                                                                · due{' '}
                                                                {formatDateTime(
                                                                    a.due_at,
                                                                )}
                                                            </p>
                                                        </div>
                                                        <Button
                                                            variant="outline"
                                                            onClick={() =>
                                                                open(e)
                                                            }
                                                        >
                                                            Open actions
                                                        </Button>
                                                    </div>
                                                )),
                                        )}
                                    </Card>
                                )}
                                <LaravelPagination
                                    links={pagination.links}
                                    lastPage={pagination.last_page}
                                />
                            </>
                        ) : noneReported ? (
                            <EmptyState
                                icon={AlertTriangle}
                                title="No medication errors reported"
                                description="Reports come from the dose, from a check that wasn’t right, from the person’s record and from here. Each one is triaged, looked into and closed, with the person told."
                                action={
                                    can.record ? (
                                        <Button onClick={() => setReport(true)}>
                                            <Plus />
                                            Report an error
                                        </Button>
                                    ) : undefined
                                }
                            />
                        ) : (
                            <EmptyState
                                icon={AlertTriangle}
                                title="No errors in this view"
                                description="Reports appear here once saved. Try another view or clear the filters."
                                action={
                                    <Button
                                        variant="outline"
                                        onClick={() => {
                                            setQuery('');
                                            visit({ q: '', reach: 'all' });
                                        }}
                                    >
                                        Clear filters
                                    </Button>
                                }
                            />
                        )}
                    </>
                )}
            </div>
            {ctx.ctx && (
                <EntityContextMenu
                    x={ctx.ctx.x}
                    y={ctx.ctx.y}
                    title={ctx.ctx.record.ref}
                    items={menu(ctx.ctx.record)}
                    onClose={ctx.close}
                />
            )}
            <ReportErrorModal
                open={report}
                onClose={() => setReport(false)}
                clients={clients.map((c) => ({
                    id: c.id,
                    name: `${c.first_name} ${c.last_name}`,
                    site: null,
                }))}
            />
            {detail && (
                <ErrorDetail
                    key={detail.id}
                    error={detail}
                    canManage={can.manage}
                    canReadInvestigation={can.all}
                    canRecord={can.record}
                    staff={staff}
                    onClose={() => visit({})}
                />
            )}
        </AppLayout>
    );
}
