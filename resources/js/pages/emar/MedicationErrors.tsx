import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogDescription,
    DialogFooter,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { EmarHubRail } from '@/components/emar/emar-hub-rail';
import {
    EntityContextMenu,
    EntityKebab,
    type MenuItem,
    useEntityContextMenu,
} from '@/components/lists/entity-menu';
import { EntityTable } from '@/components/lists/entity-table';
import { ListCaption } from '@/components/lists/list-caption';
import {
    PageHeader,
    PageHeaderFilterButton,
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderMeterDonut,
    PageHeaderPrimaryButton,
    PageHeaderSearch,
} from '@/components/page/page-header';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { StatusBadge } from '@/components/ui/status-badge';
import { useEmarBreadcrumbs } from '@/hooks/use-emar-breadcrumbs';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime } from '@/lib/datetime';
import { Head, router } from '@inertiajs/react';
import { AlertTriangle, Eye, Plus, Siren } from 'lucide-react';
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
    can: {
        record: boolean;
        manage: boolean;
        all: boolean;
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
    stats,
    clients,
    staff,
    sites,
    can,
}: Props) {
    const [report, setReport] = useState(false);
    const [exportOpen, setExportOpen] = useState(false);
    const [purpose, setPurpose] = useState('');
    const [query, setQuery] = useState(filters.q);
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
        </div>
    );
    const trend = filters.tab === 'trends';
    const actionView = filters.tab === 'actions';
    return (
        <AppLayout breadcrumbs={crumbs}>
            <Head title="Medication errors" />
            <div className="flex flex-col gap-5">
                <PageHeader
                    icon={AlertTriangle}
                    title="Medication errors"
                    subline="Safety & oversight · report, triage and look into errors and near misses"
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
                                placeholder="Search person or error reference"
                            />
                            {can.all && (
                                <PageHeaderGlassButton
                                    onClick={() => setExportOpen(true)}
                                >
                                    Export neutral CSV
                                </PageHeaderGlassButton>
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
                        <>
                            {(
                                [
                                    ['triage', 'To triage'],
                                    ['investigating', 'Investigating'],
                                    ['actions', 'Actions & close'],
                                    ['closed', 'Closed'],
                                ] as const
                            ).map(([key, label]) => (
                                <PageHeaderMeterBlock
                                    key={key}
                                    label={label}
                                    onClick={() => visit({ tab: key })}
                                    tone={
                                        key === 'triage' && stats.triage
                                            ? 'warning'
                                            : 'brand'
                                    }
                                >
                                    <PageHeaderMeterBig>
                                        {stats[key]}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        {key === 'actions'
                                            ? 'Errors to finish or close'
                                            : 'In your permitted scope'}
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                            ))}
                            <PageHeaderMeterBlock
                                label="Near misses · 90 days"
                                onClick={() =>
                                    visit({ tab: 'trends', reach: 'no' })
                                }
                                value={stats.near_miss}
                            >
                                <PageHeaderMeterDonut
                                    percent={
                                        stats.recent
                                            ? (100 * stats.near_miss) /
                                              stats.recent
                                            : 0
                                    }
                                    caption={`${stats.near_miss} of ${stats.recent} reports`}
                                />
                            </PageHeaderMeterBlock>
                            <PageHeaderMeterBlock
                                label="Reached · 90 days"
                                onClick={() =>
                                    visit({ tab: 'trends', reach: 'yes' })
                                }
                                value={stats.reached}
                            >
                                <PageHeaderMeterDonut
                                    percent={
                                        stats.recent
                                            ? (100 * stats.reached) /
                                              stats.recent
                                            : 0
                                    }
                                    caption={`${stats.reached} of ${stats.recent} reports`}
                                />
                            </PageHeaderMeterBlock>
                        </>
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
                                                        className="frontline-tap min-w-0 flex-1 justify-start whitespace-normal text-left"
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
            <Dialog open={exportOpen} onOpenChange={setExportOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Export medication errors</DialogTitle>
                        <DialogDescription>
                            Exports every error in your permitted house and
                            person scope. The CSV contains neutral summaries;
                            investigation text stays in the error records.
                        </DialogDescription>
                    </DialogHeader>
                    <Label htmlFor="error-export-purpose">
                        Purpose of this export
                    </Label>
                    <Textarea
                        id="error-export-purpose"
                        value={purpose}
                        onChange={(e) => setPurpose(e.target.value)}
                        maxLength={500}
                    />
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setExportOpen(false)}
                        >
                            Cancel
                        </Button>
                        <Button disabled={purpose.trim().length < 3} asChild>
                            <a
                                href={`/emar/errors/export?${new URLSearchParams({ ...(filters.site_id ? { site_id: String(filters.site_id) } : {}), purpose })}`}
                                onClick={(event) => {
                                    if (purpose.trim().length < 3) {
                                        event.preventDefault();
                                        return;
                                    }
                                    setExportOpen(false);
                                }}
                            >
                                Download neutral CSV
                            </a>
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
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
