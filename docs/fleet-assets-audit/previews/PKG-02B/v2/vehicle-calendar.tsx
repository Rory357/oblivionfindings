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
    CalendarDays,
    ChevronLeft,
    ChevronRight,
    Clock,
    Columns3,
    LayoutGrid,
    List,
    Lock,
    Rows3,
    ShieldAlert,
} from 'lucide-react';
import { useMemo, useState } from 'react';

type View = 'month' | 'week' | 'day' | 'agenda' | 'timeline';
const referenceDay = new Date(2026, 8, 21, 9, 30);
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
        label: 'Bookings · busy only',
        short: 'Busy',
        group: 'auto',
        icon: 'Lock',
        origin: 'Vehicle bookings',
    },
    {
        key: 'compliance',
        label: 'Compliance reminders',
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
    hold,
    empty,
    ready,
    onBack,
    onOpen,
}: {
    hold: boolean;
    empty: boolean;
    ready: boolean;
    onBack: () => void;
    onOpen: (id: string) => void;
}) {
    const [view, setView] = useState<View>('month');
    const [navDate, setNavDate] = useState(referenceDay);
    const [query, setQuery] = useState('');
    const [density, setDensity] = useState<Density>('comfortable');
    const [enabled, setEnabled] = useState(sources.map((s) => s.key));
    const [jumpOpen, setJumpOpen] = useState(false);
    const events = useMemo(
        () =>
            (empty
                ? []
                : fixtures.filter(
                      (e) =>
                          (e.id !== 'restriction' || hold) &&
                          (!ready || e.id !== 'estimate'),
                  )
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
        [empty, hold, ready],
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
    const onSelect = (entry: Decorated) => onOpen(entry.id);
    const context = {
        colorBy: 'source' as const,
        density,
        srcByKey: Object.fromEntries(sources.map((s) => [s.key, s])),
        onSelect,
        onMore: (date: Date) => {
            setNavDate(date);
            setView('day');
        },
    };
    return (
        <div
            className="vehicle-calendar"
            onPointerDownCapture={(event) => {
                // Read-only timed entries open on pointer-down in the shared calendar.
                // Focus the entry first so closing its dialog restores the origin.
                const entry = (
                    event.target as HTMLElement
                ).closest<HTMLElement>('[role="button"][tabindex="0"]');
                entry?.focus({ preventScroll: true });
            }}
        >
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
                                {hold ? 'Restricted' : 'Review readiness'}
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
                                setNavDate(new Date(2026, 8, 24));
                                setView('day');
                            }}
                        >
                            <PageHeaderMeterBig>
                                {empty ? 'None' : '24 Sep'}
                            </PageHeaderMeterBig>
                            <PageHeaderMeterCaption>
                                {empty
                                    ? 'No source record'
                                    : '9–11 am · internal plan'}
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                        <PageHeaderMeterBlock
                            label="Due reminder"
                            ariaLabel="View registration reminder"
                            onClick={() => {
                                setNavDate(new Date(2026, 9, 8));
                                setView('day');
                            }}
                        >
                            <PageHeaderMeterBig>
                                {empty ? 'None' : '8 Oct'}
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
                        events={visible}
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
                    Source records are read-only here. Appointments, advisory
                    estimates, reminders and restrictions retain their own
                    meaning. Booking creation remains outside this profile
                    mockup.
                </span>
            </div>
        </div>
    );
}
