import PageShell from '@/components/page-shell';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { Head, Link, router } from '@inertiajs/react';
import {
    DndContext,
    type DragEndEvent,
    KeyboardSensor,
    PointerSensor,
    closestCenter,
    useSensor,
    useSensors,
} from '@dnd-kit/core';
import {
    SortableContext,
    arrayMove,
    sortableKeyboardCoordinates,
    useSortable,
    verticalListSortingStrategy,
} from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import {
    BedDouble,
    GripVertical,
    History,
    Home,
    Layers,
    Package,
    Pencil,
    Plus,
    Printer,
    Search,
    Shield,
    Trash2,
    UserCog,
    UserPlus,
    UserX,
    type LucideIcon,
} from 'lucide-react';
import { useEffect, useMemo, useState, type ReactNode } from 'react';
import {
    AddRoomDialog,
    AssignClientToRoomDialog,
    DeleteRoomDialog,
    EditRoomDialog,
    UnassignRoomDialog,
    type ClientForPicker,
    type RoomRecord,
} from './_dialogs';
import {
    AssignAssetDialog,
    type AssetForPicker,
} from './_asset-dialogs';

// ── Types (match SiteRoomController::index payload) ──────────────────────

type Site = {
    id: number;
    name: string;
    type?: string | null;
    region?: string | null;
    is_active: boolean;
    is_high_risk?: boolean;
};

type RoomAssignedClient = {
    id: number;
    first_name: string;
    last_name: string;
    preferred_name?: string | null;
    status?: string | null;
    risk_level?: string | null;
    safeguarding_flag?: boolean;
    profile_photo_url?: string | null;
    key_worker?: { id: number; name: string } | null;
};

type RoomAsset = {
    id: number;
    name: string;
    asset_tag?: string | null;
    category?: string | null;
    status?: string | null;
    risk_level?: string | null;
    location?: string | null;
};

type RoomPersonalAsset = {
    id: number;
    client_id: number;
    name: string;
    category?: string | null;
    status?: string | null;
    condition?: string | null;
};

type RoomHistoryEntry = {
    id: number;
    client: { id: number; first_name: string; last_name: string } | null;
    assigned_from?: string | null;
    assigned_until?: string | null;
    assigned_by?: string | null;
    notes?: string | null;
};

type Room = {
    id: number;
    name: string;
    notes?: string | null;
    is_active: boolean;
    is_assignable: boolean;
    sort_order?: number;
    assigned_from?: string | null;
    assigned_until?: string | null;
    assigned_client: RoomAssignedClient | null;
    assets: RoomAsset[];
    personal_assets: RoomPersonalAsset[];
    history: RoomHistoryEntry[];
};

type Summary = {
    total: number;
    active: number;
    inactive: number;
    bedrooms: number;
    communal: number;
    occupied: number;
    available: number;
    occupancy_percent: number;
    assets_linked: number;
};

type Alerts = {
    empty_bedrooms: number;
    safeguarding: number;
    missing_key_worker: number;
};

type Props = {
    site: Site;
    rooms: Room[];
    clients: ClientForPicker[];
    availableAssets: AssetForPicker[];
    summary: Summary;
    alerts: Alerts;
};

type FilterKey = 'all' | 'bedrooms' | 'communal' | 'occupied' | 'available';

// ── Helpers ───────────────────────────────────────────────────────────────

function occupantName(c: {
    first_name: string;
    last_name: string;
    preferred_name?: string | null;
}) {
    const full = `${c.first_name ?? ''} ${c.last_name ?? ''}`.trim();
    return c.preferred_name && c.preferred_name !== c.first_name
        ? `${c.preferred_name} (${full})`
        : full;
}

function initials(c: { first_name?: string | null; last_name?: string | null }) {
    return (
        ((c.first_name?.[0] ?? '') + (c.last_name?.[0] ?? '')).toUpperCase() ||
        '?'
    );
}

// ── Page ──────────────────────────────────────────────────────────────────

export default function FullBedroomManagement({
    site,
    rooms,
    clients,
    availableAssets,
    summary,
    alerts,
}: Props) {
    const [search, setSearch] = useState('');
    const [filter, setFilter] = useState<FilterKey>('all');
    const [showInactive, setShowInactive] = useState(false);
    const [orderedRooms, setOrderedRooms] = useState<Room[]>(rooms);

    useEffect(() => {
        setOrderedRooms(rooms);
    }, [rooms]);

    type RoomDialog =
        | 'add'
        | 'edit'
        | 'delete'
        | 'assign'
        | 'unassign'
        | 'asset'
        | null;
    const [dialog, setDialog] = useState<{
        mode: RoomDialog;
        target: RoomRecord | null;
    }>({ mode: null, target: null });
    const closeDialog = () => setDialog({ mode: null, target: null });

    const [drawerRoomId, setDrawerRoomId] = useState<number | null>(null);
    const drawerRoom = drawerRoomId
        ? orderedRooms.find((r) => r.id === drawerRoomId) ?? null
        : null;

    const filtered = useMemo(() => {
        const q = search.trim().toLowerCase();
        return orderedRooms.filter((r) => {
            if (!showInactive && !r.is_active) return false;
            if (q) {
                const hay = `${r.name} ${r.notes ?? ''} ${
                    r.assigned_client
                        ? occupantName(r.assigned_client)
                        : ''
                }`.toLowerCase();
                if (!hay.includes(q)) return false;
            }
            switch (filter) {
                case 'bedrooms':
                    return r.is_assignable !== false;
                case 'communal':
                    return r.is_assignable === false;
                case 'occupied':
                    return r.is_assignable && !!r.assigned_client;
                case 'available':
                    return r.is_assignable && !r.assigned_client;
                default:
                    return true;
            }
        });
    }, [orderedRooms, search, filter, showInactive]);

    const bedroomList = filtered.filter((r) => r.is_assignable !== false);
    const communalList = filtered.filter((r) => r.is_assignable === false);

    // ── Drag-and-drop sort ────────────────────────────────────────────
    const sensors = useSensors(
        useSensor(PointerSensor, { activationConstraint: { distance: 8 } }),
        useSensor(KeyboardSensor, {
            coordinateGetter: sortableKeyboardCoordinates,
        }),
    );

    const persistOrder = (ids: number[]) => {
        router.patch(
            `/sites/${site.id}/rooms/order`,
            { ordered_ids: ids },
            { preserveScroll: true, preserveState: true },
        );
    };

    const handleDragEnd = (e: DragEndEvent, scope: 'bedrooms' | 'communal') => {
        const { active, over } = e;
        if (!over || active.id === over.id) return;

        const scopedIds =
            scope === 'bedrooms'
                ? orderedRooms
                      .filter((r) => r.is_assignable !== false)
                      .map((r) => r.id)
                : orderedRooms
                      .filter((r) => r.is_assignable === false)
                      .map((r) => r.id);

        const oldIndex = scopedIds.indexOf(Number(active.id));
        const newIndex = scopedIds.indexOf(Number(over.id));
        if (oldIndex < 0 || newIndex < 0) return;

        const reorderedIds = arrayMove(scopedIds, oldIndex, newIndex);

        const otherScopeIds = orderedRooms
            .filter((r) =>
                scope === 'bedrooms'
                    ? r.is_assignable === false
                    : r.is_assignable !== false,
            )
            .map((r) => r.id);

        const finalIds =
            scope === 'bedrooms'
                ? [...reorderedIds, ...otherScopeIds]
                : [...otherScopeIds, ...reorderedIds];

        setOrderedRooms((prev) =>
            finalIds
                .map((id) => prev.find((r) => r.id === id))
                .filter((r): r is Room => !!r),
        );
        persistOrder(finalIds);
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Sites', href: '/sites' },
                { title: site.name, href: `/sites/${site.id}` },
                { title: 'Bedrooms', href: `/sites/${site.id}/rooms` },
            ]}
        >
            <Head title={`Bedrooms · ${site.name}`} />

            <PageShell>
                <BedroomsHero
                    site={site}
                    summary={summary}
                    alerts={alerts}
                    onAdd={() => setDialog({ mode: 'add', target: null })}
                />

                {/* Filter bar */}
                <div className="flex flex-col gap-3 rounded-2xl border bg-card/40 p-3 md:flex-row md:items-center md:justify-between">
                    <div className="flex flex-1 items-center gap-2">
                        <div className="relative w-full md:max-w-sm">
                            <Search className="pointer-events-none absolute left-2 top-2.5 h-4 w-4 text-muted-foreground" />
                            <Input
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder="Search room or occupant…"
                                className="pl-8"
                            />
                        </div>
                    </div>
                    <div className="flex flex-wrap items-center gap-1.5">
                        {(
                            [
                                ['all', 'All'],
                                ['bedrooms', 'Bedrooms'],
                                ['communal', 'Communal'],
                                ['occupied', 'Occupied'],
                                ['available', 'Available'],
                            ] as const
                        ).map(([key, label]) => (
                            <Button
                                key={key}
                                type="button"
                                variant={filter === key ? 'default' : 'outline'}
                                size="sm"
                                onClick={() => setFilter(key)}
                            >
                                {label}
                            </Button>
                        ))}
                        <Button
                            type="button"
                            variant={showInactive ? 'default' : 'outline'}
                            size="sm"
                            onClick={() => setShowInactive((v) => !v)}
                        >
                            {showInactive ? 'Hide inactive' : 'Show inactive'}
                        </Button>
                    </div>
                </div>

                {/* Two-section split */}
                <div className="grid gap-4 lg:grid-cols-2">
                    <DndContext
                        sensors={sensors}
                        collisionDetection={closestCenter}
                        onDragEnd={(e) => handleDragEnd(e, 'bedrooms')}
                    >
                        <RoomSection
                            icon={BedDouble}
                            title="Bedrooms"
                            count={bedroomList.length}
                            accent="primary"
                            emptyHint={
                                filter === 'communal'
                                    ? 'Bedrooms hidden by the current filter.'
                                    : 'Add a room with the "Assignable to client" box ticked.'
                            }
                        >
                            <SortableContext
                                items={bedroomList.map((r) => r.id)}
                                strategy={verticalListSortingStrategy}
                            >
                                {bedroomList.map((r) => (
                                    <SortableRoomCard
                                        key={r.id}
                                        room={r}
                                        onOpen={() => setDrawerRoomId(r.id)}
                                        onEdit={() =>
                                            setDialog({
                                                mode: 'edit',
                                                target: r as RoomRecord,
                                            })
                                        }
                                        onDelete={() =>
                                            setDialog({
                                                mode: 'delete',
                                                target: r as RoomRecord,
                                            })
                                        }
                                        onAssign={() =>
                                            setDialog({
                                                mode: 'assign',
                                                target: r as RoomRecord,
                                            })
                                        }
                                        onAttachAsset={() =>
                                            setDialog({
                                                mode: 'asset',
                                                target: r as RoomRecord,
                                            })
                                        }
                                    />
                                ))}
                            </SortableContext>
                        </RoomSection>
                    </DndContext>

                    <DndContext
                        sensors={sensors}
                        collisionDetection={closestCenter}
                        onDragEnd={(e) => handleDragEnd(e, 'communal')}
                    >
                        <RoomSection
                            icon={Home}
                            title="Communal spaces"
                            count={communalList.length}
                            accent="muted"
                            emptyHint={
                                filter === 'bedrooms'
                                    ? 'Communal spaces hidden by the current filter.'
                                    : 'Untick "Assignable to client" when adding a kitchen, lounge or bathroom.'
                            }
                        >
                            <SortableContext
                                items={communalList.map((r) => r.id)}
                                strategy={verticalListSortingStrategy}
                            >
                                {communalList.map((r) => (
                                    <SortableRoomCard
                                        key={r.id}
                                        room={r}
                                        onOpen={() => setDrawerRoomId(r.id)}
                                        onEdit={() =>
                                            setDialog({
                                                mode: 'edit',
                                                target: r as RoomRecord,
                                            })
                                        }
                                        onDelete={() =>
                                            setDialog({
                                                mode: 'delete',
                                                target: r as RoomRecord,
                                            })
                                        }
                                        onAssign={() =>
                                            setDialog({
                                                mode: 'assign',
                                                target: r as RoomRecord,
                                            })
                                        }
                                        onAttachAsset={() =>
                                            setDialog({
                                                mode: 'asset',
                                                target: r as RoomRecord,
                                            })
                                        }
                                    />
                                ))}
                            </SortableContext>
                        </RoomSection>
                    </DndContext>
                </div>

                <RoomDrawer
                    site={site}
                    room={drawerRoom}
                    onClose={() => setDrawerRoomId(null)}
                    onAttachAsset={() => {
                        if (!drawerRoom) return;
                        setDialog({
                            mode: 'asset',
                            target: drawerRoom as RoomRecord,
                        });
                    }}
                    onAssignClient={() => {
                        if (!drawerRoom) return;
                        setDialog({
                            mode: 'assign',
                            target: drawerRoom as RoomRecord,
                        });
                    }}
                    onUnassignClient={() => {
                        if (!drawerRoom) return;
                        setDialog({
                            mode: 'unassign',
                            target: drawerRoom as RoomRecord,
                        });
                    }}
                    onEdit={() => {
                        if (!drawerRoom) return;
                        setDialog({
                            mode: 'edit',
                            target: drawerRoom as RoomRecord,
                        });
                    }}
                />
            </PageShell>

            <AddRoomDialog
                siteId={site.id}
                isOpen={dialog.mode === 'add'}
                onClose={closeDialog}
            />
            <EditRoomDialog
                siteId={site.id}
                room={dialog.target}
                isOpen={dialog.mode === 'edit'}
                onClose={closeDialog}
            />
            <DeleteRoomDialog
                siteId={site.id}
                room={dialog.target}
                isOpen={dialog.mode === 'delete'}
                onClose={closeDialog}
            />
            <AssignClientToRoomDialog
                siteId={site.id}
                room={dialog.target}
                clients={clients}
                isOpen={dialog.mode === 'assign'}
                onClose={closeDialog}
            />
            <UnassignRoomDialog
                siteId={site.id}
                room={dialog.target}
                isOpen={dialog.mode === 'unassign'}
                onClose={closeDialog}
            />
            <AssignAssetDialog
                siteId={site.id}
                room={dialog.target}
                assets={availableAssets}
                isOpen={dialog.mode === 'asset'}
                onClose={closeDialog}
            />
        </AppLayout>
    );
}

// ── Hero ───────────────────────────────────────────────────────────────────

function BedroomsHero({
    site,
    summary,
    alerts,
    onAdd,
}: {
    site: Site;
    summary: Summary;
    alerts: Alerts;
    onAdd: () => void;
}) {
    return (
        <div className="relative overflow-hidden rounded-2xl bg-gradient-to-br from-primary/90 via-primary to-primary/80 p-6 text-white md:p-8">
            <div className="pointer-events-none absolute -top-16 -right-16 h-64 w-64 rounded-full bg-white/5" />
            <div className="pointer-events-none absolute -bottom-20 -left-20 h-48 w-48 rounded-full bg-white/5" />
            <div className="pointer-events-none absolute top-1/4 right-1/3 h-24 w-24 rounded-full bg-white/5" />

            <div className="relative flex flex-col gap-6 md:flex-row md:items-start">
                <div className="flex h-24 w-24 shrink-0 items-center justify-center rounded-full border-4 border-white/20 bg-white/10 shadow-xl md:h-28 md:w-28">
                    <BedDouble className="h-12 w-12 text-white md:h-14 md:w-14" />
                </div>
                <div className="flex-1">
                    <p className="text-xs uppercase tracking-wide text-white/60">
                        <Link
                            href={`/sites/${site.id}`}
                            className="underline-offset-2 hover:underline"
                        >
                            {site.name}
                        </Link>
                        {site.type && <> · {site.type.replace('_', ' ')}</>}
                        {site.region && <> · {site.region}</>}
                    </p>
                    <h1 className="mt-1 text-2xl font-bold md:text-3xl">
                        Bedroom management
                    </h1>
                    <p className="mt-1 max-w-2xl text-sm text-white/70">
                        Track who lives where, attach the assets that belong in
                        each room, surface safeguarding flags and print door
                        cards for night shift.
                    </p>

                    {/* Alert chips */}
                    <div className="mt-3 flex flex-wrap gap-2">
                        {alerts.empty_bedrooms > 0 && (
                            <Badge className="border-white/20 bg-white/10 text-white">
                                <BedDouble className="mr-1 h-3 w-3" />
                                {alerts.empty_bedrooms} empty bedroom
                                {alerts.empty_bedrooms === 1 ? '' : 's'}
                            </Badge>
                        )}
                        {alerts.safeguarding > 0 && (
                            <Badge className="border-status-critical/40 bg-status-critical-bg/30 text-white">
                                <Shield className="mr-1 h-3 w-3" />
                                {alerts.safeguarding} safeguarding flag
                                {alerts.safeguarding === 1 ? '' : 's'}
                            </Badge>
                        )}
                        {alerts.missing_key_worker > 0 && (
                            <Badge className="border-status-warning/40 bg-status-warning-bg/30 text-white">
                                <UserCog className="mr-1 h-3 w-3" />
                                {alerts.missing_key_worker} occupant
                                {alerts.missing_key_worker === 1 ? '' : 's'} without a key worker
                            </Badge>
                        )}
                    </div>
                </div>

                <div className="flex flex-col gap-3 md:items-end">
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        className="border-white/20 bg-white/10 text-white hover:bg-white/20"
                        onClick={onAdd}
                    >
                        <Plus className="mr-1 h-4 w-4" />
                        Add room
                    </Button>

                    <div className="hidden gap-2 text-center md:flex md:flex-wrap md:justify-end">
                        <HeroStat label="Bedrooms" value={summary.bedrooms} />
                        <HeroStat label="Occupied" value={summary.occupied} />
                        <HeroStat
                            label="Available"
                            value={summary.available}
                        />
                        <HeroStat
                            label="Occupancy"
                            value={`${summary.occupancy_percent}%`}
                        />
                        <HeroStat label="Communal" value={summary.communal} />
                        <HeroStat
                            label="Assets"
                            value={summary.assets_linked}
                        />
                    </div>
                </div>
            </div>
        </div>
    );
}

function HeroStat({
    label,
    value,
}: {
    label: string;
    value: number | string;
}) {
    return (
        <div className="min-w-[68px] rounded-xl bg-white/10 px-3 py-2">
            <p className="text-xl font-bold leading-none">{value}</p>
            <p className="mt-1 text-[10px] uppercase tracking-wide text-white/60">
                {label}
            </p>
        </div>
    );
}

// ── Sections ───────────────────────────────────────────────────────────────

function RoomSection({
    icon: Icon,
    title,
    count,
    accent,
    emptyHint,
    children,
}: {
    icon: LucideIcon;
    title: string;
    count: number;
    accent: 'primary' | 'muted';
    emptyHint: string;
    children: ReactNode;
}) {
    const accentCls =
        accent === 'primary'
            ? 'border-primary/30 bg-primary/5'
            : 'border-border bg-muted/20';
    const iconCls =
        accent === 'primary' ? 'text-primary' : 'text-muted-foreground';
    return (
        <section
            className={cn(
                'flex flex-col gap-3 rounded-2xl border p-3',
                accentCls,
            )}
        >
            <div className="flex items-center justify-between gap-2">
                <h4 className="flex items-center gap-2 text-sm font-semibold">
                    <Icon className={cn('h-4 w-4', iconCls)} />
                    {title}
                    <span className="text-xs font-normal text-muted-foreground">
                        ({count})
                    </span>
                </h4>
            </div>
            {count === 0 ? (
                <div className="flex flex-col items-center justify-center rounded-xl border border-dashed bg-background/40 py-8 text-center">
                    <Icon className={cn('h-5 w-5', iconCls, 'opacity-60')} />
                    <p className="mt-2 max-w-xs text-[11px] text-muted-foreground">
                        {emptyHint}
                    </p>
                </div>
            ) : (
                <div className="grid gap-3">{children}</div>
            )}
        </section>
    );
}

// ── Sortable room card ─────────────────────────────────────────────────────

function SortableRoomCard({
    room,
    onOpen,
    onEdit,
    onDelete,
    onAssign,
    onAttachAsset,
}: {
    room: Room;
    onOpen: () => void;
    onEdit: () => void;
    onDelete: () => void;
    onAssign: () => void;
    onAttachAsset: () => void;
}) {
    const {
        attributes,
        listeners,
        setNodeRef,
        transform,
        transition,
        isDragging,
    } = useSortable({ id: room.id });

    const style = {
        transform: CSS.Transform.toString(transform),
        transition,
        opacity: isDragging ? 0.7 : 1,
    };

    const occupant = room.assigned_client;
    const isAssignable = room.is_assignable !== false;
    const isInactive = !room.is_active;

    return (
        <div
            ref={setNodeRef}
            style={style}
            className={cn(
                'group relative flex flex-col gap-3 rounded-2xl border bg-card p-3 transition-all',
                isInactive && 'opacity-60',
            )}
        >
            <div className="flex items-start gap-3">
                <button
                    type="button"
                    aria-label="Drag to reorder"
                    {...attributes}
                    {...listeners}
                    className="mt-0.5 cursor-grab rounded-md p-1 text-muted-foreground hover:bg-muted"
                >
                    <GripVertical className="h-4 w-4" />
                </button>
                <button
                    type="button"
                    onClick={onOpen}
                    className="min-w-0 flex-1 text-left"
                >
                    <div className="flex flex-wrap items-center gap-2">
                        <p className="truncate text-sm font-semibold">
                            {room.name}
                        </p>
                        {isInactive && (
                            <Badge
                                variant="outline"
                                className="border-muted-foreground/30 text-[10px]"
                            >
                                Inactive
                            </Badge>
                        )}
                        {!isAssignable ? (
                            <Badge
                                variant="outline"
                                className="border-muted-foreground/30 text-[10px] text-muted-foreground"
                            >
                                Communal
                            </Badge>
                        ) : occupant ? (
                            <Badge
                                variant="outline"
                                className="border-primary/30 text-[10px] text-primary"
                            >
                                Assigned
                            </Badge>
                        ) : (
                            <Badge
                                variant="outline"
                                className="border-status-success/30 text-[10px] text-status-success"
                            >
                                Available
                            </Badge>
                        )}
                        {occupant?.safeguarding_flag && (
                            <Shield
                                className="h-3.5 w-3.5 text-status-critical"
                                aria-label="Safeguarding"
                            />
                        )}
                    </div>
                    <div className="mt-1 flex flex-wrap items-center gap-3 text-[11px] text-muted-foreground">
                        {room.assets.length > 0 && (
                            <span className="inline-flex items-center gap-1">
                                <Package className="h-3 w-3" />
                                {room.assets.length} asset
                                {room.assets.length === 1 ? '' : 's'}
                            </span>
                        )}
                        {room.personal_assets.length > 0 && (
                            <span className="inline-flex items-center gap-1">
                                <Layers className="h-3 w-3" />
                                {room.personal_assets.length} personal
                            </span>
                        )}
                        {room.history.length > 0 && (
                            <span className="inline-flex items-center gap-1">
                                <History className="h-3 w-3" />
                                {room.history.length} history
                            </span>
                        )}
                    </div>
                </button>
            </div>

            {isAssignable && occupant && (
                <button
                    type="button"
                    onClick={onOpen}
                    className="flex items-center gap-2 rounded-lg border bg-background/40 px-2 py-1.5 text-left"
                >
                    <Avatar className="size-8">
                        {occupant.profile_photo_url && (
                            <AvatarImage
                                src={occupant.profile_photo_url}
                                alt={occupantName(occupant)}
                            />
                        )}
                        <AvatarFallback className="text-[10px]">
                            {initials(occupant)}
                        </AvatarFallback>
                    </Avatar>
                    <div className="min-w-0 flex-1">
                        <p className="truncate text-xs font-medium">
                            {occupantName(occupant)}
                        </p>
                        <p className="truncate text-[10px] text-muted-foreground">
                            {occupant.key_worker?.name
                                ? `Key worker: ${occupant.key_worker.name}`
                                : occupant.status ?? 'Occupant'}
                        </p>
                    </div>
                </button>
            )}

            {isAssignable && !occupant && (
                <div className="rounded-lg border border-dashed bg-background/20 px-2 py-2 text-center text-[11px] text-muted-foreground">
                    No occupant
                </div>
            )}

            {!isAssignable && (
                <div className="rounded-lg border border-dashed bg-background/20 px-2 py-2 text-center text-[11px] text-muted-foreground">
                    Shared space — no client occupant
                </div>
            )}

            <div className="flex flex-wrap items-center justify-end gap-1.5 border-t pt-2">
                {isAssignable && (
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        onClick={onAttachAsset}
                    >
                        <Package className="mr-1 h-3.5 w-3.5" />
                        Asset
                    </Button>
                )}
                {isAssignable && (
                    <Button type="button" size="sm" onClick={onAssign}>
                        <UserPlus className="mr-1 h-3.5 w-3.5" />
                        {occupant ? 'Change' : 'Assign'}
                    </Button>
                )}
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    onClick={onEdit}
                    aria-label="Edit room"
                >
                    <Pencil className="h-3.5 w-3.5" />
                </Button>
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    className="text-status-critical hover:text-status-critical"
                    onClick={onDelete}
                    aria-label="Deactivate room"
                >
                    <Trash2 className="h-3.5 w-3.5" />
                </Button>
            </div>
        </div>
    );
}

// ── Room drawer (Sheet) ────────────────────────────────────────────────────

function RoomDrawer({
    site,
    room,
    onClose,
    onAttachAsset,
    onAssignClient,
    onUnassignClient,
    onEdit,
}: {
    site: Site;
    room: Room | null;
    onClose: () => void;
    onAttachAsset: () => void;
    onAssignClient: () => void;
    onUnassignClient: () => void;
    onEdit: () => void;
}) {
    const detachAsset = (assetId: number) => {
        if (!room) return;
        if (!confirm('Remove this asset from the room?')) return;
        router.delete(
            `/sites/${site.id}/rooms/${room.id}/assets/${assetId}`,
            { preserveScroll: true, preserveState: true },
        );
    };

    return (
        <Sheet open={!!room} onOpenChange={(open) => !open && onClose()}>
            <SheetContent className="w-full overflow-y-auto sm:max-w-lg">
                {room && (
                    <>
                        <SheetHeader>
                            <div className="flex items-start gap-3">
                                <span className="shrink-0 rounded-xl border bg-background/60 p-2">
                                    <BedDouble className="h-5 w-5 text-primary" />
                                </span>
                                <div className="min-w-0 flex-1">
                                    <SheetTitle className="truncate text-left">
                                        {room.name}
                                    </SheetTitle>
                                    <SheetDescription className="flex flex-wrap items-center gap-2">
                                        {!room.is_assignable ? (
                                            <Badge
                                                variant="outline"
                                                className="border-muted-foreground/30 text-[10px] text-muted-foreground"
                                            >
                                                Communal
                                            </Badge>
                                        ) : room.assigned_client ? (
                                            <Badge
                                                variant="outline"
                                                className="border-primary/30 text-[10px] text-primary"
                                            >
                                                Assigned
                                            </Badge>
                                        ) : (
                                            <Badge
                                                variant="outline"
                                                className="border-status-success/30 text-[10px] text-status-success"
                                            >
                                                Available
                                            </Badge>
                                        )}
                                        {!room.is_active && (
                                            <Badge variant="outline">
                                                Inactive
                                            </Badge>
                                        )}
                                    </SheetDescription>
                                </div>
                            </div>
                        </SheetHeader>

                        <div className="mt-4 space-y-4 px-1">
                            <div className="flex flex-wrap items-center gap-2">
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    asChild
                                >
                                    <a
                                        href={`/sites/${site.id}/rooms/${room.id}/door-card`}
                                        target="_blank"
                                        rel="noopener"
                                    >
                                        <Printer className="mr-1 h-3.5 w-3.5" />
                                        Print door card
                                    </a>
                                </Button>
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    onClick={onEdit}
                                >
                                    <Pencil className="mr-1 h-3.5 w-3.5" />
                                    Edit
                                </Button>
                                {room.is_assignable && (
                                    <Button
                                        type="button"
                                        size="sm"
                                        onClick={onAssignClient}
                                    >
                                        <UserPlus className="mr-1 h-3.5 w-3.5" />
                                        {room.assigned_client
                                            ? 'Change occupant'
                                            : 'Assign client'}
                                    </Button>
                                )}
                                {room.is_assignable &&
                                    room.assigned_client && (
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="outline"
                                            onClick={onUnassignClient}
                                        >
                                            <UserX className="mr-1 h-3.5 w-3.5" />
                                            Unassign
                                        </Button>
                                    )}
                            </div>

                            {room.assigned_client ? (
                                <DrawerSection title="Occupant">
                                    <div className="flex items-center gap-3 rounded-xl border bg-card/40 p-3">
                                        <Avatar className="size-10">
                                            {room.assigned_client
                                                .profile_photo_url && (
                                                <AvatarImage
                                                    src={
                                                        room.assigned_client
                                                            .profile_photo_url
                                                    }
                                                    alt={occupantName(
                                                        room.assigned_client,
                                                    )}
                                                />
                                            )}
                                            <AvatarFallback>
                                                {initials(
                                                    room.assigned_client,
                                                )}
                                            </AvatarFallback>
                                        </Avatar>
                                        <div className="min-w-0 flex-1">
                                            <p className="truncate text-sm font-medium">
                                                {occupantName(
                                                    room.assigned_client,
                                                )}
                                            </p>
                                            <p className="truncate text-xs text-muted-foreground">
                                                {room.assigned_client.status &&
                                                    `${room.assigned_client.status} · `}
                                                {room.assigned_client.key_worker
                                                    ?.name
                                                    ? `Key worker: ${room.assigned_client.key_worker.name}`
                                                    : 'No key worker'}
                                            </p>
                                            {(room.assigned_from ||
                                                room.assigned_until) && (
                                                <p className="mt-1 text-[11px] text-muted-foreground">
                                                    {room.assigned_from &&
                                                        `Since ${room.assigned_from}`}
                                                    {room.assigned_until &&
                                                        ` · until ${room.assigned_until}`}
                                                </p>
                                            )}
                                        </div>
                                        <Button
                                            asChild
                                            variant="ghost"
                                            size="sm"
                                        >
                                            <a
                                                href={`/clients/${room.assigned_client.id}`}
                                            >
                                                Profile
                                            </a>
                                        </Button>
                                    </div>
                                </DrawerSection>
                            ) : (
                                room.is_assignable && (
                                    <DrawerSection title="Occupant">
                                        <p className="rounded-xl border border-dashed p-3 text-center text-xs text-muted-foreground">
                                            No client currently in this room.
                                        </p>
                                    </DrawerSection>
                                )
                            )}

                            {room.notes && (
                                <DrawerSection title="Notes">
                                    <p className="whitespace-pre-wrap rounded-lg border bg-muted/30 p-3 text-sm">
                                        {room.notes}
                                    </p>
                                </DrawerSection>
                            )}

                            <DrawerSection
                                title={`Assets (${room.assets.length})`}
                                action={
                                    room.is_assignable ? (
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="outline"
                                            onClick={onAttachAsset}
                                        >
                                            <Plus className="mr-1 h-3.5 w-3.5" />
                                            Attach asset
                                        </Button>
                                    ) : undefined
                                }
                            >
                                {room.assets.length === 0 ? (
                                    <p className="rounded-xl border border-dashed p-3 text-center text-xs text-muted-foreground">
                                        No assets attached to this room yet.
                                    </p>
                                ) : (
                                    <ul className="divide-y rounded-xl border bg-card/40">
                                        {room.assets.map((a) => (
                                            <li
                                                key={a.id}
                                                className="flex items-center gap-2 px-3 py-2 text-sm"
                                            >
                                                <Package className="h-4 w-4 shrink-0 text-muted-foreground" />
                                                <div className="min-w-0 flex-1">
                                                    <p className="truncate font-medium">
                                                        {a.name}
                                                    </p>
                                                    <p className="truncate text-[11px] text-muted-foreground">
                                                        {[
                                                            a.asset_tag,
                                                            a.category,
                                                            a.status,
                                                        ]
                                                            .filter(Boolean)
                                                            .join(' · ') ||
                                                            '—'}
                                                    </p>
                                                </div>
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="sm"
                                                    className="text-muted-foreground hover:text-status-critical"
                                                    onClick={() =>
                                                        detachAsset(a.id)
                                                    }
                                                    aria-label="Detach asset"
                                                >
                                                    <Trash2 className="h-3.5 w-3.5" />
                                                </Button>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </DrawerSection>

                            {room.personal_assets.length > 0 && (
                                <DrawerSection
                                    title={`Resident's personal items (${room.personal_assets.length})`}
                                >
                                    <ul className="divide-y rounded-xl border bg-card/40">
                                        {room.personal_assets.map((p) => (
                                            <li
                                                key={p.id}
                                                className="flex items-center gap-2 px-3 py-2 text-sm"
                                            >
                                                <Layers className="h-4 w-4 shrink-0 text-muted-foreground" />
                                                <div className="min-w-0 flex-1">
                                                    <p className="truncate font-medium">
                                                        {p.name}
                                                    </p>
                                                    <p className="truncate text-[11px] text-muted-foreground">
                                                        {[
                                                            p.category,
                                                            p.condition,
                                                            p.status,
                                                        ]
                                                            .filter(Boolean)
                                                            .join(' · ') ||
                                                            'Personal item'}
                                                    </p>
                                                </div>
                                                <Badge
                                                    variant="outline"
                                                    className="text-[10px]"
                                                >
                                                    Personal
                                                </Badge>
                                            </li>
                                        ))}
                                    </ul>
                                </DrawerSection>
                            )}

                            {room.history.length > 0 && (
                                <DrawerSection
                                    title={`Assignment history (${room.history.length})`}
                                >
                                    <ul className="space-y-1.5 rounded-xl border bg-card/40 p-3">
                                        {room.history.slice(0, 10).map((h) => (
                                            <li
                                                key={h.id}
                                                className="flex flex-wrap items-baseline justify-between gap-2 text-xs"
                                            >
                                                <span className="font-medium">
                                                    {h.client
                                                        ? `${h.client.first_name} ${h.client.last_name}`
                                                        : 'Unknown client'}
                                                </span>
                                                <span className="text-muted-foreground">
                                                    {h.assigned_from ?? '—'}
                                                    {' → '}
                                                    {h.assigned_until ??
                                                        'present'}
                                                    {h.assigned_by &&
                                                        ` · by ${h.assigned_by}`}
                                                </span>
                                            </li>
                                        ))}
                                    </ul>
                                </DrawerSection>
                            )}

                            {!room.is_active && (
                                <div className="rounded-xl border border-status-warning/40 bg-status-warning-bg/40 p-3 text-xs">
                                    <p className="font-medium text-status-warning">
                                        This room is deactivated.
                                    </p>
                                    <p className="mt-1 text-muted-foreground">
                                        Restore it to show on the main grid
                                        again.
                                    </p>
                                    <Button
                                        type="button"
                                        size="sm"
                                        className="mt-2"
                                        onClick={() =>
                                            router.post(
                                                `/sites/${site.id}/rooms/${room.id}/restore`,
                                                {},
                                                {
                                                    preserveScroll: true,
                                                    preserveState: true,
                                                },
                                            )
                                        }
                                    >
                                        Restore room
                                    </Button>
                                </div>
                            )}
                        </div>
                    </>
                )}
            </SheetContent>
        </Sheet>
    );
}

function DrawerSection({
    title,
    action,
    children,
}: {
    title: string;
    action?: ReactNode;
    children: ReactNode;
}) {
    return (
        <section className="space-y-2">
            <div className="flex items-center justify-between gap-2">
                <h3 className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                    {title}
                </h3>
                {action}
            </div>
            {children}
        </section>
    );
}
