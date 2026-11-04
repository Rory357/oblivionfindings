import { TrendChart } from '@/components/control-room/reports/report-parts';
import {
    CounterPill,
    EntityTable,
    ListCaption,
    ProgressValue,
    type EntityTableColumn,
} from '@/components/lists';
import {
    PageHeader,
    PageHeaderGlassButton,
    PageHeaderMeterBar,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderMeterDonut,
    PageHeaderSearch,
    PageLayout,
} from '@/components/page';
import { Surface } from '@/components/signature/blocks';
import {
    OnTimeTrendChart,
    PACK_PATH,
    Pager,
    REPORTS_BASE,
    ReportBlock,
    ReportFilterRow,
    bucketLabel,
    formatRate,
    plural,
    rateRows,
    rateTone,
    reportHref,
    scopeParams,
    scopeWords,
    showBlock,
    siteMenu,
    siteReportHref,
    usePaged,
    useSiteContextMenu,
    type PackKey,
    type ReportMetrics,
    type ReportScope,
    type SiteRow,
    type SiteRowMetrics,
    type TrendBucket,
} from '@/components/sites/reports/report-kit';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import {
    Building2,
    Download,
    Home,
    MapPinOff,
    Warehouse,
    type LucideIcon,
} from 'lucide-react';
import { useMemo, useState } from 'react';

type PackSite = SiteRow & {
    metrics: SiteRowMetrics;
    extras: Record<string, number>;
};

type Props = ReportScope & {
    pack: { key: PackKey; label: string; site_count_all: number };
    totals: ReportMetrics;
    extraTotals: Record<string, number>;
    trend: TrendBucket[];
    sites: PackSite[];
};

type Attention = 'all' | 'safety' | 'checks' | 'maintenance';

const PACKS: Record<
    PackKey,
    {
        title: string;
        crumb: string;
        icon: LucideIcon;
        one: string;
        many: string;
        exportType: string;
    }
> = {
    houses: {
        title: 'House reports',
        crumb: 'Houses',
        icon: Home,
        one: 'house',
        many: 'houses',
        exportType: 'houses',
    },
    facilities: {
        title: 'Facility reports',
        crumb: 'Facilities',
        icon: Warehouse,
        one: 'facility',
        many: 'facilities',
        exportType: 'facilities',
    },
    'head-office': {
        title: 'Head office reports',
        crumb: 'Head office',
        icon: Building2,
        one: 'head office',
        many: 'head offices',
        exportType: 'head_office',
    },
};

const ATTENTION_WORDS: Record<Exclude<Attention, 'all'>, string> = {
    safety: 'with overdue actions or high hazards',
    checks: 'with missed checks or overdue inspections',
    maintenance: 'with maintenance past due',
};

function matchesAttention(m: SiteRowMetrics, attention: Attention): boolean {
    switch (attention) {
        case 'safety':
            return m.actions_overdue > 0 || m.hazards_serious > 0;
        case 'checks':
            return m.checks_missed > 0 || m.inspections_overdue_now > 0;
        case 'maintenance':
            return m.maintenance_overdue_now > 0 || m.assets_out_of_service > 0;
        default:
            return true;
    }
}

function attentionScore(m: SiteRowMetrics): number {
    return (
        m.actions_overdue * 3 +
        m.hazards_serious * 3 +
        m.checks_missed * 2 +
        m.inspections_overdue_now * 2 +
        m.maintenance_overdue_now
    );
}

/**
 * One report pack (Houses, Facilities or Head office): for every Site of
 * that type, is it safe, are checks done on time, is maintenance slipping.
 * A Site row opens that Site's report; its menu also opens the profile.
 */
export default function PackReport(props: Props) {
    const { pack, filters, can, totals, extraTotals, trend, sites } = props;
    const scope: ReportScope = props;
    const config = PACKS[pack.key];
    const path = PACK_PATH[pack.key];
    const [query, setQuery] = useState('');
    const [attention, setAttention] = useState<Attention>('all');
    const siteContext = useSiteContextMenu<PackSite>(filters);

    const q = query.trim().toLowerCase();
    const sorted = useMemo(
        () =>
            [...sites].sort(
                (a, b) =>
                    attentionScore(b.metrics) - attentionScore(a.metrics) ||
                    a.name.localeCompare(b.name),
            ),
        [sites],
    );
    const shown = useMemo(
        () =>
            sorted.filter(
                (site) =>
                    matchesAttention(site.metrics, attention) &&
                    (!q ||
                        `${site.name} ${site.region ?? ''}`
                            .toLowerCase()
                            .includes(q)),
            ),
        [sorted, attention, q],
    );
    const paged = usePaged(shown, `${attention}|${q}`);

    const toggle = (next: Attention) => {
        setAttention((current) => (current === next ? 'all' : next));
        showBlock('pack-sites');
    };

    const checksRate = totals.checks_on_time_rate;
    const maintenanceRate = totals.maintenance_on_time_rate;
    const occupancy =
        (extraTotals.bedrooms ?? 0) > 0
            ? Math.round(
                  ((extraTotals.clients ?? 0) / (extraTotals.bedrooms ?? 1)) *
                      100,
              )
            : null;
    const exportHref = `${REPORTS_BASE}/export?${new URLSearchParams(
        scopeParams(filters, { type: config.exportType, format: 'csv' }),
    ).toString()}`;

    const columns: EntityTableColumn<PackSite>[] = [
        {
            key: 'actions',
            label: 'Overdue actions',
            width: '0.9fr',
            align: 'center',
            cell: (site) => (
                <CounterPill
                    tone={
                        site.metrics.actions_overdue > 0
                            ? 'critical'
                            : 'neutral'
                    }
                >
                    {site.metrics.actions_overdue}
                </CounterPill>
            ),
        },
        {
            key: 'serious',
            label: 'High or critical',
            width: '0.9fr',
            align: 'center',
            cell: (site) => (
                <CounterPill
                    tone={
                        site.metrics.hazards_serious > 0
                            ? 'critical'
                            : 'neutral'
                    }
                >
                    {site.metrics.hazards_serious}
                </CounterPill>
            ),
        },
        {
            key: 'checks',
            label: 'Checks on time',
            width: '1.3fr',
            cell: (site) => {
                const rate = site.metrics.checks_on_time_rate;
                const tone = rateTone(rate);
                return rate === null ? (
                    <span className="text-muted-foreground">Nothing due</span>
                ) : (
                    <ProgressValue
                        percent={rate}
                        tone={tone === 'neutral' ? 'brand' : tone}
                    >
                        {formatRate(rate)}
                        {site.metrics.checks_missed > 0
                            ? ` · ${site.metrics.checks_missed} missed`
                            : ''}
                    </ProgressValue>
                );
            },
        },
        {
            key: 'inspections',
            label: 'Inspections overdue',
            width: '1fr',
            align: 'center',
            cell: (site) => (
                <CounterPill
                    tone={
                        site.metrics.inspections_overdue_now > 0
                            ? 'warning'
                            : 'neutral'
                    }
                >
                    {site.metrics.inspections_overdue_now}
                </CounterPill>
            ),
        },
        {
            key: 'maintenance',
            label: 'Maintenance past due',
            width: '1fr',
            align: 'center',
            cell: (site) => (
                <CounterPill
                    tone={
                        site.metrics.maintenance_overdue_now > 0
                            ? 'warning'
                            : 'neutral'
                    }
                >
                    {site.metrics.maintenance_overdue_now}
                </CounterPill>
            ),
        },
        ...packColumns(pack.key),
    ];

    const noSites = pack.site_count_all === 0;

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Sites', href: '/sites' },
                { title: 'Reports', href: reportHref(REPORTS_BASE, filters) },
                { title: config.crumb, href: reportHref(path, filters) },
            ]}
        >
            <Head title={config.title} />

            <PageLayout
                hero={
                    <PageHeader
                        icon={config.icon}
                        title={config.title}
                        subline={`Safety, checks on time and maintenance for each ${config.one} · ${plural(sites.length, config.one, config.many)} · ${scopeWords(scope)}`}
                        actions={
                            <>
                                <PageHeaderSearch
                                    value={query}
                                    onChange={setQuery}
                                    placeholder={`Search ${config.many}, regions…`}
                                />
                                {can.export ? (
                                    <PageHeaderGlassButton
                                        icon={Download}
                                        asChild
                                    >
                                        <a href={exportHref}>Export CSV</a>
                                    </PageHeaderGlassButton>
                                ) : null}
                            </>
                        }
                        meters={
                            <>
                                <PageHeaderMeterBlock
                                    label="Overdue actions"
                                    tone={
                                        totals.actions_overdue > 0
                                            ? 'critical'
                                            : 'success'
                                    }
                                    pressed={attention === 'safety'}
                                    ariaLabel={`Show ${config.many} with overdue actions or high hazards`}
                                    onClick={() => toggle('safety')}
                                >
                                    <PageHeaderMeterBig>
                                        {totals.actions_overdue}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        {`${plural(totals.hazards_serious, 'high or critical hazard')} open`}
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Checks on time"
                                    tone={
                                        rateTone(checksRate) === 'critical'
                                            ? 'critical'
                                            : rateTone(checksRate) === 'warning'
                                              ? 'warning'
                                              : 'brand'
                                    }
                                    pressed={attention === 'checks'}
                                    ariaLabel={`Show ${config.many} with missed checks`}
                                    onClick={() => toggle('checks')}
                                >
                                    {checksRate !== null ? (
                                        <PageHeaderMeterDonut
                                            percent={checksRate}
                                            caption={
                                                <>
                                                    {totals.checks_on_time} of{' '}
                                                    {totals.checks_due} due
                                                    <br />
                                                    {totals.checks_missed}{' '}
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
                                                no checks due in this period
                                            </PageHeaderMeterCaption>
                                        </>
                                    )}
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Inspections overdue"
                                    tone={
                                        totals.inspections_overdue_now > 0
                                            ? 'warning'
                                            : 'success'
                                    }
                                    pressed={attention === 'checks'}
                                    ariaLabel={`Show ${config.many} with overdue inspections`}
                                    onClick={() => toggle('checks')}
                                >
                                    <PageHeaderMeterBig>
                                        {totals.inspections_overdue_now}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        {`${plural(totals.inspections_missed, 'inspection')} missed in period`}
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Maintenance on time"
                                    tone={
                                        totals.maintenance_overdue_now > 0
                                            ? 'warning'
                                            : 'brand'
                                    }
                                    pressed={attention === 'maintenance'}
                                    ariaLabel={`Show ${config.many} with maintenance past due`}
                                    onClick={() => toggle('maintenance')}
                                >
                                    {maintenanceRate !== null ? (
                                        <PageHeaderMeterDonut
                                            percent={maintenanceRate}
                                            caption={
                                                <>
                                                    {plural(
                                                        totals.maintenance_overdue_now,
                                                        'asset',
                                                    )}
                                                    <br />
                                                    past due now
                                                </>
                                            }
                                        />
                                    ) : (
                                        <>
                                            <PageHeaderMeterBig>
                                                Nothing due
                                            </PageHeaderMeterBig>
                                            <PageHeaderMeterCaption>
                                                {`${plural(totals.maintenance_overdue_now, 'asset')} past due now`}
                                            </PageHeaderMeterCaption>
                                        </>
                                    )}
                                </PageHeaderMeterBlock>
                                {pack.key === 'houses' && occupancy !== null ? (
                                    <PageHeaderMeterBlock
                                        label="Occupancy"
                                        value={`${extraTotals.clients ?? 0}/${extraTotals.bedrooms ?? 0}`}
                                        ariaLabel="View clients"
                                        href="/operations/clients"
                                    >
                                        <PageHeaderMeterBar
                                            percent={occupancy}
                                        />
                                        <PageHeaderMeterCaption>
                                            {occupancy}% of bedrooms filled
                                        </PageHeaderMeterCaption>
                                    </PageHeaderMeterBlock>
                                ) : null}
                            </>
                        }
                        filters={<ReportFilterRow scope={scope} path={path} />}
                    />
                }
            >
                {noSites || sites.length === 0 ? (
                    <Surface>
                        <EmptyState
                            icon={MapPinOff}
                            title={
                                noSites
                                    ? `No ${config.many} yet`
                                    : `No ${config.many} in ${filters.region}`
                            }
                            description={
                                noSites
                                    ? `This pack fills in once a site of this type is added, or once you are given access to one.`
                                    : 'Choose another region, or all regions, in the header.'
                            }
                            action={
                                noSites ? (
                                    <Button asChild size="sm" variant="outline">
                                        <Link
                                            href={reportHref(
                                                REPORTS_BASE,
                                                filters,
                                            )}
                                        >
                                            Back to site reports
                                        </Link>
                                    </Button>
                                ) : undefined
                            }
                            className="border-0"
                        />
                    </Surface>
                ) : (
                    <div className="grid gap-5">
                        <div className="grid gap-5 lg:grid-cols-2">
                            <ReportBlock
                                id="pack-checks"
                                title="Checks done on time"
                                sub={`Checklists and inspections, by ${filters.unit}`}
                            >
                                <OnTimeTrendChart
                                    rows={rateRows(
                                        trend,
                                        filters.unit,
                                        'checks',
                                    )}
                                    emptyTitle="No checks were due in this period"
                                />
                            </ReportBlock>
                            <ReportBlock
                                id="pack-hazards"
                                title="Hazards raised"
                                sub={`New hazards reported, by ${filters.unit}`}
                                action={{
                                    label: 'Overdue actions',
                                    href: reportHref(
                                        `${REPORTS_BASE}/overdue-actions`,
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
                                        value: bucket.hazards_raised,
                                    }))}
                                    unit="Hazards"
                                    labelHeading="Period"
                                    emptyTitle="No hazards were raised in this period"
                                />
                            </ReportBlock>
                        </div>

                        <div
                            id="pack-sites"
                            tabIndex={-1}
                            className="grid scroll-mt-5 gap-3 outline-none"
                        >
                            <ListCaption
                                title={`Every ${config.one}`}
                                caption={
                                    attention === 'all' && !q
                                        ? `${plural(sites.length, config.one, config.many)} · most to do first`
                                        : `${shown.length} of ${plural(sites.length, config.one, config.many)}${attention !== 'all' ? ` ${ATTENTION_WORDS[attention]}` : ''}`
                                }
                                right={
                                    attention !== 'all' || q ? (
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() => {
                                                setAttention('all');
                                                setQuery('');
                                            }}
                                        >
                                            Show all
                                        </Button>
                                    ) : null
                                }
                            />
                            {shown.length === 0 ? (
                                <Surface>
                                    <EmptyState
                                        variant="compact"
                                        icon={config.icon}
                                        title={
                                            q
                                                ? `No ${config.one} matches “${query.trim()}”`
                                                : `No ${config.many} ${attention !== 'all' ? ATTENTION_WORDS[attention] : ''}`
                                        }
                                        className="border-0"
                                    />
                                </Surface>
                            ) : (
                                <>
                                    <EntityTable
                                        rows={paged.rows}
                                        rowKey={(site) => site.id}
                                        identityLabel={config.crumb}
                                        identity={(site) => ({
                                            icon: config.icon,
                                            name: site.name,
                                            linkLabel: `Open ${site.name} report`,
                                            subline:
                                                site.region ?? 'No region set',
                                        })}
                                        columns={columns}
                                        hrefFor={(site) =>
                                            siteReportHref(site.id, filters)
                                        }
                                        onOpen={(site) =>
                                            router.visit(
                                                siteReportHref(
                                                    site.id,
                                                    filters,
                                                ),
                                            )
                                        }
                                        actionsFor={(site) =>
                                            siteMenu(site, filters)
                                        }
                                        onRowContextMenu={siteContext.open}
                                    />
                                    <Pager {...paged} />
                                </>
                            )}
                        </div>
                    </div>
                )}
                {siteContext.menu}
            </PageLayout>
        </AppLayout>
    );
}

/** The pack's own column: occupancy for houses, zones for facilities, rooms for head office. */
function packColumns(pack: PackKey): EntityTableColumn<PackSite>[] {
    if (pack === 'houses') {
        return [
            {
                key: 'occupancy',
                label: 'Occupancy',
                width: '1.1fr',
                cell: (site) => {
                    const bedrooms = site.extras.bedrooms ?? 0;
                    const clients = site.extras.clients ?? 0;
                    if (bedrooms === 0) {
                        return (
                            <span className="text-muted-foreground">
                                No bedrooms set up
                            </span>
                        );
                    }
                    return (
                        <ProgressValue
                            percent={Math.round((clients / bedrooms) * 100)}
                        >
                            {clients} of {plural(bedrooms, 'bedroom')}
                        </ProgressValue>
                    );
                },
            },
        ];
    }
    if (pack === 'facilities') {
        return [
            {
                key: 'zones',
                label: 'Zones',
                width: '0.7fr',
                align: 'center',
                cell: (site) => <span>{site.extras.zones ?? 0}</span>,
            },
        ];
    }
    return [
        {
            key: 'rooms',
            label: 'Rooms · bookings',
            width: '1fr',
            cell: (site) => (
                <span>
                    {plural(site.extras.rooms ?? 0, 'room')} ·{' '}
                    {plural(site.extras.room_bookings ?? 0, 'booking')}
                </span>
            ),
        },
    ];
}
