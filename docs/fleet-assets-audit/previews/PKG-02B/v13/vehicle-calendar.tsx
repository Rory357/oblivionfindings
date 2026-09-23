import {
    PageHeader,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderRail,
    PageHeaderSearch,
    PageHeaderStatusChip,
} from '@/components/page/page-header';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import type { CalendarItem } from '@/lib/calendar/recur';
import {
    AgendaView,
    CalendarUIProvider,
    DayView,
    MO,
    MiniMonth,
    MonthView,
    TimelineView,
    TodayRail,
    WeekView,
    addDays,
    decorate,
    sameDay,
    startOfWeek,
    type Decorated,
    type Density,
    type SourceDef,
} from '@/pages/sites/calendar/_parts';
import {
    ArrowLeft,
    ArrowUpRight,
    CalendarDays,
    ChevronLeft,
    ChevronRight,
    Clock,
    Columns3,
    FileText,
    LayoutGrid,
    List,
    Lock,
    Route,
    Rows3,
    ShieldAlert,
} from 'lucide-react';
import { useMemo, useRef, useState } from 'react';
import type { CalendarAction } from './calendar-actions';
import { defaultBookingStart } from './readiness-contract';

type View = 'month' | 'week' | 'day' | 'agenda' | 'timeline';
const referenceDay = new Date(2026, 8, 22, 9, 30);
const sources: SourceDef[] = [
    {
        key: 'event',
        label: 'Service appointments',
        short: 'Appointment',
        group: 'auto',
        icon: 'Wrench',
        origin: 'Maintenance',
    },
    {
        key: 'asset',
        label: 'Estimated work',
        short: 'Estimate',
        group: 'auto',
        icon: 'Wrench',
        origin: 'Maintenance',
    },
    {
        key: 'respite',
        label: 'Bookings & unavailable',
        short: 'Booking',
        group: 'auto',
        icon: 'Lock',
        origin: 'Vehicle bookings',
    },
    {
        key: 'compliance',
        label: 'Reminders',
        short: 'Reminder',
        group: 'auto',
        icon: 'ShieldCheck',
        origin: 'Compliance',
    },
    {
        key: 'damage',
        label: 'Restriction records',
        short: 'Restriction',
        group: 'auto',
        icon: 'AlertTriangle',
        origin: 'Maintenance',
    },
];
const base: Omit<
    CalendarItem,
    'id' | 'title' | 'source' | 'start' | 'end' | 'allDay' | 'ref'
> = {
    group: 'auto',
    status: 'scheduled',
    owner: null,
    room: null,
    site: null,
    link: null,
    editable: false,
};
const fixtures: CalendarItem[] = [
    {
        ...base,
        id: 'restriction',
        source: 'damage',
        title: 'Restriction started · still active',
        ref: 'RST-DEMO-12',
        start: '2026-09-21T00:00:00',
        end: null,
        allDay: true,
    },
    {
        ...base,
        id: 'service',
        source: 'event',
        title: 'Routine service · internal appointment',
        ref: 'APT-DEMO-28',
        start: '2026-09-24T09:00:00',
        end: '2026-09-24T11:00:00',
        allDay: false,
    },
    {
        ...base,
        id: 'estimate',
        source: 'asset',
        title: 'Estimated maintenance · advisory',
        ref: 'WO-0264',
        start: '2026-09-24T00:00:00',
        end: '2026-09-26T00:00:00',
        allDay: true,
    },
    {
        ...base,
        id: 'booking',
        source: 'respite',
        title: 'Busy',
        ref: null,
        start: '2026-09-25T10:00:00',
        end: '2026-09-25T12:00:00',
        allDay: false,
    },
    {
        ...base,
        id: 'compliance',
        source: 'compliance',
        title: 'Registration due · reminder',
        ref: 'REG-DEMO-14',
        start: '2026-10-08T00:00:00',
        end: null,
        allDay: true,
    },
];
const viewItems = [
    { key: 'month', label: 'Month', icon: LayoutGrid },
    { key: 'week', label: 'Week', icon: Columns3 },
    { key: 'day', label: 'Day', icon: Clock },
    { key: 'agenda', label: 'Agenda', icon: List },
    { key: 'timeline', label: 'Timeline', icon: Rows3 },
];
const fullDate = (date: Date) =>
    date.toLocaleDateString('en-NZ', {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        year: 'numeric',
    });

export function VehicleCalendar({
    focusDate,
    hold,
    empty,
    ready,
    readiness,
    items,
    onCreate,
    onBack,
    onOpen,
    actionsFor,
    createActions,
    onTrips,
}: {
    focusDate?: string;
    hold: boolean;
    empty: boolean;
    ready: boolean;
    readiness: string;
    items?: CalendarItem[];
    onCreate?: (start: string) => void;
    onBack: () => void;
    onOpen: (id: string) => void;
    actionsFor?: (id: string) => CalendarAction[];
    createActions?: (start: string) => CalendarAction[];
    onTrips?: (date: string) => void;
}) {
    const rightClick = useRef<{ x: number; y: number } | null>(null);
    const [eventMenu, setEventMenu] = useState<{
        x: number;
        y: number;
        entry: Decorated;
    } | null>(null);
    const [view, setView] = useState<View>('month');
    const [navDate, setNavDate] = useState(
        focusDate ? new Date(focusDate + 'T12:00') : referenceDay,
    );
    const [query, setQuery] = useState('');
    const [density, setDensity] = useState<Density>('comfortable');
    const [enabled, setEnabled] = useState(sources.map((s) => s.key));
    const [jumpOpen, setJumpOpen] = useState(false);
    const [creation, setCreation] = useState<{
        x: number;
        y: number;
        date: Date;
        hour?: number;
    } | null>(null);
    const events = useMemo(
        () =>
            (
                items ??
                (empty
                    ? []
                    : fixtures.filter(
                          (e) =>
                              (e.id !== 'restriction' || hold) &&
                              (!ready || e.id !== 'estimate'),
                      ))
            ).map((entry) => ({
                ...decorate(entry),
                typeLabel: sources.find((source) => source.key === entry.source)
                    ?.short,
                statusLabel: {
                    restriction: 'Active restriction',
                    service: 'Internal plan',
                    estimate: 'Advisory dates',
                    booking: 'Busy only',
                    compliance: 'Due reminder',
                }[entry.id],
            })),
        [empty, hold, ready, items],
    );
    const visible = events.filter(
        (e) =>
            enabled.includes(e.source) &&
            `${e.title} ${e.ref ?? ''}`
                .toLowerCase()
                .includes(query.toLowerCase()),
    );
    const weekStart = startOfWeek(navDate),
        weekEnd = addDays(weekStart, 6);
    const period =
        view === 'week'
            ? `${fullDate(weekStart)} – ${fullDate(weekEnd)}`
            : view === 'day'
              ? fullDate(navDate)
              : `${MO[navDate.getMonth()]} ${navDate.getFullYear()}`;
    const periodStart =
        view === 'week'
            ? weekStart
            : view === 'day'
              ? new Date(
                    navDate.getFullYear(),
                    navDate.getMonth(),
                    navDate.getDate(),
                )
              : new Date(navDate.getFullYear(), navDate.getMonth(), 1);
    const periodEnd =
        view === 'week'
            ? addDays(weekStart, 7)
            : view === 'day'
              ? addDays(periodStart, 1)
              : new Date(navDate.getFullYear(), navDate.getMonth() + 1, 1);
    const periodCount = visible.filter(
        (e) =>
            e._start < periodEnd &&
            (e._end ? e._end > periodStart : e._start >= periodStart),
    ).length;
    const shift = (n: number) =>
        setNavDate((date) =>
            view === 'week'
                ? addDays(date, n * 7)
                : view === 'day'
                  ? addDays(date, n)
                  : new Date(
                        date.getFullYear(),
                        date.getMonth() + n,
                        Math.min(
                            date.getDate(),
                            new Date(
                                date.getFullYear(),
                                date.getMonth() + n + 1,
                                0,
                            ).getDate(),
                        ),
                    ),
        );
    const onSelect = (entry: Decorated) => {
        if (rightClick.current) {
            setEventMenu({ ...rightClick.current, entry });
            rightClick.current = null;
        } else onOpen(entry.id);
    };
    const dateKey = (date: Date) =>
        [
            date.getFullYear(),
            String(date.getMonth() + 1).padStart(2, '0'),
            String(date.getDate()).padStart(2, '0'),
        ].join('-');
    const creationStart = creation
        ? dateKey(creation.date) +
          'T' +
          String(
              Math.floor(
                  creation.hour ??
                      (sameDay(creation.date, referenceDay) ? 10 : 9),
              ),
          ).padStart(2, '0') +
          ':' +
          String(Math.round(((creation.hour ?? 0) % 1) * 60)).padStart(2, '0')
        : '';
    const creationActions = creation
        ? (createActions?.(creationStart) ?? [])
        : [];
    const entryActions = eventMenu
        ? (actionsFor?.(eventMenu.entry.id) ?? [])
        : [];
    const context = {
        colorBy: 'source' as const,
        density,
        srcByKey: Object.fromEntries(sources.map((s) => [s.key, s])),
        onSelect,
        onContext: (e: React.MouseEvent, date: Date, hour?: number) => {
            e.preventDefault();
            rightClick.current = null;
            setCreation({ x: e.clientX, y: e.clientY, date, hour });
        },
        onCreateAt: onCreate
            ? (date: Date, hour = 9) =>
                  onCreate(
                      [
                          date.getFullYear(),
                          String(date.getMonth() + 1).padStart(2, '0'),
                          String(date.getDate()).padStart(2, '0'),
                      ].join('-') +
                          'T' +
                          String(Math.floor(hour)).padStart(2, '0') +
                          ':' +
                          String(Math.round((hour % 1) * 60)).padStart(2, '0'),
                  )
            : undefined,
        onMore: (date: Date) => {
            setNavDate(date);
            setView('day');
        },
    };
    return (
        <div
            className="vehicle-calendar"
            onContextMenuCapture={(e) => {
                rightClick.current = { x: e.clientX, y: e.clientY };
            }}
            onKeyDownCapture={() => {
                rightClick.current = null;
            }}
            onPointerDownCapture={(event) => {
                rightClick.current =
                    event.button === 2
                        ? { x: event.clientX, y: event.clientY }
                        : null;
                // Read-only timed entries open on pointer-down in the shared calendar.
                // Focus the entry first so closing its dialog restores the origin.
                const entry = (
                    event.target as HTMLElement
                ).closest<HTMLElement>('[role="button"][tabindex="0"]');
                entry?.focus({ preventScroll: true });
            }}
        >
            {eventMenu && (
                <DropdownMenu
                    open
                    onOpenChange={(open) => {
                        if (!open) {
                            setEventMenu(null);
                            rightClick.current = null;
                        }
                    }}
                >
                    <DropdownMenuTrigger
                        style={{
                            position: 'fixed',
                            left: eventMenu.x,
                            top: eventMenu.y,
                            width: 1,
                            height: 1,
                        }}
                        aria-label="Calendar entry actions"
                    />
                    <DropdownMenuContent align="start">
                        <DropdownMenuLabel>
                            {eventMenu.entry.title}
                        </DropdownMenuLabel>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                            onSelect={() => {
                                onOpen(eventMenu.entry.id);
                                setEventMenu(null);
                            }}
                        >
                            <FileText size={15} />
                            {eventMenu.entry.id === 'restriction'
                                ? 'View restriction reason'
                                : ['event', 'asset'].includes(
                                        eventMenu.entry.source,
                                    )
                                  ? 'Open work order'
                                  : 'Open source record'}
                        </DropdownMenuItem>
                        {entryActions
                            .filter((a) => !a.destructive)
                            .map((action) => (
                                <DropdownMenuItem
                                    key={action.label}
                                    disabled={action.disabled}
                                    onSelect={() => {
                                        action.run();
                                        setEventMenu(null);
                                    }}
                                >
                                    <action.icon size={15} />
                                    <span>
                                        {action.label}
                                        {action.reason && (
                                            <small className="block text-xs text-muted-foreground">
                                                {action.reason}
                                            </small>
                                        )}
                                    </span>
                                </DropdownMenuItem>
                            ))}
                        {entryActions.some((a) => a.destructive) && (
                            <DropdownMenuSeparator />
                        )}
                        {entryActions
                            .filter((a) => a.destructive)
                            .map((action) => (
                                <DropdownMenuItem
                                    key={action.label}
                                    disabled={action.disabled}
                                    onSelect={() => {
                                        setEventMenu(null);
                                        action.run();
                                    }}
                                >
                                    <action.icon size={15} />
                                    {action.label}
                                </DropdownMenuItem>
                            ))}
                    </DropdownMenuContent>
                </DropdownMenu>
            )}
            {creation && (
                <DropdownMenu
                    open
                    onOpenChange={(open) => !open && setCreation(null)}
                >
                    <DropdownMenuTrigger
                        style={{
                            position: 'fixed',
                            left: creation.x,
                            top: creation.y,
                            width: 1,
                            height: 1,
                        }}
                        aria-label="Calendar creation menu"
                    />
                    <DropdownMenuContent align="start">
                        <DropdownMenuLabel>
                            {fullDate(creation.date)} · Kōwhai van
                            <span className="block text-xs font-normal text-muted-foreground">
                                {creationStart.slice(11)} · Pacific/Auckland
                            </span>
                        </DropdownMenuLabel>
                        {creationActions.length > 0 && (
                            <>
                                <DropdownMenuSeparator />
                                {creationStart < '2026-09-22T09:30' && (
                                    <DropdownMenuLabel className="text-xs font-normal text-muted-foreground">
                                        Past time · choose a future slot to
                                        schedule
                                    </DropdownMenuLabel>
                                )}
                                {creationActions.map((action) => (
                                    <DropdownMenuItem
                                        key={action.label}
                                        disabled={action.disabled}
                                        onSelect={() => {
                                            setCreation(null);
                                            action.run();
                                        }}
                                    >
                                        <action.icon size={15} />
                                        {action.label}
                                    </DropdownMenuItem>
                                ))}
                            </>
                        )}
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                            onSelect={() => {
                                setNavDate(creation.date);
                                setView('day');
                                setCreation(null);
                            }}
                        >
                            <CalendarDays size={15} />
                            View this day / availability
                            <ArrowUpRight size={13} />
                        </DropdownMenuItem>
                        {onTrips &&
                            dateKey(creation.date) <= dateKey(referenceDay) && (
                                <DropdownMenuItem
                                    onSelect={() => {
                                        onTrips(dateKey(creation.date));
                                        setCreation(null);
                                    }}
                                >
                                    <Route size={15} />
                                    View trips on this date
                                </DropdownMenuItem>
                            )}
                    </DropdownMenuContent>
                </DropdownMenu>
            )}
            <PageHeader
                variant="profile"
                wrapTitle
                mark={
                    <div className="identity-mark">
                        <button
                            className="hero-back"
                            aria-label="Back to vehicle"
                            onClick={onBack}
                        >
                            <ArrowLeft size={16} />
                        </button>
                        <div className="eh-mark-ring">
                            <CalendarDays size={25} />
                        </div>
                    </div>
                }
                title="Vehicle calendar"
                titleChip={
                    <PageHeaderStatusChip variant="info">
                        Kōwhai van
                    </PageHeaderStatusChip>
                }
                subline="KWH014 · VH-014 · Pacific/Auckland"
                actions={
                    <>
                        {onCreate && (
                            <PageHeaderGlassButton
                                onClick={() =>
                                    onCreate(
                                        defaultBookingStart(dateKey(navDate)),
                                    )
                                }
                            >
                                Request booking
                            </PageHeaderGlassButton>
                        )}
                        <PageHeaderSearch
                            value={query}
                            onChange={setQuery}
                            placeholder="Find an entry or reference…"
                        />
                        <PageHeaderGlassButton onClick={onBack}>
                            <ArrowLeft size={16} />
                            Vehicle profile
                        </PageHeaderGlassButton>
                    </>
                }
                meters={
                    <div className="meter-grid">
                        <PageHeaderMeterBlock
                            label="Viewing"
                            value={`${periodCount} ${periodCount === 1 ? 'entry' : 'entries'}`}
                            ariaLabel="View this period in the agenda"
                            onClick={() => setView('agenda')}
                        >
                            <div
                                className="calendar-date-anchor"
                                data-testid="calendar-date-anchor"
                                aria-live="polite"
                                aria-atomic="true"
                            >
                                <PageHeaderMeterBig>
                                    {navDate.getDate()}
                                </PageHeaderMeterBig>
                                <div>
                                    <strong>{MO[navDate.getMonth()]}</strong>
                                    <span>{navDate.getFullYear()}</span>
                                </div>
                            </div>
                            <PageHeaderMeterCaption>
                                {view === 'week'
                                    ? `${fullDate(weekStart)} – ${fullDate(weekEnd)}`
                                    : fullDate(navDate)}
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                        <PageHeaderMeterBlock
                            label="Vehicle use"
                            ariaLabel="Review vehicle readiness"
                            onClick={onBack}
                            tone={hold ? 'critical' : 'brand'}
                        >
                            <PageHeaderMeterBig>
                                {hold ? 'Restricted' : readiness}
                            </PageHeaderMeterBig>
                            <PageHeaderMeterCaption>
                                {hold
                                    ? 'Active · no release recorded'
                                    : 'Booking and checkout still need assessment'}
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                        <PageHeaderMeterBlock
                            label="Next appointment"
                            ariaLabel="View service appointment"
                            onClick={() => {
                                const next = events.find(
                                    (e) => e.source === 'event',
                                );
                                if (next?.start)
                                    setNavDate(new Date(next.start));
                                setView('day');
                            }}
                        >
                            <PageHeaderMeterBig>
                                {events.find((e) => e.source === 'event')
                                    ? new Date(
                                          events.find(
                                              (e) => e.source === 'event',
                                          )!.start!,
                                      ).toLocaleDateString('en-NZ', {
                                          day: 'numeric',
                                          month: 'short',
                                      })
                                    : 'None'}
                            </PageHeaderMeterBig>
                            <PageHeaderMeterCaption>
                                {events.find((e) => e.source === 'event')
                                    ?.title ?? 'No source record'}
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                        <PageHeaderMeterBlock
                            label="Due reminder"
                            ariaLabel="View registration reminder"
                            onClick={() => {
                                const due = events.find(
                                    (e) => e.ref === 'REG-DEMO-14',
                                );
                                if (due?.start) setNavDate(new Date(due.start));
                                setView('day');
                            }}
                        >
                            <PageHeaderMeterBig>
                                {events.find((e) => e.ref === 'REG-DEMO-14')
                                    ?.start
                                    ? new Date(
                                          events.find(
                                              (e) => e.ref === 'REG-DEMO-14',
                                          )!.start!,
                                      ).toLocaleDateString('en-NZ', {
                                          day: 'numeric',
                                          month: 'short',
                                      })
                                    : 'None'}
                            </PageHeaderMeterBig>
                            <PageHeaderMeterCaption>
                                {empty
                                    ? 'No source record'
                                    : 'Registration · does not reserve a day'}
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                    </div>
                }
                filters={
                    <div className="calendar-controls">
                        <div className="calendar-period">
                            <PageHeaderGlassButton
                                aria-label="Previous period"
                                onClick={() => shift(-1)}
                            >
                                <ChevronLeft size={17} />
                            </PageHeaderGlassButton>
                            <PageHeaderGlassButton
                                onClick={() => setNavDate(referenceDay)}
                            >
                                Today
                            </PageHeaderGlassButton>
                            <PageHeaderGlassButton
                                aria-label="Next period"
                                onClick={() => shift(1)}
                            >
                                <ChevronRight size={17} />
                            </PageHeaderGlassButton>
                            <Popover open={jumpOpen} onOpenChange={setJumpOpen}>
                                <PopoverTrigger asChild>
                                    <PageHeaderGlassButton aria-label="Jump to date">
                                        <CalendarDays size={16} />
                                        {period}
                                    </PageHeaderGlassButton>
                                </PopoverTrigger>
                                <PopoverContent
                                    className="w-auto p-2"
                                    align="start"
                                >
                                    <MiniMonth
                                        selected={navDate}
                                        onSelect={(date) => {
                                            setNavDate(date);
                                            setJumpOpen(false);
                                        }}
                                    />
                                </PopoverContent>
                            </Popover>
                        </div>
                        <label className="calendar-density">
                            Display
                            <select
                                aria-label="Calendar display density"
                                value={density}
                                onChange={(e) =>
                                    setDensity(e.target.value as Density)
                                }
                            >
                                <option value="comfortable">Comfortable</option>
                                <option value="compact">Compact</option>
                            </select>
                        </label>
                    </div>
                }
                rail={
                    <PageHeaderRail
                        items={viewItems}
                        value={view}
                        onSelect={(key) => setView(key as View)}
                        ariaLabel="Calendar views"
                    />
                }
            />
            <div className="calendar-source-bar" aria-label="Calendar sources">
                {sources.map((source) => (
                    <button
                        key={source.key}
                        aria-pressed={enabled.includes(source.key)}
                        className="calendar-source-pill"
                        onClick={() =>
                            setEnabled((list) =>
                                list.includes(source.key)
                                    ? list.filter((key) => key !== source.key)
                                    : [...list, source.key],
                            )
                        }
                    >
                        <span
                            style={{ background: `var(--src-${source.key})` }}
                        />
                        {source.label}
                        <small>
                            {
                                events.filter((e) => e.source === source.key)
                                    .length
                            }
                        </small>
                    </button>
                ))}
                <Button
                    variant="ghost"
                    size="sm"
                    onClick={() => {
                        setEnabled(sources.map((s) => s.key));
                        setQuery('');
                    }}
                >
                    Reset filters
                </Button>
            </div>
            {hold && (
                <div className="calendar-restriction">
                    <ShieldAlert size={19} />
                    <div>
                        <strong>Vehicle use remains restricted</strong>
                        <span>
                            Since 21 September, 8:20 am · No end or authorised
                            release recorded. Calendar gaps do not mean
                            available.
                        </span>
                    </div>
                    <Button
                        variant="outline"
                        onClick={() => onOpen('restriction')}
                    >
                        View restriction
                    </Button>
                </div>
            )}
            <CalendarUIProvider value={context}>
                <div className="calendar-layout">
                    <div className="calendar-view">
                        {view === 'month' && (
                            <MonthView events={visible} navDate={navDate} />
                        )}
                        {view === 'week' && (
                            <WeekView events={visible} navDate={navDate} />
                        )}
                        {view === 'day' && (
                            <DayView events={visible} navDate={navDate} />
                        )}
                        {view === 'agenda' && (
                            <AgendaView
                                events={visible}
                                navDate={navDate}
                                sourcesOff={!enabled.length}
                                filtersActive={
                                    enabled.length !== sources.length || !!query
                                }
                            />
                        )}
                        {view === 'timeline' && (
                            <TimelineView
                                events={visible}
                                navDate={navDate}
                                sources={sources}
                            />
                        )}
                    </div>
                    <TodayRail
                        events={visible.filter(
                            (e) =>
                                !e.title.includes('Returned') &&
                                !e.title.includes('Completed'),
                        )}
                        today={referenceDay}
                        onSelect={onSelect}
                        onApprovals={() => setView('agenda')}
                        onJumpToday={() => setNavDate(referenceDay)}
                        viewingToday={sameDay(referenceDay, navDate)}
                    />
                </div>
            </CalendarUIProvider>
            <div className="calendar-footnote">
                <Lock size={14} />
                <span>
                    Right-click a date/time for scheduling and reminders, or an
                    entry for its actions. Select an entry to open its source.
                    Reminders and advisory estimates do not reserve the vehicle.
                </span>
            </div>
        </div>
    );
}
