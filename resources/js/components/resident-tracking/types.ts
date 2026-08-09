/**
 * Shared types for the resident tracking surfaces (Fleet dashboard + Client profile).
 * Keep this in sync with the controller payloads at
 *   - app/Http/Controllers/FleetAssets/ResidentTrackingController::buildResidentPayload
 *   - app/Http/Controllers/ClientController::buildLocationData
 */

export type CommandStatus =
    | 'queued'
    | 'sent'
    | 'acked'
    | 'failed'
    | 'expired'
    | null;

export type GeofenceStatus = 'in_zone' | 'outside_zone' | 'unknown';

export type Geofence = {
    id: string;
    name?: string | null;
    type: 'circle' | 'polygon';
    scope?: string | null;
    applies_to?: string | null;
    center?: { lat: number; lng: number };
    radius_m?: number;
    coordinates?: { lat: number; lng: number }[];
    color?: string | null;
    is_active?: boolean;
};

export type Resident = {
    id: number;
    device_uid?: string | null;
    client_id: number;
    name: string;
    preferred_name?: string | null;
    house: string;
    site_id?: number | null;
    photo?: string | null;
    tracker_name?: string | null;
    tracker_serial?: string | null;
    status: string;
    health_status?: string | null;
    last_seen_at: string | null;
    lat: number | null;
    lng: number | null;
    address?: string | null;
    coordinates?: string | null;
    display_location?: string | null;
    battery: number | null;
    battery_status?: 'low' | 'normal' | 'unknown' | string | null;
    battery_voltage_mv?: number | null;
    battery_low_threshold?: number | null;
    battery_updated_at?: string | null;
    charging_status?: string | null;
    external_power?: boolean | null;
    last_power_event?: string | null;
    last_safety_event?: string | null;
    last_safety_event_at?: string | null;
    panic_active?: boolean;
    speed: number | null;
    heading?: number | null;
    accuracy?: number | null;
    altitude?: number | null;
    motion?: string | null;
    imei?: string | null;
    mac?: string | null;
    model?: string | null;
    manufacturer?: string | null;
    firmware_version?: string | null;
    provider?: string | null;
    hardware_version?: string | null;
    ble_firmware?: string | null;
    ble_mac?: string | null;
    sim_iccid?: string | null;
    imsi?: string | null;
    network_type?: string | null;
    rsrp?: number | string | null;
    band?: string | null;
    mcc?: string | null;
    mnc?: string | null;
    cell_id?: string | null;
    lac?: string | null;
    satellites?: number | null;
    last_frame_at?: string | null;
    last_location_at?: string | null;
    config_snapshot?: Record<string, unknown> | null;
    geofence_status: GeofenceStatus;
    on_outing: boolean;
    house_geofence?: Geofence | null;
    locate_now_url?: string;
    acknowledge_panic_url?: string;
    profile_url?: string;
    history_url?: string;
    detail_url?: string | null;
    detail_access?: {
        state: 'available' | 'restricted';
        label: string;
    };
    last_command_status?: CommandStatus;
};
