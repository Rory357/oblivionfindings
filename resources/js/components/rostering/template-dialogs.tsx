/* eslint-disable no-restricted-syntax -- The template wizard mirrors the bespoke
 * Add-client modal surface (stepper rail + scroll-contained body + custom footer).
 * Every colour is a semantic design token, per design_styles/DESIGN_TOKENS.md. */
import { ConfirmDialog } from '@/components/confirm-dialog';
import { TimePicker } from '@/components/fleet-assets/maintenance/time-picker';
import { RecordPicker } from '@/components/people-locations/record-picker';
import { Checkbox } from '@/components/ui/checkbox';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
    WizardSuccessPane,
} from '@/components/wizard/shell';
import { WORKER_TIMEZONE } from '@/lib/datetime';
import { useForm } from '@inertiajs/react';
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
import { TemplateCommandNotice } from './template-command-notice';
import type {
    TemplateCommand,
    TemplateResult,
    TemplateValues,
} from './use-template-command';

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
    site_id: number | null;
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
    site_id: number | null;
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

function contextMatchesClient(
    context: TemplateServiceContextOption,
    client: TemplateClientOption | undefined,
): boolean {
    return (
        context.is_active !== false &&
        (context.site_id === null ||
            (client?.site_id != null && context.site_id === client.site_id))
    );
}

export function templateContextsForClient(
    clientId: string,
    clients: TemplateClientOption[],
    contexts: TemplateServiceContextOption[],
): TemplateServiceContextOption[] {
    const client = clients.find(
        (candidate) => String(candidate.id) === clientId,
    );

    return contexts.filter((context) => contextMatchesClient(context, client));
}

export function retainedTemplateContextId(
    clientId: string,
    currentContextId: string,
    clients: TemplateClientOption[],
    contexts: TemplateServiceContextOption[],
): string {
    const client = clients.find(
        (candidate) => String(candidate.id) === clientId,
    );
    const current = contexts.find(
        (context) => String(context.id) === currentContextId,
    );

    return current && contextMatchesClient(current, client)
        ? currentContextId
        : '';
}

export function reconcileTemplateContextId(
    clientId: string,
    currentContextId: string,
    clients: TemplateClientOption[],
    contexts: TemplateServiceContextOption[],
): string {
    const client = clients.find(
        (candidate) => String(candidate.id) === clientId,
    );
    const retainedContextId = retainedTemplateContextId(
        clientId,
        currentContextId,
        clients,
        contexts,
    );

    if (retainedContextId) {
        return retainedContextId;
    }

    const preferred = contexts.find(
        (context) =>
            client?.service_context_id != null &&
            context.id === client.service_context_id &&
            contextMatchesClient(context, client),
    );

    return preferred ? String(preferred.id) : '';
}

export function templateApplyBlockLines(errors: {
    template_shifts?: string;
    preflight_blocks?: string;
}): string[] {
    return [
        ...(errors.template_shifts ? [errors.template_shifts] : []),
        ...(errors.preflight_blocks
            ? errors.preflight_blocks.split('\n').filter(Boolean)
            : []),
    ];
}

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

function toWizardRow(
    shift: RosterTemplateShiftRow,
    clients: TemplateClientOption[],
    serviceContexts: TemplateServiceContextOption[],
): WizardShiftRow {
    const clientId = shift.client_id ? String(shift.client_id) : '';
    const contextId = shift.service_context_id
        ? String(shift.service_context_id)
        : '';

    return {
        client_id: clientId,
        user_id: shift.user_id ? String(shift.user_id) : '',
        service_context_id: retainedTemplateContextId(
            clientId,
            contextId,
            clients,
            serviceContexts,
        ),
        day_of_week: String(shift.day_of_week ?? 0),
        start_time: shift.start_time?.slice(0, 5) || '07:00',
        end_time: shift.end_time?.slice(0, 5) || '15:00',
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

const WIZARD_STEPS: {
    key: 'details' | 'shifts' | 'review';
    label: string;
    icon: IconType;
    blurb: string;
}[] = [
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
    workerTimezone?: string;
    command: TemplateCommand;
};

export function TemplateWizardDialog(props: TemplateWizardDialogProps) {
    return props.open ? (
        <WizardBody key={props.template?.id ?? 'create'} {...props} />
    ) : null;
}

function WizardBody({
    onOpenChange,
    template,
    clients,
    staff,
    serviceContexts,
    workerTimezone = WORKER_TIMEZONE,
    command,
}: TemplateWizardDialogProps) {
    const isEdit = !!template;
    const form = useForm<WizardForm>({
        name: template?.name ?? '',
        description: template?.description ?? '',
        template_type: template?.template_type ?? 'weekly',
        is_active: template?.is_active ?? true,
        template_shifts: template?.template_shifts?.length
            ? template.template_shifts.map((shift) =>
                  toWizardRow(shift, clients, serviceContexts),
              )
            : [emptyRow()],
    });
    const { data, setData } = form;
    const [receipt, setReceipt] = useState<TemplateResult | null>(null);
    const [uncertain, setUncertain] = useState(false);
    const [recoveryChecked, setRecoveryChecked] = useState(false);
    const [recoveryRows, setRecoveryRows] = useState<
        RosterTemplateRow[] | null
    >(null);
    const processing = command.busy;
    const saved = receipt !== null;
    const blocked = command.blocked || uncertain;
    useEffect(() => {
        if (command.notice?.kind === 'unknown') setUncertain(true);
    }, [command.notice]);

    const [stepIndex, setStepIndex] = useState(0);
    const [discardOpen, setDiscardOpen] = useState(false);
    const requestClose = () => {
        if (processing || command.isBusy()) return;
        if ((form.isDirty || uncertain) && !saved) setDiscardOpen(true);
        else onOpenChange(false);
    };
    const completedFields =
        Number(Boolean(data.name.trim())) +
        Number(Boolean(data.description.trim())) +
        data.template_shifts.reduce(
            (count, row) =>
                count +
                Number(Boolean(row.client_id)) +
                Number(Boolean(row.start_time)) +
                Number(Boolean(row.end_time)),
            0,
        );
    const completeness = Math.round(
        (completedFields / (2 + data.template_shifts.length * 3)) * 100,
    );
    const [localErrors, setLocalErrors] = useState<Record<string, string>>({});
    const cur = WIZARD_STEPS[stepIndex];
    const isLast = stepIndex === WIZARD_STEPS.length - 1;

    const clientOptions = useMemo(
        () =>
            clients.map((c) => ({
                value: String(c.id),
                label: clientLabel(c),
            })),
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
            if (!row.client_id) {
                e[`row-${i}`] = 'Each row needs a client.';
            } else if (
                ![row.start_time, row.end_time].every((time) =>
                    /^([01]\d|2[0-3]):[0-5]\d$/.test(time),
                )
            ) {
                e[`row-${i}`] = 'Choose a valid start and end time.';
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
        if (processing || command.isBusy() || blocked) return;
        if (!validateDetails()) {
            setStepIndex(0);
            return;
        }
        if (!validateShifts()) {
            setStepIndex(1);
            return;
        }

        form.clearErrors();
        const values: TemplateValues = {
            name: data.name,
            description: data.description || null,
            template_type: data.template_type,
            is_active: data.is_active,
            template_shifts: data.template_shifts.map((row) => ({
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
                    .map((skill) => skill.trim())
                    .filter(Boolean),
                location: row.location || null,
                notes: row.notes || null,
            })),
        };
        void command.submit(
            {
                action: isEdit ? 'update' : 'create',
                source: template
                    ? {
                          template_id: template.id,
                          source_revision: template.source_revision ?? '',
                      }
                    : null,
                values,
                rowCount: values.template_shifts.length,
            },
            setReceipt,
            (errors) => {
                form.setError(errors);
                setStepIndex(
                    Object.keys(errors).some((key) =>
                        [
                            'name',
                            'description',
                            'template_type',
                            'is_active',
                        ].includes(key),
                    )
                        ? 0
                        : 1,
                );
            },
        );
    };

    // Server-side validation errors (e.g. normalizeTemplateShift) → surface on the rows step.
    const serverErrors = [...new Set(Object.values(form.errors))];
    const reload = () =>
        command.refresh((current) => {
            setRecoveryRows(current.rosterTemplates);
            setRecoveryChecked(false);
        });
    const sourceUnchanged =
        !template ||
        recoveryRows?.some(
            (row) =>
                row.id === template.id &&
                row.source_revision === template.source_revision,
        );

    return (
        <>
            <WizardShell
                open
                onClose={requestClose}
                title={isEdit ? 'Edit roster template' : 'New roster template'}
                description="Build and review a reusable roster pattern before saving."
                railIcon={LayoutTemplate}
                railTitle={isEdit ? 'Edit template' : 'New template'}
                railSub="Reusable roster pattern"
                steps={WIZARD_STEPS}
                stepIndex={stepIndex}
                onStepClick={(index) => !processing && setStepIndex(index)}
                pct={completeness}
                footerStart={
                    stepIndex > 0 ? (
                        <Button
                            variant="ghost"
                            onClick={goBack}
                            disabled={processing}
                        >
                            <ChevronLeft className="h-4 w-4" /> Back
                        </Button>
                    ) : null
                }
                footerEnd={
                    <>
                        <Button
                            variant="outline"
                            onClick={requestClose}
                            disabled={processing}
                        >
                            Cancel
                        </Button>
                        {isLast ? (
                            <Button
                                onClick={submit}
                                disabled={processing || blocked}
                            >
                                {processing ? (
                                    <Loader2 className="h-4 w-4 animate-spin" />
                                ) : (
                                    <Check className="h-4 w-4" />
                                )}
                                {processing
                                    ? 'Saving…'
                                    : isEdit
                                      ? 'Save changes'
                                      : 'Create template'}
                            </Button>
                        ) : (
                            <Button onClick={goNext} disabled={processing}>
                                Continue <ChevronRight className="h-4 w-4" />
                            </Button>
                        )}
                    </>
                }
                success={
                    saved ? (
                        <WizardSuccessPane
                            title={
                                isEdit ? 'Template updated' : 'Template created'
                            }
                            blurb="Your roster pattern has been saved. Apply it to a chosen week when you are ready; saving the pattern does not create shifts."
                            actions={
                                <Button onClick={() => onOpenChange(false)}>
                                    Done
                                </Button>
                            }
                        />
                    ) : undefined
                }
            >
                <WizardStepPane>
                    {command.notice?.kind !== 'confirmed' ? (
                        <TemplateCommandNotice
                            command={command}
                            onReload={reload}
                        />
                    ) : null}
                    {uncertain && recoveryRows && !command.needsRead ? (
                        <Alert className="mb-4">
                            <AlertTitle>Check the saved library</AlertTitle>
                            <AlertDescription>
                                <p>
                                    Your draft is still here. The library
                                    currently contains:
                                </p>
                                <ul className="my-2 max-h-32 list-disc overflow-auto pl-4">
                                    {recoveryRows.map((row) => (
                                        <li key={row.id}>
                                            {row.name} ·{' '}
                                            {row.template_shifts_count} rows
                                        </li>
                                    ))}
                                </ul>
                                {sourceUnchanged ? (
                                    <>
                                        <p>
                                            Check whether this attempt already
                                            saved before sending it again.
                                        </p>
                                        <label className="my-2 flex items-center gap-2">
                                            <Checkbox
                                                checked={recoveryChecked}
                                                onCheckedChange={(value) =>
                                                    setRecoveryChecked(
                                                        value === true,
                                                    )
                                                }
                                            />{' '}
                                            I checked the current templates and
                                            want to continue with this draft.
                                        </label>
                                        <Button
                                            variant="outline"
                                            disabled={!recoveryChecked}
                                            onClick={() => setUncertain(false)}
                                        >
                                            Continue editing
                                        </Button>
                                    </>
                                ) : (
                                    <p>
                                        The saved template has changed or is no
                                        longer available. Keep this draft open
                                        for reference, or close it and open the
                                        current template before editing.
                                    </p>
                                )}
                            </AlertDescription>
                        </Alert>
                    ) : null}
                    {serverErrors.length > 0 ? (
                        <Alert variant="destructive" className="mb-4">
                            <AlertTriangle className="h-4 w-4" />
                            <AlertTitle>Template not saved</AlertTitle>
                            <AlertDescription>
                                <ul className="list-disc space-y-1 pl-4">
                                    {serverErrors.map((message, index) => (
                                        <li key={index}>{message}</li>
                                    ))}
                                </ul>
                            </AlertDescription>
                        </Alert>
                    ) : null}
                    <fieldset
                        disabled={processing || blocked}
                        className="min-w-0"
                    >
                        {cur.key === 'details' ? (
                            <div className="animate-in duration-300 fade-in slide-in-from-right-2">
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
                                        error={
                                            localErrors.name ?? form.errors.name
                                        }
                                    >
                                        <Input
                                            value={data.name}
                                            onChange={(e) =>
                                                setData('name', e.target.value)
                                            }
                                            placeholder="e.g. North House weekday support"
                                            aria-invalid={
                                                !!(
                                                    localErrors.name ??
                                                    form.errors.name
                                                )
                                            }
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
                                                {
                                                    value: 'weekly',
                                                    label: 'Weekly',
                                                },
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
                            <div className="animate-in duration-300 fade-in slide-in-from-right-2">
                                <StepHeading
                                    icon={ListChecks}
                                    title="Shift rows"
                                    blurb="Each row becomes one shift when the template is applied. Day 1 is the Monday of the chosen week."
                                />

                                <p className="mb-4 text-sm text-muted-foreground">
                                    Times use {workerTimezone}. An earlier end
                                    time continues into the next day.
                                </p>

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
                                            serviceContexts={serviceContexts}
                                            clients={clients}
                                            onChange={(patch) =>
                                                setRow(index, patch)
                                            }
                                            onRemove={() => removeRow(index)}
                                            onDuplicate={() =>
                                                duplicateRow(index)
                                            }
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
                                data={data}
                                clients={clients}
                                staff={staff}
                                serviceContexts={serviceContexts}
                                workerTimezone={workerTimezone}
                                onEdit={setStepIndex}
                            />
                        )}
                    </fieldset>
                </WizardStepPane>
            </WizardShell>
            <ConfirmDialog
                open={discardOpen}
                onClose={() => setDiscardOpen(false)}
                onConfirm={() => onOpenChange(false)}
                title="Discard template changes?"
                description={
                    uncertain
                        ? 'The save result is unknown. Closing removes this draft from the screen. Check the template list before creating or saving it again.'
                        : 'Your unsaved template changes will be lost. Saved templates and roster shifts will stay as they are.'
                }
                confirmText="Discard changes"
                cancelText="Keep editing"
            />
        </>
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
    serviceContexts,
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
    serviceContexts: TemplateServiceContextOption[];
    clients: TemplateClientOption[];
    onChange: (patch: Partial<WizardShiftRow>) => void;
    onRemove: () => void;
    onDuplicate: () => void;
}) {
    const overnight = row.end_time <= row.start_time;
    const contextOptions = [
        { value: NONE, label: 'No service context' },
        ...templateContextsForClient(
            row.client_id,
            clients,
            serviceContexts,
        ).map((context) => ({
            value: String(context.id),
            label:
                context.is_active === false
                    ? `${context.name} (inactive)`
                    : context.name,
        })),
    ];
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
                    <TimePicker
                        id={`template-row-${index}-start`}
                        label={`Shift row ${index + 1} start time`}
                        value={row.start_time}
                        onChange={(value) => onChange({ start_time: value })}
                    />
                </Field>
                <Field label="End">
                    <TimePicker
                        id={`template-row-${index}-end`}
                        label={`Shift row ${index + 1} end time`}
                        value={row.end_time}
                        onChange={(value) => onChange({ end_time: value })}
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
                <Field label="Client" required>
                    <RecordPicker
                        label={`Shift row ${index + 1} client`}
                        value={row.client_id}
                        onChange={(v) => {
                            const clientId = v;
                            const patch: Partial<WizardShiftRow> = {
                                client_id: clientId,
                                service_context_id: reconcileTemplateContextId(
                                    clientId,
                                    row.service_context_id,
                                    clients,
                                    serviceContexts,
                                ),
                            };
                            onChange(patch);
                        }}
                        options={clientOptions}
                    />
                </Field>
                <Field label="Assigned staff">
                    <RecordPicker
                        label={`Shift row ${index + 1} staff member`}
                        value={row.user_id || NONE}
                        onChange={(v) =>
                            onChange({ user_id: v === NONE ? '' : v })
                        }
                        options={staffOptions}
                    />
                </Field>
                <Field label="Service context" span>
                    <RecordPicker
                        label={`Shift row ${index + 1} service context`}
                        value={row.service_context_id || NONE}
                        onChange={(v) =>
                            onChange({
                                service_context_id: v === NONE ? '' : v,
                            })
                        }
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
    data,
    clients,
    staff,
    serviceContexts,
    workerTimezone,
    onEdit,
}: {
    data: WizardForm;
    clients: TemplateClientOption[];
    staff: TemplateStaffOption[];
    serviceContexts: TemplateServiceContextOption[];
    workerTimezone: string;
    onEdit: (step: number) => void;
}) {
    const shifts = data.template_shifts;
    return (
        <div className="space-y-4">
            <StepHeading
                icon={ClipboardCheck}
                title="Review template"
                blurb="Check the people, exact times and support details. Saving does not create roster shifts."
            />
            <ReviewCard
                icon={LayoutTemplate}
                title="Template details"
                onEdit={() => onEdit(0)}
            >
                <ReviewRow label="Name" value={data.name} />
                <ReviewRow label="Description" value={data.description} />
                <ReviewRow
                    label="Cadence"
                    value={
                        data.template_type === 'monthly'
                            ? 'Every four weeks (monthly pattern)'
                            : data.template_type === 'fortnightly'
                              ? 'Every two weeks'
                              : 'Every week'
                    }
                />
                <ReviewRow
                    label="Status"
                    value={data.is_active ? 'Active' : 'Inactive'}
                />
                <ReviewRow label="Time zone" value={workerTimezone} />
            </ReviewCard>
            <div
                className="grid grid-cols-7 gap-1"
                aria-label="Shift rows by weekday"
            >
                {DAY_SHORT.map((day, index) => (
                    <div
                        key={day}
                        className="text-caption rounded-md border border-border bg-muted/40 p-2 text-center"
                    >
                        <span className="block">{day}</span>
                        <strong>
                            {
                                shifts.filter(
                                    (row) => Number(row.day_of_week) === index,
                                ).length
                            }
                        </strong>
                    </div>
                ))}
            </div>
            {shifts.map((row, index) => {
                const person = clients.find(
                    (client) => String(client.id) === row.client_id,
                );
                return (
                    <ReviewCard
                        key={index}
                        icon={Clock}
                        title={`Shift row ${index + 1} · ${DAY_LABELS[Number(row.day_of_week)]}`}
                        onEdit={() => onEdit(1)}
                    >
                        <ReviewRow
                            label="Client"
                            value={
                                person ? clientLabel(person) : 'Select a client'
                            }
                        />
                        <ReviewRow
                            label="Staff"
                            value={
                                staff.find(
                                    (worker) =>
                                        String(worker.id) === row.user_id,
                                )?.name ?? 'Unassigned / open'
                            }
                        />
                        <ReviewRow
                            label="Service context"
                            value={
                                serviceContexts.find(
                                    (context) =>
                                        String(context.id) ===
                                        row.service_context_id,
                                )?.name ?? 'None selected'
                            }
                        />
                        <ReviewRow
                            label="Times"
                            value={`${row.start_time}–${row.end_time}${row.end_time < row.start_time ? ' (ends next day)' : ''}`}
                        />
                        <ReviewRow
                            label="Shift type"
                            value={
                                SHIFT_TYPE_OPTIONS.find(
                                    (type) => type.value === row.shift_type,
                                )?.label ?? row.shift_type
                            }
                        />
                        <ReviewRow
                            label="Sleepover / on-call / lone worker"
                            value={
                                [
                                    (row.is_sleepover ||
                                        row.shift_type === 'sleepover') &&
                                        'Sleepover',
                                    (row.is_on_call ||
                                        row.shift_type === 'on_call') &&
                                        'On-call',
                                    row.is_lone_worker && 'Lone worker',
                                ]
                                    .filter(Boolean)
                                    .join(', ') || 'None selected'
                            }
                        />
                        <ReviewRow
                            label="Expected break"
                            value={
                                row.expected_break_minutes
                                    ? `${row.expected_break_minutes} minutes`
                                    : 'Not specified'
                            }
                        />
                        <ReviewRow
                            label="Required skills"
                            value={row.required_skills}
                        />
                        <ReviewRow label="Location" value={row.location} />
                        <ReviewRow label="Notes" value={row.notes} />
                    </ReviewCard>
                );
            })}
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
    canApply: boolean;
    actionsBlocked: boolean;
    onEdit: (template: RosterTemplateRow) => void;
    onDelete: (template: RosterTemplateRow) => void;
    onCloseAutoFocus?: (event: Event) => void;
};

export function TemplateDetailDialog({
    template,
    open,
    onOpenChange,
    canManage,
    canDelete,
    canApply,
    actionsBlocked,
    onEdit,
    onDelete,
    onCloseAutoFocus,
}: TemplateDetailDialogProps) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent
                showCloseButton={false}
                className="frontline-dialog max-h-[92vh] min-w-0 grid-cols-1 overflow-hidden p-0"
                onCloseAutoFocus={onCloseAutoFocus}
                style={{
                    maxWidth: 'min(94vw, 940px)',
                    width: 'min(94vw, 940px)',
                }}
            >
                <DialogTitle className="sr-only">
                    {template?.name ?? 'Roster template'}
                </DialogTitle>
                <DialogDescription className="sr-only">
                    {canApply
                        ? 'Review the template rows and apply the pattern to a week.'
                        : 'Review the saved template rows and support details.'}
                </DialogDescription>
                {open && template ? (
                    <DetailBody
                        template={template}
                        onOpenChange={onOpenChange}
                        canManage={canManage}
                        canDelete={canDelete}
                        canApply={canApply}
                        actionsBlocked={actionsBlocked}
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
    canApply,
    actionsBlocked,
    onEdit,
    onDelete,
}: {
    template: RosterTemplateRow;
    onOpenChange: (open: boolean) => void;
    canManage: boolean;
    canDelete: boolean;
    canApply: boolean;
    actionsBlocked: boolean;
    onEdit: (template: RosterTemplateRow) => void;
    onDelete: (template: RosterTemplateRow) => void;
}) {
    const applyForm = useForm({
        week_start: nextMonday(),
        cycles: 1,
        confirm_warnings: false,
    });

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
            templateApplyBlockLines({
                template_shifts: errors.template_shifts,
                preflight_blocks: errors.preflight_blocks,
            }),
        [errors.preflight_blocks, errors.template_shifts],
    );

    useEffect(() => {
        if (warningLines.length > 0) setWarningOpen(true);
    }, [warningLines.length]);

    const postApply = (confirmWarnings: boolean) => {
        if (!canApply || actionsBlocked || applyForm.processing) return;
        applyForm.transform((d) => ({
            ...d,
            confirm_warnings: confirmWarnings,
        }));
        applyForm.post(`/operations/rostering/templates/${template.id}/apply`, {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
            onFinish: () => applyForm.transform((d) => d),
        });
    };

    return (
        <div className="flex max-h-[92vh] min-h-0 min-w-0 flex-col">
            <header className="relative min-w-0 shrink-0 border-b border-border px-5 py-4">
                <div className="min-w-0 pr-[52px] [overflow-wrap:anywhere]">
                    <h2 className="text-lg font-bold tracking-tight">
                        {template.name}
                    </h2>
                    <div className="mt-1 flex flex-wrap items-center gap-1.5 text-[11px]">
                        <span className="rounded-full bg-primary/10 px-2 py-0.5 font-semibold text-primary capitalize">
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
                {canManage || canDelete ? (
                    <div className="mt-3 flex flex-wrap items-center gap-2">
                        {canManage ? (
                            <Button
                                variant="outline"
                                size="sm"
                                disabled={
                                    actionsBlocked || applyForm.processing
                                }
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
                                disabled={
                                    actionsBlocked || applyForm.processing
                                }
                                onClick={() => onDelete(template)}
                            >
                                <Trash2 className="h-3.5 w-3.5" /> Delete
                            </Button>
                        ) : null}
                    </div>
                ) : null}
                <button
                    type="button"
                    onClick={() => onOpenChange(false)}
                    aria-label="Close"
                    className="absolute top-3 right-3 grid h-[44px] w-[44px] place-items-center rounded-md text-muted-foreground hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring"
                >
                    <X className="h-5 w-5" />
                </button>
            </header>

            <div
                className={cn(
                    'grid min-h-0 min-w-0 flex-1 gap-0 overflow-hidden',
                    canApply && 'lg:grid-cols-[minmax(0,1fr)_320px]',
                )}
            >
                {/* Rows */}
                <div className="min-h-0 min-w-0 overflow-y-auto px-5 py-4 [overflow-wrap:anywhere]">
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
                {canApply ? (
                    <div
                        className="min-h-0 min-w-0 overflow-y-auto border-t border-border bg-muted/20 px-5 py-4 lg:border-t-0 lg:border-l"
                        data-test="template-apply-card"
                    >
                        <div className="flex items-center gap-2 text-sm font-bold">
                            <CalendarPlus className="h-4 w-4 text-primary" />
                            Apply to a week
                        </div>
                        <p className="mt-1 text-[13px] text-muted-foreground">
                            Creates draft shifts for the chosen week (snapped to
                            its Monday). Apply more than one cycle to stamp
                            several weeks.
                        </p>

                        {blockLines.length > 0 ? (
                            <Alert
                                variant="destructive"
                                className="mt-3"
                                data-test="template-apply-blocks"
                            >
                                <AlertTriangle className="h-4 w-4" />
                                <AlertTitle>
                                    Template cannot be applied
                                </AlertTitle>
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
                                    draft shift{totalShifts === 1 ? '' : 's'}{' '}
                                    across{' '}
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
                                disabled={
                                    applyForm.processing || actionsBlocked
                                }
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
                ) : null}
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
                            disabled={applyForm.processing || actionsBlocked}
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
        </div>
    );
}

function DetailRow({ shift }: { shift: RosterTemplateShiftRow }) {
    const overnight = shift.end_time <= shift.start_time;
    const dayLabel =
        DAY_LABELS[shift.day_of_week] ?? `Day ${shift.day_of_week}`;
    return (
        <div className="min-w-0 rounded-lg border border-border p-3 [overflow-wrap:anywhere]">
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
                    <span className="rounded-full bg-muted px-2 py-0.5 font-semibold text-muted-foreground capitalize">
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
            <div className="mt-2 grid min-w-0 grid-cols-1 gap-x-4 gap-y-1 text-[13px] text-muted-foreground sm:grid-cols-2">
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
