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
import { Textarea } from '@/components/ui/textarea';
import {
    localDateInput,
    personalCalendarRequest,
    type PersonalCalendarEntry,
    type PersonalCalendarKind,
} from '@/lib/personal-calendar';
import { useRef, useState, type FormEvent } from 'react';
import { toast } from 'sonner';
import type { CreateSeed } from './SiteCalendar';

export const PERSONAL_KINDS = [
    {
        key: 'task',
        label: 'Personal task',
        icon: 'CheckSquare',
        color: 'var(--src-checklist)',
    },
    {
        key: 'meeting',
        label: 'Meeting',
        icon: 'CalendarDays',
        color: 'var(--src-event)',
    },
    {
        key: 'appointment',
        label: 'Appointment',
        icon: 'CalendarDays',
        color: 'var(--src-compliance)',
    },
    {
        key: 'reminder',
        label: 'Reminder',
        icon: 'Bell',
        color: 'var(--src-credential)',
    },
];

export default function PersonalEntryDialog({
    entry,
    seed,
    onClose,
    onSaved,
}: {
    entry?: PersonalCalendarEntry;
    seed?: CreateSeed;
    onClose: () => void;
    onSaved: () => void;
}) {
    const [record, setRecord] = useState(entry);
    const [requestId, setRequestId] = useState(() => crypto.randomUUID());
    const [form, setForm] = useState(() => {
        const start = entry
            ? new Date(entry.start_at)
            : new Date(seed?.date ?? new Date());
        if (!entry) {
            const hour = seed?.hour ?? 9;
            start.setHours(Math.floor(hour), Math.round((hour % 1) * 60), 0, 0);
        }
        const end = entry?.end_at
            ? new Date(entry.end_at)
            : new Date(start.getTime() + 3600000);
        if (entry?.all_day && entry.end_at) end.setDate(end.getDate() - 1);
        return {
            kind:
                entry?.kind ??
                (seed?.eventType as PersonalCalendarKind | undefined) ??
                'meeting',
            title: entry?.title ?? '',
            description: entry?.description ?? '',
            location: entry?.location ?? '',
            start: localDateInput(start),
            end: localDateInput(end),
            all_day: entry?.all_day ?? false,
            status: entry?.status ?? 'scheduled',
        };
    });
    const initial = useRef(JSON.stringify(form));
    const [busy, setBusy] = useState(false);
    const saving = useRef(false);
    const [error, setError] = useState('');
    const [discard, setDiscard] = useState(false);
    const change = <K extends keyof typeof form>(
        key: K,
        value: (typeof form)[K],
    ) => {
        setForm((f) => ({ ...f, [key]: value }));
        setDiscard(false);
    };
    const close = () => {
        if (saving.current) return;
        if (JSON.stringify(form) !== initial.current) setDiscard(true);
        else onClose();
    };
    const save = async (e: FormEvent) => {
        e.preventDefault();
        if (saving.current) return;
        const start = new Date(
            form.all_day ? `${form.start.slice(0, 10)}T00:00` : form.start,
        );
        const end = new Date(
            form.all_day ? `${form.end.slice(0, 10)}T00:00` : form.end,
        );
        if (form.all_day) end.setDate(end.getDate() + 1);
        if (!form.title.trim()) {
            setError('Enter a title.');
            return;
        }
        if (
            !Number.isFinite(start.getTime()) ||
            !Number.isFinite(end.getTime()) ||
            end <= start
        ) {
            setError('Choose an end after the start.');
            return;
        }
        saving.current = true;
        setBusy(true);
        setError('');
        try {
            await personalCalendarRequest(
                record ? `/${record.id}` : '',
                record ? 'PUT' : 'POST',
                {
                    ...(record
                        ? { version: record.version }
                        : { request_id: requestId }),
                    kind: form.kind,
                    title: form.title.trim(),
                    description: form.description || null,
                    location: form.location || null,
                    start_at: start.toISOString(),
                    end_at: end.toISOString(),
                    all_day: form.all_day,
                    status: form.status,
                },
            );
            toast.success(
                record ? 'Calendar entry updated' : 'Calendar entry created',
            );
            onSaved();
            onClose();
        } catch (err) {
            setError(
                err instanceof Error
                    ? err.message
                    : 'Could not save. Please try again.',
            );
        } finally {
            saving.current = false;
            setBusy(false);
        }
    };
    const remove = async () => {
        if (!record || saving.current) return;
        saving.current = true;
        setBusy(true);
        setError('');
        try {
            const deleted = await personalCalendarRequest(
                `/${record.id}`,
                'DELETE',
                { version: record.version },
            );
            onSaved();
            onClose();
            toast.success('Calendar entry deleted', {
                duration: 10000,
                action: {
                    label: 'Undo',
                    onClick: () => {
                        void personalCalendarRequest(
                            `/${deleted.id}/restore`,
                            'POST',
                            { version: deleted.version },
                        )
                            .then(() => {
                                onSaved();
                                toast.success('Entry restored');
                            })
                            .catch((err: Error) => toast.error(err.message));
                    },
                },
            });
        } catch (err) {
            setError(
                err instanceof Error
                    ? err.message
                    : 'Could not delete this entry.',
            );
        } finally {
            saving.current = false;
            setBusy(false);
        }
    };
    return (
        <Dialog
            open
            onOpenChange={(open) => {
                if (!open) close();
            }}
        >
            <DialogContent
                className="max-h-[90dvh] overflow-y-auto sm:max-w-xl"
                onInteractOutside={(e) => e.preventDefault()}
            >
                <DialogHeader>
                    <DialogTitle>
                        {record ? 'Edit calendar entry' : 'New calendar entry'}
                    </DialogTitle>
                    <DialogDescription>
                        Private to you. Meetings here reserve your own time;
                        they do not send invitations.
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={save} className="space-y-4">
                    <fieldset
                        disabled={busy}
                        className="space-y-4 disabled:opacity-60"
                    >
                        <div className="grid grid-cols-2 gap-3">
                            <div className="space-y-1.5">
                                <Label htmlFor="personal-kind">
                                    Entry type
                                </Label>
                                <select
                                    id="personal-kind"
                                    className="min-h-[44px] w-full rounded-md border bg-background px-3 text-sm"
                                    value={form.kind}
                                    onChange={(e) =>
                                        change(
                                            'kind',
                                            e.target
                                                .value as PersonalCalendarKind,
                                        )
                                    }
                                >
                                    {PERSONAL_KINDS.map((k) => (
                                        <option key={k.key} value={k.key}>
                                            {k.label}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <div className="space-y-1.5">
                                <Label htmlFor="personal-status">Status</Label>
                                <select
                                    id="personal-status"
                                    className="min-h-[44px] w-full rounded-md border bg-background px-3 text-sm"
                                    value={form.status}
                                    onChange={(e) =>
                                        change(
                                            'status',
                                            e.target
                                                .value as typeof form.status,
                                        )
                                    }
                                >
                                    <option value="scheduled">Scheduled</option>
                                    <option value="completed">Completed</option>
                                    <option value="cancelled">Cancelled</option>
                                </select>
                            </div>
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="personal-title">Title</Label>
                            <Input
                                autoFocus
                                id="personal-title"
                                className="min-h-[44px]"
                                maxLength={255}
                                required
                                placeholder="What have you planned?"
                                value={form.title}
                                onChange={(e) =>
                                    change('title', e.target.value)
                                }
                            />
                        </div>
                        <label className="flex min-h-[44px] items-center gap-3 text-sm">
                            <input
                                type="checkbox"
                                checked={form.all_day}
                                onChange={(e) =>
                                    change('all_day', e.target.checked)
                                }
                                className="h-5 w-5 accent-primary"
                            />
                            All day
                        </label>
                        <div className="grid gap-3 sm:grid-cols-2">
                            <div className="min-w-0 space-y-1.5">
                                <Label htmlFor="personal-start">Starts</Label>
                                <Input
                                    id="personal-start"
                                    className="min-h-[44px]"
                                    type={
                                        form.all_day ? 'date' : 'datetime-local'
                                    }
                                    required
                                    value={
                                        form.all_day
                                            ? form.start.slice(0, 10)
                                            : form.start
                                    }
                                    onChange={(e) =>
                                        change(
                                            'start',
                                            form.all_day
                                                ? `${e.target.value}T00:00`
                                                : e.target.value,
                                        )
                                    }
                                />
                            </div>
                            <div className="min-w-0 space-y-1.5">
                                <Label htmlFor="personal-end">
                                    {form.all_day ? 'Last day' : 'Ends'}
                                </Label>
                                <Input
                                    id="personal-end"
                                    className="min-h-[44px]"
                                    type={
                                        form.all_day ? 'date' : 'datetime-local'
                                    }
                                    required
                                    value={
                                        form.all_day
                                            ? form.end.slice(0, 10)
                                            : form.end
                                    }
                                    onChange={(e) =>
                                        change(
                                            'end',
                                            form.all_day
                                                ? `${e.target.value}T00:00`
                                                : e.target.value,
                                        )
                                    }
                                />
                            </div>
                        </div>
                    <p className="text-xs text-muted-foreground">
                        Times shown in{' '}
                            {Intl.DateTimeFormat()
                                .resolvedOptions()
                                .timeZone.replaceAll('_', ' ')}
                            .
                    </p>
                    {form.kind === 'reminder' && <p className="text-xs text-muted-foreground">This reminder appears on your calendar. Timed notifications are not sent.</p>}
                        <div className="space-y-1.5">
                            <Label htmlFor="personal-location">
                                Location or meeting link{' '}
                                <span className="text-muted-foreground">
                                    (optional)
                                </span>
                            </Label>
                            <Input
                                id="personal-location"
                                className="min-h-[44px]"
                                maxLength={255}
                                value={form.location}
                                onChange={(e) =>
                                    change('location', e.target.value)
                                }
                            />
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="personal-description">
                                Notes{' '}
                                <span className="text-muted-foreground">
                                    (optional)
                                </span>
                            </Label>
                            <Textarea
                                id="personal-description"
                                maxLength={10000}
                                rows={3}
                                value={form.description}
                                onChange={(e) =>
                                    change('description', e.target.value)
                                }
                            />
                        </div>
                    </fieldset>
                    {error && (
                        <p
                            role="alert"
                            className="rounded-md border border-status-critical/30 bg-status-critical-bg p-3 text-sm text-status-critical"
                        >
                            {error}
                        </p>
                    )}
                    {discard && (
                        <div
                            role="alert"
                            className="rounded-md border bg-muted p-3 text-sm"
                        >
                            <p>You have unsaved changes.</p>
                            <div className="mt-2 flex gap-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="min-h-[44px]"
                                    onClick={() => setDiscard(false)}
                                >
                                    Keep editing
                                </Button>
                                <Button
                                    type="button"
                                    variant="destructive"
                                    className="min-h-[44px]"
                                    onClick={onClose}
                                >
                                    Discard changes
                                </Button>
                            </div>
                        </div>
                    )}
                    <div className="flex flex-wrap items-center gap-2 border-t pt-4">
                        {record && (
                            <>
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="min-h-[44px]"
                                    disabled={busy}
                                    onClick={() => {
                                        setRecord(undefined);
                                        setRequestId(crypto.randomUUID());
                                        change('status', 'scheduled');
                                        setError('');
                                    }}
                                >
                                    Duplicate
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="min-h-[44px] text-destructive"
                                    disabled={busy}
                                    onClick={() => void remove()}
                                >
                                    Delete
                                </Button>
                            </>
                        )}
                        <Button
                            type="button"
                            variant="outline"
                            className="ml-auto min-h-[44px]"
                            disabled={busy}
                            onClick={close}
                        >
                            Close
                        </Button>
                        <Button
                            type="submit"
                            className="min-h-[44px]"
                            disabled={busy}
                        >
                            {busy
                                ? 'Saving…'
                                : record
                                  ? 'Save changes'
                                  : 'Create entry'}
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}
