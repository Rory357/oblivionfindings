import { EntityTable } from '@/components/lists';
import {
    PageHeader,
    PageHeaderFilterButton,
    PageHeaderFilterSelect,
    PageHeaderMeterBar,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderMeterDonut,
    PageHeaderPrimaryButton,
    PageHeaderRail,
    PageHeaderStatusChip,
    PageLayout,
} from '@/components/page';
import { ReferralReportAges } from '@/components/referrals/report-ages';
import {
    metricPercent,
    metricText,
    ReferralDistribution,
    ReferralHandoverGauge,
    ReferralSourceChart,
    ReferralTrend,
    ReportMeasureLinks,
    ReportMeasuresTable,
    reportMetric,
    ReportPanel,
    ReportPrivacyContext,
} from '@/components/referrals/report-charts';
import { ReferralHistoryReport } from '@/components/referrals/report-history';
import {
    shownCount,
    suppressionCaption,
    type ReferralReportProps,
    type ReportFilters,
} from '@/components/referrals/report-types';
import { ReferralWorkingTimeReport } from '@/components/referrals/report-working-time';
import { ReferralStageBadge } from '@/components/referrals/stage-badge';
import { label, referralHref } from '@/components/referrals/types';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import AppLayout from '@/layouts/app-layout';
import {
    formatDateOnly,
    formatDateTimeLong,
    toDateInput,
} from '@/lib/datetime';
import { Head, router } from '@inertiajs/react';
import {
    BarChart3,
    CalendarDays,
    Download,
    Eye,
    Inbox,
    LayoutDashboard,
    ListChecks,
    RotateCcw,
    SlidersHorizontal,
    Users,
} from 'lucide-react';
import { useRef, useState, type ReactNode } from 'react';
import {
    ReportDates,
    ReportExport,
    ReportMoreFilters,
} from './_report-dialogs';

type RailView = 'overview' | 'details' | 'workload';

const STAGES = [
    'new',
    'triage',
    'assessment',
    'funding_assistance',
    'ready_for_handover',
    'on_hold',
    'converted',
    'closed',
];

/** Received-date presets on the NZ calendar; the server re-validates the range. */
function periodPresets(): {
    value: string;
    label: string;
    from: string;
    to: string;
}[] {
    const today = toDateInput(new Date());
    const [year, month] = today.split('-');
    const shift = (days: number) => {
        const date = new Date(`${today}T12:00:00Z`);
        date.setUTCDate(date.getUTCDate() - days);
        return date.toISOString().slice(0, 10);
    };
    return [
        {
            value: 'month',
            label: 'This month',
            from: `${year}-${month}-01`,
            to: today,
        },
        { value: '30', label: 'Last 30 days', from: shift(29), to: today },
        { value: '90', label: 'Last 90 days', from: shift(89), to: today },
        {
            value: 'year',
            label: 'Year to date',
            from: `${year}-01-01`,
            to: today,
        },
    ];
}

const MORE_FILTER_KEYS = [
    'classification',
    'stage_age',
    'funding_age',
    'funding_kind',
] as const;

export default function ReferralReportsPage({
    report,
    records,
    sites,
    permissions,
}: ReferralReportProps) {
    const [dates, setDates] = useState(false);
    const [more, setMore] = useState(false);
    const [exporting, setExporting] = useState(false);
    const [denied, setDenied] = useState(false);
    const [error, setError] = useState('');
    const matchingHeading = useRef<HTMLHeadingElement>(null);
    const filters = report.filters;
    const rail: RailView =
        filters.scope === 'current' ? 'workload' : filters.view;
    const visit = (patch: Partial<ReportFilters>) => {
        setError('');
        router.get(
            '/operations/referrals/reports',
            { ...filters, page: 1, ...patch },
            {
                preserveScroll: true,
                preserveState: true,
                replace: true,
                onError: (errors) => setError(Object.values(errors).join(' ')),
                onSuccess: () => {
                    if (patch.view === 'details') {
                        requestAnimationFrame(() => {
                            matchingHeading.current?.scrollIntoView({
                                block: 'start',
                            });
                            matchingHeading.current?.focus({
                                preventScroll: true,
                            });
                        });
                    }
                },
            },
        );
    };
    const drill = (metric: string) => visit({ metric, view: 'details' });
    const drillBy = (patch: Partial<ReportFilters>) =>
        visit({ ...patch, metric: 'received', view: 'details' });
    const selected =
        report.metrics.find((metric) => metric.key === filters.metric) ??
        report.metrics[0];
    // Small groups are hidden ("Fewer than 5"), never shown as 0 (config/referral-reports.php).
    const received = metricText(report, 'received');
    const positive = (key: string) => {
        const metric = reportMetric(report, key);
        return !!metric && (!!metric.suppressed || (metric.value ?? 0) > 0);
    };
    const presets = periodPresets();
    const preset =
        presets.find(
            (option) =>
                option.from === filters.from && option.to === filters.to,
        )?.value ?? 'custom';
    const moreActive = MORE_FILTER_KEYS.filter((key) => !!filters[key]).length;
    const narrowed =
        filters.site !== 'all' ||
        filters.owner !== 'all' ||
        !!filters.source ||
        !!filters.stage ||
        !!filters.region ||
        moreActive > 0;
    const meter = (
        key: string,
        tone: 'brand' | 'warning' | 'critical' | 'success',
        body: ReactNode,
    ) => {
        const metric = report.metrics.find((item) => item.key === key);
        if (!metric) return null;
        return (
            <PageHeaderMeterBlock
                key={key}
                label={metric.label}
                tone={tone}
                onClick={() => drill(key)}
                pressed={rail === 'details' && filters.metric === key}
                ariaLabel={`${metric.label}: ${shownCount(report, metric.value, metric.suppressed)}. View matching referrals`}
                className="min-w-0"
            >
                {body}
            </PageHeaderMeterBlock>
        );
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Referrals', href: '/operations/referrals' },
                { title: 'Reports', href: '/operations/referrals/reports' },
            ]}
        >
            <Head title="Referral reports" />
            <PageLayout
                hero={
                    <PageHeader
                        icon={BarChart3}
                        title="Referral reports"
                        titleChip={
                            denied ? undefined : (
                                <PageHeaderStatusChip variant="info">
                                    {filters.scope === 'current'
                                        ? 'All current work'
                                        : 'Received cohort'}
                                </PageHeaderStatusChip>
                            )
                        }
                        subline={
                            denied
                                ? 'Report content is hidden until your access is checked'
                                : filters.scope === 'received'
                                  ? `Received ${formatDateOnly(filters.from)} – ${formatDateOnly(filters.to)} · ${received} referrals · NZ dates`
                                  : `${received} open or unresolved referrals, including older ones`
                        }
                        mobileSummary={`${received} referrals · ${filters.scope === 'current' ? 'Current work' : 'Received cohort'}`}
                        actions={
                            permissions['reports.export'] && !denied ? (
                                <PageHeaderPrimaryButton
                                    icon={Download}
                                    onClick={() => setExporting(true)}
                                >
                                    Preview export
                                </PageHeaderPrimaryButton>
                            ) : undefined
                        }
                        meters={
                            denied ? undefined : (
                                <div className="grid w-full grid-cols-2 gap-2 xl:grid-cols-4">
                                    {meter(
                                        'received',
                                        'brand',
                                        <>
                                            <PageHeaderMeterBig>
                                                {received}
                                            </PageHeaderMeterBig>
                                            <PageHeaderMeterCaption>
                                                {filters.scope === 'current'
                                                    ? 'Open or unresolved now'
                                                    : 'Received in this period'}
                                            </PageHeaderMeterCaption>
                                        </>,
                                    )}
                                    {meter(
                                        'contacted',
                                        'brand',
                                        metricPercent(report, 'contacted') ===
                                            null ? (
                                            // A hidden small group has no honest share to draw.
                                            <>
                                                <PageHeaderMeterBig>
                                                    {metricText(
                                                        report,
                                                        'contacted',
                                                    )}
                                                </PageHeaderMeterBig>
                                                <PageHeaderMeterCaption>
                                                    Spoken with · small numbers
                                                    hidden
                                                </PageHeaderMeterCaption>
                                            </>
                                        ) : (
                                            <PageHeaderMeterDonut
                                                percent={
                                                    metricPercent(
                                                        report,
                                                        'contacted',
                                                    ) ?? 0
                                                }
                                                caption={`${metricText(report, 'contacted')} of ${received} spoken with`}
                                            />
                                        ),
                                    )}
                                    {meter(
                                        'unassigned',
                                        positive('unassigned')
                                            ? 'warning'
                                            : 'brand',
                                        <>
                                            <PageHeaderMeterBig>
                                                {metricText(
                                                    report,
                                                    'unassigned',
                                                )}
                                            </PageHeaderMeterBig>
                                            <PageHeaderMeterCaption>
                                                No named owner
                                            </PageHeaderMeterCaption>
                                        </>,
                                    )}
                                    {meter(
                                        'overdue',
                                        positive('overdue')
                                            ? 'critical'
                                            : 'brand',
                                        <>
                                            <PageHeaderMeterBig>
                                                {metricText(report, 'overdue')}
                                            </PageHeaderMeterBig>
                                            <PageHeaderMeterCaption>
                                                Next step past due
                                            </PageHeaderMeterCaption>
                                        </>,
                                    )}
                                    {meter(
                                        'capacity_waitlist',
                                        'brand',
                                        <>
                                            <PageHeaderMeterBig>
                                                {metricText(
                                                    report,
                                                    'capacity_waitlist',
                                                )}
                                            </PageHeaderMeterBig>
                                            <PageHeaderMeterCaption>
                                                Waiting for a place
                                            </PageHeaderMeterCaption>
                                        </>,
                                    )}
                                    {meter(
                                        'converted',
                                        'success',
                                        <>
                                            <PageHeaderMeterBig>
                                                {metricText(
                                                    report,
                                                    'converted',
                                                )}
                                            </PageHeaderMeterBig>
                                            {metricPercent(
                                                report,
                                                'converted',
                                            ) === null ? (
                                                <PageHeaderMeterCaption>
                                                    Handed over · small numbers
                                                    hidden
                                                </PageHeaderMeterCaption>
                                            ) : (
                                                <PageHeaderMeterBar
                                                    percent={
                                                        metricPercent(
                                                            report,
                                                            'converted',
                                                        ) ?? 0
                                                    }
                                                />
                                            )}
                                        </>,
                                    )}
                                    {meter(
                                        'commenced',
                                        'success',
                                        <>
                                            <PageHeaderMeterBig>
                                                {metricText(
                                                    report,
                                                    'commenced',
                                                )}
                                            </PageHeaderMeterBig>
                                            <PageHeaderMeterCaption>
                                                Actual starts recorded
                                            </PageHeaderMeterCaption>
                                        </>,
                                    )}
                                    {meter(
                                        'reopened',
                                        'brand',
                                        <>
                                            <PageHeaderMeterBig>
                                                {metricText(report, 'reopened')}
                                            </PageHeaderMeterBig>
                                            <PageHeaderMeterCaption>
                                                Came back after closing
                                            </PageHeaderMeterCaption>
                                        </>,
                                    )}
                                </div>
                            )
                        }
                        filters={
                            denied ? undefined : (
                                <>
                                    {filters.scope === 'received' && (
                                        <PageHeaderFilterSelect
                                            label="Period"
                                            value={preset}
                                            options={[
                                                ...presets.map(
                                                    ({ value, label }) => ({
                                                        value,
                                                        label,
                                                    }),
                                                ),
                                                {
                                                    value: 'custom',
                                                    label:
                                                        preset === 'custom'
                                                            ? `${formatDateOnly(filters.from)} – ${formatDateOnly(filters.to)}`
                                                            : 'Custom dates…',
                                                },
                                            ]}
                                            onChange={(value) => {
                                                const chosen = presets.find(
                                                    (option) =>
                                                        option.value === value,
                                                );
                                                if (chosen)
                                                    visit({
                                                        from: chosen.from,
                                                        to: chosen.to,
                                                    });
                                                else setDates(true);
                                            }}
                                        />
                                    )}
                                    {filters.scope === 'received' && (
                                        <PageHeaderFilterButton
                                            icon={CalendarDays}
                                            onClick={() => setDates(true)}
                                            aria-label="Choose custom received dates"
                                        />
                                    )}
                                    <PageHeaderFilterSelect
                                        label="Site"
                                        value={filters.site}
                                        options={[
                                            {
                                                value: 'all',
                                                label: 'All accessible sites',
                                            },
                                            ...(permissions.centralIntake
                                                ? [
                                                      {
                                                          value: 'central',
                                                          label: 'Central intake',
                                                      },
                                                  ]
                                                : []),
                                            ...sites.map((site) => ({
                                                value: String(site.id),
                                                label: site.name,
                                            })),
                                        ]}
                                        onChange={(site) => visit({ site })}
                                    />
                                    <PageHeaderFilterSelect
                                        label="Stage"
                                        value={filters.stage || 'all'}
                                        options={[
                                            {
                                                value: 'all',
                                                label: 'All stages',
                                            },
                                            ...STAGES.map((stage) => ({
                                                value: stage,
                                                label: label(stage),
                                            })),
                                        ]}
                                        onChange={(stage) =>
                                            visit({
                                                stage:
                                                    stage === 'all'
                                                        ? ''
                                                        : stage,
                                            })
                                        }
                                    />
                                    <PageHeaderFilterButton
                                        icon={SlidersHorizontal}
                                        active={moreActive > 0}
                                        onClick={() => setMore(true)}
                                    >
                                        More filters
                                        {moreActive ? ` · ${moreActive}` : ''}
                                    </PageHeaderFilterButton>
                                    {filters.source && (
                                        <PageHeaderFilterButton
                                            active
                                            onClick={() =>
                                                visit({ source: '' })
                                            }
                                        >
                                            Channel: {label(filters.source)} ·
                                            Clear
                                        </PageHeaderFilterButton>
                                    )}
                                    {filters.region && (
                                        <PageHeaderFilterButton
                                            active
                                            onClick={() =>
                                                visit({ region: '' })
                                            }
                                        >
                                            Region:{' '}
                                            {report.groups.region?.find(
                                                (row) =>
                                                    row.key === filters.region,
                                            )?.label ?? 'Selected region'}{' '}
                                            · Clear
                                        </PageHeaderFilterButton>
                                    )}
                                    {filters.owner !== 'all' && (
                                        <PageHeaderFilterButton
                                            active
                                            onClick={() =>
                                                visit({ owner: 'all' })
                                            }
                                        >
                                            Owner:{' '}
                                            {report.groups.owner?.find(
                                                (row) =>
                                                    row.key === filters.owner,
                                            )?.label ?? 'Selected'}{' '}
                                            · Clear
                                        </PageHeaderFilterButton>
                                    )}
                                    {narrowed && (
                                        <PageHeaderFilterButton
                                            icon={RotateCcw}
                                            onClick={() =>
                                                visit({
                                                    site: 'all',
                                                    owner: 'all',
                                                    source: '',
                                                    stage: '',
                                                    metric: 'received',
                                                    stage_age: '',
                                                    funding_age: '',
                                                    funding_kind: '',
                                                    region: '',
                                                    classification: '',
                                                })
                                            }
                                        >
                                            Reset
                                        </PageHeaderFilterButton>
                                    )}
                                </>
                            )
                        }
                        rail={
                            denied ? undefined : (
                                <PageHeaderRail<RailView>
                                    items={[
                                        {
                                            key: 'overview',
                                            label: 'Overview',
                                            icon: LayoutDashboard,
                                        },
                                        {
                                            key: 'details',
                                            label: 'Detailed reports',
                                            icon: ListChecks,
                                        },
                                        {
                                            key: 'workload',
                                            label: 'Current workload',
                                            icon: Users,
                                        },
                                    ]}
                                    value={rail}
                                    onSelect={(view) =>
                                        view === 'workload'
                                            ? visit({
                                                  scope: 'current',
                                                  view: 'overview',
                                                  metric: 'received',
                                              })
                                            : visit({
                                                  scope: 'received',
                                                  view,
                                                  metric: 'received',
                                              })
                                    }
                                />
                            )
                        }
                    />
                }
            >
                {denied ? (
                    <ErrorState
                        title="Your report access needs to be checked again"
                        message="Private report content has been hidden. Check your access to continue."
                        onRetry={() => router.reload()}
                    />
                ) : (
                    <ReportPrivacyContext.Provider value={report.privacy}>
                        <div className="space-y-5">
                            {error && (
                                <Alert variant="destructive">
                                    <AlertDescription>{error}</AlertDescription>
                                </Alert>
                            )}
                            <p className="text-caption text-muted-foreground">
                                {report.definitions.scope}{' '}
                                {report.definitions.cutoff} Generated{' '}
                                {formatDateTimeLong(report.generated_at)} NZ
                                time.
                            </p>
                            {report.metrics.some(
                                (metric) => metric.suppressed,
                            ) && (
                                // Explains a hidden number in the header meters.
                                <p className="text-subtle">
                                    {suppressionCaption(report)} The matching
                                    referrals list shows the records you can
                                    open.
                                </p>
                            )}

                            {rail === 'overview' && (
                                <>
                                    <div className="grid gap-5 xl:grid-cols-3">
                                        <div className="xl:col-span-2">
                                            <ReferralTrend
                                                report={report}
                                                onOpen={() => drill('received')}
                                            />
                                        </div>
                                        <ReferralHandoverGauge
                                            report={report}
                                            onOpen={drill}
                                        />
                                    </div>
                                    <div className="grid gap-5 lg:grid-cols-2">
                                        <ReferralDistribution
                                            title="Where referrals are now"
                                            description="Current stage of this received cohort. Select a stage to list it."
                                            rows={report.groups.stage}
                                            onSelect={(stage) =>
                                                drillBy({ stage })
                                            }
                                        />
                                        <ReferralSourceChart
                                            rows={report.groups.source}
                                            onSelect={(source) =>
                                                drillBy({ source })
                                            }
                                        />
                                    </div>
                                    <div className="grid gap-5 lg:grid-cols-2">
                                        <ReportPanel
                                            title="Needs attention in this cohort"
                                            description="Referrals received outside this period are in Current workload. A person can appear in more than one measure."
                                        >
                                            <ReportMeasureLinks
                                                report={report}
                                                keys={[
                                                    'overdue',
                                                    'unassigned',
                                                    'capacity_waitlist',
                                                ]}
                                                onOpen={drill}
                                            />
                                        </ReportPanel>
                                        <ReferralDistribution
                                            title="Work by coordinator"
                                            description="Current accountable owner of referrals in this cohort."
                                            rows={report.groups.owner}
                                            onSelect={(owner) =>
                                                drillBy({ owner })
                                            }
                                        />
                                    </div>
                                </>
                            )}

                            {rail === 'workload' &&
                                filters.view === 'overview' && (
                                    <>
                                        <div className="grid gap-5 lg:grid-cols-2">
                                            <ReportPanel
                                                title="All current work needing attention"
                                                description="Every open referral you can see, including those received before any report period."
                                            >
                                                <ReportMeasureLinks
                                                    report={report}
                                                    keys={[
                                                        'overdue',
                                                        'unassigned',
                                                        'capacity_waitlist',
                                                    ]}
                                                    onOpen={drill}
                                                />
                                            </ReportPanel>
                                            <ReferralDistribution
                                                title="Current workload by coordinator"
                                                description="Every matching open referral, regardless of when it arrived."
                                                rows={report.groups.owner}
                                                onSelect={(owner) =>
                                                    drillBy({ owner })
                                                }
                                            />
                                        </div>
                                        <div className="grid gap-5 lg:grid-cols-2">
                                            <ReferralDistribution
                                                title="Where open work is now"
                                                rows={report.groups.stage}
                                                onSelect={(stage) =>
                                                    drillBy({ stage })
                                                }
                                            />
                                            <ReferralDistribution
                                                title="Reasons for waiting"
                                                description="Current recorded hold reasons."
                                                rows={report.groups.hold_reason}
                                            />
                                        </div>
                                        <ReferralReportAges
                                            report={report}
                                            onStage={(stage_age) =>
                                                drillBy({ stage_age })
                                            }
                                            onFunding={(
                                                funding_kind,
                                                funding_age,
                                            ) =>
                                                drillBy({
                                                    funding_kind,
                                                    funding_age,
                                                })
                                            }
                                        />
                                    </>
                                )}

                            {filters.view === 'details' && (
                                <>
                                    <ReportMeasuresTable
                                        report={report}
                                        selected={filters.metric}
                                        onOpen={drill}
                                    />
                                    <div className="grid gap-5 lg:grid-cols-2 xl:grid-cols-3">
                                        <ReferralDistribution
                                            title="How people reached us"
                                            rows={report.groups.source}
                                            onSelect={(source) =>
                                                drillBy({ source })
                                            }
                                        />
                                        <ReferralDistribution
                                            title="Reported regions"
                                            description={
                                                report.definitions.region
                                            }
                                            rows={report.groups.region ?? []}
                                            onSelect={(region) =>
                                                drillBy({ region })
                                            }
                                        />
                                        <ReferralDistribution
                                            title="Services requested"
                                            rows={
                                                report.groups.requested_service
                                            }
                                        />
                                        <ReferralDistribution
                                            title="Reasons for waiting"
                                            description="Current recorded hold reasons; capacity is a separate measure."
                                            rows={report.groups.hold_reason}
                                        />
                                        <ReferralDistribution
                                            title="Closure outcomes"
                                            description="Includes information-only and another-provider outcomes."
                                            rows={report.groups.closure_reason}
                                        />
                                        <ReferralDistribution
                                            title="Record classification"
                                            description={
                                                report.definitions
                                                    .classification
                                            }
                                            rows={
                                                report.groups.classification ??
                                                []
                                            }
                                            onSelect={(classification) =>
                                                drillBy({ classification })
                                            }
                                        />
                                    </div>
                                    <ReferralReportAges
                                        report={report}
                                        onStage={(stage_age) =>
                                            drillBy({ stage_age })
                                        }
                                        onFunding={(
                                            funding_kind,
                                            funding_age,
                                        ) =>
                                            drillBy({
                                                funding_kind,
                                                funding_age,
                                            })
                                        }
                                    />
                                    <ReferralWorkingTimeReport
                                        report={report}
                                    />
                                    <ReferralHistoryReport
                                        report={report}
                                        onReopened={() =>
                                            visit({
                                                metric: 'reopened',
                                                view: 'details',
                                            })
                                        }
                                    />
                                    {report.funding && (
                                        <section
                                            className="space-y-4"
                                            aria-label="Funding assistance reports"
                                        >
                                            <div>
                                                <h2 className="text-section-title">
                                                    Funding assistance
                                                </h2>
                                                <p className="text-subtle">
                                                    {shownCount(
                                                        report,
                                                        report.funding
                                                            .provider_cases,
                                                        report.funding
                                                            .provider_cases_suppressed,
                                                    )}{' '}
                                                    provider-funding cases ·{' '}
                                                    {shownCount(
                                                        report,
                                                        report.funding
                                                            .income_cases,
                                                        report.funding
                                                            .income_cases_suppressed,
                                                    ).toLowerCase()}{' '}
                                                    personal-income cases.{' '}
                                                    {report.definitions.funding}
                                                </p>
                                            </div>
                                            <div className="grid gap-5 lg:grid-cols-3">
                                                <ReferralDistribution
                                                    title="Pathways"
                                                    rows={
                                                        report.funding
                                                            .by_pathway
                                                    }
                                                    unit="cases"
                                                />
                                                <ReferralDistribution
                                                    title="Assistance progress"
                                                    rows={
                                                        report.funding.by_status
                                                    }
                                                    unit="cases"
                                                />
                                                <ReferralDistribution
                                                    title="Latest external decisions"
                                                    description="A recorded decision is not independent verification or authority to start support."
                                                    rows={
                                                        report.funding.outcomes
                                                    }
                                                    unit="cases"
                                                />
                                            </div>
                                        </section>
                                    )}
                                    <section
                                        className="space-y-4"
                                        aria-label="Matching referrals"
                                    >
                                        <div className="flex flex-wrap items-center justify-between gap-3">
                                            <div>
                                                <h2
                                                    ref={matchingHeading}
                                                    tabIndex={-1}
                                                    className="text-section-title scroll-mt-20 focus-visible:outline-2 focus-visible:outline-ring"
                                                >
                                                    {selected.label} ·{' '}
                                                    {records.total} matching
                                                </h2>
                                                <p className="text-subtle">
                                                    {selected.definition}
                                                </p>
                                            </div>
                                            {filters.metric !== 'received' && (
                                                <Button
                                                    variant="outline"
                                                    onClick={() =>
                                                        visit({
                                                            metric: 'received',
                                                        })
                                                    }
                                                >
                                                    Show all in scope
                                                </Button>
                                            )}
                                        </div>
                                        {!records.data.length ? (
                                            <EmptyState
                                                icon={Inbox}
                                                title="No matching referrals"
                                                description="Try another measure, site or period."
                                            />
                                        ) : (
                                            <EntityTable
                                                rows={records.data}
                                                rowKey={(row) => row.reference}
                                                identity={(row) => ({
                                                    icon: Inbox,
                                                    name: row.name,
                                                    subline: row.reference,
                                                })}
                                                identityLabel="Referral"
                                                wrapIdentity
                                                rowHeight="content"
                                                hrefFor={(row) =>
                                                    referralHref(row.reference)
                                                }
                                                actionsFor={(row) => [
                                                    {
                                                        label: 'Open referral',
                                                        icon: Eye,
                                                        onClick: () =>
                                                            router.visit(
                                                                referralHref(
                                                                    row.reference,
                                                                ),
                                                            ),
                                                    },
                                                ]}
                                                columns={[
                                                    {
                                                        key: 'stage',
                                                        label: 'Stage',
                                                        width: '1fr',
                                                        cell: (row) => (
                                                            <ReferralStageBadge
                                                                stage={
                                                                    row.stage
                                                                }
                                                            />
                                                        ),
                                                    },
                                                    {
                                                        key: 'owner',
                                                        label: 'Owner',
                                                        width: '1fr',
                                                        cell: (row) =>
                                                            row.owner_name ??
                                                            'Unassigned',
                                                    },
                                                    {
                                                        key: 'site',
                                                        label: 'Site',
                                                        width: '1fr',
                                                        cell: (row) =>
                                                            row.site_name ??
                                                            'Central intake',
                                                    },
                                                    {
                                                        key: 'received',
                                                        label: 'Received',
                                                        width: '1.2fr',
                                                        cell: (row) =>
                                                            formatDateTimeLong(
                                                                row.created_at,
                                                            ),
                                                    },
                                                    {
                                                        key: 'stage_age',
                                                        label: 'Time in stage',
                                                        width: '1fr',
                                                        cell: (row) =>
                                                            row.stage_age_label ??
                                                            'Not recorded',
                                                    },
                                                ]}
                                            />
                                        )}
                                        <LaravelPagination
                                            links={records.links}
                                            lastPage={records.last_page}
                                            preserveScroll
                                        />
                                    </section>
                                </>
                            )}
                        </div>
                    </ReportPrivacyContext.Provider>
                )}
            </PageLayout>
            {dates && !denied && (
                <ReportDates
                    filters={filters}
                    onClose={() => setDates(false)}
                    onApply={(from, to) => {
                        setDates(false);
                        visit({ from, to });
                    }}
                />
            )}
            {more && !denied && (
                <ReportMoreFilters
                    filters={filters}
                    canFunding={!!permissions['funding.view']}
                    onClose={() => setMore(false)}
                    onApply={(patch) => {
                        setMore(false);
                        visit(patch);
                    }}
                />
            )}
            {exporting && !denied && (
                <ReportExport
                    sites={sites}
                    report={report}
                    recordCount={records.total}
                    onClose={() => setExporting(false)}
                    onDenied={() => {
                        setDenied(true);
                        setExporting(false);
                    }}
                />
            )}
        </AppLayout>
    );
}
