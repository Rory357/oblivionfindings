import { LeaveCalendarRange } from '@/components/hr/leave-calendar-range';
import {
    PageHeader,
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
    PageHeaderPrimaryButton,
    PageHeaderRail,
} from '@/components/page/page-header';
import { Tally } from '@/components/signature/blocks';
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
            ? 'All sites'
            : (sites.find((site) => site.id === filters.site_id)?.name ??
              'Site access unconfirmed');
    return (
        <PageHeader
            title="Health & safety analytics"
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
                    items={tabs.map(({ id, label }) => ({
                        key: id,
                        label,
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

const LENS_LINE: Record<string, string> = {
    governance: 'Governance view: rates, notifiable events and assurance come first',
    manager: 'Manager view: incidents, investigations and overdue actions come first, then trends',
    frontline: 'Frontline view: hazard alerts and lone-worker check-ins come first',
};

/**
 * "This period" (approved H&S drawing 45): activity in the current view as one tally. The header no longer
 * carries numbers; the prevention and harm measures follow as their own tallies. Unconfirmed values stay
 * distinct from a recorded zero.
 */
export function AnalyticsPeriodSummary({
    summary,
    sdsExpiring,
    sourceCompleteness,
    lens = 'manager',
}: {
    summary: AnalyticsProps['period_summary'];
    sdsExpiring: number;
    sourceCompleteness?: 'unknown';
    lens?: string;
}) {
    const drillsIncomplete = summary.drills_complete < summary.drills_total;
    return (
        <Tally
            aria-label="Recorded period activity"
            title="This period"
            sub={[
                'Activity in your current view. Open the registers for work still to do',
                LENS_LINE[lens] ?? LENS_LINE.manager,
                sourceCompleteness === 'unknown'
                    ? 'These measures cover records you can access. The full picture may be incomplete.'
                    : null,
            ]
                .filter(Boolean)
                .join(' · ')}
            keepZeros
            items={[
                { value: String(summary.incidents), label: 'Incidents' },
                { value: String(summary.near_misses), label: 'Near misses' },
                {
                    value: String(summary.worksafe_awaiting),
                    label: 'WorkSafe reviews to do',
                    tone: summary.worksafe_awaiting > 0 ? 'critical' : undefined,
                },
                {
                    value: `${summary.drills_complete}/${summary.drills_total}`,
                    label: 'Drills complete',
                    tone: drillsIncomplete ? 'warning' : undefined,
                },
                {
                    value: String(sdsExpiring),
                    label: 'Expiring safety data sheets',
                    tone: sdsExpiring > 0 ? 'warning' : undefined,
                },
            ]}
        />
    );
}
