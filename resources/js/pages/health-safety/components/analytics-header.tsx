import { LeaveCalendarRange } from '@/components/hr/leave-calendar-range';
import {
    PageHeader,
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderMeterDelta,
    PageHeaderPrimaryButton,
    PageHeaderRail,
} from '@/components/page/page-header';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { formatDateOnly } from '@/lib/datetime';
import { Link } from '@inertiajs/react';
import {
    BarChart3,
    CalendarDays,
    ChevronDown,
    Download,
    FileText,
    LayoutDashboard,
} from 'lucide-react';
import { useEffect, useState, type ComponentType } from 'react';
import type { AnalyticsProps } from '../analytics-types';

const PERIODS = [
    { value: '30d', label: 'Last 30 days' },
    { value: 'q', label: 'This quarter' },
    { value: '6m', label: 'Last 6 months' },
    { value: 'ytd', label: 'Year to date' },
    { value: 'custom', label: 'Custom range' },
];
type HeaderProps = Pick<
    AnalyticsProps,
    'filters' | 'sites' | 'scorecard' | 'period_summary' | 'worksafe_notifiable'
> & {
    tab: string;
    tabs: {
        id: string;
        label: string;
        icon?: ComponentType<{ className?: string }>;
    }[];
    onTabChange: (tab: string) => void;
    onPeriod: (period: string) => void;
    onReload: (next: Record<string, string | number | null>) => void;
    exportHref: string;
    reports: { label: string; href: string }[];
    canViewBoardReports: boolean;
};

export function AnalyticsHeader({
    filters,
    sites,
    scorecard,
    period_summary,
    worksafe_notifiable,
    tab,
    tabs,
    onTabChange,
    onPeriod,
    onReload,
    exportHref,
    reports,
    canViewBoardReports,
}: HeaderProps) {
    const [datesOpen, setDatesOpen] = useState(false);
    const [from, setFrom] = useState(filters.from);
    const [to, setTo] = useState(filters.to);
    const [calendarRevision, setCalendarRevision] = useState(
        `${filters.from}:${filters.to}`,
    );
    useEffect(() => {
        setFrom(filters.from);
        setTo(filters.to);
        setCalendarRevision(`${filters.from}:${filters.to}`);
    }, [filters.from, filters.to]);
    const changeDatesOpen = (open: boolean) => {
        if (open) {
            setFrom(filters.from);
            setTo(filters.to);
        }
        setDatesOpen(open);
    };
    const completeRange =
        !!formatDateOnly(from, '') && !!formatDateOnly(to, '') && from <= to;
    const siteScope =
        filters.site_id === null
            ? 'All approved sites'
            : (sites.find((site) => site.id === filters.site_id)?.name ??
              'Site access unconfirmed');
    const metrics =
        filters.lens === 'governance'
            ? [
                  {
                      key: 'incidents',
                      label: 'Incidents',
                      caption: 'Recorded in this period',
                      view: 'breakdowns',
                  },
                  {
                      key: 'ltifr',
                      label: 'LTIFR',
                      caption: 'Recorded rate · per million hours',
                      view: 'trends',
                  },
                  {
                      key: 'trifr',
                      label: 'TRIFR',
                      caption: 'Recorded rate · per million hours',
                      view: 'trends',
                  },
                  {
                      key: 'days_since_lti',
                      label: 'Days since LTI',
                      caption: 'Last readable lost-time injury',
                      view: 'overview',
                  },
              ]
            : [
                  {
                      key: 'near_miss_ratio',
                      label: 'Near-miss ratio',
                      caption: 'Recorded ratio',
                      view: 'trends',
                  },
                  {
                      key: 'actions_on_time_pct',
                      label: 'Actions on time',
                      caption: 'Recorded due-action performance',
                      view: 'overview',
                  },
                  {
                      key: 'training_pct',
                      label: 'Training',
                      caption: 'Recorded compliance',
                      view: 'overview',
                  },
                  {
                      key: 'open_hazards',
                      label: 'Open hazards',
                      caption: 'Recorded open work',
                      view: 'breakdowns',
                  },
              ];
    return (
        <PageHeader
            title="Health & Safety analytics"
            icon={BarChart3}
            wrapTitle
            frontline={filters.lens === 'frontline'}
            subline={`${siteScope} · ${formatDateOnly(filters.from)} – ${formatDateOnly(filters.to)} · ${PERIODS.find((period) => period.value === filters.period)?.label ?? 'Reporting period'}`}
            actions={
                <>
                    <PageHeaderGlassButton asChild icon={LayoutDashboard}>
                        <Link href="/health-safety">Safety overview</Link>
                    </PageHeaderGlassButton>
                    <PageHeaderPrimaryButton asChild icon={Download}>
                        <a href={exportHref}>Export</a>
                    </PageHeaderPrimaryButton>
                    {canViewBoardReports && (
                        <Popover>
                            <PopoverTrigger asChild>
                                <PageHeaderGlassButton icon={FileText}>
                                    Board reports
                                    <ChevronDown className="size-3.5" />
                                </PageHeaderGlassButton>
                            </PopoverTrigger>
                            <PopoverContent align="end" className="w-60 p-1">
                                {reports.map((report) => (
                                    <a
                                        key={report.href}
                                        href={report.href}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="frontline-hit text-subtle block rounded-md px-3 py-2 text-foreground outline-none hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring"
                                    >
                                        {report.label}
                                    </a>
                                ))}
                            </PopoverContent>
                        </Popover>
                    )}
                </>
            }
            meters={
                <>
                    {metrics.map((metric) => {
                        const item = [
                            ...scorecard.leading,
                            ...scorecard.lagging,
                        ].find((entry) => entry.key === metric.key);
                        const hasDelta =
                            item?.value !== null &&
                            item?.value !== undefined &&
                            item?.delta !== null &&
                            item?.delta !== undefined &&
                            item.delta !== 0;
                        return (
                            <PageHeaderMeterBlock
                                key={metric.key}
                                label={metric.label}
                                onClick={() => onTabChange(metric.view)}
                            >
                                <PageHeaderMeterBig>
                                    {item?.value === null ||
                                    item?.value === undefined
                                        ? '—'
                                        : `${item.value}${item.suffix}`}
                                </PageHeaderMeterBig>
                                <PageHeaderMeterCaption>
                                    {item?.value === null ||
                                    item?.value === undefined
                                        ? 'Not confirmed'
                                        : metric.caption}
                                </PageHeaderMeterCaption>
                                {hasDelta && (
                                    <PageHeaderMeterDelta
                                        trend={item!.delta! > 0 ? 'up' : 'down'}
                                        good={item!.dir === 'improving'}
                                    >
                                        <span className="sr-only">
                                            {item!.delta! > 0
                                                ? 'Increased'
                                                : 'Decreased'}{' '}
                                            by{' '}
                                        </span>
                                        {Math.abs(item!.delta!)}
                                    </PageHeaderMeterDelta>
                                )}
                            </PageHeaderMeterBlock>
                        );
                    })}
                    <PageHeaderMeterBlock
                        label="WorkSafe review"
                        onClick={() => onTabChange('governance')}
                        tone={
                            worksafe_notifiable.awaiting > 0
                                ? 'warning'
                                : 'brand'
                        }
                    >
                        <PageHeaderMeterBig>
                            {worksafe_notifiable.awaiting}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Recorded awaiting review
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Drills"
                        onClick={() => onTabChange('governance')}
                    >
                        <PageHeaderMeterBig>
                            {period_summary.drills_complete}/
                            {period_summary.drills_total}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Recorded completed drills
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            filters={
                <>
                    <PageHeaderFilterSelect
                        label="Period"
                        value={filters.period}
                        allValue="ytd"
                        options={PERIODS}
                        onChange={(period) => {
                            if (period === 'custom') changeDatesOpen(true);
                            else onPeriod(period);
                        }}
                    />
                    <Popover open={datesOpen} onOpenChange={changeDatesOpen}>
                        <PopoverTrigger asChild>
                            <PageHeaderGlassButton icon={CalendarDays}>
                                Set dates
                            </PageHeaderGlassButton>
                        </PopoverTrigger>
                        <PopoverContent
                            className="date-picker-popover space-y-3"
                            data-compact
                            aria-label="Analytics period picker"
                            align="start"
                            collisionPadding={16}
                        >
                            <div
                                role="group"
                                aria-label="Analytics period"
                                className="space-y-3"
                            >
                                <h3 className="text-section-title">
                                    Analytics period
                                </h3>
                                <p className="text-subtle">
                                    Choose a start and end day. Select the same
                                    day twice for one day. Both dates are
                                    included.
                                </p>
                                <div className="date-time-calendar scrollbar-pretty overflow-x-auto [&_button[aria-pressed]]:min-h-11 [&_button[aria-pressed]]:min-w-11">
                                    <div className="min-w-[308px]">
                                        <LeaveCalendarRange
                                            key={calendarRevision}
                                            start={from || null}
                                            end={to || null}
                                            required={false}
                                            onChange={(start, end) => {
                                                setFrom(start ?? '');
                                                setTo(end ?? '');
                                            }}
                                        />
                                    </div>
                                </div>
                                <p className="text-caption sm:hidden">
                                    Scroll sideways to see more days if needed.
                                </p>
                                <p
                                    role="status"
                                    aria-live="polite"
                                    className="text-subtle"
                                >
                                    {completeRange
                                        ? `${formatDateOnly(from)} – ${formatDateOnly(to)} · inclusive`
                                        : from
                                          ? `Starts ${formatDateOnly(from)} · choose the end day`
                                          : 'Choose the start day'}
                                </p>
                            </div>
                            <div className="flex flex-wrap justify-end gap-2">
                                <Button
                                    type="button"
                                    variant="ghost"
                                    className="frontline-tap"
                                    onClick={() => setDatesOpen(false)}
                                >
                                    Cancel
                                </Button>
                                <Button
                                    type="button"
                                    className="frontline-tap"
                                    disabled={!completeRange}
                                    onClick={() => {
                                        onReload({
                                            period: 'custom',
                                            from,
                                            to,
                                        });
                                        setDatesOpen(false);
                                    }}
                                >
                                    Apply range
                                </Button>
                            </div>
                        </PopoverContent>
                    </Popover>
                    <PageHeaderFilterSelect
                        label="Site"
                        value={
                            filters.site_id === null
                                ? 'all'
                                : String(filters.site_id)
                        }
                        allValue="all"
                        options={[
                            { value: 'all', label: 'All approved sites' },
                            ...sites.map((site) => ({
                                value: String(site.id),
                                label: site.name,
                            })),
                        ]}
                        onChange={(site) =>
                            onReload({
                                site_id: site === 'all' ? null : Number(site),
                            })
                        }
                    />
                    <PageHeaderFilterSelect
                        label="Lens"
                        value={filters.lens}
                        allValue="manager"
                        options={[
                            { value: 'governance', label: 'Governance' },
                            { value: 'manager', label: 'Manager' },
                            { value: 'frontline', label: 'Frontline' },
                        ]}
                        onChange={(lens) => onReload({ lens })}
                    />
                </>
            }
            rail={
                <PageHeaderRail
                    items={tabs.map(({ id, label, icon }) => ({
                        key: id,
                        label,
                        icon,
                    }))}
                    value={tab}
                    onSelect={onTabChange}
                    ariaLabel="Analytics views"
                />
            }
            mobileSummary="Analytics · reporting period and recorded measures"
        />
    );
}

export function AnalyticsPeriodSummary({
    summary,
    sdsExpiring,
    sourceCompleteness,
}: {
    summary: AnalyticsProps['period_summary'];
    sdsExpiring: number;
    sourceCompleteness?: 'unknown';
}) {
    const [open, setOpen] = useState(true);
    return (
        <section
            aria-label="Recorded period activity"
            className="rounded-xl border bg-card p-4"
        >
            {sourceCompleteness === 'unknown' && (
                <p role="status" className="text-subtle">
                    These measures cover records you can access. The full
                    picture may be incomplete.
                </p>
            )}
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h2 className="text-section-title">Recorded period activity</h2>
                <Button
                    type="button"
                    variant="ghost"
                    className="frontline-tap"
                    aria-expanded={open}
                    aria-controls="analytics-period-activity"
                    onClick={() => setOpen(!open)}
                >
                    {open ? 'Hide activity' : 'Show activity'}
                </Button>
            </div>
            {open && (
                <div id="analytics-period-activity" className="space-y-2">
                    <p className="text-subtle">
                        Recorded activity in the current scope. Open the
                        registers for outstanding review and safety work.
                    </p>
                    <ul className="text-subtle flex flex-wrap gap-x-5 gap-y-2">
                        <li>{summary.incidents} incidents</li>
                        <li>{summary.near_misses} near misses</li>
                        <li>
                            {summary.worksafe_awaiting} awaiting WorkSafe review
                        </li>
                        <li>{summary.open_hazards} open hazards</li>
                        <li>
                            {summary.actions_on_time_pct === null
                                ? 'Actions on time unconfirmed'
                                : `${summary.actions_on_time_pct}% actions on time`}
                        </li>
                        <li>
                            {summary.drills_complete}/{summary.drills_total}{' '}
                            drills complete
                        </li>
                        <li>{sdsExpiring} expiring safety data sheets</li>
                    </ul>
                </div>
            )}
        </section>
    );
}
