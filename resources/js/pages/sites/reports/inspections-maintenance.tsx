import {
    EntityContextMenu,
    EntityStatusChip,
    EntityTable,
    ListCaption,
    PersonCell,
    useEntityContextMenu,
    type EntityTableColumn,
    type MenuItem,
} from '@/components/lists';
import {
    PageHeader,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderMeterDonut,
    PageHeaderSearch,
    PageLayout,
} from '@/components/page';
import { QuietLine } from '@/components/signature/blocks';
import {
    DueCell,
    OnTimeTrendChart,
    Pager,
    REPORTS_BASE,
    ReportBlock,
    ReportFilterRow,
    assetStatusLabel,
    formatMoney,
    plural,
    rateRows,
    rateTone,
    reportHref,
    scopeWords,
    sentenceLabel,
    showBlock,
    siteReportHref,
    usePaged,
    type ReportFilters,
    type ReportMetrics,
    type ReportScope,
    type SiteRef,
    type TrendBucket,
} from '@/components/sites/reports/report-kit';
import AppLayout from '@/layouts/app-layout';
import { formatDate, formatDateOnly } from '@/lib/datetime';
import { Head, Link, router } from '@inertiajs/react';
import {
    FileBarChart,
    Package,
    ShieldAlert,
    ShieldCheck,
    Wrench,
} from 'lucide-react';
import { useState, type ReactNode } from 'react';

type OverdueInspection = {
    id: number;
    title: string;
    inspection_type: string | null;
    next_due_date: string | null;
    days_overdue: number;
    assigned_to: string | null;
    site: SiteRef | null;
};

type FailedInspection = {
    id: number;
    title: string;
    inspection_type: string | null;
    completed_at: string | null;
    findings: string | null;
    site: SiteRef | null;
};

type OverdueAsset = {
    id: number;
    name: string;
    asset_tag: string | null;
    category: string | null;
    status: string | null;
    maintenance_due_at: string | null;
    days_overdue: number;
    site: SiteRef | null;
};

type SlippedJob = {
    id: number;
    asset_id: number;
    asset_name: string | null;
    asset_tag: string | null;
    due_date: string;
    outcome: 'late' | 'missed';
    site: SiteRef | null;
};

type Props = ReportScope & {
    totals: ReportMetrics;
    trend: TrendBucket[];
    overdueInspections: OverdueInspection[];
    failedInspections: FailedInspection[];
    overdueMaintenance: OverdueAsset[];
    slippedMaintenance: SlippedJob[];
};

const PATH = `${REPORTS_BASE}/inspections-maintenance`;

function siteColumn<T extends { site: SiteRef | null }>(
    filters: ReportFilters,
): EntityTableColumn<T> {
    return {
        key: 'site',
        label: 'Site',
        width: '1.3fr',
        cell: (row) =>
            row.site ? (
                <Link
                    href={siteReportHref(row.site.id, filters)}
                    onClick={(event) => event.stopPropagation()}
                    className="truncate underline-offset-4 hover:underline focus-visible:ring-2 focus-visible:ring-ring"
                >
                    {row.site.name}
                </Link>
            ) : (
                <span className="text-muted-foreground">—</span>
            ),
    };
}

function siteReportItem(
    site: SiteRef | null,
    filters: ReportFilters,
): MenuItem[] {
    return site
        ? [
              {
                  label: 'Open site report',
                  icon: FileBarChart,
                  onClick: () => router.visit(siteReportHref(site.id, filters)),
              },
          ]
        : [];
}

/**
 * Inspections and maintenance — where is maintenance slipping? Inspections
 * done by their due date, schedules past due now, failed inspections, and
 * asset maintenance done late or missed, at the Sites the viewer can access.
 */
export default function InspectionsMaintenance(props: Props) {
    const {
        totals: t,
        trend,
        filters,
        overdueInspections,
        failedInspections,
        overdueMaintenance,
        slippedMaintenance,
    } = props;
    const scope: ReportScope = props;
    const [query, setQuery] = useState('');
    const q = query.trim().toLowerCase();
    const has = (...parts: (string | null | undefined)[]) =>
        !q || parts.filter(Boolean).join(' ').toLowerCase().includes(q);

    const inspections = overdueInspections.filter((row) =>
        has(row.title, row.inspection_type, row.site?.name, row.assigned_to),
    );
    const failed = failedInspections.filter((row) =>
        has(row.title, row.findings, row.site?.name),
    );
    const assets = overdueMaintenance.filter((row) =>
        has(row.name, row.asset_tag, row.category, row.site?.name),
    );
    const slipped = slippedMaintenance.filter((row) =>
        has(row.asset_name, row.asset_tag, row.site?.name),
    );
    const pagedInspections = usePaged(inspections, q);
    const pagedAssets = usePaged(assets, q);
    const pagedSlipped = usePaged(slipped, q);

    const inspectionMenu = useEntityContextMenu<OverdueInspection>();
    const failedMenu = useEntityContextMenu<FailedInspection>();
    const assetMenu = useEntityContextMenu<OverdueAsset>();
    const slippedMenu = useEntityContextMenu<SlippedJob>();

    const inspectionActions = (row: { site: SiteRef | null }): MenuItem[] => [
        ...(row.site
            ? [
                  {
                      label: 'Open inspections',
                      icon: ShieldCheck,
                      onClick: () =>
                          router.visit(`/sites/${row.site!.id}/inspections`),
                  },
              ]
            : []),
        ...siteReportItem(row.site, filters),
    ];
    const assetActions = (row: {
        asset_id?: number;
        id: number;
        site: SiteRef | null;
    }): MenuItem[] => [
        {
            label: 'Open asset',
            icon: Package,
            onClick: () =>
                router.visit(`/fleet-assets/assets/${row.asset_id ?? row.id}`),
        },
        ...siteReportItem(row.site, filters),
    ];

    const inspectionRate = t.inspections_on_time_rate;
    const maintenanceRate = t.maintenance_on_time_rate;

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Sites', href: '/sites' },
                { title: 'Reports', href: reportHref(REPORTS_BASE, filters) },
                {
                    title: 'Inspections and maintenance',
                    href: reportHref(PATH, filters),
                },
            ]}
        >
            <Head title="Inspections and maintenance" />

            <PageLayout
                hero={
                    <PageHeader
                        icon={Wrench}
                        title="Inspections and maintenance"
                        subline={`Inspections and asset maintenance done on time, late or missed · ${scopeWords(scope)}`}
                        actions={
                            <PageHeaderSearch
                                value={query}
                                onChange={setQuery}
                                placeholder="Search inspections, assets, sites…"
                            />
                        }
                        meters={
                            <>
                                <PageHeaderMeterBlock
                                    label="Inspections on time"
                                    tone={
                                        rateTone(inspectionRate) === 'critical'
                                            ? 'critical'
                                            : rateTone(inspectionRate) ===
                                                'warning'
                                              ? 'warning'
                                              : 'brand'
                                    }
                                    ariaLabel="Show inspections done on time by month"
                                    onClick={() =>
                                        showBlock('im-inspection-trend')
                                    }
                                >
                                    {inspectionRate !== null ? (
                                        <PageHeaderMeterDonut
                                            percent={inspectionRate}
                                            caption={
                                                <>
                                                    {t.inspections_on_time} of{' '}
                                                    {t.inspections_due}
                                                    <br />
                                                    inspections due
                                                </>
                                            }
                                        />
                                    ) : (
                                        <>
                                            <PageHeaderMeterBig>
                                                Nothing due
                                            </PageHeaderMeterBig>
                                            <PageHeaderMeterCaption>
                                                no inspections due in this
                                                period
                                            </PageHeaderMeterCaption>
                                        </>
                                    )}
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Inspections overdue"
                                    tone={
                                        t.inspections_overdue_now > 0
                                            ? 'warning'
                                            : 'success'
                                    }
                                    ariaLabel="Show overdue inspections"
                                    onClick={() =>
                                        showBlock('im-overdue-inspections')
                                    }
                                >
                                    <PageHeaderMeterBig>
                                        {t.inspections_overdue_now}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        schedules past their due date
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Failed inspections"
                                    tone={
                                        t.inspections_failed > 0
                                            ? 'critical'
                                            : 'success'
                                    }
                                    ariaLabel="Show failed inspections"
                                    onClick={() => showBlock('im-failed')}
                                >
                                    <PageHeaderMeterBig>
                                        {t.inspections_failed}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        in this period
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Maintenance on time"
                                    tone={
                                        rateTone(maintenanceRate) === 'critical'
                                            ? 'critical'
                                            : rateTone(maintenanceRate) ===
                                                'warning'
                                              ? 'warning'
                                              : 'brand'
                                    }
                                    ariaLabel="Show maintenance done late or missed"
                                    onClick={() => showBlock('im-slipped')}
                                >
                                    {maintenanceRate !== null ? (
                                        <PageHeaderMeterDonut
                                            percent={maintenanceRate}
                                            caption={
                                                <>
                                                    {t.maintenance_late} late
                                                    <br />
                                                    {t.maintenance_missed}{' '}
                                                    missed
                                                </>
                                            }
                                        />
                                    ) : (
                                        <>
                                            <PageHeaderMeterBig>
                                                Nothing due
                                            </PageHeaderMeterBig>
                                            <PageHeaderMeterCaption>
                                                no maintenance fell due
                                            </PageHeaderMeterCaption>
                                        </>
                                    )}
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Past maintenance date"
                                    tone={
                                        t.maintenance_overdue_now > 0
                                            ? 'warning'
                                            : 'success'
                                    }
                                    ariaLabel="Show assets past their maintenance date"
                                    onClick={() =>
                                        showBlock('im-overdue-maintenance')
                                    }
                                >
                                    <PageHeaderMeterBig>
                                        {t.maintenance_overdue_now}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        {`of ${plural(t.assets, 'asset')} in use`}
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Maintenance spend"
                                    ariaLabel="Show maintenance done on time by month"
                                    onClick={() =>
                                        showBlock('im-maintenance-trend')
                                    }
                                >
                                    <PageHeaderMeterBig>
                                        {formatMoney(t.maintenance_spend)}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        {`${plural(t.maintenance_done, 'job')} logged`}
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                            </>
                        }
                        filters={<ReportFilterRow scope={scope} path={PATH} />}
                    />
                }
            >
                <div className="grid gap-5">
                    <div className="grid gap-5 lg:grid-cols-2">
                        <ReportBlock
                            id="im-inspection-trend"
                            title="Inspections done on time"
                            sub={`Done by their due date, by ${filters.unit}`}
                        >
                            <OnTimeTrendChart
                                rows={rateRows(
                                    trend,
                                    filters.unit,
                                    'inspections',
                                )}
                                noun="inspections"
                                emptyTitle="No inspections were due in this period"
                            />
                        </ReportBlock>
                        <ReportBlock
                            id="im-maintenance-trend"
                            title="Maintenance done on time"
                            sub={`Done by the next-due date on the last job, by ${filters.unit}`}
                        >
                            <OnTimeTrendChart
                                rows={rateRows(
                                    trend,
                                    filters.unit,
                                    'maintenance',
                                )}
                                noun="maintenance jobs"
                                emptyTitle="No maintenance fell due in this period"
                                emptyDescription="Maintenance counts here once a logged job sets a next-due date."
                            />
                        </ReportBlock>
                    </div>

                    <Section
                        id="im-overdue-inspections"
                        title="Inspections past their due date"
                        caption="Oldest first"
                    >
                        {inspections.length === 0 ? (
                            <QuietLine
                                text={
                                    q
                                        ? `No overdue inspection matches “${query.trim()}”.`
                                        : 'Every active inspection schedule is within its due date.'
                                }
                            />
                        ) : (
                            <>
                                <EntityTable
                                    rows={pagedInspections.rows}
                                    rowKey={(row) => row.id}
                                    identityLabel="Inspection"
                                    identity={(row) => ({
                                        icon: ShieldCheck,
                                        name: row.title,
                                        subline: sentenceLabel(
                                            row.inspection_type,
                                        ),
                                    })}
                                    columns={[
                                        siteColumn<OverdueInspection>(filters),
                                        {
                                            key: 'due',
                                            label: 'Was due',
                                            width: '1.4fr',
                                            cell: (row) => (
                                                <DueCell
                                                    date={row.next_due_date}
                                                    daysOverdue={
                                                        row.days_overdue
                                                    }
                                                    none="—"
                                                />
                                            ),
                                        },
                                        {
                                            key: 'assigned',
                                            label: 'Assigned to',
                                            width: '1.2fr',
                                            cell: (row) => (
                                                <PersonCell
                                                    name={row.assigned_to}
                                                />
                                            ),
                                        },
                                    ]}
                                    onOpen={(row) =>
                                        row.site &&
                                        router.visit(
                                            `/sites/${row.site.id}/inspections`,
                                        )
                                    }
                                    actionsFor={inspectionActions}
                                    onRowContextMenu={inspectionMenu.open}
                                    minWidth={820}
                                />
                                <Pager {...pagedInspections} />
                            </>
                        )}
                    </Section>

                    <Section
                        id="im-failed"
                        title="Failed inspections"
                        caption="Done in this period with a fail result"
                    >
                        {failed.length === 0 ? (
                            <QuietLine
                                text={
                                    q
                                        ? `No failed inspection matches “${query.trim()}”.`
                                        : 'No inspection failed in this period.'
                                }
                            />
                        ) : (
                            <EntityTable
                                rows={failed}
                                rowKey={(row) => row.id}
                                identityLabel="Inspection"
                                identityWidth="2.4fr"
                                wrapIdentity
                                rowHeight="content"
                                identity={(row) => ({
                                    icon: ShieldAlert,
                                    name: row.title,
                                    subline:
                                        row.findings ??
                                        sentenceLabel(row.inspection_type),
                                })}
                                columns={[
                                    siteColumn<FailedInspection>(filters),
                                    {
                                        key: 'done',
                                        label: 'Done',
                                        width: '1fr',
                                        cell: (row) =>
                                            formatDate(row.completed_at),
                                    },
                                ]}
                                onOpen={(row) =>
                                    row.site &&
                                    router.visit(
                                        `/sites/${row.site.id}/inspections`,
                                    )
                                }
                                actionsFor={inspectionActions}
                                onRowContextMenu={failedMenu.open}
                                minWidth={720}
                            />
                        )}
                    </Section>

                    <Section
                        id="im-overdue-maintenance"
                        title="Assets past their maintenance date"
                        caption="Most overdue first"
                    >
                        {assets.length === 0 ? (
                            <QuietLine
                                text={
                                    q
                                        ? `No asset matches “${query.trim()}”.`
                                        : 'No asset in use is past its maintenance date.'
                                }
                            />
                        ) : (
                            <>
                                <EntityTable
                                    rows={pagedAssets.rows}
                                    rowKey={(row) => row.id}
                                    identityLabel="Asset"
                                    identity={(row) => ({
                                        icon: Wrench,
                                        name: row.name,
                                        subline: [row.category, row.asset_tag]
                                            .filter(Boolean)
                                            .join(' · '),
                                    })}
                                    columns={[
                                        siteColumn<OverdueAsset>(filters),
                                        {
                                            key: 'status',
                                            label: 'Status',
                                            width: '1fr',
                                            cell: (row) => (
                                                <EntityStatusChip
                                                    variant={
                                                        row.status === 'active'
                                                            ? 'success'
                                                            : 'warning'
                                                    }
                                                >
                                                    {assetStatusLabel(
                                                        row.status,
                                                    )}
                                                </EntityStatusChip>
                                            ),
                                        },
                                        {
                                            key: 'due',
                                            label: 'Maintenance due',
                                            width: '1.4fr',
                                            cell: (row) => (
                                                <DueCell
                                                    date={
                                                        row.maintenance_due_at
                                                    }
                                                    daysOverdue={
                                                        row.days_overdue
                                                    }
                                                    none="—"
                                                />
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
                                    actionsFor={assetActions}
                                    onRowContextMenu={assetMenu.open}
                                    minWidth={820}
                                />
                                <Pager {...pagedAssets} />
                            </>
                        )}
                    </Section>

                    <Section
                        id="im-slipped"
                        title="Maintenance done late or missed"
                        caption="Jobs that fell due in this period"
                    >
                        {slipped.length === 0 ? (
                            <QuietLine
                                text={
                                    q
                                        ? `No maintenance job matches “${query.trim()}”.`
                                        : t.maintenance_due > 0
                                          ? 'Every maintenance job due in this period was done on time.'
                                          : 'No maintenance fell due in this period.'
                                }
                            />
                        ) : (
                            <>
                                <EntityTable
                                    rows={pagedSlipped.rows}
                                    rowKey={(row) => row.id}
                                    identityLabel="Asset"
                                    identity={(row) => ({
                                        icon: Wrench,
                                        name: row.asset_name ?? 'Asset',
                                        subline: row.asset_tag ?? undefined,
                                    })}
                                    columns={[
                                        siteColumn<SlippedJob>(filters),
                                        {
                                            key: 'due',
                                            label: 'Was due',
                                            width: '1fr',
                                            cell: (row) =>
                                                formatDateOnly(row.due_date),
                                        },
                                        {
                                            key: 'outcome',
                                            label: 'Outcome',
                                            width: '1fr',
                                            cell: (row) =>
                                                row.outcome === 'missed' ? (
                                                    <EntityStatusChip variant="critical">
                                                        Not done yet
                                                    </EntityStatusChip>
                                                ) : (
                                                    <EntityStatusChip variant="warning">
                                                        Done late
                                                    </EntityStatusChip>
                                                ),
                                        },
                                    ]}
                                    onOpen={(row) =>
                                        router.visit(
                                            `/fleet-assets/assets/${row.asset_id}`,
                                        )
                                    }
                                    actionsFor={assetActions}
                                    onRowContextMenu={slippedMenu.open}
                                    minWidth={760}
                                />
                                <Pager {...pagedSlipped} />
                            </>
                        )}
                    </Section>
                </div>

                {inspectionMenu.ctx ? (
                    <EntityContextMenu
                        {...inspectionMenu.ctx}
                        onClose={inspectionMenu.close}
                        title={inspectionMenu.ctx.record.title}
                        items={inspectionActions(inspectionMenu.ctx.record)}
                    />
                ) : null}
                {failedMenu.ctx ? (
                    <EntityContextMenu
                        {...failedMenu.ctx}
                        onClose={failedMenu.close}
                        title={failedMenu.ctx.record.title}
                        items={inspectionActions(failedMenu.ctx.record)}
                    />
                ) : null}
                {assetMenu.ctx ? (
                    <EntityContextMenu
                        {...assetMenu.ctx}
                        onClose={assetMenu.close}
                        title={assetMenu.ctx.record.name}
                        items={assetActions(assetMenu.ctx.record)}
                    />
                ) : null}
                {slippedMenu.ctx ? (
                    <EntityContextMenu
                        {...slippedMenu.ctx}
                        onClose={slippedMenu.close}
                        title={slippedMenu.ctx.record.asset_name ?? 'Asset'}
                        items={assetActions(slippedMenu.ctx.record)}
                    />
                ) : null}
            </PageLayout>
        </AppLayout>
    );
}

function Section({
    id,
    title,
    caption,
    children,
}: {
    id: string;
    title: string;
    caption?: ReactNode;
    children: ReactNode;
}) {
    return (
        <section
            id={id}
            tabIndex={-1}
            aria-label={title}
            className="grid min-w-0 scroll-mt-5 gap-3 outline-none focus-visible:ring-2 focus-visible:ring-ring"
        >
            <ListCaption title={title} caption={caption} />
            {children}
        </section>
    );
}
