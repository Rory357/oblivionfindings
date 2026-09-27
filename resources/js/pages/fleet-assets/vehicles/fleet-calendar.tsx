import { bookingOffset } from '@/components/fleet-assets/vehicle-workspace/booking-time';
import { addLocalMinutes } from '@/components/fleet-assets/vehicle-workspace/booking-wizard';
import type {
    BookingRow,
    VehicleCalendarItem,
} from '@/components/fleet-assets/vehicle-workspace/calendar-types';
import {
    PageHeaderFilterButton,
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
    PageHeaderSearch,
} from '@/components/page/page-header';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { formatDateTime, toDatetimeLocal } from '@/lib/datetime';
import { fleetSourceHref } from '@/lib/fleet-return';
import {
    addDays,
    AgendaView,
    CalendarContextMenu,
    CalendarSourcePills,
    CalendarUIProvider,
    DayView,
    decorate,
    JumpToDate,
    MonthView,
    periodLabel,
    TimelineView,
    TodayRail,
    viewRange,
    WeekView,
    type CalView,
    type Decorated,
    type SourceDef,
} from '@/pages/sites/calendar/_parts';
import {
    BookOpen,
    CalendarDays,
    ChevronLeft,
    ChevronRight,
    Download,
    Lock,
    Plus,
    RefreshCw,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { calendarCsv } from './calendar-export';
import { CalendarRange } from './calendar-range';
import {
    calendarDate,
    calendarLocal,
    calendarMoveChoices,
    type CalendarTimeProposal,
} from './calendar-time';
import { FleetHeaderSlot, type FleetHeaderTargets } from './fleet-header';

export type FleetCalendarVehicle = {
    id: number;
    name: string;
    asset_tag: string | null;
    registration_number: string | null;
    status: string;
    body_type: string | null;
    seating_capacity: number | null;
    has_wheelchair_ramp: boolean;
    has_hoist: boolean;
    at_your_sites: boolean;
    home_site: { id: number; name: string } | null;
};
export type FleetEvent = VehicleCalendarItem & { vehicleId: number };
const SOURCES: SourceDef[] = [
    {
        key: 'respite',
        label: 'Bookings & unavailable',
        short: 'Booking',
        group: 'auto',
        icon: 'Lock',
        origin: 'Vehicle bookings',
        note: 'Bookings and unavailable periods',
    },
    {
        key: 'event',
        label: 'Confirmed appointments',
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
        key: 'damage',
        label: 'Restriction records',
        short: 'Restriction',
        group: 'auto',
        icon: 'AlertTriangle',
        origin: 'Maintenance',
    },
    {
        key: 'compliance',
        label: 'Reminders',
        short: 'Reminder',
        group: 'auto',
        icon: 'ShieldCheck',
        origin: 'Compliance',
    },
];
const keyDate = (date: Date) =>
    `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;

export function FleetCalendar({
    view,
    date,
    vehicles,
    vehicleId,
    onDate,
    onDay,
    onVehicle,
    onRequest,
    onEdit,
    onProfile,
    headerTargets,
    onView,
    vehicleCriteria = [],
    onClearCriteria,
}: {
    view: CalView;
    date: string;
    vehicles: FleetCalendarVehicle[];
    vehicleId: number | null;
    onDate: (date: string) => void;
    onDay?: (date: string) => void;
    onVehicle: (id: number | null) => void;
    onRequest: (vehicleId: number | null, start?: string, end?: string) => void;
    onEdit: (event: FleetEvent, proposal?: CalendarTimeProposal) => void;
    onProfile: (id: number) => void;
    headerTargets?: FleetHeaderTargets;
    onView?: (view: CalView) => void;
    vehicleCriteria?: string[];
    onClearCriteria?: () => void;
}) {
    const navDate = useMemo(() => new Date(`${date}T12:00:00`), [date]);
    const [events, setEvents] = useState<FleetEvent[]>([]);
    const [loading, setLoading] = useState(true);
    const request = useRef<AbortController | null>(null);
    const [, setRevision] = useState(0);
    const [error, setError] = useState('');
    const [asOf, setAsOf] = useState('');
    const [query, setQuery] = useState(
        () =>
            new URLSearchParams(window.location.search).get('calendarQuery') ??
            '',
    );
    const [enabled, setEnabled] = useState(() => {
        const saved = new URLSearchParams(window.location.search);
        return new Set(
            saved.has('calendarSources')
                ? saved.get('calendarSources')!.split(',')
                : SOURCES.map((source) => source.key),
        );
    });
    const [selected, setSelected] = useState<FleetEvent | null>(null);
    const [moveNotice, setMoveNotice] = useState('');
    const [moveChoices, setMoveChoices] = useState<{
        event: FleetEvent;
        choices: CalendarTimeProposal[];
    } | null>(null);
    const [bookingDetails, setBookingDetails] = useState<BookingRow | null>(
        null,
    );
    useEffect(() => {
        setBookingDetails(null);
        if (selected?.kind !== 'booking' || !selected.recordId) return;
        const controller = new AbortController();
        void fetch(
            `/fleet-assets/vehicles/${selected.vehicleId}/calendar/records/booking/${selected.recordId}`,
            {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
                signal: controller.signal,
            },
        )
            .then(async (response) => {
                if (!response.ok) return;
                const result = (await response.json()) as { row: BookingRow };
                if (!controller.signal.aborted) setBookingDetails(result.row);
            })
            .catch(() => {});
        return () => controller.abort();
    }, [selected]);
    const [menu, setMenu] = useState<{
        x: number;
        y: number;
        opener: HTMLElement | null;
        event?: FleetEvent;
        start?: string;
    } | null>(null);
    const today = calendarDate(new Date());
    const { start, end } = viewRange(view, navDate);
    const rangeStart = keyDate(start);
    const rangeEnd = keyDate(end);
    const reload = useCallback(async () => {
        request.current?.abort();
        const controller = new AbortController();
        request.current = controller;
        setLoading(true);
        setError('');
        setSelected(null);
        setMoveNotice('');
        setMoveChoices(null);
        setMenu(null);
        try {
            const params = new URLSearchParams({
                start: rangeStart,
                end: rangeEnd,
            });
            const response = await fetch(
                `/fleet-assets/vehicles/fleet-calendar/events?${params}`,
                {
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json' },
                    signal: controller.signal,
                },
            );
            if (!response.ok)
                throw new Error(
                    response.status === 403
                        ? 'Your calendar access has changed.'
                        : 'The fleet calendar could not be loaded.',
                );
            const result = (await response.json()) as {
                events: FleetEvent[];
                as_of: string;
            };
            if (controller.signal.aborted) return;
            setEvents(result.events);
            setAsOf(result.as_of);
        } catch (reason) {
            if (controller.signal.aborted) return;
            setEvents([]);
            setAsOf('');
            setError(
                reason instanceof Error
                    ? reason.message
                    : 'The fleet calendar could not be loaded.',
            );
        } finally {
            if (!controller.signal.aborted) setLoading(false);
        }
    }, [rangeStart, rangeEnd]);
    useEffect(() => {
        void reload();
        return () => request.current?.abort();
    }, [reload]);
    useEffect(() => {
        const url = new URL(window.location.href);
        if (query) url.searchParams.set('calendarQuery', query);
        else url.searchParams.delete('calendarQuery');
        if (enabled.size === SOURCES.length)
            url.searchParams.delete('calendarSources');
        else url.searchParams.set('calendarSources', [...enabled].join(','));
        window.history.replaceState(window.history.state, '', url);
    }, [query, enabled]);
    useEffect(() => {
        const timer = window.setInterval(
            () => setRevision((value) => value + 1),
            30000,
        );
        return () => window.clearInterval(timer);
    }, []);
    const stale =
        !!asOf &&
        (Date.now() - Date.parse(asOf) > 5 * 60000 ||
            !Number.isFinite(Date.parse(asOf)));
    const canChange = !loading && !error && !!asOf && !stale;
    const byId = useMemo(
        () => new Map(vehicles.map((vehicle) => [vehicle.id, vehicle])),
        [vehicles],
    );
    const scoped = events.filter(
        (event) =>
            byId.has(event.vehicleId) &&
            (vehicleId === null || event.vehicleId === vehicleId),
    );
    const searched = scoped.filter((event) => {
        const vehicle = byId.get(event.vehicleId);
        // Busy-only projections contain no source identity or private details.
        const text = `${vehicle?.name ?? ''} ${vehicle?.registration_number ?? ''} ${vehicle?.asset_tag ?? ''} ${event.title} ${event.ref ?? ''}`;
        return text.toLowerCase().includes(query.trim().toLowerCase());
    });
    const counts = Object.fromEntries(
        SOURCES.map((source) => [
            source.key,
            searched.filter((event) => event.source === source.key).length,
        ]),
    );
    const visible = searched
        .filter((event) => enabled.has(event.source))
        .map((event) => {
            const vehicle = byId.get(event.vehicleId);
            return {
                ...decorate({
                    ...event,
                    start: event.start ? toDatetimeLocal(event.start) : null,
                    end: event.end ? toDatetimeLocal(event.end) : null,
                    title: `${vehicle?.name ?? 'Vehicle'} · ${event.title}`,
                    editable:
                        canChange &&
                        event.kind === 'booking' &&
                        event.editable === true,
                }),
                typeLabel:
                    event.kind === 'busy'
                        ? 'Busy'
                        : (SOURCES.find((source) => source.key === event.source)
                              ?.label ?? 'Vehicle record'),
            };
        });
    const source = Object.fromEntries(SOURCES.map((item) => [item.key, item]));
    const eventByKey = (decorated: Decorated) =>
        events.find(
            (event) =>
                event.id === decorated.id &&
                event.vehicleId ===
                    (decorated as Decorated & { vehicleId: number }).vehicleId,
        );
    const select = (decorated: Decorated) => {
        const event = eventByKey(decorated);
        if (event) setSelected(event);
    };
    const move = (
        decorated: Decorated,
        proposedDate: Date,
        proposedEnd?: Date,
        intent?: {
            kind: 'move' | 'resize';
            startLocal: string;
            endLocal: string;
        },
    ) => {
        if (!canChange) return;
        const event = eventByKey(decorated);
        if (
            !event ||
            event.kind !== 'booking' ||
            !event.editable ||
            !event.start ||
            !event.end
        )
            return;
        setMoveNotice('');
        setMoveChoices(null);
        if (intent?.kind === 'resize' || (!intent && proposedEnd)) {
            onEdit(event, {
                start: intent?.startLocal ?? calendarLocal(proposedDate),
                end: intent?.endLocal ?? calendarLocal(proposedEnd!),
                startOffset: bookingOffset(event.start),
            });
            return;
        }
        const startLocal =
            intent?.startLocal ??
            `${keyDate(proposedDate)}T${toDatetimeLocal(event.start).slice(11)}`;
        const choices = calendarMoveChoices(event.start, event.end, startLocal);
        if (!choices.length) {
            setMoveNotice(
                'Cannot move to that Auckland time. It does not exist during the daylight-saving change, or the source duration is invalid. Choose another time.',
            );
            return;
        }
        if (choices.length > 1) {
            setMoveChoices({ event, choices });
            return;
        }
        const proposal = choices[0];
        if (
            Date.parse(`${proposal.start}:00${proposal.startOffset}`) ===
            Date.parse(event.start)
        )
            return;
        onEdit(event, proposal);
    };
    const shift = (direction: number) => {
        if (view === 'day') return onDate(keyDate(addDays(navDate, direction)));
        if (view === 'week')
            return onDate(keyDate(addDays(navDate, direction * 7)));
        onDate(
            keyDate(
                new Date(
                    navDate.getFullYear(),
                    navDate.getMonth() + direction,
                    Math.min(
                        navDate.getDate(),
                        new Date(
                            navDate.getFullYear(),
                            navDate.getMonth() + direction + 1,
                            0,
                        ).getDate(),
                    ),
                ),
            ),
        );
    };
    const exportVisible = () => {
        const url = URL.createObjectURL(
            new Blob(
                [
                    '\ufeff',
                    calendarCsv(
                        searched.filter((event) => enabled.has(event.source)),
                        (id) => byId.get(id)?.name ?? 'Vehicle',
                    ),
                ],
                { type: 'text/csv;charset=utf-8' },
            ),
        );
        const link = document.createElement('a');
        link.href = url;
        link.download = `fleet-calendar-${rangeStart}-${rangeEnd}.csv`;
        link.click();
        window.setTimeout(() => URL.revokeObjectURL(url), 1000);
    };
    return (
        <section className="space-y-3 pt-4" aria-label="Fleet calendar">
            <FleetHeaderSlot target={headerTargets?.meters}>
                <PageHeaderMeterBlock
                    label="Viewing"
                    value={
                        loading
                            ? 'Checking…'
                            : `${visible.length} ${visible.length === 1 ? 'entry' : 'entries'}`
                    }
                    onClick={() =>
                        document
                            .querySelector<HTMLButtonElement>(
                                '.fleet-calendar-date button',
                            )
                            ?.click()
                    }
                >
                    <div className="flex items-center gap-2">
                        <b className="text-[28px] leading-none">
                            {navDate.getDate()}
                        </b>
                        <span className="text-xs leading-tight">
                            {navDate.toLocaleDateString('en-NZ', {
                                month: 'long',
                            })}
                            <br />
                            {navDate.getFullYear()}
                        </span>
                    </div>
                    <PageHeaderMeterCaption>
                        {periodLabel(view, navDate)}
                    </PageHeaderMeterCaption>
                </PageHeaderMeterBlock>
            </FleetHeaderSlot>
            <FleetHeaderSlot target={headerTargets?.actions}>
                <PageHeaderSearch
                    value={query}
                    onChange={setQuery}
                    placeholder="Search entries or registration…"
                    ariaLabel="Search fleet calendar"
                />
                <PageHeaderGlassButton
                    disabled={!canChange}
                    onClick={exportVisible}
                    aria-label="Export visible calendar"
                >
                    <Download className="size-4" />
                    Export
                </PageHeaderGlassButton>
                <PageHeaderPrimaryButton
                    icon={Plus}
                    disabled={!canChange}
                    onClick={() => onRequest(vehicleId)}
                    aria-label="Request a vehicle"
                >
                    Request vehicle
                </PageHeaderPrimaryButton>
            </FleetHeaderSlot>
            <FleetHeaderSlot target={headerTargets?.filters}>
                <PageHeaderFilterSelect
                    label="Vehicle"
                    value={vehicleId ? String(vehicleId) : 'all'}
                    options={[
                        { value: 'all', label: 'All vehicles' },
                        ...vehicles.map((vehicle) => ({
                            value: String(vehicle.id),
                            label: vehicle.name,
                        })),
                    ]}
                    onChange={(value) =>
                        onVehicle(value === 'all' ? null : Number(value))
                    }
                />
                <PageHeaderFilterButton
                    aria-label="Previous period"
                    onClick={() => shift(-1)}
                >
                    <ChevronLeft className="size-3" />
                </PageHeaderFilterButton>
                <PageHeaderFilterButton onClick={() => onDate(keyDate(today))}>
                    Today
                </PageHeaderFilterButton>
                <span className="fleet-calendar-date">
                    <JumpToDate
                        pill
                        view={view}
                        navDate={navDate}
                        onPick={(next) => onDate(keyDate(next))}
                    />
                </span>
                <PageHeaderFilterButton
                    aria-label="Next period"
                    onClick={() => shift(1)}
                >
                    <ChevronRight className="size-3" />
                </PageHeaderFilterButton>
                {onView && (
                    <PageHeaderFilterButton
                        onClick={() => onView('timeline')}
                        title="Compare recorded activity in Timeline. Request a vehicle to assess an exact trip."
                    >
                        Compare slots
                    </PageHeaderFilterButton>
                )}
            </FleetHeaderSlot>
            {vehicleCriteria.length > 0 && (
                <Card className="flex flex-row flex-wrap items-center gap-2 rounded-lg px-3 py-2 text-sm">
                    <span>Vehicle criteria: {vehicleCriteria.join(' · ')}</span>
                    <Button
                        variant="link"
                        size="sm"
                        className="ml-auto"
                        onClick={onClearCriteria}
                    >
                        Clear vehicle criteria
                    </Button>
                </Card>
            )}
            <div className="flex flex-wrap items-center justify-between gap-2">
                <CalendarSourcePills
                    sources={SOURCES}
                    enabled={enabled}
                    counts={counts}
                    onToggle={(key) =>
                        setEnabled((previous) => {
                            const next = new Set(previous);
                            if (next.has(key)) next.delete(key);
                            else next.add(key);
                            return next;
                        })
                    }
                />
                <div className="ml-auto flex items-center gap-2">
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => {
                            setEnabled(
                                new Set(SOURCES.map((source) => source.key)),
                            );
                            setQuery('');
                        }}
                    >
                        Reset sources
                    </Button>
                    <Button
                        size="sm"
                        variant="ghost"
                        onClick={() => void reload()}
                        disabled={loading}
                        title={
                            asOf
                                ? `Source checked ${formatDateTime(asOf)}`
                                : 'Checking source'
                        }
                    >
                        <RefreshCw className="size-3" />
                        Recheck
                    </Button>
                </div>
            </div>
            {error && (
                <div
                    role="alert"
                    className="rounded-lg border border-status-critical bg-status-critical-bg p-3 text-status-critical-foreground"
                >
                    {error}{' '}
                    <Button variant="outline" onClick={() => void reload()}>
                        Retry
                    </Button>
                </div>
            )}
            {stale && (
                <p role="status" className="text-sm text-status-warning">
                    This calendar snapshot is more than five minutes old.
                    Recheck before requesting or changing a booking.
                </p>
            )}
            {moveNotice && (
                <p role="alert" className="text-sm text-status-warning">
                    {moveNotice}
                </p>
            )}
            <p className="text-caption flex items-center gap-2 rounded-lg border bg-card p-3">
                <Lock className="size-4" />A lock means Cannot move. In-use and
                restricted entries can be inspected by a short click or keyboard
                Enter/Space. Booking changes open for review and require a
                reason. Estimates and reminders do not reserve time.
            </p>
            <CalendarUIProvider
                value={{
                    colorBy: 'source',
                    density: 'comfortable',
                    srcByKey: source,
                    onSelect: select,
                    onEntryContext: (entry, event) => {
                        const sourceEvent = eventByKey(entry);
                        if (sourceEvent)
                            setMenu({
                                x: event.clientX || 100,
                                y: event.clientY || 300,
                                opener: document.activeElement as HTMLElement,
                                event: sourceEvent,
                            });
                    },
                    onContext: (event, day, hour = 9) => {
                        event.preventDefault();
                        setMenu({
                            x: event.clientX || 100,
                            y: event.clientY || 300,
                            opener: document.activeElement as HTMLElement,
                            start: addLocalMinutes(
                                `${keyDate(day)}T00:00`,
                                Math.round(hour * 60),
                            ),
                        });
                    },
                    onMove: canChange ? move : undefined,
                    onCreateAt: canChange
                        ? (day, hour = 9) =>
                              onRequest(
                                  vehicleId,
                                  addLocalMinutes(
                                      `${keyDate(day)}T00:00`,
                                      Math.round(hour * 60),
                                  ),
                              )
                        : undefined,
                    onMore: (day) => (onDay ?? onDate)(keyDate(day)),
                }}
            >
                <div className="flex min-w-0 items-start gap-3">
                    <div
                        className="relative h-[620px] min-w-0 flex-1"
                        aria-busy={loading}
                    >
                        <CalendarRange
                            enabled={
                                canChange && (view === 'week' || view === 'day')
                            }
                            onRange={(start, end) =>
                                onRequest(vehicleId, start, end)
                            }
                        >
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
                                    sourcesOff={!enabled.size}
                                    filtersActive={
                                        !!query ||
                                        enabled.size !== SOURCES.length
                                    }
                                />
                            )}
                            {view === 'timeline' && (
                                <TimelineView
                                    events={visible}
                                    navDate={navDate}
                                    sources={SOURCES}
                                />
                            )}
                            {loading && (
                                <div className="pointer-events-none absolute inset-0 flex justify-center pt-24">
                                    <span className="text-caption rounded-full border bg-card px-3 py-2 shadow">
                                        Loading calendar…
                                    </span>
                                </div>
                            )}
                        </CalendarRange>
                    </div>
                    {keyDate(today) >= rangeStart &&
                        keyDate(today) < rangeEnd && (
                            <TodayRail
                                events={visible}
                                today={today}
                                onSelect={select}
                                onApprovals={() =>
                                    window.location.assign(
                                        '/fleet-assets/bookings?status=pending',
                                    )
                                }
                                onJumpToday={() => onDate(keyDate(today))}
                                viewingToday={date === keyDate(today)}
                            />
                        )}
                </div>
            </CalendarUIProvider>
            <p className="text-caption">
                Calendar gaps do not prove readiness. A source booking,
                reservation, restriction or actual return may change after this
                snapshot; every request and edit is checked again when saved.
            </p>
            <Dialog
                open={!!moveChoices}
                onOpenChange={(open) => {
                    if (!open) setMoveChoices(null);
                }}
            >
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>
                            Choose the repeated Auckland time
                        </DialogTitle>
                        <DialogDescription>
                            {moveChoices?.choices[0].start.replace('T', ' ')}{' '}
                            occurs twice as daylight saving ends. Choose the
                            occurrence; the booking keeps its elapsed duration
                            and opens for review.
                        </DialogDescription>
                    </DialogHeader>
                    {moveChoices?.choices.map((choice, index) => (
                        <Button
                            key={choice.startOffset}
                            variant="outline"
                            disabled={!canChange}
                            onClick={() => {
                                const event = moveChoices.event;
                                setMoveChoices(null);
                                if (canChange) onEdit(event, choice);
                            }}
                        >
                            {index === 0 ? 'First' : 'Second'} occurrence (UTC
                            {choice.startOffset})
                        </Button>
                    ))}
                </DialogContent>
            </Dialog>
            <Dialog
                open={!!selected}
                onOpenChange={(open) => {
                    if (!open) setSelected(null);
                }}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            {selected?.kind === 'busy'
                                ? 'Busy time'
                                : selected?.title}
                        </DialogTitle>
                        <DialogDescription>
                            {selected
                                ? `${formatDateTime(selected.start)} to ${selected.end ? formatDateTime(selected.end) : 'open ended'} · Pacific/Auckland`
                                : ''}
                        </DialogDescription>
                    </DialogHeader>
                    {selected && (
                        <div className="space-y-3">
                            <p className="text-sm">
                                {selected.kind === 'busy'
                                    ? 'Source details are restricted. No requester, driver, purpose or destination is available in this view.'
                                    : selected.desc || selected.statusLabel}
                            </p>
                            {selected.kind !== 'busy' && (
                                <p className="text-caption">
                                    Source:{' '}
                                    {SOURCES.find(
                                        (source) =>
                                            source.key === selected.source,
                                    )?.origin ?? 'Vehicle records'}
                                    {selected.ref ? ` · ${selected.ref}` : ''}
                                </p>
                            )}
                            {bookingDetails &&
                                selected.recordId === bookingDetails.id && (
                                    <dl className="space-y-2 text-sm">
                                        <div>
                                            <dt className="text-caption">
                                                Requester → driver
                                            </dt>
                                            <dd>
                                                {bookingDetails.requester
                                                    ?.name ??
                                                    'Not recorded'}{' '}
                                                →{' '}
                                                {bookingDetails.driver?.name ??
                                                    'Not assigned'}
                                            </dd>
                                        </div>
                                        <div>
                                            <dt className="text-caption">
                                                Purpose
                                            </dt>
                                            <dd>{bookingDetails.purpose}</dd>
                                        </div>
                                        <div>
                                            <dt className="text-caption">
                                                Source state
                                            </dt>
                                            <dd>
                                                {bookingDetails.status_label}
                                            </dd>
                                        </div>
                                    </dl>
                                )}
                            <div className="flex flex-wrap gap-2">
                                <Button
                                    variant="outline"
                                    onClick={() => {
                                        onProfile(selected.vehicleId);
                                        setSelected(null);
                                    }}
                                >
                                    Vehicle profile
                                </Button>
                                {selected.kind !== 'busy' && selected.link && (
                                    <Button
                                        variant="outline"
                                        onClick={() =>
                                            window.location.assign(
                                                fleetSourceHref(selected.link!),
                                            )
                                        }
                                    >
                                        Open source record
                                    </Button>
                                )}
                                {selected.kind === 'booking' &&
                                    selected.editable && (
                                        <Button
                                            disabled={!canChange}
                                            onClick={() => {
                                                onEdit(selected);
                                                setSelected(null);
                                            }}
                                        >
                                            Edit / reschedule
                                        </Button>
                                    )}
                                {selected.kind === 'booking' &&
                                    selected.link && (
                                        <Button
                                            variant="outline"
                                            onClick={() =>
                                                window.location.assign(
                                                    fleetSourceHref(
                                                        selected.link!,
                                                    ),
                                                )
                                            }
                                        >
                                            Checkout / return
                                        </Button>
                                    )}
                            </div>
                        </div>
                    )}
                </DialogContent>
            </Dialog>
            {menu && (
                <CalendarContextMenu
                    x={menu.x}
                    y={menu.y}
                    heading={menu.event?.title ?? 'Choose this time'}
                    subheading="Pacific/Auckland"
                    chip={menu.event ? 'Entry' : 'Request'}
                    ariaLabel="Fleet calendar actions"
                    returnFocus={menu.opener}
                    onClose={() => setMenu(null)}
                    sections={[
                        {
                            key: 'actions',
                            items: menu.event
                                ? [
                                      {
                                          key: 'inspect',
                                          label:
                                              menu.event.kind === 'busy'
                                                  ? 'View busy time'
                                                  : 'View entry details',
                                          icon: BookOpen,
                                          onSelect: () => {
                                              setSelected(menu.event!);
                                              setMenu(null);
                                          },
                                      },
                                      ...(menu.event.kind === 'booking' &&
                                      menu.event.editable
                                          ? [
                                                {
                                                    key: 'edit',
                                                    label: 'Edit / reschedule / extend',
                                                    icon: CalendarDays,
                                                    disabled: !canChange,
                                                    onSelect: () => {
                                                        onEdit(menu.event!);
                                                        setMenu(null);
                                                    },
                                                },
                                            ]
                                          : []),
                                  ]
                                : [
                                      {
                                          key: 'request',
                                          label: 'Request a vehicle at this time',
                                          icon: Plus,
                                          disabled: !canChange,
                                          detail: !canChange
                                              ? 'Recheck current source data first.'
                                              : undefined,
                                          onSelect: () => {
                                              onRequest(vehicleId, menu.start);
                                              setMenu(null);
                                          },
                                      },
                                  ],
                        },
                    ]}
                />
            )}
        </section>
    );
}
