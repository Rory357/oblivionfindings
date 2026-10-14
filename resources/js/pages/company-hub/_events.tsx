import {
    EventWizardDialog,
    type CalendarEventInitial,
} from '@/components/hr/calendar/event-wizard-dialog';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { formatDateOnly, formatDateTime } from '@/lib/datetime';
import { router } from '@inertiajs/react';
import { CalendarDays, Plus } from 'lucide-react';
import { useEffect, useRef, useState, type ComponentProps } from 'react';
import { hubRequest } from './_api';
import { EventDraftWorkspace } from './_event-drafts';
import type { HubEvent, HubProps } from './_types';

export type EventOptions = Pick<
    ComponentProps<typeof EventWizardDialog>,
    | 'sites'
    | 'departments'
    | 'teams'
    | 'categories'
    | 'staff'
    | 'storyOptions'
    | 'emailAvailable'
> & { can_manage_recurring?: boolean; can_publish?: boolean };
type EditScope = 'this' | 'following' | 'all';
export function eventInitial(
    event: HubEvent,
    scope?: EditScope,
): CalendarEventInitial {
    const p = event.extendedProps;
    return {
        id: p.eventId,
        edit_version: p.editVersion,
        registration_enabled: p.registration?.enabled,
        registration_capacity: p.registration?.capacity,
        registration_version: p.registration?.version,
        recurrence_parent_id: p.recurrenceParentId,
        title: event.title,
        description: p.description,
        event_type: p.category ?? 'company',
        starts_at:
            scope === 'all' && !p.recurrenceParentId
                ? (p.seriesStart ?? event.start)
                : event.start,
        ends_at:
            scope === 'all' && !p.recurrenceParentId
                ? (p.seriesEnd ?? event.end)
                : event.end,
        is_all_day: event.allDay,
        location: p.location,
        department_id: p.departmentId ?? null,
        site_id: p.siteId ?? null,
        rrule: p.rrule,
        recurrence_until: p.recurrenceUntil,
        recurrence_timezone: p.recurrenceTimezone,
        hub_content: p.hubContent?.editor ?? undefined,
        audience_type: p.audienceType ?? 'org',
        audience_team: p.audienceRef,
        audience_user_ids: p.attendeeUserIds,
        reminders: p.reminders,
        attachments: p.attachments,
        // Editing a repeated date opens the canonical occurrence editor.
        scope: scope ?? (p.recurring && !p.recurrenceParentId ? 'this' : 'all'),
        occurrence_date: p.occurrenceDate,
    };
}
export function EventEditorButton({
    event,
    onOpen,
    onSaved,
}: {
    event?: HubEvent;
    onOpen?: () => void;
    onSaved?: () => void;
}) {
    const [options, setOptions] = useState<EventOptions | null>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [scope, setScope] = useState<EditScope | null>(null);
    const requestId = useRef(0);
    useEffect(
        () => () => {
            requestId.current++;
        },
        [],
    );
    return (
        <>
            <Button
                variant={event ? 'outline' : 'default'}
                disabled={busy}
                onClick={async () => {
                    const id = ++requestId.current;
                    setBusy(true);
                    setError('');
                    try {
                        const result = await hubRequest<EventOptions>(
                            '/company-hub/events/options',
                            'GET',
                        );
                        if (requestId.current !== id) return;
                        setOptions(result);
                        setScope(
                            event?.extendedProps.recurring &&
                                !event.extendedProps.recurrenceParentId
                                ? null
                                : 'all',
                        );
                        onOpen?.();
                    } catch (e) {
                        if (requestId.current === id)
                            setError((e as Error).message);
                    } finally {
                        if (requestId.current === id) setBusy(false);
                    }
                }}
            >
                {event ? (
                    'Edit event details'
                ) : (
                    <>
                        <Plus className="size-4" /> Create event
                    </>
                )}
            </Button>
            {error && (
                <p role="alert" className="text-sm text-destructive">
                    {error}
                </p>
            )}
            {options && !scope && (
                <Dialog
                    open
                    onOpenChange={(open) => {
                        if (!open) setOptions(null);
                    }}
                >
                    <DialogContent className="overflow-x-hidden sm:max-w-lg">
                        <DialogHeader>
                            <DialogTitle>Edit a recurring event</DialogTitle>
                            <DialogDescription>
                                Choose which dates you want to change.
                                Registrations and individual date changes stay
                                linked to the series.
                            </DialogDescription>
                        </DialogHeader>
                        <div className="space-y-3">
                            {options.can_manage_recurring && (
                                <>
                                    <Button
                                        variant="outline"
                                        className="w-full justify-start"
                                        onClick={() => setScope('this')}
                                    >
                                        This date only
                                    </Button>
                                    <Button
                                        variant="outline"
                                        className="w-full justify-start"
                                        onClick={() => setScope('following')}
                                    >
                                        This and following dates
                                    </Button>
                                </>
                            )}
                            <Button
                                variant="outline"
                                className="w-full justify-start"
                                onClick={() => setScope('all')}
                            >
                                Whole series
                            </Button>
                            {!options.can_manage_recurring && (
                                <p className="text-sm text-muted-foreground">
                                    Changing individual dates requires
                                    recurring-event permission.
                                </p>
                            )}
                        </div>
                    </DialogContent>
                </Dialog>
            )}
            {options && scope && (
                <EventWizardDialog
                    open
                    onClose={() => setOptions(null)}
                    onSaved={() =>
                        router.reload({
                            only: ['events'],
                            preserveScroll: true,
                            onSuccess: onSaved,
                        })
                    }
                    {...options}
                    publishing={{ can_publish: !!options.can_publish }}
                    initial={event ? eventInitial(event, scope) : null}
                />
            )}
        </>
    );
}

export function EventRegistration({ event }: { event: HubEvent }) {
    const [registration, setRegistration] = useState(
        event.extendedProps.registration,
    );
    const [enabled, setEnabled] = useState(!!registration?.enabled);
    const [capacity, setCapacity] = useState(
        registration?.capacity?.toString() ?? '',
    );
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [saved, setSaved] = useState(false);
    if (!registration) return null;
    const closed = !registration.enabled || new Date(event.start) <= new Date();
    const hasPlace = ['going', 'waiting', 'maybe'].includes(
        registration.mine ?? '',
    );
    const respond = async (status: string) => {
        setBusy(true);
        setError('');
        try {
            const result = await hubRequest<{
                registration: NonNullable<
                    HubEvent['extendedProps']['registration']
                >;
            }>(
                `/company-hub/events/${event.extendedProps.eventId}/registration`,
                'POST',
                { date: registration.date, status },
            );
            setRegistration(result.registration);
            router.reload({ only: ['events'], preserveScroll: true });
        } catch (e) {
            setError((e as Error).message);
        } finally {
            setBusy(false);
        }
    };
    return (
        <div className="space-y-4 rounded-xl border p-4">
            {(registration.enabled || hasPlace || registration.attendance) && (
                <>
                    <div className="flex justify-between gap-3">
                        <strong>Join this date</strong>
                        <span className="text-sm text-muted-foreground">
                            {registration.going} going
                            {registration.capacity !== null &&
                                ` · ${Math.max(0, registration.capacity - registration.going)} ${registration.capacity - registration.going === 1 ? 'place' : 'places'} left`}
                            {registration.waiting > 0 &&
                                ` · ${registration.waiting} waiting`}
                        </span>
                    </div>
                    <p className="text-sm text-muted-foreground">
                        {registration.mine === 'waiting'
                            ? `You’re on the waiting list${registration.queue_position ? ` · position ${registration.queue_position}` : ''}. We’ll notify you here when a place is confirmed.`
                            : registration.mine === 'going'
                              ? 'Your place is confirmed for this date.'
                              : 'Your choice applies to this date only.'}
                    </p>
                    {registration.attendance && (
                        <p className="text-sm font-medium">
                            Your attendance:{' '}
                            {registration.attendance === 'attended'
                                ? 'attended'
                                : 'did not attend'}
                            .
                        </p>
                    )}
                    {closed ? (
                        <div className="space-y-3">
                            <p className="text-sm">
                                Registration has closed for this date.
                            </p>
                            {hasPlace && (
                                <Button
                                    variant="outline"
                                    disabled={busy}
                                    onClick={() => void respond('declined')}
                                >
                                    Cancel my place
                                </Button>
                            )}
                        </div>
                    ) : (
                        <div className="flex flex-wrap gap-2">
                            {[
                                [
                                    'going',
                                    registration.mine === 'waiting'
                                        ? 'On waiting list'
                                        : registration.capacity !== null &&
                                            registration.going >=
                                                registration.capacity &&
                                            registration.mine !== 'going'
                                          ? 'Join waiting list'
                                          : 'I’m going',
                                ],
                                ['maybe', 'Maybe'],
                                [
                                    'declined',
                                    registration.mine === 'going' ||
                                    registration.mine === 'waiting'
                                        ? 'Cancel my place'
                                        : 'Can’t attend',
                                ],
                            ].map(([status, label]) => (
                                <Button
                                    key={status}
                                    disabled={busy}
                                    variant={
                                        registration.mine === status ||
                                        (status === 'going' &&
                                            registration.mine === 'waiting')
                                            ? 'default'
                                            : 'outline'
                                    }
                                    onClick={() => void respond(status)}
                                >
                                    {label}
                                </Button>
                            ))}
                        </div>
                    )}
                </>
            )}
            {(registration.can_configure ?? event.editable) && (
                <details>
                    <summary className="cursor-pointer text-sm font-medium">
                        Registration settings
                    </summary>
                    <div className="mt-4 space-y-4">
                        <p className="text-xs text-muted-foreground">
                            These settings apply to every date in this series.
                            Each date has its own places and waiting list. Only
                            staff who can view this event can register.
                        </p>
                        <div className="flex items-center justify-between">
                            <Label htmlFor="registration-enabled">
                                Open registration
                            </Label>
                            <Switch
                                id="registration-enabled"
                                checked={enabled}
                                onCheckedChange={setEnabled}
                            />
                        </div>
                        <Label htmlFor="registration-capacity">
                            Places per date · leave empty for unlimited
                        </Label>
                        <Input
                            id="registration-capacity"
                            type="number"
                            min={1}
                            max={100000}
                            value={capacity}
                            onChange={(e) => setCapacity(e.target.value)}
                        />
                        <Button
                            disabled={busy}
                            onClick={async () => {
                                setBusy(true);
                                setError('');
                                setSaved(false);
                                try {
                                    const result = await hubRequest<{
                                        registration: NonNullable<
                                            HubEvent['extendedProps']['registration']
                                        >;
                                    }>(
                                        `/company-hub/events/${event.extendedProps.eventId}/registration`,
                                        'PUT',
                                        {
                                            enabled,
                                            capacity: capacity
                                                ? Number(capacity)
                                                : null,
                                            version: registration.version,
                                            date: registration.date,
                                        },
                                    );
                                    setRegistration(result.registration);
                                    setSaved(true);
                                    router.reload({
                                        only: ['events'],
                                        preserveScroll: true,
                                    });
                                } catch (e) {
                                    setError((e as Error).message);
                                } finally {
                                    setBusy(false);
                                }
                            }}
                        >
                            Save registration settings
                        </Button>
                        {saved && (
                            <p role="status" className="text-sm">
                                Registration settings saved.
                            </p>
                        )}
                    </div>
                </details>
            )}
            {error && (
                <p role="alert" className="text-sm text-destructive">
                    {error}
                </p>
            )}
        </div>
    );
}

export function EventBoard({
    data,
    onEvent,
}: {
    data: HubProps;
    onEvent: (event: HubEvent) => void;
}) {
    const [query, setQuery] = useState('');
    const [filter, setFilter] = useState('all');
    const [category, setCategory] = useState('all');
    const currentYear = Number(
        new Intl.DateTimeFormat('en-NZ', {
            year: 'numeric',
            timeZone: 'Pacific/Auckland',
        }).format(new Date()),
    );
    const [year, setYear] = useState(currentYear);
    const [history, setHistory] = useState<HubEvent[]>([]);
    const [historyBusy, setHistoryBusy] = useState(false);
    const [historyError, setHistoryError] = useState('');
    const [historyRetry, setHistoryRetry] = useState(0);
    useEffect(() => {
        if (filter !== 'past' || data.demo) return;
        let current = true;
        setHistoryBusy(true);
        setHistoryError('');
        setHistory([]);
        void hubRequest<{ events: HubEvent[] }>(
            `/company-hub/events/history?year=${year}`,
            'GET',
        )
            .then((result) => {
                if (current) setHistory(result.events);
            })
            .catch((error) => {
                if (current) setHistoryError((error as Error).message);
            })
            .finally(() => {
                if (current) setHistoryBusy(false);
            });
        return () => {
            current = false;
        };
    }, [filter, year, historyRetry, data.events, data.demo]);
    const isPast = (event: HubEvent) =>
        event.extendedProps.hubContent?.past ??
        new Date(event.end) < new Date();
    const source =
        filter === 'past'
            ? data.demo
                ? data.events.filter(isPast)
                : history
            : data.events.filter((event) => !isPast(event));
    const matching = source.filter(
        (event) =>
            (!query ||
                `${event.title} ${event.extendedProps.location ?? ''}`
                    .toLowerCase()
                    .includes(query.toLowerCase())) &&
            (category === 'all' || event.extendedProps.category === category) &&
            (filter !== 'mine' ||
                ['going', 'waiting', 'maybe'].includes(
                    event.extendedProps.registration?.mine ?? '',
                ) ||
                ['yes', 'maybe'].includes(event.extendedProps.myRsvp ?? '')) &&
            (filter !== 'saved' ||
                data.bookmarks.some(
                    (b) =>
                        b.subject_type === 'event' &&
                        b.subject_id ===
                            (event.extendedProps.recurrenceParentId ??
                                event.extendedProps.eventId),
                )),
    );
    // Show one card for a series; its dialog lets staff choose an available date.
    const series = new Map<number, HubEvent[]>();
    for (const item of [...matching].sort((a, b) =>
        a.start.localeCompare(b.start),
    )) {
        const id =
            item.extendedProps.recurrenceParentId ?? item.extendedProps.eventId;
        series.set(id, [...(series.get(id) ?? []), item]);
    }
    const events =
        filter === 'past'
            ? matching
            : [...series.values()].map((dates) => dates[0]);
    return (
        <div className="space-y-5">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 className="text-xl font-semibold">
                        Make time to connect
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        {filter === 'past'
                            ? `Photos, stories and shared moments · ${year}`
                            : 'Upcoming events across your company · next 90 days'}
                    </p>
                </div>
                <div className="flex items-center gap-2">
                    {data.capabilities.calendar && (
                        <Button variant="outline" asChild>
                            <a href="/hr/calendar">
                                <CalendarDays className="size-4" />
                                Open calendar
                            </a>
                        </Button>
                    )}
                    {data.capabilities.createEvent && !data.demo && (
                        <>
                            <EventDraftWorkspace />
                            <EventEditorButton />
                        </>
                    )}
                </div>
            </div>
            <div className="flex gap-3">
                <Input
                    aria-label="Search events"
                    placeholder="Search events or locations…"
                    value={query}
                    onChange={(e) => setQuery(e.target.value)}
                    className="max-w-sm"
                />
                <select
                    className="hub-native-select"
                    aria-label="Event category"
                    value={category}
                    onChange={(e) => setCategory(e.target.value)}
                >
                    <option value="all">All categories</option>
                    {[
                        ...new Set(
                            source
                                .map((e) => e.extendedProps.category)
                                .filter(Boolean),
                        ),
                    ].map((value) => (
                        <option key={value} value={value}>
                            {value}
                        </option>
                    ))}
                </select>
                {[
                    ['all', 'All events'],
                    ['mine', 'My events'],
                    ['saved', 'Saved'],
                    ['past', 'Photos & recaps'],
                ].map(([value, label]) => (
                    <Button
                        key={value}
                        variant={filter === value ? 'default' : 'outline'}
                        onClick={() => setFilter(value)}
                    >
                        {label}
                    </Button>
                ))}
            </div>
            {filter === 'past' && (
                <div className="flex items-center gap-3">
                    <Button
                        variant="outline"
                        disabled={year <= 1900}
                        onClick={() => setYear(year - 1)}
                    >
                        Previous year
                    </Button>
                    <Label htmlFor="event-history-year">Year</Label>
                    <select
                        id="event-history-year"
                        className="hub-native-select"
                        value={year}
                        onChange={(e) => setYear(Number(e.target.value))}
                    >
                        {Array.from(
                            { length: currentYear - 1899 },
                            (_, index) => currentYear - index,
                        ).map((value) => (
                            <option key={value} value={value}>
                                {value}
                            </option>
                        ))}
                    </select>
                    <Button
                        variant="outline"
                        disabled={year >= currentYear}
                        onClick={() => setYear(year + 1)}
                    >
                        Next year
                    </Button>
                    <span className="text-xs text-muted-foreground">
                        Each past date has its own recap.
                    </span>
                </div>
            )}
            {filter === 'past' && historyBusy && (
                <p role="status" className="text-sm text-muted-foreground">
                    Loading past events…
                </p>
            )}
            {filter === 'past' && historyError && (
                <div role="alert" className="rounded-xl border p-4">
                    <p>{historyError}</p>
                    <Button
                        variant="outline"
                        onClick={() => setHistoryRetry((value) => value + 1)}
                    >
                        Try again
                    </Button>
                </div>
            )}
            {!events.length &&
                !(filter === 'past' && (historyBusy || historyError)) && (
                    <div className="rounded-xl border border-dashed p-10 text-center text-muted-foreground">
                        {filter === 'past'
                            ? `No past events match this view in ${year}.`
                            : 'No upcoming events match this view.'}
                    </div>
                )}
            <div className="grid grid-cols-2 gap-5">
                {events.map((event) => {
                    const p = event.extendedProps;
                    const cover = p.hubContent
                        ? p.hubContent.cover
                        : p.attachments?.find((a) =>
                              a.mime?.startsWith('image/'),
                          );
                    return (
                        <Button
                            variant="ghost"
                            key={event.id}
                            onClick={() => onEvent(event)}
                            className="h-auto flex-col items-stretch overflow-hidden rounded-xl border bg-card p-0 text-left whitespace-normal hover:bg-muted/40"
                        >
                            {cover ? (
                                <img
                                    src={cover.url}
                                    alt=""
                                    className="h-48 w-full object-cover"
                                />
                            ) : (
                                <div className="flex h-28 items-center justify-between bg-primary/10 px-6 text-primary">
                                    <CalendarDays className="size-8" />
                                    <span className="text-2xl font-semibold">
                                        {new Date(
                                            event.start,
                                        ).toLocaleDateString('en-NZ', {
                                            month: 'short',
                                            day: 'numeric',
                                        })}
                                    </span>
                                </div>
                            )}
                            <div className="space-y-2 p-5">
                                <p className="text-xs text-primary">
                                    {filter === 'past'
                                        ? 'Event recap'
                                        : (p.category ?? 'Company event')}
                                    {p.recurring && ' · Recurring'}
                                </p>
                                <h3 className="text-lg font-semibold">
                                    {event.title}
                                </h3>
                                <p className="text-sm text-muted-foreground">
                                    {event.allDay
                                        ? formatDateOnly(
                                              event.start.slice(0, 10),
                                          )
                                        : formatDateTime(event.start)}
                                </p>
                                {filter !== 'past' &&
                                    (series.get(
                                        p.recurrenceParentId ?? p.eventId,
                                    )?.length ?? 0) > 1 && (
                                        <p className="text-xs text-primary">
                                            {
                                                series.get(
                                                    p.recurrenceParentId ??
                                                        p.eventId,
                                                )!.length
                                            }{' '}
                                            matching dates · Choose a date
                                            inside
                                        </p>
                                    )}
                                <p className="text-sm text-muted-foreground">
                                    {p.location || 'Details inside'}
                                </p>
                                {p.hubContent?.host && (
                                    <p className="text-xs text-muted-foreground">
                                        Hosted by {p.hubContent.host.name}
                                    </p>
                                )}
                                {filter === 'past' && (
                                    <p className="line-clamp-2 text-sm text-muted-foreground">
                                        {p.hubContent?.recap ||
                                            'Revisit the details and shared photos.'}
                                    </p>
                                )}
                                {filter === 'past' &&
                                    !!p.registration?.attended && (
                                        <p className="text-xs text-primary">
                                            {p.registration.attended}{' '}
                                            {p.registration.attended === 1
                                                ? 'colleague'
                                                : 'colleagues'}{' '}
                                            attended
                                        </p>
                                    )}
                                {filter !== 'past' && p.registration?.mine && (
                                    <span className="inline-block rounded-full bg-primary/10 px-3 py-1 text-xs text-primary">
                                        {p.registration.mine === 'going'
                                            ? 'You’re going'
                                            : p.registration.mine === 'waiting'
                                              ? 'On waiting list'
                                              : p.registration.mine === 'maybe'
                                                ? 'Interested'
                                                : 'Not attending'}
                                    </span>
                                )}
                            </div>
                        </Button>
                    );
                })}
            </div>
        </div>
    );
}
