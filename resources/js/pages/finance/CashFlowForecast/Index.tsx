import { CashFlowForecastDialog, ConfirmDialog } from '@/components/finance';
import { formatMoney } from '@/components/finance/money';
import {
    FinanceReportPage,
    formatReportDate,
} from '@/components/finance/report-page';
import {
    EntityContextMenu,
    EntityStatusChip,
    EntityTable,
    ListCaption,
    compactMenu,
    useEntityContextMenu,
    type EntityTableColumn,
    type MenuItem,
} from '@/components/lists';
import {
    PageHeaderFilterSelect,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
    PageHeaderSearch,
    PageHeaderStatusChip,
} from '@/components/page';
import { EmptyList, EmptySearch } from '@/components/ui/empty-state';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { router } from '@inertiajs/react';
import { ExternalLink, Plus, Trash2, TrendingUp } from 'lucide-react';
import { useState } from 'react';

type Forecast = {
    id: number;
    name: string;
    forecast_date: string;
    period_start: string;
    period_end: string;
    period_type: string;
    opening_balance: string;
    status: string;
    scenarios_count: number;
    created_by: { id: number; name: string } | null;
    created_at: string;
};

type PaginatedData = {
    data: Forecast[];
    links: { url: string | null; label: string; active: boolean }[];
    current_page: number;
    last_page: number;
    total: number;
};

type Summary = {
    total: number;
    draft: number;
    final: number;
    scenarios: number;
};

type Filters = {
    q: string;
    status: string;
    period_type: string;
};

type PageProps = {
    forecasts: PaginatedData;
    summary: Summary;
    filters: Filters;
    canManage: boolean;
};

const URL = '/finance/cash-flow-forecast';

const ALL = 'all';

const periodTypeLabels: Record<string, string> = {
    weekly: 'Weekly',
    fortnightly: 'Fortnightly',
    monthly: 'Monthly',
};

export default function CashFlowForecastIndex({
    forecasts,
    summary,
    filters,
    canManage = false,
}: PageProps) {
    const [createOpen, setCreateOpen] = useState(false);
    const [deleteTarget, setDeleteTarget] = useState<Forecast | null>(null);
    const [deleting, setDeleting] = useState(false);
    const [search, setSearch] = useState(filters.q ?? '');

    const ctx = useEntityContextMenu<Forecast>();

    const go = (next: Partial<Filters>) => {
        const params: Record<string, string> = {};
        const merged = { ...filters, ...next };
        if (merged.q) params.q = merged.q;
        if (merged.status) params.status = merged.status;
        if (merged.period_type) params.period_type = merged.period_type;

        router.get(URL, params, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    function confirmDelete() {
        if (!deleteTarget) return;
        router.delete(`${URL}/${deleteTarget.id}`, {
            onStart: () => setDeleting(true),
            onFinish: () => setDeleting(false),
            onSuccess: () => setDeleteTarget(null),
        });
    }

    const menuFor = (forecast: Forecast): MenuItem[] =>
        compactMenu([
            {
                label: 'Open forecast',
                icon: ExternalLink,
                onClick: () => router.visit(`${URL}/${forecast.id}`),
            },
            canManage &&
                forecast.status === 'draft' && { separator: true },
            canManage &&
                forecast.status === 'draft' && {
                    label: 'Delete draft',
                    icon: Trash2,
                    danger: true,
                    onClick: () => setDeleteTarget(forecast),
                },
        ]);

    const columns: EntityTableColumn<Forecast>[] = [
        {
            key: 'period',
            label: 'Period',
            width: '1.5fr',
            cell: (f) =>
                `${formatReportDate(f.period_start)} – ${formatReportDate(f.period_end)}`,
        },
        {
            key: 'period_type',
            label: 'Interval',
            width: '0.8fr',
            cell: (f) => periodTypeLabels[f.period_type] ?? f.period_type,
        },
        {
            key: 'opening_balance',
            label: 'Opening balance',
            width: '1fr',
            align: 'right',
            cell: (f) => (
                <span className="tabular-nums">
                    {formatMoney(f.opening_balance)}
                </span>
            ),
        },
        {
            key: 'scenarios',
            label: 'Scenarios',
            width: '0.7fr',
            align: 'right',
            cell: (f) => (
                <span className="tabular-nums">{f.scenarios_count}</span>
            ),
        },
        {
            key: 'status',
            label: 'Status',
            width: '0.8fr',
            cell: (f) => (
                <EntityStatusChip
                    variant={f.status === 'final' ? 'success' : 'neutral'}
                >
                    {f.status === 'final' ? 'Final' : 'Draft'}
                </EntityStatusChip>
            ),
        },
        {
            key: 'created',
            label: 'Generated',
            width: '1.1fr',
            cell: (f) => (
                <span className="min-w-0">
                    <span className="block truncate">
                        {formatReportDate(f.forecast_date)}
                    </span>
                    {f.created_by && (
                        <span className="block truncate text-caption">
                            {f.created_by.name}
                        </span>
                    )}
                </span>
            ),
        },
    ];

    const filtered = Boolean(
        filters.q || filters.status || filters.period_type,
    );

    const meters = (
        <>
            <PageHeaderMeterBlock
                label="Forecasts"
                href={URL}
                ariaLabel="View every forecast"
            >
                <PageHeaderMeterBig>{summary.total}</PageHeaderMeterBig>
                <PageHeaderMeterCaption>
                    Generated for this organisation
                </PageHeaderMeterCaption>
            </PageHeaderMeterBlock>
            <PageHeaderMeterBlock
                label="Final"
                tone="success"
                href={`${URL}?status=final`}
                ariaLabel="View final forecasts"
            >
                <PageHeaderMeterBig>{summary.final}</PageHeaderMeterBig>
                <PageHeaderMeterCaption>
                    Signed off for planning
                </PageHeaderMeterCaption>
            </PageHeaderMeterBlock>
            <PageHeaderMeterBlock
                label="Draft"
                tone={summary.draft > 0 ? 'warning' : 'brand'}
                href={`${URL}?status=draft`}
                ariaLabel="View draft forecasts"
            >
                <PageHeaderMeterBig>{summary.draft}</PageHeaderMeterBig>
                <PageHeaderMeterCaption>
                    Still being worked on
                </PageHeaderMeterCaption>
            </PageHeaderMeterBlock>
            <PageHeaderMeterBlock
                label="Scenarios"
                href={URL}
                ariaLabel="View forecasts and their scenarios"
            >
                <PageHeaderMeterBig>{summary.scenarios}</PageHeaderMeterBig>
                <PageHeaderMeterCaption>
                    What-if variations across every forecast
                </PageHeaderMeterCaption>
            </PageHeaderMeterBlock>
        </>
    );

    return (
        <FinanceReportPage
            icon={TrendingUp}
            title="Cash-flow forecast"
            chip={
                <PageHeaderStatusChip
                    variant={summary.draft > 0 ? 'warning' : 'neutral'}
                >
                    {summary.draft > 0
                        ? `${summary.draft} draft`
                        : `${summary.total} forecasts`}
                </PageHeaderStatusChip>
            }
            subline="Projected cash from outstanding invoices, bills and recurring transactions"
            actions={
                <>
                    <PageHeaderSearch
                        value={search}
                        onChange={setSearch}
                        onKeyDown={(e) => {
                            if (e.key === 'Enter') go({ q: search });
                        }}
                        placeholder="Search forecasts…"
                    />
                    {canManage && (
                        <PageHeaderPrimaryButton
                            icon={Plus}
                            onClick={() => setCreateOpen(true)}
                        >
                            New forecast
                        </PageHeaderPrimaryButton>
                    )}
                </>
            }
            meters={meters}
            filters={
                <>
                    <PageHeaderFilterSelect
                        label="Status"
                        value={filters.status || ALL}
                        allValue={ALL}
                        options={[
                            { value: ALL, label: 'Any status' },
                            { value: 'draft', label: 'Draft' },
                            { value: 'final', label: 'Final' },
                        ]}
                        onChange={(value) =>
                            go({ status: value === ALL ? '' : value })
                        }
                    />
                    <PageHeaderFilterSelect
                        label="Interval"
                        value={filters.period_type || ALL}
                        allValue={ALL}
                        options={[
                            { value: ALL, label: 'Any interval' },
                            { value: 'weekly', label: 'Weekly' },
                            { value: 'fortnightly', label: 'Fortnightly' },
                            { value: 'monthly', label: 'Monthly' },
                        ]}
                        onChange={(value) =>
                            go({ period_type: value === ALL ? '' : value })
                        }
                    />
                </>
            }
        >
            <ListCaption
                title="Forecasts"
                caption={`${forecasts.data.length} of ${forecasts.total} shown`}
            />

            {forecasts.data.length === 0 ? (
                filtered ? (
                    <EmptySearch
                        searchTerm={filters.q}
                        onClear={() => {
                            setSearch('');
                            router.get(
                                URL,
                                {},
                                { preserveScroll: true, replace: true },
                            );
                        }}
                    />
                ) : (
                    <EmptyList
                        icon={TrendingUp}
                        itemName="forecast"
                        title="No forecasts yet"
                        description="Generate your first cash-flow forecast to project future cash positions and plan ahead."
                        onCreate={canManage ? () => setCreateOpen(true) : undefined}
                        createLabel="New forecast"
                    />
                )
            ) : (
                <>
                    <EntityTable
                        rows={forecasts.data}
                        rowKey={(f) => f.id}
                        identityLabel="Forecast"
                        identity={(f) => ({
                            icon: TrendingUp,
                            name: f.name,
                            subline: `${f.scenarios_count} scenarios`,
                        })}
                        columns={columns}
                        actionsFor={menuFor}
                        hrefFor={(f) => `${URL}/${f.id}`}
                        onRowContextMenu={ctx.open}
                        minWidth={1120}
                    />
                    <LaravelPagination
                        links={forecasts.links}
                        lastPage={forecasts.last_page}
                    />
                </>
            )}

            {canManage && (
                <CashFlowForecastDialog
                    open={createOpen}
                    onClose={() => setCreateOpen(false)}
                />
            )}

            <ConfirmDialog
                open={!!deleteTarget}
                onClose={() => setDeleteTarget(null)}
                title="Delete this forecast?"
                description={
                    <>
                        This permanently deletes the forecast{' '}
                        <span className="font-medium text-foreground">
                            {deleteTarget?.name}
                        </span>{' '}
                        and its scenarios. This can&rsquo;t be undone.
                    </>
                }
                confirmText="Delete forecast"
                variant="destructive"
                processing={deleting}
                onConfirm={confirmDelete}
            />

            {ctx.ctx ? (
                <EntityContextMenu
                    x={ctx.ctx.x}
                    y={ctx.ctx.y}
                    icon={TrendingUp}
                    title={ctx.ctx.record.name}
                    items={menuFor(ctx.ctx.record)}
                    onClose={ctx.close}
                />
            ) : null}
        </FinanceReportPage>
    );
}
