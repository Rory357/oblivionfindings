import type { ZoneSchedule } from '@/components/client-location/types';
import { mapMarkerStatsHtml } from '@/components/leaflet-map';
import { describe, expect, it } from 'vitest';
import {
    geofenceOverlays,
    isoWeekday,
    linkedSummary,
    mapCentre,
    observedLabel,
    reportedFacts,
    sampleLabel,
    scheduleError,
    telemetryTiles,
    weekdayNames,
    zoneAbbreviation,
} from './map-model';
import type {
    LinkedGeofence,
    Observation,
    ReportedState,
    TelemetrySample,
    TelemetryTracker,
    VehicleLocation,
} from './map-types';

const state = (overrides: Partial<ReportedState> = {}): ReportedState => ({
    event_id: 41,
    observed_at: '2026-09-22T21:12:00Z',
    received_at: '2026-09-22T21:12:03Z',
    lat: -41.2865,
    lng: 174.7762,
    withheld: null,
    speed_kph: 42.4,
    heading_deg: 90,
    ignition: true,
    motion: 'moving',
    battery_pct: 92,
    external_power: true,
    status: 'online',
    fresh: true,
    trip_id: 12,
    ...overrides,
});

const latest = (overrides: Partial<Observation> = {}): Observation => ({
    id: 'current',
    kind: 'latest',
    observed_at: '2026-09-22T21:12:00Z',
    lat: -41.2865,
    lng: 174.7762,
    address: null,
    trip_id: 12,
    ...overrides,
});

const location = (
    overrides: Partial<VehicleLocation> = {},
): VehicleLocation => ({
    as_of: '2026-09-22T21:15:00Z',
    timezone: 'Pacific/Auckland',
    fresh_minutes: 15,
    vehicle: {
        id: 14,
        name: 'Kōwhai van',
        registration_number: 'KWH014',
        asset_tag: 'VH-014',
        home_site: {
            id: 3,
            name: 'Kōwhai House',
            lat: -41.2838,
            lng: 174.7743,
        },
    },
    tracker: { linked: true },
    positions_visible: true,
    state: state(),
    observations: [latest()],
    alerts: { open: 1 },
    geofences: {
        items: [],
        version: 'x'.repeat(64),
        owner_site: { id: 3, name: 'Kōwhai House' },
        can: { manage: true },
    },
    can: { view_telemetry: true, view_alerts: true, add_reminder: true },
    ...overrides,
});

describe('reported state', () => {
    it('shows a fresh report as the current sample', () => {
        const facts = reportedFacts(location(), latest());
        expect(facts.stateLabel).toBe('Current sample');
        expect(facts.ignition).toBe('On');
        expect(facts.motion).toBe('Moving');
        expect(facts.speed).toBe('42 km/h');
        expect(facts.signal).toBe('Reporting');
        expect(facts.trackerBadge.variant).toBe('success');
        expect(facts.reference).toBe('Tracker report #41');
        // Supply voltage isn't in the recorded reports.
        expect(facts.voltage).toBe('—');
    });

    it('keeps ignition and motion unknown when the last report is stale', () => {
        const facts = reportedFacts(
            location({ state: state({ fresh: false }) }),
            latest(),
        );
        expect(facts.stateLabel).toBe('Last known · stale');
        expect(facts.ignition).toBe('Unknown');
        expect(facts.motion).toBe('Unknown');
        expect(facts.speed).toBe('—');
        expect(facts.signal).toBe('Stale');
        expect(facts.trackerBadge.label).toBe('Stale');
    });

    it('never presents a historical position as the vehicle now', () => {
        const tripEnd: Observation = {
            id: 'trip:11',
            kind: 'trip_end',
            observed_at: '2026-09-20T05:08:00Z',
            lat: -41.2838,
            lng: 174.7743,
            address: 'Kōwhai House',
            trip_id: 11,
        };
        const facts = reportedFacts(location(), tripEnd);
        expect(facts.stateLabel).toBe('Historical position');
        expect(facts.ignition).toBe('Unknown');
        expect(facts.speed).toBe('—');
        expect(facts.signal).toBe('Historic');
        expect(facts.source).toBe(
            'Historical observation · live state unknown',
        );
        expect(facts.reference).toBe('Trip #11 · end of trip');
        expect(facts.position).toEqual({ lat: -41.2838, lng: 174.7743 });
    });

    it('shows no tracker and centres on the home site without a position', () => {
        const none = location({
            tracker: { linked: false },
            state: null,
            observations: [],
        });
        const facts = reportedFacts(none, null);
        expect(facts.noTracker).toBe(true);
        expect(facts.stateLabel).toBe('No tracker');
        expect(facts.signal).toBe('None');
        expect(mapCentre(none, facts)).toEqual({
            lat: -41.2838,
            lng: 174.7743,
        });
    });

    it('explains a withheld position instead of showing one', () => {
        const withheld = location({
            state: state({ lat: null, lng: null, withheld: 'personal' }),
            observations: [latest({ lat: null, lng: null })],
        });
        const facts = reportedFacts(withheld, withheld.observations[0]);
        expect(facts.position).toBeNull();
        expect(facts.withheld).toBe('personal');
        // Even if a report carried them, a withheld report shows no journey.
        expect(facts.ignition).toBe('Unknown');
        expect(facts.motion).toBe('Unknown');
        expect(facts.speed).toBe('—');
        // The tracker itself is still reported.
        expect(facts.signal).toBe('Reporting');
    });

    it('formats observation times in Auckland with the right zone', () => {
        expect(observedLabel('2026-09-22T21:12:00Z')).toBe(
            '23 Sep 2026 · 9:12 am',
        );
        expect(observedLabel(null)).toBe('Time not recorded');
        expect(zoneAbbreviation('2026-07-01T00:00:00Z')).toBe('NZST');
        expect(zoneAbbreviation('2026-12-01T00:00:00Z')).toBe('NZDT');
    });
});

const schedule = (overrides: Partial<ZoneSchedule> = {}): ZoneSchedule => ({
    timezone: 'Pacific/Auckland',
    weekdays: [1, 2, 3, 4, 5],
    start: '08:00',
    end: '18:00',
    following_day: false,
    first_date: '2026-09-22',
    last_date: '2026-12-31',
    exception_dates: [],
    ...overrides,
});

describe('geofence schedules', () => {
    it('uses ISO weekdays and lists them from Sunday', () => {
        expect(isoWeekday('2026-09-27')).toBe(7);
        expect(isoWeekday('2026-09-28')).toBe(1);
        expect(weekdayNames([1, 7, 3])).toBe('Sun, Mon, Wed');
    });

    it('needs an explicit following-day finish for overnight hours', () => {
        expect(scheduleError(schedule())).toBe('');
        expect(scheduleError(schedule({ start: '22:00', end: '06:00' }))).toBe(
            'For overnight hours, select Ends the following day.',
        );
        expect(
            scheduleError(
                schedule({ start: '22:00', end: '06:00', following_day: true }),
            ),
        ).toBe('');
        expect(
            scheduleError(schedule({ end: '23:00', following_day: true })),
        ).toBe(
            'Overnight windows must finish on or before the starting clock time.',
        );
    });

    it('keeps exceptions inside the schedule and on scheduled days', () => {
        expect(scheduleError(schedule({ weekdays: [] }))).toBe(
            'Choose at least one scheduled day.',
        );
        expect(
            scheduleError(schedule({ exception_dates: ['2027-01-04'] })),
        ).toBe('Exception dates must fall within the schedule.');
        // 27 September 2026 is a Sunday, which isn't scheduled.
        expect(
            scheduleError(schedule({ exception_dates: ['2026-09-27'] })),
        ).toBe('An exception must fall on a selected scheduled day.');
        expect(
            scheduleError(schedule({ exception_dates: ['2026-09-28'] })),
        ).toBe('');
    });
});

const linked = (overrides: Partial<LinkedGeofence> = {}): LinkedGeofence => ({
    key: 'assignment:1',
    assignment_id: 1,
    geofence_id: 7,
    label: 'Kōwhai House grounds',
    origin: 'linked',
    boundary: {
        id: 7,
        name: 'Kōwhai House grounds',
        type: 'circle',
        scope: 'house',
        site: { id: 3, name: 'Kōwhai House' },
        vehicle: null,
        geometry: {
            type: 'circle',
            center: { lat: -41.2838, lng: 174.7743 },
            radius_m: 160,
        },
        hash: 'h'.repeat(64),
    },
    source_state: 'current',
    purpose: null,
    response_proposal: null,
    schedule: null,
    monitoring: 'inactive',
    lock_version: 1,
    saved_at: null,
    saved_by: null,
    ...overrides,
});

describe('linked geofences', () => {
    it('summarises the schedule or the owning site with the monitoring state', () => {
        expect(linkedSummary(linked())).toBe('Site · Kōwhai House · Inactive');
        expect(linkedSummary(linked({ schedule: schedule() }))).toBe(
            '08:00–18:00 · Inactive',
        );
        expect(linkedSummary(linked({ source_state: 'changed' }))).toBe(
            'Site · Kōwhai House · Boundary changed · review',
        );
        expect(
            linkedSummary(linked({ boundary: null, source_state: 'removed' })),
        ).toBe('Boundary not available · Boundary removed · review');
    });

    it('draws only boundaries the viewer can see, monitored ones in a different colour', () => {
        const overlays = geofenceOverlays([
            linked(),
            linked({ key: 'fleet:9', monitoring: 'on' }),
            linked({ key: 'assignment:3', boundary: null }),
        ]);
        expect(overlays).toHaveLength(2);
        expect(overlays[0]).toMatchObject({
            id: 'assignment:1',
            type: 'circle',
            radius_m: 160,
            color: 'var(--primary)',
        });
        expect(overlays[1].color).toBe('var(--status-info)');
    });
});

const sample = (overrides: Partial<TelemetrySample> = {}): TelemetrySample => ({
    id: 9,
    occurred_at: '2026-09-22T21:12:00Z',
    received_at: '2026-09-22T21:12:02Z',
    event_type: 'location_report',
    ignition: true,
    motion: 'moving',
    speed_kph: 41.6,
    battery_pct: 92,
    external_power: true,
    odometer_km: 82460.4,
    withheld: null,
    ...overrides,
});

const tracker: TelemetryTracker = {
    device_id: 5,
    name: 'Van 14 tracker',
    model: 'GV500CG',
    family: 'gv500cg',
    provider: 'queclink',
    firmware: '1.02',
    connectivity: { state: 'online', label: 'Recently observed' },
    last_seen_at: '2026-09-22T21:12:02Z',
    href: '/security-devices/devices/5',
};

describe('telemetry tiles', () => {
    const value = (tiles: ReturnType<typeof telemetryTiles>, key: string) =>
        tiles.find((tile) => tile.key === key)!;

    it('shows the current sample with its source', () => {
        const tiles = telemetryTiles(sample(), 9, 9, tracker);
        expect(value(tiles, 'ignition')).toMatchObject({
            value: 'On',
            caption: 'Virtual ignition · inferred',
            current: true,
        });
        expect(value(tiles, 'motion').caption).toBe('42 km/h · GNSS');
        expect(value(tiles, 'connection')).toMatchObject({
            value: 'Reporting',
            caption: 'Van 14 tracker · GV500CG',
        });
        expect(value(tiles, 'distance').value).toBe('82,460.4 km');
        expect(value(tiles, 'voltage').value).toBe('—');
    });

    it('leaves the vehicle state unknown when the latest report is stale', () => {
        const tiles = telemetryTiles(sample(), null, 9, tracker);
        expect(value(tiles, 'ignition').value).toBe('Unknown');
        expect(value(tiles, 'motion').value).toBe('Unknown');
        expect(value(tiles, 'connection').value).toBe('Overdue');
        expect(value(tiles, 'backup').value).toBe('92%');
    });

    it('shows an older sample as recorded, not current', () => {
        const older = sample({ id: 4, ignition: false, motion: 'stationary' });
        const tiles = telemetryTiles(older, 9, 9, tracker);
        expect(value(tiles, 'ignition')).toMatchObject({
            value: 'Off',
            current: false,
        });
        expect(value(tiles, 'connection').value).toBe('Historic');
    });

    it('keeps a withheld sample to the tracker’s own health', () => {
        // Even if a withheld sample carried journey values, none is shown.
        const withheld = telemetryTiles(
            sample({ withheld: 'personal', external_power: false }),
            9,
            9,
            tracker,
        );
        for (const key of ['ignition', 'motion', 'distance'])
            expect(value(withheld, key)).toMatchObject({
                value: '—',
                caption: 'Withheld for this sample',
            });
        expect(value(withheld, 'backup').value).toBe('92%');
        expect(value(withheld, 'connection').value).toBe('On backup');
        expect(value(withheld, 'voltage').caption).toBe(
            'External supply not detected · voltage not in reports',
        );
    });

    it('names the source for an unreviewed tracker and an unassigned one', () => {
        const other = telemetryTiles(sample(), 9, 9, {
            ...tracker,
            family: null,
            model: 'GL300',
        });
        expect(value(other, 'ignition').caption).toBe(
            'Reported ignition · inferred by the tracker',
        );
        const none = telemetryTiles(null, null, null, null);
        expect(value(none, 'connection')).toMatchObject({
            value: 'Not assigned',
            caption: 'Assign and validate a device',
        });
    });
});

describe('telemetry sample labels', () => {
    it('names the recorded event of a sample that is not withheld', () => {
        expect(sampleLabel(sample({ event_type: 'speed_alarm' }))).toBe(
            'Speed alarm',
        );
        expect(sampleLabel(sample({ event_type: null }))).toBe(
            'Tracker report',
        );
    });

    it('never names a driving event on a withheld sample', () => {
        for (const type of [
            'speed_alarm',
            'harsh_behaviour',
            'geofence_enter',
            'geofence_exit',
            'ignition_on',
            'motion_start',
            'location_report',
            null,
        ])
            expect(
                sampleLabel(sample({ event_type: type, withheld: 'personal' })),
            ).toBe('Withheld · recorded during a personal trip');
        expect(
            sampleLabel(
                sample({ event_type: 'harsh_behaviour', withheld: 'consent' }),
            ),
        ).toBe('Withheld · tracking consent was not in place');
    });

    it('keeps the tracker health report on a withheld sample', () => {
        expect(
            sampleLabel(
                sample({ event_type: 'heartbeat', withheld: 'personal' }),
            ),
        ).toBe('Heartbeat · withheld · recorded during a personal trip');
        expect(
            sampleLabel(
                sample({ event_type: 'power_off', withheld: 'consent' }),
            ),
        ).toBe(
            'Power disconnected · withheld · tracking consent was not in place',
        );
    });
});

describe('vehicle hover card', () => {
    it('escapes the action hint', () => {
        const html = mapMarkerStatsHtml({
            id: 'vehicle',
            lat: -41.2865,
            lng: 174.7762,
            title: 'KWH014',
            stats: [['Speed', '42 km/h']],
            hint: '<b>Right-click</b> vehicle for quick actions',
        });
        expect(html).toContain(
            '<footer>&lt;b&gt;Right-click&lt;/b&gt; vehicle for quick actions</footer>',
        );
        expect(html).not.toContain('<b>Right-click</b>');
    });
});
