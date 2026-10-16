import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { HubSelect } from '@/pages/company-hub/_select';
import { ArrowDown, ArrowUp, FileText, ImagePlus, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import {
    EventImageLibrary,
    type EventLibraryPhoto,
} from './event-image-library';
import type { EventAttachment } from './event-wizard-dialog';

export type EventHubContentInput = {
    host_id: number | null;
    agenda: string;
    recap: string;
    story_id: number | null;
    cover_ref: string;
    cover_alt: string;
    gallery_refs: string[];
    recap_title?: string;
    photo_details?: EventPhotoDetails[];
};
export type EventPhotoDetails = {
    ref: string;
    title: string;
    alt: string;
    caption: string;
    album: string;
};
export const emptyEventHubContent = (): EventHubContentInput => ({
    host_id: null,
    agenda: '',
    recap: '',
    story_id: null,
    cover_ref: '',
    cover_alt: '',
    gallery_refs: [],
    recap_title: '',
    photo_details: [],
});

export function removePhotoReference(
    value: EventHubContentInput,
    removed: string,
): EventHubContentInput {
    const map = (ref: string) => {
        if (ref === removed) return '';
        if (ref.startsWith('upload:') && removed.startsWith('upload:')) {
            const index = Number(ref.slice(7));
            const deleted = Number(removed.slice(7));
            return index > deleted ? `upload:${index - 1}` : ref;
        }
        return ref;
    };
    return {
        ...value,
        cover_ref: map(value.cover_ref),
        gallery_refs: value.gallery_refs.map(map).filter(Boolean),
        ...(value.photo_details
            ? {
                  photo_details: value.photo_details.flatMap((photo) => {
                      const ref = map(photo.ref);
                      return ref ? [{ ...photo, ref }] : [];
                  }),
              }
            : {}),
    };
}

export function EventHubFields({
    value,
    onChange,
    files,
    attachments,
    staff,
    stories,
    draftMode = false,
    mediaOnly = false,
    libraryPhotos = [],
    libraryActorId,
    onLibraryPhotosChange,
    retainedDraftFiles = 0,
}: {
    value: EventHubContentInput;
    onChange: (value: EventHubContentInput) => void;
    files: File[];
    attachments: EventAttachment[];
    staff: { value: string; label: string }[];
    stories: { id: number; title: string }[];
    draftMode?: boolean;
    mediaOnly?: boolean;
    libraryPhotos?: EventLibraryPhoto[];
    libraryActorId?: number;
    onLibraryPhotosChange?: (photos: EventLibraryPhoto[]) => void;
    retainedDraftFiles?: number;
}) {
    const [libraryOpen, setLibraryOpen] = useState(false);
    const [selectedPhoto, setSelectedPhoto] = useState<string | null>(null);
    const [selectedFile, setSelectedFile] = useState<File | null>(null);
    const selectPhoto = (ref: string | null) => {
        setSelectedPhoto(ref);
        setSelectedFile(
            ref?.startsWith('upload:')
                ? (files[Number(ref.slice(7))] ?? null)
                : null,
        );
    };
    const [previews, setPreviews] = useState<
        { file: File; name: string; url: string }[]
    >([]);
    useEffect(() => {
        const images = files.flatMap((file) =>
            file.type.startsWith('image/')
                ? [
                      {
                          file,
                          name: file.name,
                          url: URL.createObjectURL(file),
                      },
                  ]
                : [],
        );
        setPreviews(images);
        return () => images.forEach((image) => URL.revokeObjectURL(image.url));
    }, [files]);
    const photos = [
        ...attachments
            .filter((file) => file.mime?.startsWith('image/'))
            .map((file) => ({
                ref: `file:${file.id}`,
                name: file.name,
                url: file.url,
            })),
        ...previews.flatMap((photo) => {
            const index = files.indexOf(photo.file);
            return index < 0 ? [] : [{ ...photo, ref: `upload:${index}` }];
        }),
        ...libraryPhotos
            .filter((photo) => photo.mime.startsWith('image/'))
            .map((photo) => ({
                ref: `library:${photo.id}`,
                name: photo.name,
                url: photo.url,
            })),
    ];
    const selectedRef = selectedFile
        ? files.includes(selectedFile)
            ? `upload:${files.indexOf(selectedFile)}`
            : null
        : selectedPhoto;
    const selected = photos.find((photo) => photo.ref === selectedRef);
    const details = (ref: string): EventPhotoDetails =>
        value.photo_details?.find((photo) => photo.ref === ref) ?? {
            ref,
            title: '',
            alt: '',
            caption: '',
            album: '',
        };
    const updateDetails = (
        ref: string,
        key: keyof Omit<EventPhotoDetails, 'ref'>,
        text: string,
    ) =>
        onChange({
            ...value,
            photo_details: [
                ...(value.photo_details ?? []).filter(
                    (photo) => photo.ref !== ref,
                ),
                { ...details(ref), [key]: text },
            ],
        });
    const movePhoto = (ref: string, direction: number) => {
        const from = value.gallery_refs.indexOf(ref),
            to = from + direction;
        if (from < 0 || to < 0 || to >= value.gallery_refs.length) return;
        const gallery_refs = [...value.gallery_refs];
        [gallery_refs[from], gallery_refs[to]] = [
            gallery_refs[to],
            gallery_refs[from],
        ];
        onChange({ ...value, gallery_refs });
    };
    return (
        <section
            className="mt-7 space-y-5 border-t pt-6"
            aria-label="Company Hub event details"
        >
            {!mediaOnly && (
                <div>
                    <h3 className="text-base font-semibold">
                        Bring the event to life
                    </h3>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Add a host, a useful agenda and a photo for the event
                        board.
                    </p>
                </div>
            )}
            {!mediaOnly && (
                <div className="space-y-2">
                    <Label htmlFor="event-host">Event host</Label>
                    <HubSelect
                        id="event-host"
                        label="Event host"
                        value={
                            value.host_id == null ? '' : String(value.host_id)
                        }
                        onChange={(host) =>
                            onChange({
                                ...value,
                                host_id: host ? Number(host) : null,
                            })
                        }
                        options={[
                            { value: '', label: 'Use the organiser' },
                            ...staff.map((person) => ({
                                value: String(person.value),
                                label: person.label,
                            })),
                        ]}
                    />
                </div>
            )}
            <div className="space-y-2">
                <Label htmlFor="event-agenda">What’s on the agenda?</Label>
                <Textarea
                    id="event-agenda"
                    rows={3}
                    maxLength={6000}
                    placeholder="One agenda item per line"
                    value={value.agenda}
                    onChange={(e) =>
                        onChange({ ...value, agenda: e.target.value })
                    }
                />
            </div>
            <fieldset className="space-y-3">
                <legend className="text-sm font-medium">
                    Attachments, cover & photos
                </legend>
                <p className="text-xs text-muted-foreground">
                    Reuse files from the company library or attach them above.
                    Choose an image as a cover and up to 12 photos for the recap
                    gallery.
                </p>
                {libraryActorId && onLibraryPhotosChange && (
                    <div className="space-y-4">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setLibraryOpen(!libraryOpen)}
                            aria-expanded={libraryOpen}
                        >
                            <ImagePlus className="size-4" />
                            {libraryOpen
                                ? 'Close company library'
                                : 'Choose from company library'}
                        </Button>
                        {libraryOpen && (
                            <EventImageLibrary
                                actorId={libraryActorId}
                                selected={libraryPhotos}
                                atLimit={
                                    files.length +
                                        retainedDraftFiles +
                                        libraryPhotos.length >=
                                    10
                                }
                                onSelect={(photo) => {
                                    const previous = libraryPhotos.find(
                                        (item) => item.id === photo.id,
                                    );
                                    if (previous) {
                                        if (previous.version !== photo.version)
                                            onLibraryPhotosChange(
                                                libraryPhotos.map((item) =>
                                                    item.id === photo.id
                                                        ? photo
                                                        : item,
                                                ),
                                            );
                                        if (photo.mime.startsWith('image/'))
                                            selectPhoto(`library:${photo.id}`);
                                        return;
                                    }
                                    if (
                                        files.length +
                                            retainedDraftFiles +
                                            libraryPhotos.length >=
                                        10
                                    )
                                        return;
                                    onLibraryPhotosChange([
                                        ...libraryPhotos,
                                        photo,
                                    ]);
                                    if (!photo.mime.startsWith('image/'))
                                        return;
                                    const ref = `library:${photo.id}`;
                                    onChange({
                                        ...value,
                                        photo_details: [
                                            ...(value.photo_details ?? []),
                                            {
                                                ref,
                                                title: [...photo.name]
                                                    .slice(0, 160)
                                                    .join(''),
                                                alt: photo.alt,
                                                caption: photo.caption ?? '',
                                                album: photo.albumName,
                                            },
                                        ],
                                    });
                                    selectPhoto(ref);
                                }}
                            />
                        )}
                        {libraryPhotos.length > 0 && (
                            <p className="text-xs text-muted-foreground">
                                {libraryPhotos.length} library{' '}
                                {libraryPhotos.length === 1 ? 'file' : 'files'}{' '}
                                selected. A separate copy is made when you save
                                this event.
                            </p>
                        )}
                    </div>
                )}
                {libraryPhotos
                    .filter((file) => !file.mime.startsWith('image/'))
                    .map((file) => (
                        <div
                            key={file.id}
                            className="flex items-center gap-3 border-b py-2"
                        >
                            <FileText className="size-4 shrink-0 text-primary" />
                            <span className="min-w-0 flex-1 text-sm break-words">
                                {file.name}
                            </span>
                            <Button
                                type="button"
                                size="icon"
                                variant="ghost"
                                aria-label={`Remove selected library file ${file.name}`}
                                onClick={() =>
                                    onLibraryPhotosChange?.(
                                        libraryPhotos.filter(
                                            (item) => item.id !== file.id,
                                        ),
                                    )
                                }
                            >
                                <X className="size-4" />
                            </Button>
                        </div>
                    ))}
                <label className="flex items-center gap-2 text-sm">
                    <input
                        type="radio"
                        name="event-cover"
                        checked={!value.cover_ref}
                        onChange={() => onChange({ ...value, cover_ref: '' })}
                    />
                    No cover image
                </label>
                <div className="grid grid-cols-3 gap-3">
                    {photos.map((photo) => (
                        <Card
                            key={photo.ref}
                            className="gap-0 overflow-hidden rounded-lg bg-background py-0 shadow-none"
                        >
                            <img
                                className="h-24 w-full object-cover"
                                src={photo.url}
                                alt=""
                            />
                            <div className="space-y-2 p-3">
                                <p
                                    className="truncate text-xs"
                                    title={photo.name}
                                >
                                    {photo.name}
                                </p>
                                <label className="flex items-center gap-2 text-xs">
                                    <input
                                        type="radio"
                                        name="event-cover"
                                        aria-label={`Use ${photo.name} as cover`}
                                        checked={value.cover_ref === photo.ref}
                                        onChange={() =>
                                            onChange({
                                                ...value,
                                                cover_ref: photo.ref,
                                            })
                                        }
                                    />
                                    Cover
                                </label>
                                <label className="flex items-center gap-2 text-xs">
                                    <input
                                        type="checkbox"
                                        aria-label={`Include ${photo.name} in recap`}
                                        checked={value.gallery_refs.includes(
                                            photo.ref,
                                        )}
                                        disabled={
                                            !value.gallery_refs.includes(
                                                photo.ref,
                                            ) && value.gallery_refs.length >= 12
                                        }
                                        onChange={(e) =>
                                            onChange({
                                                ...value,
                                                gallery_refs: e.target.checked
                                                    ? [
                                                          ...value.gallery_refs,
                                                          photo.ref,
                                                      ]
                                                    : value.gallery_refs.filter(
                                                          (ref) =>
                                                              ref !== photo.ref,
                                                      ),
                                            })
                                        }
                                    />
                                    Recap gallery
                                </label>
                                <div className="flex flex-wrap gap-1 border-t pt-2">
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="ghost"
                                        aria-pressed={selectedRef === photo.ref}
                                        aria-label={`Edit details for ${photo.name}`}
                                        onClick={() => selectPhoto(photo.ref)}
                                    >
                                        Photo details
                                    </Button>
                                    {photo.ref.startsWith('library:') &&
                                        onLibraryPhotosChange && (
                                            <Button
                                                type="button"
                                                size="icon"
                                                variant="ghost"
                                                aria-label={`Remove selected library image ${photo.name}`}
                                                onClick={() => {
                                                    onLibraryPhotosChange(
                                                        libraryPhotos.filter(
                                                            (item) =>
                                                                `library:${item.id}` !==
                                                                photo.ref,
                                                        ),
                                                    );
                                                    onChange(
                                                        removePhotoReference(
                                                            value,
                                                            photo.ref,
                                                        ),
                                                    );
                                                    if (
                                                        selectedRef ===
                                                        photo.ref
                                                    )
                                                        selectPhoto(null);
                                                }}
                                            >
                                                <X className="size-4" />
                                            </Button>
                                        )}
                                </div>
                            </div>
                        </Card>
                    ))}
                </div>
                {selected && (
                    <section
                        className="space-y-4 rounded-xl border bg-muted/20 p-5"
                        aria-label={`Photo details for ${selected.name}`}
                    >
                        <div className="flex items-center justify-between gap-4">
                            <div>
                                <h4 className="font-semibold">
                                    Give this moment some context
                                </h4>
                                <p className="text-xs text-muted-foreground">
                                    {selected.name}
                                </p>
                            </div>
                            <Button
                                type="button"
                                size="icon"
                                variant="ghost"
                                aria-label="Close photo details"
                                onClick={() => selectPhoto(null)}
                            >
                                <X className="size-4" />
                            </Button>
                        </div>
                        <div className="grid grid-cols-2 gap-4">
                            <Label className="grid gap-2">
                                Photo title
                                <Input
                                    maxLength={160}
                                    value={details(selected.ref).title}
                                    onChange={(e) =>
                                        updateDetails(
                                            selected.ref,
                                            'title',
                                            e.target.value,
                                        )
                                    }
                                    placeholder="A moment worth sharing"
                                />
                            </Label>
                            <Label className="grid gap-2">
                                Album
                                <Input
                                    maxLength={80}
                                    value={details(selected.ref).album}
                                    onChange={(e) =>
                                        updateDetails(
                                            selected.ref,
                                            'album',
                                            e.target.value,
                                        )
                                    }
                                    placeholder="Community afternoons"
                                />
                            </Label>
                            <Label className="col-span-2 grid gap-2">
                                Alt text
                                <Input
                                    maxLength={300}
                                    value={details(selected.ref).alt}
                                    onChange={(e) =>
                                        updateDetails(
                                            selected.ref,
                                            'alt',
                                            e.target.value,
                                        )
                                    }
                                    placeholder="Describe what is happening for people using a screen reader"
                                />
                            </Label>
                            <Label className="col-span-2 grid gap-2">
                                Caption
                                <Textarea
                                    rows={3}
                                    maxLength={2000}
                                    value={details(selected.ref).caption}
                                    onChange={(e) =>
                                        updateDetails(
                                            selected.ref,
                                            'caption',
                                            e.target.value,
                                        )
                                    }
                                    placeholder="The people, the place and the story behind this photo…"
                                />
                            </Label>
                        </div>
                    </section>
                )}
                {value.gallery_refs.length > 0 && (
                    <section
                        aria-label="Gallery order"
                        className="space-y-2 rounded-xl border p-4"
                    >
                        <h4 className="text-sm font-semibold">
                            Tell the story in your order
                        </h4>
                        <p className="text-xs text-muted-foreground">
                            This is the order colleagues will see in the
                            gallery.
                        </p>
                        <ol className="divide-y">
                            {value.gallery_refs.map((ref, index) => {
                                const photo = photos.find(
                                    (item) => item.ref === ref,
                                );
                                return (
                                    <li
                                        key={ref}
                                        className="flex items-center gap-3 py-2"
                                    >
                                        <span className="w-5 text-xs text-muted-foreground">
                                            {index + 1}
                                        </span>
                                        {photo && (
                                            <img
                                                src={photo.url}
                                                alt=""
                                                className="size-10 rounded-md object-cover"
                                            />
                                        )}
                                        <span className="min-w-0 flex-1 truncate text-sm">
                                            {details(ref).title ||
                                                photo?.name ||
                                                'Event photo'}
                                        </span>
                                        <Button
                                            type="button"
                                            size="icon"
                                            variant="ghost"
                                            aria-label={`Move photo ${index + 1} earlier`}
                                            disabled={index === 0}
                                            onClick={() => movePhoto(ref, -1)}
                                        >
                                            <ArrowUp className="size-4" />
                                        </Button>
                                        <Button
                                            type="button"
                                            size="icon"
                                            variant="ghost"
                                            aria-label={`Move photo ${index + 1} later`}
                                            disabled={
                                                index ===
                                                value.gallery_refs.length - 1
                                            }
                                            onClick={() => movePhoto(ref, 1)}
                                        >
                                            <ArrowDown className="size-4" />
                                        </Button>
                                    </li>
                                );
                            })}
                        </ol>
                    </section>
                )}
            </fieldset>
            {value.cover_ref && (
                <div className="space-y-2">
                    <Label htmlFor="event-cover-alt">
                        Describe the cover image
                    </Label>
                    <Input
                        id="event-cover-alt"
                        maxLength={240}
                        value={value.cover_alt}
                        onChange={(e) =>
                            onChange({ ...value, cover_alt: e.target.value })
                        }
                        placeholder="A short description for people using a screen reader"
                    />
                </div>
            )}
            <div className="space-y-4 rounded-xl border bg-muted/30 p-4">
                <div>
                    <h3 className="font-semibold">A little look back</h3>
                    <p className="mt-1 text-xs text-muted-foreground">
                        The recap and gallery appear after this event ends.{' '}
                        {draftMode
                            ? 'New files stay private with this draft until the event is published.'
                            : 'Attached files are shared with the event’s audience as soon as you save.'}
                    </p>
                </div>
                <div className="space-y-2">
                    <Label htmlFor="event-recap-title">Recap title</Label>
                    <Input
                        id="event-recap-title"
                        maxLength={160}
                        value={value.recap_title ?? ''}
                        onChange={(e) =>
                            onChange({ ...value, recap_title: e.target.value })
                        }
                        placeholder="An afternoon that brought us together"
                    />
                </div>
                <div className="space-y-2">
                    <Label htmlFor="event-recap">Event recap</Label>
                    <Textarea
                        id="event-recap"
                        rows={4}
                        maxLength={8000}
                        value={value.recap}
                        onChange={(e) =>
                            onChange({ ...value, recap: e.target.value })
                        }
                        placeholder="What happened, what people enjoyed, and what comes next…"
                    />
                </div>
                <div className="space-y-2">
                    <Label htmlFor="event-story">Related company story</Label>
                    <HubSelect
                        id="event-story"
                        label="Related company story"
                        value={
                            value.story_id == null ? '' : String(value.story_id)
                        }
                        onChange={(story) =>
                            onChange({
                                ...value,
                                story_id: story ? Number(story) : null,
                            })
                        }
                        options={[
                            { value: '', label: 'No linked story' },
                            ...stories.map((story) => ({
                                value: String(story.id),
                                label: story.title,
                            })),
                        ]}
                    />
                    <p className="text-xs text-muted-foreground">
                        Only published stories are available. Each reader’s
                        access is checked again.
                    </p>
                </div>
            </div>
        </section>
    );
}
