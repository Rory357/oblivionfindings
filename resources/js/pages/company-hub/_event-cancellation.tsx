import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { formatDateTime } from '@/lib/datetime';
import { CalendarX2, Loader2 } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { hubRequest } from './_api';
import type { HubEvent } from './_types';

export type CancellationScope = 'this' | 'following' | 'all';
export type EventCancellationState = {
    cancelled: boolean;
    cancelled_at: string | null;
    reason: string | null;
    actor_id: number | null;
    can_cancel: boolean;
    scopes: CancellationScope[];
};
export type EventCancellationResult = {
    actor_id: number;
    event_id: number;
    edit_version: number;
    date: string;
    scope: CancellationScope;
    cancellation: EventCancellationState;
    registration: NonNullable<HubEvent['extendedProps']['registration']> & {
        cancelled: boolean;
        can_respond: boolean;
        place_reserved: boolean;
    };
};
type CancellableEvent = HubEvent & {
    extendedProps: HubEvent['extendedProps'] & {
        cancellation?: EventCancellationState;
    };
};

const scopeLabels: Record<CancellationScope, string> = {
    this: 'This event only',
    following: 'This and following events',
    all: 'All upcoming events in the series',
};
const scopeDescriptions: Record<CancellationScope, string> = {
    this: 'Keep the other dates as they are.',
    following: 'Cancel from this date onwards. Earlier dates stay as they are.',
    all: 'Cancel upcoming dates. Completed events and attendance stay in history.',
};

export function cancellationEvent(
    events: HubEvent[],
    result: EventCancellationResult,
): HubEvent | undefined {
    return events.find((item) => {
        const props = item.extendedProps;
        return (
            (props.occurrenceDate ?? props.registration?.date) ===
                result.date &&
            (props.eventId === result.event_id ||
                props.recurrenceParentId === result.event_id)
        );
    });
}

export function EventCancelledNotice({
    state,
}: {
    state: EventCancellationState;
}) {
    return (
        <section
            aria-label="Event cancellation"
            className="space-y-3 rounded-2xl border border-status-warning/25 bg-status-warning-bg p-4"
        >
            <div className="flex items-center gap-2 font-semibold">
                <CalendarX2 className="size-5 text-status-warning" />
                This event has been cancelled
            </div>
            <p className="text-sm leading-relaxed">
                Places are no longer reserved. Your earlier response stays in
                the event history.
            </p>
            {state.reason && (
                <p className="text-sm leading-relaxed whitespace-pre-wrap">
                    {state.reason}
                </p>
            )}
        </section>
    );
}

type Props = {
    event: CancellableEvent;
    actorId: number;
    onSaved: (result: EventCancellationResult) => void;
    onBusyChange: (busy: boolean) => void;
};

export function EventCancellation(props: Props) {
    const state = props.event.extendedProps.cancellation;
    if (
        !state?.can_cancel ||
        state.cancelled ||
        !props.actorId ||
        state.actor_id !== props.actorId ||
        !state.scopes.length
    )
        return null;
    return (
        <CancellationForm
            key={`${props.actorId}-${props.event.id}-${props.event.extendedProps.editVersion}-${state.can_cancel}`}
            {...props}
            state={state}
        />
    );
}

function CancellationForm({
    event,
    actorId,
    state,
    onSaved,
    onBusyChange,
}: Props & { state: EventCancellationState }) {
    const [open, setOpen] = useState(false);
    const [scope, setScope] = useState<CancellationScope | ''>(
        state.scopes.length === 1 ? state.scopes[0] : '',
    );
    const [reason, setReason] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const pending = useRef(false);
    const alive = useRef(true);
    const trigger = useRef<HTMLButtonElement>(null);
    const heading = useRef<HTMLHeadingElement>(null);
    useEffect(() => {
        alive.current = true;
        return () => {
            alive.current = false;
            onBusyChange(false);
        };
    }, [onBusyChange]);
    useEffect(() => {
        if (open) heading.current?.focus();
    }, [open]);
    const keep = () => {
        if (pending.current) return;
        setOpen(false);
        setError('');
        requestAnimationFrame(() => trigger.current?.focus());
    };
    const date =
        event.extendedProps.occurrenceDate ??
        event.extendedProps.registration?.date;
    const cancel = async () => {
        if (!scope || !date || pending.current) return;
        pending.current = true;
        setBusy(true);
        setError('');
        onBusyChange(true);
        try {
            const result = await hubRequest<EventCancellationResult>(
                `/company-hub/events/${event.extendedProps.eventId}/cancellation`,
                'PUT',
                {
                    actor_id: actorId,
                    edit_version: event.extendedProps.editVersion,
                    date,
                    scope,
                    reason: reason.trim() || null,
                },
            );
            if (!alive.current) return;
            if (
                result.actor_id !== actorId ||
                !Number.isInteger(result.event_id) ||
                result.event_id < 1 ||
                !Number.isInteger(result.edit_version) ||
                result.edit_version < 1 ||
                (scope !== 'following' &&
                    result.edit_version <= event.extendedProps.editVersion) ||
                result.date !== date ||
                result.scope !== scope ||
                result.cancellation?.actor_id !== actorId ||
                !result.cancellation.cancelled ||
                result.cancellation.can_cancel ||
                result.registration?.date !== date ||
                !result.registration.cancelled ||
                result.registration.can_respond ||
                result.registration.place_reserved
            )
                throw new Error(
                    'The event response could not be confirmed. Reopen the event to check its current status.',
                );
            onSaved(result);
        } catch (failure) {
            if (alive.current)
                setError(
                    failure instanceof Error
                        ? failure.message
                        : 'The event could not be cancelled. Try again.',
                );
        } finally {
            if (alive.current) {
                pending.current = false;
                setBusy(false);
                onBusyChange(false);
            }
        }
    };
    return (
        <section
            className="border-t pt-4"
            aria-label="Manage event cancellation"
            data-event-cancellation-open={open || undefined}
            onKeyDown={(e) => {
                if (e.key === 'Escape' && open) {
                    e.preventDefault();
                    e.stopPropagation();
                    keep();
                }
            }}
        >
            {!open ? (
                <Button
                    ref={trigger}
                    variant="ghost"
                    className="text-destructive hover:text-destructive"
                    onClick={() => setOpen(true)}
                >
                    <CalendarX2 className="size-4" /> Cancel event
                </Button>
            ) : (
                <div className="space-y-4">
                    <div>
                        <h3
                            ref={heading}
                            tabIndex={-1}
                            className="font-semibold"
                        >
                            Cancel this event?
                        </h3>
                        <p className="mt-2 text-sm text-muted-foreground">
                            Registered colleagues will be notified in the app.
                            Past attendance stays in history.
                        </p>
                        <p className="mt-2 text-sm font-medium">
                            {event.title} · {formatDateTime(event.start)}
                        </p>
                    </div>
                    {state.scopes.length > 1 && (
                        <fieldset disabled={busy} className="space-y-2">
                            <legend className="mb-2 text-sm font-medium">
                                Which dates should be cancelled?
                            </legend>
                            {state.scopes.map((value) => (
                                <label
                                    key={value}
                                    className="flex cursor-pointer items-start gap-3 rounded-xl border p-3 has-checked:border-primary has-checked:bg-primary/5"
                                >
                                    <input
                                        type="radio"
                                        name="event-cancellation-scope"
                                        value={value}
                                        checked={scope === value}
                                        onChange={() => setScope(value)}
                                        className="mt-1 accent-primary"
                                    />
                                    <span>
                                        <span className="block text-sm font-medium">
                                            {scopeLabels[value]}
                                        </span>
                                        <span className="mt-1 block text-xs text-muted-foreground">
                                            {scopeDescriptions[value]}
                                        </span>
                                    </span>
                                </label>
                            ))}
                        </fieldset>
                    )}
                    <div className="space-y-2">
                        <Label htmlFor="event-cancellation-reason">
                            A note for colleagues · optional
                        </Label>
                        <Textarea
                            id="event-cancellation-reason"
                            value={reason}
                            disabled={busy}
                            maxLength={500}
                            onChange={(e) => setReason(e.target.value)}
                            placeholder="Let people know what has changed…"
                            className="min-h-24"
                        />
                        <p className="text-xs text-muted-foreground">
                            This note appears with the cancelled event.
                        </p>
                    </div>
                    {error && (
                        <p role="alert" className="text-sm text-destructive">
                            {error}
                        </p>
                    )}
                    <div className="flex flex-wrap gap-2">
                        <Button
                            variant="outline"
                            disabled={busy}
                            onClick={keep}
                        >
                            Keep event
                        </Button>
                        <Button
                            variant="destructive"
                            disabled={busy || !scope || !date}
                            onClick={() => void cancel()}
                        >
                            {busy && (
                                <Loader2 className="size-4 animate-spin" />
                            )}
                            {busy ? 'Cancelling…' : 'Confirm cancellation'}
                        </Button>
                    </div>
                </div>
            )}
        </section>
    );
}
