import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';
import { router, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    BedDouble,
    CalendarDays,
    History,
    Link2Off,
    Loader2,
    Pencil,
    Trash2,
    User,
    UserPlus,
    UserX,
} from 'lucide-react';
import { useMemo, useState } from 'react';

// ── Shared types ──────────────────────────────────────────────────────────

export type RoomOccupant = {
    id: number;
    first_name: string;
    last_name: string;
    preferred_name?: string | null;
    status?: string | null;
    profile_photo_url?: string | null;
    name?: string | null;
};

export type RoomHistoryEntry = {
    id: number;
    client: {
        id: number;
        first_name: string;
        last_name: string;
    } | null;
    assigned_from?: string | null;
    assigned_until?: string | null;
    assigned_by?: string | null;
    notes?: string | null;
};

export type RoomRecord = {
    id: number;
    name: string;
    notes?: string | null;
    is_active?: boolean;
    is_assignable?: boolean;
    sort_order?: number;
    assigned_from?: string | null;
    assigned_until?: string | null;
    assigned_client?: RoomOccupant | null;
    history?: RoomHistoryEntry[];
};

export type ClientForPicker = {
    id: number;
    first_name: string;
    last_name: string;
    preferred_name?: string | null;
    status?: string | null;
    profile_photo_url?: string | null;
    room?: { id: number; name: string } | null;
};

// ── Helpers ───────────────────────────────────────────────────────────────

export function getOccupantDisplayName(c: {
    first_name: string;
    last_name: string;
    preferred_name?: string | null;
}): string {
    const full = `${c.first_name ?? ''} ${c.last_name ?? ''}`.trim();
    if (
        c.preferred_name &&
        c.preferred_name.trim() &&
        c.preferred_name !== c.first_name
    ) {
        return `${c.preferred_name} (${full})`;
    }
    return full;
}

function initials(c: { first_name?: string | null; last_name?: string | null }) {
    return (
        ((c.first_name?.[0] ?? '') + (c.last_name?.[0] ?? '')).toUpperCase() ||
        '?'
    );
}

function FieldError({ message }: { message?: string }) {
    if (!message) return null;
    return <p className="mt-1 text-xs text-status-critical">{message}</p>;
}

// ── Add room ──────────────────────────────────────────────────────────────

type RoomFormValues = {
    name: string;
    notes: string;
    is_assignable: boolean;
};

export function AddRoomDialog({
    siteId,
    isOpen,
    onClose,
}: {
    siteId: number;
    isOpen: boolean;
    onClose: () => void;
}) {
    return (
        <Dialog open={isOpen} onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-w-lg">
                {isOpen && <AddRoomBody siteId={siteId} onClose={onClose} />}
            </DialogContent>
        </Dialog>
    );
}

function AddRoomBody({
    siteId,
    onClose,
}: {
    siteId: number;
    onClose: () => void;
}) {
    const form = useForm<RoomFormValues>({
        name: '',
        notes: '',
        is_assignable: true,
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(`/sites/${siteId}/rooms`, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => onClose(),
        });
    };

    return (
        <form onSubmit={handleSubmit}>
            <DialogHeader>
                <DialogTitle className="flex items-center gap-2">
                    <BedDouble className="h-4 w-4 text-primary" />
                    New bedroom
                </DialogTitle>
                <DialogDescription>
                    Name the bedroom — you can assign a client to it afterwards.
                </DialogDescription>
            </DialogHeader>
            <div className="mt-3 space-y-3">
                <div>
                    <Label htmlFor="rm-name">
                        Bedroom name{' '}
                        <span className="text-status-critical">*</span>
                    </Label>
                    <Input
                        id="rm-name"
                        value={form.data.name}
                        onChange={(e) => form.setData('name', e.target.value)}
                        placeholder="e.g. Bedroom 1, Sunset Room"
                        required
                    />
                    <FieldError message={form.errors.name} />
                </div>
                <div>
                    <Label htmlFor="rm-notes">Notes</Label>
                    <Textarea
                        id="rm-notes"
                        rows={3}
                        value={form.data.notes}
                        onChange={(e) =>
                            form.setData('notes', e.target.value)
                        }
                        placeholder="Bed type, accessibility features, sensory considerations…"
                    />
                    <FieldError message={form.errors.notes} />
                </div>
                <AssignableToggle
                    id="rm-assignable"
                    value={form.data.is_assignable}
                    onChange={(v) => form.setData('is_assignable', v)}
                />
            </div>
            <DialogFooter className="mt-4">
                <Button type="button" variant="outline" onClick={onClose}>
                    Cancel
                </Button>
                <Button type="submit" disabled={form.processing}>
                    {form.processing && (
                        <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                    )}
                    Save bedroom
                </Button>
            </DialogFooter>
        </form>
    );
}

function AssignableToggle({
    id,
    value,
    onChange,
}: {
    id: string;
    value: boolean;
    onChange: (v: boolean) => void;
}) {
    return (
        <div className="flex items-start gap-3 rounded-lg border bg-background/40 p-3">
            <Checkbox
                id={id}
                checked={value}
                onCheckedChange={(c) => onChange(!!c)}
                className="mt-0.5"
            />
            <div className="min-w-0 flex-1">
                <Label
                    htmlFor={id}
                    className="text-sm font-medium leading-tight"
                >
                    Assignable to client
                </Label>
                <p className="mt-1 text-xs text-muted-foreground">
                    Untick for shared spaces — kitchens, lounges, bathrooms,
                    hallways. Only assignable rooms can have a client occupant.
                </p>
            </div>
        </div>
    );
}

// ── Edit room ─────────────────────────────────────────────────────────────

export function EditRoomDialog({
    siteId,
    room,
    isOpen,
    onClose,
}: {
    siteId: number;
    room: RoomRecord | null;
    isOpen: boolean;
    onClose: () => void;
}) {
    return (
        <Dialog open={isOpen} onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-w-lg">
                {isOpen && room && (
                    <EditRoomBody
                        siteId={siteId}
                        room={room}
                        onClose={onClose}
                    />
                )}
            </DialogContent>
        </Dialog>
    );
}

function EditRoomBody({
    siteId,
    room,
    onClose,
}: {
    siteId: number;
    room: RoomRecord;
    onClose: () => void;
}) {
    const form = useForm<RoomFormValues & { assigned_client_id: number | null }>({
        name: room.name ?? '',
        notes: room.notes ?? '',
        is_assignable: room.is_assignable ?? true,
        // Preserve the current occupant — the assignment endpoint owns that
        // field, but the legacy update route also accepts it.
        assigned_client_id: room.assigned_client?.id ?? null,
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        form.put(`/sites/${siteId}/rooms/${room.id}`, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => onClose(),
        });
    };

    return (
        <form onSubmit={handleSubmit}>
            <DialogHeader>
                <DialogTitle>Edit bedroom</DialogTitle>
                <DialogDescription>
                    Update name or notes. Use “Assign client” to change the
                    occupant.
                </DialogDescription>
            </DialogHeader>
            <div className="mt-3 space-y-3">
                <div>
                    <Label htmlFor="erm-name">
                        Bedroom name{' '}
                        <span className="text-status-critical">*</span>
                    </Label>
                    <Input
                        id="erm-name"
                        value={form.data.name}
                        onChange={(e) => form.setData('name', e.target.value)}
                        required
                    />
                    <FieldError message={form.errors.name} />
                </div>
                <div>
                    <Label htmlFor="erm-notes">Notes</Label>
                    <Textarea
                        id="erm-notes"
                        rows={3}
                        value={form.data.notes}
                        onChange={(e) =>
                            form.setData('notes', e.target.value)
                        }
                    />
                    <FieldError message={form.errors.notes} />
                </div>
                <AssignableToggle
                    id="erm-assignable"
                    value={form.data.is_assignable}
                    onChange={(v) => form.setData('is_assignable', v)}
                />
                {!form.data.is_assignable && room.assigned_client && (
                    <p className="rounded-md border border-status-warning/40 bg-status-warning-bg/40 px-3 py-2 text-xs text-status-warning">
                        Marking this room as a communal space will unassign{' '}
                        <span className="font-medium">
                            {`${room.assigned_client.first_name ?? ''} ${room.assigned_client.last_name ?? ''}`.trim()}
                        </span>{' '}
                        and close their assignment history.
                    </p>
                )}
            </div>
            <DialogFooter className="mt-4">
                <Button type="button" variant="outline" onClick={onClose}>
                    Cancel
                </Button>
                <Button type="submit" disabled={form.processing}>
                    {form.processing && (
                        <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                    )}
                    Save changes
                </Button>
            </DialogFooter>
        </form>
    );
}

// ── Delete (deactivate) room ──────────────────────────────────────────────

export function DeleteRoomDialog({
    siteId,
    room,
    isOpen,
    onClose,
}: {
    siteId: number;
    room: RoomRecord | null;
    isOpen: boolean;
    onClose: () => void;
}) {
    const [submitting, setSubmitting] = useState(false);

    const handleDelete = () => {
        if (!room) return;
        setSubmitting(true);
        router.delete(`/sites/${siteId}/rooms/${room.id}`, {
            preserveScroll: true,
            preserveState: true,
            onFinish: () => setSubmitting(false),
            onSuccess: () => onClose(),
        });
    };

    return (
        <Dialog open={isOpen} onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-w-md">
                <DialogHeader>
                    <DialogTitle>Deactivate bedroom?</DialogTitle>
                    <DialogDescription>
                        {room && (
                            <>
                                <span className="font-medium">{room.name}</span>{' '}
                                will be hidden from the active list. History
                                and any current assignment are kept intact.
                            </>
                        )}
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button
                        variant="destructive"
                        onClick={handleDelete}
                        disabled={submitting}
                    >
                        {submitting && (
                            <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                        )}
                        Deactivate
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

// ── Show / overview room ──────────────────────────────────────────────────

export function ShowRoomDialog({
    room,
    canManage,
    isOpen,
    onClose,
    onEdit,
    onDelete,
    onAssign,
    onUnassign,
}: {
    room: RoomRecord | null;
    canManage: boolean;
    isOpen: boolean;
    onClose: () => void;
    onEdit: () => void;
    onDelete: () => void;
    onAssign: () => void;
    onUnassign: () => void;
}) {
    if (!room) {
        return (
            <Dialog open={isOpen} onOpenChange={(open) => !open && onClose()}>
                <DialogContent className="max-w-md" />
            </Dialog>
        );
    }
    const occupant = room.assigned_client ?? null;
    return (
        <Dialog open={isOpen} onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-w-xl overflow-hidden p-0">
                {/* Branded gradient header — mirrors the site hero so the
                    dialog feels part of the same design system. */}
                <div className="relative overflow-hidden bg-gradient-to-br from-primary/90 via-primary to-primary/80 px-6 py-5 text-primary-foreground">
                    <div className="pointer-events-none absolute -top-10 -right-10 h-32 w-32 rounded-full bg-primary-foreground/10" />
                    <div className="pointer-events-none absolute -bottom-12 -left-8 h-24 w-24 rounded-full bg-primary-foreground/5" />
                    <DialogHeader className="relative space-y-0">
                        <div className="flex items-start gap-3">
                            <span className="flex h-12 w-12 shrink-0 items-center justify-center rounded-full border-2 border-primary-foreground/30 bg-primary-foreground/10">
                                <BedDouble className="h-6 w-6 text-primary-foreground" />
                            </span>
                            <div className="min-w-0 flex-1">
                                <DialogTitle className="truncate text-lg text-primary-foreground">
                                    {room.name}
                                </DialogTitle>
                                <DialogDescription className="mt-1 flex flex-wrap items-center gap-2 text-primary-foreground/80">
                                    {room.is_assignable === false ? (
                                        <Badge className="border-primary-foreground/30 bg-primary-foreground/15 text-[10px] text-primary-foreground">
                                            Communal
                                        </Badge>
                                    ) : occupant ? (
                                        <Badge className="border-primary-foreground/30 bg-primary-foreground/20 text-[10px] text-primary-foreground">
                                            Assigned
                                        </Badge>
                                    ) : (
                                        <Badge className="border-status-success/40 bg-status-success-bg/30 text-[10px] text-primary-foreground">
                                            Available
                                        </Badge>
                                    )}
                                    {(room.assigned_from || room.assigned_until) && (
                                        <span className="text-xs text-primary-foreground/70">
                                            {room.assigned_from && (
                                                <>Since {room.assigned_from}</>
                                            )}
                                            {room.assigned_until && (
                                                <> · until {room.assigned_until}</>
                                            )}
                                        </span>
                                    )}
                                </DialogDescription>
                            </div>
                        </div>
                    </DialogHeader>
                </div>

                <div className="space-y-3 px-6 pb-2 pt-4">
                {occupant ? (
                    <div className="rounded-xl border bg-card/40 p-3">
                        <div className="flex items-center gap-3">
                            <Avatar className="size-11 shrink-0">
                                {occupant.profile_photo_url && (
                                    <AvatarImage
                                        src={occupant.profile_photo_url}
                                        alt={getOccupantDisplayName(occupant)}
                                    />
                                )}
                                <AvatarFallback>
                                    {initials(occupant)}
                                </AvatarFallback>
                            </Avatar>
                            <div className="min-w-0 flex-1">
                                <p className="truncate text-sm font-medium">
                                    {getOccupantDisplayName(occupant)}
                                </p>
                                <p className="truncate text-xs text-muted-foreground">
                                    Current occupant
                                    {occupant.status
                                        ? ` · ${occupant.status}`
                                        : ''}
                                </p>
                            </div>
                        </div>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            asChild
                            className="mt-3 w-full"
                        >
                            <a href={`/clients/${occupant.id}`}>
                                Open {occupant.first_name}'s profile
                            </a>
                        </Button>
                    </div>
                ) : (
                    <div className="rounded-xl border border-dashed p-3 text-center text-sm text-muted-foreground">
                        No client assigned yet.
                    </div>
                )}

                {room.notes && (
                    <div className="rounded-lg border bg-muted/30 p-3 text-sm">
                        <p className="mb-1 text-xs uppercase tracking-wide text-muted-foreground">
                            Notes
                        </p>
                        <p className="whitespace-pre-wrap">{room.notes}</p>
                    </div>
                )}

                {room.history && room.history.length > 0 && (
                    <div className="rounded-xl border bg-card/40">
                        <p className="flex items-center gap-1.5 border-b px-3 py-2 text-xs uppercase tracking-wide text-muted-foreground">
                            <History className="h-3 w-3" />
                            Assignment history
                            <span className="ml-auto normal-case tracking-normal text-[10px] opacity-70">
                                Most recent first
                            </span>
                        </p>
                        <ul className="max-h-48 divide-y overflow-y-auto">
                            {room.history.slice(0, 8).map((h) => {
                                const range = `${h.assigned_from ?? '—'} → ${
                                    h.assigned_until ?? 'present'
                                }`;
                                return (
                                    <li
                                        key={h.id}
                                        className="px-3 py-2 text-xs"
                                    >
                                        <p className="truncate font-medium">
                                            {h.client
                                                ? `${h.client.first_name} ${h.client.last_name}`.trim()
                                                : 'Unknown client'}
                                        </p>
                                        <p className="truncate text-[11px] text-muted-foreground">
                                            {range}
                                            {h.assigned_by && (
                                                <> · by {h.assigned_by}</>
                                            )}
                                        </p>
                                    </li>
                                );
                            })}
                        </ul>
                    </div>
                )}

                </div>

                <DialogFooter className="flex flex-row flex-wrap items-center gap-2 border-t bg-muted/20 px-6 py-3 sm:justify-between">
                    <div className="flex flex-wrap items-center gap-1">
                        {canManage && (
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                className="text-status-critical hover:text-status-critical"
                                onClick={onDelete}
                                aria-label="Deactivate room"
                                title="Deactivate room"
                            >
                                <Trash2 className="h-4 w-4" />
                            </Button>
                        )}
                        {canManage && (
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                onClick={onEdit}
                                aria-label="Edit room"
                                title="Edit room"
                            >
                                <Pencil className="h-4 w-4" />
                            </Button>
                        )}
                        {canManage && occupant && room.is_assignable !== false && (
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                onClick={onUnassign}
                                aria-label="Unassign occupant"
                                title="Unassign occupant"
                            >
                                <Link2Off className="h-4 w-4" />
                            </Button>
                        )}
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={onClose}
                        >
                            Close
                        </Button>
                        {canManage && room.is_assignable !== false && (
                            <Button
                                type="button"
                                size="sm"
                                onClick={onAssign}
                            >
                                <UserPlus className="mr-1.5 h-3.5 w-3.5" />
                                {occupant ? 'Change occupant' : 'Assign client'}
                            </Button>
                        )}
                    </div>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

// ── Assign-client-to-room dialog (used FROM the Rooms tab) ────────────────
//
// Fixed: room. User picks a client from the site's clients.

export function AssignClientToRoomDialog({
    siteId,
    room,
    clients,
    isOpen,
    onClose,
}: {
    siteId: number;
    room: RoomRecord | null;
    clients: ClientForPicker[];
    isOpen: boolean;
    onClose: () => void;
}) {
    return (
        <Dialog open={isOpen} onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-w-xl">
                {isOpen && room && (
                    <AssignClientBody
                        siteId={siteId}
                        room={room}
                        clients={clients}
                        onClose={onClose}
                    />
                )}
            </DialogContent>
        </Dialog>
    );
}

function AssignClientBody({
    siteId,
    room,
    clients,
    onClose,
}: {
    siteId: number;
    room: RoomRecord;
    clients: ClientForPicker[];
    onClose: () => void;
}) {
    const currentClientId = room.assigned_client?.id ?? null;
    const [selectedId, setSelectedId] = useState<number | null>(currentClientId);
    const [query, setQuery] = useState('');
    const [assignedFrom, setAssignedFrom] = useState(
        room.assigned_from ?? '',
    );
    const [assignedUntil, setAssignedUntil] = useState(
        room.assigned_until ?? '',
    );
    const [notes, setNotes] = useState('');
    const [submitting, setSubmitting] = useState(false);

    const filtered = useMemo(() => {
        const q = query.trim().toLowerCase();
        if (!q) return clients;
        return clients.filter((c) => {
            const hay =
                `${c.first_name} ${c.last_name} ${c.preferred_name ?? ''}`.toLowerCase();
            return hay.includes(q);
        });
    }, [clients, query]);

    const handleAssign = () => {
        if (!selectedId) return;
        setSubmitting(true);
        router.post(
            `/sites/${siteId}/rooms/${room.id}/assign`,
            {
                client_id: selectedId,
                assigned_from: assignedFrom || null,
                assigned_until: assignedUntil || null,
                notes: notes || null,
            },
            {
                preserveScroll: true,
                preserveState: true,
                onFinish: () => setSubmitting(false),
                onSuccess: () => onClose(),
            },
        );
    };

    return (
        <>
            <DialogHeader>
                <DialogTitle className="flex items-center gap-2">
                    <UserPlus className="h-4 w-4 text-primary" />
                    {currentClientId
                        ? `Change occupant of ${room.name}`
                        : `Assign a client to ${room.name}`}
                </DialogTitle>
                <DialogDescription>
                    Choose a client from this site. Use the dates to record a
                    respite or fixed-term stay.
                </DialogDescription>
            </DialogHeader>

            <div className="mt-3 space-y-3">
                <div>
                    <Label htmlFor="ac-search">Search clients at this site</Label>
                    <Input
                        id="ac-search"
                        placeholder="Search by name…"
                        value={query}
                        onChange={(e) => setQuery(e.target.value)}
                    />
                </div>

                <div className="max-h-60 overflow-y-auto rounded-xl border bg-card/40">
                    {filtered.length === 0 ? (
                        <p className="px-4 py-6 text-center text-xs text-muted-foreground">
                            {clients.length === 0
                                ? 'No clients linked to this site yet — link a client to the site first.'
                                : `No clients match "${query}".`}
                        </p>
                    ) : (
                        <ul className="divide-y">
                            {filtered.map((c) => {
                                const active = selectedId === c.id;
                                const inOtherRoom =
                                    c.room && c.room.id !== room.id;
                                return (
                                    <li key={c.id}>
                                        <button
                                            type="button"
                                            onClick={() => setSelectedId(c.id)}
                                            className={cn(
                                                'flex w-full items-center justify-between gap-3 px-3 py-2 text-left text-sm transition-colors',
                                                active
                                                    ? 'bg-primary/10'
                                                    : 'hover:bg-muted/50',
                                            )}
                                        >
                                            <div className="flex min-w-0 items-center gap-3">
                                                <Avatar className="size-8">
                                                    {c.profile_photo_url && (
                                                        <AvatarImage
                                                            src={
                                                                c.profile_photo_url
                                                            }
                                                            alt=""
                                                        />
                                                    )}
                                                    <AvatarFallback className="text-[10px]">
                                                        {initials(c)}
                                                    </AvatarFallback>
                                                </Avatar>
                                                <div className="min-w-0">
                                                    <p className="truncate font-medium">
                                                        {getOccupantDisplayName(
                                                            c,
                                                        )}
                                                    </p>
                                                    <p className="truncate text-xs text-muted-foreground">
                                                        {inOtherRoom
                                                            ? `Currently in ${c.room?.name}`
                                                            : c.room
                                                              ? 'Already in this room'
                                                              : 'Not in a room'}
                                                    </p>
                                                </div>
                                            </div>
                                            {c.status && (
                                                <Badge
                                                    variant="outline"
                                                    className="text-[10px]"
                                                >
                                                    {c.status}
                                                </Badge>
                                            )}
                                        </button>
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                </div>

                <div className="grid gap-3 sm:grid-cols-2">
                    <div>
                        <Label htmlFor="ac-from">Assigned from</Label>
                        <Input
                            id="ac-from"
                            type="date"
                            value={assignedFrom}
                            onChange={(e) => setAssignedFrom(e.target.value)}
                        />
                    </div>
                    <div>
                        <Label htmlFor="ac-until">
                            Until <span className="text-xs text-muted-foreground">(optional)</span>
                        </Label>
                        <Input
                            id="ac-until"
                            type="date"
                            value={assignedUntil}
                            onChange={(e) => setAssignedUntil(e.target.value)}
                        />
                    </div>
                </div>

                <div>
                    <Label htmlFor="ac-notes">Notes</Label>
                    <Textarea
                        id="ac-notes"
                        rows={2}
                        value={notes}
                        onChange={(e) => setNotes(e.target.value)}
                        placeholder="Respite stay, transition arrangement…"
                    />
                </div>
            </div>

            <DialogFooter className="mt-4">
                <Button type="button" variant="outline" onClick={onClose}>
                    Cancel
                </Button>
                <Button
                    type="button"
                    onClick={handleAssign}
                    disabled={!selectedId || submitting}
                >
                    {submitting && (
                        <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                    )}
                    {currentClientId && selectedId !== currentClientId
                        ? 'Reassign'
                        : 'Assign'}
                </Button>
            </DialogFooter>
        </>
    );
}

// ── Assign-room-to-client dialog (used FROM the Clients tab) ──────────────
//
// Fixed: client. User picks a room from the site's available rooms (+ their
// current room).

export function AssignRoomToClientDialog({
    siteId,
    client,
    rooms,
    isOpen,
    onClose,
}: {
    siteId: number;
    client: {
        id: number;
        first_name: string;
        last_name: string;
        preferred_name?: string | null;
        room?: { id: number; name: string } | null;
    } | null;
    rooms: RoomRecord[];
    isOpen: boolean;
    onClose: () => void;
}) {
    return (
        <Dialog open={isOpen} onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-w-xl">
                {isOpen && client && (
                    <AssignRoomBody
                        siteId={siteId}
                        client={client}
                        rooms={rooms}
                        onClose={onClose}
                    />
                )}
            </DialogContent>
        </Dialog>
    );
}

function AssignRoomBody({
    siteId,
    client,
    rooms,
    onClose,
}: {
    siteId: number;
    client: {
        id: number;
        first_name: string;
        last_name: string;
        preferred_name?: string | null;
        room?: { id: number; name: string } | null;
    };
    rooms: RoomRecord[];
    onClose: () => void;
}) {
    const currentRoomId = client.room?.id ?? null;
    const pickable = useMemo(
        () =>
            rooms.filter((r) => {
                // Communal spaces are never pickable.
                if (r.is_assignable === false) return false;
                // Otherwise pick rooms that are free, the client's own, or
                // their current room (so they can confirm/clear it).
                return (
                    !r.assigned_client ||
                    r.assigned_client.id === client.id ||
                    r.id === currentRoomId
                );
            }),
        [rooms, client.id, currentRoomId],
    );
    const [selectedId, setSelectedId] = useState<number | null>(currentRoomId);
    const [assignedFrom, setAssignedFrom] = useState('');
    const [assignedUntil, setAssignedUntil] = useState('');
    const [notes, setNotes] = useState('');
    const [submitting, setSubmitting] = useState(false);

    const handleAssign = () => {
        if (!selectedId) return;
        setSubmitting(true);
        router.post(
            `/sites/${siteId}/rooms/${selectedId}/assign`,
            {
                client_id: client.id,
                assigned_from: assignedFrom || null,
                assigned_until: assignedUntil || null,
                notes: notes || null,
            },
            {
                preserveScroll: true,
                preserveState: true,
                onFinish: () => setSubmitting(false),
                onSuccess: () => onClose(),
            },
        );
    };

    return (
        <>
            <DialogHeader>
                <DialogTitle className="flex items-center gap-2">
                    <BedDouble className="h-4 w-4 text-primary" />
                    {currentRoomId
                        ? `Change room for ${getOccupantDisplayName(client)}`
                        : `Assign a room to ${getOccupantDisplayName(client)}`}
                </DialogTitle>
                <DialogDescription>
                    Pick an available bedroom. Dates are optional but useful for
                    respite stays.
                </DialogDescription>
            </DialogHeader>

            <div className="mt-3 space-y-3">
                <div className="max-h-72 overflow-y-auto rounded-xl border bg-card/40">
                    {pickable.length === 0 ? (
                        <p className="px-4 py-6 text-center text-xs text-muted-foreground">
                            No bedrooms available — every active room already
                            has an occupant.
                        </p>
                    ) : (
                        <ul className="divide-y">
                            {pickable.map((r) => {
                                const active = selectedId === r.id;
                                const isCurrent = r.id === currentRoomId;
                                return (
                                    <li key={r.id}>
                                        <button
                                            type="button"
                                            onClick={() => setSelectedId(r.id)}
                                            className={cn(
                                                'flex w-full items-center justify-between gap-3 px-3 py-2 text-left text-sm transition-colors',
                                                active
                                                    ? 'bg-primary/10'
                                                    : 'hover:bg-muted/50',
                                            )}
                                        >
                                            <div className="flex min-w-0 items-center gap-3">
                                                <span className="shrink-0 rounded-lg border bg-background/60 p-1.5">
                                                    <BedDouble className="h-4 w-4 text-primary" />
                                                </span>
                                                <div className="min-w-0">
                                                    <p className="truncate font-medium">
                                                        {r.name}
                                                    </p>
                                                    <p className="truncate text-xs text-muted-foreground">
                                                        {isCurrent
                                                            ? 'Current room'
                                                            : 'Available'}
                                                    </p>
                                                </div>
                                            </div>
                                            <Badge
                                                variant="outline"
                                                className={cn(
                                                    'text-[10px]',
                                                    isCurrent
                                                        ? 'border-primary/30 text-primary'
                                                        : 'border-status-success/30 text-status-success',
                                                )}
                                            >
                                                {isCurrent
                                                    ? 'Current'
                                                    : 'Available'}
                                            </Badge>
                                        </button>
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                </div>

                <div className="grid gap-3 sm:grid-cols-2">
                    <div>
                        <Label htmlFor="ar-from">Assigned from</Label>
                        <Input
                            id="ar-from"
                            type="date"
                            value={assignedFrom}
                            onChange={(e) => setAssignedFrom(e.target.value)}
                        />
                    </div>
                    <div>
                        <Label htmlFor="ar-until">
                            Until{' '}
                            <span className="text-xs text-muted-foreground">
                                (optional)
                            </span>
                        </Label>
                        <Input
                            id="ar-until"
                            type="date"
                            value={assignedUntil}
                            onChange={(e) => setAssignedUntil(e.target.value)}
                        />
                    </div>
                </div>

                <div>
                    <Label htmlFor="ar-notes">Notes</Label>
                    <Textarea
                        id="ar-notes"
                        rows={2}
                        value={notes}
                        onChange={(e) => setNotes(e.target.value)}
                        placeholder="Respite stay, transition arrangement…"
                    />
                </div>
            </div>

            <DialogFooter className="mt-4">
                <Button type="button" variant="outline" onClick={onClose}>
                    Cancel
                </Button>
                <Button
                    type="button"
                    onClick={handleAssign}
                    disabled={!selectedId || submitting}
                >
                    {submitting && (
                        <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                    )}
                    {currentRoomId && selectedId !== currentRoomId
                        ? 'Move client'
                        : 'Assign room'}
                </Button>
            </DialogFooter>
        </>
    );
}

// ── Unassign confirm ──────────────────────────────────────────────────────

export function UnassignRoomDialog({
    siteId,
    room,
    isOpen,
    onClose,
}: {
    siteId: number;
    room: RoomRecord | null;
    isOpen: boolean;
    onClose: () => void;
}) {
    const [submitting, setSubmitting] = useState(false);
    const occupant = room?.assigned_client ?? null;

    const handleUnassign = () => {
        if (!room) return;
        setSubmitting(true);
        router.post(
            `/sites/${siteId}/rooms/${room.id}/assign`,
            { client_id: null },
            {
                preserveScroll: true,
                preserveState: true,
                onFinish: () => setSubmitting(false),
                onSuccess: () => onClose(),
            },
        );
    };

    return (
        <Dialog open={isOpen} onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-w-md">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2">
                        <AlertTriangle className="h-4 w-4 text-status-warning" />
                        Unassign this room?
                    </DialogTitle>
                    <DialogDescription>
                        {occupant && room && (
                            <>
                                <span className="font-medium">
                                    {getOccupantDisplayName(occupant)}
                                </span>{' '}
                                will be marked as no longer in{' '}
                                <span className="font-medium">{room.name}</span>
                                . The assignment is closed and archived in
                                history.
                            </>
                        )}
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button
                        variant="destructive"
                        onClick={handleUnassign}
                        disabled={submitting}
                    >
                        {submitting && (
                            <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                        )}
                        <UserX className="mr-2 h-4 w-4" />
                        Unassign
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

// Silence unused-import warnings for icons we surface via dialogs.
void User;
void CalendarDays;
