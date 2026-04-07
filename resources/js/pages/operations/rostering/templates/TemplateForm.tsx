import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { router, useForm } from '@inertiajs/react';

type Client = {
    id: number;
    first_name: string;
    last_name: string;
    service_context_id?: number | null;
};

type Staff = {
    id: number;
    name: string;
    email?: string | null;
};

type ServiceContext = {
    id: number;
    name: string;
    type?: string | null;
    is_active?: boolean;
};

type TemplateShift = {
    id?: number;
    client_id?: number | null;
    user_id?: number | null;
    service_context_id?: number | null;
    day_of_week?: number;
    start_time?: string | null;
    end_time?: string | null;
    shift_type?: string | null;
    is_sleepover?: boolean;
    is_on_call?: boolean;
    expected_break_minutes?: number | null;
    required_skills?: string[] | null;
    location?: string | null;
    notes?: string | null;
};

type Template = {
    id: number;
    name: string;
    description?: string | null;
    template_type?: string | null;
    is_active?: boolean;
    template_shifts?: TemplateShift[];
};

type ShiftFormRow = {
    client_id: string;
    user_id: string;
    service_context_id: string;
    day_of_week: string;
    start_time: string;
    end_time: string;
    shift_type: string;
    is_sleepover: boolean;
    is_on_call: boolean;
    expected_break_minutes: string;
    required_skills: string;
    location: string;
    notes: string;
};

type Props = {
    mode: 'create' | 'edit';
    submitUrl: string;
    template?: Template | null;
    clients: Client[];
    staff: Staff[];
    serviceContexts: ServiceContext[];
};

const DAY_OPTIONS = [
    { value: '0', label: 'Monday' },
    { value: '1', label: 'Tuesday' },
    { value: '2', label: 'Wednesday' },
    { value: '3', label: 'Thursday' },
    { value: '4', label: 'Friday' },
    { value: '5', label: 'Saturday' },
    { value: '6', label: 'Sunday' },
];

const SHIFT_TYPE_OPTIONS = [
    { value: 'standard', label: 'Standard' },
    { value: 'sleepover', label: 'Sleepover' },
    { value: 'on_call', label: 'On-call' },
    { value: 'split', label: 'Split shift' },
    { value: 'travel', label: 'Travel / escort' },
];

function makeEmptyShift(): ShiftFormRow {
    return {
        client_id: '',
        user_id: '',
        service_context_id: '',
        day_of_week: '0',
        start_time: '07:00',
        end_time: '15:00',
        shift_type: 'standard',
        is_sleepover: false,
        is_on_call: false,
        expected_break_minutes: '',
        required_skills: '',
        location: '',
        notes: '',
    };
}

function toShiftFormRow(shift?: TemplateShift | null): ShiftFormRow {
    if (!shift) {
        return makeEmptyShift();
    }

    return {
        client_id: shift.client_id ? String(shift.client_id) : '',
        user_id: shift.user_id ? String(shift.user_id) : '',
        service_context_id: shift.service_context_id
            ? String(shift.service_context_id)
            : '',
        day_of_week:
            shift.day_of_week !== undefined && shift.day_of_week !== null
                ? String(shift.day_of_week)
                : '0',
        start_time: shift.start_time ?? '07:00',
        end_time: shift.end_time ?? '15:00',
        shift_type: shift.shift_type ?? 'standard',
        is_sleepover: !!shift.is_sleepover,
        is_on_call: !!shift.is_on_call,
        expected_break_minutes:
            shift.expected_break_minutes !== null &&
            shift.expected_break_minutes !== undefined
                ? String(shift.expected_break_minutes)
                : '',
        required_skills: (shift.required_skills ?? []).join(', '),
        location: shift.location ?? '',
        notes: shift.notes ?? '',
    };
}

export default function TemplateForm({
    mode,
    submitUrl,
    template,
    clients,
    staff,
    serviceContexts,
}: Props) {
    const form = useForm({
        name: template?.name ?? '',
        description: template?.description ?? '',
        template_type: template?.template_type ?? 'weekly',
        is_active: template?.is_active ?? true,
        template_shifts: template?.template_shifts?.length
            ? template.template_shifts.map((shift) => toShiftFormRow(shift))
            : [makeEmptyShift()],
    });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        form.transform((data) => ({
            ...data,
            template_shifts: data.template_shifts.map((row) => ({
                client_id: row.client_id || null,
                user_id: row.user_id || null,
                service_context_id: row.service_context_id || null,
                day_of_week: Number(row.day_of_week),
                start_time: row.start_time,
                end_time: row.end_time,
                shift_type: row.shift_type,
                is_sleepover: !!row.is_sleepover,
                is_on_call: !!row.is_on_call,
                expected_break_minutes: row.expected_break_minutes || null,
                required_skills: row.required_skills
                    .split(',')
                    .map((skill) => skill.trim())
                    .filter(Boolean),
                location: row.location || null,
                notes: row.notes || null,
            })),
        }));

        if (mode === 'edit') {
            form.put(submitUrl);
            return;
        }

        form.post(submitUrl);
    };

    const setShiftValue = (
        index: number,
        key: keyof ShiftFormRow,
        value: string | boolean,
    ) => {
        form.setData(
            'template_shifts',
            form.data.template_shifts.map((row, rowIndex) =>
                rowIndex === index ? { ...row, [key]: value } : row,
            ),
        );
    };

    const addShiftRow = () => {
        form.setData('template_shifts', [
            ...form.data.template_shifts,
            makeEmptyShift(),
        ]);
    };

    const removeShiftRow = (index: number) => {
        form.setData(
            'template_shifts',
            form.data.template_shifts.filter(
                (_, rowIndex) => rowIndex !== index,
            ),
        );
    };

    const errorMessages = Object.values(form.errors);

    return (
        <form onSubmit={submit} className="space-y-6">
            <div className="grid gap-4 rounded-md border p-4 md:grid-cols-2">
                <div className="space-y-2 md:col-span-2">
                    <Label htmlFor="template-name">Template name</Label>
                    <Input
                        id="template-name"
                        value={form.data.name}
                        onChange={(event) =>
                            form.setData('name', event.target.value)
                        }
                        placeholder="North House weekday support"
                    />
                </div>

                <div className="space-y-2">
                    <Label htmlFor="template-type">Template cadence</Label>
                    <select
                        id="template-type"
                        className="w-full rounded-md border bg-background p-2 text-sm"
                        value={form.data.template_type}
                        onChange={(event) =>
                            form.setData('template_type', event.target.value)
                        }
                    >
                        <option value="weekly">Weekly</option>
                        <option value="fortnightly">Fortnightly</option>
                        <option value="monthly">Monthly</option>
                    </select>
                </div>

                <div className="flex items-end">
                    <label className="flex items-center gap-2 rounded-md border px-3 py-2 text-sm">
                        <Checkbox
                            checked={!!form.data.is_active}
                            onCheckedChange={(value) =>
                                form.setData('is_active', value === true)
                            }
                        />
                        Active template
                    </label>
                </div>

                <div className="space-y-2 md:col-span-2">
                    <Label htmlFor="template-description">Description</Label>
                    <Textarea
                        id="template-description"
                        value={form.data.description}
                        onChange={(event) =>
                            form.setData('description', event.target.value)
                        }
                        rows={3}
                        placeholder="Use this for the regular weekday support pattern."
                    />
                </div>
            </div>

            <div className="space-y-4 rounded-md border p-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 className="text-sm font-semibold">
                            Template shifts
                        </h2>
                        <p className="text-xs text-muted-foreground">
                            Build the repeatable shift rows that should be
                            created when this template is applied.
                        </p>
                    </div>

                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={addShiftRow}
                    >
                        Add shift row
                    </Button>
                </div>

                {form.data.template_shifts.map((shift, index) => (
                    <div
                        key={`template-shift-${index}`}
                        className="space-y-4 rounded-md border bg-muted/10 p-4"
                    >
                        <div className="flex items-center justify-between gap-3">
                            <div className="text-sm font-medium">
                                Shift row {index + 1}
                            </div>
                            {form.data.template_shifts.length > 1 && (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    onClick={() => removeShiftRow(index)}
                                >
                                    Remove
                                </Button>
                            )}
                        </div>

                        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                            <div className="space-y-2">
                                <Label>Day</Label>
                                <select
                                    className="w-full rounded-md border bg-background p-2 text-sm"
                                    value={shift.day_of_week}
                                    onChange={(event) =>
                                        setShiftValue(
                                            index,
                                            'day_of_week',
                                            event.target.value,
                                        )
                                    }
                                >
                                    {DAY_OPTIONS.map((option) => (
                                        <option
                                            key={option.value}
                                            value={option.value}
                                        >
                                            {option.label}
                                        </option>
                                    ))}
                                </select>
                            </div>

                            <div className="space-y-2">
                                <Label>Start</Label>
                                <Input
                                    type="time"
                                    value={shift.start_time}
                                    onChange={(event) =>
                                        setShiftValue(
                                            index,
                                            'start_time',
                                            event.target.value,
                                        )
                                    }
                                />
                            </div>

                            <div className="space-y-2">
                                <Label>End</Label>
                                <Input
                                    type="time"
                                    value={shift.end_time}
                                    onChange={(event) =>
                                        setShiftValue(
                                            index,
                                            'end_time',
                                            event.target.value,
                                        )
                                    }
                                />
                                <p className="text-[11px] text-muted-foreground">
                                    If the end time is earlier than the start
                                    time, the shift will roll into the next day.
                                </p>
                            </div>

                            <div className="space-y-2">
                                <Label>Shift type</Label>
                                <select
                                    className="w-full rounded-md border bg-background p-2 text-sm"
                                    value={shift.shift_type}
                                    onChange={(event) =>
                                        setShiftValue(
                                            index,
                                            'shift_type',
                                            event.target.value,
                                        )
                                    }
                                >
                                    {SHIFT_TYPE_OPTIONS.map((option) => (
                                        <option
                                            key={option.value}
                                            value={option.value}
                                        >
                                            {option.label}
                                        </option>
                                    ))}
                                </select>
                            </div>

                            <div className="space-y-2">
                                <Label>Client</Label>
                                <select
                                    className="w-full rounded-md border bg-background p-2 text-sm"
                                    value={shift.client_id}
                                    onChange={(event) => {
                                        const nextClientId = event.target.value;
                                        setShiftValue(
                                            index,
                                            'client_id',
                                            nextClientId,
                                        );

                                        if (
                                            !shift.service_context_id &&
                                            nextClientId
                                        ) {
                                            const selectedClient = clients.find(
                                                (client) =>
                                                    String(client.id) ===
                                                    String(nextClientId),
                                            );

                                            if (
                                                selectedClient?.service_context_id
                                            ) {
                                                setShiftValue(
                                                    index,
                                                    'service_context_id',
                                                    String(
                                                        selectedClient.service_context_id,
                                                    ),
                                                );
                                            }
                                        }
                                    }}
                                >
                                    <option value="">No client</option>
                                    {clients.map((client) => (
                                        <option
                                            key={client.id}
                                            value={client.id}
                                        >
                                            {client.first_name}{' '}
                                            {client.last_name}
                                        </option>
                                    ))}
                                </select>
                            </div>

                            <div className="space-y-2">
                                <Label>Assigned staff</Label>
                                <select
                                    className="w-full rounded-md border bg-background p-2 text-sm"
                                    value={shift.user_id}
                                    onChange={(event) =>
                                        setShiftValue(
                                            index,
                                            'user_id',
                                            event.target.value,
                                        )
                                    }
                                >
                                    <option value="">Unassigned / open</option>
                                    {staff.map((member) => (
                                        <option
                                            key={member.id}
                                            value={member.id}
                                        >
                                            {member.name}
                                            {member.email
                                                ? ` (${member.email})`
                                                : ''}
                                        </option>
                                    ))}
                                </select>
                            </div>

                            <div className="space-y-2">
                                <Label>Service context</Label>
                                <select
                                    className="w-full rounded-md border bg-background p-2 text-sm"
                                    value={shift.service_context_id}
                                    onChange={(event) =>
                                        setShiftValue(
                                            index,
                                            'service_context_id',
                                            event.target.value,
                                        )
                                    }
                                >
                                    <option value="">No service context</option>
                                    {serviceContexts.map((context) => (
                                        <option
                                            key={context.id}
                                            value={context.id}
                                        >
                                            {context.name}
                                            {context.is_active === false
                                                ? ' (inactive)'
                                                : ''}
                                        </option>
                                    ))}
                                </select>
                            </div>

                            <div className="space-y-2">
                                <Label>Expected break (minutes)</Label>
                                <Input
                                    type="number"
                                    min="0"
                                    max="720"
                                    value={shift.expected_break_minutes}
                                    onChange={(event) =>
                                        setShiftValue(
                                            index,
                                            'expected_break_minutes',
                                            event.target.value,
                                        )
                                    }
                                />
                            </div>

                            <div className="space-y-2 xl:col-span-2">
                                <Label>Location</Label>
                                <Input
                                    value={shift.location}
                                    onChange={(event) =>
                                        setShiftValue(
                                            index,
                                            'location',
                                            event.target.value,
                                        )
                                    }
                                    placeholder="North House"
                                />
                            </div>

                            <div className="space-y-2 xl:col-span-2">
                                <Label>Required skills</Label>
                                <Input
                                    value={shift.required_skills}
                                    onChange={(event) =>
                                        setShiftValue(
                                            index,
                                            'required_skills',
                                            event.target.value,
                                        )
                                    }
                                    placeholder="Medication, Hoist, Community access"
                                />
                                <p className="text-[11px] text-muted-foreground">
                                    Separate multiple skills with commas.
                                </p>
                            </div>

                            <div className="space-y-2 xl:col-span-4">
                                <Label>Notes</Label>
                                <Textarea
                                    value={shift.notes}
                                    onChange={(event) =>
                                        setShiftValue(
                                            index,
                                            'notes',
                                            event.target.value,
                                        )
                                    }
                                    rows={2}
                                    placeholder="Anything schedulers or staff should know about this template row."
                                />
                            </div>
                        </div>

                        <div className="flex flex-wrap items-center gap-4">
                            <label className="flex items-center gap-2 text-sm">
                                <Checkbox
                                    checked={shift.is_sleepover}
                                    onCheckedChange={(value) =>
                                        setShiftValue(
                                            index,
                                            'is_sleepover',
                                            value === true,
                                        )
                                    }
                                />
                                Sleepover
                            </label>
                            <label className="flex items-center gap-2 text-sm">
                                <Checkbox
                                    checked={shift.is_on_call}
                                    onCheckedChange={(value) =>
                                        setShiftValue(
                                            index,
                                            'is_on_call',
                                            value === true,
                                        )
                                    }
                                />
                                On-call
                            </label>
                        </div>
                    </div>
                ))}
            </div>

            {errorMessages.length > 0 && (
                <div className="rounded-md border border-destructive/30 bg-destructive/5 p-3 text-sm text-destructive">
                    {errorMessages.slice(0, 5).map((message, index) => (
                        <p key={`template-error-${index}`}>{message}</p>
                    ))}
                </div>
            )}

            <div className="flex flex-wrap items-center gap-2">
                <Button type="submit" disabled={form.processing}>
                    {mode === 'edit' ? 'Update template' : 'Create template'}
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    onClick={() =>
                        router.visit('/operations/rostering/templates')
                    }
                >
                    Cancel
                </Button>
            </div>
        </form>
    );
}
