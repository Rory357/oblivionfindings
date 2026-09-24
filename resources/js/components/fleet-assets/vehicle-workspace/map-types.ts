/** Server DTOs for the vehicle Map tab (VehicleMapController). */
import type {
    Geometry,
    ZoneSchedule,
} from '@/components/client-location/types';
import type { VehicleTechnologyProjection } from '@/pages/fleet-assets/vehicles/vehicle-technology-projection';

export type Coordinate = { lat: number; lng: number };

/** Why a recorded position isn't shown. */
export type Withheld = 'consent' | 'personal' | 'access' | null;

export type ReportedState = {
    event_id: number | null;
    observed_at: string | null;
    received_at: string | null;
    lat: number | null;
    lng: number | null;
    withheld: Withheld;
    speed_kph: number | null;
    heading_deg: number | null;
    ignition: boolean | null;
    motion: 'moving' | 'stationary' | null;
    battery_pct: number | null;
    external_power: boolean | null;
    status: string;
    fresh: boolean;
    trip_id: number | null;
};

export type Observation = {
    /** 'current' for the latest report, 'trip:{id}' where a trip ended. */
    id: string;
    kind: 'latest' | 'trip_end';
    observed_at: string | null;
    lat: number | null;
    lng: number | null;
    address: string | null;
    trip_id: number | null;
};

export type SharedBoundary = {
    id: number;
    name: string;
    type: 'circle' | 'polygon' | string;
    scope: string;
    site: { id: number; name: string } | null;
    vehicle: { id: number; name: string } | null;
    geometry: Geometry | null;
    /** The boundary version an assignment is reviewed against. */
    hash: string;
};

export type CatalogueBoundary = SharedBoundary & {
    is_active: boolean;
    assignment_id: number | null;
    /** Linked in Fleet geofences (the fleet evaluator's own link). */
    fleet_link: boolean;
};

export type LinkedGeofence = {
    key: string;
    assignment_id: number | null;
    geofence_id: number | null;
    label: string;
    origin: 'linked' | 'created' | 'copied' | 'fleet_rule';
    boundary: SharedBoundary | null;
    source_state: 'current' | 'changed' | 'removed' | 'restricted';
    reviewed_geometry?: Geometry | null;
    purpose: string | null;
    response_proposal: string | null;
    schedule: ZoneSchedule | null;
    monitoring: 'inactive' | 'on' | 'paused';
    lock_version: number | null;
    saved_at: string | null;
    saved_by: string | null;
};

export type VehicleGeofences = {
    items: LinkedGeofence[];
    /** Version of the linked selection, sent back with a change. */
    version: string;
    owner_site: { id: number; name: string } | null;
    can: { manage: boolean };
};

export type VehicleLocation = {
    as_of: string;
    timezone: string;
    fresh_minutes: number;
    vehicle: {
        id: number;
        name: string;
        registration_number: string | null;
        asset_tag: string | null;
        home_site: {
            id: number;
            name: string;
            lat: number | null;
            lng: number | null;
        } | null;
    };
    tracker: { linked: boolean };
    positions_visible: boolean;
    state: ReportedState | null;
    observations: Observation[];
    alerts: { open: number | null };
    geofences: VehicleGeofences;
    can: {
        view_telemetry: boolean;
        view_alerts: boolean;
        add_reminder: boolean;
    };
};

export type LocationTrail = {
    trip: {
        id: number;
        started_at: string | null;
        ended_at: string | null;
        distance_km: number | null;
    };
    points: Array<Coordinate & { at: string }>;
    recorded_points: number;
    downsampled: boolean;
    withheld: Withheld;
};

export type Place = {
    key: string;
    kind: 'site' | 'address';
    label: string;
    detail: string;
    lat: number;
    lng: number;
};

export type TelemetrySample = {
    id: number;
    occurred_at: string | null;
    received_at: string | null;
    event_type: string | null;
    ignition: boolean | null;
    motion: 'moving' | 'stationary' | null;
    speed_kph: number | null;
    battery_pct: number | null;
    external_power: boolean | null;
    odometer_km: number | null;
    withheld: Withheld;
};

export type TelemetryTracker = {
    device_id: number | null;
    name: string | null;
    model: string | null;
    /** Set only for a reviewed exact model. */
    family: 'gv500cg' | null;
    provider: string | null;
    firmware: string | null;
    connectivity: { state: string; label: string } | null;
    last_seen_at: string | null;
    href: string | null;
};

export type VehicleTelemetry = {
    technology: VehicleTechnologyProjection;
    telemetry: {
        as_of: string;
        timezone: string;
        fresh_minutes: number;
        tracker: TelemetryTracker | null;
        /** The latest sample when it is recent enough to be current. */
        current_sample_id: number | null;
        samples: TelemetrySample[];
        vehicle: { id: number; name: string; vin: string | null };
        can: { view_alerts: boolean };
    };
};
