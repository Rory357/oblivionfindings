import { plainLabel } from '@/components/control-room/alert-record/alert-labels';
import { ControlRoomPage } from '@/components/control-room/control-room-page';
import {
    ControlRoomRowActions,
    type ControlRoomRowAction,
} from '@/components/control-room/control-room-row-actions';
import { applyLiveResponseFilters } from '@/components/control-room/live-response/live-response-filters';
import {
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
} from '@/components/page';
import { Card } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatDateTime, formatRelative } from '@/lib/datetime';
import { Link, router } from '@inertiajs/react';
import {
    Activity,
    ArrowUpRight,
    Building2,
    Copy,
    Cpu,
    Link2,
    Radio,
    RefreshCw,
    ShieldCheck,
} from 'lucide-react';
import { useState } from 'react';

interface DeviceItem {
    id: number;
    name: string | null;
    device_uid: string;
    type: string;
    type_label: string;
    vendor: string | null;
    model: string | null;
    reported_battery_level: number | null;
    last_signal_at: string | null;
    signal_activity: {
        state: 'recent' | 'quiet' | 'never';
        label: string;
        tone: 'success' | 'muted';
    };
    location_description: string | null;
    site_id: number | null;
    site_name: string | null;
    signal_source_name: string | null;
    identity_source: 'canonical' | 'signal_projection';
    canonical_health_status: string | null;
    canonical_status: string | null;
    canonical_battery_level: number | null;
    canonical_last_seen_at: string | null;
    canonical_detail_url: string | null;
}

interface Props {
    devices: {
        data: DeviceItem[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
        current_page?: number;
        last_page?: number;
        total?: number;
        from?: number | null;
        to?: number | null;
    };
    stats: {
        signal_sources: number;
        active_24h: number;
        canonical_linked: number | null;
        reconciliation_needed: number | null;
    };
    filters: {
        type: string;
        activity: string;
        site_id: string;
        linkage: string;
    };
    sites: Array<{ id: number; name: string }>;
    device_types: Record<string, string>;
    can: { view_canonical_devices: boolean };
    canonicalIndexUrl: string | null;
}

const BASE_PATH = '/control-room/devices';

/** Security & Devices health → the verified status pairs. */
const HEALTH_TONE: Record<string, StatusVariant> = {
    healthy: 'success',
    ok: 'success',
    active: 'success',
    degraded: 'warning',
    warning: 'warning',
    maintenance: 'warning',
    critical: 'critical',
    failed: 'critical',
    offline: 'critical',
    unknown: 'neutral',
};

function healthBadge(device: DeviceItem) {
    const value = device.canonical_health_status ?? device.canonical_status;
    return {
        label: value ? plainLabel(value) : 'Not reported',
        tone: (value ? HEALTH_TONE[value] : undefined) ?? 'neutral',
    };
}

/** Device types arrive in Title Case ("Vehicle Tracker"); the UI is sentence case. */
function sentenceCase(label: string): string {
    return label.charAt(0).toUpperCase() + label.slice(1).toLowerCase();
}

function query(params: Record<string, string | undefined>) {
    const search = new URLSearchParams();
    for (const [key, value] of Object.entries(params)) {
        if (value) search.set(key, value);
    }
    const value = search.toString();
    return value ? `${BASE_PATH}?${value}` : BASE_PATH;
}

/**
 * Monitoring › Signals — what each connected source last reported. Device
 * setup, health and management stay in Security & Devices; this list only
 * shows the Control Room signal side and whether it is matched to a device.
 */
export default function DevicesIndex({
    devices,
    stats,
    filters,
    sites,
    device_types: deviceTypes,
    can,
    canonicalIndexUrl,
}: Props) {
    const [refreshing, setRefreshing] = useState(false);
    const canSeeDevices = can.view_canonical_devices;

    const apply = (next: Partial<Props['filters']>) =>
        applyLiveResponseFilters(BASE_PATH, { ...filters, ...next });
    const fromAll = (value: string) => (value === 'all' ? '' : value);

    const refresh = () => {
        setRefreshing(true);
        router.reload({
            only: ['devices', 'stats'],
            onFinish: () => setRefreshing(false),
        });
    };

    const site = filters.site_id || undefined;
    const total = devices.total ?? devices.data.length;

    return (
        <ControlRoomPage
            headTitle="Signals"
            header={{
                subline:
                    'What each connected source last reported · Device setup stays in Security & Devices',
                actions: (
                    <>
                        {canonicalIndexUrl ? (
                            <PageHeaderGlassButton icon={ShieldCheck} asChild>
                                <Link href={canonicalIndexUrl}>Open Device registry</Link>
                            </PageHeaderGlassButton>
                        ) : null}
                        <PageHeaderGlassButton
                            icon={RefreshCw}
                            onClick={refresh}
                            disabled={refreshing}
                        >
                            {refreshing ? 'Refreshing…' : 'Refresh'}
                        </PageHeaderGlassButton>
                    </>
                ),
                meters: (
                    <>
                        <PageHeaderMeterBlock
                            label="Signal sources"
                            href={query({ site_id: site })}
                            ariaLabel="Show every signal source"
                        >
                            <PageHeaderMeterBig>{stats.signal_sources}</PageHeaderMeterBig>
                            <PageHeaderMeterCaption>connected to Control Room</PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                        <PageHeaderMeterBlock
                            label="Active in 24 hours"
                            tone={stats.active_24h > 0 ? 'success' : 'brand'}
                            href={query({ site_id: site, activity: 'recent' })}
                            ariaLabel="Show sources with a signal in the last 24 hours"
                        >
                            <PageHeaderMeterBig>{stats.active_24h}</PageHeaderMeterBig>
                            <PageHeaderMeterCaption>signals received</PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                        {canSeeDevices && stats.canonical_linked !== null ? (
                            <PageHeaderMeterBlock
                                label="Matched to a device"
                                href={query({ site_id: site, linkage: 'linked' })}
                                ariaLabel="Show sources matched to a device record"
                            >
                                <PageHeaderMeterBig>{stats.canonical_linked}</PageHeaderMeterBig>
                                <PageHeaderMeterCaption>device record in Security & Devices</PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                        ) : null}
                        {canSeeDevices && stats.reconciliation_needed !== null ? (
                            <PageHeaderMeterBlock
                                label="Needs matching"
                                tone={stats.reconciliation_needed > 0 ? 'warning' : 'brand'}
                                href={query({ site_id: site, linkage: 'unlinked' })}
                                ariaLabel="Show sources with no device record"
                            >
                                <PageHeaderMeterBig>{stats.reconciliation_needed}</PageHeaderMeterBig>
                                <PageHeaderMeterCaption>no device record yet</PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                        ) : null}
                    </>
                ),
                filters: (
                    <>
                        <PageHeaderFilterSelect
                            icon={Cpu}
                            label="All types"
                            value={filters.type || 'all'}
                            onChange={(value) => apply({ type: fromAll(value) })}
                            options={[
                                { value: 'all', label: 'All types' },
                                ...Object.entries(deviceTypes).map(([value, label]) => ({
                                    value,
                                    label: sentenceCase(label),
                                })),
                            ]}
                        />
                        <PageHeaderFilterSelect
                            icon={Activity}
                            label="All signal activity"
                            value={filters.activity || 'all'}
                            onChange={(value) => apply({ activity: fromAll(value) })}
                            options={[
                                { value: 'all', label: 'All signal activity' },
                                { value: 'recent', label: 'Active in the last 24 hours' },
                                { value: 'quiet', label: 'No signal in the last 24 hours' },
                                { value: 'never', label: 'No signal recorded' },
                            ]}
                        />
                        {sites.length > 1 || filters.site_id ? (
                            <PageHeaderFilterSelect
                                icon={Building2}
                                label="All sites"
                                value={filters.site_id || 'all'}
                                onChange={(value) => apply({ site_id: fromAll(value) })}
                                options={[
                                    { value: 'all', label: 'All sites' },
                                    ...sites.map((s) => ({ value: String(s.id), label: s.name })),
                                ]}
                            />
                        ) : null}
                        {canSeeDevices ? (
                            <PageHeaderFilterSelect
                                icon={Link2}
                                label="Any device match"
                                value={filters.linkage || 'all'}
                                onChange={(value) => apply({ linkage: fromAll(value) })}
                                options={[
                                    { value: 'all', label: 'Any device match' },
                                    { value: 'linked', label: 'Matched to a device' },
                                    { value: 'unlinked', label: 'Needs matching' },
                                ]}
                            />
                        ) : null}
                    </>
                ),
            }}
        >
            <Card className="min-w-0 gap-0 overflow-hidden py-0">
                <div className="flex flex-wrap items-start justify-between gap-3 border-b border-border px-5 py-4">
                    <div className="min-w-0">
                        <h2 className="text-section-title">Signal sources</h2>
                        <p className="text-subtle mt-0.5">
                            {canSeeDevices
                                ? 'The last report from each source, and whether it is matched to a device record.'
                                : 'The last report from each source. Device matching and health show only to people with Security & Devices access.'}
                        </p>
                    </div>
                    <StatusBadge variant="neutral" className="tabular-nums">
                        {total} {total === 1 ? 'source' : 'sources'}
                    </StatusBadge>
                </div>

                {devices.data.length === 0 ? (
                    <div className="px-5 py-10">
                        <EmptyState
                            icon={Radio}
                            title="No signal sources match these filters"
                            description="Clear a filter, or choose another type or site."
                        />
                    </div>
                ) : (
                    // Table owns its overflow-x-auto container.
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead className="pl-5">Source</TableHead>
                                <TableHead>Last signal and site</TableHead>
                                {canSeeDevices ? <TableHead>Device record</TableHead> : null}
                                {canSeeDevices ? <TableHead>Device health</TableHead> : null}
                                <TableHead className="w-12 pr-5">
                                    <span className="sr-only">Actions</span>
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {devices.data.map((device) => (
                                <SignalSourceRow
                                    key={device.id}
                                    device={device}
                                    canSeeDevices={canSeeDevices}
                                />
                            ))}
                        </TableBody>
                    </Table>
                )}

                {(devices.last_page ?? 1) > 1 ? (
                    <div className="flex flex-wrap items-center justify-between gap-3 border-t border-border px-5 py-3">
                        <p className="text-caption">
                            Showing {devices.from ?? 0}–{devices.to ?? 0} of {total}
                        </p>
                        <div className="max-w-full min-w-0 overflow-x-auto scrollbar-pretty">
                            <div className="w-max">
                                <LaravelPagination
                                    links={devices.links}
                                    lastPage={devices.last_page}
                                    preserveScroll
                                />
                            </div>
                        </div>
                    </div>
                ) : null}
            </Card>
        </ControlRoomPage>
    );
}

function SignalSourceRow({
    device,
    canSeeDevices,
}: {
    device: DeviceItem;
    canSeeDevices: boolean;
}) {
    const href = `${BASE_PATH}/${device.id}`;
    const name = device.name || device.device_uid;
    const maker = [device.vendor, device.model].filter(Boolean).join(' ');
    const linked = device.identity_source === 'canonical';
    const health = healthBadge(device);
    const items: ControlRoomRowAction[] = [
        {
            key: 'open',
            label: 'Open signal record',
            icon: ArrowUpRight,
            onSelect: () => router.visit(href),
        },
    ];
    if (device.canonical_detail_url)
        items.push({
            key: 'device',
            label: 'Open in Security & Devices',
            icon: ShieldCheck,
            onSelect: () => router.visit(device.canonical_detail_url!),
        });
    items.push({
        key: 'copy',
        label: 'Copy source ID',
        icon: Copy,
        onSelect: () => void navigator.clipboard?.writeText(device.device_uid),
    });

    return (
        <ControlRoomRowActions label={`Actions for ${name}`} items={items}>
            {({ rowProps, overflowButton }) => (
                <TableRow
                    onContextMenu={rowProps.onContextMenu}
                    onClick={(event) => {
                        if ((event.target as HTMLElement).closest('a,button')) return;
                        router.visit(href);
                    }}
                    className="cursor-pointer"
                >
                    <TableCell className="pl-5 whitespace-normal">
                        <Link
                            href={href}
                            className="font-semibold text-foreground underline-offset-2 hover:underline focus-visible:rounded-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        >
                            {name}
                        </Link>
                        <span className="text-caption block">
                            {[sentenceCase(device.type_label), maker].filter(Boolean).join(' · ')}
                        </span>
                        {device.name ? (
                            <span className="text-caption block font-mono">{device.device_uid}</span>
                        ) : null}
                    </TableCell>
                    <TableCell className="whitespace-normal">
                        <span className="block" title={formatDateTime(device.last_signal_at)}>
                            {device.last_signal_at
                                ? `Last signal ${formatRelative(device.last_signal_at)}`
                                : 'No signal recorded'}
                        </span>
                        <StatusBadge
                            variant={device.signal_activity.tone === 'success' ? 'success' : 'neutral'}
                            size="sm"
                            className="mt-1"
                        >
                            {device.signal_activity.label}
                        </StatusBadge>
                        {device.site_name || device.signal_source_name ? (
                            <span className="text-caption mt-1 block">
                                {[device.site_name, device.signal_source_name]
                                    .filter(Boolean)
                                    .join(' · ')}
                            </span>
                        ) : null}
                    </TableCell>
                    {canSeeDevices ? (
                        <TableCell>
                            <StatusBadge variant={linked ? 'success' : 'warning'} size="sm">
                                {linked ? 'Matched' : 'Needs matching'}
                            </StatusBadge>
                        </TableCell>
                    ) : null}
                    {canSeeDevices ? (
                        <TableCell>
                            {linked ? (
                                <StatusBadge variant={health.tone} size="sm">
                                    {health.label}
                                </StatusBadge>
                            ) : (
                                <span className="text-caption">No device record</span>
                            )}
                        </TableCell>
                    ) : null}
                    <TableCell className="pr-5 text-right">{overflowButton}</TableCell>
                </TableRow>
            )}
        </ControlRoomRowActions>
    );
}
