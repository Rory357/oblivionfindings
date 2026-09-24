import type { StatusVariant } from '@/components/ui/status-badge';
import { formatDateOnly } from '@/lib/datetime';
import type {
    TripBehaviour,
    TripDriver,
    TripEnd,
    TripEvent,
    TripEventFilter,
    TripListItem,
    TripListResponse,
    TripPoint,
    TripPolicy,
} from './trip-types';

/** The approved trip selector shows two trips per page. */
export const TRIPS_PER_PAGE = 2;

/**
 * The most days between the first and last date of an export
 * (VehicleTripHistoryService::MAX_RANGE_DAYS, a year).
 */
export const MAX_RANGE_DAYS = 366;

export const EVENT_FILTERS: Array<{ value: TripEventFilter; label: string }> = [
    { value: 'all', label: 'All types' },
    { value: 'overspeed', label: 'Overspeed' },
    { value: 'faults', label: 'Vehicle faults' },
    { value: 'partial', label: 'Partial coverage' },
];

export type TimelineFilter = 'all' | 'driving' | 'faults';

export const TIMELINE_FILTERS: Array<{ value: TimelineFilter; label: string }> =
    [
        { value: 'all', label: 'All events' },
        { value: 'driving', label: 'Driving' },
        { value: 'faults', label: 'Vehicle faults' },
    ];

/** 'all' (every recorded date), 'range' (custom) or one YYYY-MM-DD day. */
export type DayChoice = string;

export type TripFilterState = {
    q: string;
    day: DayChoice;
    from: string;
    to: string;
    driver: string;
    event: TripEventFilter;
};

export const DEFAULT_TRIP_FILTERS: TripFilterState = {
    q: '',
    day: 'all',
    from: '',
    to: '',
    driver: 'all',
    event: 'all',
};

export const isDay = (value: string) => /^\d{4}-\d{2}-\d{2}$/.test(value);

export function tripHistoryUrl(vehicleId: number, path = ''): string {
    return `/fleet-assets/vehicles/${vehicleId}/trip-history${path}`;
}

/** The inclusive local date range the server should filter by. */
export function filterRange(filters: TripFilterState): {
    from: string | null;
    to: string | null;
} {
    if (filters.day === 'range')
        return { from: filters.from || null, to: filters.to || null };
    if (isDay(filters.day)) return { from: filters.day, to: filters.day };
    return { from: null, to: null };
}

export function rangeInvalid(filters: TripFilterState): boolean {
    return (
        filters.day === 'range' &&
        !!filters.from &&
        !!filters.to &&
        filters.to < filters.from
    );
}

/** Whole days from one YYYY-MM-DD date to another. */
export function daysBetween(from: string, to: string): number {
    return Math.round(
        (Date.parse(`${to}T00:00:00Z`) - Date.parse(`${from}T00:00:00Z`)) /
            86_400_000,
    );
}

/**
 * What to say under the totals when the list holds fewer trips than the
 * filters ask for: "All recorded dates" covers the latest days of trips, a
 * range covers at most a year, and one list reads a limited number of trips.
 */
export function tripWindowNotice(
    list: Pick<TripListResponse, 'window' | 'truncated' | 'limits'>,
): { title: string; body: string } | null {
    const { truncated, limits } = list;
    const read = list.window;
    const from = formatDateOnly(read.from);
    if (truncated) {
        const trips = limits.max_trips.toLocaleString('en-NZ');
        return {
            title: `Showing the latest ${trips} trips`,
            body: `These dates have more than ${trips} trips, so the list and its totals include only the ${trips} most recent. To see earlier trips, choose a shorter date range.`,
        };
    }
    if (read.limited === 'range')
        return {
            title: 'Showing one year of trips',
            body: `Trip history shows up to a year at a time, so this list starts on ${from}. To see earlier trips, choose a date range of a year or less.`,
        };
    if (read.limited === 'recent' && read.earlier_trips)
        return {
            title: `Showing the latest ${limits.default_days} days of trips`,
            body: `All recorded dates covers the ${limits.default_days} days up to the latest trip, from ${from}. To see earlier trips, choose a custom date range.`,
        };
    return null;
}

export function tripQuery(
    filters: TripFilterState,
    extra: Record<string, string | number | undefined> = {},
): URLSearchParams {
    const params = new URLSearchParams();
    const { from, to } = filterRange(filters);
    if (filters.q.trim()) params.set('q', filters.q.trim());
    if (from) params.set('from', from);
    if (to) params.set('to', to);
    if (filters.driver !== 'all') params.set('driver', filters.driver);
    if (filters.event !== 'all') params.set('event', filters.event);
    Object.entries(extra).forEach(([key, value]) => {
        if (value !== undefined && value !== '') params.set(key, String(value));
    });
    return params;
}

/** Recent trip days, keeping a focused day that is not among them (newest first). */
export function dayOptions(recentDays: string[], current: DayChoice): string[] {
    const days = [...recentDays];
    if (isDay(current) && !days.includes(current)) days.push(current);
    return days.sort((a, b) => b.localeCompare(a));
}

export function formatDistance(km: number | null | undefined): string {
    if (km === null || km === undefined || !Number.isFinite(km))
        return 'Not recorded';
    return `${km.toLocaleString('en-NZ', { maximumFractionDigits: 1 })} km`;
}

export function tripMinutes(durationSeconds: number): number {
    return Math.max(0, Math.round(durationSeconds / 60));
}

export function formatSpeed(kph: number | null | undefined): string {
    return kph === null || kph === undefined || !Number.isFinite(kph)
        ? 'Not reported'
        : `${Math.round(kph)} km/h`;
}

export function formatIdle(minutes: number | null): string {
    if (minutes === null) return 'Not reported';
    return `${minutes.toLocaleString('en-NZ', { maximumFractionDigits: 1 })} min`;
}

export function plural(count: number, one: string, many = `${one}s`) {
    return `${count.toLocaleString('en-NZ')} ${count === 1 ? one : many}`;
}

/** The card badge: privacy first, then state, then the trail or distance. */
export function tripBadge(trip: TripListItem): {
    label: string;
    variant: StatusVariant;
} {
    if (trip.consent_blocked)
        return { label: 'Location withheld', variant: 'neutral' };
    if (trip.is_personal) return { label: 'Personal trip', variant: 'neutral' };
    if (trip.in_progress) return { label: 'In progress', variant: 'info' };
    if (trip.partial) return { label: 'Partial trail', variant: 'warning' };
    return { label: formatDistance(trip.distance_km), variant: 'info' };
}

/** Personal and consent-restricted trips never carry their places. */
function withheld(trip: {
    is_personal?: boolean;
    consent_blocked?: boolean;
}): boolean {
    return !!trip.is_personal || !!trip.consent_blocked;
}

export function startLabel(trip: {
    from: string | null;
    is_personal?: boolean;
    consent_blocked?: boolean;
}): string {
    if (withheld(trip)) return 'Location withheld';
    return trip.from ?? 'Start position';
}

export function endLabel(trip: {
    to: string | null;
    in_progress: boolean;
    is_personal?: boolean;
    consent_blocked?: boolean;
}): string {
    if (withheld(trip)) return 'Location withheld';
    return trip.to ?? (trip.in_progress ? 'Trip in progress' : 'End position');
}

export function driverName(driver: TripDriver): string {
    if (driver.name) return driver.name;
    return driver.state === 'hidden' ? 'Driver not shown' : 'Unassigned';
}

export function driverCaption(driver: TripDriver): string {
    switch (driver.state) {
        case 'confirmed':
            if (driver.attribution === 'handover')
                return 'Confirmed driver · handover from the booking';
            if (driver.attribution === 'booking')
                return 'Confirmed driver · matches the booking';
            return 'Confirmed driver';
        case 'recorded':
            return driver.source === 'session'
                ? 'Signed in to the vehicle · actual driver unconfirmed'
                : 'Recorded assignment · actual driver unconfirmed';
        case 'hidden':
            return 'A driver is recorded; their name shows only at their sites';
        default:
            return 'Driver confirmation needed';
    }
}

export function coordinates(point: { lat: number; lng: number }): string {
    return `${point.lat.toFixed(5)}, ${point.lng.toFixed(5)}`;
}

/** An address when one was looked up for the position, never an invented place. */
export function pointLocation(point: TripPoint | undefined): string {
    if (!point) return 'No recorded position';
    return point.address ?? 'Recorded position';
}

export function scoreHeadline(behaviour: TripBehaviour): string {
    if (behaviour.score === null)
        return ['personal', 'consent'].includes(behaviour.score_state)
            ? 'Not scored'
            : 'Score withheld';
    return behaviour.driving_events
        ? 'Review driving events'
        : 'No harsh events recorded';
}

export function scoreCaption(
    behaviour: TripBehaviour,
    policy: TripPolicy,
): string {
    const coverage =
        behaviour.coverage_pct === null
            ? 'Coverage not recorded'
            : `${behaviour.coverage_pct}% sample coverage`;
    const reason: Record<TripBehaviour['score_state'], string> = {
        scored: 'scored with the fleet settings',
        coverage: `score withheld below ${policy.min_score_coverage_pct}%`,
        no_samples: 'too few recorded positions',
        in_progress: 'trip still in progress',
        personal: 'personal trip, not scored',
        consent: 'tracking consent not in place',
        disputed: 'withheld while an event is disputed',
    };
    return `${coverage} · ${reason[behaviour.score_state]}`;
}

/** Plays a whole trip in about 30 seconds; short trips keep the approved 1.5 s step. */
export function playbackDelay(points: number, rate: number): number {
    const base = Math.max(120, Math.min(1500, 30000 / Math.max(1, points)));
    return Math.round(base / Math.max(1, rate));
}

export function timelineMatches(
    event: TripEvent,
    filter: TimelineFilter,
): boolean {
    if (filter === 'driving')
        return event.kind === 'driving' || event.kind === 'speed';
    if (filter === 'faults') return event.kind === 'power';
    return true;
}

export function nextStepTitle(behaviour: TripBehaviour): string {
    if (behaviour.faults > 0) return 'Review the vehicle fault';
    if (behaviour.overspeed_episodes > 0) return 'Review the overspeed event';
    return 'Follow up this journey';
}

export function endpointCaption(
    end: TripEnd,
    time: string,
    stopAfterMinutes: number,
    which: 'start' | 'end',
): string {
    if (end.trigger === 'in_progress') return 'Trip in progress';
    if (which === 'start')
        return end.trigger === 'ignition'
            ? `${time} · ignition on`
            : `${time} · started when the vehicle moved`;
    return end.trigger === 'ignition'
        ? `${time} · ignition off`
        : `${time} · stopped for ${stopAfterMinutes} min`;
}

export type SpeedChart = {
    lines: string[];
    dots: Array<{ cx: number; cy: number; index: number }>;
    selected: { cx: number; cy: number } | null;
    threshold: { y: number; label: string } | null;
    samples: number;
    maxSpeed: number | null;
};

const CHART_LEFT = 15;
const CHART_WIDTH = 290;
const CHART_BASE = 85;
const CHART_HEIGHT = 75;

/**
 * Geometry for the "Speed through the trip" chart (viewBox 320 × 105).
 * Time runs left to right; the line breaks where reports are missing.
 */
export function speedChart(
    points: TripPoint[],
    selected: number,
    policy: TripPolicy,
): SpeedChart {
    const times = points.map((point) => Date.parse(point.at));
    const samples = points
        .map((point, index) => ({ index, t: times[index], v: point.speed_kph }))
        .filter(
            (sample): sample is { index: number; t: number; v: number } =>
                sample.v !== null && Number.isFinite(sample.t),
        );
    if (!samples.length)
        return {
            lines: [],
            dots: [],
            selected: null,
            threshold: null,
            samples: 0,
            maxSpeed: null,
        };
    const first = Math.min(...times.filter(Number.isFinite));
    const last = Math.max(...times.filter(Number.isFinite));
    const span = Math.max(1, last - first);
    const maxSpeed = Math.max(...samples.map((sample) => sample.v));
    const limit = policy.speed_threshold_kph;
    const showThreshold = maxSpeed >= limit * 0.8;
    const scaleMax = Math.max(
        60,
        maxSpeed + 10,
        showThreshold ? limit + 10 : 0,
    );
    const x = (t: number) =>
        Math.round((CHART_LEFT + (CHART_WIDTH * (t - first)) / span) * 10) / 10;
    const y = (v: number) =>
        Math.round((CHART_BASE - (v * CHART_HEIGHT) / scaleMax) * 10) / 10;
    const gap = policy.coverage_gap_seconds * 1000;

    const lines: string[] = [];
    let current: string[] = [];
    samples.forEach((sample, position) => {
        const previous = samples[position - 1];
        if (previous && sample.t - previous.t > gap && current.length) {
            lines.push(current.join(' '));
            current = [];
        }
        current.push(`${x(sample.t)},${y(sample.v)}`);
    });
    if (current.length) lines.push(current.join(' '));

    const chosen = samples.find((sample) => sample.index === selected);
    return {
        lines,
        dots:
            samples.length <= 40
                ? samples.map((sample) => ({
                      cx: x(sample.t),
                      cy: y(sample.v),
                      index: sample.index,
                  }))
                : [],
        selected: chosen ? { cx: x(chosen.t), cy: y(chosen.v) } : null,
        threshold: showThreshold
            ? {
                  y: y(limit),
                  label: `Fleet threshold ${Math.round(limit)} km/h`,
              }
            : null,
        samples: samples.length,
        maxSpeed,
    };
}

/** The download name from a Content-Disposition header. */
export function dispositionFilename(
    header: string | null,
    fallback: string,
): string {
    if (!header) return fallback;
    const encoded = /filename\*=UTF-8''([^;]+)/i.exec(header);
    if (encoded) {
        try {
            return decodeURIComponent(encoded[1].trim());
        } catch {
            // Fall through to the plain filename.
        }
    }
    const plain = /filename="?([^";]+)"?/i.exec(header);
    return plain ? plain[1].trim() : fallback;
}

/** Per-browser memory of the filters; storage may be unavailable. */
export function readStored<T>(key: string, fallback: T): T {
    try {
        const raw = window.sessionStorage.getItem(key);
        if (!raw) return fallback;
        const parsed: unknown = JSON.parse(raw);
        if (
            fallback &&
            typeof fallback === 'object' &&
            parsed &&
            typeof parsed === 'object'
        )
            return { ...fallback, ...parsed };
        return typeof parsed === typeof fallback ? (parsed as T) : fallback;
    } catch {
        return fallback;
    }
}

export function writeStored(key: string, value: unknown): void {
    try {
        window.sessionStorage.setItem(key, JSON.stringify(value));
    } catch {
        // Private windows can refuse storage; the choice still applies now.
    }
}
