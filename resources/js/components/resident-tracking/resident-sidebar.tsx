import PanicStatusBadge from '@/components/resident-tracking/panic-status-badge';
import type { Resident } from '@/components/resident-tracking/types';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card as GuardrailCard } from '@/components/ui/card';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { formatDateTime, formatRelativeTime } from '@/lib/fleet-utils';
import { Link } from '@inertiajs/react';
import {
    Battery,
    BatteryLow,
    BatteryWarning,
    ChevronRight,
    Clock,
    ExternalLink,
    History,
    MapPin,
    Navigation,
    Plug,
    Radio,
    UserCircle,
} from 'lucide-react';
import { useState } from 'react';

type BatteryState = {
    label: string;
    detail?: string;
    icon: typeof Battery;
    textClass: string;
    barClass: string;
    barWidth: number;
};

type Props = {
    resident: Resident;
    variant: 'fleet-row' | 'profile-detail';
    canManage: boolean;
    onLocateNow?: () => void;
    onAcknowledgePanic?: () => void;
    onOpenProfile?: () => void;
    isActive?: boolean;
};

function getBatteryState(resident: Resident): BatteryState {
    const threshold = resident.battery_low_threshold ?? 20;
    const battery = resident.battery;
    const isCharging =
        resident.charging_status === 'charging' ||
        resident.external_power === true;
    const statusUnknown = resident.battery_status === 'unknown';

    if (isCharging) {
        return {
            label: 'Charging',
            detail: battery != null ? `${battery}%` : undefined,
            icon: Plug,
            textClass: 'text-status-success',
            barClass: 'bg-status-success',
            barWidth: battery ?? 100,
        };
    }

    if (battery == null || statusUnknown) {
        return {
            label: 'Battery not reported',
            icon: BatteryWarning,
            textClass: 'text-status-warning',
            barClass: 'bg-status-warning/40',
            barWidth: 0,
        };
    }

    if (resident.battery_status === 'low' || battery <= threshold) {
        return {
            label: 'Low battery',
            detail: `${battery}%`,
            icon: BatteryLow,
            textClass: 'text-status-critical',
            barClass: 'bg-status-critical animate-pulse',
            barWidth: battery,
        };
    }

    return {
        label: `${battery}%`,
        icon: Battery,
        textClass: 'text-foreground',
        barClass: battery <= 40 ? 'bg-status-warning' : 'bg-primary',
        barWidth: battery,
    };
}

function getZoneBadge(resident: Resident): { text: string; className: string } {
    if (resident.on_outing) {
        return {
            text: 'On Outing',
            className:
                'bg-status-info-bg text-status-info border-status-info/30',
        };
    }
    switch (resident.geofence_status) {
        case 'in_zone':
            return {
                text: 'In Zone',
                className: 'bg-primary/10 text-primary border-primary/30',
            };
        case 'outside_zone':
            return {
                text: 'Outside',
                className:
                    'bg-status-critical-bg text-status-critical border-status-critical/30',
            };
        default:
            return {
                text: 'Zone Unknown',
                className: 'bg-muted text-muted-foreground',
            };
    }
}

function getStatusDotColor(resident: Resident): string {
    if (resident.panic_active) return 'bg-status-critical animate-pulse';
    if (resident.on_outing) return 'bg-status-info';
    if (resident.geofence_status === 'outside_zone')
        return 'bg-status-critical';
    if (resident.status === 'online') return 'bg-status-success';
    if (resident.status === 'offline') return 'bg-status-critical';
    return 'bg-muted';
}

function commandStatusLabel(
    status?: Resident['last_command_status'],
): string | null {
    switch (status) {
        case 'queued':
            return 'Queued';
        case 'sent':
            return 'Sent';
        case 'acked':
            return 'Acknowledged';
        case 'failed':
            return 'Failed';
        case 'expired':
            return 'Expired';
        default:
            return null;
    }
}

function commandStatusTone(status?: Resident['last_command_status']): string {
    if (status === 'acked')
        return 'border-status-success/30 bg-status-success-bg text-status-success';
    if (status === 'failed' || status === 'expired')
        return 'border-status-critical/30 bg-status-critical-bg text-status-critical';
    if (status === 'queued' || status === 'sent')
        return 'border-status-info/30 bg-status-info-bg text-status-info';
    return '';
}

function fieldRow(label: string, value: React.ReactNode) {
    return (
        <div className="grid grid-cols-[120px_1fr] items-baseline gap-2 py-1 text-xs">
            <span className="text-muted-foreground">{label}</span>
            <span className="truncate font-medium text-foreground">
                {value ?? '—'}
            </span>
        </div>
    );
}

function formatVoltage(mv: number | null | undefined): string | null {
    if (mv == null) return null;
    return `${(mv / 1000).toFixed(2)} V`;
}

function formatChargingStatus(
    status?: string | null,
    externalPower?: boolean | null,
): string {
    if (status === 'charging' || externalPower === true) return 'Charging';
    if (status === 'not_charging') return 'Not charging';
    if (status === 'stopped_charging') return 'Stopped charging';
    if (status === 'charge_full') return 'Charge full';

    return '—';
}

export default function ResidentSidebar({
    resident,
    variant,
    canManage,
    onLocateNow,
    onAcknowledgePanic,
    onOpenProfile,
    isActive,
}: Props) {
    const battery = getBatteryState(resident);
    const BatteryIcon = battery.icon;
    const zone = getZoneBadge(resident);
    const commandLabel = commandStatusLabel(resident.last_command_status);
    const commandTone = commandStatusTone(resident.last_command_status);
    const hasLocation = resident.lat != null && resident.lng != null;
    const [detailsOpen, setDetailsOpen] = useState(
        variant === 'profile-detail',
    );

    if (variant === 'fleet-row') {
        return (
            <div
                className={`group flex flex-col gap-2 border-b border-border/60 px-4 py-3 transition-colors hover:bg-muted/40 ${
                    isActive ? 'bg-primary/5' : ''
                }`}
                data-resident-id={resident.client_id}
            >
                <div className="flex items-center gap-3">
                    <div className="relative shrink-0">
                        <img
                            src={
                                resident.photo ??
                                '/images/avatar-placeholder.svg'
                            }
                            alt={resident.name}
                            className="h-10 w-10 rounded-full border border-border/80 object-cover"
                        />
                        <span
                            className={`absolute -right-0.5 -bottom-0.5 h-3 w-3 rounded-full border-2 border-background ${getStatusDotColor(resident)}`}
                        />
                    </div>

                    <Link
                        href={
                            resident.history_url ??
                            `/fleet-assets/resident-tracking/history/${resident.client_id}`
                        }
                        className="min-w-0 flex-1"
                    >
                        <div className="flex flex-wrap items-center gap-1.5">
                            <span className="truncate text-sm font-medium">
                                {resident.preferred_name ?? resident.name}
                            </span>
                            <Badge
                                variant="outline"
                                className={`text-[10px] ${zone.className}`}
                            >
                                {zone.text}
                            </Badge>
                        </div>
                        <div className="text-xs text-muted-foreground">
                            {resident.house} ·{' '}
                            {formatRelativeTime(resident.last_seen_at)}
                        </div>
                    </Link>

                    <div className="flex items-center gap-1">
                        {onOpenProfile && (
                            <Button
                                type="button"
                                size="icon"
                                variant="ghost"
                                className="h-8 w-8 text-muted-foreground hover:text-foreground"
                                onClick={onOpenProfile}
                                title="Open client profile"
                            >
                                <UserCircle className="h-4 w-4" />
                            </Button>
                        )}
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            className="h-8"
                            onClick={onLocateNow}
                            disabled={!resident.locate_now_url}
                        >
                            <MapPin className="mr-1 h-3.5 w-3.5" />
                            Locate
                        </Button>
                    </div>
                </div>

                {(resident.panic_active || resident.last_safety_event_at) && (
                    <PanicStatusBadge
                        panicActive={!!resident.panic_active}
                        lastSafetyEvent={resident.last_safety_event}
                        lastSafetyEventAt={resident.last_safety_event_at}
                        canManage={canManage}
                        onAcknowledge={onAcknowledgePanic}
                        compact
                    />
                )}

                <div className="flex items-center justify-between gap-3 text-xs">
                    <div
                        className={`flex min-w-0 items-center gap-1.5 ${battery.textClass}`}
                    >
                        <BatteryIcon className="h-3.5 w-3.5 shrink-0" />
                        <span className="truncate font-medium">
                            {battery.label}
                        </span>
                        {battery.detail && (
                            <span className="text-muted-foreground">
                                {battery.detail}
                            </span>
                        )}
                    </div>
                    {commandLabel && (
                        <Badge
                            variant="outline"
                            className={`text-[10px] ${commandTone}`}
                        >
                            {commandLabel}
                        </Badge>
                    )}
                </div>

                {/* battery bar */}
                <div className="h-1 w-full overflow-hidden rounded-full bg-muted">
                    <div
                        className={`h-full rounded-full transition-all ${battery.barClass}`}
                        style={{ width: `${battery.barWidth}%` }}
                    />
                </div>

                {hasLocation && resident.display_location && (
                    <div className="flex items-start gap-1.5 text-xs text-muted-foreground">
                        <MapPin className="mt-0.5 h-3 w-3 shrink-0" />
                        <span className="truncate">
                            {resident.display_location}
                        </span>
                    </div>
                )}
            </div>
        );
    }

    // profile-detail variant
    return (
        <div className="flex h-full flex-col gap-4">
            {/* Hero row */}
            <div className="flex items-start gap-3">
                <div className="relative shrink-0">
                    <img
                        src={resident.photo ?? '/images/avatar-placeholder.svg'}
                        alt={resident.name}
                        className="h-14 w-14 rounded-full border border-border/80 object-cover"
                    />
                    <span
                        className={`absolute -right-0.5 -bottom-0.5 h-3.5 w-3.5 rounded-full border-2 border-background ${getStatusDotColor(resident)}`}
                    />
                </div>
                <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center gap-2">
                        <h3 className="truncate text-base font-semibold">
                            {resident.preferred_name ?? resident.name}
                        </h3>
                        <Badge
                            variant="outline"
                            className={`text-[10px] ${zone.className}`}
                        >
                            {zone.text}
                        </Badge>
                    </div>
                    <p className="text-sm text-muted-foreground">
                        {resident.house}
                    </p>
                    <p className="mt-0.5 text-xs text-muted-foreground">
                        <Clock className="-mt-0.5 mr-1 inline h-3 w-3" />
                        Last seen {formatRelativeTime(resident.last_seen_at)}
                    </p>
                    <p className="mt-1 truncate text-xs text-muted-foreground">
                        Tracker:{' '}
                        {resident.tracker_name ?? 'Unidentified device'}
                    </p>
                </div>
            </div>

            {/* Panic */}
            <PanicStatusBadge
                panicActive={!!resident.panic_active}
                lastSafetyEvent={resident.last_safety_event}
                lastSafetyEventAt={resident.last_safety_event_at}
                canManage={canManage}
                onAcknowledge={onAcknowledgePanic}
            />

            {/* Locate now action */}
            <div className="flex flex-wrap items-center gap-2">
                <Button
                    type="button"
                    size="sm"
                    onClick={onLocateNow}
                    disabled={!resident.locate_now_url}
                    className="flex-1"
                >
                    <Navigation className="mr-1 h-4 w-4" />
                    Locate Now
                </Button>
                {commandLabel && (
                    <Badge
                        variant="outline"
                        className={`text-[10px] ${commandTone}`}
                    >
                        {commandLabel}
                    </Badge>
                )}
            </div>

            {/* Battery & power */}
            <GuardrailCard unstyled className="rounded-lg border bg-card p-3">
                <div className="mb-2 flex items-center justify-between">
                    <div
                        className={`flex items-center gap-2 text-sm font-medium ${battery.textClass}`}
                    >
                        <BatteryIcon className="h-4 w-4" />
                        {battery.label}
                        {battery.detail && (
                            <span className="text-muted-foreground">
                                · {battery.detail}
                            </span>
                        )}
                    </div>
                </div>
                <div className="mb-3 h-1.5 w-full overflow-hidden rounded-full bg-muted">
                    <div
                        className={`h-full rounded-full transition-all ${battery.barClass}`}
                        style={{ width: `${battery.barWidth}%` }}
                    />
                </div>
                <div className="space-y-0.5">
                    {fieldRow(
                        'Voltage',
                        formatVoltage(resident.battery_voltage_mv),
                    )}
                    {fieldRow(
                        'Low threshold',
                        `${resident.battery_low_threshold ?? 20}%`,
                    )}
                    {fieldRow(
                        'Charging',
                        formatChargingStatus(
                            resident.charging_status,
                            resident.external_power,
                        ),
                    )}
                    {fieldRow(
                        'Last power event',
                        resident.last_power_event ?? '—',
                    )}
                    {fieldRow(
                        'Updated',
                        resident.battery_updated_at
                            ? formatRelativeTime(resident.battery_updated_at)
                            : '—',
                    )}
                </div>
            </GuardrailCard>

            {/* Current location row */}
            {hasLocation && (
                <GuardrailCard
                    unstyled
                    className="rounded-lg border bg-card p-3"
                >
                    <div className="flex items-center gap-2 text-sm font-medium">
                        <MapPin className="h-4 w-4 text-primary" />
                        Current location
                    </div>
                    <p className="mt-1 text-xs text-foreground">
                        {resident.display_location}
                    </p>
                    {resident.address && resident.coordinates && (
                        <p className="text-[11px] text-muted-foreground">
                            {resident.coordinates}
                        </p>
                    )}
                    <div className="mt-2 grid grid-cols-2 gap-x-3 gap-y-1 text-[11px] text-muted-foreground">
                        {resident.speed != null && (
                            <span>Speed: {resident.speed} km/h</span>
                        )}
                        {resident.heading != null && (
                            <span>Heading: {resident.heading}°</span>
                        )}
                        {resident.accuracy != null && (
                            <span>Accuracy: ~{resident.accuracy}m</span>
                        )}
                        {resident.satellites != null && (
                            <span>Satellites: {resident.satellites}</span>
                        )}
                    </div>
                </GuardrailCard>
            )}

            {/* Device details collapsible */}
            <Collapsible open={detailsOpen} onOpenChange={setDetailsOpen}>
                <CollapsibleTrigger asChild>
                    <button
                        type="button"
                        className="flex w-full items-center justify-between rounded-lg border bg-card px-3 py-2 text-sm font-medium hover:bg-muted/40"
                    >
                        <span className="flex items-center gap-2">
                            <Radio className="h-4 w-4 text-muted-foreground" />
                            Device details
                        </span>
                        <ChevronRight
                            className={`h-4 w-4 text-muted-foreground transition-transform ${detailsOpen ? 'rotate-90' : ''}`}
                        />
                    </button>
                </CollapsibleTrigger>
                <CollapsibleContent className="mt-2 rounded-lg border bg-card p-3">
                    <div className="mb-2 text-[11px] font-semibold tracking-wider text-muted-foreground uppercase">
                        Identity
                    </div>
                    {fieldRow('Name', resident.tracker_name)}
                    {fieldRow('Model', resident.model)}
                    {fieldRow('Manufacturer', resident.manufacturer)}
                    {fieldRow('IMEI', resident.imei)}
                    {fieldRow('Serial', resident.tracker_serial)}
                    {fieldRow('Device UID', resident.device_uid)}
                    {fieldRow('MAC', resident.mac)}
                    {fieldRow('BLE MAC', resident.ble_mac)}
                    {fieldRow('Firmware', resident.firmware_version)}
                    {fieldRow('BLE firmware', resident.ble_firmware)}
                    {fieldRow('Hardware', resident.hardware_version)}
                    {fieldRow('Provider', resident.provider)}

                    <div className="mt-3 mb-2 text-[11px] font-semibold tracking-wider text-muted-foreground uppercase">
                        Connectivity
                    </div>
                    {fieldRow('Network', resident.network_type)}
                    {fieldRow(
                        'RSRP',
                        resident.rsrp != null ? `${resident.rsrp} dBm` : null,
                    )}
                    {fieldRow('Band', resident.band)}
                    {fieldRow('SIM ICCID', resident.sim_iccid)}
                    {fieldRow('IMSI', resident.imsi)}
                    {fieldRow(
                        'MCC / MNC',
                        resident.mcc || resident.mnc
                            ? `${resident.mcc ?? '—'} / ${resident.mnc ?? '—'}`
                            : null,
                    )}
                    {fieldRow(
                        'LAC / Cell ID',
                        resident.lac || resident.cell_id
                            ? `${resident.lac ?? '—'} / ${resident.cell_id ?? '—'}`
                            : null,
                    )}
                    {fieldRow(
                        'Last frame',
                        resident.last_frame_at
                            ? formatDateTime(resident.last_frame_at)
                            : null,
                    )}

                    {resident.config_snapshot && (
                        <>
                            <div className="mt-3 mb-2 text-[11px] font-semibold tracking-wider text-muted-foreground uppercase">
                                Configuration
                            </div>
                            {Object.entries(resident.config_snapshot)
                                .slice(0, 8)
                                .map(([k, v]) => (
                                    <div key={k}>
                                        {fieldRow(
                                            k.replace(/_/g, ' '),
                                            String(v),
                                        )}
                                    </div>
                                ))}
                        </>
                    )}

                    {resident.detail_url && (
                        <div className="mt-3 border-t pt-2">
                            <Link
                                href={resident.detail_url}
                                className="flex items-center gap-1 text-xs text-primary hover:underline"
                            >
                                Open device console
                                <ExternalLink className="h-3 w-3" />
                            </Link>
                        </div>
                    )}
                </CollapsibleContent>
            </Collapsible>

            {/* Secondary links */}
            <div className="mt-auto flex flex-wrap items-center gap-3 border-t pt-3 text-xs">
                {resident.history_url && (
                    <Link
                        href={resident.history_url}
                        className="flex items-center gap-1 text-muted-foreground hover:text-foreground"
                    >
                        <History className="h-3.5 w-3.5" />
                        Movement history
                    </Link>
                )}
            </div>
        </div>
    );
}
