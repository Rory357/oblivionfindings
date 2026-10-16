import { Button } from '@/components/ui/button';
import { AtSign, ImagePlus, X } from 'lucide-react';
import { useEffect, useState } from 'react';

type MentionPerson = { id: number; name: string };
const PICTURE_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

export function RecognitionImagePreview({
    files,
    remove,
}: {
    files: File[];
    remove?: (index: number) => void;
}) {
    const [previews, setPreviews] = useState<{ name: string; url: string }[]>(
        [],
    );
    useEffect(() => {
        const next = files.map((file) => ({
            name: file.name,
            url: URL.createObjectURL(file),
        }));
        setPreviews(next);
        return () => next.forEach((item) => URL.revokeObjectURL(item.url));
    }, [files]);
    if (!previews.length) return null;
    return (
        <ul
            aria-label="Attached pictures"
            className="mt-3 grid grid-cols-2 gap-2"
        >
            {previews.map((item, index) => (
                <li
                    key={item.url}
                    className="relative overflow-hidden rounded-lg border border-border"
                >
                    <img
                        src={item.url}
                        alt={`Attached picture: ${item.name}`}
                        className="aspect-square w-full object-cover"
                    />
                    {remove && (
                        <Button
                            type="button"
                            variant="secondary"
                            size="icon"
                            className="absolute top-1 right-1 min-h-11 min-w-11"
                            aria-label={`Remove picture ${item.name}`}
                            onClick={() => remove(index)}
                        >
                            <X className="size-4" />
                        </Button>
                    )}
                </li>
            ))}
        </ul>
    );
}

/** Hub mentions are opt-in; HR-only callers never request Hub people data. */
export function RecognitionSocialFields({
    files,
    mentionIds,
    onFiles,
    onMentions,
    insertMention,
    mentionOptions,
    actorId,
}: {
    files: File[];
    mentionIds: number[];
    onFiles: (files: File[]) => void;
    onMentions: (ids: number[]) => void;
    insertMention: (person: MentionPerson) => void;
    mentionOptions?: string;
    actorId?: number;
}) {
    const [people, setPeople] = useState<MentionPerson[]>([]);
    const [search, setSearch] = useState('');
    const [showMentions, setShowMentions] = useState(false);
    const [mentionStatus, setMentionStatus] = useState('');
    const [picturesError, setPicturesError] = useState('');
    useEffect(() => {
        if (!mentionOptions || !actorId) return;
        const controller = new AbortController();
        setPeople([]);
        setMentionStatus('Loading colleagues…');
        void fetch(mentionOptions, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
            signal: controller.signal,
        })
            .then(async (response) => {
                const value = await response.json();
                if (
                    !response.ok ||
                    value.actor_id !== actorId ||
                    !Array.isArray(value.people) ||
                    value.available !== true
                )
                    throw new Error(
                        'Mentions are temporarily unavailable. Your message is still here.',
                    );
                const current: MentionPerson[] = value.people.filter(
                    (person: MentionPerson) =>
                        Number.isSafeInteger(person.id) &&
                        person.id > 0 &&
                        typeof person.name === 'string' &&
                        person.name.trim(),
                );
                if (!controller.signal.aborted) {
                    setPeople(current);
                    setMentionStatus('');
                }
            })
            .catch((error: unknown) => {
                if (!controller.signal.aborted)
                    setMentionStatus(
                        error instanceof Error
                            ? error.message
                            : 'Mentions are temporarily unavailable.',
                    );
            });
        return () => controller.abort();
    }, [mentionOptions, actorId]);
    const selected = people.filter((person) => mentionIds.includes(person.id));
    const visible = people
        .filter((person) =>
            person.name
                .toLocaleLowerCase()
                .includes(search.trim().toLocaleLowerCase()),
        )
        .slice(0, 30);
    return (
        <section aria-label="Pictures and mentions" className="space-y-3">
            <div className="flex flex-wrap gap-2">
                <label
                    htmlFor="recognition-pictures"
                    className="inline-flex min-h-11 cursor-pointer items-center gap-2 rounded-md border border-input px-3 text-sm font-medium focus-within:ring-2 focus-within:ring-ring"
                >
                    <ImagePlus className="size-4" />
                    Add pictures
                    <input
                        id="recognition-pictures"
                        type="file"
                        multiple
                        accept="image/jpeg,image/png,image/webp"
                        className="sr-only"
                        onChange={(event) => {
                            const incoming = Array.from(
                                event.target.files ?? [],
                            );
                            event.target.value = '';
                            if (files.length + incoming.length > 4) {
                                setPicturesError('Choose up to 4 pictures.');
                                return;
                            }
                            if (
                                incoming.some(
                                    (file) =>
                                        !PICTURE_TYPES.includes(file.type) ||
                                        file.size > 5 * 1024 * 1024,
                                )
                            ) {
                                setPicturesError(
                                    'Choose JPEG, PNG or WebP pictures up to 5 MB each.',
                                );
                                return;
                            }
                            setPicturesError('');
                            onFiles([...files, ...incoming]);
                        }}
                    />
                </label>
                {mentionOptions && (
                    <Button
                        type="button"
                        variant="outline"
                        className="min-h-11"
                        aria-expanded={showMentions}
                        onClick={() => setShowMentions((value) => !value)}
                    >
                        <AtSign className="size-4" />
                        Mention colleagues
                    </Button>
                )}
            </div>
            <p className="text-xs text-muted-foreground">
                Up to 4 pictures · JPEG, PNG or WebP · 5 MB each. Pictures
                follow this shout-out’s audience.
            </p>
            {picturesError && (
                <p role="alert" className="text-sm text-destructive">
                    {picturesError}
                </p>
            )}
            <RecognitionImagePreview
                files={files}
                remove={(index) => {
                    setPicturesError('');
                    onFiles(files.filter((_, position) => position !== index));
                }}
            />
            {selected.length > 0 && (
                <ul
                    aria-label="Mentioned colleagues"
                    className="flex flex-wrap gap-2"
                >
                    {selected.map((person) => (
                        <li key={person.id}>
                            <Button
                                type="button"
                                variant="secondary"
                                className="min-h-11"
                                aria-label={`Remove mention ${person.name}`}
                                onClick={() =>
                                    onMentions(
                                        mentionIds.filter(
                                            (id) => id !== person.id,
                                        ),
                                    )
                                }
                            >
                                @{person.name}
                                <X className="size-3" />
                            </Button>
                        </li>
                    ))}
                </ul>
            )}
            {showMentions && (
                <div className="space-y-2 rounded-lg border border-border p-3">
                    <label
                        htmlFor="recognition-mention-search"
                        className="text-sm font-medium"
                    >
                        Find a colleague to mention
                    </label>
                    <input
                        id="recognition-mention-search"
                        type="search"
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                        className="min-h-11 w-full rounded-md border border-input bg-background px-3 text-sm focus-visible:ring-2 focus-visible:ring-ring"
                    />
                    <p className="text-xs text-muted-foreground">
                        Mentioned colleagues must be able to read this
                        shout-out. Their notification preferences still apply.
                    </p>
                    {mentionStatus && (
                        <p
                            role="status"
                            className="text-sm text-muted-foreground"
                        >
                            {mentionStatus}
                        </p>
                    )}
                    {!mentionStatus && (
                        <ul className="max-h-48 overflow-y-auto">
                            {visible.map((person) => (
                                <li key={person.id}>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        className="min-h-11 w-full justify-start"
                                        disabled={
                                            mentionIds.includes(person.id) ||
                                            mentionIds.length >= 20
                                        }
                                        onClick={() => {
                                            onMentions([
                                                ...mentionIds,
                                                person.id,
                                            ]);
                                            insertMention(person);
                                            setShowMentions(false);
                                            setSearch('');
                                        }}
                                    >
                                        @{person.name}
                                    </Button>
                                </li>
                            ))}
                        </ul>
                    )}
                    {!mentionStatus && visible.length === 0 && (
                        <p className="text-sm text-muted-foreground">
                            No available colleagues match your search.
                        </p>
                    )}
                </div>
            )}
        </section>
    );
}
