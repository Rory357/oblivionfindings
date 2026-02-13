import AppLayout from '@/layouts/app-layout';
import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Head, router, useForm } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Badge } from '@/components/ui/badge';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
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
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Cpu,
    Plus,
    Search,
    Pencil,
    Trash2,
    DoorOpen,
    Link2,
    Wifi,
    WifiOff,
    HelpCircle,
    Ban,
    RefreshCw,
    Settings,
    MonitorSmartphone,
    Server,
} from 'lucide-react';
import { useMemo, useState } from 'react';

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

type Site = { id: number; name: string; type: string };

type Room = { id: number; name: string; sort_order: number };

type HardwareItem = {
    id: number;
    provider: string;
    category: string;
    name: string;
    asset_tag?: string;
    serial?: string;
    mac?: string;
    status: 'online' | 'offline' | 'unknown' | 'retired';
    last_seen_at?: string;
    room?: { id: number; name: string } | null;
    linked_asset?: { id: number; name: string; asset_tag?: string } | null;
    notes?: string;
};

type IntegrationConfig = {
    id: number;
    provider: string;
    status: string;
    mapped_external_site_id?: string;
    mapped_external_site_name?: string;
    is_active: boolean;
};

type AssetLite = { id: number; name: string; asset_tag?: string };

type Props = {
    site: Site;
    hardware: HardwareItem[];
    rooms: Room[];
    integrations: IntegrationConfig[];
    assets: AssetLite[];
    categories: Record<string, string>;
};

// ---------------------------------------------------------------------------
// Status helpers
// ---------------------------------------------------------------------------

const statusConfig: Record<
    HardwareItem['status'],
    { label: string; className: string; icon: typeof Wifi }
> = {
    online: {
        label: 'Online',
        className: 'bg-emerald-500/20 text-emerald-400 border-emerald-500/30',
        icon: Wifi,
    },
    offline: {
        label: 'Offline',
        className: 'bg-red-500/20 text-red-400 border-red-500/30',
        icon: WifiOff,
    },
    unknown: {
        label: 'Unknown',
        className: 'bg-slate-500/20 text-slate-300 border-slate-500/30',
        icon: HelpCircle,
    },
    retired: {
        label: 'Retired',
        className: 'bg-slate-500/10 text-slate-500 border-slate-500/20',
        icon: Ban,
    },
};

const statusDotColor: Record<HardwareItem['status'], string> = {
    online: 'bg-emerald-500',
    offline: 'bg-red-500',
    unknown: 'bg-slate-400',
    retired: 'bg-slate-500 opacity-50',
};

// ---------------------------------------------------------------------------
// Main component
// ---------------------------------------------------------------------------

export default function SiteHardware({
    site,
    hardware,
    rooms,
    integrations,
    assets,
    categories,
}: Props) {
    // --- state -----------------------------------------------------------------
    const [search, setSearch] = useState('');
    const [filterStatus, setFilterStatus] = useState<string>('all');
    const [filterCategory, setFilterCategory] = useState<string>('all');

    const [showAddDialog, setShowAddDialog] = useState(false);
    const [editingItem, setEditingItem] = useState<HardwareItem | null>(null);

    const [assignRoomItem, setAssignRoomItem] = useState<HardwareItem | null>(null);
    const [linkAssetItem, setLinkAssetItem] = useState<HardwareItem | null>(null);

    const [showAddRoom, setShowAddRoom] = useState(false);
    const [editingRoomId, setEditingRoomId] = useState<number | null>(null);
    const [editingRoomName, setEditingRoomName] = useState('');

    const [syncingProvider, setSyncingProvider] = useState<string | null>(null);

    // --- forms -----------------------------------------------------------------
    const hwForm = useForm<{
        name: string;
        category: string;
        provider: string;
        room_id: string;
        asset_tag: string;
        serial: string;
        mac: string;
        notes: string;
    }>({
        name: '',
        category: '',
        provider: 'manual',
        room_id: '',
        asset_tag: '',
        serial: '',
        mac: '',
        notes: '',
    });

    const roomForm = useForm<{ name: string }>({ name: '' });

    const assignRoomForm = useForm<{ room_id: string }>({ room_id: '' });
    const linkAssetForm = useForm<{ linked_asset_id: string }>({ linked_asset_id: '' });

    // --- derived ---------------------------------------------------------------
    const stats = useMemo(() => {
        const total = hardware.length;
        const online = hardware.filter((h) => h.status === 'online').length;
        const offline = hardware.filter((h) => h.status === 'offline').length;
        const unassigned = hardware.filter((h) => !h.room).length;
        return { total, online, offline, unassigned };
    }, [hardware]);

    const filteredHardware = useMemo(() => {
        return hardware.filter((h) => {
            if (filterStatus !== 'all' && h.status !== filterStatus) return false;
            if (filterCategory !== 'all' && h.category !== filterCategory) return false;
            if (search) {
                const q = search.toLowerCase();
                const haystack = [h.name, h.asset_tag, h.serial, h.mac, h.provider, h.category, h.room?.name]
                    .filter(Boolean)
                    .join(' ')
                    .toLowerCase();
                if (!haystack.includes(q)) return false;
            }
            return true;
        });
    }, [hardware, search, filterStatus, filterCategory]);

    const categoryKeys = Object.keys(categories);

    // --- handlers --------------------------------------------------------------
    function openAdd() {
        hwForm.reset();
        hwForm.setData({
            name: '',
            category: categoryKeys[0] || '',
            provider: 'manual',
            room_id: '',
            asset_tag: '',
            serial: '',
            mac: '',
            notes: '',
        });
        setEditingItem(null);
        setShowAddDialog(true);
    }

    function openEdit(item: HardwareItem) {
        setEditingItem(item);
        hwForm.setData({
            name: item.name,
            category: item.category,
            provider: item.provider,
            room_id: item.room?.id?.toString() || '',
            asset_tag: item.asset_tag || '',
            serial: item.serial || '',
            mac: item.mac || '',
            notes: item.notes || '',
        });
        setShowAddDialog(true);
    }

    function closeHwDialog() {
        setShowAddDialog(false);
        setEditingItem(null);
        hwForm.reset();
    }

    function submitHardware(e: React.FormEvent) {
        e.preventDefault();
        if (editingItem) {
            hwForm.put(`/sites/${site.id}/hardware/${editingItem.id}`, {
                preserveScroll: true,
                onSuccess: closeHwDialog,
            });
        } else {
            hwForm.post(`/sites/${site.id}/hardware`, {
                preserveScroll: true,
                onSuccess: closeHwDialog,
            });
        }
    }

    function deleteHardware(id: number) {
        router.delete(`/sites/${site.id}/hardware/${id}`, { preserveScroll: true });
    }

    // Rooms
    function submitRoom(e: React.FormEvent) {
        e.preventDefault();
        roomForm.post(`/sites/${site.id}/rooms`, {
            preserveScroll: true,
            onSuccess: () => {
                roomForm.reset();
                setShowAddRoom(false);
            },
        });
    }

    function startEditRoom(room: Room) {
        setEditingRoomId(room.id);
        setEditingRoomName(room.name);
    }

    function submitEditRoom(roomId: number) {
        router.put(
            `/sites/${site.id}/rooms/${roomId}`,
            { name: editingRoomName },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setEditingRoomId(null);
                    setEditingRoomName('');
                },
            },
        );
    }

    function deleteRoom(roomId: number) {
        router.delete(`/sites/${site.id}/rooms/${roomId}`, { preserveScroll: true });
    }

    // Assign room
    function openAssignRoom(item: HardwareItem) {
        setAssignRoomItem(item);
        assignRoomForm.setData('room_id', item.room?.id?.toString() || '');
    }

    function submitAssignRoom(e: React.FormEvent) {
        e.preventDefault();
        if (!assignRoomItem) return;
        assignRoomForm.post(`/sites/${site.id}/hardware/${assignRoomItem.id}/assign-room`, {
            preserveScroll: true,
            onSuccess: () => {
                setAssignRoomItem(null);
                assignRoomForm.reset();
            },
        });
    }

    // Link asset
    function openLinkAsset(item: HardwareItem) {
        setLinkAssetItem(item);
        linkAssetForm.setData('linked_asset_id', item.linked_asset?.id?.toString() || '');
    }

    function submitLinkAsset(e: React.FormEvent) {
        e.preventDefault();
        if (!linkAssetItem) return;
        linkAssetForm.post(`/sites/${site.id}/hardware/${linkAssetItem.id}/link-asset`, {
            preserveScroll: true,
            onSuccess: () => {
                setLinkAssetItem(null);
                linkAssetForm.reset();
            },
        });
    }

    // Sync devices
    function syncDevices(provider: string) {
        setSyncingProvider(provider);
        router.post(`/sites/${site.id}/integrations/${provider}/sync-devices`, {}, {
            preserveScroll: true,
            onFinish: () => setSyncingProvider(null),
        });
    }

    // --- helpers ---------------------------------------------------------------
    function hwCountInRoom(roomId: number) {
        return hardware.filter((h) => h.room?.id === roomId).length;
    }

    function formatDate(dateStr?: string) {
        if (!dateStr) return null;
        try {
            return new Date(dateStr).toLocaleDateString(undefined, {
                year: 'numeric',
                month: 'short',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit',
            });
        } catch {
            return dateStr;
        }
    }

    // --- render ----------------------------------------------------------------
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Sites', href: '/sites' },
                { title: site.name, href: `/sites/${site.id}` },
                { title: 'Hardware & Configuration', href: `/sites/${site.id}/hardware` },
            ]}
        >
            <Head title={`${site.name} - Hardware & Configuration`} />

            <PageShell>
                <PageHeader
                    title="Location Hardware & Configuration"
                    actions={
                        <div className="flex items-center gap-2">
                            <Badge variant="outline" className="text-slate-300">
                                {stats.total} item{stats.total !== 1 ? 's' : ''}
                            </Badge>
                            <Button onClick={openAdd}>
                                <Plus className="w-4 h-4 mr-1" />
                                Add Hardware
                            </Button>
                        </div>
                    }
                />

                {/* ------------------------------------------------------------------ */}
                {/* Section 1 - Hardware Overview                                      */}
                {/* ------------------------------------------------------------------ */}

                {/* Stats row */}
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <Card>
                        <CardContent className="p-4">
                            <div className="flex items-center gap-3">
                                <div className="rounded-lg bg-slate-500/10 p-2">
                                    <Cpu className="w-5 h-5 text-slate-300" />
                                </div>
                                <div>
                                    <div className="text-2xl font-bold">{stats.total}</div>
                                    <div className="text-sm text-slate-400">Total Hardware</div>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                    <Card className="bg-emerald-500/5 border-emerald-500/20">
                        <CardContent className="p-4">
                            <div className="flex items-center gap-3">
                                <div className="rounded-lg bg-emerald-500/10 p-2">
                                    <Wifi className="w-5 h-5 text-emerald-400" />
                                </div>
                                <div>
                                    <div className="text-2xl font-bold text-emerald-400">{stats.online}</div>
                                    <div className="text-sm text-slate-400">Online</div>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                    <Card className="bg-red-500/5 border-red-500/20">
                        <CardContent className="p-4">
                            <div className="flex items-center gap-3">
                                <div className="rounded-lg bg-red-500/10 p-2">
                                    <WifiOff className="w-5 h-5 text-red-400" />
                                </div>
                                <div>
                                    <div className="text-2xl font-bold text-red-400">{stats.offline}</div>
                                    <div className="text-sm text-slate-400">Offline</div>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="p-4">
                            <div className="flex items-center gap-3">
                                <div className="rounded-lg bg-amber-500/10 p-2">
                                    <HelpCircle className="w-5 h-5 text-amber-400" />
                                </div>
                                <div>
                                    <div className="text-2xl font-bold text-amber-400">{stats.unassigned}</div>
                                    <div className="text-sm text-slate-400">Unassigned</div>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* Search / Filter bar */}
                {hardware.length > 0 && (
                    <div className="flex flex-col gap-3 sm:flex-row">
                        <div className="relative flex-1">
                            <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400" />
                            <Input
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder="Search hardware by name, tag, serial, MAC..."
                                className="pl-10"
                            />
                        </div>
                        <Select value={filterStatus} onValueChange={setFilterStatus}>
                            <SelectTrigger className="w-full sm:w-40">
                                <SelectValue placeholder="Status" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All Statuses</SelectItem>
                                <SelectItem value="online">Online</SelectItem>
                                <SelectItem value="offline">Offline</SelectItem>
                                <SelectItem value="unknown">Unknown</SelectItem>
                                <SelectItem value="retired">Retired</SelectItem>
                            </SelectContent>
                        </Select>
                        {categoryKeys.length > 0 && (
                            <Select value={filterCategory} onValueChange={setFilterCategory}>
                                <SelectTrigger className="w-full sm:w-44">
                                    <SelectValue placeholder="Category" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All Categories</SelectItem>
                                    {categoryKeys.map((key) => (
                                        <SelectItem key={key} value={key}>
                                            {categories[key]}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        )}
                    </div>
                )}

                {/* Hardware list */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">
                            Hardware Items
                            {filteredHardware.length !== hardware.length && (
                                <span className="ml-2 text-sm font-normal text-slate-400">
                                    Showing {filteredHardware.length} of {hardware.length}
                                </span>
                            )}
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        {hardware.length === 0 ? (
                            <div className="text-center py-12 text-slate-400">
                                <MonitorSmartphone className="w-12 h-12 mx-auto mb-3 opacity-50" />
                                <p className="text-lg font-medium mb-1">No hardware registered</p>
                                <p className="text-sm">Add your first hardware item or sync from an integration.</p>
                                <Button onClick={openAdd} className="mt-4">
                                    <Plus className="w-4 h-4 mr-1" />
                                    Add Hardware
                                </Button>
                            </div>
                        ) : filteredHardware.length === 0 ? (
                            <div className="text-center py-8 text-slate-400">
                                <Search className="w-10 h-10 mx-auto mb-3 opacity-50" />
                                <p>No hardware items match your filters.</p>
                            </div>
                        ) : (
                            <div className="space-y-2">
                                {filteredHardware.map((item) => {
                                    const sc = statusConfig[item.status];
                                    const StatusIcon = sc.icon;
                                    return (
                                        <div
                                            key={item.id}
                                            className="rounded-xl border p-4 hover:bg-muted/50 transition-colors"
                                        >
                                            <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                                <div className="flex-1 min-w-0">
                                                    <div className="flex items-center gap-2 flex-wrap">
                                                        <span
                                                            className={`inline-block w-2.5 h-2.5 rounded-full ${statusDotColor[item.status]}`}
                                                        />
                                                        <span className="font-medium">{item.name}</span>
                                                        <Badge variant="outline" className="text-xs">
                                                            {categories[item.category] || item.category}
                                                        </Badge>
                                                        <Badge
                                                            variant="outline"
                                                            className="text-xs border-indigo-500/30 text-indigo-300"
                                                        >
                                                            {item.provider}
                                                        </Badge>
                                                        <Badge variant="outline" className={`text-xs ${sc.className}`}>
                                                            <StatusIcon className="w-3 h-3 mr-1" />
                                                            {sc.label}
                                                        </Badge>
                                                    </div>

                                                    <div className="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-sm text-slate-400">
                                                        {item.room && (
                                                            <span className="flex items-center gap-1">
                                                                <DoorOpen className="w-3.5 h-3.5" />
                                                                {item.room.name}
                                                            </span>
                                                        )}
                                                        {item.linked_asset && (
                                                            <span className="flex items-center gap-1">
                                                                <Link2 className="w-3.5 h-3.5" />
                                                                {item.linked_asset.name}
                                                                {item.linked_asset.asset_tag && (
                                                                    <span className="text-xs text-slate-500">
                                                                        ({item.linked_asset.asset_tag})
                                                                    </span>
                                                                )}
                                                            </span>
                                                        )}
                                                        {item.mac && (
                                                            <span className="font-mono text-xs">MAC: {item.mac}</span>
                                                        )}
                                                        {item.serial && (
                                                            <span className="font-mono text-xs">S/N: {item.serial}</span>
                                                        )}
                                                        {item.asset_tag && (
                                                            <span className="font-mono text-xs">Tag: {item.asset_tag}</span>
                                                        )}
                                                    </div>

                                                    {item.last_seen_at && (
                                                        <div className="mt-1 text-xs text-slate-500">
                                                            Last seen: {formatDate(item.last_seen_at)}
                                                        </div>
                                                    )}
                                                </div>

                                                <div className="flex items-center gap-1 shrink-0">
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() => openEdit(item)}
                                                        title="Edit"
                                                    >
                                                        <Pencil className="w-4 h-4" />
                                                    </Button>
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() => openAssignRoom(item)}
                                                        title="Assign Room"
                                                    >
                                                        <DoorOpen className="w-4 h-4" />
                                                    </Button>
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() => openLinkAsset(item)}
                                                        title="Link Asset"
                                                    >
                                                        <Link2 className="w-4 h-4" />
                                                    </Button>
                                                    <AlertDialog>
                                                        <AlertDialogTrigger asChild>
                                                            <Button
                                                                variant="ghost"
                                                                size="sm"
                                                                className="text-red-400 hover:text-red-300"
                                                                title="Delete"
                                                            >
                                                                <Trash2 className="w-4 h-4" />
                                                            </Button>
                                                        </AlertDialogTrigger>
                                                        <AlertDialogContent>
                                                            <AlertDialogHeader>
                                                                <AlertDialogTitle>Delete Hardware</AlertDialogTitle>
                                                                <AlertDialogDescription>
                                                                    Delete &quot;{item.name}&quot;? This action cannot be undone.
                                                                </AlertDialogDescription>
                                                            </AlertDialogHeader>
                                                            <AlertDialogFooter>
                                                                <AlertDialogCancel>Cancel</AlertDialogCancel>
                                                                <AlertDialogAction
                                                                    className="bg-red-600 hover:bg-red-700"
                                                                    onClick={() => deleteHardware(item.id)}
                                                                >
                                                                    Delete
                                                                </AlertDialogAction>
                                                            </AlertDialogFooter>
                                                        </AlertDialogContent>
                                                    </AlertDialog>
                                                </div>
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>
                        )}
                    </CardContent>
                </Card>

                {/* ------------------------------------------------------------------ */}
                {/* Section 2 - Rooms                                                  */}
                {/* ------------------------------------------------------------------ */}

                <Card>
                    <CardHeader className="flex flex-row items-center justify-between">
                        <CardTitle className="flex items-center gap-2">
                            <DoorOpen className="w-5 h-5" />
                            Rooms
                            <Badge variant="outline" className="ml-1 text-slate-300">
                                {rooms.length}
                            </Badge>
                        </CardTitle>
                        {!showAddRoom && (
                            <Button variant="secondary" size="sm" onClick={() => setShowAddRoom(true)}>
                                <Plus className="w-4 h-4 mr-1" />
                                Add Room
                            </Button>
                        )}
                    </CardHeader>
                    <CardContent>
                        {/* Add room inline form */}
                        {showAddRoom && (
                            <form onSubmit={submitRoom} className="flex items-end gap-2 mb-4">
                                <div className="flex-1">
                                    <Label>Room Name</Label>
                                    <Input
                                        value={roomForm.data.name}
                                        onChange={(e) => roomForm.setData('name', e.target.value)}
                                        placeholder="e.g., Server Room, Office A"
                                        required
                                    />
                                </div>
                                <Button type="submit" disabled={roomForm.processing}>
                                    Add
                                </Button>
                                <Button
                                    type="button"
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

                        {rooms.length === 0 && !showAddRoom ? (
                            <div className="text-center py-8 text-slate-400">
                                <DoorOpen className="w-12 h-12 mx-auto mb-3 opacity-50" />
                                <p>No rooms configured yet.</p>
                                <Button
                                    variant="outline"
                                    className="mt-4"
                                    onClick={() => setShowAddRoom(true)}
                                >
                                    <Plus className="w-4 h-4 mr-1" />
                                    Add Your First Room
                                </Button>
                            </div>
                        ) : (
                            <div className="space-y-2">
                                {rooms.map((room) => {
                                    const count = hwCountInRoom(room.id);
                                    const isEditing = editingRoomId === room.id;
                                    return (
                                        <div
                                            key={room.id}
                                            className="flex items-center justify-between rounded-lg border p-3"
                                        >
                                            {isEditing ? (
                                                <div className="flex items-center gap-2 flex-1">
                                                    <Input
                                                        value={editingRoomName}
                                                        onChange={(e) => setEditingRoomName(e.target.value)}
                                                        className="max-w-xs"
                                                        autoFocus
                                                        onKeyDown={(e) => {
                                                            if (e.key === 'Enter') {
                                                                e.preventDefault();
                                                                submitEditRoom(room.id);
                                                            }
                                                            if (e.key === 'Escape') {
                                                                setEditingRoomId(null);
                                                            }
                                                        }}
                                                    />
                                                    <Button size="sm" onClick={() => submitEditRoom(room.id)}>
                                                        Save
                                                    </Button>
                                                    <Button
                                                        size="sm"
                                                        variant="ghost"
                                                        onClick={() => setEditingRoomId(null)}
                                                    >
                                                        Cancel
                                                    </Button>
                                                </div>
                                            ) : (
                                                <>
                                                    <div className="flex items-center gap-3">
                                                        <span className="font-medium">{room.name}</span>
                                                        <span className="text-xs text-slate-500">
                                                            #{room.sort_order}
                                                        </span>
                                                        <Badge variant="outline" className="text-xs text-slate-400">
                                                            {count} device{count !== 1 ? 's' : ''}
                                                        </Badge>
                                                    </div>
                                                    <div className="flex items-center gap-1">
                                                        <Button
                                                            variant="ghost"
                                                            size="sm"
                                                            onClick={() => startEditRoom(room)}
                                                        >
                                                            <Pencil className="w-4 h-4" />
                                                        </Button>
                                                        <AlertDialog>
                                                            <AlertDialogTrigger asChild>
                                                                <Button
                                                                    variant="ghost"
                                                                    size="sm"
                                                                    className="text-red-400 hover:text-red-300"
                                                                >
                                                                    <Trash2 className="w-4 h-4" />
                                                                </Button>
                                                            </AlertDialogTrigger>
                                                            <AlertDialogContent>
                                                                <AlertDialogHeader>
                                                                    <AlertDialogTitle>Delete Room</AlertDialogTitle>
                                                                    <AlertDialogDescription>
                                                                        Delete &quot;{room.name}&quot;?
                                                                        {count > 0 &&
                                                                            ` This room has ${count} hardware item${count !== 1 ? 's' : ''} assigned. They will become unassigned.`}
                                                                    </AlertDialogDescription>
                                                                </AlertDialogHeader>
                                                                <AlertDialogFooter>
                                                                    <AlertDialogCancel>Cancel</AlertDialogCancel>
                                                                    <AlertDialogAction
                                                                        className="bg-red-600 hover:bg-red-700"
                                                                        onClick={() => deleteRoom(room.id)}
                                                                    >
                                                                        Delete
                                                                    </AlertDialogAction>
                                                                </AlertDialogFooter>
                                                            </AlertDialogContent>
                                                        </AlertDialog>
                                                    </div>
                                                </>
                                            )}
                                        </div>
                                    );
                                })}
                            </div>
                        )}
                    </CardContent>
                </Card>

                {/* ------------------------------------------------------------------ */}
                {/* Section 3 - Active Integrations                                    */}
                {/* ------------------------------------------------------------------ */}

                {integrations.length > 0 && (
                    <div className="space-y-4">
                        <h2 className="text-lg font-semibold flex items-center gap-2">
                            <Server className="w-5 h-5" />
                            Active Integrations
                        </h2>
                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            {integrations.map((integration) => (
                                <Card key={integration.id}>
                                    <CardHeader className="pb-2">
                                        <CardTitle className="text-base flex items-center justify-between">
                                            <span className="capitalize">{integration.provider}</span>
                                            <Badge
                                                variant="outline"
                                                className={
                                                    integration.is_active
                                                        ? 'bg-emerald-500/20 text-emerald-400 border-emerald-500/30'
                                                        : 'bg-slate-500/20 text-slate-300 border-slate-500/30'
                                                }
                                            >
                                                {integration.status}
                                            </Badge>
                                        </CardTitle>
                                    </CardHeader>
                                    <CardContent className="space-y-3">
                                        {(integration.mapped_external_site_id ||
                                            integration.mapped_external_site_name) && (
                                            <div className="text-sm text-slate-400">
                                                <div>
                                                    Mapped to:{' '}
                                                    <span className="text-slate-300">
                                                        {integration.mapped_external_site_name ||
                                                            integration.mapped_external_site_id}
                                                    </span>
                                                </div>
                                                {integration.mapped_external_site_id &&
                                                    integration.mapped_external_site_name && (
                                                        <div className="text-xs text-slate-500 font-mono">
                                                            ID: {integration.mapped_external_site_id}
                                                        </div>
                                                    )}
                                            </div>
                                        )}
                                        <div className="flex items-center gap-2">
                                            <Button
                                                size="sm"
                                                onClick={() => syncDevices(integration.provider)}
                                                disabled={syncingProvider === integration.provider}
                                            >
                                                <RefreshCw
                                                    className={`w-4 h-4 mr-1 ${
                                                        syncingProvider === integration.provider ? 'animate-spin' : ''
                                                    }`}
                                                />
                                                {syncingProvider === integration.provider
                                                    ? 'Syncing...'
                                                    : 'Sync Devices'}
                                            </Button>
                                            <Button asChild variant="outline" size="sm">
                                                <a href="/settings/integrations">
                                                    <Settings className="w-4 h-4 mr-1" />
                                                    Configure
                                                </a>
                                            </Button>
                                        </div>
                                    </CardContent>
                                </Card>
                            ))}
                        </div>
                    </div>
                )}
            </PageShell>

            {/* ================================================================== */}
            {/* Dialogs                                                            */}
            {/* ================================================================== */}

            {/* Add / Edit Hardware Dialog */}
            <Dialog open={showAddDialog} onOpenChange={(open) => !open && closeHwDialog()}>
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>{editingItem ? 'Edit Hardware' : 'Add Hardware'}</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={submitHardware} className="space-y-4">
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="sm:col-span-2">
                                <Label>Name *</Label>
                                <Input
                                    value={hwForm.data.name}
                                    onChange={(e) => hwForm.setData('name', e.target.value)}
                                    placeholder="e.g., Main Router, Kitchen Camera"
                                    required
                                />
                                {hwForm.errors.name && (
                                    <p className="text-xs text-red-400 mt-1">{hwForm.errors.name}</p>
                                )}
                            </div>
                            <div>
                                <Label>Category *</Label>
                                <Select
                                    value={hwForm.data.category || undefined}
                                    onValueChange={(v) => hwForm.setData('category', v)}
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select category" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {categoryKeys.map((key) => (
                                            <SelectItem key={key} value={key}>
                                                {categories[key]}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {hwForm.errors.category && (
                                    <p className="text-xs text-red-400 mt-1">{hwForm.errors.category}</p>
                                )}
                            </div>
                            <div>
                                <Label>Provider</Label>
                                <Input
                                    value={hwForm.data.provider}
                                    onChange={(e) => hwForm.setData('provider', e.target.value)}
                                    placeholder="manual"
                                />
                            </div>
                            <div>
                                <Label>Room</Label>
                                <Select
                                    value={hwForm.data.room_id || undefined}
                                    onValueChange={(v) => hwForm.setData('room_id', v)}
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="No room assigned" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {rooms.map((room) => (
                                            <SelectItem key={room.id} value={room.id.toString()}>
                                                {room.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div>
                                <Label>Asset Tag</Label>
                                <Input
                                    value={hwForm.data.asset_tag}
                                    onChange={(e) => hwForm.setData('asset_tag', e.target.value)}
                                    placeholder="Optional"
                                />
                            </div>
                            <div>
                                <Label>Serial Number</Label>
                                <Input
                                    value={hwForm.data.serial}
                                    onChange={(e) => hwForm.setData('serial', e.target.value)}
                                    placeholder="Optional"
                                />
                            </div>
                            <div>
                                <Label>MAC Address</Label>
                                <Input
                                    value={hwForm.data.mac}
                                    onChange={(e) => hwForm.setData('mac', e.target.value)}
                                    placeholder="AA:BB:CC:DD:EE:FF"
                                />
                            </div>
                        </div>
                        <div>
                            <Label>Notes</Label>
                            <Textarea
                                value={hwForm.data.notes}
                                onChange={(e) => hwForm.setData('notes', e.target.value)}
                                placeholder="Any additional notes..."
                                rows={3}
                            />
                        </div>
                        <div className="flex gap-2 justify-end">
                            <Button type="button" variant="outline" onClick={closeHwDialog}>
                                Cancel
                            </Button>
                            <Button type="submit" disabled={hwForm.processing}>
                                {hwForm.processing
                                    ? 'Saving...'
                                    : editingItem
                                      ? 'Save Changes'
                                      : 'Add Hardware'}
                            </Button>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>

            {/* Assign Room Dialog */}
            <Dialog
                open={!!assignRoomItem}
                onOpenChange={(open) => {
                    if (!open) {
                        setAssignRoomItem(null);
                        assignRoomForm.reset();
                    }
                }}
            >
                <DialogContent className="sm:max-w-sm">
                    <DialogHeader>
                        <DialogTitle>Assign Room</DialogTitle>
                    </DialogHeader>
                    {assignRoomItem && (
                        <form onSubmit={submitAssignRoom} className="space-y-4">
                            <p className="text-sm text-slate-400">
                                Assign <span className="text-slate-200 font-medium">{assignRoomItem.name}</span> to a
                                room.
                            </p>
                            <div>
                                <Label>Room</Label>
                                <Select
                                    value={assignRoomForm.data.room_id || undefined}
                                    onValueChange={(v) => assignRoomForm.setData('room_id', v)}
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select a room" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {rooms.map((room) => (
                                            <SelectItem key={room.id} value={room.id.toString()}>
                                                {room.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="flex gap-2 justify-end">
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => {
                                        setAssignRoomItem(null);
                                        assignRoomForm.reset();
                                    }}
                                >
                                    Cancel
                                </Button>
                                <Button type="submit" disabled={assignRoomForm.processing}>
                                    {assignRoomForm.processing ? 'Saving...' : 'Assign'}
                                </Button>
                            </div>
                        </form>
                    )}
                </DialogContent>
            </Dialog>

            {/* Link Asset Dialog */}
            <Dialog
                open={!!linkAssetItem}
                onOpenChange={(open) => {
                    if (!open) {
                        setLinkAssetItem(null);
                        linkAssetForm.reset();
                    }
                }}
            >
                <DialogContent className="sm:max-w-sm">
                    <DialogHeader>
                        <DialogTitle>Link Asset</DialogTitle>
                    </DialogHeader>
                    {linkAssetItem && (
                        <form onSubmit={submitLinkAsset} className="space-y-4">
                            <p className="text-sm text-slate-400">
                                Link <span className="text-slate-200 font-medium">{linkAssetItem.name}</span> to an
                                asset record.
                            </p>
                            <div>
                                <Label>Asset</Label>
                                <Select
                                    value={linkAssetForm.data.linked_asset_id || undefined}
                                    onValueChange={(v) => linkAssetForm.setData('linked_asset_id', v)}
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select an asset" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {assets.map((asset) => (
                                            <SelectItem key={asset.id} value={asset.id.toString()}>
                                                {asset.name}
                                                {asset.asset_tag ? ` (${asset.asset_tag})` : ''}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="flex gap-2 justify-end">
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => {
                                        setLinkAssetItem(null);
                                        linkAssetForm.reset();
                                    }}
                                >
                                    Cancel
                                </Button>
                                <Button type="submit" disabled={linkAssetForm.processing}>
                                    {linkAssetForm.processing ? 'Saving...' : 'Link'}
                                </Button>
                            </div>
                        </form>
                    )}
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
