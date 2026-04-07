import { FleetEmptyState } from '@/components/fleet-empty-state';
import { FleetStatCard } from '@/components/fleet-stat-card';
import { FLEET_COLORS, ProgressRing } from '@/components/fleet-charts';
import FleetHero from '@/components/fleet-hero';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import type React from 'react';
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
import { useState } from 'react';
import { formatDateTime } from '@/lib/fleet-utils';


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
    stats?: { total: number; online: number; offline: number; unpaired: number };
};

function statusVariant(status: string): 'default' | 'secondary' | 'destructive' | 'outline' {
    switch (status) {
        case 'online': return 'default';
        case 'offline': return 'secondary';
        case 'stale': return 'destructive';
        default: return 'outline';
    }
}

export default function DevicesIndex({ devices, stats }: Props) {
    const [dialogOpen, setDialogOpen] = useState(false);
    const [sortField, setSortField] = useState<string>('');
    const [sortDir, setSortDir] = useState<'asc' | 'desc'>('desc');

    // Support both paginated and plain array formats
    const isPaginated = devices && !Array.isArray(devices) && 'data' in devices;
    const allDevices: Device[] = isPaginated ? (devices as PaginatedDevices).data ?? [] : (devices as Device[]) ?? [];
    const paginationLinks = isPaginated ? (devices as PaginatedDevices).links ?? [] : [];
    const paginationMeta = isPaginated ? (devices as PaginatedDevices).meta ?? { current_page: 1, last_page: 1, total: 0 } : { current_page: 1, last_page: 1, total: 0 };

    // Use stats from backend (reflects all devices, not just current page)
    const totalDevices = stats?.total ?? allDevices.length;
    const onlineCount = stats?.online ?? allDevices.filter((d) => d.status === 'online').length;
    const offlineCount = stats?.offline ?? allDevices.filter((d) => d.status === 'offline' || d.status === 'stale').length;
    const unpairedCount = stats?.unpaired ?? allDevices.filter((d) => !d.asset).length;
    const onlinePct = totalDevices > 0 ? Math.round((onlineCount / totalDevices) * 100) : 0;

    function handleSort(field: string) {
        const newDir = sortField === field && sortDir === 'asc' ? 'desc' : 'asc';
        setSortField(field);
        setSortDir(newDir);
        router.get(window.location.pathname, { sort: field, direction: newDir }, { preserveState: true });
    }

    function SortHeader({ field, children, className }: { field: string; children: React.ReactNode; className?: string }) {
        const active = sortField === field;
        return (
            <th className={`px-4 py-3 cursor-pointer select-none hover:bg-muted/50 font-medium ${className ?? 'text-left'}`} onClick={() => handleSort(field)}>
                <div className="flex items-center gap-1">
                    {children}
                    {active ? (sortDir === 'asc' ? <ChevronUp className="h-3 w-3" /> : <ChevronDown className="h-3 w-3" />) : <ChevronsUpDown className="h-3 w-3 text-muted-foreground/50" />}
                </div>
            </th>
        );
    }

    const pairForm = useForm({
        vendor: '',
        device_uid: '',
        imei: '',
        serial_number: '',
        asset_id: '',
    });

    const handlePair = (e: React.FormEvent) => {
        e.preventDefault();
        pairForm.post('/fleet-assets/devices', {
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
                    actions={<div className="flex gap-2"><Button variant="outline" size="sm" asChild><a href="/fleet-assets/devices?export=csv"><Download className="mr-2 h-4 w-4" />Export CSV</a></Button>
                        <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
                            <DialogTrigger asChild>
                                <Button>
                                    <Plus className="mr-2 h-4 w-4" />
                                    Pair Device
                                </Button>
                            </DialogTrigger>
                            <DialogContent>
                                <DialogHeader>
                                    <DialogTitle>Pair New Device</DialogTitle>
                                </DialogHeader>
                                <form onSubmit={handlePair} className="grid gap-4">
                                    <div>
                                        <label className="text-sm font-medium">Vendor *</label>
                                        <Input
                                            value={pairForm.data.vendor}
                                            onChange={(e) => pairForm.setData('vendor', e.target.value)}
                                            placeholder="e.g. Digital Matter"
                                        />
                                    </div>
                                    <div>
                                        <label className="text-sm font-medium">Device UID *</label>
                                        <Input
                                            value={pairForm.data.device_uid}
                                            onChange={(e) => pairForm.setData('device_uid', e.target.value)}
                                        />
                                    </div>
                                    <div>
                                        <label className="text-sm font-medium">IMEI</label>
                                        <Input
                                            value={pairForm.data.imei}
                                            onChange={(e) => pairForm.setData('imei', e.target.value)}
                                        />
                                    </div>
                                    <div>
                                        <label className="text-sm font-medium">Serial Number</label>
                                        <Input
                                            value={pairForm.data.serial_number}
                                            onChange={(e) => pairForm.setData('serial_number', e.target.value)}
                                        />
                                    </div>
                                    <div>
                                        <label className="text-sm font-medium">Asset ID *</label>
                                        <Input
                                            type="number"
                                            value={pairForm.data.asset_id}
                                            onChange={(e) => pairForm.setData('asset_id', e.target.value)}
                                            placeholder="Enter asset ID"
                                        />
                                    </div>
                                    {pairForm.errors.vendor && <p className="text-xs text-destructive">{pairForm.errors.vendor}</p>}
                                    {pairForm.errors.device_uid && <p className="text-xs text-destructive">{pairForm.errors.device_uid}</p>}
                                    {pairForm.errors.asset_id && <p className="text-xs text-destructive">{pairForm.errors.asset_id}</p>}
                                    <Button type="submit" disabled={pairForm.processing}>
                                        {pairForm.processing ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : <Radio className="mr-2 h-4 w-4" />}
                                        Pair Device
                                    </Button>
                                </form>
                            </DialogContent>
                        </Dialog>
                        </div>}
                />

                {/* Dark KPI Cards + ProgressRing */}
                <div className="grid gap-3 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-5">
                    <FleetStatCard label="TOTAL DEVICES" value={totalDevices} icon={Rss} subtitle="All paired devices" />
                    <FleetStatCard label="ONLINE" value={onlineCount} icon={Wifi} color="amber" valueClassName="text-green-400" subtitle="Currently reporting" />
                    <FleetStatCard label="OFFLINE" value={offlineCount} icon={WifiOff} color="red" valueClassName="text-red-400" subtitle="Not responding" />
                    <FleetStatCard label="UNPAIRED" value={unpairedCount} icon={Unplug} subtitle="No asset linked" />
                    <Card className="border bg-purple-50 dark:bg-purple-950/20">
                        <CardContent className="flex items-center justify-center p-4">
                            <ProgressRing value={onlinePct} size={80} color={FLEET_COLORS.primary} label="Online %" />
                        </CardContent>
                    </Card>
                </div>

                {/* Table */}
                <div className="rounded-lg border overflow-hidden">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="bg-muted/50 text-xs uppercase tracking-wider text-muted-foreground">
                                <SortHeader field="vendor">Vendor</SortHeader>
                                <SortHeader field="device_uid">Device UID</SortHeader>
                                <th className="px-4 py-3 text-left font-medium">IMEI</th>
                                <th className="px-4 py-3 text-left font-medium">Paired Asset</th>
                                <SortHeader field="status">Status</SortHeader>
                                <SortHeader field="last_seen_at">Last Seen</SortHeader>
                            </tr>
                        </thead>
                        <tbody>
                            {allDevices.length > 0 ? (
                                allDevices.map((device) => (
                                    <tr
                                        key={device.id}
                                        className="border-b transition-colors hover:bg-muted/30 transition-colors cursor-pointer"
                                        onClick={() => router.visit(`/fleet-assets/devices/${device.id}`)}
                                    >
                                        <td className="px-4 py-3">{device.vendor}</td>
                                        <td className="px-4 py-3 font-mono">{device.device_uid}</td>
                                        <td className="px-4 py-3 font-mono text-muted-foreground">{device.imei ?? '---'}</td>
                                        <td className="px-4 py-3">
                                            {device.asset ? (
                                                <Link
                                                    href={`/fleet-assets/assets/${device.asset.id}`}
                                                    className="text-primary hover:underline"
                                                    onClick={(e) => e.stopPropagation()}
                                                >
                                                    {device.asset.name}
                                                </Link>
                                            ) : (
                                                <span className="text-muted-foreground">Not paired</span>
                                            )}
                                        </td>
                                        <td className="px-4 py-3">
                                            <div className="flex items-center gap-2">
                                                <span className={`h-2 w-2 rounded-full ${device.status === 'online' ? 'bg-green-500' : device.status === 'stale' ? 'bg-red-500' : 'bg-gray-400'}`} />
                                                <Badge variant={statusVariant(device.status)}>{device.status}</Badge>
                                            </div>
                                        </td>
                                        <td className="px-4 py-3 text-muted-foreground">
                                            {device.last_seen_at ? formatDateTime(device.last_seen_at) : '---'}
                                        </td>
                                    </tr>
                                ))
                            ) : (
                                <tr>
                                    <td colSpan={6} className="px-4 py-12">
                                        <FleetEmptyState icon={Wifi} title="No tracking devices paired" description="Use 'Pair Device' to connect a GPS tracker or IoT unit to an asset." />
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
                                onClick={() => link.url && router.get(link.url, {}, { preserveState: true })}
                                dangerouslySetInnerHTML={{ __html: link.label }}
                            />
                        ))}
                    </div>
                )}
            </PageShell>
        </AppLayout>
    );
}
