import { TrendChart } from '@/components/control-room/reports/report-parts';
import {
    CounterPill,
    EntityContextMenu,
    EntityStatusChip,
    EntityTable,
    ListCaption,
    PersonCell,
    useEntityContextMenu,
    type MenuItem,
} from '@/components/lists';
import {
    PageHeader,
    PageHeaderGlassButton,
    PageHeaderMeterBar,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderMeterDonut,
    PageLayout,
} from '@/components/page';
import { QuietLine } from '@/components/signature/blocks';
import {
    DueCell,
    OnTimeTrendChart,
    PACK_PATH,
    REPORTS_BASE,
    ReportBlock,
    ReportFilterRow,
    SEVERITY_LABEL,
    assetStatusLabel,
    bucketLabel,
    formatMoney,
    formatRate,
    periodName,
    plural,
    rateRows,
    rateTone,
    reportHref,
    sentenceLabel,
    severityVariant,
    showBlock,
    type ReportMetrics,
    type ReportScope,
    type SiteRow,
    type TrendBucket,
} from '@/components/sites/reports/report-kit';
import AppLayout from '@/layouts/app-layout';
import { formatDate, formatDateOnly } from '@/lib/datetime';
import { Head, Link, router } from '@inertiajs/react';
import {
    AlertTriangle,
    Building2,
    ClipboardCheck,
    ExternalLink,
    Home,
    Package,
    ShieldCheck,
    Warehouse,
    Wrench,
    type LucideIcon,
} from 'lucide-react';
import type { ReactNode } from 'react';

type HazardRow = {
    id: number;
    reference_number: string | null;
    description: string;
    hazard_type: string | null;
    severity: string | null;
    risk_rating: string | null;
    status: string;
    due_date: string | null;
    days_overdue: number;
    assigned_to: string | null;
};

type CheckIssue = {
    id: number;
    name: string;
    due_date: string | null;
    done_on: string | null;
    outcome: 'on_time' | 'late' | 'missed' | 'pending';
    items_failed: number;
};

type InspectionRow = {
    id: number;
    title: string;
    inspection_type: string | null;
    frequency: string | null;
    next_due_date: string | null;
    days_overdue: number;
    assigned_to: string | null;
    last_done_at: string | null;
    last_result: string | null;
};

type AssetRow = {
    id: number;
    name: string;
    asset_tag: string | null;
    category: string | null;
    status: string | null;
    maintenance_due_at: string | null;
    days_overdue: number;
};

type MaintenanceDone = {
    id: number;
    asset_id: number;
    asset_name: string | null;
    type: string | null;
    vendor: string | null;
    performed_at: string | null;
    cost: number | null;
};

type Props = ReportScope & {
    site: SiteRow & { address: string | null };
    metrics: ReportMetrics;
    extras: Record<string, number>;
    trend: TrendBucket[];
    openHazards: HazardRow[];
    checkIssues: CheckIssue[];
    inspections: InspectionRow[];
    assets: AssetRow[];
    maintenanceDone: MaintenanceDone[];
};

const TYPE_ICON: Record<string, LucideIcon> = {
    house: Home,
    residential: Home,
    facility: Warehouse,
    head_office: Building2,
};

const RESULT_LABEL: Record<string, string> = {
    pass: 'Passed',
    fail: 'Failed',
    partial: 'Partly passed',
    na: 'Not applicable',
};

/**
 * One Site's report: is it safe, are its checks done on time, is its
 * maintenance slipping — over the chosen period, with the work behind each
 * number. Opened from every Site row in the reports; the header's glass
 * button opens the Site's profile.
 */
export default function SiteDetailReport(props: Props) {
    const {
        site,
        filters,
        metrics: m,
        extras,
        trend,
        openHazards,
        checkIssues,
        inspections,
        assets,
        maintenanceDone,
    } = props;
    const scope: ReportScope = props;
    const path = `${REPORTS_BASE}/site/${site.id}`;
    const packHref = reportHref(PACK_PATH[site.pack], filters);
    const hazardMenu = useEntityContextMenu<HazardRow>();
    const assetMenu = useEntityContextMenu<AssetRow>();
    const inspectionMenu = useEntityContextMenu<InspectionRow>();

    const hazardActions = (hazard: HazardRow): MenuItem[] => [
        {
            label: 'Open hazard',
            icon: AlertTriangle,
            onClick: () => router.visit(`/hazards/${hazard.id}`),
        },
    ];
    const assetActions = (asset: AssetRow): MenuItem[] => [
        {
            label: 'Open asset',
            icon: Package,
            onClick: () => router.visit(`/fleet-assets/assets/${asset.id}`),
        },
    ];
    const inspectionActions = (): MenuItem[] => [
        {
            label: 'Open inspections',
            icon: ShieldCheck,
            onClick: () => router.visit(`/sites/${site.id}/inspections`),
        },
    ];

    const checksRate = m.checks_on_time_rate;
    const maintenanceRate = m.maintenance_on_time_rate;
    const bedrooms = extras.bedrooms ?? 0;
    const clients = extras.clients ?? 0;
    const Icon = TYPE_ICON[site.type] ?? Building2;
    const facts = [site.type_label, site.region, periodName(scope)]
        .filter(Boolean)
        .join(' · ');

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Sites', href: '/sites' },
                { title: 'Reports', href: reportHref(REPORTS_BASE, filters) },
                {
                    title:
                        site.pack === 'houses'
                            ? 'Houses'
                            : site.pack === 'facilities'
                              ? 'Facilities'
                              : 'Head office',
                    href: packHref,
                },
                { title: site.name, href: reportHref(path, filters) },
            ]}
        >
            <Head title={`${site.name} report`} />

            <PageLayout
                hero={
                    <PageHeader
                        variant="profile"
                        backHref={packHref}
                        icon={Icon}
                        title={site.name}
                        subline={
                            <>
                                {site.address ?? 'No address recorded'}
                                <br />
                                Site report · {facts}
                            </>
                        }
                        actions={
                            <PageHeaderGlassButton icon={ExternalLink} asChild>
                                <Link href={`/sites/${site.id}`}>
                                    Open site profile
                                </Link>
                            </PageHeaderGlassButton>
                        }
                        meters={
                            <>
                                <PageHeaderMeterBlock
                                    label="Open hazards"
                                    tone={
                                        m.hazards_serious > 0 ||
                                        m.actions_overdue > 0
                                            ? 'critical'
                                            : m.hazards_open > 0
                                              ? 'warning'
                                              : 'success'
                                    }
                                    ariaLabel="Show open hazards"
                                    onClick={() => showBlock('site-safety')}
                                >
                                    <PageHeaderMeterBig>
                                        {m.hazards_open}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        {`${m.hazards_serious} high or critical · ${m.actions_overdue} overdue`}
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
                                    ariaLabel="Show checks done late or missed"
                                    onClick={() => showBlock('site-checks')}
                                >
                                    {checksRate !== null ? (
                                        <PageHeaderMeterDonut
                                            percent={checksRate}
                                            caption={
                                                <>
                                                    {m.checks_on_time} of{' '}
                                                    {m.checks_due} due
                                                    <br />
                                                    {m.checks_missed} missed
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
                                        m.inspections_overdue_now > 0
                                            ? 'warning'
                                            : 'success'
                                    }
                                    ariaLabel="Show inspection schedules"
                                    onClick={() =>
                                        showBlock('site-inspections')
                                    }
                                >
                                    <PageHeaderMeterBig>
                                        {m.inspections_overdue_now}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        {`${m.inspections_failed} failed in this period`}
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Maintenance on time"
                                    tone={
                                        m.maintenance_overdue_now > 0
                                            ? 'warning'
                                            : 'brand'
                                    }
                                    ariaLabel="Show maintenance past due"
                                    onClick={() =>
                                        showBlock('site-maintenance')
                                    }
                                >
                                    {maintenanceRate !== null ? (
                                        <PageHeaderMeterDonut
                                            percent={maintenanceRate}
                                            caption={
                                                <>
                                                    {plural(
                                                        m.maintenance_overdue_now,
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
                                                {`${plural(m.maintenance_overdue_now, 'asset')} past due now`}
                                            </PageHeaderMeterCaption>
                                        </>
                                    )}
                                </PageHeaderMeterBlock>
                                {site.pack === 'houses' && bedrooms > 0 ? (
                                    <PageHeaderMeterBlock
                                        label="Occupancy"
                                        value={`${clients}/${bedrooms}`}
                                        ariaLabel={`Open ${site.name} profile`}
                                        href={`/sites/${site.id}`}
                                    >
                                        <PageHeaderMeterBar
                                            percent={Math.round(
                                                (clients / bedrooms) * 100,
                                            )}
                                        />
                                        <PageHeaderMeterCaption>
                                            {`${Math.round((clients / bedrooms) * 100)}% of bedrooms filled`}
                                        </PageHeaderMeterCaption>
                                    </PageHeaderMeterBlock>
                                ) : null}
                            </>
                        }
                        filters={
                            <ReportFilterRow
                                scope={scope}
                                path={path}
                                showRegion={false}
                            />
                        }
                    />
                }
            >
                <div className="grid gap-5">
                    <div className="grid gap-5 lg:grid-cols-2">
                        <ReportBlock
                            title="Checks done on time"
                            sub={`Checklists and inspections, by ${filters.unit}`}
                        >
                            <OnTimeTrendChart
                                rows={rateRows(trend, filters.unit, 'checks')}
                                emptyTitle="No checks were due in this period"
                            />
                        </ReportBlock>
                        <ReportBlock
                            title="Hazards raised"
                            sub={`New hazards reported, by ${filters.unit}`}
                        >
                            <TrendChart
                                rows={trend.map((bucket) => ({
                                    key: bucket.key,
                                    label: bucketLabel(bucket, filters.unit),
                                    value: bucket.hazards_raised,
                                }))}
                                unit="Hazards"
                                labelHeading="Period"
                                emptyTitle="No hazards were raised in this period"
                            />
                        </ReportBlock>
                    </div>

                    {/* Is it safe? */}
                    <Section
                        id="site-safety"
                        title="Open hazards"
                        caption={safetySentence(m)}
                    >
                        {openHazards.length === 0 ? (
                            <QuietLine text="No hazards are open at this site." />
                        ) : (
                            <EntityTable
                                rows={openHazards}
                                rowKey={(hazard) => hazard.id}
                                identityLabel="Hazard"
                                identity={(hazard) => ({
                                    icon: AlertTriangle,
                                    name: hazard.description,
                                    subline: [
                                        sentenceLabel(hazard.hazard_type),
                                        hazard.reference_number,
                                    ]
                                        .filter(Boolean)
                                        .join(' · '),
                                })}
                                identityWidth="2.4fr"
                                columns={[
                                    {
                                        key: 'severity',
                                        label: 'Severity',
                                        width: '0.9fr',
                                        cell: (hazard) => (
                                            <EntityStatusChip
                                                variant={severityVariant(
                                                    hazard.severity,
                                                )}
                                            >
                                                {SEVERITY_LABEL[
                                                    hazard.severity ?? ''
                                                ] ?? 'Not rated'}
                                            </EntityStatusChip>
                                        ),
                                    },
                                    {
                                        key: 'owner',
                                        label: 'Owner',
                                        width: '1.2fr',
                                        cell: (hazard) => (
                                            <PersonCell
                                                name={hazard.assigned_to}
                                            />
                                        ),
                                    },
                                    {
                                        key: 'due',
                                        label: 'Due',
                                        width: '1.3fr',
                                        cell: (hazard) => (
                                            <DueCell
                                                date={hazard.due_date}
                                                daysOverdue={
                                                    hazard.days_overdue
                                                }
                                                none="No due date"
                                            />
                                        ),
                                    },
                                ]}
                                onOpen={(hazard) =>
                                    router.visit(`/hazards/${hazard.id}`)
                                }
                                hrefFor={(hazard) => `/hazards/${hazard.id}`}
                                actionsFor={hazardActions}
                                onRowContextMenu={hazardMenu.open}
                                minWidth={760}
                            />
                        )}
                    </Section>

                    {/* Are checks done on time? */}
                    <Section
                        id="site-checks"
                        title="Checks done late, missed or with failed items"
                        caption={checksSentence(m)}
                    >
                        {checkIssues.length === 0 ? (
                            <QuietLine
                                text={
                                    m.checklists_due > 0
                                        ? 'Every checklist due in this period was done on time with nothing failed.'
                                        : 'No checklists were due in this period.'
                                }
                            />
                        ) : (
                            <EntityTable
                                rows={checkIssues}
                                rowKey={(check) => check.id}
                                identityLabel="Checklist"
                                identity={(check) => ({
                                    icon: ClipboardCheck,
                                    name: check.name,
                                    subline: `Due ${formatDateOnly(check.due_date)}`,
                                })}
                                columns={[
                                    {
                                        key: 'outcome',
                                        label: 'Outcome',
                                        width: '1fr',
                                        cell: (check) => (
                                            <CheckOutcome check={check} />
                                        ),
                                    },
                                    {
                                        key: 'done',
                                        label: 'Done',
                                        width: '1fr',
                                        cell: (check) =>
                                            check.done_on ? (
                                                formatDateOnly(check.done_on)
                                            ) : (
                                                <span className="text-muted-foreground">
                                                    Not done
                                                </span>
                                            ),
                                    },
                                    {
                                        key: 'failed',
                                        label: 'Failed items',
                                        width: '0.8fr',
                                        align: 'center',
                                        cell: (check) => (
                                            <CounterPill
                                                tone={
                                                    check.items_failed > 0
                                                        ? 'warning'
                                                        : 'neutral'
                                                }
                                            >
                                                {check.items_failed}
                                            </CounterPill>
                                        ),
                                    },
                                ]}
                                onOpen={(check) =>
                                    router.visit(`/checklists/runs/${check.id}`)
                                }
                                actionsFor={(check) => [
                                    {
                                        label: 'Open checklist',
                                        icon: ClipboardCheck,
                                        onClick: () =>
                                            router.visit(
                                                `/checklists/runs/${check.id}`,
                                            ),
                                    },
                                ]}
                                minWidth={700}
                            />
                        )}
                    </Section>

                    <Section
                        id="site-inspections"
                        title="Inspection schedules"
                        caption={inspectionsSentence(m)}
                    >
                        {inspections.length === 0 ? (
                            <QuietLine text="No inspections are scheduled at this site." />
                        ) : (
                            <EntityTable
                                rows={inspections}
                                rowKey={(row) => row.id}
                                identityLabel="Inspection"
                                identity={(row) => ({
                                    icon: ShieldCheck,
                                    name: row.title,
                                    subline: [
                                        sentenceLabel(row.inspection_type),
                                        row.frequency
                                            ? sentenceLabel(row.frequency)
                                            : null,
                                    ]
                                        .filter(Boolean)
                                        .join(' · '),
                                })}
                                columns={[
                                    {
                                        key: 'due',
                                        label: 'Next due',
                                        width: '1.3fr',
                                        cell: (row) => (
                                            <DueCell
                                                date={row.next_due_date}
                                                daysOverdue={row.days_overdue}
                                                none="Not scheduled"
                                            />
                                        ),
                                    },
                                    {
                                        key: 'last',
                                        label: 'Last done',
                                        width: '1.3fr',
                                        cell: (row) =>
                                            row.last_done_at ? (
                                                <span className="flex min-w-0 items-center gap-2">
                                                    <span className="truncate">
                                                        {formatDate(
                                                            row.last_done_at,
                                                        )}
                                                    </span>
                                                    {row.last_result ? (
                                                        <EntityStatusChip
                                                            variant={
                                                                row.last_result ===
                                                                'fail'
                                                                    ? 'critical'
                                                                    : row.last_result ===
                                                                        'partial'
                                                                      ? 'warning'
                                                                      : 'success'
                                                            }
                                                        >
                                                            {RESULT_LABEL[
                                                                row.last_result
                                                            ] ??
                                                                sentenceLabel(
                                                                    row.last_result,
                                                                )}
                                                        </EntityStatusChip>
                                                    ) : null}
                                                </span>
                                            ) : (
                                                <span className="text-muted-foreground">
                                                    Not done yet
                                                </span>
                                            ),
                                    },
                                    {
                                        key: 'assigned',
                                        label: 'Assigned to',
                                        width: '1.1fr',
                                        cell: (row) => (
                                            <PersonCell
                                                name={row.assigned_to}
                                            />
                                        ),
                                    },
                                ]}
                                onOpen={() =>
                                    router.visit(
                                        `/sites/${site.id}/inspections`,
                                    )
                                }
                                actionsFor={inspectionActions}
                                onRowContextMenu={inspectionMenu.open}
                                minWidth={760}
                            />
                        )}
                    </Section>

                    {/* Is maintenance slipping? */}
                    <Section
                        id="site-maintenance"
                        title="Assets past their maintenance date or out of service"
                        caption={maintenanceSentence(m)}
                    >
                        {assets.length === 0 ? (
                            <QuietLine
                                text={
                                    m.assets > 0
                                        ? 'Every asset here is in service and within its maintenance date.'
                                        : 'No assets are recorded at this site.'
                                }
                            />
                        ) : (
                            <EntityTable
                                rows={assets}
                                rowKey={(asset) => asset.id}
                                identityLabel="Asset"
                                identity={(asset) => ({
                                    icon: Wrench,
                                    name: asset.name,
                                    subline: [asset.category, asset.asset_tag]
                                        .filter(Boolean)
                                        .join(' · '),
                                })}
                                columns={[
                                    {
                                        key: 'status',
                                        label: 'Status',
                                        width: '1fr',
                                        cell: (asset) => (
                                            <EntityStatusChip
                                                variant={
                                                    asset.status === 'active'
                                                        ? 'success'
                                                        : 'warning'
                                                }
                                            >
                                                {assetStatusLabel(asset.status)}
                                            </EntityStatusChip>
                                        ),
                                    },
                                    {
                                        key: 'due',
                                        label: 'Maintenance due',
                                        width: '1.3fr',
                                        cell: (asset) => (
                                            <DueCell
                                                date={asset.maintenance_due_at}
                                                daysOverdue={asset.days_overdue}
                                                none="No date set"
                                            />
                                        ),
                                    },
                                ]}
                                onOpen={(asset) =>
                                    router.visit(
                                        `/fleet-assets/assets/${asset.id}`,
                                    )
                                }
                                hrefFor={(asset) =>
                                    `/fleet-assets/assets/${asset.id}`
                                }
                                actionsFor={assetActions}
                                onRowContextMenu={assetMenu.open}
                                minWidth={640}
                            />
                        )}
                    </Section>

                    <Section
                        title="Maintenance done"
                        caption={`${plural(m.maintenance_done, 'job')} logged in this period${m.maintenance_done > 0 ? ` · ${formatMoney(m.maintenance_spend)} spent` : ''}`}
                    >
                        {maintenanceDone.length === 0 ? (
                            <QuietLine text="No maintenance was logged for this site's assets in this period." />
                        ) : (
                            <EntityTable
                                rows={maintenanceDone}
                                rowKey={(log) => log.id}
                                identityLabel="Asset"
                                identity={(log) => ({
                                    icon: Wrench,
                                    name: log.asset_name ?? 'Asset',
                                    subline: sentenceLabel(log.type),
                                })}
                                columns={[
                                    {
                                        key: 'done',
                                        label: 'Done',
                                        width: '1fr',
                                        cell: (log) =>
                                            formatDate(log.performed_at),
                                    },
                                    {
                                        key: 'vendor',
                                        label: 'Done by',
                                        width: '1.2fr',
                                        cell: (log) =>
                                            log.vendor ?? (
                                                <span className="text-muted-foreground">
                                                    —
                                                </span>
                                            ),
                                    },
                                    {
                                        key: 'cost',
                                        label: 'Cost',
                                        width: '0.8fr',
                                        align: 'right',
                                        cell: (log) =>
                                            log.cost !== null ? (
                                                <span className="tabular-nums">
                                                    {formatMoney(log.cost)}
                                                </span>
                                            ) : (
                                                <span className="text-muted-foreground">
                                                    —
                                                </span>
                                            ),
                                    },
                                ]}
                                onOpen={(log) =>
                                    router.visit(
                                        `/fleet-assets/assets/${log.asset_id}`,
                                    )
                                }
                                actionsFor={(log) => [
                                    {
                                        label: 'Open asset',
                                        icon: Package,
                                        onClick: () =>
                                            router.visit(
                                                `/fleet-assets/assets/${log.asset_id}`,
                                            ),
                                    },
                                ]}
                                minWidth={640}
                            />
                        )}
                    </Section>
                </div>

                {hazardMenu.ctx ? (
                    <EntityContextMenu
                        {...hazardMenu.ctx}
                        onClose={hazardMenu.close}
                        title={hazardMenu.ctx.record.description}
                        items={hazardActions(hazardMenu.ctx.record)}
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
                {inspectionMenu.ctx ? (
                    <EntityContextMenu
                        {...inspectionMenu.ctx}
                        onClose={inspectionMenu.close}
                        title={inspectionMenu.ctx.record.title}
                        items={inspectionActions()}
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
    id?: string;
    title: string;
    caption?: ReactNode;
    children: ReactNode;
}) {
    return (
        <section
            id={id}
            tabIndex={id ? -1 : undefined}
            aria-label={title}
            className="grid min-w-0 scroll-mt-5 gap-3 outline-none focus-visible:ring-2 focus-visible:ring-ring"
        >
            <ListCaption title={title} caption={caption} />
            {children}
        </section>
    );
}

function CheckOutcome({ check }: { check: CheckIssue }) {
    if (check.outcome === 'missed')
        return <EntityStatusChip variant="critical">Missed</EntityStatusChip>;
    if (check.outcome === 'late')
        return <EntityStatusChip variant="warning">Done late</EntityStatusChip>;
    return <EntityStatusChip variant="success">Done on time</EntityStatusChip>;
}

function safetySentence(m: ReportMetrics): string {
    const parts = [
        `${plural(m.hazards_raised, 'hazard')} raised and ${m.hazards_closed} closed in this period`,
    ];
    if (m.avg_days_to_close !== null) {
        parts.push(`closed in ${m.avg_days_to_close} days on average`);
    }
    return parts.join(' · ');
}

function checksSentence(m: ReportMetrics): string {
    if (m.checklists_due === 0) return 'No checklists were due in this period';
    return `${plural(m.checklists_due, 'checklist')} due: ${m.checklists_on_time} on time, ${m.checklists_late} late, ${m.checklists_missed} missed${
        m.checklists_on_time_rate !== null
            ? ` (${formatRate(m.checklists_on_time_rate)} on time)`
            : ''
    }`;
}

function inspectionsSentence(m: ReportMetrics): string {
    if (m.inspections_due === 0)
        return 'No inspections were due in this period';
    return `${plural(m.inspections_due, 'inspection')} due: ${m.inspections_on_time} on time, ${m.inspections_late} late, ${m.inspections_missed} missed`;
}

function maintenanceSentence(m: ReportMetrics): string {
    if (m.maintenance_due === 0)
        return `No maintenance fell due in this period · ${plural(m.assets_out_of_service, 'asset')} out of service`;
    return `${plural(m.maintenance_due, 'maintenance job')} due: ${m.maintenance_on_time} on time, ${m.maintenance_late} late, ${m.maintenance_missed} missed · ${plural(m.assets_out_of_service, 'asset')} out of service`;
}
