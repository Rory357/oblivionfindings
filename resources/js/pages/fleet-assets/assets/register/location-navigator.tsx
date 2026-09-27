/* eslint-disable no-restricted-syntax -- Custom connected tabs, location selectors and directory rows follow the approved workspace composition; standard actions use Button. */
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import {
    Building2,
    Check,
    ChevronRight,
    Layers,
    MapPin,
    Pin,
    Search,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import type { Option } from './api';

export type LocationOption = Option & { asset_count?: number };

export function LocationNavigator({
    sites,
    rooms,
    site,
    room,
    onSite,
    onRoom,
    open,
    onOpenChange,
    userId,
    archived,
}: {
    sites: LocationOption[];
    rooms: LocationOption[];
    site: string;
    room: string;
    onSite: (value: string) => void;
    onRoom: (value: string) => void;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    userId: number;
    archived: boolean;
}) {
    const storageKey = `assets-location-shortcuts:${userId}`;
    const [shortcuts, setShortcuts] = useState<{
        pinned: number[];
        recent: number[];
    }>(() => {
        try {
            const stored = JSON.parse(
                localStorage.getItem(storageKey) || 'null',
            );
            if (
                stored &&
                Array.isArray(stored.pinned) &&
                Array.isArray(stored.recent)
            )
                return {
                    pinned: stored.pinned.filter(Number.isInteger).slice(0, 5),
                    recent: stored.recent.filter(Number.isInteger).slice(0, 3),
                };
        } catch {
            /* Browser shortcuts are optional. */
        }
        return { pinned: [], recent: [] };
    });
    const [search, setSearch] = useState('');
    const [roomSearch, setRoomSearch] = useState('');
    const [page, setPage] = useState(1);
    const [message, setMessage] = useState('');
    useEffect(() => {
        try {
            localStorage.setItem(storageKey, JSON.stringify(shortcuts));
        } catch {
            /* Optional preference. */
        }
    }, [shortcuts, storageKey]);
    useEffect(() => {
        setRoomSearch('');
    }, [site]);
    const chosen = sites.find((s) => String(s.id) === site);
    const total = sites.reduce((sum, s) => sum + (s.asset_count || 0), 0);
    const results = sites.filter((s) =>
        `${s.name} ${s.id}`.toLowerCase().includes(search.trim().toLowerCase()),
    );
    const pages = Math.max(1, Math.ceil(results.length / 8));
    const currentPage = Math.min(page, pages);
    const pinned = sites.filter((s) => shortcuts.pinned.includes(s.id));
    const recent = shortcuts.recent
        .map((id) => sites.find((s) => s.id === id))
        .filter(
            (s): s is LocationOption => !!s && !shortcuts.pinned.includes(s.id),
        );
    function choose(value: LocationOption) {
        setShortcuts((old) => ({
            ...old,
            recent: [
                value.id,
                ...old.recent.filter((id) => id !== value.id),
            ].slice(0, 3),
        }));
        onSite(String(value.id));
        onOpenChange(false);
    }
    function pin(value: LocationOption) {
        if (
            !shortcuts.pinned.includes(value.id) &&
            shortcuts.pinned.length >= 5
        ) {
            setMessage('Five sites are pinned. Unpin one to add another.');
            return;
        }
        setShortcuts((old) => ({
            ...old,
            pinned: old.pinned.includes(value.id)
                ? old.pinned.filter((id) => id !== value.id)
                : [...old.pinned, value.id],
        }));
        setMessage('Site shortcuts updated.');
    }
    const shortcut = (s: LocationOption) => (
        <button
            key={s.id}
            className="asset-location-shortcut"
            onClick={() => choose(s)}
        >
            <Building2 className="size-4" />
            <span>
                {s.name}
                <small>Site {s.id}</small>
            </span>
            <small>{s.asset_count ?? '—'}</small>
        </button>
    );
    function directory() {
        setSearch('');
        setPage(1);
        onOpenChange(true);
    }
    return (
        <>
            <aside
                className="asset-location-nav"
                aria-label="Assigned location"
            >
                <div className="asset-location-heading">
                    Assigned location <MapPin className="size-4" />
                </div>
                <button className="asset-location-search" onClick={directory}>
                    <Search className="size-4" />
                    <span>Find a site</span>
                    <small>{sites.length}</small>
                </button>
                <button
                    className="asset-location-shortcut"
                    aria-current={!site ? 'true' : undefined}
                    onClick={() => onSite('')}
                >
                    <Layers className="size-4" />
                    <span>All permitted sites</span>
                    <small>{total}</small>
                </button>
                {chosen ? (
                    <>
                        <div className="asset-chosen-site">
                            <div>
                                <strong>{chosen.name}</strong>
                                <button
                                    aria-label={`${shortcuts.pinned.includes(chosen.id) ? 'Unpin' : 'Pin'} ${chosen.name}`}
                                    aria-pressed={shortcuts.pinned.includes(
                                        chosen.id,
                                    )}
                                    onClick={() => pin(chosen)}
                                >
                                    <Pin className="size-4" />
                                </button>
                            </div>
                            <small>Site {chosen.id}</small>
                            <button
                                className="asset-text-action"
                                onClick={directory}
                            >
                                Change site <ChevronRight className="size-3" />
                            </button>
                        </div>
                        <div className="asset-location-heading mt-5">Rooms</div>
                        <button
                            className="asset-room"
                            aria-current={!room ? 'true' : undefined}
                            onClick={() => onRoom('')}
                        >
                            <span>All rooms</span>
                            <small>{chosen.asset_count ?? '—'}</small>
                        </button>
                        {rooms.length > 6 && (
                            <Input
                                aria-label="Filter rooms"
                                placeholder="Find a room…"
                                value={roomSearch}
                                onChange={(e) => setRoomSearch(e.target.value)}
                            />
                        )}
                        <div
                            className="max-h-64 overflow-y-auto"
                            aria-label="Rooms at selected site"
                        >
                            {rooms
                                .filter((r) =>
                                    r.name
                                        .toLowerCase()
                                        .includes(roomSearch.toLowerCase()),
                                )
                                .map((r) => (
                                    <button
                                        key={r.id}
                                        className="asset-room"
                                        aria-current={
                                            room === String(r.id)
                                                ? 'true'
                                                : undefined
                                        }
                                        onClick={() => onRoom(String(r.id))}
                                    >
                                        <span>{r.name}</span>
                                        <small>{r.asset_count ?? '—'}</small>
                                    </button>
                                ))}
                            {!rooms.length && (
                                <p className="p-2 text-xs text-muted-foreground">
                                    No rooms recorded at this site.
                                </p>
                            )}
                        </div>
                    </>
                ) : (
                    <>
                        <div className="asset-location-heading mt-5">
                            Pinned <span>{pinned.length} / 5</span>
                        </div>
                        {pinned.map(shortcut)}
                        {!pinned.length && (
                            <p className="px-2 py-3 text-xs leading-relaxed text-muted-foreground">
                                Pin your usual sites from the directory for
                                quick access.
                            </p>
                        )}
                        {!!recent.length && (
                            <>
                                <div className="asset-location-heading mt-5">
                                    Recent
                                </div>
                                {recent.map(shortcut)}
                            </>
                        )}
                        <button
                            className="asset-browse-sites"
                            onClick={directory}
                        >
                            Browse {sites.length} sites{' '}
                            <ChevronRight className="size-4" />
                        </button>
                    </>
                )}
                <p className="mt-5 px-2 text-xs text-muted-foreground">
                    {archived ? 'Archived' : 'Current'} assets · before other
                    filters
                </p>
                <p className="mt-3 border-t px-2 pt-3 text-xs leading-relaxed text-muted-foreground">
                    Assigned location is the recorded home. Dated checks are
                    shown separately.
                </p>
            </aside>
            <Dialog open={open} onOpenChange={onOpenChange}>
                <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                    <DialogHeader>
                        <DialogTitle>Choose assigned location</DialogTitle>
                        <DialogDescription>
                            Search {sites.length} permitted sites by name or
                            reference. Choose a site, then narrow to a room.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="relative">
                        <Search className="absolute top-3.5 left-3 size-4 text-muted-foreground" />
                        <Input
                            autoFocus
                            className="h-11 pl-10"
                            aria-label="Search sites"
                            placeholder="Search site name or reference…"
                            value={search}
                            onChange={(e) => {
                                setSearch(e.target.value);
                                setPage(1);
                            }}
                        />
                    </div>
                    <p className="text-xs text-muted-foreground">
                        {results.length} matching sites · {pinned.length} of 5
                        pinned
                    </p>
                    <div className="divide-y overflow-hidden rounded-xl border">
                        {results
                            .slice((currentPage - 1) * 8, currentPage * 8)
                            .map((s) => (
                                <div key={s.id} className="flex">
                                    <button
                                        className="flex min-h-16 min-w-0 flex-1 items-center gap-3 px-4 py-3 text-left hover:bg-muted"
                                        onClick={() => choose(s)}
                                    >
                                        <span className="grid size-9 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary">
                                            <Building2 className="size-4" />
                                        </span>
                                        <span className="min-w-0 flex-1">
                                            <strong className="block truncate text-sm">
                                                {s.name}
                                            </strong>
                                            <small className="text-muted-foreground">
                                                Site {s.id} ·{' '}
                                                {s.asset_count ?? 0} assets
                                            </small>
                                        </span>
                                        {String(s.id) === site ? (
                                            <Check className="size-4 text-primary" />
                                        ) : (
                                            <ChevronRight className="size-4" />
                                        )}
                                    </button>
                                    <button
                                        className="min-w-11 border-l px-3 text-muted-foreground hover:bg-muted aria-pressed:bg-primary/10 aria-pressed:text-primary"
                                        aria-label={`${shortcuts.pinned.includes(s.id) ? 'Unpin' : 'Pin'} ${s.name}`}
                                        aria-pressed={shortcuts.pinned.includes(
                                            s.id,
                                        )}
                                        onClick={() => pin(s)}
                                    >
                                        <Pin className="size-4" />
                                    </button>
                                </div>
                            ))}
                        {!results.length && (
                            <p className="p-8 text-center text-sm text-muted-foreground">
                                No sites match. Try another name or reference.
                            </p>
                        )}
                    </div>
                    <div className="flex items-center justify-between gap-3">
                        <span className="text-xs text-muted-foreground">
                            Page {currentPage} of {pages}
                        </span>
                        <div className="flex gap-2">
                            <Button
                                variant="outline"
                                disabled={currentPage === 1}
                                onClick={() => setPage(currentPage - 1)}
                            >
                                Previous
                            </Button>
                            <Button
                                variant="outline"
                                disabled={currentPage === pages}
                                onClick={() => setPage(currentPage + 1)}
                            >
                                Next
                            </Button>
                        </div>
                    </div>
                    <p role="status" className="text-xs text-muted-foreground">
                        {message ||
                            'Pinned and recent sites are saved on this device. Access is checked on every visit.'}
                    </p>
                </DialogContent>
            </Dialog>
        </>
    );
}
