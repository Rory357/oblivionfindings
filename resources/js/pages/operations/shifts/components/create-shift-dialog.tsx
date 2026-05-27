import { useEffect, useMemo, useRef } from 'react';
import { useForm, router } from '@inertiajs/react';
import {
    CalendarClock,
    Check,
    CheckCircle2,
    Clock,
    LayoutGrid,
    Loader2,
    MapPin,
    Pencil,
    Plus,
    Repeat,
    Sparkles,
    Trash,
    Users,
    X,
    type LucideIcon,
} from 'lucide-react';

import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import * as VisuallyHidden from '@radix-ui/react-visually-hidden';
import {
    SHIFT_TYPES,
    SHIFT_TYPE_ACCENT_CLASSES,
    type ShiftTypeKey,
} from '@/lib/shift-types';
import {
    store as storeShift,
    update as updateShift,
} from '@/routes/operations/shifts';
import { store as storeShiftSeries } from '@/routes/operations/shifts/series';

type Client = {
    id: number;
    first_name: string;
    last_name: string;
    service_context_id?: number | null;
    site_id?: number | null;
};
type Staff = { id: number; name: string; email?: string };
type Site = { id: number; name: string; type?: string | null };
type ServiceContext = {
    id: number;
    name: string;
    type: string;
    is_active: boolean;
};

type LockedContext = {
    site_name?: string | null;
    window_label?: string | null;
    missing?: number | string | null;
} | null;

type Weekday = 'mon' | 'tue' | 'wed' | 'thu' | 'fri' | 'sat' | 'sun';
const WEEKDAYS: Weekday[] = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
const WEEKDAY_LABEL: Record<Weekday, string> = {
    mon: 'Mon',
    tue: 'Tue',
    wed: 'Wed',
    thu: 'Thu',
    fri: 'Fri',
    sat: 'Sat',
    sun: 'Sun',
};

export type EditableShift = {
    id: number;
    starts_at: string;
    ends_at: string;
    status: string;
    shift_type?: string | null;
    location?: string | null;
    is_sleepover?: boolean;
    is_on_call?: boolean;
    expected_break_minutes?: number | null;
    notes?: string | null;
    client?: { id: number } | null;
    staff?: { id: number } | null;
    site?: { id: number; name: string } | null;
    service_context_id?: number | null;
    coverage_roles?: string[] | null;
    tasks?: Array<{ id: number; label: string }>;
};

type Props = {
    open: boolean;
    onClose: () => void;
    clients: Client[];
    staff: Staff[];
    sites?: Site[];
    serviceContexts?: ServiceContext[];
    defaultServiceContextId?: number | null;
    defaultStartsAt?: string | null;
    defaultEndsAt?: string | null;
    defaultClientId?: number | null;
    defaultSiteId?: number | null;
    lockedContext?: LockedContext;
    /** When set, the dialog flips into edit mode and pre-fills from this shift. */
    initialShift?: EditableShift | null;
};

function weekdayFromDatetime(value: string | null | undefined): Weekday {
    const parsed = value ? new Date(value) : new Date();
    const day = parsed.getDay();
    const map: Record<number, Weekday> = {
        0: 'sun',
        1: 'mon',
        2: 'tue',
        3: 'wed',
        4: 'thu',
        5: 'fri',
        6: 'sat',
    };
    return map[day] ?? 'mon';
}

function toLocalDatetimeInput(value: string | null | undefined): string {
    if (!value) return '';
    // Fast path only for "naive" local datetime strings (no timezone suffix) —
    // anything with a Z or ±HH:MM offset must go through Date so we render the
    // user's local wall time, not the UTC time-of-day.
    if (
        /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d+)?)?$/.test(value)
    ) {
        return value.slice(0, 16);
    }
    const parsed = new Date(value);
    if (Number.isNaN(parsed.getTime())) return '';
    const yyyy = parsed.getFullYear();
    const mm = String(parsed.getMonth() + 1).padStart(2, '0');
    const dd = String(parsed.getDate()).padStart(2, '0');
    const hh = String(parsed.getHours()).padStart(2, '0');
    const min = String(parsed.getMinutes()).padStart(2, '0');
    return `${yyyy}-${mm}-${dd}T${hh}:${min}`;
}

function defaultStartForToday(): string {
    const d = new Date();
    d.setHours(9, 0, 0, 0);
    return toLocalDatetimeInput(d.toISOString());
}
function defaultEndForToday(): string {
    const d = new Date();
    d.setHours(17, 0, 0, 0);
    return toLocalDatetimeInput(d.toISOString());
}

export function CreateShiftDialog({
    open,
    onClose,
    clients,
    staff,
    sites = [],
    serviceContexts = [],
    defaultServiceContextId = null,
    defaultStartsAt = null,
    defaultEndsAt = null,
    defaultClientId = null,
    defaultSiteId = null,
    lockedContext = null,
    initialShift = null,
}: Props) {
    const isEdit = !!initialShift;
    const initialClient = useMemo(() => {
        if (initialShift?.client?.id) {
            const found = clients.find(
                (c) => c.id === initialShift.client?.id,
            );
            if (found) return found;
        }
        if (defaultClientId) {
            const found = clients.find(
                (c) => String(c.id) === String(defaultClientId),
            );
            if (found) return found;
        }
        if (defaultSiteId) {
            const found = clients.find(
                (c) => String(c.site_id ?? '') === String(defaultSiteId),
            );
            if (found) return found;
        }
        return clients[0] ?? null;
    }, [clients, defaultClientId, defaultSiteId]);

    const form = useForm({
        client_id: (initialShift?.client?.id ??
            initialClient?.id ??
            '') as number | '',
        service_context_id: (initialShift?.service_context_id ??
            initialClient?.service_context_id ??
            defaultServiceContextId ??
            '') as number | '',
        user_id: (initialShift?.staff?.id ?? '') as number | '',
        starts_at:
            toLocalDatetimeInput(
                initialShift?.starts_at ?? defaultStartsAt,
            ) || defaultStartForToday(),
        ends_at:
            toLocalDatetimeInput(initialShift?.ends_at ?? defaultEndsAt) ||
            defaultEndForToday(),
        location: (initialShift?.location ?? '') as string,
        notes: (initialShift?.notes ?? '') as string,
        status:
            initialShift?.status === 'draft'
                ? ('draft' as const)
                : ('scheduled' as const),
        shift_type: ((initialShift?.shift_type as ShiftTypeKey) ??
            'standard') as ShiftTypeKey,
        is_sleepover: !!initialShift?.is_sleepover,
        is_on_call: !!initialShift?.is_on_call,
        expected_break_minutes:
            initialShift?.expected_break_minutes != null
                ? String(initialShift.expected_break_minutes)
                : '30',
        // Hydrate from initialShift in edit mode so submitting doesn't wipe
        // existing coverage roles / tasks on the server. We keep the task id
        // for existing rows so syncShiftTasks updates them in place instead of
        // recreating them.
        coverage_roles: (initialShift?.coverage_roles ?? []) as string[],
        tasks: (initialShift?.tasks?.map((t) => ({
            id: t.id,
            label: t.label,
        })) ?? []) as Array<{ id?: number; label: string }>,
        repeat_weekly: false,
        repeat_end_date: '' as string,
        repeat_by_weekday: [
            weekdayFromDatetime(
                initialShift?.starts_at ?? defaultStartsAt,
            ),
        ] as Weekday[],
        return_to: '' as string,
    });

    // Reset form when dialog opens with new defaults. Run only on the open→true
    // transition — re-running on every form mutation restarts the dialog entry
    // animation, which keeps the dialog at opacity 0. In edit mode we let the
    // useForm() initial state stand (it already pulled from `initialShift`);
    // this effect only re-syncs the create-mode defaults.
    const wasOpenRef = useRef(false);
    useEffect(() => {
        if (!open) {
            wasOpenRef.current = false;
            return;
        }
        if (wasOpenRef.current) return; // already initialised this open
        wasOpenRef.current = true;
        if (isEdit) return; // useForm initialiser handled edit-mode hydration
        form.setData({
            ...form.data,
            client_id: initialClient?.id ?? '',
            service_context_id:
                initialClient?.service_context_id ??
                defaultServiceContextId ??
                '',
            starts_at:
                toLocalDatetimeInput(defaultStartsAt) || defaultStartForToday(),
            ends_at:
                toLocalDatetimeInput(defaultEndsAt) || defaultEndForToday(),
        } as typeof form.data);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    // Cmd/Ctrl+Enter submits
    useEffect(() => {
        if (!open) return;
        const handler = (e: KeyboardEvent) => {
            if ((e.metaKey || e.ctrlKey) && e.key === 'Enter') {
                document
                    .querySelector<HTMLFormElement>('form[data-shifts-create]')
                    ?.requestSubmit();
            }
        };
        window.addEventListener('keydown', handler);
        return () => window.removeEventListener('keydown', handler);
    }, [open]);

    function setShiftType(key: ShiftTypeKey) {
        form.setData('shift_type', key);
        form.setData('is_sleepover', key === 'sleepover');
        form.setData('is_on_call', key === 'on_call');
    }

    function toggleWeekday(d: Weekday) {
        const set = new Set(form.data.repeat_by_weekday);
        if (set.has(d)) set.delete(d);
        else set.add(d);
        form.setData('repeat_by_weekday', Array.from(set) as Weekday[]);
    }

    function addTask() {
        form.setData('tasks', [...form.data.tasks, { label: '' }]);
    }
    function setTask(i: number, label: string) {
        const next = [...form.data.tasks];
        next[i] = { ...next[i], label };
        form.setData('tasks', next);
    }
    function removeTask(i: number) {
        form.setData(
            'tasks',
            form.data.tasks.filter((_, idx) => idx !== i),
        );
    }

    function selectClient(idStr: string) {
        const id = Number(idStr) || '';
        form.setData('client_id', id as number | '');
        const c = clients.find((x) => x.id === id);
        if (c?.service_context_id != null) {
            form.setData('service_context_id', c.service_context_id);
        }
    }

    const durationLabel = useMemo(() => {
        try {
            const a = new Date(form.data.starts_at).getTime();
            const b = new Date(form.data.ends_at).getTime();
            if (a && b && b > a)
                return `${((b - a) / 3_600_000).toFixed(1)}h`;
        } catch {
            // fallthrough
        }
        return '—';
    }, [form.data.starts_at, form.data.ends_at]);

    const summary = useMemo(() => {
        const start = form.data.starts_at
            ? new Date(form.data.starts_at)
            : null;
        const day = start
            ? start.toLocaleDateString('en-NZ', {
                  weekday: 'short',
                  day: 'numeric',
                  month: 'short',
              })
            : 'No date';
        const time = start
            ? start.toLocaleTimeString('en-NZ', {
                  hour: '2-digit',
                  minute: '2-digit',
                  hour12: false,
              })
            : '—';
        const client = clients.find(
            (c) => c.id === Number(form.data.client_id),
        );
        const name = client
            ? `${client.first_name} ${client.last_name}`.trim()
            : 'No client';
        let recurringSuffix = '';
        if (form.data.repeat_weekly && form.data.repeat_end_date) {
            const startDate = new Date(form.data.starts_at);
            const endDate = new Date(form.data.repeat_end_date);
            if (!Number.isNaN(startDate.getTime()) && !Number.isNaN(endDate.getTime())) {
                const weeks = Math.max(
                    1,
                    Math.round(
                        (endDate.getTime() - startDate.getTime()) /
                            (7 * 86_400_000),
                    ),
                );
                const count = weeks * form.data.repeat_by_weekday.length;
                recurringSuffix = ` · ~${count} shifts`;
            }
        }
        return `${day} · ${time} · ${durationLabel} · ${name}${recurringSuffix}`;
    }, [form.data, clients, durationLabel]);

    const selectedClient = clients.find(
        (c) => c.id === Number(form.data.client_id),
    );

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        // Round-trip the current URL so the server's redirect after save
        // lands the user back on the same week / filter combo instead of
        // resetting to the default index. Transform is invoked right before
        // the payload is built, so the latest URL is captured even when the
        // user navigated weeks before opening the dialog.
        form.transform((data) => ({
            ...data,
            return_to:
                typeof window !== 'undefined'
                    ? window.location.pathname + window.location.search
                    : data.return_to,
        }));
        if (isEdit && initialShift) {
            // Edit mode: PUT to update; recurring options don't apply.
            form.put(updateShift.url(initialShift.id), {
                preserveScroll: true,
                onSuccess: () => onClose(),
            });
            return;
        }
        if (!form.data.repeat_weekly) {
            form.post(storeShift.url(), {
                preserveScroll: true,
                onSuccess: () => onClose(),
            });
            return;
        }
        // Recurring series
        const starts = form.data.starts_at;
        const ends = form.data.ends_at;
        const startDate = starts?.slice(0, 10);
        const startsTime = starts?.slice(11, 16);
        const endsTime = ends?.slice(11, 16);
        router.post(storeShiftSeries.url(), {
            client_id: form.data.client_id,
            service_context_id: form.data.service_context_id,
            user_id: form.data.user_id || null,
            start_date: startDate,
            end_date: form.data.repeat_end_date || startDate,
            by_weekday: form.data.repeat_by_weekday,
            starts_time: startsTime,
            ends_time: endsTime,
            location: form.data.location,
            notes: form.data.notes,
            status: form.data.status,
            shift_type: form.data.shift_type,
            is_sleepover: form.data.is_sleepover,
            is_on_call: form.data.is_on_call,
            expected_break_minutes:
                form.data.expected_break_minutes || null,
            tasks: form.data.tasks.filter((t) => t.label.trim() !== ''),
        }, {
            preserveScroll: true,
            onSuccess: () => onClose(),
        });
    }

    return (
        <Dialog open={open} onOpenChange={(o) => (!o ? onClose() : null)}>
            <DialogContent
                className="!max-w-[min(94vw,1080px)] !w-full max-h-[90vh] overflow-hidden !rounded-2xl !p-0 [&>button:last-child]:hidden"
                onInteractOutside={(e) => e.preventDefault()}
            >
                <VisuallyHidden.Root>
                    <DialogTitle>
                        {isEdit ? `Edit shift #${initialShift?.id}` : 'Create shift'}
                    </DialogTitle>
                    <DialogDescription>
                        {isEdit
                            ? 'Update the schedule, staff, tasks or notes for this shift.'
                            : 'Schedule an appointment or rostered shift. Add tasks and optionally repeat weekly.'}
                    </DialogDescription>
                </VisuallyHidden.Root>
                <form
                    data-shifts-create
                    onSubmit={handleSubmit}
                    className="flex h-full max-h-[90vh] flex-col"
                >
                    {/* Header */}
                    <div className="relative overflow-hidden rounded-t-2xl">
                        <div className="absolute -right-12 -top-12 h-40 w-40 rounded-full bg-primary/10 blur-2xl" />
                        <div className="relative flex items-start gap-4 border-b border-border px-6 pb-4 pt-5">
                            <span className="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-primary/10 ring-1 ring-primary/20">
                                <CalendarClock className="h-5 w-5 text-primary" />
                            </span>
                            <div className="min-w-0 flex-1">
                                <div className="text-[10.5px] font-semibold uppercase tracking-wider text-primary">
                                    {isEdit
                                        ? `Edit · Shift #${initialShift?.id}`
                                        : 'New shift'}
                                </div>
                                <h2 className="mt-0.5 text-xl font-bold tracking-tight text-foreground">
                                    {isEdit ? 'Edit shift' : 'Create shift'}
                                </h2>
                                <p className="mt-0.5 text-sm text-muted-foreground">
                                    {isEdit
                                        ? 'Update the schedule, staff, tasks or notes. Changes are saved on submit.'
                                        : 'Schedule an appointment or rostered shift. Add tasks and optionally repeat weekly.'}
                                </p>
                            </div>
                            <div className="flex shrink-0 items-center gap-2">
                                <span className="hidden items-center gap-1 rounded-md border border-border px-1.5 py-1 text-[10.5px] text-muted-foreground sm:inline-flex">
                                    <kbd className="font-sans font-semibold">⌘</kbd>
                                    <kbd className="font-sans font-semibold">↵</kbd>
                                    <span>to save</span>
                                </span>
                                <button
                                    type="button"
                                    onClick={onClose}
                                    aria-label="Close dialog"
                                    className="inline-flex h-9 w-9 items-center justify-center rounded-md text-muted-foreground hover:bg-muted hover:text-foreground"
                                >
                                    <X className="h-4 w-4" />
                                </button>
                            </div>
                        </div>
                    </div>

                    {/* Body */}
                    <div className="flex-1 overflow-y-auto px-6 py-4">
                        {lockedContext ? (
                            <LockedContextCard context={lockedContext} />
                        ) : null}

                        <Section
                            first
                            icon={LayoutGrid}
                            title="Shift type"
                            hint="What kind of shift is this?"
                        >
                            <ShiftTypePicker
                                value={form.data.shift_type}
                                onChange={setShiftType}
                            />
                            <FieldError message={form.errors.shift_type} />
                        </Section>

                        <Section icon={Users} title="Who & where">
                            <div className="grid gap-3 sm:grid-cols-2">
                                <div>
                                    <Label htmlFor="csd-client" required>
                                        Client
                                    </Label>
                                    <select
                                        id="csd-client"
                                        className="select"
                                        value={form.data.client_id}
                                        onChange={(e) =>
                                            selectClient(e.target.value)
                                        }
                                    >
                                        {clients.map((c) => (
                                            <option key={c.id} value={c.id}>
                                                {c.first_name} {c.last_name}
                                            </option>
                                        ))}
                                    </select>
                                    {selectedClient ? (
                                        <ServiceContextHint
                                            client={selectedClient}
                                            serviceContexts={serviceContexts}
                                        />
                                    ) : null}
                                    <FieldError
                                        message={form.errors.client_id}
                                    />
                                </div>

                                <div>
                                    <Label htmlFor="csd-location">
                                        Location
                                    </Label>
                                    <input
                                        id="csd-location"
                                        className="input"
                                        value={form.data.location}
                                        onChange={(e) =>
                                            form.setData(
                                                'location',
                                                e.target.value,
                                            )
                                        }
                                        placeholder={
                                            sites.length
                                                ? sites[0].name
                                                : "e.g. Client's home"
                                        }
                                        list="csd-locations"
                                    />
                                    <datalist id="csd-locations">
                                        {sites.map((s) => (
                                            <option
                                                key={s.id}
                                                value={s.name}
                                            />
                                        ))}
                                    </datalist>
                                    <FieldError message={form.errors.location} />
                                </div>

                                <div className="sm:col-span-2">
                                    <Label htmlFor="csd-staff">Staff</Label>
                                    <select
                                        id="csd-staff"
                                        className="select"
                                        value={form.data.user_id}
                                        onChange={(e) =>
                                            form.setData(
                                                'user_id',
                                                e.target.value === ''
                                                    ? ''
                                                    : (Number(e.target.value) as number),
                                            )
                                        }
                                    >
                                        <option value="">
                                            Unassigned (create an open shift)
                                        </option>
                                        {staff.map((s) => (
                                            <option key={s.id} value={s.id}>
                                                {s.name}
                                            </option>
                                        ))}
                                    </select>
                                    {!form.data.user_id ? (
                                        <p className="mt-1 text-xs text-muted-foreground">
                                            Leave blank to publish as an open shift — staff can be assigned later from Rostering.
                                        </p>
                                    ) : null}
                                    <FieldError message={form.errors.user_id} />
                                </div>
                            </div>
                        </Section>

                        <Section
                            icon={Clock}
                            title="Schedule"
                            hint={
                                durationLabel === '—'
                                    ? undefined
                                    : `${durationLabel} including any breaks`
                            }
                        >
                            <ScheduleStrip
                                startsAt={form.data.starts_at}
                                endsAt={form.data.ends_at}
                                breakMinutes={form.data.expected_break_minutes}
                                onStartsAtChange={(v) =>
                                    form.setData('starts_at', v)
                                }
                                onEndsAtChange={(v) =>
                                    form.setData('ends_at', v)
                                }
                                onBreakChange={(v) =>
                                    form.setData('expected_break_minutes', v)
                                }
                                duration={durationLabel}
                            />
                            <div className="mt-3">
                                <Label required>Publish as</Label>
                                <StatusPicker
                                    value={form.data.status}
                                    onChange={(v) => form.setData('status', v)}
                                />
                            </div>
                            <FieldError message={form.errors.starts_at} />
                            <FieldError message={form.errors.ends_at} />
                        </Section>

                        {isEdit ? null : (
                        <Section
                            icon={Repeat}
                            title="Repeat weekly"
                            hint={
                                form.data.repeat_weekly
                                    ? 'Creates a recurring series'
                                    : 'One-off shift'
                            }
                            action={
                                <Toggle
                                    value={form.data.repeat_weekly}
                                    onChange={(v) =>
                                        form.setData('repeat_weekly', v)
                                    }
                                    ariaLabel="Toggle repeat weekly"
                                />
                            }
                        >
                            {form.data.repeat_weekly ? (
                                <div className="space-y-3">
                                    <div>
                                        <Label>Repeat on</Label>
                                        <div className="flex flex-wrap gap-1.5">
                                            {WEEKDAYS.map((d) => {
                                                const active =
                                                    form.data.repeat_by_weekday.includes(
                                                        d,
                                                    );
                                                return (
                                                    <button
                                                        key={d}
                                                        type="button"
                                                        onClick={() =>
                                                            toggleWeekday(d)
                                                        }
                                                        className={[
                                                            'h-8 min-w-[44px] rounded-md px-3 text-xs font-semibold tabular-nums transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring',
                                                            active
                                                                ? 'bg-primary text-primary-foreground shadow-sm'
                                                                : 'border border-border bg-card text-foreground hover:border-primary/40 hover:bg-primary/5',
                                                        ].join(' ')}
                                                    >
                                                        {WEEKDAY_LABEL[d]}
                                                    </button>
                                                );
                                            })}
                                        </div>
                                    </div>
                                    <div className="grid items-end gap-3 sm:grid-cols-[1fr_auto]">
                                        <div>
                                            <Label htmlFor="csd-rep-end">
                                                Repeat end date
                                            </Label>
                                            <input
                                                id="csd-rep-end"
                                                type="date"
                                                className="input"
                                                value={form.data.repeat_end_date}
                                                onChange={(e) =>
                                                    form.setData(
                                                        'repeat_end_date',
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                        </div>
                                        <div className="inline-flex h-9 items-center gap-2 self-end rounded-lg border border-primary/20 bg-primary/10 px-3 py-2 text-xs text-foreground">
                                            <Sparkles className="h-3.5 w-3.5 text-primary" />
                                            <span>
                                                Multiple shifts will be created across the date range
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            ) : null}
                        </Section>
                        )}

                        <Section
                            icon={Pencil}
                            title="Tasks & notes"
                            hint="What the worker needs to know"
                        >
                            <div className="grid gap-4 md:grid-cols-2">
                                <div>
                                    <div className="mb-2 flex items-center justify-between">
                                        <label className="text-xs font-semibold text-foreground">
                                            Shift tasks{' '}
                                            <span className="font-normal text-muted-foreground">
                                                ·{' '}
                                                {form.data.tasks.length
                                                    ? `${form.data.tasks.length} task${form.data.tasks.length === 1 ? '' : 's'}`
                                                    : 'checklist for the worker'}
                                            </span>
                                        </label>
                                        {form.data.tasks.length > 0 ? (
                                            <button
                                                type="button"
                                                onClick={addTask}
                                                className="inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-medium text-primary hover:bg-primary/5"
                                            >
                                                <Plus className="h-3.5 w-3.5" />{' '}
                                                Add
                                            </button>
                                        ) : null}
                                    </div>
                                    {form.data.tasks.length === 0 ? (
                                        <button
                                            type="button"
                                            onClick={addTask}
                                            className="flex w-full items-center justify-center gap-2 rounded-lg border border-dashed border-border bg-muted/30 px-4 py-3 text-xs text-muted-foreground transition hover:border-primary/40 hover:bg-primary/5 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                                        >
                                            <Plus className="h-3.5 w-3.5" />
                                            Add the first task — e.g. “Morning medication round”
                                        </button>
                                    ) : (
                                        <ul className="space-y-1.5">
                                            {form.data.tasks.map((t, i) => (
                                                <li
                                                    key={i}
                                                    className="flex items-center gap-2"
                                                >
                                                    <span className="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-md bg-muted text-xs font-semibold text-muted-foreground tabular-nums">
                                                        {i + 1}
                                                    </span>
                                                    <input
                                                        className="input flex-1"
                                                        placeholder={`Task ${i + 1}`}
                                                        value={t.label}
                                                        onChange={(e) =>
                                                            setTask(
                                                                i,
                                                                e.target.value,
                                                            )
                                                        }
                                                    />
                                                    <button
                                                        type="button"
                                                        onClick={() =>
                                                            removeTask(i)
                                                        }
                                                        className="inline-flex h-9 w-9 items-center justify-center rounded-md text-muted-foreground hover:bg-muted hover:text-foreground"
                                                        aria-label={`Remove task ${i + 1}`}
                                                    >
                                                        <Trash className="h-4 w-4" />
                                                    </button>
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                </div>
                                <div>
                                    <label
                                        className="mb-2 block text-xs font-semibold text-foreground"
                                        htmlFor="csd-notes"
                                    >
                                        Handover notes{' '}
                                        <span className="font-normal text-muted-foreground">
                                            · anything the worker should know
                                        </span>
                                    </label>
                                    <textarea
                                        id="csd-notes"
                                        rows={4}
                                        className="textarea"
                                        placeholder="e.g. Prefers a quieter handover; check fridge for new medication."
                                        value={form.data.notes}
                                        onChange={(e) =>
                                            form.setData('notes', e.target.value)
                                        }
                                    />
                                </div>
                            </div>
                        </Section>
                    </div>

                    {/* Footer */}
                    <div className="flex flex-col gap-3 border-t border-border bg-card px-6 py-3.5 sm:flex-row sm:items-center sm:justify-between">
                        <div className="flex min-w-0 items-center gap-2">
                            <span className="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary">
                                <CalendarClock className="h-3.5 w-3.5" />
                            </span>
                            <div className="min-w-0">
                                <div className="text-[10.5px] font-semibold uppercase tracking-wider text-muted-foreground">
                                    {isEdit ? 'Will update' : 'Will create'}
                                </div>
                                <div className="truncate text-xs text-foreground">
                                    {summary}
                                </div>
                            </div>
                        </div>
                        <div className="flex shrink-0 items-center gap-2">
                            <button
                                type="button"
                                onClick={onClose}
                                className="inline-flex items-center gap-1.5 rounded-md border border-border bg-transparent px-3 py-1.5 text-sm font-medium text-foreground hover:bg-muted"
                            >
                                Cancel
                            </button>
                            <button
                                type="submit"
                                disabled={form.processing}
                                className="inline-flex items-center gap-1.5 rounded-md bg-primary px-3 py-1.5 text-sm font-medium text-primary-foreground transition hover:brightness-95 disabled:cursor-not-allowed disabled:opacity-70"
                            >
                                {form.processing ? (
                                    <>
                                        <Loader2 className="h-4 w-4 animate-spin" />
                                        Saving…
                                    </>
                                ) : (
                                    <>
                                        <Check className="h-4 w-4" />
                                        {isEdit ? 'Save changes' : 'Create shift'}
                                    </>
                                )}
                            </button>
                        </div>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function Section({
    icon: Icon,
    title,
    hint,
    action,
    children,
    first,
}: {
    icon: LucideIcon;
    title: string;
    hint?: string;
    action?: React.ReactNode;
    children: React.ReactNode;
    first?: boolean;
}) {
    return (
        <section className={first ? '' : 'mt-4 border-t border-border pt-4'}>
            <div className="mb-3 flex items-baseline justify-between gap-3">
                <div className="flex min-w-0 items-center gap-2">
                    <Icon className="h-4 w-4 shrink-0 text-primary" />
                    <h3 className="text-sm font-semibold text-foreground">
                        {title}
                    </h3>
                    {hint ? (
                        <span className="truncate text-xs text-muted-foreground">
                            · {hint}
                        </span>
                    ) : null}
                </div>
                {action ? <div className="shrink-0">{action}</div> : null}
            </div>
            {children}
        </section>
    );
}

function Label({
    children,
    htmlFor,
    required,
}: {
    children: React.ReactNode;
    htmlFor?: string;
    required?: boolean;
}) {
    return (
        <label
            htmlFor={htmlFor}
            className="mb-1.5 block text-[13px] font-medium text-foreground"
        >
            {children}
            {required ? (
                <span className="ml-0.5 text-status-critical">*</span>
            ) : null}
        </label>
    );
}

function FieldError({ message }: { message?: string }) {
    if (!message) return null;
    return <p className="mt-1 text-xs text-status-critical">{message}</p>;
}

function ShiftTypePicker({
    value,
    onChange,
}: {
    value: ShiftTypeKey;
    onChange: (k: ShiftTypeKey) => void;
}) {
    return (
        <div className="grid grid-cols-2 gap-2 sm:grid-cols-5">
            {SHIFT_TYPES.map((t) => {
                const active = value === t.key;
                const accent = SHIFT_TYPE_ACCENT_CLASSES[t.accent];
                const Icon = t.icon;
                return (
                    <button
                        key={t.key}
                        type="button"
                        onClick={() => onChange(t.key)}
                        aria-pressed={active}
                        className={[
                            'group relative flex flex-col items-start gap-2 rounded-xl border-2 p-3 text-left transition-all focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring',
                            active
                                ? 'border-primary bg-primary/5 shadow-sm'
                                : 'border-border bg-card hover:border-primary/40 hover:bg-primary/5',
                        ].join(' ')}
                    >
                        <span
                            className={`inline-flex h-8 w-8 items-center justify-center rounded-lg ${accent.bg} ${accent.fg}`}
                        >
                            <Icon className="h-4 w-4" />
                        </span>
                        <span className="block">
                            <span className="block text-sm font-semibold text-foreground">
                                {t.label}
                            </span>
                            <span className="mt-0.5 block text-[11px] leading-tight text-muted-foreground">
                                {t.description}
                            </span>
                        </span>
                        {active ? (
                            <span className="absolute right-2 top-2 inline-flex h-4 w-4 items-center justify-center rounded-full bg-primary text-primary-foreground">
                                <Check className="h-3 w-3" strokeWidth={3} />
                            </span>
                        ) : null}
                    </button>
                );
            })}
        </div>
    );
}

function ScheduleStrip({
    startsAt,
    endsAt,
    breakMinutes,
    onStartsAtChange,
    onEndsAtChange,
    onBreakChange,
    duration,
}: {
    startsAt: string;
    endsAt: string;
    breakMinutes: string;
    onStartsAtChange: (v: string) => void;
    onEndsAtChange: (v: string) => void;
    onBreakChange: (v: string) => void;
    duration: string;
}) {
    return (
        <div className="grid items-end gap-2 sm:grid-cols-[1fr_auto_1fr_140px]">
            <div>
                <Label htmlFor="csd-start" required>
                    Start
                </Label>
                <input
                    id="csd-start"
                    type="datetime-local"
                    className="input"
                    value={startsAt}
                    onChange={(e) => onStartsAtChange(e.target.value)}
                />
            </div>
            <div className="flex flex-col items-center pb-1">
                <div className="text-[10.5px] font-semibold uppercase tracking-wider text-muted-foreground">
                    Duration
                </div>
                <div className="mt-1 flex items-center gap-1.5">
                    <div className="h-[3px] w-6 rounded-full bg-primary/30" />
                    <div className="rounded-full bg-primary px-2.5 py-0.5 text-xs font-semibold text-primary-foreground tabular-nums">
                        {duration}
                    </div>
                    <div className="h-[3px] w-6 rounded-full bg-primary/30" />
                </div>
            </div>
            <div>
                <Label htmlFor="csd-end" required>
                    End
                </Label>
                <input
                    id="csd-end"
                    type="datetime-local"
                    className="input"
                    value={endsAt}
                    onChange={(e) => onEndsAtChange(e.target.value)}
                />
            </div>
            <div>
                <Label htmlFor="csd-break">
                    Break{' '}
                    <span className="font-normal text-muted-foreground">
                        (min)
                    </span>
                </Label>
                <input
                    id="csd-break"
                    type="number"
                    min={0}
                    max={720}
                    className="input"
                    value={breakMinutes}
                    onChange={(e) => onBreakChange(e.target.value)}
                />
            </div>
        </div>
    );
}

function StatusPicker({
    value,
    onChange,
}: {
    value: 'draft' | 'scheduled';
    onChange: (v: 'draft' | 'scheduled') => void;
}) {
    const options = [
        {
            key: 'draft' as const,
            label: 'Draft',
            icon: Pencil,
            hint: 'Plan privately, no notification.',
        },
        {
            key: 'scheduled' as const,
            label: 'Scheduled',
            icon: CheckCircle2,
            hint: 'Publish to the worker.',
        },
    ];
    return (
        <div className="grid grid-cols-2 gap-2">
            {options.map((o) => {
                const active = value === o.key;
                const Icon = o.icon;
                return (
                    <button
                        key={o.key}
                        type="button"
                        onClick={() => onChange(o.key)}
                        aria-pressed={active}
                        className={[
                            'flex items-start gap-2 rounded-lg border p-2.5 text-left transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring',
                            active
                                ? 'border-primary bg-primary/5'
                                : 'border-border bg-card hover:border-primary/40',
                        ].join(' ')}
                    >
                        <span
                            className={[
                                'mt-0.5 inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-md',
                                active
                                    ? 'bg-primary text-primary-foreground'
                                    : 'bg-muted text-muted-foreground',
                            ].join(' ')}
                        >
                            <Icon className="h-3.5 w-3.5" />
                        </span>
                        <span className="min-w-0">
                            <span className="block text-sm font-medium text-foreground">
                                {o.label}
                            </span>
                            <span className="block text-[11px] leading-tight text-muted-foreground">
                                {o.hint}
                            </span>
                        </span>
                    </button>
                );
            })}
        </div>
    );
}

function Toggle({
    value,
    onChange,
    ariaLabel,
}: {
    value: boolean;
    onChange: (v: boolean) => void;
    ariaLabel?: string;
}) {
    return (
        <button
            type="button"
            role="switch"
            aria-checked={value}
            aria-label={ariaLabel}
            onClick={() => onChange(!value)}
            className={[
                'relative h-5 w-9 rounded-full transition',
                value ? 'bg-primary' : 'bg-muted',
            ].join(' ')}
        >
            <span
                className={[
                    'absolute top-0.5 h-4 w-4 rounded-full bg-white shadow transition-transform',
                    value ? 'translate-x-4' : 'translate-x-0.5',
                ].join(' ')}
            />
        </button>
    );
}

function ServiceContextHint({
    client,
    serviceContexts,
}: {
    client: Client;
    serviceContexts: ServiceContext[];
}) {
    const ctx = serviceContexts.find(
        (c) => c.id === Number(client.service_context_id ?? -1),
    );
    if (!ctx) return null;
    return (
        <p className="mt-1 text-xs text-muted-foreground">
            Service context:{' '}
            <span className="text-foreground">{ctx.name}</span> (inherited)
        </p>
    );
}

function LockedContextCard({ context }: { context: LockedContext }) {
    if (!context) return null;
    return (
        <div className="mb-4 flex items-start gap-3 rounded-xl border border-primary/40 bg-primary/10 p-3">
            <span className="mt-0.5 inline-flex shrink-0 items-center justify-center rounded-lg border border-primary/20 bg-background p-1.5">
                <MapPin className="h-4 w-4 text-primary" />
            </span>
            <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-center gap-2">
                    <span className="text-sm font-medium text-foreground">
                        {context.site_name ?? 'Coverage gap'}
                    </span>
                    <span className="inline-flex items-center rounded-full bg-status-info-bg px-2 py-0.5 text-[11px] font-medium text-status-info">
                        From coverage gap
                    </span>
                </div>
                {context.window_label || context.missing ? (
                    <p className="mt-0.5 text-xs text-muted-foreground">
                        {context.window_label}
                        {context.missing
                            ? ` · missing ${context.missing} staff`
                            : ''}
                        . Confirm the client and staff so coverage closes safely.
                    </p>
                ) : null}
            </div>
        </div>
    );
}
