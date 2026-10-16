import { Button } from '@/components/ui/button';
import {
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { formatDateOnly, formatDateTime } from '@/lib/datetime';
import { Link, router } from '@inertiajs/react';
import { CalendarDays, CalendarPlus, MapPin } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { HubBookmarkButton } from './_bookmarks';
import { EventAttachments } from './_event-attachments';
import { EventAttendance } from './_event-attendance';
import {
    EventCancellation,
    EventCancelledNotice,
    cancellationEvent,
    type EventCancellationResult,
} from './_event-cancellation';
import { EventStory } from './_event-story';
import { EventEditorButton, EventRegistration } from './_events';
import { HubSelect } from './_select';
import type { HubEvent, HubProps } from './_types';

export function EventBody({
    event: initialEvent,
    bookmarks,
    preview,
    events,
    onSelect,
    onEdited,
    actorId,
    commandBusy,
    onCommandBusy,
    page = false,
    onRecognise,
    sampleRoster,
    sample = false,
}: {
    event: HubEvent;
    bookmarks: HubProps['bookmarks'];
    preview: boolean;
    events: HubEvent[];
    onSelect?: (event: HubEvent) => void;
    onEdited: () => void;
    actorId: number;
    commandBusy: boolean;
    onCommandBusy: (busy: boolean) => void;
    page?: boolean;
    onRecognise?: () => void;
    sampleRoster?: import('./_event-attendance').Roster;
    sample?: boolean;
}) {
    const [saving, setSaving] = useState(false);
    const [reminder, setReminder] = useState('15');
    const [confirmed, setConfirmed] = useState<EventCancellationResult | null>(
        null,
    );
    const [refreshError, setRefreshError] = useState('');
    const [refreshing, setRefreshing] = useState(false);
    const alive = useRef(true);
    useEffect(() => {
        alive.current = true;
        return () => {
            alive.current = false;
        };
    }, []);
    const event = confirmed
        ? {
              ...initialEvent,
              extendedProps: {
                  ...initialEvent.extendedProps,
                  eventId: confirmed.event_id,
                  editVersion: confirmed.edit_version,
                  cancellation: confirmed.cancellation,
                  registration: confirmed.registration,
              },
          }
        : initialEvent;
    const props = event.extendedProps;
    const cancelled = !!props.cancellation?.cancelled;
    const past = props.hubContent?.past ?? new Date(event.end) < new Date();
    const cover = props.hubContent
        ? props.hubContent.cover
        : props.attachments?.find((file) => file.mime?.startsWith('image/'));
    const seriesId =
        initialEvent.extendedProps.recurrenceParentId ??
        initialEvent.extendedProps.eventId;
    const dates = events.filter(
        (item) =>
            (item.extendedProps.recurrenceParentId ??
                item.extendedProps.eventId) === seriesId,
    );
    const saved = bookmarks.some(
        (item) => item.subject_type === 'event' && item.subject_id === seriesId,
    );
    const canRsvp =
        !preview &&
        !past &&
        !cancelled &&
        !props.registration?.enabled &&
        props.audienceType === 'people' &&
        props.attendeeUserIds?.includes(actorId);
    const refreshCancellation = (result: EventCancellationResult) => {
        setRefreshError('');
        setRefreshing(true);
        let refreshed = false;
        const options: Parameters<typeof router.reload>[0] = {
            only: ['events', 'selectedEvent'],
            preserveScroll: true,
            onSuccess: (response) => {
                if (!alive.current) return;
                const auth = response.props.auth as
                    | { user?: { id: number } }
                    | undefined;
                const fresh = cancellationEvent(
                    [
                        ...(response.props.selectedEvent
                            ? [response.props.selectedEvent as HubEvent]
                            : []),
                        ...((response.props.events ?? []) as HubEvent[]),
                    ],
                    result,
                );
                if (
                    auth?.user?.id === actorId &&
                    fresh?.extendedProps.cancellation?.cancelled
                ) {
                    refreshed = true;
                    // Page navigation installs the canonical selectedEvent itself.
                    if (!page) onSelect?.(fresh);
                }
            },
            onFinish: () => {
                if (!alive.current) return;
                setRefreshing(false);
                if (!refreshed)
                    setRefreshError(
                        'Cancellation is saved. Refresh the event list to load its latest details.',
                    );
            },
        };
        if (page) {
            router.visit(
                `/company-hub/events/${result.event_id}?date=${encodeURIComponent(result.date)}`,
                {
                    ...options,
                    preserveState: true,
                },
            );
        } else router.reload(options);
    };
    return (
        <>
            {page ? (
                <header className="space-y-2">
                    <p className="text-xs font-semibold tracking-[0.16em] text-primary uppercase">
                        {past ? 'Shared moments' : 'Make a little time'}
                    </p>
                    <h1 className="text-3xl leading-tight font-semibold tracking-tight">
                        {event.title}
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Organised by {props.createdBy || 'your company'}
                        {props.recurring ? ' · Recurring event' : ''}
                    </p>
                </header>
            ) : (
                <DialogHeader className="pr-8">
                    <p className="text-xs font-semibold tracking-[0.16em] text-primary uppercase">
                        {past ? 'Shared moments' : 'Make a little time'}
                    </p>
                    <DialogTitle className="text-2xl leading-tight">
                        {event.title}
                    </DialogTitle>
                    <DialogDescription>
                        Organised by {props.createdBy || 'your company'}
                        {props.recurring ? ' · Recurring event' : ''}
                    </DialogDescription>
                </DialogHeader>
            )}
            {!past && dates.length > 1 && onSelect && (
                <div className="flex items-center gap-4 rounded-xl border bg-muted/20 px-4 py-3">
                    <Label htmlFor="event-date-choice" className="shrink-0">
                        Choose a date
                    </Label>
                    <div className="min-w-0 flex-1">
                        <HubSelect
                            id="event-date-choice"
                            label="Choose a date"
                            value={initialEvent.id}
                            disabled={commandBusy || !!confirmed}
                            onChange={(value) => {
                                const selected = dates.find(
                                    (item) => item.id === value,
                                );
                                if (selected) onSelect(selected);
                            }}
                            options={dates.map((item) => ({
                                value: item.id,
                                label: `${formatDateTime(item.start)}${
                                    item.extendedProps.cancellation?.cancelled
                                        ? ' · Cancelled'
                                        : item.extendedProps.registration
                                                ?.mine === 'going'
                                          ? ' · You’re going'
                                          : item.extendedProps.registration
                                                  ?.mine === 'waiting'
                                            ? ' · Waiting list'
                                            : ''
                                }`,
                            }))}
                        />
                    </div>
                </div>
            )}
            <div className="grid grid-cols-[minmax(0,1fr)_310px] items-start gap-6 pt-2">
                <div className="min-w-0 space-y-5">
                    {cover && (
                        <img
                            className="aspect-[16/9] w-full rounded-2xl object-cover"
                            alt={props.hubContent?.cover?.alt ?? ''}
                            src={cover.url}
                        />
                    )}
                    <section className="space-y-3 px-1">
                        <h3 className="text-lg font-semibold">
                            {past
                                ? 'About this gathering'
                                : 'What to look forward to'}
                        </h3>
                        <p className="text-sm leading-7 whitespace-pre-wrap">
                            {props.description ||
                                'More details will be shared by the organiser.'}
                        </p>
                    </section>
                    <EventStory
                        event={event}
                        onRecognise={
                            !preview && !commandBusy && !saving && !refreshing
                                ? onRecognise
                                : undefined
                        }
                    />
                    <EventAttachments
                        key={`${actorId}:${event.id}:${props.editVersion}`}
                        files={props.attachments ?? []}
                    />
                    {(!preview || sampleRoster) && props.registration && (
                        <EventAttendance
                            key={`${actorId}-${event.id}-${cancelled}`}
                            event={event}
                            sampleRoster={preview ? sampleRoster : undefined}
                        />
                    )}
                </div>
                <aside
                    aria-label="Event invitation"
                    className="sticky top-0 space-y-5 rounded-2xl border bg-muted/25 p-5"
                >
                    <div>
                        <p className="text-xs font-semibold tracking-[0.14em] text-muted-foreground uppercase">
                            {cancelled
                                ? 'Event update'
                                : past
                                  ? 'A shared moment'
                                  : 'You’re invited'}
                        </p>
                        <div className="mt-4 flex items-start gap-3">
                            <span className="rounded-xl border bg-background p-2.5 text-primary">
                                <CalendarDays className="size-5" />
                            </span>
                            <div className="min-w-0 text-sm">
                                <p className="font-semibold">
                                    {event.allDay
                                        ? formatDateOnly(
                                              event.start.slice(0, 10),
                                          )
                                        : formatDateTime(event.start)}
                                </p>
                                <p className="mt-1 text-muted-foreground">
                                    {event.allDay
                                        ? 'All day'
                                        : `Until ${formatDateTime(event.end)}`}
                                </p>
                            </div>
                        </div>
                        {props.location && (
                            <p className="mt-4 flex items-start gap-2 text-sm text-muted-foreground">
                                <MapPin className="mt-0.5 size-4 shrink-0" />
                                {props.location}
                            </p>
                        )}
                    </div>
                    {cancelled && props.cancellation && (
                        <EventCancelledNotice state={props.cancellation} />
                    )}
                    {confirmed && refreshing && (
                        <p role="status" className="text-sm">
                            Event cancelled. Refreshing the calendar…
                        </p>
                    )}
                    {refreshError && confirmed && (
                        <div role="status" className="space-y-2 text-sm">
                            <p>{refreshError}</p>
                            <Button
                                variant="outline"
                                disabled={refreshing}
                                onClick={() => refreshCancellation(confirmed)}
                            >
                                Refresh event
                            </Button>
                        </div>
                    )}
                    {(!preview || sample) && !past && props.registration && (
                        <fieldset
                            disabled={commandBusy || preview}
                            className="min-w-0"
                        >
                            <EventRegistration
                                key={`${actorId}-${event.id}-${props.registration.version}-${props.registration.going}-${props.registration.waiting}-${props.registration.mine}-${props.registration.attendance}-${cancelled}`}
                                event={event}
                                readOnly={preview}
                            />
                        </fieldset>
                    )}
                    {canRsvp && (
                        <fieldset
                            disabled={commandBusy || saving}
                            className="space-y-3"
                        >
                            <legend className="mb-2 text-sm font-medium">
                                Will you join us?
                            </legend>
                            {props.recurring && (
                                <p className="text-xs text-muted-foreground">
                                    Your response applies to the event series.
                                </p>
                            )}
                            <div className="flex flex-wrap gap-2">
                                {[
                                    { status: 'yes', label: 'I’m going' },
                                    { status: 'maybe', label: 'Maybe' },
                                    { status: 'no', label: 'Can’t attend' },
                                ].map((option) => (
                                    <Button
                                        key={option.status}
                                        variant={
                                            props.myRsvp === option.status
                                                ? 'default'
                                                : 'outline'
                                        }
                                        onClick={() => {
                                            if (saving || commandBusy) return;
                                            setSaving(true);
                                            router.post(
                                                `/company-hub/events/${props.eventId}/rsvp`,
                                                { status: option.status },
                                                {
                                                    preserveScroll: true,
                                                    onFinish: () => {
                                                        if (alive.current)
                                                            setSaving(false);
                                                    },
                                                },
                                            );
                                        }}
                                    >
                                        {option.label}
                                    </Button>
                                ))}
                            </div>
                        </fieldset>
                    )}
                    {!preview && props.registration && (
                        <div className="space-y-3 border-t pt-4">
                            {!cancelled && (
                                <div className="space-y-2">
                                    <Label htmlFor="event-reminder">
                                        Calendar reminder
                                    </Label>
                                    <HubSelect
                                        id="event-reminder"
                                        label="Calendar reminder"
                                        value={reminder}
                                        onChange={setReminder}
                                        options={[
                                            { value: '', label: 'None' },
                                            {
                                                value: '15',
                                                label: '15 minutes before',
                                            },
                                            {
                                                value: '60',
                                                label: '1 hour before',
                                            },
                                            {
                                                value: '1440',
                                                label: '1 day before',
                                            },
                                        ]}
                                    />
                                </div>
                            )}
                            <Button
                                variant="outline"
                                className="w-full justify-start"
                                asChild
                            >
                                <a
                                    href={`/company-hub/events/${props.eventId}/calendar?date=${props.registration.date}${!cancelled && reminder ? `&reminder=${reminder}` : ''}`}
                                >
                                    <CalendarPlus className="size-4" />
                                    {cancelled
                                        ? 'Download cancelled date'
                                        : 'Add this date to my calendar'}
                                </a>
                            </Button>
                            {props.recurring && (
                                <Button
                                    variant="ghost"
                                    className="w-full justify-start"
                                    asChild
                                >
                                    <a
                                        href={`/company-hub/events/${props.eventId}/calendar?date=${props.registration.date}&scope=series${!cancelled && reminder ? `&reminder=${reminder}` : ''}`}
                                    >
                                        Download whole series
                                    </a>
                                </Button>
                            )}
                            <p className="text-xs leading-relaxed text-muted-foreground">
                                {cancelled
                                    ? 'The calendar file marks this date as cancelled. '
                                    : 'Your calendar app handles the reminder. '}
                                Downloaded copies do not update automatically.
                            </p>
                        </div>
                    )}
                    {!preview && (
                        <EventCancellation
                            event={event}
                            actorId={actorId}
                            onBusyChange={onCommandBusy}
                            onSaved={(result) => {
                                setConfirmed(result);
                                refreshCancellation(result);
                            }}
                        />
                    )}
                </aside>
            </div>
            {!preview && (
                <div className="flex items-center justify-between gap-3 border-t pt-4">
                    <fieldset disabled={commandBusy} className="flex gap-2">
                        <HubBookmarkButton
                            subject={{
                                subject_type: 'event',
                                subject_id: seriesId,
                            }}
                            title={event.title}
                            saved={saved}
                            disabled={commandBusy}
                            saveLabel="Save event"
                            savedLabel="Remove saved event"
                        />
                        <Button variant="ghost" asChild>
                            <Link href="/my-calendar">Open my calendar</Link>
                        </Button>
                    </fieldset>
                    {event.editable && !cancelled && !commandBusy && (
                        <EventEditorButton event={event} onSaved={onEdited} />
                    )}
                </div>
            )}
        </>
    );
}
