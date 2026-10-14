import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useEffect, useState } from 'react';
import type { EventAttachment } from './event-wizard-dialog';

export type EventHubContentInput = {
    host_id: number | null;
    agenda: string;
    recap: string;
    story_id: number | null;
    cover_ref: string;
    cover_alt: string;
    gallery_refs: string[];
};
export const emptyEventHubContent = (): EventHubContentInput => ({
    host_id: null,
    agenda: '',
    recap: '',
    story_id: null,
    cover_ref: '',
    cover_alt: '',
    gallery_refs: [],
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
}: {
    value: EventHubContentInput;
    onChange: (value: EventHubContentInput) => void;
    files: File[];
    attachments: EventAttachment[];
    staff: { value: string; label: string }[];
    stories: { id: number; title: string }[];
    draftMode?: boolean;
}) {
    const [previews, setPreviews] = useState<
        { ref: string; name: string; url: string }[]
    >([]);
    useEffect(() => {
        const images = files.flatMap((file, index) =>
            file.type.startsWith('image/')
                ? [
                      {
                          ref: `upload:${index}`,
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
        ...previews,
    ];
    return (
        <section
            className="mt-7 space-y-5 border-t pt-6"
            aria-label="Company Hub event details"
        >
            <div>
                <h3 className="text-base font-semibold">
                    Bring the event to life
                </h3>
                <p className="mt-1 text-sm text-muted-foreground">
                    Add a host, a useful agenda and a photo for the event board.
                </p>
            </div>
            <div className="space-y-2">
                <Label htmlFor="event-host">Event host</Label>
                <select
                    id="event-host"
                    className="h-10 w-full rounded-md border bg-background px-3 text-sm"
                    value={value.host_id ?? ''}
                    onChange={(e) =>
                        onChange({
                            ...value,
                            host_id: e.target.value
                                ? Number(e.target.value)
                                : null,
                        })
                    }
                >
                    <option value="">Use the organiser</option>
                    {staff.map((person) => (
                        <option key={person.value} value={person.value}>
                            {person.label}
                        </option>
                    ))}
                </select>
            </div>
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
                    Event cover & photos
                </legend>
                <p className="text-xs text-muted-foreground">
                    Attach photos above, then choose a cover. Select up to 12
                    photos for the recap gallery.
                </p>
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
                            </div>
                        </Card>
                    ))}
                </div>
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
                    <select
                        id="event-story"
                        className="h-10 w-full rounded-md border bg-background px-3 text-sm"
                        value={value.story_id ?? ''}
                        onChange={(e) =>
                            onChange({
                                ...value,
                                story_id: e.target.value
                                    ? Number(e.target.value)
                                    : null,
                            })
                        }
                    >
                        <option value="">No linked story</option>
                        {stories.map((story) => (
                            <option key={story.id} value={story.id}>
                                {story.title}
                            </option>
                        ))}
                    </select>
                    <p className="text-xs text-muted-foreground">
                        Only published stories are available. Each reader’s
                        access is checked again.
                    </p>
                </div>
            </div>
        </section>
    );
}
