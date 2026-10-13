import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { router } from '@inertiajs/react';
import { Check, Users } from 'lucide-react';
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
};
type Roster = { can_record: boolean; registrations: Registration[] };

export function EventAttendance({ event }: { event: HubEvent }) {
    const [open, setOpen] = useState(false);
    const [roster, setRoster] = useState<Roster | null>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [query, setQuery] = useState('');
    const [notice, setNotice] = useState('');
    const requestId = useRef(0);
    useEffect(
        () => () => {
            requestId.current++;
        },
        [],
    );
    if (!event.editable || !event.extendedProps.registration) return null;
    const date = event.extendedProps.registration.date;
    const url = `/company-hub/events/${event.extendedProps.eventId}/attendance`;
    const refresh = async () => {
        const id = ++requestId.current;
        setBusy(true);
        setError('');
        setNotice('');
        try {
            const result = await hubRequest<Roster>(
                `${url}?date=${date}`,
                'GET',
            );
            if (requestId.current === id) setRoster(result);
        } catch (e) {
            if (requestId.current === id) setError((e as Error).message);
        } finally {
            if (requestId.current === id) setBusy(false);
        }
    };
    const record = async (row: Registration, attendance: Attendance) => {
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
            setRoster(result);
            router.reload({ only: ['events'], preserveScroll: true });
            setNotice(
                `${row.name}: ${attendance === 'attended' ? 'marked attended' : attendance === 'no_show' ? 'marked did not attend' : 'attendance cleared'}.`,
            );
        } catch (e) {
            if (requestId.current === id) setError((e as Error).message);
        } finally {
            if (requestId.current === id) setBusy(false);
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
                    {open ? 'Hide attendance' : 'Manage attendance'}
                </Button>
            </div>
            {open && (
                <>
                    <p className="text-sm text-muted-foreground">
                        For this date only. Visible to authorised organisers.
                        Attendance can be recorded once the event starts.
                    </p>
                    <div className="flex gap-2">
                        <Input
                            aria-label="Find a registrant"
                            placeholder="Find a person…"
                            value={query}
                            onChange={(e) => setQuery(e.target.value)}
                        />
                        <Button
                            variant="outline"
                            disabled={busy}
                            onClick={() => void refresh()}
                        >
                            Refresh list
                        </Button>
                    </div>
                    {busy && (
                        <p
                            role="status"
                            className="text-sm text-muted-foreground"
                        >
                            Updating attendance…
                        </p>
                    )}
                    {roster && (
                        <div className="divide-y rounded-lg border px-3">
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
                                    {roster.can_record &&
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
                                                        disabled={busy}
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
