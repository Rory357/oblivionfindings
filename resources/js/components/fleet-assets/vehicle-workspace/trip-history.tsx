import { DatePicker } from '@/components/fleet-assets/maintenance/date-picker';
import { Button } from '@/components/ui/button';
import { ErrorState } from '@/components/ui/error-state';
import { Input } from '@/components/ui/input';
import { LoadingState } from '@/components/ui/loading-state';
import { StatusBadge } from '@/components/ui/status-badge';
import { formatDateOnly, formatTime } from '@/lib/datetime';
import { router } from '@inertiajs/react';
import {
    Activity,
    ArrowRight,
    Clock3,
    Navigation,
    Route,
    UserRound,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { VehicleSearchSelect } from './search-select';
import './studio.css';
import { ConfirmDriverWizard, TripSourceDialog } from './trip-driver-dialog';
import { TripExportDialog } from './trip-export-dialog';
import { CoachingWizard, TripNext } from './trip-follow-up';
import { TripInsights } from './trip-insights';
import { TripJourney } from './trip-journey';
import {
    dayOptions,
    DEFAULT_TRIP_FILTERS,
    driverName,
    endLabel,
    EVENT_FILTERS,
    formatDistance,
    isDay,
    playbackDelay,
    rangeInvalid,
    readStored,
    startLabel,
    tripBadge,
    tripHistoryUrl,
    tripMinutes,
    tripQuery,
    TRIPS_PER_PAGE,
    writeStored,
    type TimelineFilter,
    type TripFilterState,
} from './trip-model';
import { TripTimeline } from './trip-timeline';
import type {
    TripDetail,
    TripEvent,
    TripEventFilter,
    TripListResponse,
} from './trip-types';
import './trips.css';
import type { VehicleWorkspace } from './types';
import type { WorkspaceLocation } from './workspace-model';

type Load = 'loading' | 'ready' | 'error' | 'forbidden';
type DialogName = 'export' | 'driver' | 'source' | 'coach' | null;

function sanitise(stored: TripFilterState): TripFilterState {
    const text = (value: unknown) => (typeof value === 'string' ? value : '');
    const day = text(stored.day);
    const event = EVENT_FILTERS.some((option) => option.value === stored.event)
        ? stored.event
        : 'all';
    return {
        q: text(stored.q).slice(0, 100),
        day: day === 'range' || isDay(day) ? day : 'all',
        from: isDay(text(stored.from)) ? text(stored.from) : '',
        to: isDay(text(stored.to)) ? text(stored.to) : '',
        driver: /^(all|unassigned|\d+)$/.test(text(stored.driver))
            ? text(stored.driver)
            : 'all',
        event,
    };
}

async function getJson<T>(url: string, signal: AbortSignal): Promise<T> {
    const response = await fetch(url, {
        signal,
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
    });
    if (!response.ok)
        throw Object.assign(new Error('request failed'), {
            status: response.status,
        });
    return (await response.json()) as T;
}

function failure(error: unknown): Load | null {
    if ((error as Error)?.name === 'AbortError') return null;
    const status = (error as { status?: number })?.status;
    return status === 401 || status === 403 || status === 404
        ? 'forbidden'
        : 'error';
}

/**
 * The vehicle profile's Trip history view, built to the approved PKG-02B v13
 * design: filters, totals, paged trip cards, the recorded journey with sample
 * playback, trip behaviour, speed, driver attribution, the journey timeline,
 * follow-up and PDF / Excel exports. Data comes from the trip-history
 * endpoints; nothing here is sample content.
 */
export function TripHistory({
    workspace,
    focusDate,
    onNavigate,
}: {
    workspace: VehicleWorkspace;
    focusDate?: string;
    onNavigate: (location: WorkspaceLocation) => void;
}) {
    const vehicle = workspace.vehicle;
    const storageKey = `vehicle-trip-history.${vehicle.id}.filters`;
    const [filters, setFilters] = useState<TripFilterState>(() =>
        sanitise(readStored(storageKey, DEFAULT_TRIP_FILTERS)),
    );
    const [search, setSearch] = useState(filters.q);
    const [page, setPage] = useState(1);
    const [list, setList] = useState<TripListResponse | null>(null);
    const [listLoad, setListLoad] = useState<Load>('loading');
    const [selectedId, setSelectedId] = useState<number | null>(null);
    const [detail, setDetail] = useState<TripDetail | null>(null);
    const [detailLoad, setDetailLoad] = useState<Load>('loading');
    const [sample, setSample] = useState(0);
    const [playing, setPlaying] = useState(false);
    const [rate, setRate] = useState(1);
    const [eventKey, setEventKey] = useState('');
    const [mapFocus, setMapFocus] = useState<{
        lat: number;
        lng: number;
        nonce: number;
    }>();
    const [timelineFilter, setTimelineFilter] = useState<TimelineFilter>('all');
    const [dialog, setDialog] = useState<DialogName>(null);
    // Dialogs keep the trip they opened with, so a refresh behind them
    // (after a save) cannot close them before their success pane.
    const [dialogDetail, setDialogDetail] = useState<TripDetail | null>(null);
    const [reload, setReload] = useState(0);
    const mapPanel = useRef<HTMLElement | null>(null);
    const sampleRef = useRef(sample);
    useEffect(() => {
        sampleRef.current = sample;
    }, [sample]);

    useEffect(() => writeStored(storageKey, filters), [storageKey, filters]);

    const updateFilters = useCallback((patch: Partial<TripFilterState>) => {
        setFilters((current) => ({ ...current, ...patch }));
        setPage(1);
        setPlaying(false);
    }, []);

    // Search waits for a pause in typing.
    useEffect(() => {
        const timer = window.setTimeout(() => {
            setFilters((current) =>
                current.q === search ? current : { ...current, q: search },
            );
            setPage((current) => (filters.q === search ? current : 1));
        }, 300);
        return () => window.clearTimeout(timer);
    }, [search, filters.q]);

    // A calendar day opened from elsewhere replaces the filters (approved).
    useEffect(() => {
        if (!focusDate || !isDay(focusDate)) return;
        setFilters({ ...DEFAULT_TRIP_FILTERS, day: focusDate });
        setSearch('');
        setPage(1);
        setPlaying(false);
        setSelectedId(null);
    }, [focusDate]);

    const invalidRange = rangeInvalid(filters);
    const listQuery = tripQuery(filters, {
        page,
        per_page: TRIPS_PER_PAGE,
    }).toString();
    useEffect(() => {
        if (invalidRange) return;
        const controller = new AbortController();
        setListLoad('loading');
        getJson<TripListResponse>(
            `${tripHistoryUrl(vehicle.id)}?${listQuery}`,
            controller.signal,
        )
            .then((response) => {
                setList(response);
                setListLoad('ready');
                // The server keeps the page within range after filtering.
                setPage(response.meta.page);
                setSelectedId((current) =>
                    current !== null && response.trip_ids.includes(current)
                        ? current
                        : (response.data[0]?.id ?? null),
                );
            })
            .catch((error: unknown) => {
                const state = failure(error);
                if (state) setListLoad(state);
            });
        return () => controller.abort();
    }, [vehicle.id, listQuery, invalidRange, reload]);

    // A different trip starts from its first recorded point.
    useEffect(() => {
        setSample(0);
        setPlaying(false);
        setEventKey('');
        setMapFocus(undefined);
        setTimelineFilter('all');
    }, [selectedId]);

    useEffect(() => {
        if (selectedId === null) {
            setDetail(null);
            return;
        }
        const controller = new AbortController();
        setDetailLoad('loading');
        getJson<TripDetail>(
            tripHistoryUrl(vehicle.id, `/${selectedId}`),
            controller.signal,
        )
            .then((response) => {
                setDetail(response);
                setDetailLoad('ready');
            })
            .catch((error: unknown) => {
                const state = failure(error);
                if (state) setDetailLoad(state);
            });
        return () => controller.abort();
    }, [vehicle.id, selectedId, reload]);

    const points = useMemo(() => detail?.points ?? [], [detail]);
    const pointCount = points.length;

    // Playback steps through the recorded points; it stops at the last one.
    useEffect(() => {
        if (!playing) return;
        const timer = window.setInterval(
            () => {
                if (sampleRef.current >= pointCount - 1) {
                    setPlaying(false);
                    return;
                }
                setSample(sampleRef.current + 1);
            },
            playbackDelay(pointCount, rate),
        );
        return () => window.clearInterval(timer);
    }, [playing, rate, pointCount]);

    const selectPoint = useCallback(
        (index: number) => {
            setSample(Math.max(0, Math.min(index, pointCount - 1)));
            setPlaying(false);
            setEventKey('');
        },
        [pointCount],
    );
    const togglePlay = () => {
        if (!playing && sample >= pointCount - 1) setSample(0);
        setPlaying(!playing);
    };
    const selectEvent = (event: TripEvent) => {
        setSample(event.point);
        setEventKey(event.key);
        setPlaying(false);
        const point = points[event.point];
        if (point)
            setMapFocus({ lat: point.lat, lng: point.lng, nonce: Date.now() });
        const reduce =
            typeof window.matchMedia === 'function' &&
            window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        mapPanel.current?.scrollIntoView({
            block: 'center',
            behavior: reduce ? 'auto' : 'smooth',
        });
    };
    const choose = (id: number) => {
        if (id !== selectedId) setSelectedId(id);
    };
    const resetFilters = () => {
        setSearch('');
        setFilters(DEFAULT_TRIP_FILTERS);
        setPage(1);
        setPlaying(false);
    };

    const summary = list?.summary;
    const detailReady =
        detail !== null &&
        detail.trip.id === selectedId &&
        detailLoad === 'ready';
    const focusedEvent =
        detailReady && eventKey
            ? (detail.events.find(
                  (event) => event.key === eventKey && event.point === sample,
              ) ?? null)
            : null;
    const days = dayOptions(list?.recent_days ?? [], filters.day);
    const driverOptions = [
        {
            value: 'all',
            label: 'All drivers',
            description: 'Every trip you can see',
        },
        ...(list?.drivers ?? []).map((driver) => ({
            value: String(driver.id),
            label: driver.name,
            description: driver.confirmed
                ? `Confirmed on ${driver.confirmed} of ${driver.trips} ${driver.trips === 1 ? 'trip' : 'trips'}`
                : `Booked or signed in · ${driver.trips} ${driver.trips === 1 ? 'trip' : 'trips'} · unconfirmed`,
        })),
        {
            value: 'unassigned',
            label: 'Unassigned',
            description: 'No driver recorded',
        },
    ];
    const canCoach = workspace.can.manage && workspace.people.length > 0;
    const coachBlocked = !detailReady
        ? null
        : detail.trip.consent_blocked
          ? 'Trips without tracking consent are not used for coaching.'
          : detail.trip.is_personal
            ? 'Personal trips are not used for coaching.'
            : null;
    const refreshAfterSave = () => setReload((value) => value + 1);
    const openTripDialog = (name: 'driver' | 'source' | 'coach') => {
        if (!detailReady) return;
        setDialogDetail(detail);
        setDialog(name);
    };
    const closeDialog = () => {
        setDialog(null);
        setDialogDetail(null);
    };

    return (
        <div className="vehicle-studio journey-studio">
            {dialog === 'export' && list && (
                <TripExportDialog
                    vehicle={list.vehicle}
                    filters={filters}
                    defaultFrom={
                        filters.day === 'range' && filters.from
                            ? filters.from
                            : (summary?.first_day ?? '')
                    }
                    defaultTo={
                        filters.day === 'range' && filters.to
                            ? filters.to
                            : (summary?.last_day ?? '')
                    }
                    onClose={closeDialog}
                />
            )}
            {dialog === 'driver' && dialogDetail && (
                <ConfirmDriverWizard
                    workspace={workspace}
                    detail={dialogDetail}
                    onClose={closeDialog}
                    onSaved={refreshAfterSave}
                />
            )}
            {dialog === 'source' && dialogDetail && (
                <TripSourceDialog detail={dialogDetail} onClose={closeDialog} />
            )}
            {dialog === 'coach' && dialogDetail && (
                <CoachingWizard
                    workspace={workspace}
                    detail={dialogDetail}
                    onClose={closeDialog}
                    onSaved={() => router.reload({ only: ['workspace'] })}
                />
            )}

            <div
                className="journey-refine"
                role="group"
                aria-label="Trip filters"
            >
                <label className="trip-search-field">
                    Search trips
                    <Input
                        aria-label="Search trips"
                        placeholder="Driver, route or reference…"
                        value={search}
                        maxLength={100}
                        onChange={(event) => setSearch(event.target.value)}
                    />
                </label>
                <label className="trip-date-field">
                    Dates
                    <select
                        className="select"
                        aria-label="Trip date filter"
                        value={filters.day}
                        onChange={(event) =>
                            updateFilters({
                                day: event.target.value,
                                from: '',
                                to: '',
                            })
                        }
                    >
                        <option value="all">All recorded dates</option>
                        <option value="range">Custom date range</option>
                        {days.map((day) => (
                            <option key={day} value={day}>
                                {formatDateOnly(day)}
                            </option>
                        ))}
                    </select>
                </label>
                <div className="field">
                    <label className="field-label" htmlFor="trip-driver-filter">
                        Driver
                    </label>
                    <VehicleSearchSelect
                        id="trip-driver-filter"
                        label="Driver"
                        value={filters.driver}
                        options={driverOptions}
                        onChange={(value) =>
                            updateFilters({ driver: value || 'all' })
                        }
                    />
                </div>
                <label className="trip-event-field">
                    Event type
                    <select
                        className="select"
                        aria-label="Trip type filter"
                        value={filters.event}
                        onChange={(event) =>
                            updateFilters({
                                event: event.target.value as TripEventFilter,
                            })
                        }
                    >
                        {EVENT_FILTERS.map((option) => (
                            <option key={option.value} value={option.value}>
                                {option.label}
                            </option>
                        ))}
                    </select>
                </label>
                <div className="trip-filter-actions">
                    <Button variant="ghost" size="sm" onClick={resetFilters}>
                        Reset filters
                    </Button>
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() => setDialog('export')}
                        disabled={!summary?.trips}
                    >
                        Export PDF / Excel
                    </Button>
                </div>
                {filters.day === 'range' && (
                    <div className="trip-date-range">
                        <label>
                            From
                            <DatePicker
                                id="trip-from"
                                label="Trip date from"
                                value={filters.from}
                                invalid={invalidRange}
                                onChange={(value) =>
                                    updateFilters({ from: value })
                                }
                            />
                        </label>
                        <label>
                            To
                            <DatePicker
                                id="trip-to"
                                label="Trip date to"
                                value={filters.to}
                                invalid={invalidRange}
                                onChange={(value) =>
                                    updateFilters({ to: value })
                                }
                            />
                        </label>
                        {invalidRange && (
                            <p className="trip-range-error" role="alert">
                                The end date must be on or after the start date.
                            </p>
                        )}
                    </div>
                )}
            </div>

            <div
                className="journey-totals"
                aria-live="polite"
                aria-busy={listLoad === 'loading'}
            >
                <span>
                    <Route aria-hidden="true" />
                    {summary?.trips ?? 0}{' '}
                    {summary?.trips === 1 ? 'trip' : 'trips'}
                </span>
                <span>
                    <Navigation aria-hidden="true" />
                    {formatDistance(summary?.distance_km ?? 0)} estimated
                </span>
                <span>
                    <Clock3 aria-hidden="true" />
                    {tripMinutes(summary?.duration_s ?? 0).toLocaleString(
                        'en-NZ',
                    )}{' '}
                    min recorded
                </span>
                <span>
                    <Activity aria-hidden="true" />
                    {summary?.driving_events ?? 0} driving events
                </span>
            </div>

            {!list && listLoad === 'loading' ? (
                <LoadingState message="Loading trip history…" />
            ) : listLoad === 'forbidden' ? (
                <div className="studio-card telemetry-empty">
                    <Route size={35} aria-hidden="true" />
                    <h3>Trip history is not available to you</h3>
                    <p>
                        Your access to this vehicle&apos;s trips has changed.
                        Reload the vehicle to check what is available.
                    </p>
                </div>
            ) : !list && listLoad === 'error' ? (
                <ErrorState
                    title="Trip history could not be loaded"
                    message="Check your connection and try again."
                    onRetry={() => setReload((value) => value + 1)}
                />
            ) : list && list.data.length ? (
                <>
                    <div
                        className="journey-selector"
                        role="group"
                        aria-label="Select vehicle trip"
                    >
                        {list.data.map((trip) => {
                            const badge = tripBadge(trip);
                            return (
                                // eslint-disable-next-line no-restricted-syntax -- Trip selector card from the approved design.
                                <button
                                    key={trip.id}
                                    type="button"
                                    aria-pressed={trip.id === selectedId}
                                    onClick={() => choose(trip.id)}
                                >
                                    <div>
                                        <span>
                                            {formatDateOnly(trip.local_date)} ·{' '}
                                            {formatTime(trip.started_at)}
                                        </span>
                                        <StatusBadge variant={badge.variant}>
                                            {badge.label}
                                        </StatusBadge>
                                    </div>
                                    <strong>
                                        {startLabel(trip)}{' '}
                                        <ArrowRight
                                            size={13}
                                            aria-hidden="true"
                                        />
                                        <span className="sr-only">to</span>{' '}
                                        {endLabel(trip)}
                                    </strong>
                                    <small>
                                        <UserRound
                                            size={13}
                                            aria-hidden="true"
                                        />
                                        {driverName(trip.driver)}{' '}
                                        <span>
                                            {tripMinutes(trip.duration_s)} min ·{' '}
                                            {trip.reference}
                                        </span>
                                    </small>
                                </button>
                            );
                        })}
                    </div>
                    <div className="replay-controls">
                        <Button
                            variant="outline"
                            disabled={page <= 1 || listLoad === 'loading'}
                            onClick={() => setPage((value) => value - 1)}
                        >
                            Previous trips
                        </Button>
                        <span>
                            Page {list.meta.page} of {list.meta.last_page}
                        </span>
                        <Button
                            variant="outline"
                            disabled={
                                page >= list.meta.last_page ||
                                listLoad === 'loading'
                            }
                            onClick={() => setPage((value) => value + 1)}
                        >
                            Next trips
                        </Button>
                    </div>
                    {detailReady ? (
                        <>
                            <div className="journey-workspace">
                                <TripJourney
                                    detail={detail}
                                    panelRef={mapPanel}
                                    sample={sample}
                                    onSample={selectPoint}
                                    playing={playing}
                                    onTogglePlay={togglePlay}
                                    rate={rate}
                                    onRate={setRate}
                                    focusedEvent={focusedEvent}
                                    mapFocus={mapFocus}
                                />
                                <TripInsights
                                    detail={detail}
                                    sample={Math.min(
                                        sample,
                                        Math.max(0, pointCount - 1),
                                    )}
                                    canConfirmDriver={detail.can.confirm_driver}
                                    onConfirmDriver={() =>
                                        openTripDialog('driver')
                                    }
                                    onSource={() => openTripDialog('source')}
                                />
                            </div>
                            <div className="journey-bottom">
                                <TripTimeline
                                    detail={detail}
                                    point={sample}
                                    focusedEventKey={focusedEvent?.key ?? ''}
                                    filter={timelineFilter}
                                    onFilter={setTimelineFilter}
                                    onSelect={selectEvent}
                                />
                                <TripNext
                                    detail={detail}
                                    canCoach={canCoach}
                                    coachBlocked={coachBlocked}
                                    onCoach={() => openTripDialog('coach')}
                                    onOpenAlerts={() =>
                                        onNavigate({
                                            tab: 'map',
                                            view: 'alerts',
                                        })
                                    }
                                />
                            </div>
                        </>
                    ) : detailLoad === 'error' ? (
                        <ErrorState
                            title="This trip could not be loaded"
                            message="Check your connection and try again."
                            onRetry={() => setReload((value) => value + 1)}
                        />
                    ) : detailLoad === 'forbidden' ? (
                        <div className="studio-card telemetry-empty">
                            <Route size={35} aria-hidden="true" />
                            <h3>This trip is no longer available</h3>
                            <p>Choose another trip or reload the vehicle.</p>
                        </div>
                    ) : (
                        <div className="studio-card">
                            <LoadingState message="Loading the recorded journey…" />
                        </div>
                    )}
                </>
            ) : (
                <div className="studio-card telemetry-empty">
                    <Route size={35} aria-hidden="true" />
                    <h3>
                        {list && !list.has_trips
                            ? list.tracker_linked
                                ? 'No trips recorded yet'
                                : 'No tracker trip history'
                            : 'No matching trips'}
                    </h3>
                    <p>
                        {list && !list.has_trips
                            ? list.tracker_linked
                                ? 'Trips appear here once the tracker reports a journey.'
                                : 'Manual mileage and booking custody remain available.'
                            : 'Try another driver, route or date.'}
                    </p>
                </div>
            )}
        </div>
    );
}
