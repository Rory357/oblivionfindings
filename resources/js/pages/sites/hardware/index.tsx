import AppLayout from '@/layouts/app-layout';
import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
    AlertDialogTrigger,
} from '@/components/ui/alert-dialog';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    ArrowRight,
    Ban,
    Cpu,
    DoorOpen,
    ExternalLink,
    HelpCircle,
    MapPin,
    Pencil,
    Plus,
    Search,
    Shield,
    Trash2,
    Wifi,
    WifiOff,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { PlanThumbnail, type PlanLayout, type PlanPin } from '../plan/_thumbnail';

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

type Site = { id: number; name: string; type: string };

type Room = { id: number; name: string; sort_order: number };

/**
 * Canonical device as supplied by the Security & Devices registry.
 * This page is a read-only context view; all device CRUD + provider config
 * lives in the Security & Devices module.
 */
type DeviceItem = {
    id: number;
    device_uid: string;
    name: string;
    domain: string;
    category: string;
    subcategory: string | null;
    manufacturer: string | null;
    model: string | null;
    serial_number: string | null;
    mac_address: string | null;
    asset_tag: string | null;
    status: string;
    health_status: string;
    provider: string | null;
    provider_entity_id?: string | null;
    provider_type?: string | null;
    last_seen_at: string | null;
    battery_level: number | null;
    firmware_version: string | null;
    ip_address: string | null;
    notes: string | null;
    assignment_type: string | null;
    assignment_id: number | null;
    plan_pin?: PlanPin | null;
};

type Permissions = {
    manage_hardware?: boolean;
};

type TypePlanSummary = {
    tab_label: string;
    status: string;
    draft?: { layout: PlanLayout; pins: PlanPin[] } | null;
    published?: { layout: PlanLayout; pins: PlanPin[] } | null;
    has_plan: boolean;
};

type Props = {
    site: Site;
    devices: DeviceItem[];
    rooms: Room[];
    can: Permissions;
    typePlan?: TypePlanSummary | null;
};

// ---------------------------------------------------------------------------
// Status helpers
// ---------------------------------------------------------------------------

type DeviceStatusKey =
    | 'active'
    | 'offline'
    | 'degraded'
    | 'maintenance'
    | 'decommissioned'
    | 'in_stock'
    | 'lost';

const deviceStatusConfig: Record<
    DeviceStatusKey,
    { label: string; className: string; icon: typeof Wifi }
> = {
    active: {
        label: 'Online',
        className: 'bg-status-success-bg text-status-success border-status-success/30',
        icon: Wifi,
    },
    offline: {
        label: 'Offline',
        className: 'bg-status-critical-bg text-status-critical border-status-critical/30',
        icon: WifiOff,
    },
    degraded: {
        label: 'Degraded',
        className: 'bg-status-warning-bg text-status-warning border-status-warning/30',
        icon: HelpCircle,
    },
    maintenance: {
        label: 'Maintenance',
        className: 'bg-status-info-bg text-status-info border-status-info/30',
        icon: HelpCircle,
    },
    decommissioned: {
        label: 'Retired',
        className: 'bg-muted-foreground/80/10 text-muted-foreground border-border/20',
        icon: Ban,
    },
    in_stock: {
        label: 'In Stock',
        className: 'bg-muted-foreground/80/20 text-muted-foreground border-border/30',
        icon: HelpCircle,
    },
    lost: {
        label: 'Lost',
        className: 'bg-status-critical-bg text-status-critical border-status-critical/20',
        icon: Ban,
    },
};

function getDeviceStatus(status: string) {
    return (
        deviceStatusConfig[status as DeviceStatusKey] ?? {
            label: status || 'Unknown',
            className: 'bg-muted-foreground/80/20 text-muted-foreground border-border/30',
            icon: HelpCircle,
        }
    );
}

function formatDateTime(value?: string | null): string {
    if (!value) return '—';
    const d = new Date(value);
    if (Number.isNaN(d.getTime())) return value;
    return d.toLocaleString(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

// ---------------------------------------------------------------------------
// Main component
// ---------------------------------------------------------------------------

export default function SiteHardware({ site, devices, rooms, can, typePlan = null }: Props) {
    // ── filter / search state ──────────────────────────────────────
    const [search, setSearch] = useState('');
    const [filterStatus, setFilterStatus] = useState<string>('all');
    const [filterCategory, setFilterCategory] = useState<string>('all');
    const [filterProvider, setFilterProvider] = useState<string>('all');

    // ── room management state ─────────────────────────────────────
    const [showAddRoom, setShowAddRoom] = useState(false);
    const [editingRoomId, setEditingRoomId] = useState<number | null>(null);
    const [editingRoomName, setEditingRoomName] = useState('');
    const [addingRoom, setAddingRoom] = useState(false);
    const [savingRoomId, setSavingRoomId] = useState<number | null>(null);

    // ── device → room assignment (row-level only; physical placement) ─
    const [assigningDeviceId, setAssigningDeviceId] = useState<number | null>(null);
    const [pinningDevice, setPinningDevice] = useState<DeviceItem | null>(null);
    const [pinDraft, setPinDraft] = useState<{ x: number; y: number } | null>(null);
    const [savingPin, setSavingPin] = useState(false);
    const [deviceRoomDraft, setDeviceRoomDraft] = useState<Record<number, string>>(() =>
        devices.reduce<Record<number, string>>((acc, d) => {
            acc[d.id] =
                d.assignment_type === 'room' && d.assignment_id
                    ? String(d.assignment_id)
                    : 'unassigned';
            return acc;
        }, {}),
    );

    // ── forms ──────────────────────────────────────────────────────
    const roomForm = useForm<{ name: string }>({ name: '' });

    // ── derived values ─────────────────────────────────────────────
    const stats = useMemo(() => {
        const total = devices.length;
        const online = devices.filter((d) => d.status === 'active').length;
        const offline = devices.filter((d) => d.status === 'offline' || d.status === 'degraded').length;
        const unassigned = devices.filter((d) => d.assignment_type !== 'room').length;
        return { total, online, offline, unassigned };
    }, [devices]);

    const providerOptions = useMemo(() => {
        const providers = Array.from(
            new Set(devices.map((d) => d.provider).filter(Boolean) as string[]),
        );
        return providers.sort((a, b) => a.localeCompare(b));
    }, [devices]);

    const statusOptions = useMemo(() => {
        const values = Array.from(new Set(devices.map((d) => d.status).filter(Boolean)));
        return values.sort((a, b) => a.localeCompare(b));
    }, [devices]);

    const categoryOptions = useMemo(() => {
        const values = Array.from(new Set(devices.map((d) => d.category).filter(Boolean)));
        return values.sort((a, b) => a.localeCompare(b));
    }, [devices]);

    const filteredDevices = useMemo(() => {
        return devices.filter((d) => {
            if (filterStatus !== 'all' && d.status !== filterStatus) return false;
            if (filterCategory !== 'all' && d.category !== filterCategory) return false;
            if (filterProvider !== 'all' && d.provider !== filterProvider) return false;
            if (search) {
                const q = search.toLowerCase();
                const haystack = [
                    d.name,
                    d.device_uid,
                    d.asset_tag,
                    d.serial_number,
                    d.mac_address,
                    d.provider,
                    d.category,
                ]
                    .filter(Boolean)
                    .join(' ')
                    .toLowerCase();
                if (!haystack.includes(q)) return false;
            }
            return true;
        });
    }, [devices, search, filterStatus, filterCategory, filterProvider]);

    const canManageHardware = !!can?.manage_hardware;
    const planForPins = typePlan?.published ?? typePlan?.draft ?? null;

    // ── handlers ───────────────────────────────────────────────────
    function submitRoom(e: React.FormEvent) {
        e.preventDefault();
        setAddingRoom(true);
        router.post(
            `/sites/${site.id}/hardware/rooms`,
            { action: 'add', name: roomForm.data.name },
            {
                preserveScroll: true,
                onSuccess: () => {
                    roomForm.reset();
                    setShowAddRoom(false);
                },
                onFinish: () => setAddingRoom(false),
            },
        );
    }

    function startEditRoom(room: Room) {
        setEditingRoomId(room.id);
        setEditingRoomName(room.name);
    }

    function submitEditRoom(roomId: number) {
        setSavingRoomId(roomId);
        router.post(
            `/sites/${site.id}/hardware/rooms`,
            { action: 'rename', room_id: roomId, name: editingRoomName },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setEditingRoomId(null);
                    setEditingRoomName('');
                },
                onFinish: () => setSavingRoomId(null),
            },
        );
    }

    function deleteRoom(roomId: number) {
        setSavingRoomId(roomId);
        router.post(
            `/sites/${site.id}/hardware/rooms`,
            { action: 'delete', room_id: roomId },
            {
                preserveScroll: true,
                onFinish: () => setSavingRoomId(null),
            },
        );
    }

    function assignDeviceRoom(deviceId: number) {
        const raw = deviceRoomDraft[deviceId];
        const roomId = !raw || raw === 'unassigned' ? null : Number(raw);
        setAssigningDeviceId(deviceId);
        router.post(
            `/sites/${site.id}/hardware/${deviceId}/assign-room`,
            { room_id: roomId },
            {
                preserveScroll: true,
                onFinish: () => setAssigningDeviceId(null),
            },
        );
    }

    function openPinDialog(device: DeviceItem) {
        setPinningDevice(device);
        setPinDraft(device.plan_pin ? { x: device.plan_pin.x, y: device.plan_pin.y } : null);
    }

    function savePlanPin() {
        if (!pinningDevice || !pinDraft) return;

        setSavingPin(true);
        router.post(
            `/sites/${site.id}/hardware/${pinningDevice.id}/pin`,
            {
                x: pinDraft.x,
                y: pinDraft.y,
                label: pinningDevice.name || pinningDevice.device_uid,
            },
            {
                preserveScroll: true,
                onFinish: () => setSavingPin(false),
                onSuccess: () => {
                    setPinningDevice(null);
                    setPinDraft(null);
                },
            },
        );
    }

    function removePlanPin(device: DeviceItem) {
        setSavingPin(true);
        router.delete(`/sites/${site.id}/hardware/${device.id}/pin`, {
            preserveScroll: true,
            onFinish: () => setSavingPin(false),
            onSuccess: () => {
                setPinningDevice(null);
                setPinDraft(null);
            },
        });
    }

    function deviceCountInRoom(roomId: number) {
        return devices.filter(
            (d) => d.assignment_type === 'room' && d.assignment_id === roomId,
        ).length;
    }

    // ── render ─────────────────────────────────────────────────────
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Sites', href: '/sites' },
                { title: site.name, href: `/sites/${site.id}` },
                { title: 'Hardware', href: `/sites/${site.id}/hardware` },
            ]}
        >
            <Head title={`${site.name} - Hardware`} />

            <PageShell>
                <PageHeader
                    title="Hardware at this location"
                    description="Read-only view of devices at this site, with room placement. Device management lives in Security & Devices."
                    actions={
                        <div className="flex items-center gap-2">
                            <Badge variant="outline">
                                {stats.total} device{stats.total !== 1 ? 's' : ''}
                            </Badge>
                            {planForPins && (
                                <Button asChild variant="outline">
                                    <Link href={`/sites/${site.id}?tab=type-plan`}>
                                        <MapPin className="mr-1 h-4 w-4" />
                                        Open {typePlan?.tab_label ?? 'Plan'}
                                    </Link>
                                </Button>
                            )}
                            <Button asChild variant="outline">
                                <a href={`/security-devices/devices?site_id=${site.id}`}>
                                    Manage in Security &amp; Devices
                                    <ArrowRight className="ml-1 h-4 w-4" />
                                </a>
                            </Button>
                            <Button asChild>
                                <a
                                    href={`/security-devices/devices/create?domain=&site_id=${site.id}`}
                                >
                                    <Plus className="mr-1 h-4 w-4" />
                                    Register Device
                                </a>
                            </Button>
                        </div>
                    }
                />

                {/* ── Ownership banner ─────────────────────────────── */}
                <Card className="border-dashed bg-muted/30">
                    <CardContent className="flex flex-col gap-3 p-4 text-sm sm:flex-row sm:items-center sm:gap-6">
                        <div className="flex items-start gap-3">
                            <Shield className="mt-0.5 h-4 w-4 text-primary" />
                            <p className="leading-6 text-muted-foreground">
                                This page shows the devices assigned to{' '}
                                <span className="font-medium text-foreground">
                                    {site.name}
                                </span>
                                . Credentials, provider sync, device creation and
                                reassignment happen in{' '}
                                <a
                                    href="/security-devices"
                                    className="font-medium text-primary hover:underline"
                                >
                                    Security &amp; Devices
                                </a>
                                .
                            </p>
                        </div>
                        <div className="flex flex-wrap gap-2 sm:ml-auto">
                            <Button asChild size="sm" variant="outline">
                                <a href="/security-devices/integrations">
                                    <ExternalLink className="mr-1 h-3.5 w-3.5" />
                                    APIs &amp; Integrations
                                </a>
                            </Button>
                        </div>
                    </CardContent>
                </Card>

                {/* ── Stats strip ──────────────────────────────────── */}
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <Card>
                        <CardContent className="flex items-center gap-3 p-4">
                            <div className="rounded-lg bg-muted-foreground/80/10 p-2">
                                <Cpu className="h-5 w-5 text-muted-foreground" />
                            </div>
                            <div>
                                <div className="text-2xl font-bold">{stats.total}</div>
                                <div className="text-sm text-muted-foreground">Total devices</div>
                            </div>
                        </CardContent>
                    </Card>
                    <Card className="border-status-success/20 bg-status-success">
                        <CardContent className="flex items-center gap-3 p-4">
                            <div className="rounded-lg bg-status-success p-2">
                                <Wifi className="h-5 w-5 text-status-success" />
                            </div>
                            <div>
                                <div className="text-2xl font-bold text-status-success">
                                    {stats.online}
                                </div>
                                <div className="text-sm text-muted-foreground">Online</div>
                            </div>
                        </CardContent>
                    </Card>
                    <Card className="border-status-critical/20 bg-status-critical">
                        <CardContent className="flex items-center gap-3 p-4">
                            <div className="rounded-lg bg-status-critical p-2">
                                <WifiOff className="h-5 w-5 text-status-critical" />
                            </div>
                            <div>
                                <div className="text-2xl font-bold text-status-critical">
                                    {stats.offline}
                                </div>
                                <div className="text-sm text-muted-foreground">Offline / degraded</div>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="flex items-center gap-3 p-4">
                            <div className="rounded-lg bg-status-warning p-2">
                                <HelpCircle className="h-5 w-5 text-status-warning" />
                            </div>
                            <div>
                                <div className="text-2xl font-bold text-status-warning">
                                    {stats.unassigned}
                                </div>
                                <div className="text-sm text-muted-foreground">Unassigned to rooms</div>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* ── Filters ─────────────────────────────────────── */}
                <Card>
                    <CardContent className="flex flex-wrap items-center gap-3 p-4">
                        <div className="relative min-w-[240px] flex-1">
                            <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                            <Input
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder="Search by name, tag, serial, MAC…"
                                className="pl-9"
                            />
                        </div>
                        <Select value={filterStatus} onValueChange={setFilterStatus}>
                            <SelectTrigger className="w-[140px]">
                                <SelectValue placeholder="Status" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All statuses</SelectItem>
                                {statusOptions.map((s) => (
                                    <SelectItem key={s} value={s}>
                                        {getDeviceStatus(s).label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <Select value={filterCategory} onValueChange={setFilterCategory}>
                            <SelectTrigger className="w-[160px]">
                                <SelectValue placeholder="Category" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All categories</SelectItem>
                                {categoryOptions.map((c) => (
                                    <SelectItem key={c} value={c}>
                                        {c}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <Select value={filterProvider} onValueChange={setFilterProvider}>
                            <SelectTrigger className="w-[140px]">
                                <SelectValue placeholder="Provider" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All providers</SelectItem>
                                {providerOptions.map((p) => (
                                    <SelectItem key={p} value={p}>
                                        {p}
                                    </SelectItem>
                                ))}
                                <SelectItem value="manual">manual</SelectItem>
                            </SelectContent>
                        </Select>
                    </CardContent>
                </Card>

                {/* ── Device table ────────────────────────────────── */}
                <Card>
                    <CardHeader>
                        <CardTitle>Devices</CardTitle>
                        <CardDescription>
                            Click any device to open it in Security &amp; Devices.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div className="rounded-md border">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Status</TableHead>
                                        <TableHead>Name</TableHead>
                                        <TableHead>Category</TableHead>
                                        <TableHead>Provider</TableHead>
                                        <TableHead>Model</TableHead>
                                        <TableHead>Room</TableHead>
                                        <TableHead>Plan Pin</TableHead>
                                        <TableHead>Last seen</TableHead>
                                        <TableHead className="text-right">Actions</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {filteredDevices.map((d) => {
                                        const badge = getDeviceStatus(d.status);
                                        const draft = deviceRoomDraft[d.id] ?? 'unassigned';
                                        const saving = assigningDeviceId === d.id;
                                        return (
                                            <TableRow key={d.id}>
                                                <TableCell>
                                                    <Badge
                                                        variant="outline"
                                                        className={badge.className}
                                                    >
                                                        <badge.icon className="mr-1 h-3 w-3" />
                                                        {badge.label}
                                                    </Badge>
                                                </TableCell>
                                                <TableCell>
                                                    <a
                                                        href={`/security-devices/devices/${d.id}`}
                                                        className="font-medium hover:underline"
                                                    >
                                                        {d.name || d.device_uid}
                                                    </a>
                                                    {d.asset_tag && (
                                                        <div className="text-xs text-muted-foreground">
                                                            {d.asset_tag}
                                                        </div>
                                                    )}
                                                </TableCell>
                                                <TableCell className="text-sm">
                                                    {d.subcategory ?? d.category}
                                                </TableCell>
                                                <TableCell>
                                                    {d.provider ? (
                                                        <Badge variant="secondary">{d.provider}</Badge>
                                                    ) : (
                                                        <Badge variant="outline">manual</Badge>
                                                    )}
                                                </TableCell>
                                                <TableCell className="text-sm text-muted-foreground">
                                                    {d.model ?? '—'}
                                                </TableCell>
                                                <TableCell className="min-w-[170px]">
                                                    <div className="flex items-center gap-2">
                                                        <Select
                                                            value={draft}
                                                            onValueChange={(v) =>
                                                                setDeviceRoomDraft((prev) => ({
                                                                    ...prev,
                                                                    [d.id]: v,
                                                                }))
                                                            }
                                                            disabled={!canManageHardware}
                                                        >
                                                            <SelectTrigger className="h-8 w-[160px]">
                                                                <SelectValue placeholder="Room" />
                                                            </SelectTrigger>
                                                            <SelectContent>
                                                                <SelectItem value="unassigned">
                                                                    Unassigned
                                                                </SelectItem>
                                                                {rooms.map((r) => (
                                                                    <SelectItem
                                                                        key={r.id}
                                                                        value={String(r.id)}
                                                                    >
                                                                        {r.name}
                                                                    </SelectItem>
                                                                ))}
                                                            </SelectContent>
                                                        </Select>
                                                        {canManageHardware &&
                                                            draft !==
                                                                (d.assignment_type === 'room' &&
                                                                d.assignment_id
                                                                    ? String(d.assignment_id)
                                                                    : 'unassigned') && (
                                                                <Button
                                                                    size="sm"
                                                                    variant="outline"
                                                                    onClick={() => assignDeviceRoom(d.id)}
                                                                    disabled={saving}
                                                                >
                                                                    {saving ? 'Saving…' : 'Save'}
                                                                </Button>
                                                            )}
                                                    </div>
                                                </TableCell>
                                                <TableCell>
                                                    {d.plan_pin ? (
                                                        <div className="flex items-center gap-2">
                                                            <Badge variant="outline">
                                                                <MapPin className="mr-1 h-3 w-3" />
                                                                Pinned
                                                            </Badge>
                                                            {canManageHardware && (
                                                                <Button
                                                                    size="sm"
                                                                    variant="ghost"
                                                                    onClick={() => openPinDialog(d)}
                                                                >
                                                                    Move
                                                                </Button>
                                                            )}
                                                        </div>
                                                    ) : canManageHardware && planForPins ? (
                                                        <Button
                                                            size="sm"
                                                            variant="outline"
                                                            onClick={() => openPinDialog(d)}
                                                        >
                                                            <MapPin className="mr-1 h-3.5 w-3.5" />
                                                            Pin
                                                        </Button>
                                                    ) : (
                                                        <span className="text-sm text-muted-foreground">-</span>
                                                    )}
                                                </TableCell>
                                                <TableCell className="text-sm text-muted-foreground">
                                                    {formatDateTime(d.last_seen_at)}
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    <Button asChild size="sm" variant="ghost">
                                                        <a
                                                            href={`/security-devices/devices/${d.id}`}
                                                        >
                                                            View
                                                            <ArrowRight className="ml-1 h-3.5 w-3.5" />
                                                        </a>
                                                    </Button>
                                                </TableCell>
                                            </TableRow>
                                        );
                                    })}
                                    {filteredDevices.length === 0 && (
                                        <TableRow>
                                            <TableCell
                                                colSpan={9}
                                                className="py-8 text-center text-sm text-muted-foreground"
                                            >
                                                {devices.length === 0
                                                    ? 'No devices assigned to this site yet.'
                                                    : 'No devices match the current filters.'}
                                            </TableCell>
                                        </TableRow>
                                    )}
                                </TableBody>
                            </Table>
                        </div>
                    </CardContent>
                </Card>

                {/* ── Room management ─────────────────────────────── */}
                <Card>
                    <CardHeader className="flex flex-row items-center justify-between space-y-0">
                        <div>
                            <CardTitle>Rooms</CardTitle>
                            <CardDescription>
                                Physical rooms at this site. Used for placing devices.
                            </CardDescription>
                        </div>
                        {canManageHardware && !showAddRoom && (
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() => setShowAddRoom(true)}
                            >
                                <Plus className="mr-1 h-4 w-4" />
                                Add room
                            </Button>
                        )}
                    </CardHeader>
                    <CardContent className="space-y-4">
                        {showAddRoom && (
                            <form
                                onSubmit={submitRoom}
                                className="flex flex-wrap items-end gap-3 rounded-lg border p-4"
                            >
                                <div className="flex-1 space-y-1">
                                    <Label htmlFor="room_name">Room name</Label>
                                    <Input
                                        id="room_name"
                                        value={roomForm.data.name}
                                        onChange={(e) => roomForm.setData('name', e.target.value)}
                                        placeholder="e.g. Lounge"
                                    />
                                </div>
                                <Button
                                    type="submit"
                                    size="sm"
                                    disabled={addingRoom || !roomForm.data.name.trim()}
                                >
                                    {addingRoom ? 'Saving…' : 'Save'}
                                </Button>
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="ghost"
                                    onClick={() => {
                                        setShowAddRoom(false);
                                        roomForm.reset();
                                    }}
                                >
                                    Cancel
                                </Button>
                            </form>
                        )}

                        {rooms.length === 0 ? (
                            <div className="rounded-md border border-dashed p-6 text-center text-sm text-muted-foreground">
                                No rooms yet.
                                {canManageHardware && ' Add one to start placing devices.'}
                            </div>
                        ) : (
                            <div className="rounded-md border">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Room</TableHead>
                                            <TableHead>Devices</TableHead>
                                            <TableHead className="text-right">Actions</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {rooms.map((room) => {
                                            const isEditing = editingRoomId === room.id;
                                            const count = deviceCountInRoom(room.id);
                                            const saving = savingRoomId === room.id;
                                            return (
                                                <TableRow key={room.id}>
                                                    <TableCell>
                                                        {isEditing ? (
                                                            <Input
                                                                value={editingRoomName}
                                                                onChange={(e) =>
                                                                    setEditingRoomName(e.target.value)
                                                                }
                                                                className="h-8 max-w-xs"
                                                            />
                                                        ) : (
                                                            <div className="flex items-center gap-2">
                                                                <DoorOpen className="h-4 w-4 text-muted-foreground" />
                                                                <span className="font-medium">
                                                                    {room.name}
                                                                </span>
                                                            </div>
                                                        )}
                                                    </TableCell>
                                                    <TableCell className="text-sm text-muted-foreground">
                                                        {count} device{count === 1 ? '' : 's'}
                                                    </TableCell>
                                                    <TableCell className="text-right">
                                                        {canManageHardware && (
                                                            <div className="flex justify-end gap-2">
                                                                {isEditing ? (
                                                                    <>
                                                                        <Button
                                                                            size="sm"
                                                                            onClick={() =>
                                                                                submitEditRoom(room.id)
                                                                            }
                                                                            disabled={
                                                                                saving ||
                                                                                !editingRoomName.trim()
                                                                            }
                                                                        >
                                                                            {saving ? 'Saving…' : 'Save'}
                                                                        </Button>
                                                                        <Button
                                                                            size="sm"
                                                                            variant="ghost"
                                                                            onClick={() => {
                                                                                setEditingRoomId(null);
                                                                                setEditingRoomName('');
                                                                            }}
                                                                        >
                                                                            Cancel
                                                                        </Button>
                                                                    </>
                                                                ) : (
                                                                    <>
                                                                        <Button
                                                                            size="sm"
                                                                            variant="ghost"
                                                                            onClick={() =>
                                                                                startEditRoom(room)
                                                                            }
                                                                        >
                                                                            <Pencil className="h-3.5 w-3.5" />
                                                                        </Button>
                                                                        <AlertDialog>
                                                                            <AlertDialogTrigger asChild>
                                                                                <Button
                                                                                    size="sm"
                                                                                    variant="ghost"
                                                                                    disabled={count > 0}
                                                                                >
                                                                                    <Trash2 className="h-3.5 w-3.5" />
                                                                                </Button>
                                                                            </AlertDialogTrigger>
                                                                            <AlertDialogContent>
                                                                                <AlertDialogHeader>
                                                                                    <AlertDialogTitle>
                                                                                        Delete room "
                                                                                        {room.name}"?
                                                                                    </AlertDialogTitle>
                                                                                    <AlertDialogDescription>
                                                                                        This cannot be
                                                                                        undone. Devices
                                                                                        must be moved
                                                                                        out first.
                                                                                    </AlertDialogDescription>
                                                                                </AlertDialogHeader>
                                                                                <AlertDialogFooter>
                                                                                    <AlertDialogCancel>
                                                                                        Cancel
                                                                                    </AlertDialogCancel>
                                                                                    <AlertDialogAction
                                                                                        onClick={() =>
                                                                                            deleteRoom(
                                                                                                room.id,
                                                                                            )
                                                                                        }
                                                                                    >
                                                                                        Delete
                                                                                    </AlertDialogAction>
                                                                                </AlertDialogFooter>
                                                                            </AlertDialogContent>
                                                                        </AlertDialog>
                                                                    </>
                                                                )}
                                                            </div>
                                                        )}
                                                    </TableCell>
                                                </TableRow>
                                            );
                                        })}
                                    </TableBody>
                                </Table>
                            </div>
                        )}
                    </CardContent>
                </Card>
            </PageShell>

            <Dialog
                open={pinningDevice !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setPinningDevice(null);
                        setPinDraft(null);
                    }
                }}
            >
                <DialogContent className="max-w-4xl">
                    <DialogHeader>
                        <DialogTitle>
                            Pin {pinningDevice?.name ?? 'device'} to {typePlan?.tab_label ?? 'plan'}
                        </DialogTitle>
                    </DialogHeader>
                    {planForPins ? (
                        <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_220px]">
                            <PlanThumbnail
                                layout={planForPins.layout}
                                pins={[
                                    ...planForPins.pins.filter(
                                        (pin) =>
                                            pin.kind !== 'device' ||
                                            pin.device_id !== pinningDevice?.id,
                                    ),
                                    ...(pinDraft && pinningDevice
                                        ? [
                                              {
                                                  kind: 'device',
                                                  device_id: pinningDevice.id,
                                                  label: pinningDevice.name || pinningDevice.device_uid,
                                                  x: pinDraft.x,
                                                  y: pinDraft.y,
                                              },
                                          ]
                                        : []),
                                ]}
                                onCanvasClick={setPinDraft}
                                className="min-h-[420px]"
                            />
                            <div className="space-y-3 text-sm">
                                <div className="rounded-md border p-3">
                                    <div className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                        Coordinates
                                    </div>
                                    <div className="mt-2 font-mono">
                                        {pinDraft
                                            ? `${Math.round(pinDraft.x * 100)}%, ${Math.round(pinDraft.y * 100)}%`
                                            : 'Not set'}
                                    </div>
                                </div>
                                {pinningDevice?.plan_pin && (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        className="w-full justify-start"
                                        onClick={() => removePlanPin(pinningDevice)}
                                        disabled={savingPin}
                                    >
                                        Remove pin
                                    </Button>
                                )}
                            </div>
                        </div>
                    ) : (
                        <div className="rounded-md border border-dashed p-6 text-sm text-muted-foreground">
                            Build a site plan before pinning devices.
                        </div>
                    )}
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => {
                                setPinningDevice(null);
                                setPinDraft(null);
                            }}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="button"
                            onClick={savePlanPin}
                            disabled={!pinDraft || savingPin || !planForPins}
                        >
                            Save pin
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
