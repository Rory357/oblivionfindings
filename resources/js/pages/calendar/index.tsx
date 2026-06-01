import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { useCreateShiftLauncher } from '@/pages/operations/shifts/components/use-create-shift-launcher';
import { Head, usePage } from '@inertiajs/react';

import type {
    DateSelectArg,
    EventClickArg,
    EventDropArg,
} from '@fullcalendar/core';
import dayGridPlugin from '@fullcalendar/daygrid';
import interactionPlugin, {
    type EventResizeDoneArg,
} from '@fullcalendar/interaction';
import listPlugin from '@fullcalendar/list';
import FullCalendar from '@fullcalendar/react';
import timeGridPlugin from '@fullcalendar/timegrid';

// FullCalendar v6 bundles CSS into JS automatically — no separate CSS import needed.

import { useCallback, useMemo, useRef, useState } from 'react';

type Props = {
    canManageAny: boolean;
    staff: Array<{ id: number; name: string; email: string }>;
    clients: Array<{
        id: number;
        first_name: string;
        last_name: string;
        service_context_id?: number | null;
        site?: { id: number; name: string } | null;
    }>;
    serviceContexts: Array<{
        id: number;
        name: string;
        type: string;
        is_active: boolean;
    }>;
    defaultServiceContextId?: number | null;
};

type ShiftForm = {
    id?: number;
    client_id: number | '';
    service_context_id: number | '';
    user_id: number | '';
    starts_at: string;
    ends_at: string;
    location: string;
    status: 'draft' | 'scheduled';
    shift_type: 'standard' | 'sleepover' | 'on_call' | 'split' | 'travel';
    is_sleepover: boolean;
    is_on_call: boolean;
    expected_break_minutes: number | '';
    coverage_roles: string[];
    notes: string;
};

type TimedShiftTask = {
    id: number;
    label: string;
    scheduled_time: string | null;
    is_completed?: boolean;
};

type ShiftViewInfo = {
    id?: number;
    eventType?: string;
    client?: string;
    staff?: string;
    shiftType?: string;
    status?: string;
    serviceContext?: string;
    location?: string;
    expectedBreakMinutes?: number | null;
    shiftSeriesId?: number | null;
    isRecurring?: boolean;
    replacementStatus?: string | null;
    hasActiveReplacement?: boolean;
    tasksTotal?: number;
    tasksCompleted?: number;
    tasks?: TimedShiftTask[];
    timedTasks?: TimedShiftTask[];
    incidentsCount?: number;
    isOpenShift?: boolean;
    siteId?: number | null;
    siteName?: string;
    coverageState?: string | null;
    coverageGapKind?: string | null;
    coverageRecommendedFillAction?: string | null;
    coverageMissingStaff?: number;
    coverageRequiredStaff?: number | null;
    coverageAssignedStaff?: number | null;
    coverageWindowLabel?: string | null;
    coverageRuleName?: string | null;
    coverageRuleId?: number | null;
    coveragePreferredClientId?: number | null;
    coverageRoleShortages?: Array<{
        key: string;
        label?: string | null;
        missing?: number;
    }>;
    coveragePlannedRoleShortages?: Array<{
        key: string;
        label?: string | null;
        missing?: number;
    }>;
    coverageContradictions?: string[];
};

function eventTone(
    status?: string,
    isOpenShift?: boolean,
    hasActiveReplacement?: boolean,
) {
    if (status === 'cancelled') return 'border-border bg-muted text-foreground';
    if (status === 'completed')
        return 'border-status-success/30 bg-status-success-bg text-status-success';
    if (hasActiveReplacement)
        return 'border-status-warning/30 bg-status-warning-bg text-status-warning';
    if (isOpenShift)
        return 'border-status-critical/30 bg-status-critical-bg text-status-critical';
    if (status === 'in_progress')
        return 'border-status-info/30 bg-status-info-bg text-status-info';
    return 'border-border bg-background text-foreground';
}

function pad2(n: number) {
    return String(n).padStart(2, '0');
}

function toDatetimeLocalValue(d: Date) {
    return `${d.getFullYear()}-${pad2(d.getMonth() + 1)}-${pad2(d.getDate())}T${pad2(
        d.getHours(),
    )}:${pad2(d.getMinutes())}`;
}

function addHours(date: Date, hours: number) {
    const d = new Date(date);
    d.setHours(d.getHours() + hours);
    return d;
}

function getCsrfToken() {
    return (
        document.querySelector(
            'meta[name="csrf-token"]',
        ) as HTMLMetaElement | null
    )?.content;
}

function coverageRolesForAction(viewInfo?: ShiftViewInfo | null) {
    return (
        (viewInfo?.coveragePlannedRoleShortages?.length
            ? viewInfo.coveragePlannedRoleShortages
            : viewInfo?.coverageRoleShortages) ?? []
    );
}

function gapKindLabel(kind?: string | null) {
    switch (kind) {
        case 'headcount_open':
            return 'Open shift gap';
        case 'headcount_unplanned':
            return 'Unplanned headcount gap';
        case 'role_open':
            return 'Open role gap';
        case 'role_unplanned':
            return 'Unplanned role gap';
        case 'mixed_open':
            return 'Open shift + role gap';
        case 'mixed_unplanned':
            return 'Headcount + role gap';
        case 'overfill_not_allowed':
            return 'Overfill not allowed';
        case 'overfilled_wrong_role_mix':
            return 'Overfilled role imbalance';
        case 'overfill_and_role_imbalance':
            return 'Overfill + role imbalance';
        default:
            return 'Coverage gap';
    }
}

function fillActionLabel(action?: string | null) {
    switch (action) {
        case 'fill_existing_open_shift':
            return 'Fill existing open shift';
        case 'retag_or_replace_open_shift':
            return 'Retag or replace open shift';
        case 'create_role_specific_shift':
            return 'Create role-specific cover';
        case 'create_recurring_cover':
            return 'Create recurring cover';
        case 'review_existing_supply':
            return 'Review existing supply';
        case 'rebalance_existing_supply':
            return 'Rebalance existing supply';
        default:
            return 'Create cover shift';
    }
}

function shouldOfferCreation(action?: string | null) {
    return !['review_existing_supply', 'rebalance_existing_supply'].includes(
        action ?? '',
    );
}

async function jsonRequest<T>(
    url: string,
    opts: { method: string; body?: any },
): Promise<T> {
    const token = getCsrfToken();

    const res = await fetch(url, {
        method: opts.method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            ...(token ? { 'X-CSRF-TOKEN': token } : {}),
        },
        body: opts.body ? JSON.stringify(opts.body) : undefined,
    });

    if (!res.ok) {
        let message = `Request failed (${res.status})`;
        try {
            const data = await res.json();
            message =
                data?.message ||
                Object.values(data?.errors ?? {})?.flat?.()?.[0] ||
                message;
        } catch {
            // ignore
        }
        throw new Error(message);
    }

    return res.json();
}

export default function CalendarIndex(props: Props) {
    const defaultServiceContextId = props.defaultServiceContextId ?? null;
    const { auth, labels } = usePage().props as any;

    const canManageAny = !!(props.canManageAny && auth?.can?.shifts?.manageAny);
    const canCreate = !!auth?.can?.shifts?.create;
    const canUpdate = !!auth?.can?.shifts?.update;

    const staffOptions = useMemo(
        () =>
            (props.staff ?? []).map((u) => ({
                id: u.id,
                label: u.name,
            })),
        [props.staff],
    );

    const clientOptions = useMemo(
        () =>
            (props.clients ?? []).map((c) => ({
                id: c.id,
                label: `${c.first_name} ${c.last_name}`,
                service_context_id: c.service_context_id ?? null,
                site_name: c.site?.name ?? '',
            })),
        [props.clients],
    );

    const clientServiceContextById = useMemo(() => {
        const m = new Map<number, number | null>();
        for (const c of props.clients ?? []) {
            m.set(c.id, c.service_context_id ?? null);
        }
        return m;
    }, [props.clients]);

    const clientLocationById = useMemo(() => {
        const m = new Map<number, string>();
        for (const c of props.clients ?? []) {
            m.set(c.id, c.site?.name?.trim() ?? '');
        }
        return m;
    }, [props.clients]);

    const locationForClientId = useCallback(
        (selectedClientId: number | '' | null | undefined): string => {
            if (
                selectedClientId === '' ||
                selectedClientId === null ||
                selectedClientId === undefined
            ) {
                return '';
            }

            return clientLocationById.get(Number(selectedClientId)) ?? '';
        },
        [clientLocationById],
    );

    const serviceContextOptions = useMemo(() => {
        return (props.serviceContexts ?? []).map((sc) => ({
            id: sc.id,
            label: sc.name,
            is_active: !!sc.is_active,
        }));
    }, [props.serviceContexts]);

    const [staffId, setStaffId] = useState<string>('all');
    const [clientId, setClientId] = useState<string>('all');

    const [rangeSummary, setRangeSummary] = useState<{
        total: number;
        hours: number;
        scheduled: number;
        completed: number;
        cancelled: number;
        coverageGaps: number;
    }>({
        total: 0,
        hours: 0,
        scheduled: 0,
        completed: 0,
        cancelled: 0,
        coverageGaps: 0,
    });

    const calendarRef = useRef<FullCalendar | null>(null);
    const loadEvents = useCallback(
        async (info: any, successCallback: any, failureCallback: any) => {
            try {
                const params = new URLSearchParams({
                    start: info.startStr,
                    end: info.endStr,
                });

                if (canManageAny && staffId !== 'all') {
                    params.set('staff_id', staffId);
                }
                if (canManageAny && clientId !== 'all') {
                    params.set('client_id', clientId);
                }

                const res = await fetch(
                    `/calendar/events?${params.toString()}`,
                    {
                        headers: { Accept: 'application/json' },
                        credentials: 'same-origin',
                    },
                );

                if (!res.ok) {
                    throw new Error(`Failed to load events: ${res.status}`);
                }

                const data = await res.json();

                // Summary for current range (avoid causing refetch loops)
                try {
                    const summary = {
                        total: 0,
                        hours: 0,
                        scheduled: 0,
                        completed: 0,
                        cancelled: 0,
                        coverageGaps: 0,
                    };

                    for (const ev of data ?? []) {
                        if (ev?.extendedProps?.event_type === 'coverage_gap') {
                            summary.coverageGaps += 1;
                            continue;
                        }
                        summary.total += 1;

                        const status = ev?.extendedProps?.status ?? 'scheduled';
                        if (status === 'completed') summary.completed += 1;
                        else if (status === 'cancelled') summary.cancelled += 1;
                        else summary.scheduled += 1;

                        const start = ev?.start ? new Date(ev.start) : null;
                        const end = ev?.end ? new Date(ev.end) : null;
                        if (
                            start instanceof Date &&
                            !isNaN(start.getTime()) &&
                            end instanceof Date &&
                            !isNaN(end.getTime())
                        ) {
                            summary.hours +=
                                (end.getTime() - start.getTime()) / 36e5;
                        }
                    }

                    summary.hours = Math.round(summary.hours * 10) / 10;

                    setRangeSummary((prev) => {
                        const same =
                            prev.total === summary.total &&
                            prev.hours === summary.hours &&
                            prev.scheduled === summary.scheduled &&
                            prev.completed === summary.completed &&
                            prev.cancelled === summary.cancelled &&
                            prev.coverageGaps === summary.coverageGaps;
                        return same ? prev : summary;
                    });
                } catch {
                    // ignore summary failure
                }

                successCallback(data);
            } catch (e) {
                console.error(e);
                failureCallback(e as any);
            }
        },
        [canManageAny, staffId, clientId],
    );

    const [modalOpen, setModalOpen] = useState(false);
    const [modalMode, setModalMode] = useState<'create' | 'edit'>('create');
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const createShiftLauncher = useCreateShiftLauncher();
    const [viewInfo, setViewInfo] = useState<ShiftViewInfo | null>(null);
    const [form, setForm] = useState<ShiftForm>({
        client_id: '',
        service_context_id: (defaultServiceContextId ?? '') as any,
        user_id: '',
        starts_at: '',
        ends_at: '',
        location: '',
        status: 'scheduled',
        shift_type: 'standard',
        is_sleepover: false,
        is_on_call: false,
        expected_break_minutes: '',
        coverage_roles: [],
        notes: '',
    });

    const shiftLabel = labels?.['shift.singular'] ?? 'Shift';

    function openCreate(start: Date, end: Date) {
        const prefillStaff =
            canManageAny && staffId !== 'all' ? Number(staffId) : '';
        const prefillClient =
            canManageAny && clientId !== 'all' ? Number(clientId) : '';

        const prefillServiceContext =
            prefillClient !== ''
                ? (clientServiceContextById.get(Number(prefillClient)) ?? '')
                : '';

        setModalMode('create');
        setError(null);
        setViewInfo(null);
        setForm({
            id: undefined,
            client_id: prefillClient,
            service_context_id: (prefillServiceContext === null
                ? (defaultServiceContextId ?? '')
                : prefillServiceContext) as any,
            user_id: prefillStaff,
            starts_at: toDatetimeLocalValue(start),
            ends_at: toDatetimeLocalValue(end),
            location: locationForClientId(prefillClient),
            status: 'scheduled',
            shift_type: 'standard',
            is_sleepover: false,
            is_on_call: false,
            expected_break_minutes: '',
            coverage_roles: [],
            notes: '',
        });
        setModalOpen(true);
    }

    function openEdit(arg: EventClickArg) {
        const ext = (arg.event.extendedProps ?? {}) as any;
        const start = arg.event.start ? new Date(arg.event.start) : new Date();
        const end = arg.event.end
            ? new Date(arg.event.end)
            : addHours(start, 1);

        setModalMode('edit');
        setError(null);
        setViewInfo({
            id: Number(arg.event.id),
            eventType: ext.event_type ?? 'shift',
            client: ext.client,
            staff: ext.staff,
            shiftType: ext.shift_type ?? 'standard',
            status: ext.status ?? 'scheduled',
            serviceContext: ext.service_context ?? '',
            location: ext.location ?? '',
            expectedBreakMinutes: ext.expected_break_minutes ?? null,
            shiftSeriesId: ext.shift_series_id ?? null,
            isRecurring: !!ext.is_recurring,
            replacementStatus: ext.replacement_status ?? null,
            hasActiveReplacement: !!ext.has_active_replacement,
            tasksTotal: ext.tasks_total ?? 0,
            tasksCompleted: ext.tasks_completed ?? 0,
            tasks: ext.tasks ?? [],
            timedTasks: ext.timed_tasks ?? ext.tasks ?? [],
            incidentsCount: ext.incidents_count ?? 0,
            isOpenShift: !!ext.is_open_shift,
            siteId: ext.site_id ?? null,
            siteName: ext.site_name ?? null,
            coverageState: ext.coverage_state ?? null,
            coverageGapKind: ext.coverage_gap_kind ?? null,
            coverageRecommendedFillAction:
                ext.coverage_recommended_fill_action ?? null,
            coverageMissingStaff: ext.coverage_missing_staff ?? 0,
            coverageRequiredStaff: ext.coverage_required_staff ?? null,
            coverageAssignedStaff: ext.coverage_assigned_staff ?? null,
            coverageWindowLabel: ext.coverage_window_label ?? null,
            coverageRuleName: ext.rule_name ?? null,
            coverageRuleId: ext.coverage_rule_id ?? null,
            coveragePreferredClientId: ext.coverage_preferred_client_id ?? null,
            coverageRoleShortages: ext.coverage_role_shortages ?? [],
            coveragePlannedRoleShortages:
                ext.coverage_planned_role_shortages ?? [],
            coverageContradictions: ext.coverage_contradictions ?? [],
        });
        if (ext.event_type === 'coverage_gap') {
            setModalMode('edit');
            setForm({
                id: undefined,
                client_id: '',
                service_context_id: '',
                user_id: '',
                starts_at: toDatetimeLocalValue(start),
                ends_at: toDatetimeLocalValue(end),
                location: '',
                status: 'draft',
                shift_type: 'standard',
                is_sleepover: false,
                is_on_call: false,
                expected_break_minutes: '',
                coverage_roles: coverageRolesForAction({
                    coveragePlannedRoleShortages:
                        ext.coverage_planned_role_shortages ?? [],
                    coverageRoleShortages: ext.coverage_role_shortages ?? [],
                }).map((role) => role.key),
                notes: '',
            });
            setModalOpen(true);
            return;
        }
        setForm({
            id: Number(arg.event.id),
            client_id: ext.client_id ?? '',
            service_context_id: ext.service_context_id ?? '',
            user_id: ext.user_id ?? '',
            starts_at: toDatetimeLocalValue(start),
            ends_at: toDatetimeLocalValue(end),
            location: ext.location ?? '',
            status: ext.status === 'draft' ? 'draft' : 'scheduled',
            shift_type: (ext.shift_type ?? 'standard') as any,
            is_sleepover: !!ext.is_sleepover,
            is_on_call: !!ext.is_on_call,
            expected_break_minutes: ext.expected_break_minutes ?? '',
            coverage_roles: ext.coverage_roles ?? [],
            notes: ext.notes ?? '',
        });
        setModalOpen(true);
    }

    async function saveShift() {
        setSaving(true);
        setError(null);

        try {
            const payload = {
                client_id:
                    form.client_id === '' ? null : Number(form.client_id),
                service_context_id:
                    form.service_context_id === ''
                        ? null
                        : Number(form.service_context_id),
                user_id: form.user_id === '' ? null : Number(form.user_id),
                starts_at: form.starts_at,
                ends_at: form.ends_at,
                location: form.location,
                status: form.status,
                shift_type: form.shift_type,
                is_sleepover: form.is_sleepover,
                is_on_call: form.is_on_call,
                expected_break_minutes:
                    form.expected_break_minutes === ''
                        ? null
                        : Number(form.expected_break_minutes),
                coverage_roles: form.coverage_roles,
                notes: form.notes,
            };

            if (modalMode === 'create') {
                await jsonRequest('/calendar/shifts', {
                    method: 'POST',
                    body: payload,
                });
            } else {
                if (!form.id) throw new Error('Missing shift id');
                await jsonRequest(`/calendar/shifts/${form.id}`, {
                    method: 'PATCH',
                    body: payload,
                });
            }

            setModalOpen(false);
            calendarRef.current?.getApi()?.refetchEvents();
        } catch (e: any) {
            setError(e?.message ?? 'Something went wrong');
        } finally {
            setSaving(false);
        }
    }

    async function patchEventTime(id: string, start: Date, end: Date | null) {
        const endSafe = end ?? addHours(start, 1);
        await jsonRequest(`/calendar/shifts/${id}`, {
            method: 'PATCH',
            body: {
                starts_at: toDatetimeLocalValue(start),
                ends_at: toDatetimeLocalValue(endSafe),
            },
        });
    }

    async function onEventDrop(arg: EventDropArg) {
        if (!canUpdate) {
            arg.revert();
            return;
        }
        try {
            await patchEventTime(arg.event.id, arg.event.start!, arg.event.end);
        } catch (e) {
            console.error(e);
            arg.revert();
        }
    }

    async function onEventResize(arg: EventResizeDoneArg) {
        if (!canUpdate) {
            arg.revert();
            return;
        }
        try {
            await patchEventTime(arg.event.id, arg.event.start!, arg.event.end);
        } catch (e) {
            console.error(e);
            arg.revert();
        }
    }

    return (
        <AppLayout breadcrumbs={[{ title: 'Calendar', href: '/calendar' }]}>
            <Head title="Calendar" />

            <div className="space-y-4 p-4">
                <Card>
                    <CardHeader className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div className="space-y-1">
                            <CardTitle>Calendar</CardTitle>
                            <div className="text-xs text-muted-foreground">
                                {canCreate
                                    ? `Click + drag to create a ${shiftLabel.toLowerCase()}.`
                                    : 'Click an item to view details.'}
                            </div>
                        </div>

                        {canManageAny && (
                            <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
                                <div className="grid gap-1">
                                    <Label>Staff</Label>
                                    <Select
                                        value={staffId}
                                        onValueChange={(v) => setStaffId(v)}
                                    >
                                        <SelectTrigger className="w-[220px]">
                                            <SelectValue placeholder="All staff" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="all">
                                                All staff
                                            </SelectItem>
                                            {staffOptions.map((u) => (
                                                <SelectItem
                                                    key={u.id}
                                                    value={String(u.id)}
                                                >
                                                    {u.label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>

                                <div className="grid gap-1">
                                    <Label>
                                        {labels?.['client.singular'] ??
                                            'Client'}
                                    </Label>
                                    <Select
                                        value={clientId}
                                        onValueChange={(v) => setClientId(v)}
                                    >
                                        <SelectTrigger className="w-[220px]">
                                            <SelectValue
                                                placeholder={`All ${(labels?.['client.plural'] ?? 'Clients').toLowerCase()}`}
                                            />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="all">
                                                {`All ${(labels?.['client.plural'] ?? 'Clients').toLowerCase()}`}
                                            </SelectItem>
                                            {clientOptions.map((c) => (
                                                <SelectItem
                                                    key={c.id}
                                                    value={String(c.id)}
                                                >
                                                    {c.label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                            </div>
                        )}
                    </CardHeader>

                    <CardContent>
                        <div className="mb-4 grid gap-3 rounded-lg border p-3 text-xs sm:grid-cols-5">
                            <div>
                                <div className="text-muted-foreground">
                                    Total
                                </div>
                                <div className="mt-1 text-sm font-semibold">
                                    {rangeSummary.total}
                                </div>
                            </div>
                            <div>
                                <div className="text-muted-foreground">
                                    Hours
                                </div>
                                <div className="mt-1 text-sm font-semibold">
                                    {rangeSummary.hours.toFixed(1)}
                                </div>
                            </div>
                            <div>
                                <div className="text-muted-foreground">
                                    Scheduled
                                </div>
                                <div className="mt-1 text-sm font-semibold">
                                    {rangeSummary.scheduled}
                                </div>
                            </div>
                            <div>
                                <div className="text-muted-foreground">
                                    Completed
                                </div>
                                <div className="mt-1 text-sm font-semibold">
                                    {rangeSummary.completed}
                                </div>
                            </div>
                            <div>
                                <div className="text-muted-foreground">
                                    Cancelled
                                </div>
                                <div className="mt-1 text-sm font-semibold">
                                    {rangeSummary.cancelled}
                                </div>
                            </div>
                            <div>
                                <div className="text-muted-foreground">
                                    Coverage gaps
                                </div>
                                <div className="mt-1 text-sm font-semibold text-status-critical">
                                    {rangeSummary.coverageGaps}
                                </div>
                            </div>
                        </div>

                        <div className="mb-4 grid gap-2 rounded-lg border p-3 text-xs text-muted-foreground sm:grid-cols-4">
                            <div className="flex items-center gap-2">
                                <span className="inline-block h-2.5 w-2.5 rounded-full bg-status-critical" />
                                Open or unassigned shift
                            </div>
                            <div className="flex items-center gap-2">
                                <span className="inline-block h-2.5 w-2.5 rounded-full bg-status-warning" />
                                Replacement in progress
                            </div>
                            <div className="flex items-center gap-2">
                                <span className="inline-block h-2.5 w-2.5 rounded-full bg-status-info" />
                                In progress
                            </div>
                            <div className="flex items-center gap-2">
                                <span className="inline-block h-2.5 w-2.5 rounded-full bg-status-success" />
                                Completed
                            </div>
                            <div className="flex items-center gap-2">
                                <span className="inline-block h-2.5 w-2.5 rounded-full bg-status-critical" />
                                Background coverage gap
                            </div>
                        </div>

                        <div className="of-calendar">
                            <FullCalendar
                                ref={(r) => {
                                    // @ts-ignore
                                    calendarRef.current = r;
                                }}
                                plugins={[
                                    dayGridPlugin,
                                    timeGridPlugin,
                                    listPlugin,
                                    interactionPlugin,
                                ]}
                                // Default view: month (requested)
                                initialView="dayGridMonth"
                                height="auto"
                                headerToolbar={{
                                    left: 'prev,next today',
                                    center: 'title',
                                    right: 'dayGridMonth,timeGridWeek,timeGridDay,listWeek',
                                }}
                                nowIndicator
                                selectable={canCreate}
                                editable={canUpdate}
                                eventResizableFromStart
                                selectMirror
                                eventTimeFormat={{
                                    hour: '2-digit',
                                    minute: '2-digit',
                                    meridiem: false,
                                }}
                                events={loadEvents}
                                eventContent={(arg) => {
                                    const ext = (arg.event.extendedProps ??
                                        {}) as any;
                                    const timedTasks =
                                        ext.timed_tasks ?? ext.tasks ?? [];
                                    const client =
                                        ext.client ?? arg.event.title;
                                    const staff = ext.staff;
                                    const loc = ext.location;
                                    const shiftType = String(
                                        ext.shift_type ?? 'standard',
                                    ).replace('_', ' ');
                                    return (
                                        <div
                                            className={`min-w-0 rounded-md border px-1.5 py-1 ${eventTone(
                                                ext.status,
                                                ext.is_open_shift,
                                                ext.has_active_replacement,
                                            )}`}
                                        >
                                            <div className="truncate text-xs font-medium">
                                                {client}
                                            </div>
                                            {(staff || loc) && (
                                                <div className="truncate text-[11px] opacity-80">
                                                    {staff ? staff : null}
                                                    {staff && loc
                                                        ? ' · '
                                                        : null}
                                                    {loc ? loc : null}
                                                </div>
                                            )}
                                            <div className="truncate text-[10px] uppercase opacity-70">
                                                {shiftType}
                                            </div>
                                            <div className="mt-1 flex flex-wrap gap-1">
                                                {ext.is_open_shift ? (
                                                    <span className="rounded-full border px-1 py-0.5 text-[9px] font-medium tracking-wide uppercase">
                                                        Open
                                                    </span>
                                                ) : null}
                                                {ext.is_recurring ? (
                                                    <span className="rounded-full border px-1 py-0.5 text-[9px] font-medium tracking-wide uppercase">
                                                        Recurring
                                                    </span>
                                                ) : null}
                                                {ext.has_active_replacement ? (
                                                    <span className="rounded-full border px-1 py-0.5 text-[9px] font-medium tracking-wide uppercase">
                                                        Replacement
                                                    </span>
                                                ) : null}
                                                {(ext.incidents_count ?? 0) >
                                                0 ? (
                                                    <span className="rounded-full border px-1 py-0.5 text-[9px] font-medium tracking-wide uppercase">
                                                        {ext.incidents_count}{' '}
                                                        incident
                                                    </span>
                                                ) : null}
                                                {timedTasks[0]
                                                    ?.scheduled_time ? (
                                                    <span className="rounded-full border px-1 py-0.5 text-[9px] font-medium tracking-wide uppercase">
                                                        {
                                                            timedTasks[0]
                                                                .scheduled_time
                                                        }
                                                    </span>
                                                ) : null}
                                            </div>
                                        </div>
                                    );
                                }}
                                eventDidMount={(info) => {
                                    const ext = (info.event.extendedProps ??
                                        {}) as any;
                                    const timedTasks =
                                        ext.timed_tasks ?? ext.tasks ?? [];
                                    const lines = [
                                        ext.client
                                            ? `Client: ${ext.client}`
                                            : null,
                                        ext.shift_type
                                            ? `Type: ${String(ext.shift_type).replace('_', ' ')}`
                                            : null,
                                        ext.service_context
                                            ? `Service context: ${ext.service_context}`
                                            : null,
                                        ext.staff
                                            ? `Staff: ${ext.staff}`
                                            : null,
                                        ext.location
                                            ? `Location: ${ext.location}`
                                            : null,
                                        ext.is_recurring
                                            ? 'Recurring series'
                                            : null,
                                        ext.has_active_replacement
                                            ? `Replacement: ${ext.replacement_status ?? 'active'}`
                                            : null,
                                        ext.tasks_total != null
                                            ? `Tasks: ${ext.tasks_completed ?? 0}/${ext.tasks_total}`
                                            : null,
                                        timedTasks.length
                                            ? `Timed tasks: ${timedTasks
                                                  .map(
                                                      (task: TimedShiftTask) =>
                                                          `${task.scheduled_time} ${task.label}`,
                                                  )
                                                  .join(', ')}`
                                            : null,
                                        ext.incidents_count
                                            ? `Incidents: ${ext.incidents_count}`
                                            : null,
                                        ext.coverage_state === 'under'
                                            ? `Coverage gap: missing ${ext.coverage_missing_staff ?? 0}`
                                            : null,
                                        ext.coverage_gap_kind
                                            ? `Coverage type: ${gapKindLabel(ext.coverage_gap_kind)}`
                                            : null,
                                        (
                                            ext.coverage_planned_role_shortages ??
                                            ext.coverage_role_shortages ??
                                            []
                                        ).length > 0
                                            ? `Role demand: ${(
                                                  ext.coverage_planned_role_shortages ??
                                                  ext.coverage_role_shortages
                                              )
                                                  .map(
                                                      (role: any) =>
                                                          `${role.label ?? role.key} x${role.missing ?? 1}`,
                                                  )
                                                  .join(', ')}`
                                            : null,
                                        ext.expected_break_minutes != null
                                            ? `Expected break: ${ext.expected_break_minutes}m`
                                            : null,
                                        ext.is_sleepover
                                            ? 'Sleepover shift'
                                            : null,
                                        ext.is_on_call ? 'On-call shift' : null,
                                        ext.status
                                            ? `Status: ${ext.status}`
                                            : null,
                                    ].filter(Boolean);
                                    if (lines.length) {
                                        info.el.setAttribute(
                                            'title',
                                            lines.join('\n'),
                                        );
                                    }
                                }}
                                select={(arg: DateSelectArg) => {
                                    if (!canCreate) return;
                                    openCreate(arg.start, arg.end);
                                }}
                                eventClick={(arg: EventClickArg) => {
                                    arg.jsEvent.preventDefault();
                                    openEdit(arg);
                                }}
                                eventDrop={onEventDrop}
                                eventResize={onEventResize}
                            />
                        </div>

                        {!canManageAny && (
                            <div className="mt-3 text-xs text-muted-foreground">
                                You’re seeing only your own shifts.
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>

            <Dialog open={modalOpen} onOpenChange={setModalOpen}>
                <DialogContent className="sm:max-w-[640px]">
                    <DialogHeader>
                        <DialogTitle>
                            {viewInfo?.eventType === 'coverage_gap'
                                ? 'Coverage gap'
                                : modalMode === 'create'
                                  ? `Create ${shiftLabel}`
                                  : canUpdate
                                    ? `Edit ${shiftLabel}`
                                    : `${shiftLabel} details`}
                        </DialogTitle>
                    </DialogHeader>

                    <div className="grid gap-4">
                        {error && (
                            <div className="rounded-md border border-destructive/40 bg-destructive/10 p-3 text-sm">
                                {error}
                            </div>
                        )}

                        {modalMode === 'edit' && !canManageAny && viewInfo && (
                            <div className="grid gap-1 rounded-md border p-3 text-sm">
                                {viewInfo.client && (
                                    <div>
                                        <span className="text-muted-foreground">
                                            Client:{' '}
                                        </span>
                                        {viewInfo.client}
                                    </div>
                                )}
                                {viewInfo.staff && (
                                    <div>
                                        <span className="text-muted-foreground">
                                            Staff:{' '}
                                        </span>
                                        {viewInfo.staff}
                                    </div>
                                )}
                                {viewInfo.shiftType && (
                                    <div>
                                        <span className="text-muted-foreground">
                                            Type:{' '}
                                        </span>
                                        {String(viewInfo.shiftType).replace(
                                            '_',
                                            ' ',
                                        )}
                                    </div>
                                )}
                            </div>
                        )}

                        {viewInfo?.eventType === 'coverage_gap' && (
                            <div className="grid gap-2 rounded-md border border-status-critical/30 bg-status-critical-bg p-3 text-sm">
                                <div className="font-medium text-status-critical">
                                    {gapKindLabel(viewInfo.coverageGapKind)}
                                </div>
                                <div className="text-status-critical">
                                    {viewInfo.siteName ?? 'Site'} needs{' '}
                                    {viewInfo.coverageRequiredStaff ?? 0} staff
                                    in this window and only has{' '}
                                    {viewInfo.coverageAssignedStaff ?? 0}{' '}
                                    assigned.
                                </div>
                                <div className="text-status-critical">
                                    Missing {viewInfo.coverageMissingStaff ?? 0}{' '}
                                    staff
                                    {viewInfo.coverageWindowLabel
                                        ? ` · ${viewInfo.coverageWindowLabel}`
                                        : ''}
                                    .
                                </div>
                                {coverageRolesForAction(viewInfo).length > 0 ? (
                                    <div className="flex flex-wrap gap-2">
                                        {coverageRolesForAction(viewInfo).map(
                                            (role) => (
                                                <span
                                                    key={`coverage-role-${role.key}`}
                                                    className="rounded-full border border-status-critical/30 bg-white/70 px-2 py-1 text-[11px] font-medium"
                                                >
                                                    {role.label ?? role.key}{' '}
                                                    still needed x
                                                    {role.missing ?? 1}
                                                </span>
                                            ),
                                        )}
                                    </div>
                                ) : null}
                                {viewInfo.coverageContradictions &&
                                viewInfo.coverageContradictions.length > 0 ? (
                                    <div className="flex flex-wrap gap-2">
                                        {viewInfo.coverageContradictions.map(
                                            (issue) => (
                                                <span
                                                    key={`coverage-issue-${issue}`}
                                                    className="rounded-full border border-status-critical/30 bg-white/70 px-2 py-1 text-[11px] font-medium"
                                                >
                                                    {issue ===
                                                    'headcount_exact_but_role_gap'
                                                        ? 'Headcount looks full but role demand is still short'
                                                        : issue ===
                                                            'partial_window_undercoverage'
                                                          ? 'Coverage drops away inside the window and needs partial backfill'
                                                          : issue ===
                                                              'planned_supply_exact_but_role_gap'
                                                            ? 'Planned supply still misses the required role mix'
                                                            : issue ===
                                                                'preferred_client_drift'
                                                              ? 'Preferred client context has drifted'
                                                              : issue ===
                                                                  'overfill_not_allowed'
                                                                ? 'This window is overstaffed beyond the allowed limit'
                                                                : issue ===
                                                                    'overfilled_but_wrong_role_mix'
                                                                  ? 'This window is overfilled but still has the wrong role mix'
                                                                  : issue}
                                                </span>
                                            ),
                                        )}
                                    </div>
                                ) : null}
                            </div>
                        )}

                        {modalMode === 'edit' && !canManageAny && viewInfo && (
                            <div className="grid gap-2 rounded-md border p-3 text-sm">
                                <div className="flex items-center justify-between gap-2">
                                    <span className="text-muted-foreground">
                                        Client
                                    </span>
                                    <span className="font-medium">
                                        {viewInfo.client ?? '—'}
                                    </span>
                                </div>
                                <div className="flex items-center justify-between gap-2">
                                    <span className="text-muted-foreground">
                                        Staff
                                    </span>
                                    <span className="font-medium">
                                        {viewInfo.staff ?? '—'}
                                    </span>
                                </div>
                                <div className="flex items-center justify-between gap-2">
                                    <span className="text-muted-foreground">
                                        Type
                                    </span>
                                    <span className="font-medium capitalize">
                                        {String(
                                            viewInfo.shiftType ?? 'standard',
                                        ).replace('_', ' ')}
                                    </span>
                                </div>
                                <div className="flex items-center justify-between gap-2">
                                    <span className="text-muted-foreground">
                                        Status
                                    </span>
                                    <span className="font-medium capitalize">
                                        {String(
                                            viewInfo.status ?? 'scheduled',
                                        ).replace('_', ' ')}
                                    </span>
                                </div>
                                {viewInfo.serviceContext && (
                                    <div className="flex items-center justify-between gap-2">
                                        <span className="text-muted-foreground">
                                            Service
                                        </span>
                                        <span className="font-medium">
                                            {viewInfo.serviceContext}
                                        </span>
                                    </div>
                                )}
                                {viewInfo.isRecurring ? (
                                    <div className="flex items-center justify-between gap-2">
                                        <span className="text-muted-foreground">
                                            Series
                                        </span>
                                        <span className="font-medium">
                                            Recurring support
                                        </span>
                                    </div>
                                ) : null}
                            </div>
                        )}

                        {modalMode === 'edit' &&
                            viewInfo &&
                            viewInfo.eventType !== 'coverage_gap' && (
                                <div className="grid gap-3 rounded-md border p-3 text-sm">
                                    <div className="flex flex-wrap gap-2">
                                        {viewInfo.isOpenShift ? (
                                            <span className="rounded-full border px-2 py-1 text-[11px] font-medium tracking-wide text-status-critical uppercase">
                                                Open shift
                                            </span>
                                        ) : null}
                                        {viewInfo.isRecurring ? (
                                            <span className="rounded-full border px-2 py-1 text-[11px] font-medium tracking-wide uppercase">
                                                Recurring
                                            </span>
                                        ) : null}
                                        {viewInfo.hasActiveReplacement ? (
                                            <span className="rounded-full border px-2 py-1 text-[11px] font-medium tracking-wide text-status-warning uppercase">
                                                Replacement{' '}
                                                {viewInfo.replacementStatus ??
                                                    'active'}
                                            </span>
                                        ) : null}
                                    </div>

                                    <div className="grid gap-2 sm:grid-cols-3">
                                        <div className="rounded-md border p-2">
                                            <div className="text-xs text-muted-foreground">
                                                Tasks
                                            </div>
                                            <div className="mt-1 font-medium">
                                                {viewInfo.tasksCompleted ?? 0}/
                                                {viewInfo.tasksTotal ?? 0}
                                            </div>
                                        </div>
                                        <div className="rounded-md border p-2">
                                            <div className="text-xs text-muted-foreground">
                                                Incidents
                                            </div>
                                            <div className="mt-1 font-medium">
                                                {viewInfo.incidentsCount ?? 0}
                                            </div>
                                        </div>
                                        <div className="rounded-md border p-2">
                                            <div className="text-xs text-muted-foreground">
                                                Break
                                            </div>
                                            <div className="mt-1 font-medium">
                                                {viewInfo.expectedBreakMinutes !=
                                                null
                                                    ? `${viewInfo.expectedBreakMinutes} min`
                                                    : 'Not set'}
                                            </div>
                                        </div>
                                    </div>

                                    {viewInfo.timedTasks?.length ? (
                                        <div className="rounded-md border p-2">
                                            <div className="text-xs text-muted-foreground">
                                                Timed tasks
                                            </div>
                                            <div className="mt-2 space-y-1.5">
                                                {viewInfo.timedTasks.map(
                                                    (task) => (
                                                        <div
                                                            key={task.id}
                                                            className="flex items-center gap-2 text-xs"
                                                        >
                                                            <span className="rounded bg-muted px-1.5 py-0.5 font-medium tabular-nums">
                                                                {
                                                                    task.scheduled_time
                                                                }
                                                            </span>
                                                            <span className="min-w-0 truncate text-muted-foreground">
                                                                {task.label}
                                                            </span>
                                                        </div>
                                                    ),
                                                )}
                                            </div>
                                        </div>
                                    ) : null}

                                    <div className="flex flex-wrap gap-2">
                                        {viewInfo.id ? (
                                            <a
                                                className="rounded-md border px-3 py-2 text-xs hover:bg-muted"
                                                href={`/operations/shifts/${viewInfo.id}`}
                                            >
                                                Open shift workspace
                                            </a>
                                        ) : null}
                                        {viewInfo.shiftSeriesId ? (
                                            <a
                                                className="rounded-md border px-3 py-2 text-xs hover:bg-muted"
                                                href={`/operations/shifts/series/${viewInfo.shiftSeriesId}`}
                                            >
                                                Open recurring series
                                            </a>
                                        ) : null}
                                    </div>
                                </div>
                            )}

                        {viewInfo?.eventType !== 'coverage_gap' &&
                            (canManageAny || modalMode === 'create') && (
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div className="grid gap-1">
                                        <Label>
                                            {labels?.['client.singular'] ??
                                                'Client'}
                                        </Label>
                                        <select
                                            className="w-full rounded-md border bg-background p-2 text-sm"
                                            value={String(form.client_id)}
                                            disabled={
                                                !canUpdate &&
                                                modalMode === 'edit'
                                            }
                                            onChange={(e) =>
                                                setForm((s) => {
                                                    const nextClientId =
                                                        e.target.value === ''
                                                            ? ''
                                                            : Number(
                                                                  e.target
                                                                      .value,
                                                              );
                                                    const inherited =
                                                        nextClientId === ''
                                                            ? ''
                                                            : (clientServiceContextById.get(
                                                                  nextClientId,
                                                              ) ?? '');

                                                    return {
                                                        ...s,
                                                        client_id: nextClientId,
                                                        // If service context not manually chosen yet, inherit from client
                                                        service_context_id:
                                                            s.service_context_id ===
                                                            ''
                                                                ? inherited ===
                                                                  null
                                                                    ? ''
                                                                    : inherited
                                                                : s.service_context_id,
                                                        location:
                                                            locationForClientId(
                                                                nextClientId,
                                                            ),
                                                    };
                                                })
                                            }
                                        >
                                            <option value="">
                                                Select a client
                                            </option>
                                            {clientOptions.map((c) => (
                                                <option key={c.id} value={c.id}>
                                                    {c.label}
                                                </option>
                                            ))}
                                        </select>
                                    </div>

                                    <div className="grid gap-1">
                                        <Label>Staff</Label>
                                        <select
                                            className="w-full rounded-md border bg-background p-2 text-sm"
                                            value={String(form.user_id)}
                                            disabled={
                                                !canUpdate &&
                                                modalMode === 'edit'
                                            }
                                            onChange={(e) =>
                                                setForm((s) => ({
                                                    ...s,
                                                    user_id:
                                                        e.target.value === ''
                                                            ? ''
                                                            : Number(
                                                                  e.target
                                                                      .value,
                                                              ),
                                                }))
                                            }
                                        >
                                            <option value="">
                                                Unassigned / open shift
                                            </option>
                                            {staffOptions.map((u) => (
                                                <option key={u.id} value={u.id}>
                                                    {u.label}
                                                </option>
                                            ))}
                                        </select>
                                    </div>
                                </div>
                            )}

                        {viewInfo?.eventType !== 'coverage_gap' &&
                            (canUpdate || modalMode === 'create') && (
                                <div className="grid gap-1">
                                    <Label>Service context</Label>
                                    <select
                                        className="w-full rounded-md border bg-background p-2 text-sm"
                                        value={String(form.service_context_id)}
                                        disabled={
                                            !canUpdate && modalMode === 'edit'
                                        }
                                        onChange={(e) =>
                                            setForm((s) => ({
                                                ...s,
                                                service_context_id:
                                                    e.target.value === ''
                                                        ? ''
                                                        : Number(
                                                              e.target.value,
                                                          ),
                                            }))
                                        }
                                    >
                                        <option value="">
                                            Inherit from client (recommended)
                                        </option>
                                        {serviceContextOptions
                                            .filter(
                                                (sc) =>
                                                    sc.is_active ||
                                                    sc.id ===
                                                        Number(
                                                            form.service_context_id,
                                                        ),
                                            )
                                            .map((sc) => (
                                                <option
                                                    key={sc.id}
                                                    value={sc.id}
                                                >
                                                    {sc.label}
                                                    {!sc.is_active
                                                        ? ' (inactive)'
                                                        : ''}
                                                </option>
                                            ))}
                                    </select>
                                    <div className="text-xs text-muted-foreground">
                                        If left blank, the shift will inherit
                                        the selected client’s service context
                                        (if set).
                                    </div>
                                </div>
                            )}

                        {viewInfo?.eventType !== 'coverage_gap' && (
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid gap-1">
                                    <Label>Start</Label>
                                    <Input
                                        type="datetime-local"
                                        value={form.starts_at}
                                        disabled={
                                            !canUpdate && modalMode === 'edit'
                                        }
                                        onChange={(e) =>
                                            setForm((s) => ({
                                                ...s,
                                                starts_at: e.target.value,
                                            }))
                                        }
                                    />
                                </div>
                                <div className="grid gap-1">
                                    <Label>End</Label>
                                    <Input
                                        type="datetime-local"
                                        value={form.ends_at}
                                        disabled={
                                            !canUpdate && modalMode === 'edit'
                                        }
                                        onChange={(e) =>
                                            setForm((s) => ({
                                                ...s,
                                                ends_at: e.target.value,
                                            }))
                                        }
                                    />
                                </div>
                            </div>
                        )}

                        {viewInfo?.eventType !== 'coverage_gap' &&
                            (canUpdate || modalMode === 'create') && (
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div className="grid gap-1">
                                        <Label>Shift type</Label>
                                        <select
                                            className="w-full rounded-md border bg-background p-2 text-sm"
                                            value={form.shift_type}
                                            disabled={
                                                !canUpdate &&
                                                modalMode === 'edit'
                                            }
                                            onChange={(e) =>
                                                setForm((s) => {
                                                    const nextType = e.target
                                                        .value as ShiftForm['shift_type'];
                                                    return {
                                                        ...s,
                                                        shift_type: nextType,
                                                        is_sleepover:
                                                            nextType ===
                                                            'sleepover'
                                                                ? true
                                                                : s.is_sleepover,
                                                        is_on_call:
                                                            nextType ===
                                                            'on_call'
                                                                ? true
                                                                : s.is_on_call,
                                                    };
                                                })
                                            }
                                        >
                                            <option value="standard">
                                                standard
                                            </option>
                                            <option value="sleepover">
                                                sleepover
                                            </option>
                                            <option value="on_call">
                                                on_call
                                            </option>
                                            <option value="split">split</option>
                                            <option value="travel">
                                                travel
                                            </option>
                                        </select>
                                    </div>
                                    <div className="grid gap-1">
                                        <Label>Expected break (minutes)</Label>
                                        <Input
                                            type="number"
                                            min={0}
                                            max={720}
                                            value={form.expected_break_minutes}
                                            disabled={
                                                !canUpdate &&
                                                modalMode === 'edit'
                                            }
                                            onChange={(e) =>
                                                setForm((s) => ({
                                                    ...s,
                                                    expected_break_minutes:
                                                        e.target.value === ''
                                                            ? ''
                                                            : Number(
                                                                  e.target
                                                                      .value,
                                                              ),
                                                }))
                                            }
                                        />
                                    </div>
                                </div>
                            )}

                        {(canUpdate || modalMode === 'create') && (
                            <div className="grid gap-4 sm:grid-cols-2">
                                <label className="flex items-center gap-2 rounded-md border p-3 text-sm">
                                    <input
                                        type="checkbox"
                                        checked={form.is_sleepover}
                                        disabled={
                                            !canUpdate && modalMode === 'edit'
                                        }
                                        onChange={(e) =>
                                            setForm((s) => ({
                                                ...s,
                                                is_sleepover: e.target.checked,
                                            }))
                                        }
                                    />
                                    Sleepover allowances apply
                                </label>
                                <label className="flex items-center gap-2 rounded-md border p-3 text-sm">
                                    <input
                                        type="checkbox"
                                        checked={form.is_on_call}
                                        disabled={
                                            !canUpdate && modalMode === 'edit'
                                        }
                                        onChange={(e) =>
                                            setForm((s) => ({
                                                ...s,
                                                is_on_call: e.target.checked,
                                            }))
                                        }
                                    />
                                    On-call allowances apply
                                </label>
                            </div>
                        )}

                        {viewInfo?.eventType !== 'coverage_gap' &&
                            (canUpdate || modalMode === 'create') && (
                                <div className="grid gap-1">
                                    <Label>Coverage roles</Label>
                                    <div className="grid gap-2 sm:grid-cols-3">
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
                                                    checked={form.coverage_roles.includes(
                                                        role,
                                                    )}
                                                    disabled={
                                                        !canUpdate &&
                                                        modalMode === 'edit'
                                                    }
                                                    onChange={(e) =>
                                                        setForm((s) => ({
                                                            ...s,
                                                            coverage_roles: e
                                                                .target.checked
                                                                ? Array.from(
                                                                      new Set([
                                                                          ...s.coverage_roles,
                                                                          role,
                                                                      ]),
                                                                  )
                                                                : s.coverage_roles.filter(
                                                                      (value) =>
                                                                          value !==
                                                                          role,
                                                                  ),
                                                        }))
                                                    }
                                                />
                                                {label}
                                            </label>
                                        ))}
                                    </div>
                                    <div className="text-xs text-muted-foreground">
                                        Use this when the shift is meant to
                                        satisfy a specific house coverage role,
                                        not just general headcount.
                                    </div>
                                </div>
                            )}

                        {viewInfo?.eventType !== 'coverage_gap' && (
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid gap-1">
                                    <Label>Location</Label>
                                    <Input
                                        value={form.location}
                                        disabled={
                                            !canUpdate && modalMode === 'edit'
                                        }
                                        onChange={(e) =>
                                            setForm((s) => ({
                                                ...s,
                                                location: e.target.value,
                                            }))
                                        }
                                    />
                                </div>
                                <div className="grid gap-1">
                                    <Label>Status</Label>
                                    <select
                                        className="w-full rounded-md border bg-background p-2 text-sm"
                                        value={form.status}
                                        disabled={
                                            !canUpdate && modalMode === 'edit'
                                        }
                                        onChange={(e) =>
                                            setForm((s) => ({
                                                ...s,
                                                status: e.target.value as any,
                                            }))
                                        }
                                    >
                                        <option value="draft">draft</option>
                                        <option value="scheduled">
                                            scheduled
                                        </option>
                                    </select>
                                    <div className="text-xs text-muted-foreground">
                                        Start, complete, and cancel actions are
                                        managed from the shift workflow, not the
                                        calendar editor.
                                    </div>
                                </div>
                            </div>
                        )}

                        {viewInfo?.eventType !== 'coverage_gap' && (
                            <div className="grid gap-1">
                                <Label>Notes</Label>
                                <textarea
                                    className="min-h-[110px] w-full rounded-md border bg-background p-2 text-sm"
                                    value={form.notes}
                                    disabled={
                                        !canUpdate && modalMode === 'edit'
                                    }
                                    onChange={(e) =>
                                        setForm((s) => ({
                                            ...s,
                                            notes: e.target.value,
                                        }))
                                    }
                                />
                            </div>
                        )}
                    </div>

                    <DialogFooter className="gap-2 sm:gap-2">
                        {viewInfo?.eventType === 'coverage_gap' ? (
                            <>
                                {shouldOfferCreation(
                                    viewInfo.coverageRecommendedFillAction,
                                ) ? (
                                    <>
                                        <Button
                                            type="button"
                                            onClick={() =>
                                                createShiftLauncher.openWith({
                                                    site_id: viewInfo.siteId,
                                                    coverage_rule_id:
                                                        viewInfo.coverageRuleId,
                                                    client_id:
                                                        viewInfo.coveragePreferredClientId,
                                                    starts_at: form.starts_at,
                                                    ends_at: form.ends_at,
                                                    coverage_rule_name:
                                                        viewInfo.coverageRuleName ??
                                                        'Coverage gap',
                                                    coverage_required_staff:
                                                        viewInfo.coverageRequiredStaff,
                                                    coverage_missing_staff:
                                                        viewInfo.coverageMissingStaff,
                                                    coverage_role_shortages:
                                                        JSON.stringify(
                                                            coverageRolesForAction(
                                                                viewInfo,
                                                            ),
                                                        ),
                                                })
                                            }
                                        >
                                            {fillActionLabel(
                                                viewInfo.coverageRecommendedFillAction,
                                            )}
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            onClick={() =>
                                                createShiftLauncher.openWith({
                                                    site_id: viewInfo.siteId,
                                                    coverage_rule_id:
                                                        viewInfo.coverageRuleId,
                                                    client_id:
                                                        viewInfo.coveragePreferredClientId,
                                                    starts_at: form.starts_at,
                                                    ends_at: form.ends_at,
                                                    open_shift: true,
                                                    coverage_rule_name:
                                                        viewInfo.coverageRuleName ??
                                                        'Coverage gap',
                                                    coverage_required_staff:
                                                        viewInfo.coverageRequiredStaff,
                                                    coverage_missing_staff:
                                                        viewInfo.coverageMissingStaff,
                                                    coverage_role_shortages:
                                                        JSON.stringify(
                                                            coverageRolesForAction(
                                                                viewInfo,
                                                            ),
                                                        ),
                                                })
                                            }
                                        >
                                            Create open shift
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            onClick={() =>
                                                createShiftLauncher.openWith({
                                                    site_id: viewInfo.siteId,
                                                    coverage_rule_id:
                                                        viewInfo.coverageRuleId,
                                                    client_id:
                                                        viewInfo.coveragePreferredClientId,
                                                    starts_at: form.starts_at,
                                                    ends_at: form.ends_at,
                                                    open_shift: true,
                                                    repeat_weekly: true,
                                                    repeat_end_date: new Date(
                                                        new Date(
                                                            form.starts_at,
                                                        ).getTime() +
                                                            1000 *
                                                                60 *
                                                                60 *
                                                                24 *
                                                                28,
                                                    )
                                                        .toISOString()
                                                        .slice(0, 10),
                                                    coverage_rule_name:
                                                        viewInfo.coverageRuleName ??
                                                        'Coverage gap',
                                                    coverage_required_staff:
                                                        viewInfo.coverageRequiredStaff,
                                                    coverage_missing_staff:
                                                        viewInfo.coverageMissingStaff,
                                                    coverage_role_shortages:
                                                        JSON.stringify(
                                                            coverageRolesForAction(
                                                                viewInfo,
                                                            ),
                                                        ),
                                                })
                                            }
                                        >
                                            Recurring cover
                                        </Button>
                                    </>
                                ) : null}
                            </>
                        ) : null}
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setModalOpen(false)}
                        >
                            Close
                        </Button>
                        {viewInfo?.eventType !== 'coverage_gap' &&
                            (canUpdate || modalMode === 'create') && (
                                <Button
                                    type="button"
                                    disabled={saving}
                                    onClick={saveShift}
                                >
                                    {saving ? 'Saving…' : 'Save'}
                                </Button>
                            )}
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {createShiftLauncher.dialog}
        </AppLayout>
    );
}
