import {
    CategoryBars,
    TrendChart,
} from '@/components/control-room/reports/report-parts';
import {
    EntityContextMenu,
    EntityStatusChip,
    EntityTable,
    ListCaption,
    useEntityContextMenu,
    type MenuItem,
} from '@/components/lists';
import {
    PageHeader,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderMeterDonut,
    PageHeaderRail,
    PageHeaderSearch,
    PageLayout,
} from '@/components/page';
import { QuietLine, Surface } from '@/components/signature/blocks';
import {
    DueCell,
    Pager,
    REPORTS_BASE,
    ReportBlock,
    ReportFilterRow,
    assetStatusLabel,
    bucketLabel,
    formatMoney,
    plural,
    reportHref,
    scopeWords,
    showBlock,
    siteReportHref,
    usePaged,
    type ReportScope,
    type SiteRef,
    type TrendBucket,
} from '@/components/sites/reports/report-kit';
import { EmptyState } from '@/components/ui/empty-state';
import AppLayout from '@/layouts/app-layout';
import { formatDateOnly } from '@/lib/datetime';
import { Head, Link, router } from '@inertiajs/react';
import { FileBarChart, Package, Wrench } from 'lucide-react';
import { useState } from 'react';

type Flag =
    | 'out_of_service'
    | 'maintenance_overdue'
    | 'inspection_overdue'
    | 'warranty_expired'
    | 'warranty_expiring';

type AssetRow = {
    id: number;
    name: string;
    asset_tag: string | null;
    category: string | null;
    status: string | null;
    maintenance_due_at: string | null;
    days_overdue: number;
    inspection_due_at: string | null;
    warranty_expires_at: string | null;
    flags: Flag[];
    site: SiteRef | null;
};

type Props = ReportScope & {
    summary: {
        total_assets: number;
        retired: number;
        needs_attention: number;
        out_of_service: number;
        maintenance_overdue: number;
        inspection_overdue: number;
        warranty_expired: number;
        warranty_expiring_soon: number;
        maintenance_done: number;
        maintenance_spend: number;
    };
    byStatus: { status: string; count: number }[];
    trend: TrendBucket[];
    assets: AssetRow[];
};

type View =
    | 'all'
    | 'out_of_service'
    | 'maintenance'
    | 'inspection'
    | 'warranty';

const PATH = `${REPORTS_BASE}/asset-condition`;

function inView(row: AssetRow, view: View): boolean {
    switch (view) {
        case 'out_of_service':
            return row.flags.includes('out_of_service');
        case 'maintenance':
            return row.flags.includes('maintenance_overdue');
        case 'inspection':
            return row.flags.includes('inspection_overdue');
        case 'warranty':
            return (
                row.flags.includes('warranty_expired') ||
                row.flags.includes('warranty_expiring')
            );
        default:
            return true;
    }
}

function daysLate(date: string | null, today: string): number {
    if (!date || date >= today) return 0;
    return Math.round(
        (Date.parse(`${today}T00:00:00Z`) - Date.parse(`${date}T00:00:00Z`)) /
            86_400_000,
    );
}

/**
 * Asset condition — where is maintenance slipping? Assets in use at the
 * Sites the viewer can access that are out of service, past their
 * maintenance or inspection date, or whose warranty has ended or ends in
 * the next 30 days. The period sets the maintenance history shown.
 */
export default function AssetConditionReport(props: Props) {
    const { summary: s, byStatus, trend, assets, filters } = props;
    const scope: ReportScope = props;
    const [view, setView] = useState<View>('all');
    const [query, setQuery] = useState('');
    const menu = useEntityContextMenu<AssetRow>();

    const q = query.trim().toLowerCase();
    const shown = assets.filter(
        (row) =>
            inView(row, view) &&
            (!q ||
                `${row.name} ${row.asset_tag ?? ''} ${row.category ?? ''} ${row.site?.name ?? ''}`
                    .toLowerCase()
                    .includes(q)),
    );
    const paged = usePaged(shown, `${view}|${q}`);
    const goodShare =
        s.total_assets > 0
            ? ((s.total_assets - s.needs_attention) / s.total_assets) * 100
            : null;

    const actionsFor = (row: AssetRow): MenuItem[] => [
        {
            label: 'Open asset',
            icon: Package,
            onClick: () => router.visit(`/fleet-assets/assets/${row.id}`),
        },
        ...(row.site
            ? [
                  {
                      label: 'Open site report',
                      icon: FileBarChart,
                      onClick: () =>
                          router.visit(siteReportHref(row.site!.id, filters)),
                  },
              ]
            : []),
    ];

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Sites', href: '/sites' },
                { title: 'Reports', href: reportHref(REPORTS_BASE, filters) },
                { title: 'Asset condition', href: reportHref(PATH, filters) },
            ]}
        >
            <Head title="Asset condition" />

            <PageLayout
                hero={
                    <PageHeader
                        icon={Package}
                        title="Asset condition"
                        subline={`Assets at your sites that are out of service, past a date or with a warranty ending · ${scopeWords(scope)}`}
                        actions={
                            <PageHeaderSearch
                                value={query}
                                onChange={setQuery}
                                placeholder="Search assets, sites…"
                            />
                        }
                        meters={
                            <>
                                <PageHeaderMeterBlock
                                    label="Assets in use"
                                    ariaLabel="Show assets by status"
                                    onClick={() => showBlock('ac-status')}
                                >
                                    <PageHeaderMeterBig>
                                        {s.total_assets}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        {s.retired > 0
                                            ? `${s.retired} retired not counted`
                                            : 'at your sites'}
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="In good order"
                                    tone={
                                        goodShare !== null && goodShare < 75
                                            ? 'warning'
                                            : 'brand'
                                    }
                                    ariaLabel="Show assets needing attention"
                                    onClick={() => {
                                        setView('all');
                                        showBlock('ac-list');
                                    }}
                                >
                                    {goodShare !== null ? (
                                        <PageHeaderMeterDonut
                                            percent={goodShare}
                                            caption={
                                                <>
                                                    in service and
                                                    <br />
                                                    within every date
                                                </>
                                            }
                                        />
                                    ) : (
                                        <>
                                            <PageHeaderMeterBig>
                                                No assets
                                            </PageHeaderMeterBig>
                                            <PageHeaderMeterCaption>
                                                none recorded at your sites
                                            </PageHeaderMeterCaption>
                                        </>
                                    )}
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Maintenance logged"
                                    ariaLabel="Show maintenance logged by month"
                                    onClick={() => showBlock('ac-maintenance')}
                                >
                                    <PageHeaderMeterBig>
                                        {s.maintenance_done}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        {`${formatMoney(s.maintenance_spend)} spent in this period`}
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                            </>
                        }
                        filters={<ReportFilterRow scope={scope} path={PATH} />}
                        rail={
                            <PageHeaderRail<View>
                                ariaLabel="Condition"
                                value={view}
                                onSelect={setView}
                                items={[
                                    {
                                        key: 'all',
                                        label: 'Needs attention',
                                        count: s.needs_attention,
                                    },
                                    {
                                        key: 'out_of_service',
                                        label: 'Out of service',
                                        count: s.out_of_service,
                                        alert: true,
                                    },
                                    {
                                        key: 'maintenance',
                                        label: 'Maintenance overdue',
                                        count: s.maintenance_overdue,
                                    },
                                    {
                                        key: 'inspection',
                                        label: 'Inspection overdue',
                                        count: s.inspection_overdue,
                                    },
                                    {
                                        key: 'warranty',
                                        label: 'Warranty ending',
                                        count:
                                            s.warranty_expired +
                                            s.warranty_expiring_soon,
                                    },
                                ]}
                            />
                        }
                    />
                }
            >
                {s.total_assets === 0 ? (
                    <Surface>
                        <EmptyState
                            icon={Package}
                            title="No assets at your sites"
                            description="Assets appear here once they are recorded against a site you can see."
                            className="border-0"
                        />
                    </Surface>
                ) : (
                    <div className="grid gap-5">
                        <div className="grid gap-5 lg:grid-cols-2">
                            <ReportBlock
                                id="ac-status"
                                title="Assets by status"
                                sub="Every asset at your sites, retired ones included"
                            >
                                <CategoryBars
                                    rows={byStatus.map((row) => ({
                                        key: row.status,
                                        label: assetStatusLabel(row.status),
                                        value: row.count,
                                    }))}
                                    unit="Assets"
                                    labelHeading="Status"
                                    showShare
                                    emptyTitle="No assets recorded"
                                />
                            </ReportBlock>
                            <ReportBlock
                                id="ac-maintenance"
                                title="Maintenance logged"
                                sub={`Jobs logged against these assets, by ${filters.unit}`}
                                action={{
                                    label: 'Inspections and maintenance',
                                    href: reportHref(
                                        `${REPORTS_BASE}/inspections-maintenance`,
                                        filters,
                                    ),
                                }}
                            >
                                <TrendChart
                                    rows={trend.map((bucket) => ({
                                        key: bucket.key,
                                        label: bucketLabel(
                                            bucket,
                                            filters.unit,
                                        ),
                                        value: bucket.maintenance_done,
                                    }))}
                                    unit="Jobs"
                                    labelHeading="Period"
                                    emptyTitle="No maintenance was logged in this period"
                                />
                            </ReportBlock>
                        </div>

                        <section
                            id="ac-list"
                            tabIndex={-1}
                            aria-label="Assets needing attention"
                            className="grid scroll-mt-5 gap-3 outline-none"
                        >
                            <ListCaption
                                title="Assets needing attention"
                                caption={`${shown.length} shown · most to do first`}
                            />
                            {shown.length === 0 ? (
                                <QuietLine
                                    text={
                                        q
                                            ? `No asset matches “${query.trim()}”.`
                                            : 'Nothing in this view needs attention.'
                                    }
                                />
                            ) : (
                                <>
                                    <EntityTable
                                        rows={paged.rows}
                                        rowKey={(row) => row.id}
                                        identityLabel="Asset"
                                        identity={(row) => ({
                                            icon: Wrench,
                                            name: row.name,
                                            subline: [
                                                row.category,
                                                row.asset_tag,
                                            ]
                                                .filter(Boolean)
                                                .join(' · '),
                                        })}
                                        columns={[
                                            {
                                                key: 'site',
                                                label: 'Site',
                                                width: '1.2fr',
                                                cell: (row) =>
                                                    row.site ? (
                                                        <Link
                                                            href={siteReportHref(
                                                                row.site.id,
                                                                filters,
                                                            )}
                                                            onClick={(event) =>
                                                                event.stopPropagation()
                                                            }
                                                            className="truncate underline-offset-4 hover:underline focus-visible:ring-2 focus-visible:ring-ring"
                                                        >
                                                            {row.site.name}
                                                        </Link>
                                                    ) : (
                                                        <span className="text-muted-foreground">
                                                            —
                                                        </span>
                                                    ),
                                            },
                                            {
                                                key: 'status',
                                                label: 'Status',
                                                width: '1fr',
                                                cell: (row) => (
                                                    <EntityStatusChip
                                                        variant={
                                                            row.flags.includes(
                                                                'out_of_service',
                                                            )
                                                                ? 'warning'
                                                                : 'success'
                                                        }
                                                    >
                                                        {assetStatusLabel(
                                                            row.status,
                                                        )}
                                                    </EntityStatusChip>
                                                ),
                                            },
                                            {
                                                key: 'maintenance',
                                                label: 'Maintenance due',
                                                width: '1.3fr',
                                                cell: (row) => (
                                                    <DueCell
                                                        date={
                                                            row.maintenance_due_at
                                                        }
                                                        daysOverdue={
                                                            row.days_overdue
                                                        }
                                                        none="No date set"
                                                    />
                                                ),
                                            },
                                            {
                                                key: 'inspection',
                                                label: 'Inspection due',
                                                width: '1.3fr',
                                                cell: (row) => (
                                                    <DueCell
                                                        date={
                                                            row.inspection_due_at
                                                        }
                                                        daysOverdue={daysLate(
                                                            row.inspection_due_at,
                                                            filters.today,
                                                        )}
                                                        none="No date set"
                                                    />
                                                ),
                                            },
                                            {
                                                key: 'warranty',
                                                label: 'Warranty',
                                                width: '1.2fr',
                                                cell: (row) =>
                                                    row.warranty_expires_at ? (
                                                        <span className="flex min-w-0 items-center gap-2">
                                                            <span className="truncate">
                                                                {formatDateOnly(
                                                                    row.warranty_expires_at,
                                                                )}
                                                            </span>
                                                            {row.flags.includes(
                                                                'warranty_expired',
                                                            ) ? (
                                                                <EntityStatusChip variant="critical">
                                                                    Ended
                                                                </EntityStatusChip>
                                                            ) : row.flags.includes(
                                                                  'warranty_expiring',
                                                              ) ? (
                                                                <EntityStatusChip variant="warning">
                                                                    Ends soon
                                                                </EntityStatusChip>
                                                            ) : null}
                                                        </span>
                                                    ) : (
                                                        <span className="text-muted-foreground">
                                                            —
                                                        </span>
                                                    ),
                                            },
                                        ]}
                                        hrefFor={(row) =>
                                            `/fleet-assets/assets/${row.id}`
                                        }
                                        onOpen={(row) =>
                                            router.visit(
                                                `/fleet-assets/assets/${row.id}`,
                                            )
                                        }
                                        actionsFor={actionsFor}
                                        onRowContextMenu={menu.open}
                                        minWidth={1040}
                                    />
                                    <Pager {...paged} />
                                </>
                            )}
                            {assets.length >= 500 ? (
                                <p className="text-caption">
                                    {`Showing the first 500 of ${plural(s.needs_attention, 'asset')} needing attention. Narrow the region to see the rest.`}
                                </p>
                            ) : null}
                        </section>
                    </div>
                )}
                {menu.ctx ? (
                    <EntityContextMenu
                        {...menu.ctx}
                        onClose={menu.close}
                        title={menu.ctx.record.name}
                        items={actionsFor(menu.ctx.record)}
                    />
                ) : null}
            </PageLayout>
        </AppLayout>
    );
}
