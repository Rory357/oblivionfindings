import type { ZoneSchedule } from '@/components/client-location/types';
import type { MapGeofence } from '@/components/leaflet-map';
import {
    formatDateOnly,
    formatTime,
    toDatetimeLocal,
    WORKER_TIMEZONE,
} from '@/lib/datetime';
import type {
    LinkedGeofence,
    Observation,
    SharedBoundary,
    TelemetrySample,
    TelemetryTracker,
    VehicleLocation,
    Withheld,
} from './map-types';

/** "21 Sep 2026 · 8:12 am" in Pacific/Auckland, as the design shows times. */
export function observedLabel(value: string | null | undefined): string {
    if (!value) return 'Time not recorded';
    const local = toDatetimeLocal(value);
    if (!local) return 'Time not recorded';
    return `${formatDateOnly(local.slice(0, 10))} · ${formatTime(value)}`;
}

/** NZST or NZDT for the moment a position was recorded. */
export function zoneAbbreviation(value: string | null | undefined): string {
    if (!value) return 'Pacific/Auckland';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return 'Pacific/Auckland';
    const part = new Intl.DateTimeFormat('en-NZ', {
        timeZone: WORKER_TIMEZONE,
        timeZoneName: 'short',
    })
        .formatToParts(date)
        .find((entry) => entry.type === 'timeZoneName')?.value;
    return part && /^NZ[SD]T$/.test(part) ? part : 'Pacific/Auckland';
}

export const WITHHELD_TEXT: Record<Exclude<Withheld, null>, string> = {
    consent: 'Tracking consent is not in place, so no position is kept.',
    personal: 'Recorded during a personal trip, so the position is withheld.',
    access: 'Recorded positions need trip access for this vehicle’s site.',
};

export type ReportedFacts = {
    /** The selected observation is the latest tracker report. */
    current: boolean;
    /** The latest report is recent enough to describe the vehicle now. */
    fresh: boolean;
    noTracker: boolean;
    stateLabel: string;
    motion: 'Moving' | 'Stationary' | 'Unknown';
    ignition: 'On' | 'Off' | 'Unknown';
    speed: string;
    voltage: string;
    signal: 'Reporting' | 'Stale' | 'Historic' | 'None';
    trackerBadge: { label: string; variant: 'success' | 'warning' | 'neutral' };
    source: string;
    position: { lat: number; lng: number } | null;
    withheld: Withheld;
    reference: string;
};

/**
 * What the design's reported-state inspector shows for one observation.
 * Unknown stays unknown: a stale report can't prove ignition or motion, and
 * a historical position says nothing about the vehicle now.
 */
export function reportedFacts(
    location: VehicleLocation,
    observation: Observation | null,
): ReportedFacts {
    const state = location.state;
    const noTracker = !location.tracker.linked && state === null;
    const current = observation === null || observation.kind === 'latest';
    const fresh = !!state?.fresh;
    const live = !noTracker && current && fresh;
    const motion: ReportedFacts['motion'] = noTracker
        ? 'Unknown'
        : !current
          ? 'Stationary'
          : !fresh || !state?.motion
            ? 'Unknown'
            : state.motion === 'moving'
              ? 'Moving'
              : 'Stationary';
    const ignition: ReportedFacts['ignition'] =
        !live || state?.ignition === null || state?.ignition === undefined
            ? 'Unknown'
            : state.ignition
              ? 'On'
              : 'Off';
    const speed =
        live && state?.speed_kph !== null && state?.speed_kph !== undefined
            ? `${Math.round(state.speed_kph)} km/h`
            : '—';
    const position =
        observation && observation.lat !== null && observation.lng !== null
            ? { lat: observation.lat, lng: observation.lng }
            : null;
    return {
        current,
        fresh,
        noTracker,
        stateLabel: noTracker
            ? 'No tracker'
            : current
              ? fresh
                  ? 'Current sample'
                  : 'Last known · stale'
              : 'Historical position',
        motion,
        ignition,
        speed,
        // Supply voltage isn't part of the recorded reports.
        voltage: '—',
        signal: noTracker
            ? 'None'
            : !current
              ? 'Historic'
              : fresh
                ? 'Reporting'
                : 'Stale',
        trackerBadge: noTracker
            ? { label: 'None', variant: 'neutral' }
            : fresh
              ? { label: 'Reporting', variant: 'success' }
              : { label: 'Stale', variant: 'warning' },
        source: current
            ? 'Tracker report · sample-based state'
            : 'Historical observation · live state unknown',
        position,
        withheld: current ? (state?.withheld ?? null) : null,
        reference:
            observation?.kind === 'trip_end' && observation.trip_id
                ? `Trip #${observation.trip_id} · end of trip`
                : state?.event_id
                  ? `Tracker report #${state.event_id}`
                  : 'Tracker report',
    };
}

/** Where to centre: the chosen position, else the home site. */
export function mapCentre(
    location: VehicleLocation,
    facts: ReportedFacts,
): { lat: number; lng: number } | null {
    if (facts.position) return facts.position;
    const site = location.vehicle.home_site;
    return site && site.lat !== null && site.lng !== null
        ? { lat: site.lat, lng: site.lng }
        : null;
}

export function scopeLabel(boundary: SharedBoundary | null): string {
    if (!boundary) return 'Boundary not available';
    if (boundary.site) return `Site · ${boundary.site.name}`;
    if (boundary.vehicle) return `Vehicle · ${boundary.vehicle.name}`;
    return 'Shared boundary';
}

export const MONITORING_LABELS: Record<LinkedGeofence['monitoring'], string> = {
    inactive: 'Inactive',
    on: 'Monitoring on',
    paused: 'Monitoring paused',
};

/** The design's second line under a linked geofence. */
export function linkedSummary(item: LinkedGeofence): string {
    const status =
        item.source_state === 'removed'
            ? 'Boundary removed · review'
            : item.source_state === 'restricted'
              ? 'Boundary not available to you'
              : item.source_state === 'changed'
                ? 'Boundary changed · review'
                : MONITORING_LABELS[item.monitoring];
    return item.schedule
        ? `${item.schedule.start}–${item.schedule.end}${item.schedule.following_day ? ' next day' : ''} · ${status}`
        : `${scopeLabel(item.boundary)} · ${status}`;
}

/** Linked boundaries as map overlays (coloured over the greyscale tiles). */
export function geofenceOverlays(items: LinkedGeofence[]): MapGeofence[] {
    return items.flatMap((item) => {
        const shape = item.boundary?.geometry;
        if (!shape) return [];
        const color =
            item.monitoring === 'on' ? 'var(--status-info)' : 'var(--primary)';
        return [
            shape.type === 'circle'
                ? {
                      id: item.key,
                      name: item.label,
                      type: 'circle' as const,
                      center: shape.center,
                      radius_m: shape.radius_m,
                      color,
                  }
                : {
                      id: item.key,
                      name: item.label,
                      type: 'polygon' as const,
                      coordinates: shape.coordinates,
                      color,
                  },
        ];
    });
}

/* ── Schedules (Client Location contract: ISO weekdays, 1 = Monday) ─────── */

/** The design lists the week from Sunday. */
export const WEEKDAY_BUTTONS: Array<{ day: number; label: string }> = [
    { day: 7, label: 'Sun' },
    { day: 1, label: 'Mon' },
    { day: 2, label: 'Tue' },
    { day: 3, label: 'Wed' },
    { day: 4, label: 'Thu' },
    { day: 5, label: 'Fri' },
    { day: 6, label: 'Sat' },
];

export function weekdayNames(days: number[]): string {
    return WEEKDAY_BUTTONS.filter((entry) => days.includes(entry.day))
        .map((entry) => entry.label)
        .join(', ');
}

/** ISO weekday (1 = Monday) of a YYYY-MM-DD date. */
export function isoWeekday(date: string): number {
    const [year, month, day] = date.split('-').map(Number);
    const weekday = new Date(Date.UTC(year, month - 1, day)).getUTCDay();
    return weekday === 0 ? 7 : weekday;
}

export function scheduleError(schedule: ZoneSchedule): string {
    if (!schedule.weekdays.length) return 'Choose at least one scheduled day.';
    if (!schedule.start || !schedule.end) return 'Choose start and end times.';
    if (!schedule.following_day && schedule.end <= schedule.start)
        return 'For overnight hours, select Ends the following day.';
    if (schedule.following_day && schedule.end > schedule.start)
        return 'Overnight windows must finish on or before the starting clock time.';
    if (
        !schedule.first_date ||
        !schedule.last_date ||
        schedule.last_date < schedule.first_date
    )
        return 'Choose a valid first and last date.';
    if (
        schedule.exception_dates.some(
            (date) => date < schedule.first_date || date > schedule.last_date,
        )
    )
        return 'Exception dates must fall within the schedule.';
    if (
        schedule.exception_dates.some(
            (date) => !schedule.weekdays.includes(isoWeekday(date)),
        )
    )
        return 'An exception must fall on a selected scheduled day.';
    return '';
}

/* ── Telemetry ────────────────────────────────────────────────────────── */

const EVENT_LABELS: Record<string, string> = {
    location_report: 'Location report',
    fri: 'Location report',
    heartbeat: 'Heartbeat',
    hbd: 'Heartbeat',
    ignition_on: 'Ignition on',
    ignition_off: 'Ignition off',
    motion_start: 'Movement started',
    motion_stop: 'Stopped',
    external_power: 'Power report',
    power_on: 'Power connected',
    power_off: 'Power disconnected',
    battery_low: 'Low tracker battery',
    charging_started: 'Tracker charging',
    charging_stopped: 'Tracker charging stopped',
    speed_alarm: 'Speed alarm',
    harsh_behaviour: 'Harsh driving event',
    tamper: 'Towing or tamper alarm',
    geofence_enter: 'Geofence entered',
    geofence_exit: 'Geofence exited',
    vehicle_sos: 'Emergency button',
};

export function eventLabel(type: string | null): string {
    if (!type) return 'Tracker report';
    return (
        EVENT_LABELS[type] ??
        type.charAt(0).toUpperCase() + type.slice(1).replace(/_/g, ' ')
    );
}

export type TelemetryTile = {
    key: string;
    label: string;
    value: string;
    caption: string;
    current: boolean;
};

const km = (value: number) =>
    `${value.toLocaleString('en-NZ', { maximumFractionDigits: 1 })} km`;

/**
 * The design's six telemetry tiles for one recorded sample. A stale latest
 * report leaves ignition and motion unknown; an older sample is shown as
 * what it recorded, marked as not current.
 */
export function telemetryTiles(
    sample: TelemetrySample | null,
    currentSampleId: number | null,
    latestId: number | null,
    tracker: TelemetryTracker | null,
): TelemetryTile[] {
    const unavailable = sample === null && tracker === null;
    const current = !!sample && sample.id === currentSampleId;
    const latestStale = !!sample && sample.id === latestId && !current;
    const known = !!sample && !latestStale;
    const gv500 = tracker?.family === 'gv500cg';
    const ignition = !known
        ? 'Unknown'
        : sample!.ignition === null
          ? 'Unknown'
          : sample!.ignition
            ? 'On'
            : 'Off';
    const motion = !known
        ? 'Unknown'
        : sample!.motion === 'moving'
          ? 'Moving'
          : sample!.motion === 'stationary'
            ? 'Stationary'
            : 'Unknown';
    const connection = unavailable
        ? 'Not assigned'
        : !sample
          ? 'No reports yet'
          : sample.id !== latestId
            ? 'Historic'
            : !current
              ? 'Overdue'
              : sample.external_power === false
                ? 'On backup'
                : 'Reporting';
    return [
        {
            key: 'ignition',
            label: 'Ignition',
            value: ignition,
            caption: gv500
                ? 'Virtual ignition · inferred'
                : 'Reported ignition · inferred by the tracker',
            current,
        },
        {
            key: 'motion',
            label: 'Motion',
            value: motion,
            caption:
                known && sample!.speed_kph !== null
                    ? `${Math.round(sample!.speed_kph)} km/h · GNSS`
                    : sample?.withheld
                      ? 'Speed withheld for this sample'
                      : 'Current movement not known',
            current,
        },
        {
            key: 'voltage',
            label: 'Vehicle voltage',
            value: '—',
            caption:
                sample?.external_power === true
                    ? 'External supply connected · voltage not in reports'
                    : sample?.external_power === false
                      ? 'External supply not detected · voltage not in reports'
                      : 'External supply · voltage not in reports',
            current,
        },
        {
            key: 'backup',
            label: 'Tracker backup',
            value:
                sample?.battery_pct !== null &&
                sample?.battery_pct !== undefined
                    ? `${sample.battery_pct}%`
                    : '—',
            caption: 'Internal tracker battery · separate from vehicle',
            current,
        },
        {
            key: 'connection',
            label: 'Connection',
            value: connection,
            caption: unavailable
                ? 'Assign and validate a device'
                : [tracker?.name ?? 'Vehicle tracker', tracker?.model]
                      .filter(Boolean)
                      .join(' · '),
            current,
        },
        {
            key: 'distance',
            label: 'Distance counter',
            value:
                sample?.odometer_km !== null &&
                sample?.odometer_km !== undefined
                    ? km(sample.odometer_km)
                    : '—',
            caption:
                sample?.withheld && sample.odometer_km === null
                    ? 'Withheld for this sample'
                    : 'Tracker cumulative distance · not dashboard OBD',
            current,
        },
    ];
}

/** The model capability list, only for an exact reviewed model. */
export const GV500CG_CAPABILITIES: Array<[string, string]> = [
    [
        'Location & trip reconstruction',
        'GNSS points, speed and tracker distance',
    ],
    [
        'Virtual ignition & motion',
        'Derived state; preserve method and confidence',
    ],
    [
        'Crash & driving events',
        'Requires device configuration and verified decoding',
    ],
    [
        'BLE 5.2 accessories',
        'Additional supported hardware and pairing required',
    ],
    [
        'Buffered reports & OTA',
        'Late reports retain observed time; configuration needs device acknowledgement',
    ],
];
