/** H&S overview uses the shared PageHeader; attention is separate, source-aware content. */
import { LeaveCalendarRange } from '@/components/hr/leave-calendar-range';
import {
    PageHeader,
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
    PageHeaderSearch,
    type PageHeaderMeterTone,
} from '@/components/page/page-header';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { StatusBadge } from '@/components/ui/status-badge';
import { formatDateOnly, toDateInput, workerDayRange } from '@/lib/datetime';
import type { SharedData } from '@/types';
import { Link, router, usePage } from '@inertiajs/react';
import {
    BarChart3,
    CalendarDays,
    ChevronDown,
    Download,
    Plus,
    ShieldCheck,
} from 'lucide-react';
import { useEffect, useState, type ReactNode } from 'react';
import type { PpeExpiringState } from './worklists';

export type HeroLeadingLagging = {
    source_scope?: 'readable_subset';
    source_completeness?: 'unknown';
    lagging: {
        incidents: number;
        ltifr: number | null;
        trifr: number | null;
        injury_severity_rate: number | null;
        days_since_lti: number | null;
    };
    leading: {
        near_miss_ratio: number | null;
        actions_on_time_pct: number | null;
        training_pct: number | null;
        open_hazards: number;
    };
};
export type HeroFilters = {
    from: string;
    to: string;
    site: number | null;
    lens: string;
};
type AttentionProps = {
    expiring: Array<{ type: string }>;
    ppeState?: PpeExpiringState;
    worksafePending: number | null;
    activeAlerts: number | null;
};
export type CommandCentreHeroProps = AttentionProps & {
    sourceCompleteness?: 'unknown';
    leadingLagging: HeroLeadingLagging;
    filters: HeroFilters;
    sites: Array<{ id: number; name: string }>;
    openSafeguarding: number | null;
    fleetUnresolved: number | null;
    fleetIncidents30d: number | null;
    procedures: {
        approved: number;
        review_due: number;
        coverage_gap_categories: number;
    };
    onReport?: () => void;
    orgName?: string | null;
    rail?: ReactNode;
    search?: { value: string; onChange: (value: string) => void };
};
const GOV_REPORTS = [
    { label: 'Board summary', href: '/health-safety/reports/board-summary' },
    {
        label: 'WorkSafe register',
        href: '/health-safety/reports/worksafe-register',
    },
    {
        label: 'Investigation outcomes',
        href: '/health-safety/reports/investigation-outcomes',
    },
    {
        label: 'Corrective-action traceability',
        href: '/health-safety/reports/corrective-action-traceability',
    },
    {
        label: 'Risk-assessment register',
        href: '/health-safety/reports/risk-assessment-register',
    },
];
function presetRange(
    key: string,
    asAt: Date = new Date(),
): { from: string; to: string } {
    if (key !== 'week')
        return workerDayRange(key === 'quarter' ? 90 : 30, asAt);
    const to = toDateInput(asAt);
    const calendar = new Date(to + 'T00:00:00Z');
    calendar.setUTCDate(
        calendar.getUTCDate() - ((calendar.getUTCDay() + 6) % 7),
    );
    return { from: calendar.toISOString().slice(0, 10), to };
}
const fmt = (value: number | null, suffix = '') =>
    value === null ? '—' : String(value) + suffix;
type Meter = {
    label: string;
    value: string;
    caption: string;
    href?: string;
    ariaLabel?: string;
    tone?: PageHeaderMeterTone;
};

export function CommandCentreHero({
    sourceCompleteness,
    leadingLagging,
    filters,
    sites,
    openSafeguarding,
    fleetUnresolved,
    fleetIncidents30d,
    procedures,
    onReport,
    orgName,
    rail,
    search,
}: CommandCentreHeroProps) {
    const canViewBoardReports =
        usePage<SharedData>().props.auth.can?.governance?.view ?? false;
    const [customFrom, setCustomFrom] = useState(filters.from);
    const [customTo, setCustomTo] = useState(filters.to);
    const [calendarRevision, setCalendarRevision] = useState(
        `${filters.from}:${filters.to}`,
    );
    const [datesOpen, setDatesOpen] = useState(false);
    const [fleetAccessNotice, setFleetAccessNotice] = useState(false);
    useEffect(() => {
        setCustomFrom(filters.from);
        setCustomTo(filters.to);
        setCalendarRevision(`${filters.from}:${filters.to}`);
    }, [filters.from, filters.to]);
    const changeDatesOpen = (open: boolean) => {
        if (open) {
            setCustomFrom(filters.from);
            setCustomTo(filters.to);
        }
        setDatesOpen(open);
    };
    const go = (params: Partial<HeroFilters>) => {
        const merged: Record<string, string | number> = {
            from: filters.from,
            to: filters.to,
            lens: filters.lens,
        };
        if (filters.site !== null) merged.site = filters.site;
        for (const [key, value] of Object.entries(params)) {
            if (value == null) delete merged[key];
            else merged[key] = value;
        }
        router.get('/health-safety', merged, {
            preserveScroll: true,
            preserveState: true,
        });
    };
    const period =
        ['week', '30d', 'quarter'].find((key) => {
            const range = presetRange(key);
            return range.from === filters.from && range.to === filters.to;
        }) ?? 'custom';
    const activeSite = sites.find((site) => site.id === filters.site);
    const siteLabel =
        filters.site === null
            ? 'All approved sites'
            : (activeSite?.name ?? 'Site access unconfirmed');
    const lagging = leadingLagging.lagging;
    const leading = leadingLagging.leading;
    // These keys are consumed by the destination controllers. Current-state
    // registers deliberately receive no occurrence-date filter.
    const destination = (path: string, params: Record<string, string> = {}) => {
        const query = new URLSearchParams(params);
        if (filters.site !== null) query.set('site_id', String(filters.site));
        return path + (query.size ? '?' + query.toString() : '');
    };
    const selectedPeriod = { from: filters.from, to: filters.to };
    const analytics = destination('/health-safety/analytics', {
        period: 'custom',
        ...selectedPeriod,
        lens: filters.lens,
    });
    const outcomes: Meter[] = [
        {
            label: 'Incidents',
            value: fmt(lagging.incidents),
            caption: 'selected period · excludes near misses',
            href: destination('/incidents', {
                ...selectedPeriod,
                kind: 'incidents',
                tab: 'all',
            }),
            tone: lagging.incidents > 0 ? 'warning' : 'brand',
        },
        {
            label: 'LTIFR',
            value: fmt(lagging.ltifr),
            caption: 'year basis · per million hours',
            href: analytics,
        },
        {
            label: 'TRIFR',
            value: fmt(lagging.trifr),
            caption: 'year basis · per million hours',
            href: analytics,
        },
        {
            label: 'Days since LTI',
            value: fmt(lagging.days_since_lti),
            caption:
                lagging.days_since_lti === null
                    ? 'Not confirmed'
                    : 'last readable lost-time injury',
            href: analytics,
        },
        {
            label: 'Safeguarding',
            value: fmt(openSafeguarding),
            caption:
                openSafeguarding === null
                    ? 'Not confirmed'
                    : 'open concerns now',
            href: destination('/safeguarding', { tab: 'open' }),
            tone: (openSafeguarding ?? 0) > 0 ? 'warning' : 'brand',
        },
        {
            label: 'Fleet incidents',
            value: fmt(fleetUnresolved),
            caption:
                fleetUnresolved === null
                    ? 'Access restricted'
                    : 'unresolved now',
            href:
                fleetUnresolved === null
                    ? undefined
                    : destination('/fleet-assets/incidents', { tab: 'open' }),
            tone: (fleetUnresolved ?? 0) > 0 ? 'warning' : 'brand',
        },
    ];
    const prevention: Meter[] = [
        {
            label: 'Near-miss ratio',
            value: fmt(leading.near_miss_ratio, '×'),
            caption: 'year basis · per recordable injury',
            href: analytics,
        },
        {
            label: 'Actions on time',
            value: fmt(leading.actions_on_time_pct, '%'),
            caption: 'due in selected period',
            href: destination('/health-safety/corrective-actions', {
                ...selectedPeriod,
                tab: 'all',
            }),
        },
        {
            label: 'Training',
            value: fmt(leading.training_pct, '%'),
            caption: 'current recorded compliance',
            href: analytics,
        },
        {
            label: 'Open hazards',
            value: fmt(leading.open_hazards),
            caption: 'open and in progress now',
            href: destination('/compliance/hazards', { tab: 'active' }),
            tone: leading.open_hazards > 0 ? 'warning' : 'brand',
        },
        {
            label: 'Procedures',
            value: fmt(procedures.approved),
            caption:
                procedures.review_due > 0
                    ? String(procedures.review_due) + ' review due'
                    : procedures.coverage_gap_categories > 0
                      ? String(procedures.coverage_gap_categories) +
                        ' coverage gaps'
                      : 'approved records',
            href: destination('/health-safety/procedures', { tab: 'approved' }),
            tone:
                procedures.coverage_gap_categories > 0
                    ? 'critical'
                    : procedures.review_due > 0
                      ? 'warning'
                      : 'brand',
        },
        // Fleet's register currently accepts civil-date filters, while this
        // source is a rolling-instant count. Do not invent a matching range.
        {
            label: 'Fleet · 30 days',
            value: fmt(fleetIncidents30d),
            caption:
                fleetIncidents30d === null
                    ? 'Access restricted'
                    : '30-day count · full register',
            href:
                fleetIncidents30d === null
                    ? undefined
                    : destination('/fleet-assets/incidents'),
            ariaLabel: 'Open full fleet incident register',
        },
    ];
    const validRange =
        !!formatDateOnly(customFrom, '') &&
        !!formatDateOnly(customTo, '') &&
        customFrom <= customTo;
    return (
        <PageHeader
            frontline={filters.lens === 'frontline'}
            wrapTitle
            icon={ShieldCheck}
            title="Health & Safety"
            subline={[
                siteLabel,
                orgName,
                formatDateOnly(filters.from) +
                    ' – ' +
                    formatDateOnly(filters.to),
            ]
                .filter(Boolean)
                .join(' · ')}
            actions={
                <>
                    {search && (
                        <PageHeaderSearch
                            value={search.value}
                            onChange={search.onChange}
                            placeholder="Search current overview…"
                            ariaLabel="Search current safety overview"
                        />
                    )}
                    <PageHeaderGlassButton asChild icon={BarChart3}>
                        <Link href={analytics}>View analytics</Link>
                    </PageHeaderGlassButton>
                    {canViewBoardReports && (
                        <Popover>
                            <PopoverTrigger asChild>
                                <PageHeaderGlassButton icon={Download}>
                                    Export board summary
                                    <ChevronDown className="size-3.5" />
                                </PageHeaderGlassButton>
                            </PopoverTrigger>
                            <PopoverContent align="end" className="w-60 p-1">
                                {GOV_REPORTS.map((report) => (
                                    <Link
                                        key={report.href}
                                        href={report.href}
                                        className="frontline-hit text-subtle block rounded-md px-3 py-2 text-foreground outline-none hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring"
                                    >
                                        {report.label}
                                    </Link>
                                ))}
                            </PopoverContent>
                        </Popover>
                    )}
                    {onReport && (
                        <PageHeaderPrimaryButton icon={Plus} onClick={onReport}>
                            Report
                        </PageHeaderPrimaryButton>
                    )}
                </>
            }
            meters={
                <>
                    {(filters.lens === 'governance'
                        ? outcomes
                        : prevention
                    ).map((meter) => (
                        <PageHeaderMeterBlock
                            key={meter.label}
                            label={meter.label}
                            {...(meter.href
                                ? { href: meter.href }
                                : {
                                      onClick: () => setFleetAccessNotice(true),
                                  })}
                            ariaLabel={
                                meter.href
                                    ? meter.ariaLabel
                                    : 'Explain restricted Fleet access'
                            }
                            tone={meter.tone}
                        >
                            <PageHeaderMeterBig>
                                {meter.value}
                            </PageHeaderMeterBig>
                            <PageHeaderMeterCaption>
                                {meter.caption}
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                    ))}
                </>
            }
            filters={
                <>
                    <PageHeaderFilterSelect
                        label="Period"
                        value={period}
                        allValue="30d"
                        options={[
                            { value: 'week', label: 'This week' },
                            { value: '30d', label: '30 days' },
                            { value: 'quarter', label: '90 days' },
                            { value: 'custom', label: 'Custom range' },
                        ]}
                        onChange={(value) => {
                            if (value === 'custom') changeDatesOpen(true);
                            else go(presetRange(value));
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
                            aria-label="Safety overview period picker"
                            collisionPadding={16}
                            align="start"
                        >
                            <div
                                role="group"
                                aria-label="Safety overview period"
                                className="space-y-3"
                            >
                                <h3 className="text-section-title">
                                    Safety overview period
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
                                            start={customFrom || null}
                                            end={customTo || null}
                                            required={false}
                                            onChange={(start, end) => {
                                                setCustomFrom(start ?? '');
                                                setCustomTo(end ?? '');
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
                                    {validRange
                                        ? `${formatDateOnly(customFrom)} – ${formatDateOnly(customTo)} · inclusive`
                                        : customFrom
                                          ? `Starts ${formatDateOnly(customFrom)} · choose the end day`
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
                                    disabled={!validRange}
                                    onClick={() => {
                                        go({ from: customFrom, to: customTo });
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
                            filters.site === null ? 'all' : String(filters.site)
                        }
                        options={[
                            { value: 'all', label: 'All approved sites' },
                            ...sites.map((site) => ({
                                value: String(site.id),
                                label: site.name,
                            })),
                        ]}
                        onChange={(value) =>
                            go({ site: value === 'all' ? null : Number(value) })
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
                        onChange={(lens) => go({ lens })}
                    />
                    {sourceCompleteness === 'unknown' && (
                        <p
                            role="status"
                            className="text-subtle text-band-foreground"
                        >
                            These measures cover records you can access. The
                            full picture may be incomplete.
                        </p>
                    )}
                    {fleetAccessNotice &&
                        (fleetUnresolved === null ||
                            fleetIncidents30d === null) && (
                            <p
                                role="status"
                                className="text-subtle text-band-foreground"
                            >
                                Fleet access is restricted for the selected
                                sites. Health & Safety access does not grant
                                Fleet record access.
                            </p>
                        )}
                </>
            }
            mobileSummary="Safety overview · filters and measures"
            rail={rail}
        />
    );
}

/** Recorded counts and capped previews describe attention, never overall safety clearance. */
export function CommandCentreAttention({
    expiring,
    ppeState,
    worksafePending,
    activeAlerts,
}: AttentionProps) {
    const ppeDue =
        ppeState?.source_integrity === 'restricted'
            ? 0
            : expiring.filter(
                  (item) =>
                      item.type === 'ppe_inspection' ||
                      item.type === 'ppe_expiry',
              ).length;
    const ppeLabel =
        ppeState?.source_integrity === 'restricted'
            ? 'PPE access restricted'
            : ppeState?.source_integrity === 'repair_required'
              ? 'PPE facts need review'
              : !ppeState
                ? 'PPE attention unconfirmed'
                : ppeDue > 0
                  ? String(ppeDue) +
                    ' PPE due in preview' +
                    (ppeState.truncated ? ' · more records' : '')
                  : ppeState.truncated
                    ? 'PPE preview incomplete'
                    : 'No matching PPE due in preview';
    const notices = [
        {
            label:
                worksafePending === null
                    ? 'WorkSafe review count unconfirmed'
                    : String(worksafePending) + ' pending WorkSafe reviews',
            href: '/health-safety/events',
            tone: (worksafePending ?? 0) > 0 ? 'critical' : 'neutral',
        },
        {
            label:
                activeAlerts === null
                    ? 'Lone-worker alert count unconfirmed'
                    : String(activeAlerts) +
                      ' recorded active lone-worker alerts',
            href: '/health-safety/lone-workers',
            tone: (activeAlerts ?? 0) > 0 ? 'critical' : 'neutral',
        },
        {
            label:
                String(expiring.filter((item) => item.type === 'sds').length) +
                ' SDS reviews in preview',
            href: '/health-safety/substances',
            tone: 'neutral',
        },
        {
            label:
                String(
                    expiring.filter((item) => item.type === 'drill').length,
                ) + ' drills in preview',
            href: '/health-safety/drills',
            tone: 'neutral',
        },
        {
            label: ppeLabel,
            href: '/health-safety/ppe',
            tone:
                ppeDue > 0 || ppeState?.source_integrity === 'repair_required'
                    ? 'warning'
                    : 'neutral',
        },
    ] as const;
    return (
        <Card>
            <CardContent className="space-y-3 p-4">
                <h2 className="text-section-title">Safety attention</h2>
                <p className="text-subtle">
                    Recorded open work and due-item previews. Open the register
                    for the full current scope.
                </p>
                <ul className="flex flex-wrap gap-3">
                    {notices.map((notice) => (
                        <li key={notice.href}>
                            <Link
                                href={notice.href}
                                className="frontline-hit inline-flex rounded-md outline-none focus-visible:ring-2 focus-visible:ring-ring"
                            >
                                <StatusBadge variant={notice.tone}>
                                    {notice.label}
                                </StatusBadge>
                            </Link>
                        </li>
                    ))}
                </ul>
            </CardContent>
        </Card>
    );
}
export default CommandCentreHero;
