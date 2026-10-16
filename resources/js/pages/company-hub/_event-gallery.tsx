import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { formatDateLong } from '@/lib/datetime';
import { Link } from '@inertiajs/react';
import { ArrowLeft, ArrowRight, Info, X } from 'lucide-react';
import { useState } from 'react';
import type { HubEventPhoto } from './_types';

export function EventGallery({
    photos,
    title,
}: {
    photos: HubEventPhoto[];
    title: string;
}) {
    const [album, setAlbum] = useState('');
    const [photoId, setPhotoId] = useState<number | null>(null);
    const [details, setDetails] = useState(false);
    const albums = [
        ...new Set(
            photos
                .map((photo) => photo.album)
                .filter((value): value is string => !!value),
        ),
    ];
    const activeAlbum = albums.includes(album) ? album : '';
    const gallery = activeAlbum
        ? photos.filter((photo) => photo.album === activeAlbum)
        : photos;
    const index = gallery.findIndex((photo) => photo.id === photoId);
    const current = gallery[index];
    const photoTitle = (photo: HubEventPhoto) =>
        photo.title || photo.name || 'Event photo';
    const move = (offset: number) => {
        if (!gallery.length || index < 0) return;
        setPhotoId(
            gallery[(index + offset + gallery.length) % gallery.length].id,
        );
    };
    if (!photos.length) return null;
    return (
        <section aria-label="Event photo gallery" className="space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <p className="text-xs text-muted-foreground">
                    {gallery.length} {gallery.length === 1 ? 'photo' : 'photos'}
                    {activeAlbum ? ` · ${activeAlbum}` : ''}
                </p>
                {albums.length > 0 && (
                    <div
                        role="group"
                        aria-label="Photo albums"
                        className="flex flex-wrap gap-1"
                    >
                        <Button
                            type="button"
                            size="sm"
                            variant={!activeAlbum ? 'secondary' : 'ghost'}
                            aria-pressed={!activeAlbum}
                            onClick={() => setAlbum('')}
                        >
                            All photos
                        </Button>
                        {albums.map((name) => (
                            <Button
                                key={name}
                                type="button"
                                size="sm"
                                variant={
                                    activeAlbum === name ? 'secondary' : 'ghost'
                                }
                                aria-pressed={activeAlbum === name}
                                onClick={() => setAlbum(name)}
                            >
                                {name}
                            </Button>
                        ))}
                    </div>
                )}
            </div>
            <div className="grid grid-cols-3 gap-4">
                {gallery.map((photo, position) => (
                    <Button
                        key={photo.id}
                        type="button"
                        variant="ghost"
                        className="group h-auto flex-col items-stretch gap-0 overflow-hidden rounded-xl border bg-card p-0 text-left whitespace-normal focus-visible:ring-2 focus-visible:ring-offset-4"
                        aria-label={`View event photo ${position + 1} of ${gallery.length}: ${photoTitle(photo)}`}
                        onClick={() => {
                            setPhotoId(photo.id);
                            setDetails(false);
                        }}
                    >
                        <span className="overflow-hidden">
                            <img
                                src={photo.url}
                                alt={photo.alt || ''}
                                className="aspect-[4/3] w-full object-cover transition-transform duration-300 motion-safe:group-hover:scale-105"
                                loading="lazy"
                            />
                        </span>
                        <span className="space-y-1 p-4">
                            <strong className="block text-sm font-semibold">
                                {photoTitle(photo)}
                            </strong>
                            {photo.caption && (
                                <span className="line-clamp-2 block text-xs leading-relaxed font-normal text-muted-foreground">
                                    {photo.caption}
                                </span>
                            )}
                        </span>
                    </Button>
                ))}
            </div>
            <Dialog
                open={!!current}
                onOpenChange={(open) => {
                    if (!open) setPhotoId(null);
                }}
            >
                <DialogContent
                    style={{
                        width: 'min(92vw, 1040px)',
                        maxWidth: 'min(92vw, 1040px)',
                    }}
                    className="max-h-[92vh] overflow-y-auto p-0"
                    onKeyDown={(e) => {
                        if (e.key === 'ArrowLeft' || e.key === 'ArrowRight') {
                            e.preventDefault();
                            move(e.key === 'ArrowLeft' ? -1 : 1);
                        }
                    }}
                >
                    {current && (
                        <>
                            <DialogHeader className="px-7 pt-6 pr-14">
                                <DialogTitle>{photoTitle(current)}</DialogTitle>
                                <DialogDescription>
                                    {title} · Photo {index + 1} of{' '}
                                    {gallery.length}
                                </DialogDescription>
                            </DialogHeader>
                            <div className="mx-7 overflow-hidden rounded-xl bg-muted/40">
                                <img
                                    src={current.url}
                                    alt={
                                        current.alt ||
                                        current.title ||
                                        `Event photo ${index + 1}`
                                    }
                                    className="max-h-[52vh] min-h-48 w-full object-contain"
                                />
                            </div>
                            <div className="space-y-4 px-7">
                                <div className="flex items-start justify-between gap-6">
                                    <div className="space-y-2">
                                        {current.caption && (
                                            <p className="text-sm leading-relaxed whitespace-pre-wrap">
                                                {current.caption}
                                            </p>
                                        )}
                                        {(current.shared_by ||
                                            current.shared_at) && (
                                            <p className="text-xs text-muted-foreground">
                                                {current.shared_by && (
                                                    <>
                                                        Photo shared by{' '}
                                                        {current.shared_by
                                                            .profile_url ? (
                                                            <Link
                                                                className="text-primary underline-offset-4 hover:underline"
                                                                href={
                                                                    current
                                                                        .shared_by
                                                                        .profile_url
                                                                }
                                                            >
                                                                {
                                                                    current
                                                                        .shared_by
                                                                        .name
                                                                }
                                                            </Link>
                                                        ) : (
                                                            current.shared_by
                                                                .name
                                                        )}
                                                    </>
                                                )}
                                                {current.shared_at && (
                                                    <>
                                                        {current.shared_by
                                                            ? ' · '
                                                            : 'Shared '}
                                                        {formatDateLong(
                                                            current.shared_at,
                                                        )}
                                                    </>
                                                )}
                                            </p>
                                        )}
                                    </div>
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="ghost"
                                        aria-expanded={details}
                                        onClick={() => setDetails(!details)}
                                    >
                                        <Info className="size-4" />
                                        Photo details
                                    </Button>
                                </div>
                                {details && (
                                    <dl
                                        aria-label="Photo details"
                                        className="grid grid-cols-[100px_1fr] gap-x-5 gap-y-2 rounded-lg border bg-muted/20 p-4 text-sm"
                                    >
                                        <dt className="text-muted-foreground">
                                            Alt text
                                        </dt>
                                        <dd className="break-words">
                                            {current.alt ||
                                                'No description added.'}
                                        </dd>
                                        <dt className="text-muted-foreground">
                                            Album
                                        </dt>
                                        <dd>
                                            {current.album ||
                                                'No album selected.'}
                                        </dd>
                                        <dt className="text-muted-foreground">
                                            File
                                        </dt>
                                        <dd className="break-words">
                                            {current.name}
                                        </dd>
                                    </dl>
                                )}
                            </div>
                            <div className="flex items-center justify-between border-t bg-muted/20 px-7 py-4">
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => move(-1)}
                                    disabled={gallery.length < 2}
                                >
                                    <ArrowLeft className="size-4" />
                                    Previous photo
                                </Button>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    onClick={() => setPhotoId(null)}
                                >
                                    <X className="size-4" />
                                    Back to recap
                                </Button>
                                <Button
                                    type="button"
                                    onClick={() => move(1)}
                                    disabled={gallery.length < 2}
                                >
                                    Next photo
                                    <ArrowRight className="size-4" />
                                </Button>
                            </div>
                        </>
                    )}
                </DialogContent>
            </Dialog>
        </section>
    );
}
