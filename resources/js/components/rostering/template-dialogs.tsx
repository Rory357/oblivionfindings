/* eslint-disable no-restricted-syntax -- The template wizard mirrors the bespoke
 * Add-client modal surface (stepper rail + scroll-contained body + custom footer).
 * Every colour is a semantic design token, per docs/DESIGN_TOKENS.md. */
import { router, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    CalendarPlus,
    CalendarRange,
    Check,
    ChevronLeft,
    ChevronRight,
    ClipboardCheck,
    Clock,
    Copy,
    LayoutTemplate,
    ListChecks,
    Loader2,
    Pencil,
    Plus,
    Trash2,
    Users,
    X,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import {
    Field,
    SelectInput,
    SubHead,
    type IconType,
} from '@/components/wizard/primitives';
import { cn } from '@/lib/utils';

import {
    type RosterTemplateRow,
    type RosterTemplateShiftRow,
} from './templates-pane';

/* ------------------------------------------------------------------ */
/*  Option types + reference data                                      */
/* ------------------------------------------------------------------ */

export type TemplateClientOption = {
    id: number;
    first_name?: string | null;
    last_name?: string | null;
    name?: string | null;
    service_context_id?: number | null;
};
export type TemplateStaffOption = {
    id: number;
    name: string;
    email?: string | null;
};
export type TemplateServiceContextOption = {
    id: number;
    name: string;
    type?: string | null;
    is_active?: boolean;
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

const DAY_LABELS = [
    'Monday',
    'Tuesday',
    'Wednesday',
    'Thursday',
    'Friday',
    'Saturday',
    'Sunday',
];

const DAY_SHORT = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

const SHIFT_TYPE_OPTIONS = [
    { value: 'standard', label: 'Standard' },
    { value: 'sleepover', label: 'Sleepover' },
    { value: 'on_call', label: 'On-call' },
    { value: 'split', label: 'Split shift' },
    { value: 'travel', label: 'Travel / escort' },
];

const NONE = '__none__';

function clientLabel(client: TemplateClientOption): string {
    if (client.name) return client.name;
    return (
        [client.first_name, client.last_name].filter(Boolean).join(' ') ||
        `Client ${client.id}`
    );
}

function nextMonday(): string {
    const now = new Date();
    const result = new Date(now);
    const day = result.getDay();
    const daysUntilMonday = day === 1 ? 7 : (8 - day) % 7 || 7;
    result.setDate(result.getDate() + daysUntilMonday);
    return result.toISOString().slice(0, 10);
}

// Monday of the week containing the given yyyy-mm-dd (mirrors the server snap).
function mondayOf(dateStr: string): Date {
    const d = new Date(`${dateStr}T00:00:00`);
    if (Number.isNaN(d.getTime())) return new Date();
    const day = d.getDay(); // 0=Sun … 6=Sat
    const diff = (day === 0 ? -6 : 1) - day;
    d.setDate(d.getDate() + diff);
    return d;
}

function addDaysLocal(date: Date, days: number): Date {
    const d = new Date(date);
    d.setDate(d.getDate() + days);
    return d;
}

function shortDate(d: Date): string {
    return d.toLocaleDateString('en-NZ', { day: '2-digit', month: 'short' });
}

/* ------------------------------------------------------------------ */
/*  Wizard form types                                                  */
/* ------------------------------------------------------------------ */

type WizardShiftRow = {
    client_id: string;
    user_id: string;
    service_context_id: string;
    day_of_week: string;
    start_time: string;
    end_time: string;
    shift_type: string;
    is_sleepover: boolean;
    is_on_call: boolean;
    is_lone_worker: boolean;
    expected_break_minutes: string;
    required_skills: string; // comma separated in the form
    location: string;
    notes: string;
};

type WizardForm = {
    name: string;
    description: string;
    template_type: string;
    is_active: boolean;
    template_shifts: WizardShiftRow[];
};

function emptyRow(): WizardShiftRow {
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
        is_lone_worker: false,
        expected_break_minutes: '',
        required_skills: '',
        location: '',
        notes: '',
    };
}

function toWizardRow(shift: RosterTemplateShiftRow): WizardShiftRow {
    return {
        client_id: shift.client_id ? String(shift.client_id) : '',
        user_id: shift.user_id ? String(shift.user_id) : '',
        service_context_id: shift.service_context_id
            ? String(shift.service_context_id)
            : '',
        day_of_week: String(shift.day_of_week ?? 0),
        start_time: shift.start_time || '07:00',
        end_time: shift.end_time || '15:00',
        shift_type: shift.shift_type || 'standard',
        is_sleepover: !!shift.is_sleepover,
        is_on_call: !!shift.is_on_call,
        is_lone_worker: !!shift.is_lone_worker,
        expected_break_minutes:
            shift.expected_break_minutes != null
                ? String(shift.expected_break_minutes)
                : '',
        required_skills: (shift.required_skills ?? []).join(', '),
        location: shift.location ?? '',
        notes: shift.notes ?? '',
    };
}

const WIZARD_STEPS: { key: 'details' | 'shifts' | 'review'; label: string; icon: IconType; blurb: string }[] = [
    {
        key: 'details',
        label: 'Details',
        icon: LayoutTemplate,
        blurb: 'Name, cadence & status',
    },
    {
        key: 'shifts',
        label: 'Shift rows',
        icon: ListChecks,
        blurb: 'The repeatable pattern',
    },
    {
        key: 'review',
        label: 'Review',
        icon: ClipboardCheck,
        blurb: 'Check the week shape',
    },
];

/* ------------------------------------------------------------------ */
/*  Wizard dialog (create / edit)                                      */
/* ------------------------------------------------------------------ */

export type TemplateWizardDialogProps = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** Present = edit mode; absent = create. */
    template?: RosterTemplateRow | null;
    clients: TemplateClientOption[];
    staff: TemplateStaffOption[];
    serviceContexts: TemplateServiceContextOption[];
};

export function TemplateWizardDialog(props: TemplateWizardDialogProps) {
    const { open, onOpenChange } = props;
    const isEdit = !!props.template;
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent
                className="overflow-hidden p-0 [&>button]:hidden"
                style={{
                    maxWidth: 'min(94vw, 980px)',
                    width: 'min(94vw, 980px)',
                }}
            >
                <DialogTitle className="sr-only">
                    {isEdit ? 'Edit roster template' : 'New roster template'}
                </DialogTitle>
                <DialogDescription className="sr-only">
                    Build a reusable weekly roster pattern that can be applied to
                    any week.
                </DialogDescription>
                {open ? <WizardBody {...props} /> : null}
            </DialogContent>
        </Dialog>
    );
}

function WizardBody({
    onOpenChange,
    template,
    clients,
    staff,
    serviceContexts,
}: TemplateWizardDialogProps) {
    const isEdit = !!template;
    const form = useForm<WizardForm>({
        name: template?.name ?? '',
        description: template?.description ?? '',
        template_type: template?.template_type ?? 'weekly',
        is_active: template?.is_active ?? true,
        template_shifts: template?.template_shifts?.length
            ? template.template_shifts.map(toWizardRow)
            : [emptyRow()],
    });
    const { data, setData, processing } = form;

    const [stepIndex, setStepIndex] = useState(0);
    const [localErrors, setLocalErrors] = useState<Record<string, string>>({});
    const cur = WIZARD_STEPS[stepIndex];
    const isLast = stepIndex === WIZARD_STEPS.length - 1;

    const clientOptions = useMemo(
        () => [
            { value: NONE, label: 'No client' },
            ...clients.map((c) => ({
                value: String(c.id),
                label: clientLabel(c),
            })),
        ],
        [clients],
    );
    const staffOptions = useMemo(
        () => [
            { value: NONE, label: 'Unassigned / open' },
            ...staff.map((s) => ({
                value: String(s.id),
                label: s.email ? `${s.name} (${s.email})` : s.name,
            })),
        ],
        [staff],
    );
    const contextOptions = useMemo(
        () => [
            { value: NONE, label: 'No service context' },
            ...serviceContexts.map((s) => ({
                value: String(s.id),
                label: s.is_active === false ? `${s.name} (inactive)` : s.name,
            })),
        ],
        [serviceContexts],
    );

    const setRow = (index: number, patch: Partial<WizardShiftRow>) => {
        setData(
            'template_shifts',
            data.template_shifts.map((row, i) =>
                i === index ? { ...row, ...patch } : row,
            ),
        );
    };
    const addRow = () =>
        setData('template_shifts', [...data.template_shifts, emptyRow()]);
    const removeRow = (index: number) =>
        setData(
            'template_shifts',
            data.template_shifts.filter((_, i) => i !== index),
        );
    // Clone a row in place (inserted right after it) and bump it to the next day —
    // the fast way to fan a shift out across the week.
    const duplicateRow = (index: number) => {
        const source = data.template_shifts[index];
        const clone: WizardShiftRow = {
            ...source,
            day_of_week: String((Number(source.day_of_week) + 1) % 7),
        };
        setData('template_shifts', [
            ...data.template_shifts.slice(0, index + 1),
            clone,
            ...data.template_shifts.slice(index + 1),
        ]);
    };

    const validateDetails = (): boolean => {
        const e: Record<string, string> = {};
        if (!data.name.trim()) e.name = 'Give the template a name.';
        setLocalErrors(e);
        return Object.keys(e).length === 0;
    };

    const validateShifts = (): boolean => {
        const e: Record<string, string> = {};
        if (data.template_shifts.length === 0) {
            e.template_shifts = 'Add at least one shift row.';
        }
        data.template_shifts.forEach((row, i) => {
            if (!row.client_id && !row.service_context_id) {
                e[`row-${i}`] =
                    'Each row needs a client or a service context.';
            } else if (row.start_time === row.end_time) {
                e[`row-${i}`] = 'Start and end time cannot be the same.';
            }
        });
        setLocalErrors(e);
        return Object.keys(e).length === 0;
    };

    const goNext = () => {
        if (cur.key === 'details' && !validateDetails()) return;
        if (cur.key === 'shifts' && !validateShifts()) return;
        setStepIndex((i) => Math.min(i + 1, WIZARD_STEPS.length - 1));
    };
    const goBack = () => {
        setLocalErrors({});
        setStepIndex((i) => Math.max(i - 1, 0));
    };

    const submit = () => {
        if (!validateDetails()) {
            setStepIndex(0);
            return;
        }
        if (!validateShifts()) {
            setStepIndex(1);
            return;
        }

        form.transform((payload) => ({
            name: payload.name,
            description: payload.description || null,
            template_type: payload.template_type,
            is_active: payload.is_active,
            template_shifts: payload.template_shifts.map((row) => ({
                client_id: row.client_id ? Number(row.client_id) : null,
                user_id: row.user_id ? Number(row.user_id) : null,
                service_context_id: row.service_context_id
                    ? Number(row.service_context_id)
                    : null,
                day_of_week: Number(row.day_of_week),
                start_time: row.start_time,
                end_time: row.end_time,
                shift_type: row.shift_type,
                is_sleepover: row.is_sleepover,
                is_on_call: row.is_on_call,
                is_lone_worker: row.is_lone_worker,
                expected_break_minutes: row.expected_break_minutes
                    ? Number(row.expected_break_minutes)
                    : null,
                required_skills: row.required_skills
                    .split(',')
                    .map((s) => s.trim())
                    .filter(Boolean),
                location: row.location || null,
                notes: row.notes || null,
            })),
        }));

        const options = {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => onOpenChange(false),
        };

        if (isEdit && template) {
            form.put(`/operations/rostering/templates/${template.id}`, options);
        } else {
            form.post('/operations/rostering/templates', options);
        }
    };

    // Server-side validation errors (e.g. normalizeTemplateShift) → surface on the rows step.
    const serverErrors = Object.values(form.errors);

    return (
        <div className="flex h-[min(92vh,820px)] min-h-0 overflow-hidden">
            {/* Stepper rail */}
            <aside className="hidden w-[248px] shrink-0 flex-col gap-1 border-r border-sidebar-border bg-sidebar p-4 sm:flex">
                <div className="mb-3 flex items-center gap-2.5">
                    <span className="grid h-9 w-9 place-items-center rounded-lg bg-primary text-primary-foreground">
                        <LayoutTemplate className="h-5 w-5" />
                    </span>
                    <div>
                        <div className="text-sm font-bold leading-tight">
                            {isEdit ? 'Edit template' : 'New template'}
                        </div>
                        <div className="text-[11px] text-muted-foreground">
                            Reusable roster pattern
                        </div>
                    </div>
                </div>
                {WIZARD_STEPS.map((s, i) => {
                    const active = i === stepIndex;
                    const complete = i < stepIndex;
                    const Icon = s.icon;
                    return (
                        <button
                            key={s.key}
                            type="button"
                            onClick={() => setStepIndex(i)}
                            className={cn(
                                'flex items-center gap-2.5 rounded-md p-2 text-left transition-colors',
                                active ? 'bg-primary/10' : 'hover:bg-accent',
                            )}
                        >
                            <span
                                className={cn(
                                    'grid h-[26px] w-[26px] shrink-0 place-items-center rounded-full text-[11px] font-bold transition-colors',
                                    active
                                        ? 'bg-primary text-primary-foreground'
                                        : complete
                                          ? 'bg-status-success-bg text-status-success'
                                          : 'bg-muted text-muted-foreground',
                                )}
                            >
                                {complete ? (
                                    <Check className="h-3.5 w-3.5" />
                                ) : (
                                    <Icon className="h-3.5 w-3.5" />
                                )}
                            </span>
                            <span className="min-w-0">
                                <span
                                    className={cn(
                                        'block text-[13px]',
                                        active
                                            ? 'font-bold text-foreground'
                                            : 'font-semibold text-muted-foreground',
                                    )}
                                >
                                    {s.label}
                                </span>
                                <span className="block truncate text-[11px] text-muted-foreground">
                                    {s.blurb}
                                </span>
                            </span>
                        </button>
                    );
                })}
            </aside>

            {/* Main column */}
            <div className="flex min-h-0 min-w-0 flex-1 flex-col">
                <header className="flex shrink-0 items-center justify-between border-b border-border px-5 py-3.5">
                    <div className="text-[13px] font-semibold text-muted-foreground">
                        Step {stepIndex + 1} of {WIZARD_STEPS.length} ·{' '}
                        <span className="text-foreground">{cur.label}</span>
                    </div>
                    <button
                        type="button"
                        onClick={() => onOpenChange(false)}
                        aria-label="Close"
                        className="grid h-8 w-8 place-items-center rounded-md text-muted-foreground hover:bg-muted"
                    >
                        <X className="h-5 w-5" />
                    </button>
                </header>

                <div className="h-[3px] shrink-0 bg-muted">
                    <div
                        className="h-full bg-primary transition-[width] duration-300"
                        style={{
                            width: `${((stepIndex + 1) / WIZARD_STEPS.length) * 100}%`,
                        }}
                    />
                </div>

                <div className="min-h-0 flex-1 overflow-y-auto overflow-x-hidden px-6 py-6">
                    {cur.key === 'details' ? (
                        <div className="animate-in fade-in slide-in-from-right-2 duration-300">
                            <StepHeading
                                icon={LayoutTemplate}
                                title="Template details"
                                blurb="Name it for the house or team it covers — you'll apply it to a chosen week later."
                            />
                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field
                                    label="Template name"
                                    required
                                    span
                                    error={localErrors.name}
                                >
                                    <Input
                                        value={data.name}
                                        onChange={(e) =>
                                            setData('name', e.target.value)
                                        }
                                        placeholder="e.g. North House weekday support"
                                        aria-invalid={!!localErrors.name}
                                    />
                                </Field>
                                <Field label="Cadence">
                                    <SelectInput
                                        value={data.template_type}
                                        onChange={(v) =>
                                            setData('template_type', v)
                                        }
                                        placeholder="Weekly"
                                        options={[
                                            { value: 'weekly', label: 'Weekly' },
                                            {
                                                value: 'fortnightly',
                                                label: 'Fortnightly',
                                            },
                                            {
                                                value: 'monthly',
                                                label: 'Monthly',
                                            },
                                        ]}
                                    />
                                </Field>
                                <Field label="Status">
                                    <label className="flex h-10 items-center gap-3 rounded-md border border-border bg-card px-3">
                                        <Switch
                                            checked={data.is_active}
                                            onCheckedChange={(v) =>
                                                setData('is_active', v)
                                            }
                                        />
                                        <span className="text-sm">
                                            {data.is_active
                                                ? 'Active'
                                                : 'Inactive'}
                                        </span>
                                    </label>
                                </Field>
                                <Field label="Description" span>
                                    <Textarea
                                        rows={3}
                                        value={data.description}
                                        onChange={(e) =>
                                            setData(
                                                'description',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="What this pattern is for, and when to use it."
                                    />
                                </Field>
                            </div>
                        </div>
                    ) : cur.key === 'shifts' ? (
                        <div className="animate-in fade-in slide-in-from-right-2 duration-300">
                            <StepHeading
                                icon={ListChecks}
                                title="Shift rows"
                                blurb="Each row becomes one shift when the template is applied. Day 1 is the Monday of the chosen week."
                            />

                            {serverErrors.length > 0 ? (
                                <Alert
                                    variant="destructive"
                                    className="mb-4"
                                >
                                    <AlertTriangle className="h-4 w-4" />
                                    <AlertTitle>
                                        Please fix the following
                                    </AlertTitle>
                                    <AlertDescription>
                                        <ul className="list-disc space-y-0.5 pl-4">
                                            {serverErrors
                                                .slice(0, 5)
                                                .map((m, i) => (
                                                    <li key={i}>{m}</li>
                                                ))}
                                        </ul>
                                    </AlertDescription>
                                </Alert>
                            ) : null}

                            <div className="space-y-3">
                                {data.template_shifts.map((row, index) => (
                                    <RowEditor
                                        key={index}
                                        index={index}
                                        row={row}
                                        canRemove={
                                            data.template_shifts.length > 1
                                        }
                                        error={localErrors[`row-${index}`]}
                                        clientOptions={clientOptions}
                                        staffOptions={staffOptions}
                                        contextOptions={contextOptions}
                                        clients={clients}
                                        onChange={(patch) =>
                                            setRow(index, patch)
                                        }
                                        onRemove={() => removeRow(index)}
                                        onDuplicate={() => duplicateRow(index)}
                                    />
                                ))}
                            </div>

                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                className="mt-3"
                                onClick={addRow}
                            >
                                <Plus className="h-4 w-4" /> Add shift row
                            </Button>
                        </div>
                    ) : (
                        <ReviewPane
                            shifts={data.template_shifts}
                            cadence={data.template_type}
                        />
                    )}
                </div>

                <footer className="flex shrink-0 items-center justify-between gap-3 border-t border-border bg-muted/30 px-5 py-3.5">
                    <div>
                        {stepIndex > 0 ? (
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={goBack}
                            >
                                <ChevronLeft className="h-4 w-4" /> Back
                            </Button>
                        ) : null}
                    </div>
                    <div className="flex items-center gap-2.5">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => onOpenChange(false)}
                        >
                            Cancel
                        </Button>
                        {isLast ? (
                            <Button
                                type="button"
                                onClick={submit}
                                disabled={processing}
                            >
                                {processing ? (
                                    <>
                                        <Loader2 className="h-4 w-4 animate-spin" />
                                        {isEdit ? 'Saving…' : 'Creating…'}
                                    </>
                                ) : (
                                    <>
                                        <Check className="h-4 w-4" />
                                        {isEdit
                                            ? 'Save changes'
                                            : 'Create template'}
                                    </>
                                )}
                            </Button>
                        ) : (
                            <Button type="button" onClick={goNext}>
                                Continue <ChevronRight className="h-4 w-4" />
                            </Button>
                        )}
                    </div>
                </footer>
            </div>
        </div>
    );
}

function StepHeading({
    icon: Icon,
    title,
    blurb,
}: {
    icon: IconType;
    title: string;
    blurb: string;
}) {
    return (
        <div className="mb-5 flex items-start gap-3">
            <span className="shrink-0 rounded-xl bg-primary/10 p-2.5 text-primary">
                <Icon className="h-5 w-5" />
            </span>
            <div>
                <h2 className="text-lg font-bold tracking-tight">{title}</h2>
                <p className="mt-0.5 text-sm text-muted-foreground">{blurb}</p>
            </div>
        </div>
    );
}

function RowEditor({
    index,
    row,
    canRemove,
    error,
    clientOptions,
    staffOptions,
    contextOptions,
    clients,
    onChange,
    onRemove,
    onDuplicate,
}: {
    index: number;
    row: WizardShiftRow;
    canRemove: boolean;
    error?: string;
    clientOptions: { value: string; label: string }[];
    staffOptions: { value: string; label: string }[];
    contextOptions: { value: string; label: string }[];
    clients: TemplateClientOption[];
    onChange: (patch: Partial<WizardShiftRow>) => void;
    onRemove: () => void;
    onDuplicate: () => void;
}) {
    const overnight = row.end_time <= row.start_time;
    return (
        <div
            className={cn(
                'space-y-4 rounded-lg border bg-muted/10 p-4',
                error ? 'border-status-critical/50' : 'border-border',
            )}
        >
            <div className="flex items-center justify-between gap-3">
                <div className="flex items-center gap-2 text-sm font-semibold">
                    <span className="grid h-6 w-6 place-items-center rounded-md bg-primary/10 text-[11px] text-primary">
                        {index + 1}
                    </span>
                    {DAY_LABELS[Number(row.day_of_week)] ?? 'Day'} ·{' '}
                    {row.start_time}–{row.end_time}
                    {overnight ? (
                        <span className="text-[11px] font-normal text-muted-foreground">
                            (+1 day)
                        </span>
                    ) : null}
                </div>
                <div className="flex items-center gap-1">
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        className="text-muted-foreground"
                        onClick={onDuplicate}
                    >
                        <Copy className="h-3.5 w-3.5" /> Duplicate
                    </Button>
                    {canRemove ? (
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            className="text-muted-foreground"
                            onClick={onRemove}
                        >
                            <Trash2 className="h-3.5 w-3.5" /> Remove
                        </Button>
                    ) : null}
                </div>
            </div>

            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <SubHead icon={Clock}>When</SubHead>
                <Field label="Day">
                    <SelectInput
                        value={row.day_of_week}
                        onChange={(v) => onChange({ day_of_week: v })}
                        placeholder="Monday"
                        options={DAY_OPTIONS}
                    />
                </Field>
                <Field label="Start">
                    <Input
                        type="time"
                        value={row.start_time}
                        onChange={(e) =>
                            onChange({ start_time: e.target.value })
                        }
                    />
                </Field>
                <Field label="End">
                    <Input
                        type="time"
                        value={row.end_time}
                        onChange={(e) => onChange({ end_time: e.target.value })}
                    />
                </Field>
                <Field label="Shift type">
                    <SelectInput
                        value={row.shift_type}
                        onChange={(v) => onChange({ shift_type: v })}
                        placeholder="Standard"
                        options={SHIFT_TYPE_OPTIONS}
                    />
                </Field>

                <SubHead icon={Users}>Who</SubHead>
                <Field label="Client">
                    <SelectInput
                        value={row.client_id || NONE}
                        onChange={(v) => {
                            const clientId = v === NONE ? '' : v;
                            const patch: Partial<WizardShiftRow> = {
                                client_id: clientId,
                            };
                            // Auto-fill the service context from the client when blank.
                            if (clientId && !row.service_context_id) {
                                const picked = clients.find(
                                    (c) => String(c.id) === clientId,
                                );
                                if (picked?.service_context_id) {
                                    patch.service_context_id = String(
                                        picked.service_context_id,
                                    );
                                }
                            }
                            onChange(patch);
                        }}
                        placeholder="No client"
                        options={clientOptions}
                    />
                </Field>
                <Field label="Assigned staff">
                    <SelectInput
                        value={row.user_id || NONE}
                        onChange={(v) =>
                            onChange({ user_id: v === NONE ? '' : v })
                        }
                        placeholder="Unassigned / open"
                        options={staffOptions}
                    />
                </Field>
                <Field label="Service context" span>
                    <SelectInput
                        value={row.service_context_id || NONE}
                        onChange={(v) =>
                            onChange({
                                service_context_id: v === NONE ? '' : v,
                            })
                        }
                        placeholder="No service context"
                        options={contextOptions}
                    />
                </Field>

                <SubHead icon={ListChecks}>Details</SubHead>
                <Field label="Break (min)">
                    <Input
                        type="number"
                        min="0"
                        max="720"
                        value={row.expected_break_minutes}
                        onChange={(e) =>
                            onChange({
                                expected_break_minutes: e.target.value,
                            })
                        }
                        placeholder="0"
                    />
                </Field>
                <Field label="Location" span>
                    <Input
                        value={row.location}
                        onChange={(e) => onChange({ location: e.target.value })}
                        placeholder="e.g. North House"
                    />
                </Field>
                <Field label="Required skills" hint="comma separated">
                    <Input
                        value={row.required_skills}
                        onChange={(e) =>
                            onChange({ required_skills: e.target.value })
                        }
                        placeholder="Medication, Hoist"
                    />
                </Field>
                <Field label="Notes" span>
                    <Textarea
                        rows={2}
                        value={row.notes}
                        onChange={(e) => onChange({ notes: e.target.value })}
                        placeholder="Anything schedulers or staff should know."
                    />
                </Field>

                <div className="col-span-full flex flex-wrap items-center gap-4 pt-1">
                    <label className="flex items-center gap-2 text-sm">
                        <Switch
                            checked={row.is_sleepover}
                            onCheckedChange={(v) =>
                                onChange({ is_sleepover: v })
                            }
                        />
                        Sleepover
                    </label>
                    <label className="flex items-center gap-2 text-sm">
                        <Switch
                            checked={row.is_on_call}
                            onCheckedChange={(v) => onChange({ is_on_call: v })}
                        />
                        On-call
                    </label>
                    <label className="flex items-center gap-2 text-sm">
                        <Switch
                            checked={row.is_lone_worker}
                            onCheckedChange={(v) =>
                                onChange({ is_lone_worker: v })
                            }
                        />
                        Lone / remote worker
                    </label>
                </div>
            </div>

            {error ? (
                <p className="flex items-center gap-1 text-xs text-status-critical">
                    <AlertTriangle className="h-3 w-3 shrink-0" />
                    {error}
                </p>
            ) : null}
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Review step — the week shape before committing                     */
/* ------------------------------------------------------------------ */

function ReviewPane({
    shifts,
    cadence,
}: {
    shifts: WizardShiftRow[];
    cadence: string;
}) {
    const byDay = useMemo(() => {
        const counts = [0, 0, 0, 0, 0, 0, 0];
        for (const s of shifts) {
            const d = Number(s.day_of_week);
            if (d >= 0 && d < 7) counts[d]++;
        }
        return counts;
    }, [shifts]);

    const assigned = shifts.filter((s) => s.user_id).length;
    const open = shifts.length - assigned;
    const cadenceNote =
        cadence === 'fortnightly'
            ? 'Each apply cycle advances 2 weeks.'
            : cadence === 'monthly'
              ? 'Each apply cycle advances 4 weeks.'
              : 'Each apply cycle advances 1 week.';

    return (
        <div className="animate-in fade-in slide-in-from-right-2 duration-300">
            <StepHeading
                icon={ClipboardCheck}
                title="Review"
                blurb="This is the week each apply will stamp. Day 1 is the Monday of the chosen week."
            />

            <div className="mb-4 grid grid-cols-7 gap-1">
                {DAY_SHORT.map((day, i) => {
                    const count = byDay[i];
                    return (
                        <div
                            key={day}
                            className={cn(
                                'flex h-12 flex-col items-center justify-center rounded-md border text-[11px] font-semibold',
                                count
                                    ? 'border-primary/30 bg-primary/10 text-primary'
                                    : 'border-border bg-muted/40 text-muted-foreground',
                            )}
                        >
                            <span className="uppercase tracking-wide">{day}</span>
                            <span className="tabular-nums">{count || '·'}</span>
                        </div>
                    );
                })}
            </div>

            <div className="flex flex-wrap items-center gap-1.5 text-[11px]">
                <span className="rounded-full bg-muted px-2 py-0.5 font-semibold text-muted-foreground">
                    {shifts.length} {shifts.length === 1 ? 'shift' : 'shifts'} / week
                </span>
                <span className="rounded-full bg-muted px-2 py-0.5 font-semibold text-muted-foreground">
                    {assigned} assigned
                </span>
                {open > 0 ? (
                    <span className="rounded-full bg-status-warning-bg px-2 py-0.5 font-semibold text-status-warning">
                        {open} open
                    </span>
                ) : null}
                <span className="rounded-full bg-primary/10 px-2 py-0.5 font-semibold capitalize text-primary">
                    {cadence}
                </span>
            </div>
            <p className="mt-2 text-[13px] text-muted-foreground">{cadenceNote}</p>

            <div className="mt-4 space-y-2.5">
                {DAY_LABELS.map((label, dayIdx) => {
                    const dayRows = shifts.filter(
                        (s) => Number(s.day_of_week) === dayIdx,
                    );
                    if (dayRows.length === 0) return null;
                    return (
                        <div
                            key={label}
                            className="rounded-lg border border-border p-3"
                        >
                            <div className="text-sm font-semibold">{label}</div>
                            <ul className="mt-1.5 space-y-1 text-[13px] text-muted-foreground">
                                {dayRows.map((r, i) => {
                                    const overnight = r.end_time <= r.start_time;
                                    return (
                                        <li
                                            key={i}
                                            className="flex flex-wrap items-center gap-x-2"
                                        >
                                            <span className="font-medium text-foreground tabular-nums">
                                                {r.start_time}–{r.end_time}
                                                {overnight ? ' (+1)' : ''}
                                            </span>
                                            <span aria-hidden>·</span>
                                            <span>
                                                {r.user_id ? 'Assigned' : 'Open'}
                                            </span>
                                            {r.is_sleepover ? (
                                                <span className="rounded bg-primary/10 px-1.5 text-[11px] font-semibold text-primary">
                                                    Sleepover
                                                </span>
                                            ) : null}
                                            {r.is_on_call ? (
                                                <span className="rounded bg-primary/10 px-1.5 text-[11px] font-semibold text-primary">
                                                    On-call
                                                </span>
                                            ) : null}
                                            {r.is_lone_worker ? (
                                                <span className="rounded bg-status-warning/15 px-1.5 text-[11px] font-semibold text-status-warning">
                                                    Lone worker
                                                </span>
                                            ) : null}
                                        </li>
                                    );
                                })}
                            </ul>
                        </div>
                    );
                })}
            </div>
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Detail dialog (view + apply)                                       */
/* ------------------------------------------------------------------ */

export type TemplateDetailDialogProps = {
    template: RosterTemplateRow | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    canManage: boolean;
    canDelete: boolean;
    onEdit: (template: RosterTemplateRow) => void;
    onDelete: (template: RosterTemplateRow) => void;
};

export function TemplateDetailDialog({
    template,
    open,
    onOpenChange,
    canManage,
    canDelete,
    onEdit,
    onDelete,
}: TemplateDetailDialogProps) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent
                className="max-h-[92vh] overflow-hidden p-0"
                style={{
                    maxWidth: 'min(94vw, 940px)',
                    width: 'min(94vw, 940px)',
                }}
            >
                <DialogTitle className="sr-only">
                    {template?.name ?? 'Roster template'}
                </DialogTitle>
                <DialogDescription className="sr-only">
                    Review the template rows and apply the pattern to a week.
                </DialogDescription>
                {open && template ? (
                    <DetailBody
                        template={template}
                        onOpenChange={onOpenChange}
                        canManage={canManage}
                        canDelete={canDelete}
                        onEdit={onEdit}
                        onDelete={onDelete}
                    />
                ) : null}
            </DialogContent>
        </Dialog>
    );
}

function DetailBody({
    template,
    onOpenChange,
    canManage,
    canDelete,
    onEdit,
    onDelete,
}: {
    template: RosterTemplateRow;
    onOpenChange: (open: boolean) => void;
    canManage: boolean;
    canDelete: boolean;
    onEdit: (template: RosterTemplateRow) => void;
    onDelete: (template: RosterTemplateRow) => void;
}) {
    const applyForm = useForm({
        week_start: nextMonday(),
        cycles: 1,
        confirm_warnings: false,
    });
    const [confirmDeleteOpen, setConfirmDeleteOpen] = useState(false);

    const intervalWeeks =
        template.template_type === 'fortnightly'
            ? 2
            : template.template_type === 'monthly'
              ? 4
              : 1;
    const cycles = applyForm.data.cycles;
    const totalShifts = template.template_shifts_count * cycles;
    const cycleWeeks = useMemo(
        () =>
            Array.from({ length: cycles }, (_, k) =>
                shortDate(
                    addDaysLocal(
                        mondayOf(applyForm.data.week_start),
                        k * intervalWeeks * 7,
                    ),
                ),
            ),
        [applyForm.data.week_start, cycles, intervalWeeks],
    );
    const [warningOpen, setWarningOpen] = useState(false);
    const errors = applyForm.errors as Record<string, string | undefined>;
    const warningLines = useMemo(
        () =>
            errors.preflight_warnings
                ? errors.preflight_warnings.split('\n').filter(Boolean)
                : [],
        [errors.preflight_warnings],
    );
    const blockLines = useMemo(
        () =>
            errors.preflight_blocks
                ? errors.preflight_blocks.split('\n').filter(Boolean)
                : [],
        [errors.preflight_blocks],
    );

    useEffect(() => {
        if (warningLines.length > 0) setWarningOpen(true);
    }, [warningLines.length]);

    const postApply = (confirmWarnings: boolean) => {
        applyForm.transform((d) => ({ ...d, confirm_warnings: confirmWarnings }));
        applyForm.post(
            `/operations/rostering/templates/${template.id}/apply`,
            {
                preserveScroll: true,
                onSuccess: () => onOpenChange(false),
                onFinish: () => applyForm.transform((d) => d),
            },
        );
    };

    return (
        <div className="flex max-h-[92vh] min-h-0 flex-col">
            <header className="flex shrink-0 items-start justify-between gap-3 border-b border-border px-5 py-4">
                <div className="min-w-0">
                    <h2 className="truncate text-lg font-bold tracking-tight">
                        {template.name}
                    </h2>
                    <div className="mt-1 flex flex-wrap items-center gap-1.5 text-[11px]">
                        <span className="rounded-full bg-primary/10 px-2 py-0.5 font-semibold capitalize text-primary">
                            {template.template_type}
                        </span>
                        <span
                            className={cn(
                                'rounded-full px-2 py-0.5 font-semibold',
                                template.is_active
                                    ? 'bg-status-success-bg text-status-success'
                                    : 'bg-muted text-muted-foreground',
                            )}
                        >
                            {template.is_active ? 'Active' : 'Inactive'}
                        </span>
                        <span className="text-muted-foreground">
                            {template.template_shifts_count}{' '}
                            {template.template_shifts_count === 1
                                ? 'row'
                                : 'rows'}{' '}
                            · by {template.creator?.name ?? 'Unknown'}
                        </span>
                    </div>
                </div>
                <div className="flex shrink-0 items-center gap-2">
                    {canManage ? (
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() => onEdit(template)}
                        >
                            <Pencil className="h-3.5 w-3.5" /> Edit
                        </Button>
                    ) : null}
                    {canDelete ? (
                        <Button
                            variant="outline"
                            size="sm"
                            className="text-status-critical hover:text-status-critical"
                            onClick={() => setConfirmDeleteOpen(true)}
                        >
                            <Trash2 className="h-3.5 w-3.5" /> Delete
                        </Button>
                    ) : null}
                    <button
                        type="button"
                        onClick={() => onOpenChange(false)}
                        aria-label="Close"
                        className="grid h-8 w-8 place-items-center rounded-md text-muted-foreground hover:bg-muted"
                    >
                        <X className="h-5 w-5" />
                    </button>
                </div>
            </header>

            <div className="grid min-h-0 flex-1 gap-0 overflow-hidden lg:grid-cols-[1fr_320px]">
                {/* Rows */}
                <div className="min-h-0 overflow-y-auto px-5 py-4">
                    {template.description ? (
                        <p className="mb-3 text-sm text-muted-foreground">
                            {template.description}
                        </p>
                    ) : null}
                    {template.template_shifts.length > 0 ? (
                        <div className="space-y-2.5">
                            {template.template_shifts.map((shift, i) => (
                                <DetailRow key={shift.id ?? i} shift={shift} />
                            ))}
                        </div>
                    ) : (
                        <div className="rounded-lg border border-dashed border-border p-6 text-center text-sm text-muted-foreground">
                            This template has no shift rows yet.
                        </div>
                    )}
                </div>

                {/* Apply panel */}
                <div
                    className="min-h-0 overflow-y-auto border-t border-border bg-muted/20 px-5 py-4 lg:border-l lg:border-t-0"
                    data-test="template-apply-card"
                >
                    <div className="flex items-center gap-2 text-sm font-bold">
                        <CalendarPlus className="h-4 w-4 text-primary" />
                        Apply to a week
                    </div>
                    <p className="mt-1 text-[13px] text-muted-foreground">
                        Creates draft shifts for the chosen week (snapped to its
                        Monday). Apply more than one cycle to stamp several weeks.
                    </p>

                    {blockLines.length > 0 ? (
                        <Alert
                            variant="destructive"
                            className="mt-3"
                            data-test="template-apply-blocks"
                        >
                            <AlertTriangle className="h-4 w-4" />
                            <AlertTitle>Template cannot be applied</AlertTitle>
                            <AlertDescription>
                                <ul className="list-disc space-y-1 pl-4">
                                    {blockLines.map((line, i) => (
                                        <li key={i}>{line}</li>
                                    ))}
                                </ul>
                            </AlertDescription>
                        </Alert>
                    ) : null}

                    <form
                        className="mt-3 space-y-3"
                        onSubmit={(e) => {
                            e.preventDefault();
                            postApply(false);
                        }}
                    >
                        <div className="space-y-1.5">
                            <Label htmlFor="week-start">Week start</Label>
                            <Input
                                id="week-start"
                                type="date"
                                value={applyForm.data.week_start}
                                onChange={(e) =>
                                    applyForm.setData(
                                        'week_start',
                                        e.target.value,
                                    )
                                }
                            />
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="apply-cycles">
                                Cycles
                                {intervalWeeks > 1
                                    ? ` · every ${intervalWeeks} weeks`
                                    : ''}
                            </Label>
                            <Input
                                id="apply-cycles"
                                type="number"
                                min={1}
                                max={12}
                                value={applyForm.data.cycles}
                                onChange={(e) =>
                                    applyForm.setData(
                                        'cycles',
                                        Math.max(
                                            1,
                                            Math.min(
                                                12,
                                                Number(e.target.value) || 1,
                                            ),
                                        ),
                                    )
                                }
                            />
                        </div>
                        {template.template_shifts_count > 0 ? (
                            <div
                                className="rounded-md border border-border bg-card/60 p-2.5 text-[12px] text-muted-foreground"
                                data-test="template-apply-preview"
                            >
                                Creates{' '}
                                <span className="font-semibold text-foreground tabular-nums">
                                    {totalShifts}
                                </span>{' '}
                                draft shift{totalShifts === 1 ? '' : 's'} across{' '}
                                <span className="font-semibold text-foreground tabular-nums">
                                    {cycles}
                                </span>{' '}
                                week{cycles === 1 ? '' : 's'} —{' '}
                                <span className="text-foreground">
                                    {cycleWeeks.join(', ')}
                                </span>
                            </div>
                        ) : null}
                        <Button
                            type="submit"
                            className="w-full"
                            disabled={applyForm.processing}
                            data-test="template-apply-submit"
                        >
                            {applyForm.processing ? (
                                <Loader2 className="h-4 w-4 animate-spin" />
                            ) : (
                                <CalendarRange className="h-4 w-4" />
                            )}
                            Apply to roster
                        </Button>
                    </form>
                </div>
            </div>

            <AlertDialog open={warningOpen} onOpenChange={setWarningOpen}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            Review template warnings
                        </AlertDialogTitle>
                        <AlertDialogDescription asChild>
                            <div className="space-y-3">
                                <p>
                                    The template can be applied, but these items
                                    should be reviewed first.
                                </p>
                                <ul className="max-h-64 list-disc space-y-1 overflow-auto pl-4">
                                    {warningLines.map((line, i) => (
                                        <li key={i}>{line}</li>
                                    ))}
                                </ul>
                            </div>
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                        <AlertDialogAction
                            disabled={applyForm.processing}
                            onClick={(e) => {
                                e.preventDefault();
                                setWarningOpen(false);
                                postApply(true);
                            }}
                        >
                            Apply anyway
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>

            <AlertDialog
                open={confirmDeleteOpen}
                onOpenChange={setConfirmDeleteOpen}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            Delete “{template.name}”?
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            This removes the template and its{' '}
                            {template.template_shifts_count} shift rows. Shifts
                            already created from it are not affected. This cannot
                            be undone.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                        <AlertDialogAction
                            className="bg-status-critical text-white hover:bg-status-critical/90"
                            onClick={() => {
                                setConfirmDeleteOpen(false);
                                onOpenChange(false);
                                onDelete(template);
                            }}
                        >
                            Delete template
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </div>
    );
}

function DetailRow({ shift }: { shift: RosterTemplateShiftRow }) {
    const overnight = shift.end_time <= shift.start_time;
    const dayLabel = DAY_LABELS[shift.day_of_week] ?? `Day ${shift.day_of_week}`;
    return (
        <div className="rounded-lg border border-border p-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div className="text-sm font-semibold">
                    {dayLabel} · {shift.start_time}–{shift.end_time}
                    {overnight ? (
                        <span className="ml-1 text-[11px] font-normal text-muted-foreground">
                            (+1 day)
                        </span>
                    ) : null}
                </div>
                <div className="flex flex-wrap gap-1.5 text-[11px]">
                    <span className="rounded-full bg-muted px-2 py-0.5 font-semibold capitalize text-muted-foreground">
                        {shift.shift_type ?? 'standard'}
                    </span>
                    {shift.is_sleepover ? (
                        <span className="rounded-full bg-primary/10 px-2 py-0.5 font-semibold text-primary">
                            Sleepover
                        </span>
                    ) : null}
                    {shift.is_on_call ? (
                        <span className="rounded-full bg-primary/10 px-2 py-0.5 font-semibold text-primary">
                            On-call
                        </span>
                    ) : null}
                    {shift.is_lone_worker ? (
                        <span className="rounded-full bg-status-warning/15 px-2 py-0.5 font-semibold text-status-warning">
                            Lone worker
                        </span>
                    ) : null}
                </div>
            </div>
            <div className="mt-2 grid gap-x-4 gap-y-1 text-[13px] text-muted-foreground sm:grid-cols-2">
                <span>
                    Client:{' '}
                    <span className="font-medium text-foreground">
                        {shift.client
                            ? `${shift.client.first_name} ${shift.client.last_name}`
                            : 'No client'}
                    </span>
                </span>
                <span>
                    Staff:{' '}
                    <span className="font-medium text-foreground">
                        {shift.user?.name ?? 'Unassigned'}
                    </span>
                </span>
                <span>
                    Service context:{' '}
                    <span className="font-medium text-foreground">
                        {shift.service_context?.name ?? 'None'}
                    </span>
                </span>
                <span>
                    Break:{' '}
                    <span className="font-medium text-foreground">
                        {shift.expected_break_minutes ?? 0} min
                    </span>
                </span>
                {shift.location ? (
                    <span>
                        Location:{' '}
                        <span className="font-medium text-foreground">
                            {shift.location}
                        </span>
                    </span>
                ) : null}
                {shift.required_skills.length > 0 ? (
                    <span>
                        Skills:{' '}
                        <span className="font-medium text-foreground">
                            {shift.required_skills.join(', ')}
                        </span>
                    </span>
                ) : null}
            </div>
            {shift.notes ? (
                <p className="mt-2 text-[13px] text-muted-foreground">
                    {shift.notes}
                </p>
            ) : null}
        </div>
    );
}
