/** H&S overview uses the shared PageHeader; attention is separate, source-aware content. */
import { LeaveCalendarRange } from '@/components/hr/leave-calendar-range';
import {
    PageHeader,
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
    PageHeaderPrimaryButton,
    PageHeaderSearch,
} from '@/components/page/page-header';
import {
    PageHeaderNumber,
    PageHeaderNumbers,
    type PageHeaderStripTone,
} from '@/components/page/page-header-strip';
import { Tally, type TallyItem } from '@/components/signature/blocks';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
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
/** One of the four header numbers (the drawing's labels; every number links to where it lives). */
type Headline = {
    label: string;
    value: string;
    caption?: string;
    href: string;
    tone?: PageHeaderStripTone;
};

export function CommandCentreHero({
    leadingLagging,
    filters,
    sites,
    onReport,
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
            ? 'All sites'
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
    // Four header numbers per lens (approved H&S drawings, 23 Oct). The fifth and sixth
    // measures (procedures and fleet, or safeguarding and fleet) live in the page body.
    const headline: Headline[] =
        filters.lens === 'governance'
            ? [
                  {
                      label: 'Incidents',
                      value: fmt(lagging.incidents),
                      caption: 'This period',
                      href: destination('/incidents', {
                          ...selectedPeriod,
                          kind: 'incidents',
                          tab: 'all',
                      }),
                  },
                  {
                      label: 'Lost-time injury rate',
                      value: fmt(lagging.ltifr),
                      caption: 'LTIFR',
                      href: analytics,
                  },
                  {
                      label: 'Recordable injury rate',
                      value: fmt(lagging.trifr),
                      caption: 'TRIFR',
                      href: analytics,
                  },
                  {
                      label: 'Days since a lost-time injury',
                      value: fmt(lagging.days_since_lti),
                      caption:
                          lagging.days_since_lti === null
                              ? 'Not confirmed'
                              : undefined,
                      href: analytics,
                  },
              ]
            : [
                  {
                      label: 'Near misses per injury',
                      value: fmt(leading.near_miss_ratio, '×'),
                      caption: 'This year',
                      href: analytics,
                  },
                  {
                      label: 'Actions on time',
                      value: fmt(leading.actions_on_time_pct, '%'),
                      caption: 'Due this period',
                      href: destination('/health-safety/corrective-actions', {
                          ...selectedPeriod,
                          tab: 'all',
                      }),
                  },
                  {
                      label: 'Training',
                      value: fmt(leading.training_pct, '%'),
                      caption: 'Up to date',
                      href: analytics,
                  },
                  {
                      label: 'Open hazards',
                      value: fmt(leading.open_hazards),
                      caption: 'Open or in progress',
                      href: destination('/compliance/hazards', {
                          tab: 'active',
                      }),
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
            title="Health & safety"
            subline={[
                siteLabel,
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
            strip={
                <PageHeaderNumbers>
                    {headline.map((n) => (
                        <PageHeaderNumber
                            key={n.label}
                            value={n.value}
                            label={n.label}
                            caption={n.caption}
                            tone={n.tone}
                            href={n.href}
                        />
                    ))}
                </PageHeaderNumbers>
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
                </>
            }
            mobileSummary="Safety overview · filters and measures"
            rail={rail}
        />
    );
}

/**
 * Recorded counts and capped previews describe attention, never overall safety clearance.
 * Drawn as one tally (approved H&S drawings, 23 Oct): each number opens its register; zeros fold
 * into one sentence; an unconfirmed count shows a dash with the reason, never a zero.
 */
export function CommandCentreAttention({
    expiring,
    ppeState,
    worksafePending,
    activeAlerts,
}: AttentionProps) {
    const ppeRestricted = ppeState?.source_integrity === 'restricted';
    const ppeDue = ppeRestricted
        ? null
        : expiring.filter(
              (item) =>
                  item.type === 'ppe_inspection' || item.type === 'ppe_expiry',
          ).length;
    const ppeNote = ppeRestricted
        ? 'Access restricted'
        : ppeState?.source_integrity === 'repair_required'
          ? 'Facts need review'
          : !ppeState
            ? 'Not confirmed'
            : ppeState.truncated
              ? 'More records than shown'
              : undefined;
    const count = (type: string) =>
        expiring.filter((item) => item.type === type).length;
    const items: TallyItem[] = [
        {
            value: worksafePending === null ? '—' : String(worksafePending),
            label: 'WorkSafe reviews to do',
            sub: worksafePending === null ? 'Not confirmed' : undefined,
            tone: (worksafePending ?? 0) > 0 ? 'critical' : undefined,
            href: '/health-safety/events',
        },
        {
            value: activeAlerts === null ? '—' : String(activeAlerts),
            label: 'Lone-worker alerts active',
            sub: activeAlerts === null ? 'Not confirmed' : undefined,
            tone: (activeAlerts ?? 0) > 0 ? 'critical' : undefined,
            href: '/health-safety/lone-workers',
        },
        {
            value: String(count('sds')),
            label: 'Safety data sheets to review',
            href: '/health-safety/substances',
        },
        {
            value: String(count('drill')),
            label: 'Drills due',
            href: '/health-safety/drills',
        },
        {
            value: ppeDue === null ? '—' : String(ppeDue),
            label: 'Protective equipment (PPE) due',
            sub: ppeNote,
            tone:
                (ppeDue ?? 0) > 0 ||
                ppeState?.source_integrity === 'repair_required'
                    ? 'warning'
                    : undefined,
            href: '/health-safety/ppe',
        },
    ];
    return (
        <Tally
            title="Needs attention"
            sub="Open and due work from records you can see — it may not be everything. Open a register for the full list."
            items={items}
        />
    );
}
export default CommandCentreHero;
