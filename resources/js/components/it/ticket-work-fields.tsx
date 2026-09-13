import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { formatDateTime } from '@/lib/datetime';
import axios from 'axios';
import { Pause, Play, Plus, Square, Trash2 } from 'lucide-react';
import { useEffect, useId, useState } from 'react';
import {
    newWorkPeriod,
    readWorkNote,
    workLocal,
    type TicketWork,
    type WorkNote,
    type WorkPeriod,
    type WorkPerson,
} from './ticket-work-types';

export function WorkField({
    label,
    children,
}: {
    label: string;
    children: React.ReactNode;
}) {
    return (
        <label className="grid gap-1.5 text-sm font-medium">
            {label}
            {children}
        </label>
    );
}
export function WorkSelect({
    value,
    onChange,
    options,
    label,
}: {
    value: string;
    onChange: (value: string) => void;
    options: [string, string][];
    label: string;
}) {
    return (
        <WorkField label={label}>
            <select
                className="frontline-focus min-h-11 rounded-md border border-input bg-background px-3 text-sm"
                value={value}
                onChange={(e) => onChange(e.target.value)}
            >
                {options.map(([id, title]) => (
                    <option key={id} value={id}>
                        {title}
                    </option>
                ))}
            </select>
        </WorkField>
    );
}

/** Query only the ticket-scoped directory; stale search responses cannot replace newer results. */
export function TicketWorkPersonPicker({
    ticketId,
    kind = 'technician',
    label,
    selected,
    onSelect,
    startsAt,
    endsAt,
    exceptBookingId,
}: {
    ticketId: number;
    kind?: 'technician' | 'user';
    label: string;
    selected?: WorkPerson | null;
    onSelect: (person: WorkPerson) => void;
    startsAt?: string;
    endsAt?: string;
    exceptBookingId?: number;
}) {
    const id = useId();
    const [query, setQuery] = useState('');
    const [open, setOpen] = useState(false);
    const [options, setOptions] = useState<WorkPerson[]>([]);
    const [message, setMessage] = useState('');
    const [loading, setLoading] = useState(false);
    useEffect(() => {
        if (!open) return;
        const abort = new AbortController();
        const timer = setTimeout(async () => {
            setLoading(true);
            setMessage('');
            try {
                const response = await axios.get(
                    `/it/tickets/${ticketId}/work/people`,
                    {
                        params: {
                            kind,
                            q: query,
                            ...(startsAt && endsAt
                                ? {
                                      starts_at: startsAt,
                                      ends_at: endsAt,
                                      except_booking_id: exceptBookingId,
                                  }
                                : {}),
                        },
                        signal: abort.signal,
                    },
                );
                if (!abort.signal.aborted) setOptions(response.data.options);
            } catch {
                if (!abort.signal.aborted) {
                    setOptions([]);
                    setMessage(
                        'People could not be checked. Change the search or try again.',
                    );
                }
            } finally {
                if (!abort.signal.aborted) setLoading(false);
            }
        }, 200);
        return () => {
            abort.abort();
            clearTimeout(timer);
        };
    }, [ticketId, kind, query, open, startsAt, endsAt, exceptBookingId]);
    return (
        <div className="space-y-2">
            <label className="text-sm font-medium" htmlFor={id}>
                {label}
            </label>
            {selected && (
                <div className="text-sm">
                    {selected.name}
                    {selected.email ? ` · ${selected.email}` : ''}
                </div>
            )}
            <Input
                id={id}
                role="combobox"
                aria-expanded={open}
                aria-controls={`${id}-options`}
                aria-autocomplete="list"
                value={query}
                onFocus={() => setOpen(true)}
                onChange={(e) => {
                    setQuery(e.target.value);
                    setOpen(true);
                }}
                onKeyDown={(e) => {
                    if (e.key === 'Escape') setOpen(false);
                }}
                placeholder="Search name, email or work phone"
            />
            {open && (
                <Card
                    id={`${id}-options`}
                    className="max-h-56 overflow-y-auto rounded-lg border border-border bg-card p-1"
                    aria-label={`${label} results`}
                >
                    {loading ? (
                        <p className="p-3 text-sm" role="status">
                            Checking people and availability…
                        </p>
                    ) : (
                        options.map((person) => (
                            <Button
                                variant="ghost"
                                key={person.id}
                                type="button"
                                className="frontline-focus flex min-h-11 w-full flex-col items-start rounded-md px-3 py-2 text-left hover:bg-muted disabled:opacity-60"
                                disabled={person.busy === true}
                                onClick={() => {
                                    onSelect(person);
                                    setOpen(false);
                                    setQuery(person.name);
                                }}
                            >
                                <span>
                                    {person.name}
                                    {person.busy === true
                                        ? ' · Unavailable'
                                        : person.busy === false
                                          ? ' · Available'
                                          : ''}
                                </span>
                                <span className="text-xs text-muted-foreground">
                                    {person.email}
                                    {person.work_phone
                                        ? ` · ${person.work_phone}`
                                        : ''}
                                </span>
                            </Button>
                        ))
                    )}
                    {!loading && (message || options.length === 0) && (
                        <p className="p-3 text-sm" role="status">
                            {message || 'No eligible people match this search.'}
                        </p>
                    )}
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={() => setOpen(false)}
                    >
                        Close results
                    </Button>
                </Card>
            )}
        </div>
    );
}

export function TicketWorkPeriodFields({
    value,
    onChange,
    index = 0,
}: {
    value: WorkPeriod;
    onChange: (period: WorkPeriod) => void;
    index?: number;
}) {
    const [exactTime, setExactTime] = useState(false);
    return (
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <WorkField label={`Started · entry ${index + 1}`}>
                <Input
                    type={exactTime ? 'text' : 'datetime-local'}
                    placeholder={
                        exactTime ? '2026-04-05T02:30:00+13:00' : undefined
                    }
                    value={
                        exactTime ? value.starts_at : workLocal(value.starts_at)
                    }
                    onChange={(e) =>
                        onChange({ ...value, starts_at: e.target.value })
                    }
                />
            </WorkField>
            <WorkField label={`Ended · entry ${index + 1}`}>
                <Input
                    type={exactTime ? 'text' : 'datetime-local'}
                    placeholder={
                        exactTime ? '2026-04-05T02:30:00+12:00' : undefined
                    }
                    value={
                        exactTime
                            ? value.ends_at
                            : value.ends_at
                              ? workLocal(value.ends_at)
                              : ''
                    }
                    onChange={(e) =>
                        onChange({ ...value, ends_at: e.target.value })
                    }
                />
            </WorkField>
            <WorkField label="Breaks (minutes)">
                <Input
                    type="number"
                    min={0}
                    max={1439}
                    value={value.break_minutes}
                    onChange={(e) =>
                        onChange({
                            ...value,
                            break_minutes: Number(e.target.value),
                        })
                    }
                />
            </WorkField>
            <WorkSelect
                label="Work type"
                value={value.work_type}
                options={[
                    ['remote', 'Remote'],
                    ['onsite', 'Onsite'],
                    ['travel', 'Travel'],
                ]}
                onChange={(type) =>
                    onChange({
                        ...value,
                        work_type: type as WorkPeriod['work_type'],
                    })
                }
            />
            <label className="flex min-h-11 items-center gap-2 text-sm">
                <input
                    type="checkbox"
                    checked={value.after_hours}
                    onChange={(e) =>
                        onChange({ ...value, after_hours: e.target.checked })
                    }
                />
                Work done after hours
            </label>
            <label className="flex min-h-11 items-center gap-2 text-sm">
                <input
                    type="checkbox"
                    checked={exactTime}
                    onChange={(event) => setExactTime(event.target.checked)}
                />
                Enter exact times with UTC offsets
            </label>
        </div>
    );
}

export function TicketWorkFields({
    ticketId,
    actorId,
    value,
    onChange,
    disabled,
    work,
    internal,
}: {
    ticketId: number;
    actorId: number;
    value: string;
    onChange: (value: string) => void;
    disabled: boolean;
    work: TicketWork;
    internal: boolean;
}) {
    const note = readWorkNote(value);
    const update = (changes: Partial<WorkNote>) =>
        onChange(JSON.stringify({ ...note, ...changes }));
    const periods = note.periods ?? [];
    const [splitMessage, setSplitMessage] = useState('');
    const [splitting, setSplitting] = useState(false);
    const [tick, setTick] = useState(Date.now());
    useEffect(() => {
        if (!note.timer?.started) return;
        const interval = setInterval(() => setTick(Date.now()), 1000);
        return () => clearInterval(interval);
    }, [note.timer?.started]);
    const timer = note.timer;
    const timerPause = (stop: boolean) => {
        const saved = [...(timer?.periods ?? [])];
        if (timer?.started)
            saved.push({
                starts_at: new Date(timer.started).toISOString(),
                ends_at: new Date().toISOString(),
                break_minutes: 0,
                work_type: 'remote',
                after_hours: false,
            });
        update(
            stop
                ? { periods: [...periods, ...saved], timer: undefined }
                : { timer: { started: null, periods: saved } },
        );
    };
    const split = async () => {
        if (periods.length !== 1 || splitting) return;
        setSplitting(true);
        setSplitMessage('');
        try {
            const result = await axios.post(
                `/it/tickets/${ticketId}/work/split`,
                periods[0],
            );
            update({ periods: result.data.periods });
            setSplitMessage(
                `Review the suggested flags before saving. ${result.data.allocated_break_minutes ?? 0} break minutes have been shared across the entries; adjust them to match when breaks were taken.`,
            );
        } catch (error) {
            setSplitMessage(workError(error));
        } finally {
            setSplitting(false);
        }
    };
    const recipients =
        note.recipient_user_ids ??
        (work.requester && work.requester.id !== actorId
            ? [work.requester.id]
            : []);
    const follow = note.follow_up;
    return (
        <fieldset
            disabled={disabled || splitting}
            className="space-y-4 rounded-xl border border-border bg-muted/30 p-4"
        >
            <legend className="px-1 text-sm font-semibold">
                Time, status and next action
            </legend>
            <p className="text-xs text-muted-foreground">
                Actual work stays internal. All times use{' '}
                {work.timezone ?? 'Pacific/Auckland'}. Use the end date for
                overnight entries.
            </p>
            <div className="flex flex-wrap gap-2">
                {!timer && (
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() =>
                            update({
                                timer: { started: Date.now(), periods: [] },
                            })
                        }
                    >
                        <Play className="size-4" />
                        Start timer
                    </Button>
                )}
                {timer && (
                    <>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() =>
                                timer.started
                                    ? timerPause(false)
                                    : update({
                                          timer: {
                                              ...timer,
                                              started: Date.now(),
                                          },
                                      })
                            }
                        >
                            {timer.started ? (
                                <Pause className="size-4" />
                            ) : (
                                <Play className="size-4" />
                            )}
                            {timer.started ? 'Pause' : 'Resume'}
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => timerPause(true)}
                        >
                            <Square className="size-4" />
                            Stop and review time
                        </Button>
                        <span role="status" className="self-center text-sm">
                            {timer.started
                                ? `Running · ${Math.floor((tick - timer.started) / 60000)} min since resume`
                                : 'Paused · paused time is excluded'}
                        </span>
                    </>
                )}
                <Button
                    type="button"
                    variant="outline"
                    disabled={periods.length >= 48}
                    onClick={() =>
                        update({ periods: [...periods, newWorkPeriod()] })
                    }
                >
                    <Plus className="size-4" />
                    Add time entry
                </Button>
            </div>
            {timer && (
                <p className="text-sm text-muted-foreground">
                    Stop the timer before saving this note. Start and pause
                    checkpoints are included in your draft.
                </p>
            )}
            {periods.map((period, index) => (
                <div
                    key={index}
                    className="space-y-2 border-t border-border pt-3"
                >
                    <TicketWorkPeriodFields
                        value={period}
                        index={index}
                        onChange={(changed) =>
                            update({
                                periods: periods.map((p, i) =>
                                    i === index ? changed : p,
                                ),
                            })
                        }
                    />
                    <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        onClick={() =>
                            update({
                                periods: periods.filter((_, i) => i !== index),
                            })
                        }
                    >
                        <Trash2 className="size-4" />
                        Remove entry {index + 1}
                    </Button>
                </div>
            ))}
            {periods.length > 0 && (
                <>
                    <TicketWorkPersonPicker
                        ticketId={ticketId}
                        label="Technician who did the work"
                        selected={{
                            id: note.technician_user_id ?? actorId,
                            name: note.technician_name ?? 'You',
                        }}
                        onSelect={(person) =>
                            update({
                                technician_user_id: person.id,
                                technician_name: person.name,
                                booking_id: null,
                            })
                        }
                    />
                    <WorkSelect
                        label="Complete an accepted booking"
                        value={note.booking_id ? String(note.booking_id) : ''}
                        onChange={(id) =>
                            update({ booking_id: id ? Number(id) : null })
                        }
                        options={[
                            ['', 'No linked booking'],
                            ...(work.bookings ?? [])
                                .filter(
                                    (b) =>
                                        b.status === 'accepted' &&
                                        b.technician_user_id ===
                                            (note.technician_user_id ??
                                                actorId),
                                )
                                .map((b): [string, string] => [
                                    String(b.id),
                                    `${formatDateTime(b.starts_at)} · ${b.details.brief}`,
                                ]),
                        ]}
                    />
                    <Button
                        type="button"
                        variant="outline"
                        disabled={periods.length !== 1 || !periods[0].ends_at}
                        onClick={() => void split()}
                    >
                        Suggest after-hours split
                    </Button>
                    {splitMessage && (
                        <p role="status" className="text-sm">
                            {splitMessage}
                        </p>
                    )}
                    <label className="flex min-h-11 items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            checked={note.require_approval ?? false}
                            onChange={(e) =>
                                update({ require_approval: e.target.checked })
                            }
                        />
                        Require a time review before resolution
                    </label>
                    {(note.require_approval ||
                        (work.details?.review_after_hours &&
                            periods.some((p) => p.after_hours))) && (
                        <TicketWorkPersonPicker
                            ticketId={ticketId}
                            label="Assigned time reviewer"
                            selected={
                                note.approver_user_id
                                    ? {
                                          id: note.approver_user_id,
                                          name:
                                              note.approver_name ??
                                              'Selected reviewer',
                                      }
                                    : null
                            }
                            onSelect={(person) =>
                                update({
                                    approver_user_id: person.id,
                                    approver_name: person.name,
                                })
                            }
                        />
                    )}
                </>
            )}
            <details>
                <summary className="frontline-focus cursor-pointer py-2 text-sm font-semibold">
                    Status and follow-up
                </summary>
                <div className="space-y-3 pt-2">
                    <WorkSelect
                        label="Ticket status after this note"
                        value={note.status ?? ''}
                        onChange={(status) => update({ status })}
                        options={[
                            ['', 'Keep current status'],
                            ['in_progress', 'In progress'],
                            ['waiting', 'Waiting'],
                            ['resolved', 'Resolved'],
                        ]}
                    />
                    <label className="flex min-h-11 items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            checked={!!follow}
                            onChange={(e) =>
                                update({
                                    follow_up: e.target.checked
                                        ? {
                                              owner_user_id: actorId,
                                              owner_name: 'You',
                                              due_at: '',
                                              action: '',
                                              waiting_party: 'requester',
                                              reason: '',
                                          }
                                        : undefined,
                                })
                            }
                        />
                        Set the next action and follow-up
                    </label>
                    {follow && (
                        <>
                            <TicketWorkPersonPicker
                                ticketId={ticketId}
                                label="Follow-up owner"
                                selected={{
                                    id: follow.owner_user_id,
                                    name: follow.owner_name ?? 'Selected owner',
                                }}
                                onSelect={(person) =>
                                    update({
                                        follow_up: {
                                            ...follow,
                                            owner_user_id: person.id,
                                            owner_name: person.name,
                                        },
                                    })
                                }
                            />
                            <WorkField label="Follow-up due">
                                <Input
                                    type="datetime-local"
                                    value={follow.due_at}
                                    onChange={(e) =>
                                        update({
                                            follow_up: {
                                                ...follow,
                                                due_at: e.target.value,
                                            },
                                        })
                                    }
                                />
                            </WorkField>
                            <WorkField label="Next action">
                                <Textarea
                                    value={follow.action}
                                    onChange={(e) =>
                                        update({
                                            follow_up: {
                                                ...follow,
                                                action: e.target.value,
                                            },
                                        })
                                    }
                                />
                            </WorkField>
                            <WorkSelect
                                label="Waiting on"
                                value={follow.waiting_party}
                                onChange={(party) =>
                                    update({
                                        follow_up: {
                                            ...follow,
                                            waiting_party: party,
                                        },
                                    })
                                }
                                options={[
                                    'requester',
                                    'vendor',
                                    'approver',
                                    'team',
                                    'change',
                                    'other',
                                ].map((p) => [
                                    p,
                                    p[0].toUpperCase() + p.slice(1),
                                ])}
                            />
                            <WorkField label="Reason">
                                <Textarea
                                    value={follow.reason}
                                    onChange={(e) =>
                                        update({
                                            follow_up: {
                                                ...follow,
                                                reason: e.target.value,
                                            },
                                        })
                                    }
                                />
                            </WorkField>
                        </>
                    )}
                    {note.status === 'resolved' && (
                        <>
                            <WorkSelect
                                label="Resolution outcome"
                                value={note.resolution_code ?? ''}
                                onChange={(code) =>
                                    update({ resolution_code: code })
                                }
                                options={[
                                    ['', 'Choose outcome'],
                                    ['restored', 'Service restored'],
                                    ['workaround', 'Workaround provided'],
                                    ['fulfilled', 'Request fulfilled'],
                                    ['guidance', 'Guidance provided'],
                                ]}
                            />
                            <WorkField label="Public resolution summary">
                                <Textarea
                                    value={note.resolution_summary ?? ''}
                                    onChange={(e) =>
                                        update({
                                            resolution_summary: e.target.value,
                                        })
                                    }
                                />
                            </WorkField>
                            <WorkField label="How the result was verified">
                                <Textarea
                                    value={note.resolution_verification ?? ''}
                                    onChange={(e) =>
                                        update({
                                            resolution_verification:
                                                e.target.value,
                                        })
                                    }
                                />
                            </WorkField>
                            <p className="text-sm text-muted-foreground">
                                The resolution summary and verification are
                                visible to the requester. Required tasks,
                                bookings and reviews must be complete.
                            </p>
                        </>
                    )}
                </div>
            </details>
            {!internal && (
                <details>
                    <summary className="frontline-focus cursor-pointer py-2 text-sm font-semibold">
                        Notify selected people
                    </summary>
                    <p className="text-xs text-muted-foreground">
                        The public reply remains visible to everyone allowed to
                        view this ticket. Email updates go only to the selected
                        eligible people.
                    </p>
                    {(work.recipients ?? []).map((person) => (
                        <label
                            className="flex min-h-11 items-center gap-2 text-sm"
                            key={person.id}
                        >
                            <input
                                type="checkbox"
                                checked={recipients.includes(person.id)}
                                onChange={(e) =>
                                    update({
                                        recipient_user_ids: e.target.checked
                                            ? [...recipients, person.id]
                                            : recipients.filter(
                                                  (id) => id !== person.id,
                                              ),
                                    })
                                }
                            />
                            {person.name} · {person.email}
                        </label>
                    ))}
                </details>
            )}
        </fieldset>
    );
}

export function workError(error: unknown): string {
    if (axios.isAxiosError(error)) {
        const errors = error.response?.data?.errors;
        if (errors && typeof errors === 'object')
            return Object.values(errors).flat().join(' ');
        return (
            error.response?.data?.message ??
            'Save could not be confirmed. Your fields are retained. Retry the same save to check its outcome.'
        );
    }
    return error instanceof Error
        ? error.message
        : 'Unable to save. Your fields are retained.';
}
