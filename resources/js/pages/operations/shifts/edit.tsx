import HeadingSmall from '@/components/heading-small';
import { EligibilityAlertBanner } from '@/components/eligibility/eligibility-alert-banner';
import { EligibilityStatusBadge } from '@/components/eligibility/eligibility-status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { Head, useForm, usePage } from '@inertiajs/react';
import { useState, useEffect, useRef, useCallback } from 'react';
import { Loader2 } from 'lucide-react';

type Client = {
    id: number;
    first_name: string;
    last_name: string;
    service_context_id?: number | null;
};
type Staff = { id: number; name: string; email: string };
type ServiceContext = {
    id: number;
    name: string;
    type: string;
    is_active: boolean;
};

type Props = {
    shift: any;
    clients: Client[];
    staff: Staff[];
    serviceContexts: ServiceContext[];
    defaultServiceContextId?: number | null;
};

export default function ShiftEdit({
    shift,
    clients,
    staff,
    serviceContexts,
    defaultServiceContextId = null,
}: Props) {
    const { auth, labels } = usePage().props as any;
    const shiftLabel = labels?.['shift.singular'] ?? 'Shift';
    const isRecurringSeries = !!shift.shift_series_id;
    const canManageRecurring = !!auth?.can?.shifts?.manageAny;

    const [taskBusyId, setTaskBusyId] = useState<number | null>(null);
    const [taskError, setTaskError] = useState<string | null>(null);

    const form = useForm({
        client_id: shift.client_id,
        service_context_id:
            shift.service_context_id ?? shift?.service_context?.id ?? '',
        user_id: shift.user_id,
        starts_at: shift.starts_at?.slice(0, 16) ?? '',
        ends_at: shift.ends_at?.slice(0, 16) ?? '',
        location: shift.location ?? '',
        notes: shift.notes ?? '',
        status: shift.status === 'draft' ? 'draft' : 'scheduled',
        shift_type: shift.shift_type ?? 'standard',
        is_sleepover: !!shift.is_sleepover,
        is_on_call: !!shift.is_on_call,
        expected_break_minutes: shift.expected_break_minutes ?? '',
        coverage_roles: (shift.coverage_roles ?? []) as string[],
        series_scope: 'this',
        tasks: (shift.tasks ?? []).map((t: any) => ({
            id: t.id,
            label: t.label,
            is_completed: !!t.is_completed,
        })),
    });

    function addTask() {
        form.setData('tasks', [
            ...form.data.tasks,
            { label: '', is_completed: false },
        ] as any);
    }

    function removeTask(idx: number) {
        form.setData(
            'tasks',
            (form.data.tasks as any[]).filter((_, i) => i !== idx) as any,
        );
    }

    async function toggleComplete(task: any, checked: boolean) {
        if (!task.id) return; // new tasks must be saved first
        setTaskBusyId(task.id);
        setTaskError(null);
        try {
            const res = await fetch(
                `/operations/shifts/${shift.id}/tasks/${task.id}`,
                {
                    method: 'PATCH',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': (
                            document.querySelector(
                                'meta[name="csrf-token"]',
                            ) as HTMLMetaElement
                        )?.content,
                    },
                    body: JSON.stringify({ is_completed: checked }),
                    credentials: 'same-origin',
                },
            );
            if (!res.ok) {
                const data = await res.json().catch(() => null);
                throw new Error(data?.message ?? 'Failed to update task');
            }
            const data = await res.json();
            const updated = data?.task;
            form.setData(
                'tasks',
                (form.data.tasks as any[]).map((t) =>
                    t.id === updated.id
                        ? { ...t, is_completed: !!updated.is_completed }
                        : t,
                ) as any,
            );
        } catch (e: any) {
            setTaskError(e?.message ?? 'Failed');
        } finally {
            setTaskBusyId(null);
        }
    }

    // ── Eligibility preview ─────────────────────────────────────────
    const [eligPreview, setEligPreview] = useState<any>(null);
    const [eligLoading, setEligLoading] = useState(false);
    const eligAbort = useRef<AbortController | null>(null);
    const eligTimer = useRef<ReturnType<typeof setTimeout> | null>(null);

    const fetchEligibility = useCallback(() => {
        const userId = form.data.user_id;
        const startsAt = form.data.starts_at;
        const endsAt = form.data.ends_at;

        if (!userId || !startsAt || !endsAt) {
            setEligPreview(null);
            return;
        }

        if (eligTimer.current) clearTimeout(eligTimer.current);
        eligTimer.current = setTimeout(async () => {
            eligAbort.current?.abort();
            const controller = new AbortController();
            eligAbort.current = controller;
            setEligLoading(true);

            try {
                const params = new URLSearchParams({
                    user_id: String(userId),
                    starts_at: startsAt,
                    ends_at: endsAt,
                    shift_id: String(shift.id),
                });

                if (shift.site_id) params.set('site_id', String(shift.site_id));
                if (form.data.shift_type) params.set('shift_type', form.data.shift_type);
                if (form.data.coverage_roles?.length) {
                    form.data.coverage_roles.forEach((r: string) => params.append('coverage_roles[]', r));
                }

                const res = await fetch(`/operations/shifts/eligibility-preview?${params}`, {
                    signal: controller.signal,
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                });
                if (!res.ok) throw new Error('preview failed');
                const data = await res.json();
                if (!controller.signal.aborted) setEligPreview(data);
            } catch {
                // Abort or network error — silently ignore
            } finally {
                if (!controller.signal.aborted) setEligLoading(false);
            }
        }, 500);
    }, [form.data.user_id, form.data.starts_at, form.data.ends_at, form.data.shift_type, form.data.coverage_roles, shift.id, shift.site_id]);

    useEffect(() => {
        fetchEligibility();
        return () => {
            eligAbort.current?.abort();
            if (eligTimer.current) clearTimeout(eligTimer.current);
        };
    }, [fetchEligibility]);
    // ── End eligibility preview ──────────────────────────────────────

    return (
        <AppLayout
            breadcrumbs={[
                {
                    title: labels?.['shift.plural'] ?? 'Shifts',
                    href: '/shifts',
                },
                { title: `Edit`, href: `/operations/shifts/${shift.id}/edit` },
            ]}
        >
            <Head title={`Edit ${shiftLabel}`} />
            <div className="max-w-2xl space-y-6 p-4">
                <HeadingSmall
                    title={`Edit ${shiftLabel}`}
                    description="Update an appointment / shift."
                />

                {/* Eligibility status for current assignment */}
                {form.data.user_id && eligLoading ? (
                    <div className="flex items-center gap-2 text-xs text-muted-foreground">
                        <Loader2 className="size-3 animate-spin" />
                        Checking eligibility...
                    </div>
                ) : null}
                {form.data.user_id && !eligLoading && eligPreview ? (
                    <>
                        {eligPreview.blocked_reasons?.length > 0 ? (
                            <EligibilityAlertBanner
                                type="blocked"
                                reasons={eligPreview.blocked_reasons}
                                title="Current assignee is no longer eligible"
                            />
                        ) : eligPreview.warning_reasons?.length > 0 ? (
                            <EligibilityAlertBanner
                                type="warnings"
                                reasons={eligPreview.warning_reasons}
                                title="Current assignee has eligibility warnings"
                            />
                        ) : null}
                    </>
                ) : null}

                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.put(`/operations/shifts/${shift.id}`);
                    }}
                    className="space-y-4"
                >
                    <div className="space-y-4 rounded-md border p-4">
                        <div className="space-y-2">
                            <Label>Client</Label>
                            <select
                                className="w-full rounded-md border bg-background p-2 text-sm"
                                value={form.data.client_id}
                                onChange={(e) => {
                                    const nextId = e.target.value;
                                    form.setData('client_id', nextId);
                                    if (!form.data.service_context_id) {
                                        const client = clients.find(
                                            (c) =>
                                                String(c.id) === String(nextId),
                                        );
                                        const inherited =
                                            client?.service_context_id ??
                                            defaultServiceContextId;
                                        if (inherited) {
                                            form.setData(
                                                'service_context_id',
                                                inherited as any,
                                            );
                                        }
                                    }
                                }}
                            >
                                {clients.map((c) => (
                                    <option key={c.id} value={c.id}>
                                        {c.first_name} {c.last_name}
                                    </option>
                                ))}
                            </select>
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
                            <div className="text-xs text-muted-foreground">
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
                                        value={
                                            form.data
                                                .expected_break_minutes as any
                                        }
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
                                        checked={!!form.data.is_sleepover}
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
                                        checked={!!form.data.is_on_call}
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

                            <div className="text-xs text-muted-foreground">
                                These settings follow the shift into timesheets
                                and reporting, so this is the right place to
                                correct them.
                            </div>

                            <div className="space-y-2">
                                <Label>Coverage roles</Label>
                                <div className="grid gap-2 md:grid-cols-3">
                                    {(
                                        [
                                            [
                                                'caregiver',
                                                'General caregiver coverage',
                                            ],
                                            ['driver', 'Driver coverage'],
                                            [
                                                'med_competent',
                                                'Medication-competent coverage',
                                            ],
                                        ] as const
                                    ).map(([role, label]) => (
                                        <label
                                            key={role}
                                            className="flex items-center gap-2 rounded-md border p-3 text-sm"
                                        >
                                            <input
                                                type="checkbox"
                                                checked={(
                                                    form.data
                                                        .coverage_roles as string[]
                                                ).includes(role)}
                                                onChange={(e) =>
                                                    form.setData(
                                                        'coverage_roles',
                                                        e.target.checked
                                                            ? Array.from(
                                                                  new Set([
                                                                      ...(form
                                                                          .data
                                                                          .coverage_roles as string[]),
                                                                      role,
                                                                  ]),
                                                              )
                                                            : (
                                                                  form.data
                                                                      .coverage_roles as string[]
                                                              ).filter(
                                                                  (value) =>
                                                                      value !==
                                                                      role,
                                                              ),
                                                    )
                                                }
                                            />
                                            {label}
                                        </label>
                                    ))}
                                </div>
                                <div className="text-xs text-muted-foreground">
                                    Use this when the shift is intended to meet
                                    a specific house coverage role, not just
                                    general headcount.
                                </div>
                            </div>
                        </div>

                        {isRecurringSeries ? (
                            <div className="space-y-2 rounded-md border p-3">
                                <Label>Recurring update scope</Label>
                                <select
                                    className="w-full rounded-md border bg-background p-2 text-sm"
                                    value={form.data.series_scope}
                                    onChange={(e) =>
                                        form.setData(
                                            'series_scope',
                                            e.target.value as 'this' | 'future',
                                        )
                                    }
                                >
                                    <option value="this">
                                        This occurrence only
                                    </option>
                                    {canManageRecurring ? (
                                        <option value="future">
                                            This and future occurrences
                                        </option>
                                    ) : null}
                                </select>
                                <div className="text-xs text-muted-foreground">
                                    Use this to update the roster pattern from
                                    this shift onward without changing completed
                                    or cancelled history.
                                </div>
                                {!canManageRecurring ? (
                                    <div className="text-xs text-muted-foreground">
                                        Future-occurrence updates are limited to
                                        schedulers and managers.
                                    </div>
                                ) : null}
                                {form.errors.series_scope ? (
                                    <div className="text-xs text-red-500">
                                        {form.errors.series_scope}
                                    </div>
                                ) : null}
                            </div>
                        ) : null}

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
                            <div className="text-xs text-muted-foreground">
                                Lifecycle changes now happen from the shift
                                workspace so planning edits stay auditable.
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
                            {taskError && (
                                <div className="text-sm text-red-500">
                                    {taskError}
                                </div>
                            )}
                            <div className="space-y-2">
                                {(form.data.tasks as any[]).map((t, idx) => (
                                    <div
                                        key={t.id ?? idx}
                                        className="flex items-center gap-2"
                                    >
                                        <input
                                            type="checkbox"
                                            checked={!!t.is_completed}
                                            disabled={
                                                !t.id || taskBusyId === t.id
                                            }
                                            onChange={(e) =>
                                                toggleComplete(
                                                    t,
                                                    e.target.checked,
                                                )
                                            }
                                        />
                                        <Input
                                            value={t.label}
                                            onChange={(e) => {
                                                const next = [
                                                    ...(form.data
                                                        .tasks as any[]),
                                                ];
                                                next[idx] = {
                                                    ...next[idx],
                                                    label: e.target.value,
                                                };
                                                form.setData(
                                                    'tasks',
                                                    next as any,
                                                );
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
                                <div className="text-xs text-muted-foreground">
                                    Tip: Save to persist new tasks. Completed
                                    state can be toggled for saved tasks.
                                </div>
                            </div>
                        </div>
                    </div>

                    {/* Submission error banner for eligibility failures */}
                    {form.errors.user_id ? (
                        <EligibilityAlertBanner
                            type="blocked"
                            reasons={[form.errors.user_id]}
                            title="Assignment blocked"
                        />
                    ) : null}

                    <div className="flex items-center gap-2">
                        <Button type="submit" disabled={form.processing}>
                            Save
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
            </div>
        </AppLayout>
    );
}
