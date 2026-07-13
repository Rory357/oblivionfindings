import { ConfirmDialog } from '@/components/confirm-dialog';
import { FleetEmptyState } from '@/components/fleet-empty-state';
import LeafletMap, { MapMarker } from '@/components/leaflet-map';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { WizardShell, WizardStepPane } from '@/components/wizard/shell';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime } from '@/lib/fleet-utils';
import { cn } from '@/lib/utils';
import {
    FleetHeroAction,
    fmt,
    HeroClusterTile,
    HeroMedallion,
    HeroShell,
    HeroStatusPill,
} from '@/pages/fleet-assets/components/fleet-hero-kit';
import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    Battery,
    CheckCircle2,
    ChevronDown,
    ChevronUp,
    ChevronsUpDown,
    Clock,
    Download,
    Loader2,
    MapPin,
    Plus,
    Radio,
    Search,
    Shield,
    ShieldAlert,
    ShieldCheck,
    ShieldOff,
    ShieldX,
    Wifi,
    WifiOff,
} from 'lucide-react';
import type React from 'react';
import { useEffect, useMemo, useState } from 'react';

type Device = {
    id: number;
    source: string;
    vendor: string;
    device_uid: string;
    imei: string | null;
    serial_number: string | null;
    status: string;
    last_seen_at: string | null;
    paired_at: string | null;
    asset: { id: number; name: string; asset_tag: string | null } | null;
};

type PaginatedDevices = {
    data: Device[];
    links?: Array<{ url: string | null; label: string; active: boolean }>;
    meta?: { current_page: number; last_page: number; total: number };
};

type DeviceConsent = {
    id: number;
    vendor: string;
    device_uid: string;
    status: string;
    consent_status: 'consented' | 'revoked' | 'pending' | 'expired';
    consent_given_at: string | null;
    consent_withdrawn_at: string | null;
    consent_expires_at: string | null;
    consent_given_by: string | null;
    asset: { id: number; name: string; asset_tag: string | null } | null;
    client_name: string | null;
};

type TelemetrySnapshot = {
    id: number;
    created_at: string | null;
    data: Record<string, any> | null;
};

type DeviceDetail = {
    id: number;
    vendor: string;
    device_uid: string;
    imei: string | null;
    serial_number: string | null;
    device_status: string;
    link_status: 'paired' | 'unpaired';
    paired_at: string | null;
    unpaired_at: string | null;
    last_seen_at: string | null;
    battery_level: number | null;
    vendor_metadata: Record<string, any> | null;
    asset: {
        id: number;
        name: string;
        asset_tag: string | null;
        category: string | null;
        status: string | null;
    } | null;
    telemetry_snapshots: TelemetrySnapshot[];
};

type Props = {
    devices: PaginatedDevices | Device[];
    stats?: {
        total: number;
        online: number;
        offline: number;
        unpaired: number;
        low_battery: number;
        consent_granted: number;
        consent_blocked: number;
    };
    pairing_options: {
        devices: Array<{ id: number; label: string }>;
        assets: Array<{ id: number; label: string }>;
    };
    tab?: 'devices' | 'consent';
    consent_devices: DeviceConsent[];
    consent_stats: {
        total: number;
        consented: number;
        revoked: number;
        pending: number;
        expired: number;
    };
    device_detail?: DeviceDetail | null;
};

const pairDeviceSteps = [
    {
        key: 'pairing',
        label: 'Device & asset',
        blurb: 'Choose records to link',
        icon: Radio,
    },
    {
        key: 'review',
        label: 'Review',
        blurb: 'Confirm before pairing',
        icon: CheckCircle2,
    },
] as const;

const deviceDetailSteps = [
    {
        key: 'overview',
        label: 'Device overview',
        blurb: 'Status, pairing and latest data',
        icon: Wifi,
    },
    {
        key: 'telemetry',
        label: 'Recent telemetry',
        blurb: 'Latest tracker messages',
        icon: Radio,
    },
] as const;

function statusVariant(
    status: string,
): 'default' | 'secondary' | 'destructive' | 'outline' {
    switch (status) {
        case 'active':
            return 'default';
        case 'offline':
            return 'secondary';
        case 'degraded':
            return 'destructive';
        case 'maintenance':
            return 'outline';
        case 'in_stock':
            return 'secondary';
        default:
            return 'outline';
    }
}

function consentBadge(status: string) {
    switch (status) {
        case 'consented':
            return (
                <Badge variant="default">
                    <CheckCircle2 className="mr-1 h-3 w-3" />
                    Consented
                </Badge>
            );
        case 'revoked':
            return (
                <Badge variant="destructive">
                    <ShieldX className="mr-1 h-3 w-3" />
                    Revoked
                </Badge>
            );
        case 'expired':
            return (
                <Badge variant="destructive">
                    <ShieldAlert className="mr-1 h-3 w-3" />
                    Expired
                </Badge>
            );
        case 'pending':
        default:
            return (
                <Badge
                    variant="outline"
                    className="border-status-warning/30 text-status-warning dark:text-status-warning"
                >
                    <Clock className="mr-1 h-3 w-3" />
                    Pending
                </Badge>
            );
    }
}

/** Merge the given params into the current query and navigate (partial reload). */
function visitWithQuery(
    changes: Record<string, string | null>,
    options: { replace?: boolean } = {},
) {
    const params = new URLSearchParams(window.location.search);
    Object.entries(changes).forEach(([key, value]) => {
        if (value === null) params.delete(key);
        else params.set(key, value);
    });
    const qs = params.toString();
    router.get(
        `${window.location.pathname}${qs ? `?${qs}` : ''}`,
        {},
        { preserveState: true, preserveScroll: true, replace: options.replace ?? false },
    );
}

export default function DevicesIndex({
    devices,
    stats,
    pairing_options,
    tab = 'devices',
    consent_devices,
    consent_stats,
    device_detail,
}: Props) {
    const [dialogOpen, setDialogOpen] = useState(false);
    const [pairStepIndex, setPairStepIndex] = useState(0);
    const [sortField, setSortField] = useState<string>('');
    const [sortDir, setSortDir] = useState<'asc' | 'desc'>('desc');

    // Support both paginated and plain array formats
    const isPaginated = devices && !Array.isArray(devices) && 'data' in devices;
    const allDevices: Device[] = isPaginated
        ? ((devices as PaginatedDevices).data ?? [])
        : ((devices as Device[]) ?? []);
    const paginationLinks = isPaginated
        ? ((devices as PaginatedDevices).links ?? [])
        : [];
    const paginationMeta = isPaginated
        ? ((devices as PaginatedDevices).meta ?? {
              current_page: 1,
              last_page: 1,
              total: 0,
          })
        : { current_page: 1, last_page: 1, total: 0 };

    // Stats from backend (reflect all devices, not just the current page)
    const totalDevices = stats?.total ?? allDevices.length;
    const onlineCount =
        stats?.online ?? allDevices.filter((d) => d.status === 'active').length;
    const unpairedCount =
        stats?.unpaired ?? allDevices.filter((d) => !d.asset).length;
    const lowBatteryCount = stats?.low_battery ?? 0;
    const consentGranted = stats?.consent_granted ?? 0;
    const consentBlocked = stats?.consent_blocked ?? 0;
    const initialAvailableDevices = useMemo(
        () => pairing_options?.devices ?? [],
        [pairing_options?.devices],
    );
    const initialAvailableAssets = useMemo(
        () => pairing_options?.assets ?? [],
        [pairing_options?.assets],
    );
    const [availableDevices, setAvailableDevices] = useState(initialAvailableDevices);
    const [availableAssets, setAvailableAssets] = useState(initialAvailableAssets);
    const [deviceOptionSearch, setDeviceOptionSearch] = useState('');
    const [assetOptionSearch, setAssetOptionSearch] = useState('');
    const consentRows = consent_devices ?? [];

    function handleSort(field: string) {
        const newDir =
            sortField === field && sortDir === 'asc' ? 'desc' : 'asc';
        setSortField(field);
        setSortDir(newDir);
        router.get(
            window.location.pathname,
            { sort: field, direction: newDir },
            { preserveState: true },
        );
    }

    const renderSortHeader = (
        field: string,
        children: React.ReactNode,
        className?: string,
    ) => {
        const active = sortField === field;
        return (
            <th
                className={`cursor-pointer px-4 py-3 font-medium select-none hover:bg-muted/50 ${className ?? 'text-left'}`}
                onClick={() => handleSort(field)}
            >
                <div className="flex items-center gap-1">
                    {children}
                    {active ? (
                        sortDir === 'asc' ? (
                            <ChevronUp className="h-3 w-3" />
                        ) : (
                            <ChevronDown className="h-3 w-3" />
                        )
                    ) : (
                        <ChevronsUpDown className="h-3 w-3 text-muted-foreground/50" />
                    )}
                </div>
            </th>
        );
    };

    const pairForm = useForm({
        device_id: '',
        asset_id: '',
    });
    const selectedDeviceOption = [...availableDevices, ...initialAvailableDevices].find(
        (device) => String(device.id) === pairForm.data.device_id,
    );
    const selectedAssetOption = [...availableAssets, ...initialAvailableAssets].find(
        (asset) => String(asset.id) === pairForm.data.asset_id,
    );
    const visibleDeviceOptions = selectedDeviceOption && !availableDevices.some((device) => device.id === selectedDeviceOption.id)
        ? [selectedDeviceOption, ...availableDevices]
        : availableDevices;
    const visibleAssetOptions = selectedAssetOption && !availableAssets.some((asset) => asset.id === selectedAssetOption.id)
        ? [selectedAssetOption, ...availableAssets]
        : availableAssets;

    useEffect(() => {
        const query = deviceOptionSearch.trim();
        if (query.length < 2) {
            setAvailableDevices(initialAvailableDevices);
            return;
        }
        const controller = new AbortController();
        const timer = setTimeout(async () => {
            try {
                const response = await fetch(`/fleet-assets/devices/options/search?type=devices&q=${encodeURIComponent(query)}`, {
                    headers: { Accept: 'application/json' },
                    signal: controller.signal,
                });
                if (response.ok) setAvailableDevices((await response.json()).results ?? []);
            } catch (error) {
                if ((error as Error).name !== 'AbortError') setAvailableDevices([]);
            }
        }, 300);
        return () => {
            clearTimeout(timer);
            controller.abort();
        };
    }, [deviceOptionSearch, initialAvailableDevices]);

    useEffect(() => {
        const query = assetOptionSearch.trim();
        if (query.length < 2) {
            setAvailableAssets(initialAvailableAssets);
            return;
        }
        const controller = new AbortController();
        const timer = setTimeout(async () => {
            try {
                const response = await fetch(`/fleet-assets/devices/options/search?type=assets&q=${encodeURIComponent(query)}`, {
                    headers: { Accept: 'application/json' },
                    signal: controller.signal,
                });
                if (response.ok) setAvailableAssets((await response.json()).results ?? []);
            } catch (error) {
                if ((error as Error).name !== 'AbortError') setAvailableAssets([]);
            }
        }, 300);
        return () => {
            clearTimeout(timer);
            controller.abort();
        };
    }, [assetOptionSearch, initialAvailableAssets]);

    const handlePair = () => {
        pairForm.post('/fleet-assets/devices/pair', {
            onSuccess: () => {
                pairForm.reset();
                setPairStepIndex(0);
                setDialogOpen(false);
            },
        });
    };
    const closePairDialog = () => {
        setPairStepIndex(0);
        setDialogOpen(false);
    };
    const canReviewPair = Boolean(pairForm.data.device_id && pairForm.data.asset_id);
    const selectedPairDevice = availableDevices.find(
        (device) => String(device.id) === pairForm.data.device_id,
    );
    const selectedPairAsset = availableAssets.find(
        (asset) => String(asset.id) === pairForm.data.asset_id,
    );

    /* ── Tabs (Devices | Consent) ── */
    const activeTab = tab === 'consent' ? 'consent' : 'devices';
    const switchTab = (next: 'devices' | 'consent') => {
        if (next === activeTab) return;
        visitWithQuery(
            { tab: next === 'consent' ? 'consent' : null, device: null },
            { replace: true },
        );
    };

    /* ── Detail dialog (retired /devices/{id} page) ── */
    const [detailOpen, setDetailOpen] = useState(!!device_detail);
    const [detailStepIndex, setDetailStepIndex] = useState(0);
    useEffect(() => setDetailOpen(!!device_detail), [device_detail]);
    const openDevice = (id: number) => visitWithQuery({ device: String(id) });
    const closeDevice = () => {
        setDetailStepIndex(0);
        setDetailOpen(false);
        visitWithQuery({ device: null }, { replace: true });
    };

    /* ── Consent tab state ── */
    const [consentSearch, setConsentSearch] = useState('');
    const [revokeTarget, setRevokeTarget] = useState<DeviceConsent | null>(null);
    const [consentProcessing, setConsentProcessing] = useState(false);

    const filteredConsent = consentRows.filter((d) => {
        const q = consentSearch.toLowerCase();
        if (!q) return true;
        return (
            d.vendor?.toLowerCase().includes(q) ||
            d.device_uid?.toLowerCase().includes(q) ||
            d.asset?.name?.toLowerCase().includes(q) ||
            d.client_name?.toLowerCase().includes(q)
        );
    });

    const handleGrant = (device: DeviceConsent) => {
        setConsentProcessing(true);
        router.post(
            `/fleet-assets/devices/${device.id}/consent/grant`,
            {},
            {
                preserveScroll: true,
                onFinish: () => setConsentProcessing(false),
            },
        );
    };

    const handleRevoke = () => {
        if (!revokeTarget) return;
        setConsentProcessing(true);
        router.post(
            `/fleet-assets/devices/${revokeTarget.id}/consent/revoke`,
            {},
            {
                preserveScroll: true,
                onFinish: () => {
                    setConsentProcessing(false);
                    setRevokeTarget(null);
                },
            },
        );
    };

    /* ── Detail dialog map ── */
    const detail = device_detail ?? null;
    const detailSnapshots = detail?.telemetry_snapshots ?? [];
    const latestData =
        detailSnapshots.length > 0 ? (detailSnapshots[0]?.data ?? null) : null;
    const detailLat = latestData?.lat ?? latestData?.latitude ?? null;
    const detailLng = latestData?.lng ?? latestData?.longitude ?? null;
    const [showUnpairDialog, setShowUnpairDialog] = useState(false);

    const detailMarkers = useMemo<MapMarker[]>(() => {
        if (detail && detailLat && detailLng) {
            return [
                {
                    id: detail.id,
                    lat: Number(detailLat),
                    lng: Number(detailLng),
                    title: `${detail.vendor ?? ''} - ${detail.device_uid ?? ''}`,
                    type: 'vehicle' as const,
                    status: detail.device_status,
                },
            ];
        }
        return [];
    }, [detail, detailLat, detailLng]);

    const detailIsOperational =
        detail?.device_status === 'active' || detail?.device_status === 'degraded';

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Fleet & Assets', href: '/fleet-assets' },
                { title: 'Tracking Devices', href: '/fleet-assets/devices' },
            ]}
        >
            <Head title="Tracking Devices" />
            <PageShell>
                {/* ── Hero ── */}
                <HeroShell>
                    <div className="flex flex-wrap items-center gap-4">
                        <HeroMedallion icon={Radio} />
                        <div className="min-w-0">
                            <HeroStatusPill>Device registry · canonical</HeroStatusPill>
                            <h1 className="mt-1.5 text-2xl font-bold tracking-tight">
                                Tracking Devices
                            </h1>
                            <p className="mt-0.5 text-[13px] text-primary-foreground/75">
                                GPS trackers and IoT devices paired to assets, with tracking consent.
                            </p>
                        </div>
                        <div className="grid flex-1 grid-cols-2 gap-2 sm:grid-cols-4 lg:ml-auto lg:max-w-2xl">
                            <HeroClusterTile
                                label="Online"
                                value={fmt(onlineCount)}
                                caption={`of ${fmt(totalDevices)} devices`}
                                tone={onlineCount > 0 ? 'success' : 'neutral'}
                            />
                            <HeroClusterTile
                                label="Low battery"
                                value={fmt(lowBatteryCount)}
                                caption="20% or less"
                                tone={lowBatteryCount > 0 ? 'warning' : 'success'}
                            />
                            <HeroClusterTile
                                label="Consent granted"
                                value={fmt(consentGranted)}
                                caption={
                                    consentBlocked > 0
                                        ? `${fmt(consentBlocked)} blocked`
                                        : 'none blocked'
                                }
                                tone={consentBlocked > 0 ? 'warning' : 'success'}
                            />
                            <HeroClusterTile
                                label="Unpaired"
                                value={fmt(unpairedCount)}
                                caption="no asset linked"
                                tone={unpairedCount > 0 ? 'warning' : 'success'}
                            />
                        </div>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <button
                            type="button"
                            onClick={() => {
                                setPairStepIndex(0);
                                setDialogOpen(true);
                            }}
                            className="inline-flex h-[34px] items-center gap-2 rounded-lg bg-primary-foreground px-3.5 text-[12.5px] font-extrabold text-primary shadow-sm transition-colors hover:bg-primary-foreground/90 focus-visible:ring-2 focus-visible:ring-primary-foreground/40 focus-visible:outline-none"
                        >
                            <Plus className="h-[15px] w-[15px]" />
                            Pair device
                        </button>
                        <FleetHeroAction
                            href="/fleet-assets/devices?export=csv"
                            icon={Download}
                            external
                        >
                            Export CSV
                        </FleetHeroAction>
                    </div>
                </HeroShell>

                {/* ── Tab strip ── */}
                <div className="inline-flex w-fit items-center gap-1 rounded-lg border bg-muted/40 p-1">
                    {(
                        [
                            { key: 'devices', label: `Devices (${fmt(totalDevices)})` },
                            {
                                key: 'consent',
                                label: `Consent (${fmt(consent_stats?.total ?? 0)})`,
                            },
                        ] as const
                    ).map((t) => (
                        <button
                            key={t.key}
                            type="button"
                            onClick={() => switchTab(t.key)}
                            className={cn(
                                'rounded-md px-3.5 py-1.5 text-[13px] font-semibold transition-colors',
                                activeTab === t.key
                                    ? 'bg-background text-foreground shadow-sm'
                                    : 'text-muted-foreground hover:text-foreground',
                            )}
                        >
                            {t.label}
                        </button>
                    ))}
                </div>

                {activeTab === 'devices' ? (
                    <>
                        {/* Table */}
                        <div data-fleet-narrow-strategy="horizontal-scroll" className="overflow-x-auto rounded-lg border">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="bg-muted/50 text-xs tracking-wider text-muted-foreground uppercase">
                                        {renderSortHeader('provider', 'Vendor')}
                                        {renderSortHeader('device_uid', 'Device UID')}
                                        <th className="px-4 py-3 text-left font-medium">
                                            IMEI
                                        </th>
                                        <th className="px-4 py-3 text-left font-medium">
                                            Paired Asset
                                        </th>
                                        {renderSortHeader('status', 'Status')}
                                        {renderSortHeader('last_seen_at', 'Last Seen')}
                                    </tr>
                                </thead>
                                <tbody>
                                    {allDevices.length > 0 ? (
                                        allDevices.map((device) => (
                                            <tr
                                                key={device.id}
                                                className="cursor-pointer border-b transition-colors hover:bg-muted/30"
                                                onClick={() => openDevice(device.id)}
                                            >
                                                <td className="px-4 py-3">
                                                    {device.vendor}
                                                </td>
                                                <td className="px-4 py-3 font-mono">
                                                    {device.device_uid}
                                                </td>
                                                <td className="px-4 py-3 font-mono text-muted-foreground">
                                                    {device.imei ?? '---'}
                                                </td>
                                                <td className="px-4 py-3">
                                                    {device.asset ? (
                                                        <Link
                                                            href={`/fleet-assets/assets/${device.asset.id}`}
                                                            className="text-primary hover:underline"
                                                            onClick={(e) =>
                                                                e.stopPropagation()
                                                            }
                                                        >
                                                            {device.asset.name}
                                                        </Link>
                                                    ) : (
                                                        <span className="text-muted-foreground">
                                                            Not paired
                                                        </span>
                                                    )}
                                                </td>
                                                <td className="px-4 py-3">
                                                    <div className="flex items-center gap-2">
                                                        <span
                                                            className={`h-2 w-2 rounded-full ${device.status === 'online' ? 'bg-status-success' : device.status === 'stale' ? 'bg-status-critical' : 'bg-muted'}`}
                                                        />
                                                        <Badge
                                                            variant={statusVariant(
                                                                device.status,
                                                            )}
                                                        >
                                                            {device.status}
                                                        </Badge>
                                                    </div>
                                                </td>
                                                <td className="px-4 py-3 text-muted-foreground">
                                                    {device.last_seen_at
                                                        ? formatDateTime(
                                                              device.last_seen_at,
                                                          )
                                                        : '---'}
                                                </td>
                                            </tr>
                                        ))
                                    ) : (
                                        <tr>
                                            <td colSpan={6} className="px-4 py-12">
                                                <FleetEmptyState
                                                    icon={Wifi}
                                                    title="No tracking devices paired"
                                                    description="Use 'Pair device' to connect a GPS tracker or IoT unit to an asset."
                                                />
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>

                        {/* Pagination */}
                        {(paginationMeta.last_page ?? 1) > 1 && (
                            <div className="flex items-center justify-center gap-1">
                                {paginationLinks.map((link, i) => (
                                    <Button
                                        key={i}
                                        variant={link.active ? 'default' : 'outline'}
                                        size="sm"
                                        disabled={!link.url}
                                        onClick={() =>
                                            link.url &&
                                            router.get(
                                                link.url,
                                                {},
                                                { preserveState: true },
                                            )
                                        }
                                        dangerouslySetInnerHTML={{ __html: link.label }}
                                    />
                                ))}
                            </div>
                        )}
                    </>
                ) : (
                    <>
                        {/* ── Consent tab (retired /devices/consent page) ── */}
                        <div className="flex flex-wrap items-center gap-3">
                            <div className="relative max-w-sm flex-1">
                                <Search className="absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                                <Input
                                    placeholder="Search by device, asset, or client..."
                                    className="pl-10"
                                    value={consentSearch}
                                    onChange={(e) => setConsentSearch(e.target.value)}
                                />
                            </div>
                            <div className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                                <span className="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1">
                                    <ShieldCheck className="h-3.5 w-3.5 text-status-success" />
                                    {consent_stats?.consented ?? 0} consented
                                </span>
                                <span className="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1">
                                    <ShieldOff className="h-3.5 w-3.5 text-status-critical" />
                                    {consent_stats?.revoked ?? 0} revoked
                                </span>
                                <span className="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1">
                                    <Clock className="h-3.5 w-3.5 text-status-warning" />
                                    {consent_stats?.pending ?? 0} pending
                                </span>
                                <span className="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1">
                                    <ShieldAlert className="h-3.5 w-3.5 text-status-warning" />
                                    {consent_stats?.expired ?? 0} expired
                                </span>
                            </div>
                        </div>

                        <div data-fleet-narrow-strategy="horizontal-scroll" className="overflow-x-auto rounded-lg border">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="bg-muted/50 text-xs tracking-wider text-muted-foreground uppercase">
                                        <th className="px-4 py-3 text-left font-medium">Device</th>
                                        <th className="px-4 py-3 text-left font-medium">Paired Asset</th>
                                        <th className="px-4 py-3 text-left font-medium">Client</th>
                                        <th className="px-4 py-3 text-left font-medium">Consent Status</th>
                                        <th className="px-4 py-3 text-left font-medium">Granted / Revoked</th>
                                        <th className="px-4 py-3 text-left font-medium">Granted By</th>
                                        <th className="px-4 py-3 text-right font-medium">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {filteredConsent.length > 0 ? (
                                        filteredConsent.map((device) => (
                                            <tr
                                                key={device.id}
                                                className="border-b transition-colors hover:bg-muted/30"
                                            >
                                                <td className="px-4 py-3">
                                                    <div className="font-medium">{device.vendor}</div>
                                                    <div className="font-mono text-xs text-muted-foreground">
                                                        {device.device_uid}
                                                    </div>
                                                </td>
                                                <td className="px-4 py-3">
                                                    {device.asset ? (
                                                        <Link
                                                            href={`/fleet-assets/assets/${device.asset.id}`}
                                                            className="text-primary hover:underline"
                                                        >
                                                            {device.asset.name}
                                                        </Link>
                                                    ) : (
                                                        <span className="text-muted-foreground">
                                                            Not paired
                                                        </span>
                                                    )}
                                                </td>
                                                <td className="px-4 py-3 text-muted-foreground">
                                                    {device.client_name ?? '---'}
                                                </td>
                                                <td className="px-4 py-3">
                                                    {consentBadge(device.consent_status)}
                                                </td>
                                                <td className="px-4 py-3 text-muted-foreground">
                                                    {device.consent_status === 'consented' &&
                                                    device.consent_given_at
                                                        ? formatDateTime(device.consent_given_at)
                                                        : device.consent_status === 'revoked' &&
                                                            device.consent_withdrawn_at
                                                          ? formatDateTime(device.consent_withdrawn_at)
                                                          : device.consent_status === 'expired' &&
                                                              device.consent_expires_at
                                                            ? `Expired ${formatDateTime(device.consent_expires_at)}`
                                                            : '---'}
                                                </td>
                                                <td className="px-4 py-3 text-muted-foreground">
                                                    {device.consent_given_by ?? '---'}
                                                </td>
                                                <td className="px-4 py-3 text-right">
                                                    {device.consent_status === 'consented' ? (
                                                        <Button
                                                            variant="destructive"
                                                            size="sm"
                                                            onClick={() => setRevokeTarget(device)}
                                                            disabled={consentProcessing}
                                                        >
                                                            <ShieldOff className="mr-1.5 h-3.5 w-3.5" />
                                                            Revoke
                                                        </Button>
                                                    ) : (
                                                        <Button
                                                            variant="default"
                                                            size="sm"
                                                            onClick={() => handleGrant(device)}
                                                            disabled={consentProcessing}
                                                        >
                                                            <ShieldCheck className="mr-1.5 h-3.5 w-3.5" />
                                                            Grant Consent
                                                        </Button>
                                                    )}
                                                </td>
                                            </tr>
                                        ))
                                    ) : (
                                        <tr>
                                            <td colSpan={7} className="px-4 py-12">
                                                <FleetEmptyState
                                                    icon={Shield}
                                                    title="No tracking devices found"
                                                    description="Pair a GPS tracker to an asset first, then manage its consent here."
                                                />
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>

                        {/* Revoke Confirmation Dialog */}
                        <ConfirmDialog
                            open={!!revokeTarget}
                            onClose={() => setRevokeTarget(null)}
                            onConfirm={handleRevoke}
                            title="Revoke Tracking Consent"
                            description={`Are you sure you want to revoke location tracking consent for ${revokeTarget?.vendor ?? ''} ${revokeTarget?.device_uid ?? ''}? Telemetry data will no longer include GPS coordinates.`}
                            confirmText="Revoke Consent"
                            variant="destructive"
                        />
                    </>
                )}

                {/* ── Pair device dialog ── */}
                <WizardShell
                    open={dialogOpen}
                    onClose={closePairDialog}
                    title="Pair tracking device"
                    description="Link an existing tracking device to an active Fleet & Assets record."
                    railIcon={Radio}
                    railTitle="Pair device"
                    railSub="Shared device registry"
                    steps={pairDeviceSteps}
                    stepIndex={pairStepIndex}
                    onStepClick={(index) => {
                        if (index === 0 || canReviewPair) setPairStepIndex(index);
                    }}
                    footerStart={
                        <Button type="button" variant="outline" onClick={closePairDialog}>
                            Cancel
                        </Button>
                    }
                    footerEnd={
                        pairStepIndex === 0 ? (
                            <Button
                                type="button"
                                disabled={!canReviewPair}
                                onClick={() => setPairStepIndex(1)}
                            >
                                Continue
                            </Button>
                        ) : (
                            <>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => setPairStepIndex(0)}
                                >
                                    Back
                                </Button>
                                <Button
                                    type="button"
                                    onClick={handlePair}
                                    disabled={pairForm.processing}
                                >
                                    {pairForm.processing ? (
                                        <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                                    ) : (
                                        <Radio className="mr-2 h-4 w-4" />
                                    )}
                                    Pair device
                                </Button>
                            </>
                        )
                    }
                >
                    {pairStepIndex === 0 ? (
                        <WizardStepPane>
                            <div className="grid gap-5">
                            <div>
                                <label htmlFor="pair-device-id" className="text-sm font-medium">
                                    Tracking Device *
                                </label>
                                <Input
                                    value={deviceOptionSearch}
                                    onChange={(event) => setDeviceOptionSearch(event.target.value)}
                                    placeholder="Search devices..."
                                    className="mb-2"
                                />
                                <Select
                                    value={pairForm.data.device_id}
                                    onValueChange={(value) =>
                                        pairForm.setData('device_id', value)
                                    }
                                >
                                    <SelectTrigger id="pair-device-id">
                                        <SelectValue placeholder="Select an unpaired device" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {visibleDeviceOptions.map((device) => (
                                            <SelectItem
                                                key={device.id}
                                                value={String(device.id)}
                                            >
                                                {device.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div>
                                <label htmlFor="pair-asset-id" className="text-sm font-medium">
                                    Asset *
                                </label>
                                <Input
                                    value={assetOptionSearch}
                                    onChange={(event) => setAssetOptionSearch(event.target.value)}
                                    placeholder="Search assets..."
                                    className="mb-2"
                                />
                                <Select
                                    value={pairForm.data.asset_id}
                                    onValueChange={(value) =>
                                        pairForm.setData('asset_id', value)
                                    }
                                >
                                    <SelectTrigger id="pair-asset-id">
                                        <SelectValue placeholder="Select an asset" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {visibleAssetOptions.map((asset) => (
                                            <SelectItem
                                                key={asset.id}
                                                value={String(asset.id)}
                                            >
                                                {asset.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            {availableDevices.length === 0 && (
                                <p className="text-xs text-muted-foreground">
                                    No unpaired tracking devices are available. Register
                                    one in Security & Devices first, then return here to
                                    link it.
                                </p>
                            )}
                            {pairForm.errors.device_id && (
                                <p className="text-xs text-destructive">
                                    {pairForm.errors.device_id}
                                </p>
                            )}
                            {pairForm.errors.asset_id && (
                                <p className="text-xs text-destructive">
                                    {pairForm.errors.asset_id}
                                </p>
                            )}
                            </div>
                        </WizardStepPane>
                    ) : (
                        <WizardStepPane>
                            <dl className="space-y-4 rounded-xl border border-border bg-card/70 p-4 text-sm">
                                <div>
                                    <dt className="text-muted-foreground">Tracking device</dt>
                                    <dd className="font-medium">
                                        {selectedPairDevice?.label ?? 'Selected device'}
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-muted-foreground">Asset</dt>
                                    <dd className="font-medium">
                                        {selectedPairAsset?.label ?? 'Selected asset'}
                                    </dd>
                                </div>
                            </dl>
                        </WizardStepPane>
                    )}
                </WizardShell>

                {/* ── Device detail dialog (retired /devices/{id} page) ── */}
                <WizardShell
                    open={detailOpen}
                    onClose={closeDevice}
                    title={detail ? `${detail.vendor} - ${detail.device_uid}` : 'Device detail'}
                    description="Canonical tracking device detail, telemetry and pairing status."
                    railIcon={Radio}
                    railTitle="Device detail"
                    railSub={detail?.device_uid ?? 'Loading tracker'}
                    steps={deviceDetailSteps}
                    stepIndex={detailStepIndex}
                    onStepClick={setDetailStepIndex}
                    headerLabel={deviceDetailSteps[detailStepIndex]?.label}
                    footerStart={
                        <Button type="button" variant="outline" onClick={closeDevice}>
                            Close
                        </Button>
                    }
                    footerEnd={
                        detail?.asset && detail.link_status === 'paired' ? (
                            <Button
                                type="button"
                                variant="destructive"
                                onClick={() => setShowUnpairDialog(true)}
                            >
                                Unpair device
                            </Button>
                        ) : null
                    }
                >
                    {detail ? (
                        detailStepIndex === 0 ? (
                            <WizardStepPane>
                                {/* Status banner */}
                                <div
                                    className={cn(
                                        'rounded-lg border px-4 py-3',
                                        detail.link_status === 'paired'
                                            ? 'border-primary/30 bg-primary/10 text-primary dark:bg-primary/20'
                                            : 'border-border bg-muted text-foreground',
                                    )}
                                >
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <div className="flex flex-wrap items-center gap-2">
                                            {detailIsOperational ? (
                                                <Wifi className="h-4 w-4" />
                                            ) : (
                                                <WifiOff className="h-4 w-4" />
                                            )}
                                            <Badge
                                                variant={
                                                    detail.link_status === 'paired'
                                                        ? 'default'
                                                        : 'secondary'
                                                }
                                            >
                                                {detail.link_status}
                                            </Badge>
                                            <Badge
                                                variant={
                                                    detailIsOperational
                                                        ? 'secondary'
                                                        : 'outline'
                                                }
                                            >
                                                {detail.device_status ?? 'unknown'}
                                            </Badge>
                                            {detail.battery_level != null && (
                                                <span className="inline-flex items-center gap-1 text-xs">
                                                    <Battery className="h-3.5 w-3.5" />
                                                    {detail.battery_level}%
                                                </span>
                                            )}
                                        </div>
                                    </div>
                                    {detail.last_seen_at && (
                                        <div className="mt-1.5 text-xs opacity-70">
                                            Last seen: {formatDateTime(detail.last_seen_at)}
                                        </div>
                                    )}
                                </div>

                                <div className="grid gap-4 sm:grid-cols-2">
                                    {/* Device details */}
                                    <dl className="space-y-2 text-sm">
                                        <div className="rounded-md bg-muted/40 p-3">
                                            <dt className="text-xs text-muted-foreground">Vendor</dt>
                                            <dd className="mt-1 font-medium">
                                                {detail.vendor ?? '---'}
                                            </dd>
                                        </div>
                                        <div className="rounded-md bg-muted/40 p-3">
                                            <dt className="text-xs text-muted-foreground">Device UID</dt>
                                            <dd className="mt-1 font-mono font-medium">
                                                {detail.device_uid ?? '---'}
                                            </dd>
                                        </div>
                                        {detail.imei && (
                                            <div className="rounded-md bg-muted/40 p-3">
                                                <dt className="text-xs text-muted-foreground">IMEI</dt>
                                                <dd className="mt-1 font-mono font-medium">
                                                    {detail.imei}
                                                </dd>
                                            </div>
                                        )}
                                        {detail.serial_number && (
                                            <div className="rounded-md bg-muted/40 p-3">
                                                <dt className="text-xs text-muted-foreground">
                                                    Serial Number
                                                </dt>
                                                <dd className="mt-1 font-mono font-medium">
                                                    {detail.serial_number}
                                                </dd>
                                            </div>
                                        )}
                                        <div className="rounded-md bg-muted/40 p-3">
                                            <dt className="text-xs text-muted-foreground">
                                                Paired Asset
                                            </dt>
                                            <dd className="mt-1">
                                                {detail.asset ? (
                                                    <Link
                                                        href={`/fleet-assets/assets/${detail.asset.id}`}
                                                        className="font-medium text-primary hover:underline"
                                                    >
                                                        {detail.asset.name}
                                                    </Link>
                                                ) : (
                                                    <span className="text-muted-foreground">
                                                        Not paired
                                                    </span>
                                                )}
                                            </dd>
                                        </div>
                                        {detail.paired_at && (
                                            <div className="rounded-md bg-muted/40 p-3">
                                                <dt className="text-xs text-muted-foreground">
                                                    Paired At
                                                </dt>
                                                <dd className="mt-1 font-medium">
                                                    {formatDateTime(detail.paired_at)}
                                                </dd>
                                            </div>
                                        )}
                                    </dl>

                                    {/* Latest data */}
                                    <div className="space-y-3">
                                        <div>
                                            <p className="mb-2 text-sm font-semibold">
                                                Latest Data
                                            </p>
                                            {latestData ? (
                                                <dl className="max-h-56 space-y-1.5 overflow-y-auto text-sm">
                                                    {Object.entries(latestData).map(
                                                        ([key, value]) => (
                                                            <div
                                                                key={key}
                                                                className="flex justify-between rounded-md bg-muted/40 px-3 py-1.5"
                                                            >
                                                                <dt className="text-muted-foreground capitalize">
                                                                    {key.replace(/_/g, ' ')}
                                                                </dt>
                                                                <dd className="font-mono font-medium">
                                                                    {value != null
                                                                        ? String(value)
                                                                        : '---'}
                                                                </dd>
                                                            </div>
                                                        ),
                                                    )}
                                                </dl>
                                            ) : (
                                                <p className="text-sm text-muted-foreground">
                                                    No telemetry data available.
                                                </p>
                                            )}
                                        </div>
                                        {detailMarkers.length > 0 && (
                                            <div>
                                                <p className="mb-2 flex items-center gap-1.5 text-sm font-semibold">
                                                    <MapPin className="h-4 w-4" />
                                                    Location
                                                </p>
                                                <LeafletMap
                                                    center={{
                                                        lat: Number(detailLat),
                                                        lng: Number(detailLng),
                                                    }}
                                                    zoom={15}
                                                    markers={detailMarkers}
                                                    height={200}
                                                />
                                            </div>
                                        )}
                                    </div>
                                </div>
                            </WizardStepPane>
                        ) : (
                            <WizardStepPane>
                                {/* Telemetry history */}
                                <div>
                                    <p className="mb-2 text-sm font-semibold">
                                        Recent Telemetry
                                        {detailSnapshots.length > 0 && (
                                            <Badge
                                                variant="secondary"
                                                className="ml-2 text-xs"
                                            >
                                                {detailSnapshots.length}
                                            </Badge>
                                        )}
                                    </p>
                                    {detailSnapshots.length > 0 ? (
                                        <div className="max-h-48 space-y-2 overflow-y-auto">
                                            {detailSnapshots.map((s) => (
                                                <div
                                                    key={s.id}
                                                    className="flex items-center justify-between rounded-md border p-2.5 text-xs transition-colors hover:bg-muted/30"
                                                >
                                                    <div className="flex items-center gap-2">
                                                        <Radio className="h-3.5 w-3.5 text-primary" />
                                                        <span className="text-muted-foreground">
                                                            {s.created_at
                                                                ? formatDateTime(s.created_at)
                                                                : '---'}
                                                        </span>
                                                    </div>
                                                    <div className="flex items-center gap-3 text-muted-foreground">
                                                        {s.data ? (
                                                            <span className="max-w-xs truncate font-mono">
                                                                {JSON.stringify(s.data).substring(0, 80)}
                                                                {JSON.stringify(s.data).length > 80
                                                                    ? '...'
                                                                    : ''}
                                                            </span>
                                                        ) : (
                                                            <span>No data</span>
                                                        )}
                                                    </div>
                                                </div>
                                            ))}
                                        </div>
                                    ) : (
                                        <p className="text-sm text-muted-foreground">
                                            No telemetry history.
                                        </p>
                                    )}
                                </div>
                            </WizardStepPane>
                        )
                        ) : (
                            <div className="flex items-center gap-2 text-sm text-muted-foreground">
                                <Loader2 className="h-4 w-4 animate-spin" />
                                Loading device detail…
                            </div>
                        )}
                </WizardShell>
                {detail ? (
                    <ConfirmDialog
                        open={showUnpairDialog}
                        onClose={() => setShowUnpairDialog(false)}
                        onConfirm={() => {
                            setShowUnpairDialog(false);
                            router.post(
                                `/fleet-assets/devices/${detail.id}/unpair`,
                                {},
                                { preserveScroll: true },
                            );
                        }}
                        title="Unpair Device"
                        description={`Are you sure you want to unpair this device from ${detail.asset?.name ?? 'the asset'}? The device will stop tracking.`}
                        confirmText="Unpair"
                    />
                ) : null}
            </PageShell>
        </AppLayout>
    );
}
