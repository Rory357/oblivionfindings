import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { hubRequest } from '@/pages/company-hub/_api';
import type { HubMedia } from '@/pages/company-hub/_media-library';
import { fileSize } from '@/pages/company-hub/_upload';
import { Check, FileText, Images, RefreshCw, Search } from 'lucide-react';
import { useEffect, useState } from 'react';

export type EventLibraryPhoto = HubMedia & { albumName: string };
type Library = {
    actor_id: number;
    media: HubMedia[];
    albums: { id: number; name: string }[];
    next_page: number | null;
};

/** Selection is local. The event save checks the actor and source version before copying. */
export function EventImageLibrary({
    actorId,
    selected,
    onSelect,
    atLimit = false,
}: {
    actorId: number;
    selected: EventLibraryPhoto[];
    onSelect: (photo: EventLibraryPhoto) => void;
    atLimit?: boolean;
}) {
    const [query, setQuery] = useState('');
    const [search, setSearch] = useState('');
    const [page, setPage] = useState(1);
    const [retry, setRetry] = useState(0);
    const [data, setData] = useState<Library | null>(null);
    const [busy, setBusy] = useState(true);
    const [error, setError] = useState('');
    useEffect(() => {
        let live = true;
        setBusy(true);
        setError('');
        setData(null);
        const params = new URLSearchParams({
            library: 'all',
            type: 'all',
            state: 'active',
            q: search,
            cursor_page: String(page),
        });
        hubRequest<Library>(`/company-hub/editor/media?${params}`, 'GET')
            .then((result) => {
                if (!live) return;
                if (
                    result.actor_id !== actorId ||
                    !Array.isArray(result.media) ||
                    !Array.isArray(result.albums)
                )
                    throw new Error(
                        'Your company library has changed. Refresh it before choosing a file.',
                    );
                setData(result);
            })
            .catch((reason) => {
                if (live)
                    setError(
                        reason instanceof Error
                            ? reason.message
                            : 'The company library could not be loaded. Try again.',
                    );
            })
            .finally(() => {
                if (live) setBusy(false);
            });
        return () => {
            live = false;
        };
    }, [actorId, search, page, retry]);
    const available = data?.actor_id === actorId ? data : null;
    return (
        <section
            aria-label="Choose event files"
            className="space-y-4 rounded-xl border bg-background p-5"
        >
            <div>
                <h4 className="flex items-center gap-2 font-semibold">
                    <Images className="size-4 text-primary" />
                    Your company file library
                </h4>
                <p className="mt-1 text-xs text-muted-foreground">
                    Choose files you can manage. Changes to this event’s copies
                    won’t change the original page.
                </p>
            </div>
            {!error && (
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    disabled={busy}
                    onClick={() => setRetry((value) => value + 1)}
                >
                    <RefreshCw className="size-4" />
                    Refresh company library
                </Button>
            )}
            <div role="search" className="flex gap-2">
                <Input
                    aria-label="Search event file library"
                    value={query}
                    maxLength={150}
                    onChange={(e) => setQuery(e.target.value)}
                    onKeyDown={(e) => {
                        if (e.key === 'Enter') {
                            e.preventDefault();
                            setSearch(query.trim());
                            setPage(1);
                        }
                    }}
                    placeholder="Find a photo or document…"
                />
                <Button
                    type="button"
                    variant="outline"
                    onClick={() => {
                        setSearch(query.trim());
                        setPage(1);
                    }}
                >
                    <Search className="size-4" />
                    Search files
                </Button>
            </div>
            {error && (
                <div
                    role="alert"
                    className="space-y-2 rounded-lg border p-3 text-sm"
                >
                    <p>{error}</p>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() => setRetry((value) => value + 1)}
                    >
                        <RefreshCw className="size-4" />
                        Refresh company library
                    </Button>
                </div>
            )}
            {busy && (
                <p role="status" className="text-sm text-muted-foreground">
                    Loading your files…
                </p>
            )}
            {!busy && available && (
                <>
                    {!available.media.length && (
                        <p className="py-5 text-center text-sm text-muted-foreground">
                            No files match. Try another search or attach a file
                            to the event.
                        </p>
                    )}
                    <div className="grid grid-cols-3 gap-3">
                        {available.media
                            .filter((item) => !item.removed_at)
                            .map((item) => {
                                const chosen = selected.find(
                                    (photo) => photo.id === item.id,
                                );
                                const changed =
                                    !!chosen && chosen.version !== item.version;
                                const oversized = item.size > 10 * 1024 * 1024;
                                return (
                                    <Button
                                        key={item.id}
                                        type="button"
                                        variant="outline"
                                        className="h-auto flex-col items-stretch overflow-hidden p-0 text-left whitespace-normal"
                                        disabled={
                                            oversized ||
                                            (!!chosen && !changed) ||
                                            (!chosen && atLimit)
                                        }
                                        onClick={() =>
                                            onSelect({
                                                ...item,
                                                albumName:
                                                    available.albums.find(
                                                        (album) =>
                                                            album.id ===
                                                            item.album_id,
                                                    )?.name ?? '',
                                            })
                                        }
                                        aria-label={
                                            changed
                                                ? `Refresh selected ${item.name}`
                                                : `Select ${item.name}`
                                        }
                                    >
                                        {item.mime.startsWith('image/') ? (
                                            <img
                                                src={item.url}
                                                alt={item.alt || ''}
                                                className="h-28 w-full object-cover"
                                            />
                                        ) : (
                                            <span className="flex h-28 items-center justify-center bg-muted/40">
                                                <FileText className="size-9 text-primary" />
                                            </span>
                                        )}
                                        <span className="space-y-1 p-3">
                                            <span className="flex items-center gap-2 text-sm font-medium">
                                                {chosen && (
                                                    <Check className="size-4 shrink-0 text-primary" />
                                                )}
                                                {item.name}
                                            </span>
                                            <span className="block text-xs font-normal text-muted-foreground">
                                                {item.page_title}
                                            </span>
                                            <span className="block text-xs font-normal text-muted-foreground">
                                                {fileSize(item.size)}
                                            </span>
                                            {oversized && (
                                                <span className="block text-xs font-normal text-destructive">
                                                    Exceeds the 10 MB event file
                                                    limit
                                                </span>
                                            )}
                                            {changed && (
                                                <span className="block text-xs font-normal text-primary">
                                                    {item.mime.startsWith(
                                                        'image/',
                                                    )
                                                        ? 'Use the updated source. Your caption, cover and gallery order are kept.'
                                                        : 'Use the latest details for this file.'}
                                                </span>
                                            )}
                                        </span>
                                    </Button>
                                );
                            })}
                    </div>
                    {atLimit && (
                        <p
                            role="status"
                            className="text-xs text-muted-foreground"
                        >
                            You can attach up to 10 files in this save. Remove a
                            selected file to choose another file.
                        </p>
                    )}
                    <div className="flex items-center justify-between">
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            disabled={page === 1}
                            onClick={() =>
                                setPage((value) => Math.max(1, value - 1))
                            }
                        >
                            Previous files
                        </Button>
                        <span className="text-xs text-muted-foreground">
                            Page {page}
                        </span>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            disabled={!available.next_page}
                            onClick={() => {
                                if (available.next_page)
                                    setPage(available.next_page);
                            }}
                        >
                            More files
                        </Button>
                    </div>
                </>
            )}
        </section>
    );
}
