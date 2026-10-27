import {
    EntityCard,
    EntityChip,
    EntityContextMenu,
    useEntityContextMenu,
    type EntityCardProps,
    type EntityMeridian,
    type MenuItem,
} from '@/components/lists';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';

import { Card, CardContent } from '@/components/ui/card';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { formatDateTime, formatRelative } from '@/lib/datetime';
import { router } from '@inertiajs/react';
import { Battery, Cpu, Eye, MapPin } from 'lucide-react';

// ── Types ─────────────────────────────────────────────────────────

export type DeviceListItem = {
    id: number;
    device_uid: string;
    name: string;
    domain: string;
    category: string;
    subcategory: string | null;
    manufacturer: string | null;
    model: string | null;
    status: string;
    health_status: string;
    provider: string | null;
    last_seen_at: string | null;
    last_changed_at?: string | null;
    battery_level: number | null;
    assigned_to: string | null;
    assignment_type: string | null;
    monitor_count?: number | null;
    monitoring_state?: string | null;
};

export type Paginated<T> = {
    data: T[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
    meta: { current_page: number; last_page: number; total: number };
};

export type FilterOption = { value: string; label: string };

// ── Helpers ───────────────────────────────────────────────────────

export function statusVariant(
    status: string,
): 'default' | 'secondary' | 'destructive' | 'outline' {
    switch (status) {
        case 'active':
            return 'default';
        case 'offline':
        case 'decommissioned':
        case 'lost':
            return 'secondary';
        case 'degraded':
        case 'maintenance':
            return 'outline';
        default:
            return 'outline';
    }
}

export function healthVariant(
    health: string,
): 'default' | 'secondary' | 'destructive' | 'outline' {
    switch (health) {
        case 'healthy':
            return 'default';
        case 'warning':
            return 'outline';
        case 'critical':
            return 'destructive';
        default:
            return 'secondary';
    }
}

/** Stored device domains, named as the Security & Devices sidebar names them. */
export const DEVICE_DOMAIN_LABELS: Record<string, string> = {
    it_infrastructure: 'Network & IT',
    security: 'Security',
    iot_healthcare: 'Healthcare',
    tracking: 'Tracking',
    facilities: 'Facilities & IoT',
};

export function domainLabel(domain: string): string {
    return DEVICE_DOMAIN_LABELS[domain] ?? taxonomyWords(domain);
}

// Short forms that keep their capitals when a stored key is put into words.
const TAXONOMY_ACRONYMS: Record<string, string> = {
    '5g': '5G',
    can: 'CAN',
    cctv: 'CCTV',
    co: 'CO',
    dvr: 'DVR',
    gps: 'GPS',
    hvac: 'HVAC',
    it: 'IT',
    iot: 'IoT',
    lora: 'LoRa',
    lpg: 'LPG',
    lte: 'LTE',
    mfp: 'MFP',
    nas: 'NAS',
    nvr: 'NVR',
    obd: 'OBD',
    pbx: 'PBX',
    pdu: 'PDU',
    pir: 'PIR',
    ptz: 'PTZ',
    san: 'SAN',
    sip: 'SIP',
    sos: 'SOS',
    ups: 'UPS',
};

/**
 * Puts a stored taxonomy key (`access_control`, `pir_motion`) into sentence
 * case words ("Access control", "PIR motion"). Stored values never change.
 */
export function taxonomyWords(value: string | null | undefined): string {
    const words = (value ?? '').split(/[_\s-]+/).filter(Boolean);
    if (words.length === 0) return 'Not recorded';
    return words
        .map((word, index) => {
            const known = TAXONOMY_ACRONYMS[word.toLowerCase()];
            if (known) return known;
            const lower = word.toLowerCase();
            return index === 0
                ? lower.charAt(0).toUpperCase() + lower.slice(1)
                : lower;
        })
        .join(' ');
}

/**
 * Sentence case for a Title Case taxonomy label from the server ("PIR Motion
 * Sensor" → "PIR motion sensor"). Words with a capital after their first
 * letter (NVR, LoRa) keep their capitals.
 */
export function sentenceCaseLabel(label: string): string {
    return label
        .split(' ')
        .map((word, index) =>
            index === 0 || /[A-Z]/.test(word.slice(1))
                ? word
                : word.toLowerCase(),
        )
        .join(' ');
}

/** The device's equipment category in words, e.g. "Access control". */
export const deviceCategoryLabel = (category: string | null | undefined) =>
    taxonomyWords(category);

export function formatTimeSince(iso: string | null): string {
    return formatRelative(iso, Date.now(), 'Never');
}

function allLabelForPlaceholder(placeholder: string): string {
    const key = placeholder.toLowerCase();

    const labels: Record<string, string> = {
        assignment: 'All assignments',
        category: 'All categories',
        domain: 'All domains',
        'event type': 'All event types',
        health: 'All health states',
        processed: 'All processed states',
        provider: 'All providers',
        severity: 'All severity levels',
        source: 'All sources',
        status: 'All statuses',
        type: 'All types',
    };

    return labels[key] ?? `All ${key}`;
}

// ── Components ────────────────────────────────────────────────────

export function StatCard({
    label,
    value,
    icon: Icon,
    variant = 'default',
}: {
    label: string;
    value: number;
    icon: React.ComponentType<{ className?: string }>;
    variant?: 'default' | 'warning';
}) {
    return (
        <Card>
            <CardContent className="flex items-center gap-4 p-4">
                <div
                    className={`rounded-lg p-2 ${variant === 'warning' && value > 0 ? 'bg-status-warning-bg' : 'bg-muted'}`}
                >
                    <Icon
                        className={`h-5 w-5 ${variant === 'warning' && value > 0 ? 'text-status-warning' : 'text-muted-foreground'}`}
                    />
                </div>
                <div>
                    <p className="text-2xl font-semibold">{value}</p>
                    <p className="text-xs text-muted-foreground">{label}</p>
                </div>
            </CardContent>
        </Card>
    );
}

export function FilterSelect({
    value,
    onChange,
    placeholder,
    options,
}: {
    value?: string;
    onChange: (v: string) => void;
    placeholder: string;
    options: FilterOption[];
}) {
    return (
        <Select value={value || 'all'} onValueChange={onChange}>
            <SelectTrigger aria-label={placeholder} className="w-[150px]">
                <SelectValue placeholder={placeholder} />
            </SelectTrigger>
            <SelectContent>
                <SelectItem value="all">
                    {allLabelForPlaceholder(placeholder)}
                </SelectItem>
                {options.map((opt) => (
                    <SelectItem key={opt.value} value={opt.value}>
                        {opt.label}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}

export function deviceHealthMeridian(device: DeviceListItem): EntityMeridian {
    // Health is the alert signal; lifecycle status alone never paints an all-clear meridian.
    if (device.health_status === 'critical') return 'critical';
    if (
        device.health_status === 'warning' ||
        device.monitoring_state === 'attention'
    )
        return 'warning';
    if (
        device.health_status === 'healthy' &&
        device.monitoring_state !== 'unmonitored' &&
        device.monitoring_state !== 'not_monitored' &&
        device.monitoring_state !== 'unknown'
    )
        return 'success';
    return 'neutral';
}

export function deviceMonitoringVariant(state: string): StatusVariant {
    if (state === 'healthy') return 'success';
    if (state === 'attention') return 'warning';
    return 'neutral';
}

export function deviceMonitoringLabel(state: string): string {
    if (state === 'unmonitored' || state === 'not_monitored') {
        return 'Not monitored';
    }
    if (state === 'attention') {
        return 'Needs attention';
    }
    return state.replaceAll('_', ' ');
}

function signalLabel(state: string) {
    if (state === 'not_monitored' || state === 'unmonitored') {
        return 'Not monitored';
    }
    return state.replaceAll('_', ' ');
}

export function DeviceCard({
    device,
    selection,
    actions: suppliedActions,
}: {
    device: DeviceListItem;
    selection?: EntityCardProps['selection'];
    actions?: MenuItem[];
}) {
    const contextMenu = useEntityContextMenu<DeviceListItem>();
    const href = `/security-devices/devices/${device.id}`;
    const actions: MenuItem[] = suppliedActions ?? [
        { label: 'Open device', icon: Eye, onClick: () => router.visit(href) },
    ];
    return (
        <>
            <EntityCard
                meridian={deviceHealthMeridian(device)}
                icon={Cpu}
                name={device.name}
                href={href}
                onOpen={() => router.visit(href)}
                subline={
                    [device.manufacturer, device.model]
                        .filter(Boolean)
                        .join(' ') || device.device_uid
                }
                actions={actions}
                selection={selection}
                onContextMenu={(event) => contextMenu.open(event, device)}
                chips={
                    <>
                        <StatusBadge status={device.status} />
                        <EntityChip>{domainLabel(device.domain)}</EntityChip>
                        <EntityChip>
                            {deviceCategoryLabel(device.category)}
                        </EntityChip>
                        {device.subcategory ? (
                            <EntityChip>
                                {taxonomyWords(device.subcategory)}
                            </EntityChip>
                        ) : null}
                        {device.provider ? (
                            <EntityChip>{device.provider}</EntityChip>
                        ) : null}
                        <EntityChip outline>{device.device_uid}</EntityChip>
                    </>
                }
                metric={
                    device.battery_level !== null
                        ? {
                              label: 'Battery',
                              value: (
                                  <span className="inline-flex items-center gap-1">
                                      <Battery className="size-3" />
                                      {device.battery_level}%
                                  </span>
                              ),
                              percent: device.battery_level,
                          }
                        : undefined
                }
                alerts={
                    device.monitoring_state === 'unmonitored' ||
                    device.health_status === 'not_monitored' ? (
                        <>
                            {(device.health_status === 'critical' ||
                                device.health_status === 'warning') && (
                                <StatusBadge
                                    status={device.health_status}
                                    label={`Health: ${signalLabel(device.health_status)}`}
                                />
                            )}
                            <StatusBadge
                                variant="neutral"
                                status="not_monitored"
                                label="Not monitored"
                            />
                        </>
                    ) : (
                        <>
                            <StatusBadge
                                status={device.health_status}
                                label={`Health: ${signalLabel(device.health_status)}`}
                            />
                            {device.monitoring_state ? (
                                <StatusBadge
                                    variant={deviceMonitoringVariant(
                                        device.monitoring_state,
                                    )}
                                    label={`Monitoring: ${deviceMonitoringLabel(device.monitoring_state)}`}
                                    status={device.monitoring_state}
                                />
                            ) : null}
                        </>
                    )
                }
                footer={{
                    personIcon:
                        device.assignment_type === 'site' ? MapPin : Cpu,
                    primary: device.assigned_to || 'Unassigned',
                    secondary: (
                        <span
                            title={
                                device.last_seen_at
                                    ? formatDateTime(device.last_seen_at)
                                    : undefined
                            }
                        >
                            {device.assignment_type
                                ? `${device.assignment_type.replaceAll('_', ' ')} · `
                                : ''}
                            Last seen {formatTimeSince(device.last_seen_at)}
                        </span>
                    ),
                }}
            />
            {contextMenu.ctx ? (
                <EntityContextMenu
                    x={contextMenu.ctx.x}
                    y={contextMenu.ctx.y}
                    title={device.name}
                    icon={Cpu}
                    items={actions}
                    onClose={contextMenu.close}
                />
            ) : null}
        </>
    );
}
