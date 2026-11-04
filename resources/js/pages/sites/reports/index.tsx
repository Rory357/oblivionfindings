import {
    PageHeader,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderMeterDonut,
    PageHeaderSearch,
    PageLayout,
} from '@/components/page';
import {
    PlaceCards,
    QuietLine,
    SideColumn,
    Surface,
    type PlaceCardItem,
    type SgTone,
} from '@/components/signature/blocks';
import {
    OnTimeTrendChart,
    PACK_PATH,
    REPORTS_BASE,
    ReportBlock,
    ReportFilterRow,
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
    useSiteContextMenu,
    type PackKey,
    type ReportMetrics,
    type ReportScope,
    type SiteRow,
    type SiteRowMetrics,
    type TrendBucket,
} from '@/components/sites/reports/report-kit';
import { EmptyState } from '@/components/ui/empty-state';
import AppLayout from '@/layouts/app-layout';
import { Head, Link } from '@inertiajs/react';
import {
    AlertTriangle,
    BarChart3,
    Building2,
    ChevronRight,
    ClipboardCheck,
    Download,
    Home,
    MapPinOff,
    Package,
    Warehouse,
    Wrench,
    type LucideIcon,
} from 'lucide-react';
import { useMemo, useState, type ReactNode } from 'react';

type Pack = {
    key: PackKey;
    label: string;
    href: string;
    site_count: number;
    site_count_all: number;
    actions_overdue: number;
    checks_on_time_rate: number | null;
    checks_due: number;
    maintenance_overdue_now: number;
};

type HubSite = SiteRow & { metrics: SiteRowMetrics };

type Props = ReportScope & {
    totals: ReportMetrics;
    trend: TrendBucket[];
    packs: Pack[];
    sites: HubSite[];
};

const PACK_NOUN: Record<PackKey, [string, string]> = {
    houses: ['house', 'houses'],
    facilities: ['facility', 'facilities'],
    'head-office': ['head office', 'head offices'],
};

const PACK_ICON: Record<PackKey, LucideIcon> = {
    houses: Home,
    facilities: Warehouse,
    'head-office': Building2,
};

/** How much a Site needs looking at — worst first in the side column. */
function attentionScore(m: SiteRowMetrics): number {
    return (
        m.actions_overdue * 3 +
        m.hazards_serious * 3 +
        m.checks_missed * 2 +
        m.inspections_overdue_now * 2 +
        m.maintenance_overdue_now
    );
}

/** The facts on a Site card, read by their rings; zeros are left out. */
function siteFacts(m: SiteRowMetrics): { tone: SgTone; text: string }[] {
    const facts: { tone: SgTone; text: string }[] = [];
    if (m.actions_overdue > 0)
        facts.push({
            tone: 'critical',
            text: plural(m.actions_overdue, 'overdue corrective action'),
        });
    if (m.hazards_serious > 0)
        facts.push({
            tone: 'critical',
            text: `${plural(m.hazards_serious, 'high or critical hazard')} open`,
        });
    if (m.checks_missed > 0)
        facts.push({
            tone: 'warning',
            text: `${plural(m.checks_missed, 'check')} missed`,
        });
    if (m.inspections_overdue_now > 0)
        facts.push({
            tone: 'warning',
            text: `${plural(m.inspections_overdue_now, 'inspection')} overdue`,
        });
    if (m.maintenance_overdue_now > 0)
        facts.push({
            tone: 'warning',
            text: `${plural(m.maintenance_overdue_now, 'asset')} past maintenance date`,
        });
    const rate = formatRate(m.checks_on_time_rate);
    facts.push(
        rate
            ? {
                  tone:
                      rateTone(m.checks_on_time_rate) === 'success'
                          ? 'success'
                          : 'warning',
                  text: `${rate} of checks on time`,
              }
            : { tone: 'none', text: 'No checks due in this period' },
    );
    return facts;
}

type ReportLink = {
    title: string;
    text: string;
    href: string;
    icon: LucideIcon;
};

/**
 * Site reports — the hub. Every report answers one of three questions
 * (are our sites safe, are checks done on time, where is maintenance
 * slipping); the packs answer all three for each Site of one type. The
 * period and region chosen here carry into every report it links to.
 */
export default function SiteReportsIndex(props: Props) {
    const { filters, can, totals, trend, packs, sites } = props;
    const scope: ReportScope = props;
    const [query, setQuery] = useState('');
    const siteContext = useSiteContextMenu<HubSite>(filters);

    const q = query.trim().toLowerCase();
    const attention = useMemo(
        () =>
            sites
                .map((site) => ({ site, score: attentionScore(site.metrics) }))
                .filter((row) => row.score > 0)
                .sort((a, b) => b.score - a.score)
                .slice(0, 6)
                .map((row) => row.site),
        [sites],
    );
    const matches = useMemo(
        () =>
            q
                ? sites
                      .filter((site) =>
                          `${site.name} ${site.region ?? ''} ${site.type_label}`
                              .toLowerCase()
                              .includes(q),
                      )
                      .slice(0, 12)
                : [],
        [sites, q],
    );

    const card = (site: HubSite): PlaceCardItem => ({
        id: site.id,
        name: site.name,
        sub: [site.type_label, site.region].filter(Boolean).join(' · '),
        facts: siteFacts(site.metrics),
        href: siteReportHref(site.id, filters),
        menu: siteMenu(site, filters),
        onContextMenu: (event) => siteContext.open(event, site),
    });

    const shownPacks = packs.filter((pack) => pack.site_count_all > 0);
    const emptyPacks = packs.filter((pack) => pack.site_count_all === 0);
    const checksRate = totals.checks_on_time_rate;
    const maintenanceRate = totals.maintenance_on_time_rate;
    const exportHref = `${REPORTS_BASE}/export?${new URLSearchParams(
        scopeParams(filters, { type: 'all', format: 'csv' }),
    ).toString()}`;

    const groups: { question: string; reports: ReportLink[] }[] = [
        {
            question: 'Are our sites safe?',
            reports: [
                {
                    title: 'Overdue corrective actions',
                    text: 'Hazards past their due date, by site, severity and owner',
                    href: reportHref(
                        `${REPORTS_BASE}/overdue-actions`,
                        filters,
                    ),
                    icon: AlertTriangle,
                },
            ],
        },
        {
            question: 'Are checks done on time?',
            reports: [
                {
                    title: 'Checklist trends',
                    text: 'Checklists done on time, the ones that slip and the items that fail most',
                    href: reportHref(
                        `${REPORTS_BASE}/checklist-trends`,
                        filters,
                    ),
                    icon: ClipboardCheck,
                },
            ],
        },
        {
            question: 'Where is maintenance slipping?',
            reports: [
                {
                    title: 'Inspections and maintenance',
                    text: 'Inspections and asset maintenance done late, missed or overdue now',
                    href: reportHref(
                        `${REPORTS_BASE}/inspections-maintenance`,
                        filters,
                    ),
                    icon: Wrench,
                },
                {
                    title: 'Asset condition',
                    text: 'Assets out of service, past their maintenance date or with warranties ending',
                    href: reportHref(
                        `${REPORTS_BASE}/asset-condition`,
                        filters,
                    ),
                    icon: Package,
                },
            ],
        },
    ];

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Sites', href: '/sites' },
                { title: 'Reports', href: reportHref(REPORTS_BASE, filters) },
            ]}
        >
            <Head title="Site reports" />

            <PageLayout
                hero={
                    <PageHeader
                        icon={BarChart3}
                        title="Site reports"
                        subline={`Safety, checks on time and maintenance · ${plural(sites.length, 'site')} · ${scopeWords(scope)}`}
                        actions={
                            <>
                                <PageHeaderSearch
                                    value={query}
                                    onChange={setQuery}
                                    placeholder="Search sites, regions…"
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
                                    ariaLabel="View overdue corrective actions"
                                    href={reportHref(
                                        `${REPORTS_BASE}/overdue-actions`,
                                        filters,
                                    )}
                                >
                                    <PageHeaderMeterBig>
                                        {totals.actions_overdue}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        {totals.hazards_serious > 0
                                            ? `${plural(totals.hazards_serious, 'high or critical hazard')} open`
                                            : `${plural(totals.hazards_open, 'hazard')} open`}
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
                                    ariaLabel="Show checks done on time by month"
                                    onClick={() => showBlock('checks-trend')}
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
                                    ariaLabel="View overdue inspections"
                                    href={reportHref(
                                        `${REPORTS_BASE}/inspections-maintenance`,
                                        filters,
                                    )}
                                >
                                    <PageHeaderMeterBig>
                                        {totals.inspections_overdue_now}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        schedules past their due date
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Maintenance on time"
                                    tone={
                                        totals.maintenance_overdue_now > 0
                                            ? 'warning'
                                            : 'brand'
                                    }
                                    ariaLabel="View maintenance done late or missed"
                                    href={reportHref(
                                        `${REPORTS_BASE}/inspections-maintenance`,
                                        filters,
                                    )}
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
                                <PageHeaderMeterBlock
                                    label="Out of service"
                                    tone={
                                        totals.assets_out_of_service > 0
                                            ? 'warning'
                                            : 'brand'
                                    }
                                    ariaLabel="View asset condition"
                                    href={reportHref(
                                        `${REPORTS_BASE}/asset-condition`,
                                        filters,
                                    )}
                                >
                                    <PageHeaderMeterBig>
                                        {totals.assets_out_of_service}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        {`of ${plural(totals.assets, 'asset')} in use`}
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                            </>
                        }
                        filters={
                            <ReportFilterRow
                                scope={scope}
                                path={REPORTS_BASE}
                            />
                        }
                    />
                }
            >
                <div className="grid items-start gap-5 lg:grid-cols-[minmax(0,1fr)_360px]">
                    <div className="grid min-w-0 gap-5">
                        {sites.length === 0 ? (
                            <Surface>
                                <EmptyState
                                    icon={MapPinOff}
                                    title={
                                        filters.region
                                            ? `No sites in ${filters.region}`
                                            : 'No sites to report on'
                                    }
                                    description={
                                        filters.region
                                            ? 'Choose another region, or all regions, in the header.'
                                            : 'Reports cover the sites you work at. Ask a manager if a site is missing.'
                                    }
                                    className="border-0"
                                />
                            </Surface>
                        ) : (
                            <ReportBlock
                                id="checks-trend"
                                title="Checks done on time"
                                sub={`Checklists and inspections, by ${filters.unit}`}
                                action={{
                                    label: 'Checklist trends',
                                    href: reportHref(
                                        `${REPORTS_BASE}/checklist-trends`,
                                        filters,
                                    ),
                                }}
                            >
                                <OnTimeTrendChart
                                    rows={rateRows(
                                        trend,
                                        filters.unit,
                                        'checks',
                                    )}
                                    emptyTitle="No checks were due in this period"
                                    emptyDescription="Checklists and inspections appear here once they fall due."
                                />
                            </ReportBlock>
                        )}

                        <Surface
                            title="Reports"
                            sub="Each one keeps the period and region chosen above"
                        >
                            <div className="grid gap-1 pt-2 pb-2">
                                <ReportGroup question="Report packs">
                                    {shownPacks.map((pack) => {
                                        const [one, many] = PACK_NOUN[pack.key];
                                        return (
                                            <ReportRow
                                                key={pack.key}
                                                report={{
                                                    title: pack.label,
                                                    text: `Every ${one}: is it safe, are checks on time, is maintenance slipping · ${plural(pack.site_count, one, many)}${filters.region ? ` in ${filters.region}` : ''}`,
                                                    href: reportHref(
                                                        PACK_PATH[pack.key],
                                                        filters,
                                                    ),
                                                    icon: PACK_ICON[pack.key],
                                                }}
                                            />
                                        );
                                    })}
                                    {emptyPacks.length > 0 ? (
                                        <li className="px-[18px] py-2.5">
                                            <span className="text-subtle">
                                                {emptyPackSentence(emptyPacks)}
                                            </span>
                                        </li>
                                    ) : null}
                                </ReportGroup>
                                {groups.map((group) => (
                                    <ReportGroup
                                        key={group.question}
                                        question={group.question}
                                    >
                                        {group.reports.map((report) => (
                                            <ReportRow
                                                key={report.title}
                                                report={report}
                                            />
                                        ))}
                                    </ReportGroup>
                                ))}
                            </div>
                        </Surface>
                    </div>

                    <SideColumn>
                        {q ? (
                            matches.length > 0 ? (
                                <PlaceCards
                                    title="Matching sites"
                                    sub="Open a site's own report"
                                    cols={1}
                                    items={matches.map(card)}
                                />
                            ) : (
                                <QuietLine
                                    title="Matching sites"
                                    text={`No site matches “${query.trim()}”.`}
                                />
                            )
                        ) : attention.length > 0 ? (
                            <PlaceCards
                                title="Sites to look at first"
                                sub="Most overdue and missed work first"
                                cols={1}
                                items={attention.map(card)}
                            />
                        ) : sites.length > 0 ? (
                            <QuietLine
                                title="Sites to look at first"
                                text="Every site is on track for this period."
                            />
                        ) : null}
                    </SideColumn>
                </div>
                {siteContext.menu}
            </PageLayout>
        </AppLayout>
    );
}

function emptyPackSentence(packs: Pack[]): string {
    const names = packs.map((pack) => PACK_NOUN[pack.key][0]);
    const list =
        names.length > 1
            ? `${names.slice(0, -1).join(', ')} or ${names[names.length - 1]}`
            : names[0];
    return `No ${list} sites yet, so ${names.length > 1 ? 'those packs are' : 'that pack is'} hidden. ${names.length > 1 ? 'They appear' : 'It appears'} once a site of that type is added.`;
}

function ReportGroup({
    question,
    children,
}: {
    question: string;
    children: ReactNode;
}) {
    return (
        <section aria-label={question} className="pt-2">
            <h4 className="text-caption px-[18px] pb-1 font-semibold tracking-[0.06em] uppercase">
                {question}
            </h4>
            <ul className="sg-list">{children}</ul>
        </section>
    );
}

function ReportRow({ report }: { report: ReportLink }) {
    const Icon = report.icon;
    return (
        <li>
            <Link href={report.href} className="sg-li">
                <span className="sg-li-lead" aria-hidden="true">
                    <Icon className="size-4" />
                </span>
                <span className="sg-li-t">
                    <b>{report.title}</b>
                    <small>{report.text}</small>
                </span>
                <ChevronRight
                    aria-hidden="true"
                    className="size-4 shrink-0 text-muted-foreground"
                />
            </Link>
        </li>
    );
}
