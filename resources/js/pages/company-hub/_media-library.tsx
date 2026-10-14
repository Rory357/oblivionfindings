import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { FileDropzone } from '@/components/ui/file-dropzone';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { router } from '@inertiajs/react';
import {
    Check,
    Crop,
    FileText,
    FolderPlus,
    Images,
    RefreshCw,
    Search,
    Upload,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { hubRequest } from './_api';

export type HubMedia = {
    id: number;
    name: string;
    mime: string;
    size: number;
    url: string;
    hub_page_id: number;
    page_title: string;
    alt: string;
    album_id: number | null;
    version: number;
    crop: { ratio: string; x: number; y: number } | null;
};
type Album = { id: number; name: string };
type Library = {
    media: HubMedia[];
    albums: Album[];
    pages: { id: number; title: string }[];
    total: number;
    next_page: number | null;
};
const empty: Library = {
    media: [],
    albums: [],
    pages: [],
    total: 0,
    next_page: null,
};
const message = (e: unknown) =>
    e instanceof Error
        ? e.message
        : 'The media library could not be updated. Try again.';

export function MediaLibrary({
    pageId,
    file = true,
    selectedId,
    onSelect,
    onDirtyChange,
}: {
    pageId?: number;
    file?: boolean;
    selectedId?: number | null;
    onSelect?: (media: HubMedia) => void;
    onDirtyChange?: (dirty: boolean) => void;
}) {
    const [data, setData] = useState<Library>(empty);
    const [query, setQuery] = useState(''),
        [search, setSearch] = useState('');
    const [album, setAlbum] = useState(''),
        [scope, setScope] = useState('all');
    const [loadKey, setLoadKey] = useState(0),
        [loading, setLoading] = useState(true);
    const [busy, setBusy] = useState(false),
        [error, setError] = useState(''),
        [notice, setNotice] = useState('');
    const [details, setDetails] = useState<HubMedia | null>(null),
        [dirty, setDirty] = useState(false);
    const [albumDialog, setAlbumDialog] = useState(false),
        [albumName, setAlbumName] = useState('');
    const [uploadDialog, setUploadDialog] = useState(false),
        [uploadPage, setUploadPage] = useState(String(pageId ?? ''));
    const [uploadError, setUploadError] = useState('');
    const requestId = useRef(0),
        alive = useRef(true);
    const current = useRef({ pageId, onSelect, dirty, busy });
    current.current = { pageId, onSelect, dirty, busy };
    const base = pageId
        ? `/company-hub/manage/${pageId}/media`
        : '/company-hub/editor/media';
    const params = new URLSearchParams({
        library: scope,
        q: search,
        type: file ? 'all' : 'image',
        ...(album ? { album_id: album } : {}),
    });
    const url = `${base}?${params}`;
    useEffect(() => {
        const timer = setTimeout(() => setSearch(query.trim()), 250);
        return () => clearTimeout(timer);
    }, [query]);
    useEffect(() => {
        alive.current = true;
        return () => {
            alive.current = false;
        };
    }, []);
    useEffect(() => {
        const id = ++requestId.current;
        let cancelled = false;
        setLoading(true);
        setError('');
        hubRequest<Library>(url, 'GET')
            .then((result) => {
                if (!cancelled && id === requestId.current) setData(result);
            })
            .catch(
                (e) =>
                    !cancelled &&
                    id === requestId.current &&
                    setError(message(e)),
            )
            .finally(
                () =>
                    !cancelled && id === requestId.current && setLoading(false),
            );
        return () => {
            cancelled = true;
        };
    }, [url, loadKey]);
    useEffect(() => {
        onDirtyChange?.(dirty || busy);
        return () => onDirtyChange?.(false);
    }, [dirty, busy, onDirtyChange]);
    useEffect(() => {
        const warn = (event: BeforeUnloadEvent) => {
            if (current.current.dirty || current.current.busy)
                event.preventDefault();
        };
        const unsubscribe = router.on('before', (event) => {
            if (current.current.dirty || current.current.busy) {
                event.preventDefault();
                setError('Save or discard your file changes before leaving.');
            }
        });
        window.addEventListener('beforeunload', warn);
        return () => {
            unsubscribe();
            window.removeEventListener('beforeunload', warn);
        };
    }, []);
    const refresh = () => setLoadKey((key) => key + 1);
    const choose = async (item: HubMedia) => {
        if (!pageId || !onSelect) return;
        setBusy(true);
        setError('');
        try {
            const result = await hubRequest<HubMedia>(
                `/company-hub/manage/${pageId}/media/reuse`,
                'POST',
                { media_id: item.id, version: item.version },
            );
            if (alive.current && current.current.pageId === pageId)
                current.current.onSelect?.(result);
        } catch (e) {
            if (alive.current) setError(message(e));
        } finally {
            if (alive.current) setBusy(false);
        }
    };
    const closeDetails = () => {
        setDetails(null);
        setDirty(false);
        setError('');
    };
    return (
        <div className="hub-media-workspace">
            <div className="flex items-start justify-between gap-4">
                <div>
                    <h3 className="flex items-center gap-2 text-lg font-semibold">
                        <Images className="size-5 text-primary" /> Media library
                    </h3>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Images and files from pages you can edit. Organise once,
                        reuse across the hub.
                    </p>
                </div>
                <div className="flex shrink-0 gap-2">
                    <Button
                        variant="outline"
                        onClick={() => {
                            setError('');
                            setAlbumName('');
                            setAlbumDialog(true);
                        }}
                    >
                        <FolderPlus className="size-4" /> New album
                    </Button>
                    <Button
                        disabled={!data.pages.length || loading}
                        onClick={() => {
                            setError('');
                            setUploadPage(
                                String(pageId ?? data.pages[0]?.id ?? ''),
                            );
                            setUploadDialog(true);
                            setUploadError('');
                        }}
                    >
                        <Upload className="size-4" /> Upload
                    </Button>
                </div>
            </div>
            <div className="hub-media-tools">
                <label className="hub-library-search">
                    <Search className="size-4" />
                    <Input
                        aria-label="Search media"
                        placeholder="Search file names or image descriptions…"
                        value={query}
                        onChange={(e) => setQuery(e.target.value)}
                    />
                </label>
                {pageId && (
                    <select
                        className="hub-native-select"
                        aria-label="Media library scope"
                        value={scope}
                        onChange={(e) => setScope(e.target.value)}
                    >
                        <option value="all">All pages I can edit</option>
                        <option value="page">This page</option>
                    </select>
                )}
                <Button
                    variant="ghost"
                    size="icon"
                    disabled={loading}
                    aria-label="Refresh media library"
                    onClick={refresh}
                >
                    <RefreshCw className="size-4" />
                </Button>
            </div>
            {error && (
                <p role="alert" className="text-sm text-destructive">
                    {error}
                </p>
            )}
            {notice && (
                <p role="status" className="text-sm text-primary">
                    {notice}
                </p>
            )}
            <div className="hub-media-body">
                <aside className="hub-media-albums" aria-label="Media albums">
                    <p className="text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                        Collections
                    </p>
                    <Button
                        variant={!album ? 'secondary' : 'ghost'}
                        aria-pressed={!album}
                        onClick={() => setAlbum('')}
                    >
                        All media
                    </Button>
                    {data.albums.map((a) => (
                        <Button
                            key={a.id}
                            title={a.name}
                            variant={
                                album === String(a.id) ? 'secondary' : 'ghost'
                            }
                            aria-pressed={album === String(a.id)}
                            onClick={() => setAlbum(String(a.id))}
                        >
                            <span className="truncate">{a.name}</span>
                        </Button>
                    ))}
                    <p className="mt-3 text-xs leading-relaxed text-muted-foreground">
                        Albums organise files. Each file keeps its page’s access
                        rules.
                    </p>
                </aside>
                <div className="min-w-0 space-y-4" aria-busy={loading}>
                    <p role="status" className="text-xs text-muted-foreground">
                        {loading
                            ? 'Loading your media…'
                            : `${data.total} ${data.total === 1 ? 'file' : 'files'}`}
                    </p>
                    {!loading && (
                        <div className="hub-media-grid">
                            {data.media.map((item) => (
                                <article
                                    className="hub-media-card"
                                    key={item.id}
                                >
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        className="hub-media-thumbnail h-auto rounded-none p-0"
                                        aria-label={`Edit ${item.name}`}
                                        onClick={() => {
                                            setError('');
                                            setDetails(item);
                                        }}
                                    >
                                        {item.mime.startsWith('image/') ? (
                                            <img
                                                src={item.url}
                                                alt={item.alt || ''}
                                                loading="lazy"
                                            />
                                        ) : (
                                            <FileText className="size-10 text-muted-foreground" />
                                        )}
                                        {selectedId === item.id && (
                                            <span className="hub-media-selected">
                                                <Check className="size-3" />{' '}
                                                Selected
                                            </span>
                                        )}
                                    </Button>
                                    <div className="space-y-2 p-3">
                                        <p
                                            title={item.name}
                                            className="truncate text-sm font-medium"
                                        >
                                            {item.name}
                                        </p>
                                        <p
                                            title={item.page_title}
                                            className="truncate text-xs text-muted-foreground"
                                        >
                                            {item.page_title} ·{' '}
                                            {Math.max(
                                                1,
                                                Math.round(item.size / 1024),
                                            )}{' '}
                                            KB
                                        </p>
                                        <div className="flex gap-1">
                                            <Button
                                                className="flex-1"
                                                size="sm"
                                                variant="ghost"
                                                onClick={() => {
                                                    setError('');
                                                    setDetails(item);
                                                }}
                                                aria-label={`Details for ${item.name}`}
                                            >
                                                Details
                                            </Button>
                                            {onSelect && (
                                                <Button
                                                    size="sm"
                                                    variant="secondary"
                                                    disabled={busy}
                                                    onClick={() =>
                                                        void choose(item)
                                                    }
                                                    aria-label={`Use ${item.name}`}
                                                >
                                                    {item.mime.startsWith(
                                                        'image/',
                                                    )
                                                        ? 'Use image'
                                                        : 'Use file'}
                                                </Button>
                                            )}
                                        </div>
                                    </div>
                                </article>
                            ))}
                        </div>
                    )}
                    {!loading && !data.media.length && (
                        <div className="hub-media-empty">
                            <Images className="size-9 text-primary/60" />
                            <h4 className="font-medium">
                                {search || album
                                    ? 'No matching files'
                                    : 'A home for your company’s images'}
                            </h4>
                            <p className="text-sm text-muted-foreground">
                                {search || album
                                    ? 'Try another search or collection, or add a file here.'
                                    : 'Upload a photograph, illustration or document to get started.'}
                            </p>
                        </div>
                    )}
                    {!loading && data.next_page && (
                        <Button
                            variant="outline"
                            disabled={busy}
                            onClick={async () => {
                                const id = requestId.current;
                                setBusy(true);
                                setError('');
                                try {
                                    const more = await hubRequest<Library>(
                                        `${url}&cursor_page=${data.next_page}`,
                                        'GET',
                                    );
                                    if (id === requestId.current)
                                        setData((prev) => ({
                                            ...more,
                                            media: [
                                                ...prev.media,
                                                ...more.media,
                                            ],
                                        }));
                                } catch (e) {
                                    if (id === requestId.current)
                                        setError(message(e));
                                } finally {
                                    if (alive.current) setBusy(false);
                                }
                            }}
                        >
                            Load more files
                        </Button>
                    )}
                </div>
            </div>
            <Dialog
                open={!!details}
                onOpenChange={(open) => {
                    if (!open && !busy && !dirty) closeDetails();
                }}
            >
                {details && (
                    <MediaDetails
                        key={details.id}
                        media={details}
                        albums={data.albums}
                        busy={busy}
                        setBusy={setBusy}
                        onDirty={setDirty}
                        onClose={closeDetails}
                        onSaved={(item, copy) => {
                            setDetails(item);
                            setDirty(false);
                            setNotice(
                                copy
                                    ? 'New copy saved. The original is unchanged.'
                                    : 'File details saved.',
                            );
                            refresh();
                        }}
                    />
                )}
            </Dialog>
            <Dialog
                open={albumDialog}
                onOpenChange={(open) => !busy && setAlbumDialog(open)}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>New album</DialogTitle>
                        <DialogDescription>
                            Group related images for easy reuse. Files keep
                            their existing page permissions.
                        </DialogDescription>
                    </DialogHeader>
                    <Label htmlFor="hub-album-name">Album name</Label>
                    <Input
                        id="hub-album-name"
                        autoFocus
                        maxLength={80}
                        value={albumName}
                        onChange={(e) => setAlbumName(e.target.value)}
                    />
                    {error && (
                        <p role="alert" className="text-sm text-destructive">
                            {error}
                        </p>
                    )}
                    <Button
                        disabled={busy || !albumName.trim()}
                        onClick={async () => {
                            setBusy(true);
                            setError('');
                            try {
                                const a = await hubRequest<Album>(
                                    '/company-hub/editor/media/albums',
                                    'POST',
                                    { name: albumName.trim() },
                                );
                                setAlbum(String(a.id));
                                setAlbumDialog(false);
                                setNotice(
                                    'Album created. Open a file’s details to add it.',
                                );
                                refresh();
                            } catch (e) {
                                setError(message(e));
                            } finally {
                                setBusy(false);
                            }
                        }}
                    >
                        {busy ? 'Creating…' : 'Create album'}
                    </Button>
                </DialogContent>
            </Dialog>
            <Dialog
                open={uploadDialog}
                onOpenChange={(open) => !busy && setUploadDialog(open)}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Upload to the hub</DialogTitle>
                        <DialogDescription>
                            Choose the page that owns this file. It can be
                            reused on other pages you can edit.
                        </DialogDescription>
                    </DialogHeader>
                    <Label htmlFor="hub-upload-page">Page</Label>
                    <select
                        id="hub-upload-page"
                        className="hub-native-select w-full"
                        value={uploadPage}
                        disabled={busy || !!pageId}
                        onChange={(e) => setUploadPage(e.target.value)}
                    >
                        {data.pages.map((p) => (
                            <option value={p.id} key={p.id}>
                                {p.title}
                            </option>
                        ))}
                    </select>
                    <FileDropzone
                        disabled={busy || !uploadPage}
                        accept={
                            file
                                ? 'application/pdf,image/jpeg,image/png,image/webp,image/gif'
                                : 'image/jpeg,image/png,image/webp,image/gif'
                        }
                        hint="Up to 6 files at once · 10 MB per file"
                        onFiles={async (files) => {
                            if (!files[0]) return;
                            if (files.length > 6) {
                                setUploadError(
                                    'Choose up to 6 files at a time.',
                                );
                                return;
                            }
                            setBusy(true);
                            setError('');
                            const uploaded: HubMedia[] = [];
                            setUploadError('');
                            const failures: string[] = [];
                            for (const file of files) {
                                if (file.size > 10 * 1024 * 1024) {
                                    failures.push(
                                        `${file.name}: larger than 10 MB.`,
                                    );
                                    continue;
                                }
                                const form = new FormData();
                                form.append('file', file);
                                try {
                                    let item = await hubRequest<HubMedia>(
                                        `/company-hub/manage/${uploadPage}/media`,
                                        'POST',
                                        form,
                                    );
                                    if (album) {
                                        try {
                                            item = await hubRequest<HubMedia>(
                                                `/company-hub/manage/${uploadPage}/media/${item.id}`,
                                                'PATCH',
                                                {
                                                    name: item.name,
                                                    album_id: Number(album),
                                                    version: item.version,
                                                },
                                            );
                                        } catch {
                                            setNotice(
                                                'File uploaded. Choose its album in file details.',
                                            );
                                        }
                                    }
                                    uploaded.push(item);
                                } catch (e) {
                                    failures.push(
                                        `${file.name}: ${message(e)}`,
                                    );
                                }
                            }
                            setBusy(false);
                            if (uploaded.length) {
                                refresh();
                                setNotice(
                                    `${uploaded.length} ${uploaded.length === 1 ? 'file uploaded' : 'files uploaded'}. Open file details to add image descriptions.`,
                                );
                            }
                            if (failures.length) {
                                setUploadError(failures.join(' '));
                            } else {
                                setUploadDialog(false);
                                if (uploaded.length === 1)
                                    setDetails(uploaded[0]);
                            }
                        }}
                    />
                    {busy && <p role="status">Uploading…</p>}
                    {uploadError && (
                        <p role="alert" className="text-sm text-destructive">
                            {uploadError}
                        </p>
                    )}
                </DialogContent>
            </Dialog>
        </div>
    );
}

function MediaDetails({
    media,
    albums,
    busy,
    setBusy,
    onDirty,
    onClose,
    onSaved,
}: {
    media: HubMedia;
    albums: Album[];
    busy: boolean;
    setBusy: (busy: boolean) => void;
    onDirty: (dirty: boolean) => void;
    onClose: () => void;
    onSaved: (media: HubMedia, copy: boolean) => void;
}) {
    const [name, setName] = useState(media.name),
        [alt, setAlt] = useState(media.alt || ''),
        [album, setAlbum] = useState(String(media.album_id ?? ''));
    const [crop, setCrop] = useState(false),
        [ratio, setRatio] = useState('16:9'),
        [x, setX] = useState(50),
        [y, setY] = useState(50);
    const [error, setError] = useState('');
    const changed =
        name !== media.name ||
        alt !== (media.alt || '') ||
        album !== String(media.album_id ?? '');
    const image = media.mime.startsWith('image/');
    useEffect(() => {
        onDirty(changed || crop);
        return () => onDirty(false);
    }, [changed, crop, onDirty]);
    const endpoint = `/company-hub/manage/${media.hub_page_id}/media/${media.id}`;
    return (
        <DialogContent
            className="hub-media-details-dialog"
            onEscapeKeyDown={(event) => {
                if (busy || changed || crop) event.preventDefault();
            }}
            onInteractOutside={(event) => {
                if (busy || changed || crop) event.preventDefault();
            }}
        >
            <DialogHeader>
                <DialogTitle>
                    {crop ? 'Create an image crop' : 'File details'}
                </DialogTitle>
                <DialogDescription>
                    {media.page_title} ·{' '}
                    {Math.max(1, Math.round(media.size / 1024))} KB
                </DialogDescription>
            </DialogHeader>
            <div className="hub-media-details-body">
                <div className="hub-media-image-stage">
                    {image ? (
                        <div
                            className={
                                crop
                                    ? 'hub-media-crop-preview'
                                    : 'hub-media-original-preview'
                            }
                            style={
                                crop
                                    ? { aspectRatio: ratio.replace(':', ' / ') }
                                    : undefined
                            }
                        >
                            <img
                                src={media.url}
                                alt={alt}
                                style={
                                    crop
                                        ? { objectPosition: `${x}% ${y}%` }
                                        : undefined
                                }
                            />
                            {crop && (
                                <span
                                    className="hub-media-crop-guides"
                                    aria-hidden="true"
                                />
                            )}
                        </div>
                    ) : (
                        <FileText className="size-16 text-muted-foreground" />
                    )}
                    <p className="text-xs text-muted-foreground">
                        {crop
                            ? 'Preview of the new image. Your original stays unchanged.'
                            : 'File preview · protected by its page’s access rules'}
                    </p>
                </div>
                <div className="space-y-4">
                    {crop ? (
                        <>
                            <div className="space-y-2">
                                <Label htmlFor="hub-crop-shape">
                                    Crop shape
                                </Label>
                                <select
                                    className="hub-native-select w-full"
                                    id="hub-crop-shape"
                                    value={ratio}
                                    disabled={busy}
                                    onChange={(e) => setRatio(e.target.value)}
                                >
                                    <option value="16:9">Wide · 16:9</option>
                                    <option value="1:1">Square · 1:1</option>
                                    <option value="4:3">Classic · 4:3</option>
                                </select>
                            </div>
                            <label className="block space-y-2 text-sm">
                                Horizontal focus · {x}%
                                <input
                                    className="w-full accent-primary"
                                    type="range"
                                    min="0"
                                    max="100"
                                    value={x}
                                    aria-label="Horizontal crop focus"
                                    disabled={busy}
                                    onChange={(e) =>
                                        setX(Number(e.target.value))
                                    }
                                />
                            </label>
                            <label className="block space-y-2 text-sm">
                                Vertical focus · {y}%
                                <input
                                    className="w-full accent-primary"
                                    type="range"
                                    min="0"
                                    max="100"
                                    value={y}
                                    aria-label="Vertical crop focus"
                                    disabled={busy}
                                    onChange={(e) =>
                                        setY(Number(e.target.value))
                                    }
                                />
                            </label>
                            <p className="text-xs leading-relaxed text-muted-foreground">
                                Saved as a new PNG, up to 1,600 pixels wide.
                                Animated images become a still image. Images
                                over 16 megapixels cannot be cropped here.
                            </p>
                            <div className="flex flex-wrap gap-2">
                                <Button
                                    variant="outline"
                                    disabled={busy}
                                    onClick={() => {
                                        setCrop(false);
                                        setError('');
                                    }}
                                >
                                    Cancel crop
                                </Button>
                                <Button
                                    disabled={busy}
                                    onClick={async () => {
                                        setBusy(true);
                                        setError('');
                                        try {
                                            const result =
                                                await hubRequest<HubMedia>(
                                                    `${endpoint}/crop`,
                                                    'POST',
                                                    {
                                                        version: media.version,
                                                        ratio,
                                                        x,
                                                        y,
                                                    },
                                                );
                                            onSaved(result, true);
                                        } catch (e) {
                                            setError(message(e));
                                        } finally {
                                            setBusy(false);
                                        }
                                    }}
                                >
                                    <Crop className="size-4" />
                                    {busy ? 'Saving…' : 'Save crop as copy'}
                                </Button>
                            </div>
                        </>
                    ) : (
                        <>
                            <div className="space-y-2">
                                <Label htmlFor="hub-file-name">File name</Label>
                                <Input
                                    id="hub-file-name"
                                    maxLength={160}
                                    value={name}
                                    disabled={busy}
                                    onChange={(e) => setName(e.target.value)}
                                />
                            </div>
                            {image && (
                                <div className="space-y-2">
                                    <Label htmlFor="hub-file-alt">
                                        Image description
                                    </Label>
                                    <Textarea
                                        id="hub-file-alt"
                                        maxLength={300}
                                        rows={3}
                                        value={alt}
                                        disabled={busy}
                                        onChange={(e) => setAlt(e.target.value)}
                                        placeholder="Briefly describe what the image shows…"
                                    />
                                    <p className="text-xs text-muted-foreground">
                                        Used for screen readers when this image
                                        is added to a page. Published page
                                        descriptions stay unchanged until that
                                        page is edited.
                                    </p>
                                </div>
                            )}
                            <div className="space-y-2">
                                <Label htmlFor="hub-file-album">Album</Label>
                                <select
                                    id="hub-file-album"
                                    className="hub-native-select w-full"
                                    value={album}
                                    disabled={busy}
                                    onChange={(e) => setAlbum(e.target.value)}
                                >
                                    <option value="">No album</option>
                                    {albums.map((a) => (
                                        <option key={a.id} value={a.id}>
                                            {a.name}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <div className="flex flex-wrap gap-2">
                                <Button
                                    disabled={busy || !changed || !name.trim()}
                                    onClick={async () => {
                                        setBusy(true);
                                        setError('');
                                        try {
                                            const result =
                                                await hubRequest<HubMedia>(
                                                    endpoint,
                                                    'PATCH',
                                                    {
                                                        name: name.trim(),
                                                        alt,
                                                        album_id: album
                                                            ? Number(album)
                                                            : null,
                                                        version: media.version,
                                                    },
                                                );
                                            setName(result.name);
                                            setAlt(result.alt || '');
                                            onSaved(result, false);
                                        } catch (e) {
                                            setError(message(e));
                                        } finally {
                                            setBusy(false);
                                        }
                                    }}
                                >
                                    {busy ? 'Saving…' : 'Save details'}
                                </Button>
                                {image && (
                                    <Button
                                        variant="outline"
                                        disabled={busy || changed}
                                        onClick={() => setCrop(true)}
                                    >
                                        <Crop className="size-4" /> Crop image
                                    </Button>
                                )}
                                <Button
                                    variant="outline"
                                    disabled={busy || changed}
                                    onClick={async () => {
                                        setBusy(true);
                                        setError('');
                                        try {
                                            const result =
                                                await hubRequest<HubMedia>(
                                                    `/company-hub/manage/${media.hub_page_id}/media/reuse`,
                                                    'POST',
                                                    {
                                                        media_id: media.id,
                                                        version: media.version,
                                                        copy: true,
                                                    },
                                                );
                                            onSaved(result, true);
                                        } catch (e) {
                                            setError(message(e));
                                        } finally {
                                            setBusy(false);
                                        }
                                    }}
                                >
                                    Create a copy
                                </Button>
                            </div>
                            {changed && (
                                <p className="text-xs text-muted-foreground">
                                    Save these details before creating a crop.
                                </p>
                            )}
                        </>
                    )}
                    {error && (
                        <p role="alert" className="text-sm text-destructive">
                            {error} You can discard changes, then refresh the
                            library to load the latest version.
                        </p>
                    )}
                    <Button variant="ghost" disabled={busy} onClick={onClose}>
                        {changed || crop
                            ? 'Discard changes and close'
                            : 'Close details'}
                    </Button>
                </div>
            </div>
        </DialogContent>
    );
}
