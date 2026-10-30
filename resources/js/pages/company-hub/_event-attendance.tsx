import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { router, usePage } from '@inertiajs/react';
import { Check, Download, UserPlus, Users } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { hubRequest } from './_api';
import type { HubEvent } from './_types';

type Attendance = 'attended' | 'no_show' | null;
type Registration = {
    id: number;
    name: string;
    status: string;
    attendance: Attendance;
    version: number;
    can_offer_place?: boolean;
};
export type Roster = {
    actor_id: number;
    event_id: number;
    edit_version: number;
    registration_version: number;
    date: string;
    can_export: boolean;
    can_record: boolean;
    registrations: Registration[];
};

export function EventAttendance({
    event,
    sampleRoster,
}: {
    event: HubEvent;
    sampleRoster?: Roster;
}) {
    const actorId = Number(
        (usePage().props.auth as { user?: { id: number } })?.user?.id,
    );
    return (
        <AttendanceBody
            key={`${actorId}:${event.id}:${event.extendedProps.editVersion}:${event.extendedProps.cancellation?.cancelled}`}
            event={event}
            actorId={actorId}
            sampleRoster={sampleRoster}
        />
    );
}
function AttendanceBody({
    event,
    actorId,
    sampleRoster,
}: {
    event: HubEvent;
    actorId: number;
    sampleRoster?: Roster;
}) {
    const [open, setOpen] = useState(false);
    const [roster, setRoster] = useState<Roster | null>(sampleRoster ?? null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [query, setQuery] = useState('');
    const [notice, setNotice] = useState('');
    const [offering, setOffering] = useState<Registration | null>(null);
    const pending = useRef(false);
    const offerHeading = useRef<HTMLHeadingElement>(null);
    const offerTrigger = useRef<HTMLButtonElement | null>(null);
    const requestId = useRef(0);
    useEffect(
        () => () => {
            requestId.current++;
        },
        [],
    );
    useEffect(() => {
        if (offering) offerHeading.current?.focus();
    }, [offering]);
    if ((!event.editable && !sampleRoster) || !event.extendedProps.registration)
        return null;
    const date = event.extendedProps.registration.date;
    const url = `/company-hub/events/${event.extendedProps.eventId}/attendance`;
    const confirmed = (result: Roster) => {
        if (
            result.actor_id !== actorId ||
            result.event_id !== event.extendedProps.eventId ||
            result.date !== date ||
            !Array.isArray(result.registrations) ||
            !Number.isInteger(result.edit_version) ||
            result.edit_version < 1 ||
            !Number.isInteger(result.registration_version) ||
            result.registration_version < 1
        )
            throw new Error(
                'This attendance list could not be confirmed for your account and date. Refresh the list.',
            );
        return result;
    };
    const refresh = async () => {
        if (sampleRoster) return;
        if (pending.current) return;
        pending.current = true;
        const id = ++requestId.current;
        setBusy(true);
        setError('');
        setNotice('');
        try {
            const result = await hubRequest<Roster>(
                `${url}?date=${date}`,
                'GET',
            );
            if (requestId.current === id) {
                setRoster(confirmed(result));
                setOffering(null);
            }
        } catch (e) {
            if (requestId.current === id) setError((e as Error).message);
        } finally {
            if (requestId.current === id) {
                setBusy(false);
                pending.current = false;
            }
        }
    };
    const record = async (row: Registration, attendance: Attendance) => {
        if (sampleRoster) return;
        if (pending.current || error || !roster?.can_record) return;
        pending.current = true;
        const id = ++requestId.current;
        setBusy(true);
        setError('');
        setNotice('');
        try {
            const result = await hubRequest<Roster>(url, 'PUT', {
                date,
                registration_id: row.id,
                attendance,
                version: row.version,
            });
            if (requestId.current !== id) return;
            const next = confirmed(result);
            const saved = next.registrations.find((item) => item.id === row.id);
            if (
                saved?.attendance !== attendance ||
                saved.version !== row.version + 1
            )
                throw new Error(
                    'The attendance change could not be confirmed. Refresh the list.',
                );
            setRoster(next);
            router.reload({
                only: ['events', 'selectedEvent'],
                preserveScroll: true,
            });
            setNotice(
                `${row.name}: ${attendance === 'attended' ? 'marked attended' : attendance === 'no_show' ? 'marked did not attend' : 'attendance cleared'}.`,
            );
        } catch (e) {
            if (requestId.current === id) setError((e as Error).message);
        } finally {
            if (requestId.current === id) {
                setBusy(false);
                pending.current = false;
            }
        }
    };
    const keepWaiting = () => {
        if (pending.current) return;
        setOffering(null);
        requestAnimationFrame(() => offerTrigger.current?.focus());
    };
    const offer = async () => {
        if (sampleRoster) return;
        if (pending.current || error || !roster || !offering?.can_offer_place)
            return;
        pending.current = true;
        const id = ++requestId.current;
        setBusy(true);
        setError('');
        setNotice('');
        try {
            const result = await hubRequest<{ roster: Roster }>(
                `/company-hub/events/${event.extendedProps.eventId}/offer-place`,
                'POST',
                {
                    actor_id: actorId,
                    edit_version: roster.edit_version,
                    registration_version: roster.registration_version,
                    date,
                    registration_id: offering.id,
                    version: offering.version,
                },
            );
            if (requestId.current !== id) return;
            const next = confirmed(result.roster);
            const saved = next.registrations.find(
                (row) => row.id === offering.id,
            );
            if (saved?.status !== 'going' || saved.version <= offering.version)
                throw new Error(
                    'The place could not be confirmed. Refresh the list before trying again.',
                );
            setRoster(next);
            setOffering(null);
            setNotice(`${offering.name} now has a confirmed place.`);
            router.reload({
                only: ['events', 'selectedEvent'],
                preserveScroll: true,
            });
        } catch (e) {
            if (requestId.current === id) setError((e as Error).message);
        } finally {
            if (requestId.current === id) {
                pending.current = false;
                setBusy(false);
            }
        }
    };
    const shown =
        roster?.registrations.filter((row) =>
            row.name.toLowerCase().includes(query.toLowerCase()),
        ) ?? [];
    return (
        <section
            className="space-y-4 rounded-xl border p-4"
            aria-label="Event attendance"
        >
            <div className="flex items-center justify-between gap-3">
                <div className="flex items-center gap-2 font-medium">
                    <Users className="size-4 text-primary" /> People &
                    attendance
                </div>
                <Button
                    variant="outline"
                    disabled={busy}
                    onClick={() => {
                        setOpen(!open);
                        if (!open) void refresh();
                    }}
                >
                    {open
                        ? 'Hide attendance'
                        : sampleRoster
                          ? 'View sample attendance'
                          : 'Manage attendance'}
                </Button>
            </div>
            {open && (
                <>
                    <p className="text-sm text-muted-foreground">
                        For this date only. Visible to authorised organisers.
                        Attendance can be recorded once the event starts.
                    </p>
                    <div className="flex flex-wrap gap-2">
                        <Input
                            aria-label="Find a registrant"
                            placeholder="Find a person…"
                            value={query}
                            onChange={(e) => setQuery(e.target.value)}
                        />
                        <Button
                            variant="outline"
                            disabled={busy || !!sampleRoster}
                            onClick={() => void refresh()}
                        >
                            Refresh list
                        </Button>
                        {!sampleRoster &&
                            roster?.can_export &&
                            !busy &&
                            !error && (
                                <Button variant="outline" asChild>
                                    <a
                                        href={`${url}/export?date=${encodeURIComponent(date)}`}
                                    >
                                        <Download className="size-4" />
                                        Export attendance
                                    </a>
                                </Button>
                            )}
                    </div>
                    {busy && (
                        <p
                            role="status"
                            className="text-sm text-muted-foreground"
                        >
                            Updating the people list…
                        </p>
                    )}
                    {roster && (
                        <div className="divide-y">
                            {!shown.length && (
                                <p className="py-5 text-sm text-muted-foreground">
                                    {query
                                        ? 'No people match your search.'
                                        : 'No registrations for this date.'}
                                </p>
                            )}
                            {shown.map((row) => (
                                <div
                                    key={row.id}
                                    className="flex items-center justify-between gap-3 py-3"
                                >
                                    <div>
                                        <p className="text-sm font-medium">
                                            {row.name}
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            {row.attendance === 'attended'
                                                ? 'Attended'
                                                : row.attendance === 'no_show'
                                                  ? 'Did not attend'
                                                  : row.status === 'going'
                                                    ? 'Place confirmed · attendance not recorded'
                                                    : row.status === 'waiting'
                                                      ? 'Waiting list'
                                                      : row.status === 'maybe'
                                                        ? 'Interested'
                                                        : 'Cancelled'}
                                        </p>
                                    </div>
                                    {!sampleRoster && row.can_offer_place && (
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            aria-label={`Offer place to ${row.name}`}
                                            disabled={
                                                busy || !!error || !!offering
                                            }
                                            onClick={(e) => {
                                                offerTrigger.current =
                                                    e.currentTarget;
                                                setOffering(row);
                                            }}
                                        >
                                            <UserPlus className="size-3.5" />
                                            Offer place
                                        </Button>
                                    )}
                                    {!sampleRoster &&
                                        roster.can_record &&
                                        (row.status === 'going' ||
                                            row.attendance) && (
                                            <div className="flex gap-1">
                                                <Button
                                                    size="sm"
                                                    variant={
                                                        row.attendance ===
                                                        'attended'
                                                            ? 'default'
                                                            : 'outline'
                                                    }
                                                    disabled={
                                                        busy ||
                                                        !!error ||
                                                        row.attendance ===
                                                            'attended'
                                                    }
                                                    aria-label={`Mark ${row.name} attended`}
                                                    onClick={() =>
                                                        void record(
                                                            row,
                                                            'attended',
                                                        )
                                                    }
                                                >
                                                    <Check className="size-3.5" />{' '}
                                                    Attended
                                                </Button>
                                                <Button
                                                    size="sm"
                                                    variant={
                                                        row.attendance ===
                                                        'no_show'
                                                            ? 'secondary'
                                                            : 'outline'
                                                    }
                                                    disabled={
                                                        busy ||
                                                        !!error ||
                                                        row.attendance ===
                                                            'no_show'
                                                    }
                                                    aria-label={`Mark ${row.name} did not attend`}
                                                    onClick={() =>
                                                        void record(
                                                            row,
                                                            'no_show',
                                                        )
                                                    }
                                                >
                                                    Did not attend
                                                </Button>
                                                {row.attendance && (
                                                    <Button
                                                        size="sm"
                                                        variant="ghost"
                                                        disabled={
                                                            busy || !!error
                                                        }
                                                        aria-label={`Clear attendance for ${row.name}`}
                                                        onClick={() =>
                                                            void record(
                                                                row,
                                                                null,
                                                            )
                                                        }
                                                    >
                                                        Clear
                                                    </Button>
                                                )}
                                            </div>
                                        )}
                                </div>
                            ))}
                        </div>
                    )}
                    {offering && (
                        <section
                            className="space-y-3 rounded-xl border border-primary/20 bg-primary/5 p-4"
                            aria-label="Confirm waiting-list place"
                            onKeyDown={(e) => {
                                if (e.key === 'Escape') {
                                    e.preventDefault();
                                    keepWaiting();
                                }
                            }}
                        >
                            <h3
                                ref={offerHeading}
                                tabIndex={-1}
                                className="font-semibold"
                            >
                                Confirm a place for {offering.name}?
                            </h3>
                            <p className="text-sm leading-relaxed text-muted-foreground">
                                This gives them the available place and sends
                                the usual event confirmation. The earliest
                                eligible person on the waiting list is served
                                first.
                            </p>
                            <div className="flex gap-2">
                                <Button
                                    variant="outline"
                                    disabled={busy}
                                    onClick={keepWaiting}
                                >
                                    Keep on waiting list
                                </Button>
                                <Button
                                    disabled={busy || !!error}
                                    onClick={() => void offer()}
                                >
                                    Confirm place
                                </Button>
                            </div>
                        </section>
                    )}
                    {notice && (
                        <p role="status" className="text-sm text-primary">
                            {notice}
                        </p>
                    )}
                    {error && (
                        <p role="alert" className="text-sm text-destructive">
                            {error} Refresh the list before trying again.
                        </p>
                    )}
                </>
            )}
        </section>
    );
}
