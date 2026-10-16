import { EventBookingCutoff } from '@/components/hr/calendar/event-booking-cutoff';
import {
    EventWizardDialog,
    type CalendarEventInitial,
} from '@/components/hr/calendar/event-wizard-dialog';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { formatDateOnly, formatDateTime } from '@/lib/datetime';
import { router, usePage } from '@inertiajs/react';
import { CalendarDays, Plus } from 'lucide-react';
import { useEffect, useRef, useState, type ComponentProps } from 'react';
import { hubRequest } from './_api';
import { useHubListControls } from './_reading-navigation';
import { HubSelect } from './_select';

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
> & {
    can_manage_recurring?: boolean;
    can_publish?: boolean;
    can_schedule?: boolean;
    cutoff_supported?: boolean;
    personal_reminders_supported?: boolean;
    actor_id?: number;
};
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
        registration_closes_before_minutes:
            p.registration?.closes_before_minutes,
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
}: {
    event?: HubEvent;
    onOpen?: () => void;
    onSaved?: () => void;
}) {
    return (
        <Button
            variant={event ? 'outline' : 'default'}
            disabled={event?.extendedProps.cancellation?.cancelled}
            onClick={() => {
                onOpen?.();
                const date =
                    event?.extendedProps.occurrenceDate ??
                    event?.extendedProps.registration?.date ??
                    event?.start.slice(0, 10);
                router.visit(
                    event
                        ? '/company-hub/events/' +
                              event.extendedProps.eventId +
                              '?date=' +
                              encodeURIComponent(date ?? '') +
                              '&event_workspace=edit'
                        : '/company-hub?view=events&event_workspace=new',
                    { preserveState: true },
                );
            }}
        >
            {event ? (
                'Edit event details'
            ) : (
                <>
                    <Plus className="size-4" />
                    Create event
                </>
            )}
        </Button>
    );
}

export function EventRegistration({
    event,
    readOnly = false,
}: {
    event: HubEvent;
    readOnly?: boolean;
}) {
    const actorId = Number(
        (usePage().props.auth as { user?: { id: number } })?.user?.id,
    );
    const registration = event.extendedProps.registration;
    return (
        <fieldset disabled={readOnly} className="min-w-0">
            <RegistrationBody
                key={`${actorId}:${event.id}:${event.extendedProps.editVersion}:${registration?.version}:${registration?.mine}:${registration?.personal_reminder?.version}:${registration?.cancelled}`}
                event={event}
                actorId={actorId}
                readOnly={readOnly}
            />
        </fieldset>
    );
}

function RegistrationBody({
    event,
    actorId,
    readOnly,
}: {
    event: HubEvent;
    actorId: number;
    readOnly: boolean;
}) {
    const [registration, setRegistration] = useState(
        event.extendedProps.registration,
    );
    // A fresh notification projection must update delivery copy without resetting
    // an organiser's unsaved settings or accepting a late, older delivery flag.
    const [delivery, setDelivery] = useState({
        source: event.extendedProps.registration,
        allowed:
            event.extendedProps.registration?.personal_reminder?.inapp_allowed,
    });
    const reminderInAppAllowed =
        delivery.source === event.extendedProps.registration
            ? delivery.allowed
            : event.extendedProps.registration?.personal_reminder
                  ?.inapp_allowed;
    const acceptRegistration = (next: typeof registration) => {
        setRegistration(next);
        setDelivery({
            source: event.extendedProps.registration,
            allowed: next?.personal_reminder?.inapp_allowed,
        });
    };
    const [enabled, setEnabled] = useState(!!registration?.enabled);
    const [capacity, setCapacity] = useState(
        registration?.capacity?.toString() ?? '',
    );
    const [cutoff, setCutoff] = useState(
        registration?.closes_before_minutes?.toString() ?? '',
    );
    const [notice, setNotice] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [saved, setSaved] = useState(false);
    const [leaving, setLeaving] = useState(false);
    const leaveTrigger = useRef<HTMLButtonElement | null>(null);
    const leaveHeading = useRef<HTMLHeadingElement>(null);
    const pending = useRef(false);
    const alive = useRef(true);
    useEffect(() => {
        alive.current = true;
        return () => {
            alive.current = false;
        };
    }, []);
    useEffect(() => {
        if (leaving) leaveHeading.current?.focus();
    }, [leaving]);
    if (!registration) return null;
    const cancelled = !!(
        event.extendedProps.cancellation?.cancelled || registration.cancelled
    );
    const closed =
        !registration.enabled ||
        registration.can_respond === false ||
        new Date(registration.closes_at || event.start) <= new Date();
    const hasPlace = ['going', 'waiting', 'maybe'].includes(
        registration.mine ?? '',
    );
    const respond = async (status: string) => {
        if (readOnly || cancelled || pending.current) return;
        pending.current = true;
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
            if (!alive.current) return;
            if (result.registration?.date !== registration.date)
                throw new Error(
                    'This response belongs to another date. Reopen the event to check your registration.',
                );
            acceptRegistration(result.registration);
            setLeaving(false);
            router.reload({
                only: ['events', 'selectedEvent'],
                preserveScroll: true,
            });
        } catch (e) {
            if (alive.current) setError((e as Error).message);
        } finally {
            if (alive.current) {
                pending.current = false;
                setBusy(false);
            }
        }
    };
    const chooseResponse = (status: string, trigger: HTMLButtonElement) => {
        if (readOnly) return;
        if (
            status === 'declined' &&
            ['going', 'waiting'].includes(registration.mine ?? '')
        ) {
            leaveTrigger.current = trigger;
            setLeaving(true);
            setError('');
        } else void respond(status);
    };
    const keepPlace = () => {
        if (pending.current) return;
        setLeaving(false);
        requestAnimationFrame(() => leaveTrigger.current?.focus());
    };
    const reminder = registration.personal_reminder;
    const changeReminder = async (enabled: boolean) => {
        if (readOnly) return;
        if (
            pending.current ||
            cancelled ||
            !actorId ||
            !reminder?.supported ||
            !(enabled ? reminder.can_enable : reminder.can_disable)
        )
            return;
        pending.current = true;
        setBusy(true);
        setError('');
        setNotice('');
        try {
            const result = await hubRequest<{
                actor_id: number;
                event_id: number;
                edit_version: number;
                date: string;
                registration: typeof registration;
            }>(
                `/company-hub/events/${event.extendedProps.eventId}/reminder`,
                'PUT',
                {
                    actor_id: actorId,
                    edit_version: event.extendedProps.editVersion,
                    date: registration.date,
                    reminder_version: reminder.version,
                    enabled,
                },
            );
            if (!alive.current) return;
            if (
                result.actor_id !== actorId ||
                result.event_id !== event.extendedProps.eventId ||
                result.edit_version !== event.extendedProps.editVersion ||
                result.date !== registration.date ||
                result.registration?.date !== registration.date ||
                result.registration.personal_reminder?.enabled !== enabled ||
                result.registration.personal_reminder.version !==
                    reminder.version + 1
            )
                throw new Error(
                    'The reminder response could not be confirmed. Reopen this event to check your saved choice.',
                );
            acceptRegistration(result.registration);
            setNotice(
                enabled
                    ? 'Your day-before reminder is saved.'
                    : 'Your personal reminder is turned off.',
            );
        } catch (e) {
            if (alive.current) setError((e as Error).message);
        } finally {
            if (alive.current) {
                pending.current = false;
                setBusy(false);
            }
        }
    };
    if (cancelled) {
        return (
            <section
                aria-label="Previous registration"
                className="rounded-xl border bg-background/70 p-4"
            >
                <h3 className="text-sm font-semibold">Your earlier response</h3>
                <p className="mt-2 text-sm text-muted-foreground">
                    {registration.mine === 'going'
                        ? 'You were registered for this date.'
                        : registration.mine === 'waiting'
                          ? 'You were on the waiting list.'
                          : registration.mine === 'maybe'
                            ? 'You had marked this date as maybe.'
                            : registration.mine === 'declined'
                              ? 'You had declined this date.'
                              : 'You had not registered for this date.'}
                </p>
                <p className="mt-2 text-xs text-muted-foreground">
                    No action is needed.
                </p>
            </section>
        );
    }
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
                    {registration.capacity !== null &&
                        registration.capacity > 0 && (
                            <div
                                role="progressbar"
                                aria-label="Event places filled"
                                aria-valuenow={Math.min(
                                    registration.going,
                                    registration.capacity,
                                )}
                                aria-valuemin={0}
                                aria-valuemax={registration.capacity}
                                className="h-1.5 overflow-hidden rounded-full bg-primary/10"
                            >
                                <div
                                    className="h-full rounded-full bg-primary"
                                    style={{
                                        width: `${Math.min(100, (registration.going / registration.capacity) * 100)}%`,
                                    }}
                                />
                            </div>
                        )}
                    {registration.attendance && (
                        <p className="text-sm font-medium">
                            Your attendance:{' '}
                            {registration.attendance === 'attended'
                                ? 'attended'
                                : 'did not attend'}
                            .
                        </p>
                    )}
                    {leaving ? (
                        <section
                            className="space-y-3 rounded-xl bg-muted/40 p-3"
                            aria-label="Confirm registration cancellation"
                            data-event-cancellation-open
                            onKeyDown={(e) => {
                                if (e.key === 'Escape') {
                                    e.preventDefault();
                                    e.stopPropagation();
                                    keepPlace();
                                }
                            }}
                        >
                            <h4
                                ref={leaveHeading}
                                tabIndex={-1}
                                className="text-sm font-semibold"
                            >
                                {registration.mine === 'waiting'
                                    ? 'Leave the waiting list?'
                                    : 'Cancel your place?'}
                            </h4>
                            <p className="text-sm text-muted-foreground">
                                {registration.mine === 'waiting'
                                    ? 'You will no longer be offered a place if one becomes available.'
                                    : 'Your place will be available for someone else. You can register again while places are available.'}
                            </p>
                            <div className="flex flex-wrap gap-2">
                                <Button
                                    variant="outline"
                                    disabled={busy}
                                    onClick={keepPlace}
                                >
                                    {registration.mine === 'waiting'
                                        ? 'Stay on list'
                                        : 'Keep my place'}
                                </Button>
                                <Button
                                    variant="destructive"
                                    disabled={busy}
                                    onClick={() => void respond('declined')}
                                >
                                    {busy
                                        ? 'Saving…'
                                        : registration.mine === 'waiting'
                                          ? 'Leave waiting list'
                                          : 'Confirm cancellation'}
                                </Button>
                            </div>
                        </section>
                    ) : closed ? (
                        <div className="space-y-3">
                            <p className="text-sm">
                                Registration has closed for this date.
                            </p>
                            {hasPlace && registration.can_decline !== false && (
                                <Button
                                    ref={leaveTrigger}
                                    variant="outline"
                                    disabled={busy}
                                    onClick={(e) =>
                                        chooseResponse(
                                            'declined',
                                            e.currentTarget,
                                        )
                                    }
                                >
                                    {registration.mine === 'waiting'
                                        ? 'Leave waiting list'
                                        : 'Cancel my place'}
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
                                    registration.mine === 'waiting'
                                        ? 'Leave waiting list'
                                        : registration.mine === 'going'
                                          ? 'Cancel my place'
                                          : 'Can’t attend',
                                ],
                            ].map(([status, label]) => (
                                <Button
                                    key={status}
                                    ref={
                                        status === 'declined'
                                            ? leaveTrigger
                                            : undefined
                                    }
                                    disabled={busy}
                                    variant={
                                        registration.mine === status ||
                                        (status === 'going' &&
                                            registration.mine === 'waiting')
                                            ? 'default'
                                            : 'outline'
                                    }
                                    onClick={(e) =>
                                        chooseResponse(status, e.currentTarget)
                                    }
                                >
                                    {label}
                                </Button>
                            ))}
                        </div>
                    )}
                    {registration.closes_at && !closed && (
                        <p className="text-xs text-muted-foreground">
                            Registration closes{' '}
                            {formatDateTime(registration.closes_at)}.
                        </p>
                    )}
                    {reminder?.supported &&
                        actorId > 0 &&
                        ['going', 'waiting'].includes(
                            registration.mine ?? '',
                        ) && (
                            <div className="space-y-2 border-t pt-4">
                                <label className="flex items-start gap-3 text-sm leading-5">
                                    <Checkbox
                                        className="mt-0.5"
                                        checked={reminder.enabled}
                                        disabled={
                                            busy ||
                                            !(reminder.enabled
                                                ? reminder.can_disable
                                                : reminder.can_enable)
                                        }
                                        onCheckedChange={(checked) =>
                                            void changeReminder(
                                                checked === true,
                                            )
                                        }
                                    />
                                    Remind me the day before
                                </label>
                                <p className="text-xs leading-relaxed text-muted-foreground">
                                    {!reminderInAppAllowed
                                        ? 'In-app event notifications are off. Turn them on in notification settings to receive this reminder.'
                                        : !reminder.enabled &&
                                            !reminder.can_enable
                                          ? 'It is too late to add a day-before reminder for this date.'
                                          : 'A notification here in the app. Your quiet-time settings still apply.'}
                                </p>
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
                                disabled={busy}
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
                            disabled={busy}
                        />
                        {registration.cutoff_supported && actorId > 0 && (
                            <EventBookingCutoff
                                value={cutoff}
                                onChange={setCutoff}
                                disabled={busy}
                            />
                        )}
                        <Button
                            disabled={busy}
                            onClick={async () => {
                                if (readOnly || pending.current || cancelled)
                                    return;
                                pending.current = true;
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
                                            ...(registration.cutoff_supported &&
                                            actorId > 0
                                                ? {
                                                      actor_id: actorId,
                                                      edit_version:
                                                          event.extendedProps
                                                              .editVersion,
                                                      closes_before_minutes:
                                                          cutoff === ''
                                                              ? null
                                                              : Number(cutoff),
                                                  }
                                                : {}),
                                        },
                                    );
                                    if (!alive.current) return;
                                    if (
                                        result.registration?.date !==
                                        registration.date
                                    )
                                        throw new Error(
                                            'These settings belong to another date. Reopen the event.',
                                        );
                                    if (
                                        result.registration.version !==
                                            registration.version + 1 ||
                                        result.registration.enabled !==
                                            enabled ||
                                        result.registration.capacity !==
                                            (capacity
                                                ? Number(capacity)
                                                : null) ||
                                        (registration.cutoff_supported &&
                                            actorId > 0 &&
                                            result.registration
                                                .closes_before_minutes !==
                                                (cutoff === ''
                                                    ? null
                                                    : Number(cutoff)))
                                    )
                                        throw new Error(
                                            'The registration settings could not be confirmed. Reopen the event to check the saved settings.',
                                        );
                                    acceptRegistration(result.registration);
                                    setSaved(true);
                                    router.reload({
                                        only: ['events', 'selectedEvent'],
                                        preserveScroll: true,
                                    });
                                } catch (e) {
                                    if (alive.current)
                                        setError((e as Error).message);
                                } finally {
                                    if (alive.current) {
                                        pending.current = false;
                                        setBusy(false);
                                    }
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
            {notice && (
                <p role="status" className="text-sm text-primary">
                    {notice}
                </p>
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
    const currentYear = Number(
        new Intl.DateTimeFormat('en-NZ', {
            year: 'numeric',
            timeZone: 'Pacific/Auckland',
        }).format(new Date()),
    );
    const [{ query, filter, category, year }, setControls] = useHubListControls(
        'events',
        { query: '', filter: 'all', category: 'all', year: currentYear },
    );
    const setQuery = (query: string) =>
        setControls((previous) => ({ ...previous, query }));
    const setFilter = (filter: string) =>
        setControls((previous) => ({ ...previous, filter }));
    const setCategory = (category: string) =>
        setControls((previous) => ({ ...previous, category }));
    const setYear = (year: number) =>
        setControls((previous) => ({ ...previous, year }));
    const [history, setHistory] = useState<HubEvent[]>([]);
    const historyYear = useRef<number | null>(null);
    const [historyBusy, setHistoryBusy] = useState(false);
    const [historyError, setHistoryError] = useState('');
    const [historyRetry, setHistoryRetry] = useState(0);
    useEffect(() => {
        if (filter !== 'past' || data.demo) return;
        let current = true;
        setHistoryBusy(true);
        setHistoryError('');
        if (historyYear.current !== year) setHistory([]);
        void hubRequest<{ events: HubEvent[] }>(
            `/company-hub/events/history?year=${year}`,
            'GET',
        )
            .then((result) => {
                if (current) {
                    historyYear.current = year;
                    setHistory(result.events);
                }
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
            : [...series.values()].map(
                  (dates) =>
                      dates.find(
                          (item) => !item.extendedProps.cancellation?.cancelled,
                      ) ?? dates[0],
              );
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
                            <Button
                                variant="outline"
                                onClick={() =>
                                    router.visit(
                                        '/company-hub?view=events&event_workspace=drafts',
                                    )
                                }
                            >
                                Event drafts
                            </Button>
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
                <div className="w-44 shrink-0">
                    <HubSelect
                        label="Event category"
                        value={category}
                        onChange={setCategory}
                        options={[
                            { value: 'all', label: 'All categories' },
                            ...[
                                ...new Set(
                                    source
                                        .map(
                                            (event) =>
                                                event.extendedProps.category,
                                        )
                                        .filter(
                                            (value): value is string => !!value,
                                        ),
                                ),
                            ].map((value) => ({ value, label: value })),
                        ]}
                    />
                </div>
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
                    <div className="w-28 shrink-0">
                        <HubSelect
                            id="event-history-year"
                            label="Year"
                            value={String(year)}
                            onChange={(value) => setYear(Number(value))}
                            options={Array.from(
                                { length: currentYear - 1899 },
                                (_, index) => {
                                    const value = String(currentYear - index);
                                    return { value, label: value };
                                },
                            )}
                        />
                    </div>
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
                            data-hub-event-key={event.id}
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
                                {p.cancellation?.cancelled ? (
                                    <span className="inline-flex items-center rounded-full border border-status-warning/30 bg-status-warning-bg px-3 py-1 text-xs font-medium text-status-warning">
                                        Cancelled
                                    </span>
                                ) : (
                                    filter !== 'past' &&
                                    p.registration?.mine && (
                                        <span className="inline-block rounded-full bg-primary/10 px-3 py-1 text-xs text-primary">
                                            {p.registration.mine === 'going'
                                                ? 'You’re going'
                                                : p.registration.mine ===
                                                    'waiting'
                                                  ? 'On waiting list'
                                                  : p.registration.mine ===
                                                      'maybe'
                                                    ? 'Interested'
                                                    : 'Not attending'}
                                        </span>
                                    )
                                )}
                            </div>
                        </Button>
                    );
                })}
            </div>
        </div>
    );
}
