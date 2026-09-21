import LocationWorkspace from '@/components/client-location/location-workspace';
import type {
    CommandStatus,
    Geofence,
    GeofenceStatus,
} from '@/components/resident-tracking/types';
import { Button } from '@/components/ui/button';
import { usePersonalLocationPrivacy } from '@/hooks/use-personal-location-privacy';
import { ShieldOff } from 'lucide-react';
export type ClientLocationData = {
    accessFingerprint?: string | null;
    zonesUrl?: string | null;
    trackingRestricted?: boolean;
    canManage: boolean;
    tracker: {
        id: number;
        device_uid?: string | null;
        name: string;
        serial: string | null;
        mac: string | null;
        imei?: string | null;
        model?: string | null;
        manufacturer?: string | null;
        firmware_version?: string | null;
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
        provider: string | null;
        status: string;
        health_status?: string;
        last_seen_at: string | null;
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
        panic_active?: boolean | null;
        panic_acknowledged_at?: string | null;
        motion_status?: 'moving' | 'stationary' | null;
        motion_reported_at?: string | null;
        fall_report_type?: 'fall_detected' | 'man_down' | null;
        fall_reported_at?: string | null;
        locate_now_url?: string;
        locate_requests_url?: string;
        tracker_modes_url?: string;
        acknowledge_panic_url?: string;
        fleet_dashboard_url?: string;
        history_url?: string;
        tracking_workspace_url?: string | null;
        tracking_workspace_access?: {
            state: 'available' | 'restricted';
            label: string;
        };
        last_command_status?: CommandStatus;
        detail_url?: string | null;
        detail_access?: {
            state: 'available' | 'restricted';
            label: string;
        };
    } | null;
    currentLocation: {
        lat: number;
        lng: number;
        address?: string | null;
        address_source?: 'recorded' | 'nearest' | null;
        coordinates?: string | null;
        display_location?: string | null;
        speed: number | null;
        heading: number | null;
        accuracy: number | null;
        altitude?: number | null;
    } | null;
    trackingConsent: {
        status: string;
        given_at: string | null;
        expires_at: string | null;
    } | null;
    geofences: Geofence[];
    geofenceStatus?: GeofenceStatus;
    privacyStatusUrl?: string | null;
    exportUrl?: string | null;
    canExport?: boolean;
    retentionDays?: number | null;
};

type Props = {
    clientId: number;
    clientName: string;
    clientHouse?: string;
    clientPhoto?: string | null;
    location: ClientLocationData;
};

export default function ClientLocationTab(props: Props) {
    const privacy = usePersonalLocationPrivacy({
        statusUrl: props.location.privacyStatusUrl,
        fingerprint: props.location.accessFingerprint,
    });
    if (!privacy.active || props.location.trackingRestricted)
        return (
            <section className="mt-4 flex items-start gap-3 rounded-xl border bg-card p-5">
                <ShieldOff className="mt-1 size-5 shrink-0" />
                <div>
                    <h2 className="font-semibold">
                        {privacy.checking
                            ? 'Checking location access'
                            : 'Location access is not active'}
                    </h2>
                    <p className="mt-2 text-sm text-muted-foreground">
                        {privacy.checking
                            ? 'Current location, history and zone drafts stay hidden until current access is confirmed.'
                            : privacy.message ||
                              'Current tracking consent and assignment are required. Cached location data has been removed.'}
                    </p>
                    {!privacy.checking && (
                        <Button
                            className="mt-3"
                            variant="outline"
                            onClick={() => window.location.reload()}
                        >
                            Reload current access
                        </Button>
                    )}
                </div>
            </section>
        );
    return (
        <LocationWorkspace
            key={`${props.clientId}:${props.location.accessFingerprint ?? ''}`}
            clientId={props.clientId}
            clientName={props.clientName}
            location={props.location}
            endAccess={privacy.endAccess}
        />
    );
}
