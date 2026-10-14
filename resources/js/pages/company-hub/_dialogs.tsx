import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { formatDateOnly, formatDateTime } from '@/lib/datetime';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import { CalendarDays, Loader2, MapPin, Newspaper } from 'lucide-react';
import { useState } from 'react';
import { EventAttendance } from './_event-attendance';
import { EventStory } from './_event-story';
import { EventEditorButton, EventRegistration } from './_events';
import type { HubEvent, HubLink, HubProps } from './_types';

export function HubNotificationDialog({
    open,
    close,
    preferences,
}: {
    open: boolean;
    close: () => void;
    preferences: Record<string, boolean>;
}) {
    const form = useForm({
        news: preferences.news ?? true,
        reviews: preferences.reviews ?? true,
    });
    return (
        <Dialog open={open} onOpenChange={(value) => !value && close()}>
            <DialogContent>
                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.put('/company-hub/notifications', {
                            preserveScroll: true,
                            onSuccess: close,
                        });
                    }}
                >
                    <DialogHeader>
                        <DialogTitle>Your hub notifications</DialogTitle>
                        <DialogDescription>
                            Choose what appears in your notification bell.
                            Calendar and HR notifications keep their existing
                            settings.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="space-y-5 py-6">
                        <Label className="flex items-center justify-between gap-4">
                            <span>New company stories</span>
                            <Switch
                                checked={form.data.news}
                                onCheckedChange={(value) =>
                                    form.setData('news', value)
                                }
                            />
                        </Label>
                        <Label className="flex items-center justify-between gap-4">
                            <span>Review reminders for pages I own</span>
                            <Switch
                                checked={form.data.reviews}
                                onCheckedChange={(value) =>
                                    form.setData('reviews', value)
                                }
                            />
                        </Label>
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={close}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            Save preferences
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export function BirthdaySettingsDialog({
    open,
    close,
    people,
}: {
    open: boolean;
    close: () => void;
    people: HubProps['birthdaySettings'];
}) {
    const [search, setSearch] = useState('');
    const [busy, setBusy] = useState<number | null>(null);
    return (
        <Dialog open={open} onOpenChange={(value) => !value && close()}>
            <DialogContent
                style={{
                    width: 'min(92vw, 720px)',
                    maxWidth: 'min(92vw, 720px)',
                }}
            >
                <DialogHeader>
                    <DialogTitle>Birthday celebrations</DialogTitle>
                    <DialogDescription>
                        Birthdays stay hidden until you enable each person.
                        Staff see the day and month only.
                    </DialogDescription>
                </DialogHeader>
                <Input
                    aria-label="Find a staff member"
                    placeholder="Find a staff member…"
                    value={search}
                    onChange={(event) => setSearch(event.target.value)}
                />
                <div className="max-h-96 space-y-1 overflow-y-auto">
                    {people
                        .filter((person) =>
                            person.name
                                .toLowerCase()
                                .includes(search.toLowerCase()),
                        )
                        .map((person) => (
                            <Label
                                key={person.id}
                                className="flex items-center justify-between gap-4 rounded-lg px-2 py-3 hover:bg-accent"
                            >
                                <span>{person.name}</span>
                                <Switch
                                    checked={person.enabled}
                                    disabled={busy !== null}
                                    onCheckedChange={(enabled) => {
                                        setBusy(person.id);
                                        router.put(
                                            `/company-hub/celebrations/${person.id}`,
                                            { birthday_enabled: enabled },
                                            {
                                                preserveScroll: true,
                                                onFinish: () => setBusy(null),
                                            },
                                        );
                                    }}
                                />
                            </Label>
                        ))}
                </div>
                <DialogFooter>
                    <Button variant="outline" onClick={close}>
                        Done
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export function ShortcutDialog({
    open,
    close,
    shortcuts,
}: {
    open: boolean;
    close: () => void;
    shortcuts: HubLink[];
}) {
    return (
        <Dialog open={open} onOpenChange={(value) => !value && close()}>
            <DialogContent
                style={{
                    width: 'min(92vw, 720px)',
                    maxWidth: 'min(92vw, 720px)',
                }}
            >
                {open && <ShortcutBody close={close} shortcuts={shortcuts} />}
            </DialogContent>
        </Dialog>
    );
}
function ShortcutBody({
    close,
    shortcuts,
}: {
    close: () => void;
    shortcuts: HubLink[];
}) {
    const form = useForm<{ shortcuts: HubLink[] }>({ shortcuts });
    return (
        <form
            onSubmit={(event) => {
                event.preventDefault();
                form.put('/company-hub/shortcuts', {
                    preserveScroll: true,
                    onSuccess: close,
                });
            }}
        >
            <DialogHeader>
                <DialogTitle>Your shortcuts</DialogTitle>
                <DialogDescription>
                    Keep the tools you use most close by. These links are just
                    for you.
                </DialogDescription>
            </DialogHeader>
            <div className="max-h-96 space-y-5 overflow-y-auto py-5">
                {form.data.shortcuts.map((link, index) => (
                    <div key={link.id} className="space-y-2">
                        <Label htmlFor={`shortcut-${index}`}>
                            Shortcut {index + 1}
                        </Label>
                        <div className="flex gap-2">
                            <Input
                                id={`shortcut-${index}`}
                                required
                                value={link.title}
                                placeholder="Name"
                                onChange={(event) =>
                                    form.setData(
                                        'shortcuts',
                                        form.data.shortcuts.map((item) =>
                                            item.id === link.id
                                                ? {
                                                      ...item,
                                                      title: event.target.value,
                                                  }
                                                : item,
                                        ),
                                    )
                                }
                            />
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={() =>
                                    form.setData(
                                        'shortcuts',
                                        form.data.shortcuts.filter(
                                            (item) => item.id !== link.id,
                                        ),
                                    )
                                }
                            >
                                Remove
                            </Button>
                        </div>
                        <Input
                            aria-label={`Shortcut ${index + 1} link`}
                            required
                            value={link.url}
                            placeholder="https://… or /internal-page"
                            onChange={(event) =>
                                form.setData(
                                    'shortcuts',
                                    form.data.shortcuts.map((item) =>
                                        item.id === link.id
                                            ? {
                                                  ...item,
                                                  url: event.target.value,
                                              }
                                            : item,
                                    ),
                                )
                            }
                        />
                    </div>
                ))}
                <Button
                    type="button"
                    variant="outline"
                    disabled={form.data.shortcuts.length >= 20}
                    onClick={() =>
                        form.setData('shortcuts', [
                            ...form.data.shortcuts,
                            { id: crypto.randomUUID(), title: '', url: '' },
                        ])
                    }
                >
                    Add shortcut
                </Button>
                {Object.values(form.errors).length > 0 && (
                    <p role="alert" className="text-sm text-destructive">
                        {Object.values(form.errors).join(' ')}
                    </p>
                )}
            </div>
            <DialogFooter>
                <Button type="button" variant="outline" onClick={close}>
                    Cancel
                </Button>
                <Button type="submit" disabled={form.processing}>
                    Save shortcuts
                </Button>
            </DialogFooter>
        </form>
    );
}

export type CreateHubPageInitial = {
    title: string;
    kind: string;
    template: string;
    source_page_id?: number;
    source_revision_id?: number;
};

export function CreateHubPageDialog({
    open,
    close,
    initial,
    context,
}: {
    open: boolean;
    close: () => void;
    initial?: CreateHubPageInitial;
    context?: string;
}) {
    return (
        <Dialog open={open} onOpenChange={(v) => !v && close()}>
            <DialogContent
                style={{
                    width: 'min(92vw, 720px)',
                    maxWidth: 'min(92vw, 720px)',
                }}
            >
                {open && (
                    <CreateBody
                        close={close}
                        initial={initial}
                        context={context}
                    />
                )}
            </DialogContent>
        </Dialog>
    );
}
function CreateBody({
    close,
    initial,
    context,
}: {
    close: () => void;
    initial?: CreateHubPageInitial;
    context?: string;
}) {
    const form = useForm<CreateHubPageInitial>(
        initial ?? { title: '', kind: 'news', template: 'story' },
    );
    return (
        <form
            onSubmit={(event) => {
                event.preventDefault();
                form.post('/company-hub/pages', { onSuccess: close });
            }}
        >
            <DialogHeader>
                <DialogTitle className="flex items-center gap-2">
                    <Newspaper className="size-5 text-primary" />
                    Create something worth sharing
                </DialogTitle>
                <DialogDescription>
                    {context ||
                        'Start a story or build a useful page for your company.'}
                </DialogDescription>
            </DialogHeader>
            <div className="space-y-5 py-6">
                <fieldset className="space-y-2">
                    <legend className="text-sm font-medium">
                        Choose a starting point
                    </legend>
                    <div className="grid grid-cols-2 gap-2">
                        {[
                            ['story', 'A great story'],
                            ['leadership', 'Leadership update'],
                            ['information', 'Information page'],
                            ['guide', 'Knowledge resource'],
                            ['team', 'Department space'],
                            ['campaign', 'Company campaign'],
                            ['welcome', 'New starter welcome'],
                            ['blank', 'Blank canvas'],
                        ]
                            .filter(
                                ([key]) =>
                                    !initial?.source_page_id ||
                                    ['story', 'leadership'].includes(key),
                            )
                            .map(([key, title]) => (
                                <Button
                                    key={key}
                                    type="button"
                                    variant={
                                        form.data.template === key
                                            ? 'secondary'
                                            : 'outline'
                                    }
                                    aria-pressed={form.data.template === key}
                                    onClick={() =>
                                        form.setData((data) => ({
                                            ...data,
                                            template: key,
                                            kind:
                                                (
                                                    {
                                                        story: 'news',
                                                        leadership: 'news',
                                                        guide: 'knowledge',
                                                        team: 'department',
                                                        campaign: 'campaign',
                                                    } as Record<string, string>
                                                )[key] ?? 'page',
                                        }))
                                    }
                                >
                                    {title}
                                </Button>
                            ))}
                    </div>
                    <p className="text-sm text-muted-foreground">
                        Every section can be changed in the editor.
                    </p>
                </fieldset>
                <div className="space-y-2">
                    <Label htmlFor="hub-new-title">Title</Label>
                    <Input
                        id="hub-new-title"
                        autoFocus
                        required
                        maxLength={160}
                        value={form.data.title}
                        onChange={(event) =>
                            form.setData('title', event.target.value)
                        }
                        placeholder="Give your story a great title"
                    />
                    {form.errors.title && (
                        <p role="alert" className="text-sm text-destructive">
                            {form.errors.title}
                        </p>
                    )}
                    {Object.entries(form.errors)
                        .filter(([key]) => key !== 'title')
                        .map(([key, message]) => (
                            <p
                                key={key}
                                role="alert"
                                className="text-sm text-destructive"
                            >
                                {message}
                            </p>
                        ))}
                </div>
            </div>
            <DialogFooter>
                <Button type="button" variant="outline" onClick={close}>
                    Cancel
                </Button>
                <Button
                    type="submit"
                    disabled={form.processing || !form.data.title.trim()}
                >
                    {form.processing && (
                        <Loader2 className="size-4 animate-spin" />
                    )}
                    Create page
                </Button>
            </DialogFooter>
        </form>
    );
}

export function HubEventDialog({
    event,
    close,
    bookmarks = [],
    preview = false,
    events = [],
    onSelect,
}: {
    event: HubEvent | null;
    close: () => void;
    bookmarks?: HubProps['bookmarks'];
    preview?: boolean;
    events?: HubEvent[];
    onSelect?: (event: HubEvent) => void;
}) {
    return (
        <Dialog open={!!event} onOpenChange={(v) => !v && close()}>
            <DialogContent
                className="max-h-[88vh] overflow-x-hidden overflow-y-auto"
                style={{
                    width: 'min(92vw, 900px)',
                    maxWidth: 'min(92vw, 900px)',
                }}
            >
                {event && (
                    <EventBody
                        key={event.id}
                        event={event}
                        bookmarks={bookmarks}
                        preview={preview}
                        events={events}
                        onSelect={onSelect}
                        onEdited={close}
                    />
                )}
            </DialogContent>
        </Dialog>
    );
}
function EventBody({
    event,
    bookmarks,
    preview,
    events,
    onSelect,
    onEdited,
}: {
    event: HubEvent;
    bookmarks: HubProps['bookmarks'];
    preview: boolean;
    events: HubEvent[];
    onSelect?: (event: HubEvent) => void;
    onEdited: () => void;
}) {
    const [saving, setSaving] = useState(false);
    const [reminder, setReminder] = useState('15');
    const userId = Number(
        (usePage().props.auth as { user?: { id: number } })?.user?.id,
    );
    const props = event.extendedProps;
    const past = props.hubContent?.past ?? new Date(event.end) < new Date();
    const cover = props.hubContent
        ? props.hubContent.cover
        : props.attachments?.find((file) => file.mime?.startsWith('image/'));
    const seriesId = props.recurrenceParentId ?? props.eventId;
    const dates = events.filter(
        (item) =>
            (item.extendedProps.recurrenceParentId ??
                item.extendedProps.eventId) === seriesId,
    );
    const canRsvp =
        !preview &&
        !past &&
        !props.registration?.enabled &&
        props.audienceType === 'people' &&
        props.attendeeUserIds?.includes(userId);
    return (
        <>
            <DialogHeader>
                <DialogTitle>{event.title}</DialogTitle>
                <DialogDescription>
                    Organised by {props.createdBy || 'your company'}
                    {props.recurring ? ' · Recurring event' : ''}
                </DialogDescription>
            </DialogHeader>
            <div className="space-y-4 py-5">
                {!past && dates.length > 1 && onSelect && (
                    <div className="space-y-2 rounded-xl bg-muted/40 p-3">
                        <Label htmlFor="event-date-choice">Choose a date</Label>
                        <select
                            id="event-date-choice"
                            className="hub-native-select block w-full"
                            value={event.id}
                            onChange={(e) => {
                                const selected = dates.find(
                                    (item) => item.id === e.target.value,
                                );
                                if (selected) onSelect(selected);
                            }}
                        >
                            {dates.map((item) => (
                                <option key={item.id} value={item.id}>
                                    {formatDateTime(item.start)}
                                    {item.extendedProps.registration?.mine ===
                                    'going'
                                        ? ' · You’re going'
                                        : item.extendedProps.registration
                                                ?.mine === 'waiting'
                                          ? ' · Waiting list'
                                          : ''}
                                </option>
                            ))}
                        </select>
                        <p className="text-xs text-muted-foreground">
                            Available dates in this view. Each date has its own
                            registration and attendance.
                        </p>
                    </div>
                )}
                {cover && (
                    <img
                        className="h-56 w-full rounded-xl object-cover"
                        alt={props.hubContent?.cover?.alt ?? ''}
                        src={cover.url}
                    />
                )}
                <p className="flex items-center gap-2">
                    <CalendarDays className="size-4 text-primary" />
                    {event.allDay
                        ? formatDateOnly(event.start.slice(0, 10))
                        : formatDateTime(event.start)}
                    {!event.allDay && ` – ${formatDateTime(event.end)}`}
                    {event.allDay && ' · All day'}
                </p>
                {props.location && (
                    <p className="flex items-center gap-2">
                        <MapPin className="size-4 text-primary" />
                        {props.location}
                    </p>
                )}
                <p className="text-sm leading-relaxed whitespace-pre-wrap">
                    {props.description ||
                        'More details will be shared by the organiser.'}
                </p>
                <EventStory event={event} />
                {!preview && (
                    <div className="flex flex-wrap gap-2">
                        <Button
                            variant="outline"
                            onClick={() =>
                                router.post(
                                    '/company-hub/bookmarks',
                                    {
                                        subject_type: 'event',
                                        subject_id:
                                            props.recurrenceParentId ??
                                            props.eventId,
                                        saved: !bookmarks.some(
                                            (item) =>
                                                item.subject_type === 'event' &&
                                                item.subject_id ===
                                                    (props.recurrenceParentId ??
                                                        props.eventId),
                                        ),
                                    },
                                    { preserveScroll: true },
                                )
                            }
                        >
                            {bookmarks.some(
                                (item) =>
                                    item.subject_type === 'event' &&
                                    item.subject_id ===
                                        (props.recurrenceParentId ??
                                            props.eventId),
                            )
                                ? 'Remove saved event'
                                : 'Save event'}
                        </Button>
                        <Button variant="ghost" asChild>
                            <Link href="/my-calendar">Open my calendar</Link>
                        </Button>
                        {event.editable && (
                            <EventEditorButton
                                event={event}
                                onSaved={onEdited}
                            />
                        )}
                    </div>
                )}
                {!preview && props.registration && (
                    <>
                        {!past && (
                            <EventRegistration
                                key={`${event.id}-${props.registration.version}-${props.registration.going}-${props.registration.waiting}-${props.registration.mine}-${props.registration.attendance}`}
                                event={event}
                            />
                        )}
                        <EventAttendance key={event.id} event={event} />
                        <div className="flex items-end gap-3">
                            <div className="space-y-1">
                                <Label htmlFor="event-reminder">
                                    Calendar reminder
                                </Label>
                                <select
                                    className="hub-native-select block"
                                    id="event-reminder"
                                    value={reminder}
                                    onChange={(e) =>
                                        setReminder(e.target.value)
                                    }
                                >
                                    <option value="">None</option>
                                    <option value="15">
                                        15 minutes before
                                    </option>
                                    <option value="60">1 hour before</option>
                                    <option value="1440">1 day before</option>
                                </select>
                            </div>
                            <Button variant="outline" asChild>
                                <a
                                    href={`/company-hub/events/${props.eventId}/calendar?date=${props.registration.date}${reminder ? `&reminder=${reminder}` : ''}`}
                                >
                                    Add this date to my calendar
                                </a>
                            </Button>
                            {props.recurring && (
                                <Button variant="outline" asChild>
                                    <a
                                        href={`/company-hub/events/${props.eventId}/calendar?date=${props.registration.date}&scope=series${reminder ? `&reminder=${reminder}` : ''}`}
                                    >
                                        Add whole series
                                    </a>
                                </Button>
                            )}
                        </div>
                        <p className="text-xs text-muted-foreground">
                            {props.recurring
                                ? 'Choose this date or the whole series, including moved and cancelled dates. '
                                : 'Downloads this event. '}
                            Your calendar app handles the reminder. Downloaded
                            copies do not update automatically.
                        </p>
                    </>
                )}
                {!!props.attachments?.length && (
                    <div className="space-y-2">
                        <h3 className="text-sm font-semibold">Event files</h3>
                        {props.attachments.map((file) => (
                            <a
                                className="block text-sm text-primary underline"
                                key={file.id}
                                href={file.url}
                            >
                                {file.name}
                            </a>
                        ))}
                    </div>
                )}
                {canRsvp && props.recurring && (
                    <p className="text-sm text-muted-foreground">
                        Your response applies to the event series.
                    </p>
                )}
            </div>
            {canRsvp && (
                <DialogFooter>
                    {[
                        { status: 'yes', label: 'I’m going' },
                        { status: 'maybe', label: 'Maybe' },
                        { status: 'no', label: 'Can’t attend' },
                    ].map((option) => (
                        <Button
                            key={option.status}
                            disabled={saving}
                            variant={
                                props.myRsvp === option.status
                                    ? 'default'
                                    : 'outline'
                            }
                            onClick={() => {
                                setSaving(true);
                                router.post(
                                    `/company-hub/events/${props.recurrenceParentId ?? props.eventId}/rsvp`,
                                    { status: option.status },
                                    {
                                        preserveScroll: true,
                                        onFinish: () => setSaving(false),
                                    },
                                );
                            }}
                        >
                            {option.label}
                        </Button>
                    ))}
                </DialogFooter>
            )}
        </>
    );
}
