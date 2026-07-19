import { OperationalStateBadge } from '@/components/security-devices/estate-operations';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { formatRelative } from '@/lib/datetime';
import { Link } from '@inertiajs/react';
import { Battery } from 'lucide-react';

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

export function domainLabel(domain: string): string {
    const map: Record<string, string> = {
        security: 'Security',
        tracking: 'Tracking',
        iot_healthcare: 'IoT / Healthcare',
        it_infrastructure: 'IT Infrastructure',
        facilities: 'Facilities',
    };
    return map[domain] ?? domain;
}

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
                        className={`h-5 w-5 ${variant === 'warning' && value > 0 ? 'text-status-warning dark:text-status-warning' : 'text-muted-foreground'}`}
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
            <SelectTrigger className="w-[150px]">
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

export function DeviceCard({ device }: { device: DeviceListItem }) {
    return (
        <Link
            href={`/security-devices/devices/${device.id}`}
            className="frontline-focus group flex flex-col rounded-lg border p-4 transition-all hover:bg-muted/50 hover:shadow-md"
        >
            <div className="flex items-start justify-between gap-2">
                <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="truncate text-sm font-semibold">
                            {device.name}
                        </span>
                        <Badge
                            variant="outline"
                            className="shrink-0 font-mono text-[10px]"
                        >
                            {device.device_uid}
                        </Badge>
                    </div>
                    {(device.manufacturer || device.model) && (
                        <p className="mt-0.5 truncate text-xs text-muted-foreground">
                            {[device.manufacturer, device.model]
                                .filter(Boolean)
                                .join(' ')}
                        </p>
                    )}
                </div>
                <div className="flex shrink-0 flex-col items-end gap-1">
                    <OperationalStateBadge
                        state={device.status}
                        className="text-[10px]"
                    />
                    <OperationalStateBadge
                        state={device.health_status}
                        className="text-[10px]"
                    />
                </div>
            </div>

            <div className="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted-foreground">
                <span>{domainLabel(device.domain)}</span>
                <span className="text-muted-foreground/50">/</span>
                <span>{device.category.replace(/_/g, ' ')}</span>
                {device.subcategory && (
                    <>
                        <span className="text-muted-foreground/50">/</span>
                        <span>{device.subcategory.replace(/_/g, ' ')}</span>
                    </>
                )}
                {device.provider && (
                    <>
                        <span className="text-muted-foreground/50">|</span>
                        <span>{device.provider}</span>
                    </>
                )}
            </div>

            <div className="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted-foreground">
                <span>Seen: {formatTimeSince(device.last_seen_at)}</span>
                {device.monitoring_state ? (
                    <OperationalStateBadge
                        state={device.monitoring_state}
                        className="text-[10px]"
                    />
                ) : null}
                {device.battery_level !== null && (
                    <span className="flex items-center gap-1">
                        <Battery className="h-3 w-3" />
                        {device.battery_level}%
                    </span>
                )}
                {device.assigned_to ? (
                    <span className="text-foreground">
                        {device.assignment_type}: {device.assigned_to}
                    </span>
                ) : (
                    <span className="italic">Unassigned</span>
                )}
            </div>
        </Link>
    );
}
