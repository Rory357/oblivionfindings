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
import { DeferredPanel } from '@/components/deferred-panel';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { formatDateOnly, toDateInput, workerDayRange } from '@/lib/datetime';
import {
    BOARD_REPORTS_LABEL,
    boardReportLinks,
} from '@/lib/health-safety/board-reports';
import type { SharedData } from '@/types';
import { Link, router, usePage } from '@inertiajs/react';
import {
    CalendarDays,
    ChevronDown,
    FileText,
    Plus,
    ShieldCheck,
} from 'lucide-react';
import { useEffect, useState, type ReactNode } from 'react';
import {
    OverviewHeaderBoxes,
    OverviewHeaderBoxesLoading,
    type HeaderBoxes,
} from './overview-header-boxes';
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
        /** Why the ratio is empty: no recordable injuries yet, or not shown to this role. */
        near_miss_ratio_reason?: 'none' | 'restricted' | null;
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
    /** Exposure managers only: exposures not on the H&S register yet (a count, no details). Absent for everyone else. */
    exposureDeliveryProblems?: number | null;
};
export type CommandCentreHeroProps = Partial<AttentionProps> & {
    sourceCompleteness?: 'unknown';
    /** Given directly, or loaded in the background from the page's `leading_lagging` prop (step C). */
    leadingLagging?: HeroLeadingLagging;
    filters: HeroFilters;
    sites: Array<{ id: number; name: string }>;
    openSafeguarding?: number | null;
    fleetUnresolved?: number | null;
    fleetIncidents30d?: number | null;
    procedures?: {
        approved: number;
        review_due: number;
        coverage_gap_categories: number;
    };
    onReport?: () => void;
    orgName?: string | null;
    rail?: ReactNode;
    search?: { value: string; onChange: (value: string) => void };
    /** G2: this person's four header boxes (Stephan's approved header), loaded in the background as `header_boxes`. */
    boxSet?: HeaderBoxes['set'];
    /** G2: the chart row under the boxes. */
    charts?: ReactNode;
};
// One board-report list, linking the readable report pages (lib/health-safety/board-reports).
const GOV_REPORTS = boardReportLinks();
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
/** Only to lay out the loading labels; its values are never shown. */
const LOADING_FIGURES: HeroLeadingLagging = {
    lagging: { incidents: 0, ltifr: null, trifr: null, injury_severity_rate: null, days_since_lti: null },
    leading: { near_miss_ratio: null, actions_on_time_pct: null, training_pct: null, open_hazards: 0 },
};
/** One of the four header numbers (the drawing's labels; every number links to where it lives). */
type Headline = {
    label: string;
    value: ReactNode;
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
    boxSet,
    charts,
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
    // Someone who can see only one house sees its name, not "All sites" (drawing D1).
    const siteLabel =
        filters.site === null
            ? sites.length === 1
                ? sites[0].name
                : 'All sites'
            : (activeSite?.name ?? 'Site access unconfirmed');
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
    const headlineFor = ({ lagging, leading }: HeroLeadingLagging): Headline[] =>
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
                      // An empty tile says why (30 Oct user test).
                      caption:
                          leading.near_miss_ratio !== null
                              ? 'This year'
                              : leading.near_miss_ratio_reason === 'restricted'
                                ? 'Injury figures not shown for your role'
                                : 'No recordable injuries yet this year',
                      href: analytics,
                  },
                  {
                      label: 'Actions on time',
                      value: fmt(leading.actions_on_time_pct, '%'),
                      caption:
                          leading.actions_on_time_pct === null
                              ? 'No actions due this period'
                              : 'Due this period',
                      href: destination('/health-safety/corrective-actions', {
                          ...selectedPeriod,
                          tab: 'all',
                      }),
                  },
                  {
                      label: 'Training',
                      value: fmt(leading.training_pct, '%'),
                      caption:
                          leading.training_pct === null
                              ? 'No training records yet'
                              : 'Up to date',
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
    const numbers = (items: Headline[]) => (
        <PageHeaderNumbers>
            {items.map((n) => (
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
    );
    // While the numbers load (in the background, step C): the same four labels with a placeholder, never a "0".
    const loadingNumbers = () =>
        numbers(
            headlineFor(LOADING_FIGURES).map((n) => ({
                ...n,
                value: (
                    <Skeleton className="inline-block h-[0.85em] w-12 bg-current opacity-20 align-middle" />
                ),
                caption: 'Loading',
                tone: undefined,
            })),
        );
    const validRange =
        !!formatDateOnly(customFrom, '') &&
        !!formatDateOnly(customTo, '') &&
        customFrom <= customTo;
    return (
        <PageHeader
            frontline={filters.lens === 'frontline'}
            wrapTitle
            icon={ShieldCheck}
            title="Health and safety"
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
                            placeholder="Search this overview…"
                            ariaLabel="Search this overview"
                        />
                    )}
                    {canViewBoardReports && (
                        <Popover>
                            <PopoverTrigger asChild>
                                <PageHeaderGlassButton icon={FileText}>
                                    {BOARD_REPORTS_LABEL}
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
                            Report something
                        </PageHeaderPrimaryButton>
                    )}
                </>
            }
            strip={
                // The frontline start keeps the header to the title and Report something (drawing D1).
                filters.lens === 'frontline' ? undefined : boxSet ? (
                    <div className="grid w-full gap-3">
                        <DeferredPanel<{ header_boxes: HeaderBoxes }>
                            data="header_boxes"
                            label="Header numbers"
                            fallback={<OverviewHeaderBoxesLoading set={boxSet} />}
                        >
                            {({ header_boxes }) => <OverviewHeaderBoxes data={header_boxes} />}
                        </DeferredPanel>
                        {charts}
                    </div>
                ) : leadingLagging ? (
                    numbers(headlineFor(leadingLagging))
                ) : (
                    <DeferredPanel<{ leading_lagging: HeroLeadingLagging }>
                        data="leading_lagging"
                        label="Headline numbers"
                        fallback={loadingNumbers()}
                    >
                        {({ leading_lagging }) => numbers(headlineFor(leading_lagging))}
                    </DeferredPanel>
                )
            }
            filters={
                // The frontline start keeps the header to the title and Report something (drawing D1).
                filters.lens === 'frontline' ? undefined : (
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
                        <Popover
                            open={datesOpen}
                            onOpenChange={changeDatesOpen}
                        >
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
                                        Choose a start and end day. Select the
                                        same day twice for one day. Both dates
                                        are included.
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
                                        Scroll sideways to see more days if
                                        needed.
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
                                            go({
                                                from: customFrom,
                                                to: customTo,
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
                                filters.site === null
                                    ? 'all'
                                    : String(filters.site)
                            }
                            options={[
                                { value: 'all', label: 'All approved sites' },
                                ...sites.map((site) => ({
                                    value: String(site.id),
                                    label: site.name,
                                })),
                            ]}
                            onChange={(value) =>
                                go({
                                    site:
                                        value === 'all' ? null : Number(value),
                                })
                            }
                        />
                    </>
                )
            }
            mobileSummary="Safety overview · filters and measures"
            rail={rail}
        />
    );
}

/**
 * Recorded counts and capped previews describe attention, never overall safety clearance.
 * Drawn as one tally (approved H&S drawings, 23 Oct): each number opens its register (keeping the
 * selected Site); zeros fold into one sentence about the records you can see; an unconfirmed count
 * shows a dash with the reason, never a zero.
 */
export function CommandCentreAttention({
    expiring,
    ppeState,
    worksafePending,
    activeAlerts,
    exposureDeliveryProblems = null,
    site = null,
}: AttentionProps & { site?: number | null }) {
    const ppeRestricted = ppeState?.source_integrity === 'restricted';
    // No PPE state from the source = not confirmed: a dash, never a zero.
    const ppeDue =
        ppeRestricted || !ppeState
            ? null
            : expiring.filter(
                  (item) =>
                      item.type === 'ppe_inspection' ||
                      item.type === 'ppe_expiry',
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
    const register = (path: string, params: Record<string, string> = {}) => {
        const query = new URLSearchParams(params);
        if (site !== null) query.set('site_id', String(site));
        return path + (query.size ? '?' + query.toString() : '');
    };
    const items: TallyItem[] = [
        {
            value: worksafePending === null ? '—' : String(worksafePending),
            label: 'WorkSafe reviews to do',
            sub: worksafePending === null ? 'Not confirmed' : undefined,
            tone: (worksafePending ?? 0) > 0 ? 'critical' : undefined,
            href: register('/health-safety/events', {
                tab: 'worksafe_pending',
            }),
        },
        {
            value: activeAlerts === null ? '—' : String(activeAlerts),
            label: 'Lone-worker alerts active',
            sub: activeAlerts === null ? 'Not confirmed' : undefined,
            tone: (activeAlerts ?? 0) > 0 ? 'critical' : undefined,
            href: register('/health-safety/lone-workers'),
        },
        ...(exposureDeliveryProblems === null
            ? []
            : [
                  {
                      value: String(exposureDeliveryProblems),
                      label: 'Exposures not on the H&S register yet',
                      tone:
                          exposureDeliveryProblems > 0
                              ? ('critical' as const)
                              : undefined,
                      href: '/tasks?sources=chemical_exposure_delivery',
                  },
              ]),
        {
            value: String(count('sds')),
            label: 'Safety data sheets to review',
            href: register('/health-safety/substances'),
        },
        {
            value: String(count('drill')),
            label: 'Drills due',
            href: register('/health-safety/drills'),
        },
        {
            value: ppeDue === null ? '—' : String(ppeDue),
            label: 'Protective equipment (PPE) due',
            sub: ppeNote,
            // A caveat on a zero (facts need review, more records than shown) must stay visible.
            keep: ppeNote !== undefined,
            tone:
                (ppeDue ?? 0) > 0 ||
                ppeState?.source_integrity === 'repair_required'
                    ? 'warning'
                    : undefined,
            href: register('/health-safety/ppe'),
        },
    ];
    // A zero here only covers the records this person can see, so the folded sentence says so.
    const zeros = items.filter((item) => item.value === '0' && !item.keep);
    return (
        <Tally
            title="Needs attention"
            sub="Open and due work from records you can see — it may not be everything. Open a register for the full list."
            items={items}
            calm={
                zeros.length
                    ? `None in the records you can see: ${zeros.map((item) => item.label).join(', ')}`
                    : undefined
            }
        />
    );
}
export default CommandCentreHero;
