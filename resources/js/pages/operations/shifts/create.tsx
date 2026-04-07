import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { Head, router, useForm, usePage } from '@inertiajs/react';

type Client = {
    id: number;
    first_name: string;
    last_name: string;
    service_context_id?: number | null;
    site_id?: number | null;
};
type Staff = { id: number; name: string; email: string };

type ServiceContext = {
    id: number;
    name: string;
    type: string;
    is_active: boolean;
};

type Props = {
    clients: Client[];
    staff: Staff[];
    serviceContexts: ServiceContext[];
    defaultServiceContextId?: number | null;
    defaultClientId?: number | string | null;
    defaultSiteId?: number | string | null;
    defaultUserId?: number | string | null;
    defaultStartsAt?: string | null;
    defaultEndsAt?: string | null;
    defaultLocation?: string | null;
    defaultShiftType?: string | null;
    defaultOpenShift?: boolean;
    defaultRepeatWeekly?: boolean;
    defaultRepeatEndDate?: string | null;
    defaultReturnTo?: string | null;
    coverageReservationToken?: string | null;
    coverageContext?: {
        rule_id?: number | string | null;
        rule_name?: string | null;
        required_staff?: number | string | null;
        missing_staff?: number | string | null;
        site_id?: number | string | null;
        site_name?: string | null;
        site_client_count?: number | string | null;
        site_clients?: Array<{ id: number; name: string }>;
        preferred_client_id?: number | string | null;
        preferred_client_name?: string | null;
        role_shortages?: Array<{
            key: string;
            label?: string | null;
            required?: number | string | null;
            missing?: number | string | null;
        }>;
        fill_intent?: {
            action?: string | null;
            site_name?: string | null;
            preferred_client_name?: string | null;
            roles?: Array<{
                key: string;
                label?: string | null;
                missing?: number | string | null;
            }>;
        } | null;
        coverage_slots?: Array<{
            slot_key: string;
            kind: string;
            label: string;
            status: string;
            role_key?: string | null;
        }>;
    } | null;
};

function toLocalDatetimeInput(value?: string | null) {
    if (!value) return '';
    if (/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(value)) {
        return value;
    }

    const parsed = new Date(value);
    if (Number.isNaN(parsed.getTime())) {
        return '';
    }

    const yyyy = parsed.getFullYear();
    const mm = String(parsed.getMonth() + 1).padStart(2, '0');
    const dd = String(parsed.getDate()).padStart(2, '0');
    const hh = String(parsed.getHours()).padStart(2, '0');
    const min = String(parsed.getMinutes()).padStart(2, '0');

    return `${yyyy}-${mm}-${dd}T${hh}:${min}`;
}

function weekdayFromDatetime(
    value?: string | null,
): 'mon' | 'tue' | 'wed' | 'thu' | 'fri' | 'sat' | 'sun' {
    const parsed = value ? new Date(value) : new Date();
    switch (parsed.getDay()) {
        case 0:
            return 'sun';
        case 1:
            return 'mon';
        case 2:
            return 'tue';
        case 3:
            return 'wed';
        case 4:
            return 'thu';
        case 5:
            return 'fri';
        case 6:
            return 'sat';
        default:
            return 'mon';
    }
}

function fillActionLabel(action?: string | null) {
    switch (action) {
        case 'fill_existing_open_shift':
            return 'Fill an existing open shift';
        case 'retag_or_replace_open_shift':
            return 'Retag or replace the current open shift';
        case 'create_role_specific_shift':
            return 'Create a role-specific cover shift';
        case 'create_recurring_cover':
            return 'Create a recurring cover pattern';
        case 'review_existing_supply':
            return 'Review the current planned supply';
        case 'rebalance_existing_supply':
            return 'Rebalance the current supply before adding cover';
        default:
            return 'Create cover shift';
    }
}

export default function ShiftCreate({
    clients,
    staff,
    serviceContexts,
    defaultServiceContextId = null,
    defaultClientId = null,
    defaultSiteId = null,
    defaultUserId = null,
    defaultStartsAt = null,
    defaultEndsAt = null,
    defaultLocation = null,
    defaultShiftType = null,
    defaultOpenShift = false,
    defaultRepeatWeekly = false,
    defaultRepeatEndDate = null,
    defaultReturnTo = null,
    coverageReservationToken = null,
    coverageContext = null,
}: Props) {
    const { labels } = usePage().props as any;
    const shiftLabel = labels?.['shift.singular'] ?? 'Shift';

    const initialClient = (() => {
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
        return clients?.[0] ?? null;
    })();

    const form = useForm({
        client_id: initialClient?.id ?? '',
        service_context_id: (initialClient?.service_context_id ??
            defaultServiceContextId ??
            '') as any,
        // Allow creating an open/unassigned shift for roster planning
        user_id: defaultOpenShift ? '' : (defaultUserId ?? ''),
        starts_at: toLocalDatetimeInput(defaultStartsAt),
        ends_at: toLocalDatetimeInput(defaultEndsAt),
        location: defaultLocation ?? '',
        notes: '',
        status: defaultOpenShift
            ? 'draft'
            : initialClient
              ? 'scheduled'
              : 'draft',
        shift_type: defaultShiftType ?? 'standard',
        is_sleepover: false,
        is_on_call: false,
        expected_break_minutes: '',
        coverage_rule_id: coverageContext?.rule_id ?? '',
        coverage_roles:
            coverageContext?.role_shortages?.map((role) => role.key) ?? [],
        coverage_reservation_token: coverageReservationToken ?? '',
        tasks: [] as Array<{ label: string }>,
        repeat_weekly: defaultRepeatWeekly,
        repeat_end_date: defaultRepeatEndDate ?? '',
        repeat_by_weekday: [weekdayFromDatetime(defaultStartsAt)] as Array<
            'mon' | 'tue' | 'wed' | 'thu' | 'fri' | 'sat' | 'sun'
        >,
        return_to: defaultReturnTo ?? '',
    });

    function addTask() {
        form.setData('tasks', [...form.data.tasks, { label: '' }]);
    }

    function removeTask(idx: number) {
        form.setData(
            'tasks',
            form.data.tasks.filter((_, i) => i !== idx),
        );
    }

    function toggleWeekday(d: any) {
        const set = new Set(form.data.repeat_by_weekday);
        if (set.has(d)) set.delete(d);
        else set.add(d);
        form.setData('repeat_by_weekday', Array.from(set) as any);
    }

    function applyRecommendedSetup() {
        const action = coverageContext?.fill_intent?.action;
        if (!action) return;

        if (
            action === 'fill_existing_open_shift' ||
            action === 'retag_or_replace_open_shift' ||
            action === 'create_role_specific_shift'
        ) {
            form.setData('user_id', '');
            form.setData('status', 'draft');
        }

        if (action === 'create_recurring_cover') {
            form.setData('repeat_weekly', true);
            form.setData('user_id', '');
            form.setData('status', 'draft');

            if (!form.data.repeat_end_date && form.data.starts_at) {
                const repeatEnd = new Date(form.data.starts_at);
                repeatEnd.setDate(repeatEnd.getDate() + 28);
                form.setData(
                    'repeat_end_date',
                    repeatEnd.toISOString().slice(0, 10),
                );
            }
        }

        if (coverageContext?.role_shortages?.length) {
            form.setData(
                'coverage_roles',
                coverageContext.role_shortages.map((role) => role.key),
            );
        }
    }

    return (
        <AppLayout
            breadcrumbs={[
                {
                    title: labels?.['shift.plural'] ?? 'Shifts',
                    href: '/shifts',
                },
                { title: 'Create', href: '/shifts/create' },
            ]}
        >
            <Head title={`Create ${shiftLabel}`} />
            <PageShell>
                <div className="max-w-2xl">
                    <PageHeader
                        title={`Create ${shiftLabel}`}
                        backHref="/shifts"
                        description="Create an appointment / rostered shift. Add tasks and (optionally) repeat weekly."
                    />
                </div>

                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        if (!form.data.repeat_weekly) {
                            form.post('/shifts');
                            return;
                        }

                        // Recurring weekly series payload
                        const starts = form.data.starts_at;
                        const ends = form.data.ends_at;
                        const startDate = starts?.slice(0, 10);
                        const startsTime = starts?.slice(11, 16);
                        const endsTime = ends?.slice(11, 16);

                        router.post('/operations/shifts/series', {
                            client_id: form.data.client_id,
                            service_context_id: form.data.service_context_id,
                            user_id: form.data.user_id,
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
                            coverage_rule_id:
                                form.data.coverage_rule_id || undefined,
                            coverage_roles: form.data.coverage_roles,
                            coverage_reservation_token:
                                form.data.coverage_reservation_token ||
                                undefined,
                            tasks: form.data.tasks,
                            return_to: form.data.return_to || undefined,
                        });
                    }}
                    className="space-y-4"
                >
                    {defaultSiteId ? (
                        <div className="rounded-md border border-red-200 bg-red-50/70 p-3 text-sm text-red-800">
                            <div className="font-medium">
                                Coverage quick fill
                            </div>
                            <div className="mt-1">
                                {coverageContext?.rule_name
                                    ? `${coverageContext.rule_name} is short`
                                    : 'This shift is being created from a site coverage gap.'}{' '}
                                {coverageContext?.missing_staff ? (
                                    <>
                                        Missing{' '}
                                        <span className="font-semibold">
                                            {coverageContext.missing_staff}
                                        </span>{' '}
                                        staff
                                        {coverageContext?.required_staff
                                            ? ` of ${coverageContext.required_staff} required`
                                            : ''}
                                        .
                                    </>
                                ) : null}{' '}
                                Confirm the right client, service context, and
                                staff so the roster closes the missing coverage
                                safely.
                            </div>
                            {coverageContext?.site_name ? (
                                <div className="mt-2 text-xs">
                                    Site:{' '}
                                    <span className="font-medium">
                                        {coverageContext.site_name}
                                    </span>
                                    {coverageContext?.preferred_client_name
                                        ? ` · planning anchor ${coverageContext.preferred_client_name}`
                                        : coverageContext?.site_client_count
                                          ? ` · choose a planning client from ${coverageContext.site_client_count} site client(s)`
                                          : ''}
                                </div>
                            ) : null}
                            {coverageContext?.role_shortages?.length ? (
                                <div className="mt-2 flex flex-wrap gap-2">
                                    {coverageContext.role_shortages.map(
                                        (role) => (
                                            <span
                                                key={role.key}
                                                className="rounded-full border border-red-200 bg-white/80 px-2 py-1 text-xs font-medium"
                                            >
                                                {role.label ??
                                                    role.key.replace(
                                                        '_',
                                                        ' ',
                                                    )}{' '}
                                                missing x{role.missing ?? 1}
                                            </span>
                                        ),
                                    )}
                                </div>
                            ) : null}
                            {coverageContext?.coverage_slots?.length ? (
                                <div className="mt-2 flex flex-wrap gap-2">
                                    {coverageContext.coverage_slots
                                        .filter(
                                            (slot) =>
                                                slot.status === 'available' ||
                                                slot.status === 'reserved',
                                        )
                                        .slice(0, 6)
                                        .map((slot) => (
                                            <span
                                                key={slot.slot_key}
                                                className="rounded-full border border-red-200 bg-white/80 px-2 py-1 text-xs font-medium"
                                            >
                                                {slot.label}{' '}
                                                {slot.status === 'reserved'
                                                    ? 'reserved'
                                                    : 'available'}
                                            </span>
                                        ))}
                                </div>
                            ) : null}
                            {coverageContext?.fill_intent?.action ? (
                                <div className="mt-3 rounded-md border border-red-200 bg-white/70 p-3 text-xs">
                                    <div className="font-medium text-red-800">
                                        Recommended setup
                                    </div>
                                    <div className="mt-1 text-red-700">
                                        {fillActionLabel(
                                            coverageContext.fill_intent.action,
                                        )}
                                    </div>
                                    <div className="mt-2">
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="outline"
                                            onClick={applyRecommendedSetup}
                                        >
                                            Apply recommended setup
                                        </Button>
                                    </div>
                                </div>
                            ) : null}
                            {!coverageReservationToken ? (
                                <div className="mt-2 text-xs font-medium text-red-700">
                                    Live reservation could not be created for
                                    this gap. Save quickly or reopen the gap if
                                    someone else fills it first.
                                </div>
                            ) : null}
                        </div>
                    ) : null}
                    <div className="space-y-4 rounded-md border p-4">
                        <div className="space-y-2">
                            <Label>Client</Label>
                            <select
                                className="w-full rounded-md border bg-background p-2 text-sm"
                                value={form.data.client_id}
                                onChange={(e) => {
                                    const nextId = e.target.value;
                                    form.setData('client_id', nextId);

                                    // If service context not manually selected yet, inherit from client
                                    if (!form.data.service_context_id) {
                                        const client = clients.find(
                                            (c) =>
                                                String(c.id) === String(nextId),
                                        );
                                        if (client?.service_context_id) {
                                            form.setData(
                                                'service_context_id',
                                                client.service_context_id as any,
                                            );
                                        }
                                    }
                                }}
                            >
                                {(defaultSiteId
                                    ? clients.filter(
                                          (c) =>
                                              String(c.site_id ?? '') ===
                                              String(defaultSiteId),
                                      )
                                    : clients
                                ).map((c) => (
                                    <option key={c.id} value={c.id}>
                                        {c.first_name} {c.last_name}
                                    </option>
                                ))}
                            </select>
                            {defaultSiteId &&
                            !coverageContext?.preferred_client_id ? (
                                <div className="text-xs text-slate-500">
                                    The current shift schema still needs a
                                    planning client, but this cover shift is
                                    anchored to the site demand window first.
                                </div>
                            ) : null}
                        </div>

                        <div className="space-y-2">
                            <Label>Service context</Label>
                            <select
                                className="w-full rounded-md border bg-background p-2 text-sm"
                                value={String(
                                    form.data.service_context_id ?? '',
                                )}
                                onChange={(e) =>
                                    form.setData(
                                        'service_context_id',
                                        e.target.value,
                                    )
                                }
                            >
                                <option value="">
                                    Inherit from client (recommended)
                                </option>
                                {serviceContexts
                                    .filter(
                                        (sc) =>
                                            sc.is_active ||
                                            String(sc.id) ===
                                                String(
                                                    form.data
                                                        .service_context_id,
                                                ),
                                    )
                                    .map((sc) => (
                                        <option key={sc.id} value={sc.id}>
                                            {sc.name}
                                            {!sc.is_active ? ' (inactive)' : ''}
                                        </option>
                                    ))}
                            </select>
                            <div className="text-xs text-slate-500">
                                If left blank, the shift will inherit the
                                selected client’s service context (if set).
                            </div>
                        </div>

                        <div className="space-y-2">
                            <Label>Staff</Label>
                            <select
                                className="w-full rounded-md border bg-background p-2 text-sm"
                                value={form.data.user_id}
                                onChange={(e) =>
                                    form.setData('user_id', e.target.value)
                                }
                            >
                                <option value="">
                                    Unassigned (open shift)
                                </option>
                                {staff.map((s) => (
                                    <option key={s.id} value={s.id}>
                                        {s.name} ({s.email})
                                    </option>
                                ))}
                            </select>
                            <div className="text-xs text-slate-500">
                                Leave blank to create an open shift that can be
                                assigned later from the Rostering module.
                            </div>
                        </div>

                        <div className="space-y-3 rounded-md border p-3">
                            <div className="text-sm font-medium">
                                Operational settings
                            </div>
                            <div className="grid gap-4 md:grid-cols-2">
                                <div className="space-y-2">
                                    <Label>Shift type</Label>
                                    <select
                                        className="w-full rounded-md border bg-background p-2 text-sm"
                                        value={form.data.shift_type}
                                        onChange={(e) =>
                                            form.setData(
                                                'shift_type',
                                                e.target.value,
                                            )
                                        }
                                    >
                                        <option value="standard">
                                            Standard
                                        </option>
                                        <option value="sleepover">
                                            Sleepover
                                        </option>
                                        <option value="on_call">On-call</option>
                                        <option value="split">Split</option>
                                        <option value="travel">Travel</option>
                                    </select>
                                </div>
                                <div className="space-y-2">
                                    <Label>Expected break minutes</Label>
                                    <Input
                                        type="number"
                                        min={0}
                                        max={720}
                                        value={form.data.expected_break_minutes}
                                        onChange={(e) =>
                                            form.setData(
                                                'expected_break_minutes',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="e.g. 30"
                                    />
                                </div>
                            </div>

                            <div className="grid gap-3 md:grid-cols-2">
                                <label className="flex items-center gap-2 text-sm">
                                    <input
                                        type="checkbox"
                                        checked={form.data.is_sleepover}
                                        onChange={(e) =>
                                            form.setData(
                                                'is_sleepover',
                                                e.target.checked,
                                            )
                                        }
                                    />
                                    Sleepover allowance applies
                                </label>
                                <label className="flex items-center gap-2 text-sm">
                                    <input
                                        type="checkbox"
                                        checked={form.data.is_on_call}
                                        onChange={(e) =>
                                            form.setData(
                                                'is_on_call',
                                                e.target.checked,
                                            )
                                        }
                                    />
                                    On-call allowance applies
                                </label>
                            </div>

                            <div className="text-xs text-slate-500">
                                These fields feed payroll, reporting, and roster
                                context. Set them at creation so the shift
                                carries the right operational meaning.
                            </div>
                            {coverageContext?.role_shortages?.length ? (
                                <div className="space-y-2 rounded-md border border-dashed p-3">
                                    <div className="text-sm font-medium">
                                        Required roles for this gap
                                    </div>
                                    <div className="flex flex-wrap gap-2">
                                        {coverageContext.role_shortages.map(
                                            (role) => (
                                                <label
                                                    key={role.key}
                                                    className="flex items-center gap-2 rounded-md border px-3 py-1 text-sm"
                                                >
                                                    <input
                                                        type="checkbox"
                                                        checked={form.data.coverage_roles.includes(
                                                            role.key,
                                                        )}
                                                        onChange={(e) => {
                                                            const next =
                                                                new Set(
                                                                    form.data
                                                                        .coverage_roles,
                                                                );
                                                            if (
                                                                e.target.checked
                                                            ) {
                                                                next.add(
                                                                    role.key,
                                                                );
                                                            } else {
                                                                next.delete(
                                                                    role.key,
                                                                );
                                                            }
                                                            form.setData(
                                                                'coverage_roles',
                                                                Array.from(
                                                                    next,
                                                                ),
                                                            );
                                                        }}
                                                    />
                                                    {role.label ??
                                                        role.key.replace(
                                                            '_',
                                                            ' ',
                                                        )}
                                                </label>
                                            ),
                                        )}
                                    </div>
                                </div>
                            ) : null}
                        </div>

                        <div className="grid gap-4 md:grid-cols-2">
                            <div className="space-y-2">
                                <Label>Start</Label>
                                <Input
                                    type="datetime-local"
                                    value={form.data.starts_at}
                                    onChange={(e) =>
                                        form.setData(
                                            'starts_at',
                                            e.target.value,
                                        )
                                    }
                                />
                            </div>
                            <div className="space-y-2">
                                <Label>End</Label>
                                <Input
                                    type="datetime-local"
                                    value={form.data.ends_at}
                                    onChange={(e) =>
                                        form.setData('ends_at', e.target.value)
                                    }
                                />
                            </div>
                        </div>

                        <div className="space-y-2">
                            <Label>Location</Label>
                            <Input
                                value={form.data.location}
                                onChange={(e) =>
                                    form.setData('location', e.target.value)
                                }
                            />
                        </div>

                        <div className="space-y-2">
                            <Label>Status</Label>
                            <select
                                className="w-full rounded-md border bg-background p-2 text-sm"
                                value={form.data.status}
                                onChange={(e) =>
                                    form.setData('status', e.target.value)
                                }
                            >
                                <option value="draft">draft</option>
                                <option value="scheduled">scheduled</option>
                            </select>
                            <div className="text-xs text-slate-500">
                                Use draft for uncovered planning shifts. Start,
                                complete, and cancel actions happen from the
                                live shift workflow.
                            </div>
                        </div>

                        <div className="space-y-2">
                            <Label>Notes</Label>
                            <textarea
                                className="w-full rounded-md border bg-background p-2 text-sm"
                                value={form.data.notes}
                                onChange={(e) =>
                                    form.setData('notes', e.target.value)
                                }
                                rows={4}
                            />
                        </div>

                        <div className="space-y-2">
                            <Label>Shift tasks (checklist)</Label>
                            <div className="space-y-2">
                                {form.data.tasks.map((t, idx) => (
                                    <div
                                        key={idx}
                                        className="flex items-center gap-2"
                                    >
                                        <Input
                                            value={t.label}
                                            onChange={(e) => {
                                                const next = [
                                                    ...form.data.tasks,
                                                ];
                                                next[idx] = {
                                                    ...next[idx],
                                                    label: e.target.value,
                                                };
                                                form.setData('tasks', next);
                                            }}
                                            placeholder="Task label"
                                        />
                                        <Button
                                            type="button"
                                            variant="outline"
                                            onClick={() => removeTask(idx)}
                                        >
                                            Remove
                                        </Button>
                                    </div>
                                ))}
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={addTask}
                                >
                                    Add task
                                </Button>
                            </div>
                        </div>

                        <div className="space-y-3 rounded-md border p-3">
                            <div className="flex items-center justify-between">
                                <div>
                                    <div className="text-sm font-medium">
                                        Repeat weekly
                                    </div>
                                    <div className="text-xs text-slate-500">
                                        Create a recurring series (weekly) until
                                        an end date.
                                    </div>
                                </div>
                                <input
                                    type="checkbox"
                                    checked={form.data.repeat_weekly}
                                    onChange={(e) =>
                                        form.setData(
                                            'repeat_weekly',
                                            e.target.checked,
                                        )
                                    }
                                />
                            </div>

                            {form.data.repeat_weekly && (
                                <div className="space-y-3">
                                    <div className="space-y-2">
                                        <Label>Repeat on</Label>
                                        <div className="flex flex-wrap gap-2 text-sm">
                                            {(
                                                [
                                                    'mon',
                                                    'tue',
                                                    'wed',
                                                    'thu',
                                                    'fri',
                                                    'sat',
                                                    'sun',
                                                ] as const
                                            ).map((d) => (
                                                <button
                                                    type="button"
                                                    key={d}
                                                    onClick={() =>
                                                        toggleWeekday(d)
                                                    }
                                                    className={`rounded-md border px-3 py-1 ${form.data.repeat_by_weekday.includes(d) ? 'bg-slate-900 text-white dark:bg-white dark:text-slate-900' : ''}`}
                                                >
                                                    {d.toUpperCase()}
                                                </button>
                                            ))}
                                        </div>
                                    </div>

                                    <div className="space-y-2">
                                        <Label>Repeat end date</Label>
                                        <Input
                                            type="date"
                                            value={form.data.repeat_end_date}
                                            onChange={(e) =>
                                                form.setData(
                                                    'repeat_end_date',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                        <div className="text-xs text-slate-500">
                                            Tip: starts/ends time are taken from
                                            the Start/End fields above.
                                        </div>
                                    </div>
                                </div>
                            )}
                        </div>
                    </div>

                    <div className="flex items-center gap-2">
                        <Button type="submit" disabled={form.processing}>
                            Create
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => history.back()}
                        >
                            Cancel
                        </Button>
                    </div>
                </form>
            </PageShell>
        </AppLayout>
    );
}
