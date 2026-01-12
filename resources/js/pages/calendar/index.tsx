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
import { Head, usePage } from '@inertiajs/react';

import type {
    DateSelectArg,
    EventClickArg,
    EventDropArg,
    EventResizeDoneArg,
} from '@fullcalendar/core';
import dayGridPlugin from '@fullcalendar/daygrid';
import interactionPlugin from '@fullcalendar/interaction';
import listPlugin from '@fullcalendar/list';
import FullCalendar from '@fullcalendar/react';
import timeGridPlugin from '@fullcalendar/timegrid';

// import '@fullcalendar/daygrid/index.css';
// import '@fullcalendar/timegrid/index.css';
// import '@fullcalendar/list/index.css';

import { useMemo, useRef, useState } from 'react';

type Props = {
    canManageAny: boolean;
    staff: Array<{ id: number; name: string; email: string }>;
    clients: Array<{ id: number; first_name: string; last_name: string }>;
};

type ShiftForm = {
    id?: number;
    client_id: number | '';
    user_id: number | '';
    starts_at: string;
    ends_at: string;
    location: string;
    status: 'scheduled' | 'completed' | 'cancelled';
    notes: string;
};

type ShiftViewInfo = {
    client?: string;
    staff?: string;
};

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
            })),
        [props.clients],
    );

    const [staffId, setStaffId] = useState<string>('all');
    const [clientId, setClientId] = useState<string>('all');

    const calendarRef = useRef<FullCalendar | null>(null);

    const [modalOpen, setModalOpen] = useState(false);
    const [modalMode, setModalMode] = useState<'create' | 'edit'>('create');
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [viewInfo, setViewInfo] = useState<ShiftViewInfo | null>(null);
    const [form, setForm] = useState<ShiftForm>({
        client_id: '',
        user_id: '',
        starts_at: '',
        ends_at: '',
        location: '',
        status: 'scheduled',
        notes: '',
    });

    const shiftLabel = labels?.['shift.singular'] ?? 'Shift';

    function openCreate(start: Date, end: Date) {
        const prefillStaff =
            canManageAny && staffId !== 'all' ? Number(staffId) : '';
        const prefillClient =
            canManageAny && clientId !== 'all' ? Number(clientId) : '';

        setModalMode('create');
        setError(null);
        setViewInfo(null);
        setForm({
            id: undefined,
            client_id: prefillClient,
            user_id: prefillStaff,
            starts_at: toDatetimeLocalValue(start),
            ends_at: toDatetimeLocalValue(end),
            location: '',
            status: 'scheduled',
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
        setViewInfo({ client: ext.client, staff: ext.staff });
        setForm({
            id: Number(arg.event.id),
            client_id: ext.client_id ?? '',
            user_id: ext.user_id ?? '',
            starts_at: toDatetimeLocalValue(start),
            ends_at: toDatetimeLocalValue(end),
            location: ext.location ?? '',
            status: (ext.status ?? 'scheduled') as any,
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
                user_id: form.user_id === '' ? null : Number(form.user_id),
                starts_at: form.starts_at,
                ends_at: form.ends_at,
                location: form.location,
                status: form.status,
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
                                    <Label>Client</Label>
                                    <Select
                                        value={clientId}
                                        onValueChange={(v) => setClientId(v)}
                                    >
                                        <SelectTrigger className="w-[220px]">
                                            <SelectValue placeholder="All clients" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="all">
                                                All clients
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
                                initialView="timeGridWeek"
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
                                events={async (
                                    info,
                                    successCallback,
                                    failureCallback,
                                ) => {
                                    try {
                                        const params = new URLSearchParams({
                                            start: info.startStr,
                                            end: info.endStr,
                                        });
                                        if (canManageAny && staffId !== 'all') {
                                            params.set('staff_id', staffId);
                                        }
                                        if (
                                            canManageAny &&
                                            clientId !== 'all'
                                        ) {
                                            params.set('client_id', clientId);
                                        }

                                        const res = await fetch(
                                            `/calendar/events?${params.toString()}`,
                                            {
                                                headers: {
                                                    Accept: 'application/json',
                                                },
                                            },
                                        );
                                        if (!res.ok) {
                                            throw new Error(
                                                `Failed to load events: ${res.status}`,
                                            );
                                        }
                                        const data = await res.json();
                                        successCallback(data);
                                    } catch (e) {
                                        console.error(e);
                                        failureCallback(e as any);
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
                            {modalMode === 'create'
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
                            </div>
                        )}

                        {(canManageAny || modalMode === 'create') && (
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid gap-1">
                                    <Label>Client</Label>
                                    <select
                                        className="w-full rounded-md border bg-background p-2 text-sm"
                                        value={String(form.client_id)}
                                        disabled={
                                            !canUpdate && modalMode === 'edit'
                                        }
                                        onChange={(e) =>
                                            setForm((s) => ({
                                                ...s,
                                                client_id:
                                                    e.target.value === ''
                                                        ? ''
                                                        : Number(
                                                              e.target.value,
                                                          ),
                                            }))
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
                                            !canUpdate && modalMode === 'edit'
                                        }
                                        onChange={(e) =>
                                            setForm((s) => ({
                                                ...s,
                                                user_id:
                                                    e.target.value === ''
                                                        ? ''
                                                        : Number(
                                                              e.target.value,
                                                          ),
                                            }))
                                        }
                                    >
                                        <option value="">Select staff</option>
                                        {staffOptions.map((u) => (
                                            <option key={u.id} value={u.id}>
                                                {u.label}
                                            </option>
                                        ))}
                                    </select>
                                </div>
                            </div>
                        )}

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
                                    <option value="scheduled">scheduled</option>
                                    <option value="completed">completed</option>
                                    <option value="cancelled">cancelled</option>
                                </select>
                            </div>
                        </div>

                        <div className="grid gap-1">
                            <Label>Notes</Label>
                            <textarea
                                className="min-h-[110px] w-full rounded-md border bg-background p-2 text-sm"
                                value={form.notes}
                                disabled={!canUpdate && modalMode === 'edit'}
                                onChange={(e) =>
                                    setForm((s) => ({
                                        ...s,
                                        notes: e.target.value,
                                    }))
                                }
                            />
                        </div>
                    </div>

                    <DialogFooter className="gap-2 sm:gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setModalOpen(false)}
                        >
                            Close
                        </Button>
                        {(canUpdate || modalMode === 'create') && (
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
        </AppLayout>
    );
}
