import { FLEET_COLORS, ProgressRing } from '@/components/fleet-charts';
import { FleetEmptyState } from '@/components/fleet-empty-state';
import FleetHero from '@/components/fleet-hero';
import { FleetStatCard } from '@/components/fleet-stat-card';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime } from '@/lib/fleet-utils';
import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    ChevronDown,
    ChevronUp,
    ChevronsUpDown,
    Download,
    Loader2,
    Plus,
    Radio,
    Rss,
    Unplug,
    Wifi,
    WifiOff,
} from 'lucide-react';
import type React from 'react';
import { useState } from 'react';

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

type Props = {
    devices: PaginatedDevices | Device[];
    stats?: {
        total: number;
        online: number;
        offline: number;
        unpaired: number;
    };
    pairing_options: {
        devices: Array<{ id: number; label: string }>;
        assets: Array<{ id: number; label: string }>;
    };
};

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

export default function DevicesIndex({
    devices,
    stats,
    pairing_options,
}: Props) {
    const [dialogOpen, setDialogOpen] = useState(false);
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

    // Use stats from backend (reflects all devices, not just current page)
    const totalDevices = stats?.total ?? allDevices.length;
    const onlineCount =
        stats?.online ?? allDevices.filter((d) => d.status === 'active').length;
    const offlineCount =
        stats?.offline ??
        allDevices.filter(
            (d) => d.status === 'offline' || d.status === 'degraded',
        ).length;
    const unpairedCount =
        stats?.unpaired ?? allDevices.filter((d) => !d.asset).length;
    const onlinePct =
        totalDevices > 0 ? Math.round((onlineCount / totalDevices) * 100) : 0;
    const availableDevices = pairing_options?.devices ?? [];
    const availableAssets = pairing_options?.assets ?? [];

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

    const handlePair = (e: React.FormEvent) => {
        e.preventDefault();
        pairForm.post('/fleet-assets/devices/pair', {
            onSuccess: () => {
                pairForm.reset();
                setDialogOpen(false);
            },
        });
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Fleet & Assets', href: '/fleet-assets' },
                { title: 'Tracking Devices', href: '/fleet-assets/devices' },
            ]}
        >
            <Head title="Tracking Devices" />
            <PageShell>
                <FleetHero
                    title="Tracking Devices"
                    description="Manage GPS trackers and IoT devices paired to assets."
                    actions={
                        <div className="flex gap-2">
                            <Button variant="outline" size="sm" asChild>
                                <a href="/fleet-assets/devices?export=csv">
                                    <Download className="mr-2 h-4 w-4" />
                                    Export CSV
                                </a>
                            </Button>
                            <Dialog
                                open={dialogOpen}
                                onOpenChange={setDialogOpen}
                            >
                                <DialogTrigger asChild>
                                    <Button>
                                        <Plus className="mr-2 h-4 w-4" />
                                        Pair Device
                                    </Button>
                                </DialogTrigger>
                                <DialogContent>
                                    <DialogHeader>
                                        <DialogTitle>
                                            Pair Tracking Device
                                        </DialogTitle>
                                        <DialogDescription>
                                            Link an existing tracking device
                                            from the shared registry to a fleet
                                            asset.
                                        </DialogDescription>
                                    </DialogHeader>
                                    <form
                                        onSubmit={handlePair}
                                        className="grid gap-4"
                                    >
                                        <div>
                                            <label className="text-sm font-medium">
                                                Tracking Device *
                                            </label>
                                            <Select
                                                value={pairForm.data.device_id}
                                                onValueChange={(value) =>
                                                    pairForm.setData(
                                                        'device_id',
                                                        value,
                                                    )
                                                }
                                            >
                                                <SelectTrigger>
                                                    <SelectValue placeholder="Select an unpaired device" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {availableDevices.map(
                                                        (device) => (
                                                            <SelectItem
                                                                key={device.id}
                                                                value={String(
                                                                    device.id,
                                                                )}
                                                            >
                                                                {device.label}
                                                            </SelectItem>
                                                        ),
                                                    )}
                                                </SelectContent>
                                            </Select>
                                        </div>
                                        <div>
                                            <label className="text-sm font-medium">
                                                Vehicle Asset *
                                            </label>
                                            <Select
                                                value={pairForm.data.asset_id}
                                                onValueChange={(value) =>
                                                    pairForm.setData(
                                                        'asset_id',
                                                        value,
                                                    )
                                                }
                                            >
                                                <SelectTrigger>
                                                    <SelectValue placeholder="Select a vehicle" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {availableAssets.map(
                                                        (asset) => (
                                                            <SelectItem
                                                                key={asset.id}
                                                                value={String(
                                                                    asset.id,
                                                                )}
                                                            >
                                                                {asset.label}
                                                            </SelectItem>
                                                        ),
                                                    )}
                                                </SelectContent>
                                            </Select>
                                        </div>
                                        {availableDevices.length === 0 && (
                                            <p className="text-xs text-muted-foreground">
                                                No unpaired tracking devices are
                                                available. Register one in
                                                Security & Devices first, then
                                                return here to link it.
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
                                        <Button
                                            type="submit"
                                            disabled={
                                                pairForm.processing ||
                                                !pairForm.data.device_id ||
                                                !pairForm.data.asset_id
                                            }
                                        >
                                            {pairForm.processing ? (
                                                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                                            ) : (
                                                <Radio className="mr-2 h-4 w-4" />
                                            )}
                                            Pair Device
                                        </Button>
                                    </form>
                                </DialogContent>
                            </Dialog>
                        </div>
                    }
                />

                {/* Dark KPI Cards + ProgressRing */}
                <div className="grid gap-3 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-5">
                    <FleetStatCard
                        label="TOTAL DEVICES"
                        value={totalDevices}
                        icon={Rss}
                        subtitle="All paired devices"
                    />
                    <FleetStatCard
                        label="ONLINE"
                        value={onlineCount}
                        icon={Wifi}
                        color="amber"
                        valueClassName="text-status-success"
                        subtitle="Currently reporting"
                    />
                    <FleetStatCard
                        label="OFFLINE"
                        value={offlineCount}
                        icon={WifiOff}
                        color="red"
                        valueClassName="text-status-critical"
                        subtitle="Not responding"
                    />
                    <FleetStatCard
                        label="UNPAIRED"
                        value={unpairedCount}
                        icon={Unplug}
                        subtitle="No asset linked"
                    />
                    <Card className="border bg-primary/10 dark:bg-primary/20">
                        <CardContent className="flex items-center justify-center p-4">
                            <ProgressRing
                                value={onlinePct}
                                size={80}
                                color={FLEET_COLORS.primary}
                                label="Online %"
                            />
                        </CardContent>
                    </Card>
                </div>

                {/* Table */}
                <div className="overflow-hidden rounded-lg border">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="bg-muted/50 text-xs tracking-wider text-muted-foreground uppercase">
                                {renderSortHeader('vendor', 'Vendor')}
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
                                        onClick={() =>
                                            router.visit(
                                                `/fleet-assets/devices/${device.id}`,
                                            )
                                        }
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
                                            description="Use 'Pair Device' to connect a GPS tracker or IoT unit to an asset."
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
            </PageShell>
        </AppLayout>
    );
}
