import { ConfirmDialog } from '@/components/confirm-dialog';
import { DatePicker } from '@/components/fleet-assets/maintenance/date-picker';
import { TimePicker } from '@/components/fleet-assets/maintenance/time-picker';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Command,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import { Input } from '@/components/ui/input';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { Textarea } from '@/components/ui/textarea';
import { Field, InfoCard } from '@/components/wizard/primitives';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
    WizardSuccessPane,
} from '@/components/wizard/shell';
import {
    formatDateOnly,
    formatDateTimeInZone,
    WORKER_TIMEZONE,
} from '@/lib/datetime';
import { shiftWallInput } from '@/lib/workforce-time-input';
import type { SharedData } from '@/types';
import { usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowLeftRight,
    Briefcase,
    CalendarDays,
    Car,
    Check,
    CheckCircle2,
    ChevronDown,
    Clock,
    ExternalLink,
    FileText,
    GraduationCap,
    ListChecks,
    Loader2,
    Phone,
    Plus,
    UserCheck,
    Users,
    X,
} from 'lucide-react';
import { useEffect, useLayoutEffect, useRef, useState } from 'react';
import { timesheetTimeRange } from './timesheet-time';
import {
    timesheetEditProjection,
    timesheetInstant,
    timesheetMileage,
    timesheetText,
    useTimesheetCommand,
    type TimesheetEditableValues,
} from './use-timesheet-command';
import type { ViewTimesheetRow } from './view-timesheet-dialog';

export type ShiftOption = {
    id: number;
    client: { id: number; first_name: string; last_name: string } | null;
    starts_at: string;
    ends_at: string;
    location: string | null;
    shift_type: string | null;
    status: string;
    service_context: string | null;
    expected_break_minutes: number;
    is_sleepover: boolean;
    is_on_call: boolean;
    client_id: number | null;
    tasks: Array<{
        id: number;
        label: string;
        completed: boolean;
        time?: string | null;
        minutes: number;
    }>;
};
export type ClientOption = {
    id: number;
    first_name: string;
    last_name: string;
};
export type SiteOption = { id: number; name: string };
export type EditTimesheetRow = ViewTimesheetRow & {
    client_id?: number | null;
    allowance_notes?: string | null;
    is_residential_billable?: boolean;
};
export type TimesheetWizardProps = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    shifts?: ShiftOption[];
    clients: ClientOption[];
    sites?: SiteOption[];
    initialShiftId?: number | null;
    record?: EditTimesheetRow;
    canSave: boolean;
    canSubmit?: boolean;
    workerTimezone?: string;
};
/** Record identity stays separate from editable person choices in the draft. */
export function timesheetEditContext(record?: EditTimesheetRow | null) {
    return JSON.stringify(
        record
            ? [
                  record.id,
                  record.user_id ?? record.staff?.id ?? null,
                  record.shift_id !== undefined
                      ? record.shift_id
                      : (record.shift?.id ?? null),
                  record.client_id !== undefined
                      ? record.client_id
                      : (record.client?.id ?? null),
                  record.site?.id ?? null,
              ]
            : null,
    );
}
const activities = [
    {
        key: 'training',
        label: 'Training',
        description: 'Required training or professional development',
        icon: GraduationCap,
    },
    {
        key: 'meeting',
        label: 'Team meeting',
        description: 'Internal or external meeting',
        icon: Users,
    },
    {
        key: 'admin',
        label: 'Admin / paperwork',
        description: 'Notes, reports and planning',
        icon: FileText,
    },
    {
        key: 'travel',
        label: 'Travel time',
        description: 'Travel between duties or sites',
        icon: Car,
    },
    {
        key: 'handover',
        label: 'Handover',
        description: 'Sharing responsibility between shifts',
        icon: ArrowLeftRight,
    },
    {
        key: 'supervision',
        label: 'Supervision',
        description: 'Individual or group supervision',
        icon: UserCheck,
    },
    {
        key: 'standby',
        label: 'Standby / on-call',
        description: 'Available but not active',
        icon: Phone,
    },
    {
        key: 'other',
        label: 'Other',
        description: 'Other recorded work',
        icon: Briefcase,
    },
];
const steps = [
    {
        key: 'source',
        label: 'Work',
        blurb: 'Shift, activity and person',
        icon: CalendarDays,
    },
    {
        key: 'details',
        label: 'Hours and details',
        blurb: 'Times, breaks and notes',
        icon: Clock,
    },
    {
        key: 'review',
        label: 'Review',
        blurb: 'Check and save',
        icon: CheckCircle2,
    },
];
export function TimesheetPicker({
    id,
    label,
    value,
    options,
    onChange,
}: {
    id: string;
    label: string;
    value: string;
    options: Array<{ id: number; label: string }>;
    onChange: (value: string) => void;
}) {
    const [open, setOpen] = useState(false);
    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <Button
                    id={id}
                    variant="outline"
                    role="combobox"
                    aria-label={label}
                    aria-expanded={open}
                    className="min-h-11 w-full justify-between text-left whitespace-normal"
                >
                    {options.find((option) => String(option.id) === value)
                        ?.label ??
                        (value
                            ? 'Selected record unavailable'
                            : 'None selected')}
                    <ChevronDown className="ml-2 size-4 shrink-0" />
                </Button>
            </PopoverTrigger>
            <PopoverContent className="w-[min(80vw,480px)] p-0" align="start">
                <Command>
                    <CommandInput
                        aria-label={`Search ${label.toLowerCase()}`}
                        placeholder={`Search ${label.toLowerCase()}…`}
                    />
                    <CommandList>
                        <CommandEmpty>No matching choices.</CommandEmpty>
                        <CommandGroup>
                            <CommandItem
                                value="none"
                                onSelect={() => {
                                    onChange('');
                                    setOpen(false);
                                }}
                                className="min-h-11"
                            >
                                None selected
                            </CommandItem>
                            {options.map((option) => (
                                <CommandItem
                                    key={option.id}
                                    value={`${option.id} ${option.label}`}
                                    onSelect={() => {
                                        onChange(String(option.id));
                                        setOpen(false);
                                    }}
                                    className="min-h-11 whitespace-normal"
                                >
                                    <Check
                                        className={
                                            value === String(option.id)
                                                ? 'size-4'
                                                : 'size-4 opacity-0'
                                        }
                                    />
                                    {option.label}
                                </CommandItem>
                            ))}
                        </CommandGroup>
                    </CommandList>
                </Command>
            </PopoverContent>
        </Popover>
    );
}
function seed(props: TimesheetWizardProps, zone: string) {
    const record = props.record;
    const shift =
        props.shifts?.find((option) => option.id === props.initialShiftId) ??
        null;
    const starts = shiftWallInput(record?.starts_at ?? shift?.starts_at, zone);
    const ends = shiftWallInput(record?.ends_at ?? shift?.ends_at, zone);
    const today = shiftWallInput(new Date().toISOString(), zone).slice(0, 10);
    return {
        mode: record
            ? record.shift
                ? ('shift' as const)
                : ('manual' as const)
            : ('shift' as 'shift' | 'manual'),
        shift,
        activity: record?.activity_type ?? '',
        client: String(record?.client_id ?? record?.client?.id ?? ''),
        site: String(record?.site?.id ?? ''),
        workDate:
            record?.work_date.slice(0, 10) ?? (starts.slice(0, 10) || today),
        startDate: starts.slice(0, 10) || today,
        endDate: ends.slice(0, 10) || today,
        startTime: starts.slice(11, 16),
        endTime: ends.slice(11, 16),
        breakMinutes: String(
            record?.break_minutes ?? shift?.expected_break_minutes ?? 0,
        ),
        mileage: String(record?.mileage_km ?? 0),
        sleepover: !!(record?.sleepover ?? shift?.is_sleepover),
        onCall: !!(record?.on_call ?? shift?.is_on_call),
        publicHoliday: !!record?.public_holiday,
        allowanceNotes: record?.allowance_notes ?? '',
        notes: record?.notes ?? '',
        residential: !!record?.is_residential_billable,
        activityItems: record?.activity_items ?? [],
        tasks: (shift?.tasks ?? []).map((task) => ({
            ...task,
            included: true,
        })),
    };
}
/** One shared form for add and edit; a mounted draft is never reseeded by fresh list props. */
export function TimesheetWizard(props: TimesheetWizardProps) {
    if (!props.open) return null;
    return (
        <TimesheetWizardBody
            key={props.record ? `edit-${props.record.id}` : 'create'}
            {...props}
        />
    );
}
function TimesheetWizardBody(props: TimesheetWizardProps) {
    const {
        record,
        onOpenChange,
        clients,
        shifts = [],
        sites = [],
        canSave,
        canSubmit = false,
    } = props;
    const currentActorId = Number(
        usePage<SharedData>().props.auth.user?.id ?? 0,
    );
    const [actorId] = useState(currentActorId);
    const [initialContext] = useState(() => timesheetEditContext(record));
    const [zone] = useState(props.workerTimezone ?? WORKER_TIMEZONE);
    const [initial] = useState(() => seed(props, zone));
    const [draft, setDraft] = useState(initial);
    const [step, setStep] = useState(0),
        [search, setSearch] = useState(''),
        [newItem, setNewItem] = useState('');
    const [discard, setDiscard] = useState(false),
        [submitConfirm, setSubmitConfirm] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const feedback = useRef<HTMLDivElement>(null);
    const body = useRef<HTMLDivElement>(null);
    useLayoutEffect(() => {
        if (body.current) body.current.scrollTop = 0;
    }, [step]);
    const command = useTimesheetCommand(
        `${currentActorId}:${record?.id ?? 'create'}`,
    );
    const linked = record ? !!record.shift : draft.mode === 'shift';
    const shiftId = record?.shift?.id ?? draft.shift?.id ?? null;
    const allowed =
        canSave &&
        actorId > 0 &&
        actorId === currentActorId &&
        initialContext === timesheetEditContext(record);
    const locked = !allowed || command.pending || command.held.current;
    const dirty =
        JSON.stringify(initial) !== JSON.stringify(draft) || newItem !== '';
    const confirmed =
        command.outcome?.status === 'confirmed'
            ? command.outcome.receipt
            : null;
    const sourceReady = record
        ? true
        : linked
          ? !!draft.shift
          : !!draft.activity;
    const change = <K extends keyof typeof draft>(
        key: K,
        value: (typeof draft)[K],
    ) => {
        if (!locked) setDraft((current) => ({ ...current, [key]: value }));
    };
    useEffect(() => {
        if (!dirty || confirmed) return;
        const warn = (event: BeforeUnloadEvent) => {
            event.preventDefault();
            event.returnValue = '';
        };
        window.addEventListener('beforeunload', warn);
        return () => window.removeEventListener('beforeunload', warn);
    }, [dirty, confirmed]);
    useEffect(() => {
        if (command.outcome && command.outcome.status !== 'confirmed')
            feedback.current?.focus();
    }, [command.outcome]);
    const close = () => {
        if (command.busy.current) return;
        if (!confirmed && (dirty || command.outcome?.status === 'unknown'))
            setDiscard(true);
        else onOpenChange(false);
    };
    function chooseShift(shift: ShiftOption) {
        if (locked) return;
        const start = shiftWallInput(shift.starts_at, zone),
            end = shiftWallInput(shift.ends_at, zone);
        setDraft((current) => ({
            ...current,
            shift,
            workDate: start.slice(0, 10),
            startDate: start.slice(0, 10),
            endDate: end.slice(0, 10),
            startTime: start.slice(11, 16),
            endTime: end.slice(11, 16),
            breakMinutes: String(shift.expected_break_minutes),
            sleepover: shift.is_sleepover,
            onCall: shift.is_on_call,
            tasks: shift.tasks.map((task) => ({ ...task, included: true })),
        }));
    }
    const range = () =>
        timesheetTimeRange(
            `${draft.startDate}T${draft.startTime}`,
            `${draft.endDate}T${draft.endTime}`,
            record ?? draft.shift ?? undefined,
            zone,
        );
    let hours: string | null = null;
    try {
        hours = Math.max(
            0,
            (range().minutes - Number(draft.breakMinutes)) / 60,
        ).toFixed(2);
    } catch {
        /* Incomplete clocks remain editable. */
    }
    let mileageLabel = 'Check mileage';
    try {
        mileageLabel = `${timesheetMileage(draft.mileage)} km`;
    } catch {
        /* Invalid mileage stays editable until validation. */
    }
    const validate = (all = false) => {
        const next: Record<string, string> = {};
        if (!sourceReady) next.source = 'Choose a shift or activity.';
        if (all) {
            if (!/^\d{4}-\d{2}-\d{2}$/.test(draft.workDate))
                next.work_date = 'Choose the work date.';
            try {
                range();
                if (
                    !/^\d+$/.test(draft.breakMinutes) ||
                    Number(draft.breakMinutes) > 240
                )
                    next.break_minutes =
                        'Enter a whole-minute break from 0 to 240.';
            } catch (error) {
                next.starts_at =
                    error instanceof Error
                        ? error.message
                        : 'Check the dates and times.';
            }
            try {
                timesheetMileage(draft.mileage);
            } catch (error) {
                next.mileage_km = String((error as Error).message);
            }
        }
        setErrors(next);
        if (Object.keys(next).length) {
            setStep(next.source ? 0 : 1);
            return false;
        }
        return true;
    };
    function save(andSubmit: boolean) {
        if (
            locked ||
            command.busy.current ||
            (andSubmit && !canSubmit) ||
            !validate(true)
        )
            return;
        setSubmitConfirm(false);
        const interval = range();
        const action = record
            ? andSubmit
                ? ('resubmit' as const)
                : ('update' as const)
            : ('create' as const);
        const values: TimesheetEditableValues = {
            work_date: draft.workDate,
            starts_at: timesheetInstant(interval.starts_at),
            ends_at: timesheetInstant(interval.ends_at),
            break_minutes: Number(draft.breakMinutes),
            mileage_km: timesheetMileage(draft.mileage),
            allowance_notes: timesheetText(draft.allowanceNotes),
            public_holiday: draft.publicHoliday,
            notes: timesheetText(draft.notes),
            is_residential_billable: draft.residential,
            client_id: linked
                ? (record?.client_id ??
                  record?.client?.id ??
                  draft.shift?.client_id ??
                  null)
                : Number(draft.client) || null,
            site_id: Number(draft.site) || null,
            activity_type: draft.activity || null,
            sleepover: draft.sleepover,
            on_call: draft.onCall,
            activity_items: draft.activityItems.map(
                (item) => timesheetText(item) ?? '',
            ),
        };
        const payload = record
            ? {
                  client_id: values.client_id,
                  work_date: values.work_date,
                  starts_at: values.starts_at,
                  ends_at: values.ends_at,
                  break_minutes: values.break_minutes,
                  mileage_km: values.mileage_km,
                  sleepover: values.sleepover,
                  on_call: values.on_call,
                  allowance_notes: values.allowance_notes,
                  public_holiday: values.public_holiday,
                  notes: values.notes,
                  is_residential_billable: values.is_residential_billable,
              }
            : {
                  ...values,
                  mode: draft.mode,
                  shift_id: linked ? shiftId : null,
                  site_id: linked ? null : values.site_id,
                  activity_type: linked ? null : values.activity_type,
                  activity_items: linked ? [] : values.activity_items,
                  submit: andSubmit,
                  tasks: linked
                      ? draft.tasks.map(({ id, included, completed }) => ({
                            id,
                            included,
                            completed,
                        }))
                      : [],
              };
        void command.submit(
            action === 'update' ? 'put' : 'post',
            record
                ? `/operations/timesheets/${record.id}${andSubmit ? '/resubmit' : ''}`
                : '/operations/timesheets',
            payload,
            {
                action,
                actorId,
                timesheetId: record?.id,
                ownerId: record?.staff?.id ?? (!linked ? actorId : undefined),
                clientId: values.client_id,
                sourceFlags: linked
                    ? { sleepover: draft.sleepover, on_call: draft.onCall }
                    : undefined,
                shiftId: linked ? shiftId : null,
                status: andSubmit ? 'submitted' : (record?.status ?? 'draft'),
                submitRequested: record ? undefined : andSubmit,
                values: timesheetEditProjection(action, values, linked),
            },
        );
    }
    const recoveryUrl = record
        ? `/operations/timesheets?view=${record.id}`
        : '/operations/timesheets?tab=all';
    const personOptions = clients.map((client) => ({
        id: client.id,
        label: `${client.first_name} ${client.last_name}`,
    }));
    if (
        record?.client &&
        !personOptions.some((option) => option.id === record.client!.id)
    )
        personOptions.push({
            id: record.client.id,
            label: `${record.client.first_name} ${record.client.last_name}`,
        });
    const person = linked
        ? (record?.client ?? draft.shift?.client)
        : clients.find((client) => String(client.id) === draft.client);
    const activityLabel =
        activities.find((activity) => activity.key === draft.activity)?.label ??
        draft.activity;
    const sourceLabel = linked
        ? `Shift #${shiftId ?? '—'}`
        : activityLabel || 'Manual work';
    const fieldErrors = {
        ...errors,
        ...(command.outcome?.status === 'rejected'
            ? command.outcome.errors
            : {}),
    };
    return (
        <>
            <WizardShell
                open
                onClose={close}
                title={
                    record ? `Edit timesheet #${record.id}` : 'Create timesheet'
                }
                description="Choose the work, record its dates and hours, then review before saving."
                railIcon={FileText}
                railTitle={record ? 'Edit timesheet' : 'Create timesheet'}
                railSub={zone}
                steps={steps}
                stepIndex={step}
                bodyRef={body}
                onStepClick={(next) => !command.pending && setStep(next)}
                pct={sourceReady ? (hours === null ? 40 : 100) : 0}
                pctLabel="Required details"
                frontline
                footerStart={
                    <Button
                        variant="outline"
                        onClick={close}
                        disabled={command.pending}
                    >
                        {confirmed ? 'Done' : 'Cancel'}
                    </Button>
                }
                footerEnd={
                    <>
                        {step > 0 && (
                            <Button
                                variant="ghost"
                                disabled={command.pending}
                                onClick={() => setStep(step - 1)}
                            >
                                Back
                            </Button>
                        )}
                        {step < 2 ? (
                            <Button
                                disabled={command.pending}
                                onClick={() => {
                                    if (validate(step === 1)) setStep(step + 1);
                                }}
                            >
                                {step === 1
                                    ? 'Review timesheet'
                                    : 'Continue to hours'}
                            </Button>
                        ) : (
                            <>
                                <Button
                                    variant={canSubmit ? 'outline' : 'default'}
                                    disabled={locked}
                                    onClick={() => save(false)}
                                    data-test="timesheet-save"
                                >
                                    {command.pending && (
                                        <Loader2 className="size-4 animate-spin" />
                                    )}
                                    {record ? 'Save changes' : 'Save as draft'}
                                </Button>
                                {canSubmit && (
                                    <Button
                                        disabled={locked}
                                        onClick={() => {
                                            if (validate(true))
                                                setSubmitConfirm(true);
                                        }}
                                        data-test="timesheet-submit"
                                    >
                                        Save and submit
                                    </Button>
                                )}
                            </>
                        )}
                    </>
                }
                success={
                    confirmed ? (
                        <WizardSuccessPane
                            title={
                                confirmed.status === 'submitted'
                                    ? 'Timesheet submitted'
                                    : 'Timesheet saved'
                            }
                            blurb={`Timesheet #${confirmed.timesheet_id} is confirmed ${confirmed.status === 'submitted' ? 'saved and submitted for approval' : 'saved'}. ${linked && !record ? 'Shift task progress has not been changed by this form.' : ''}`}
                            actions={
                                <>
                                    <Button onClick={() => onOpenChange(false)}>
                                        Done
                                    </Button>
                                    <Button variant="outline" asChild>
                                        <a
                                            href={`/operations/timesheets?view=${confirmed.timesheet_id}`}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                        >
                                            View saved timesheet
                                            <ExternalLink className="size-4" />
                                        </a>
                                    </Button>
                                </>
                            }
                        />
                    ) : undefined
                }
            >
                {!allowed && (
                    <InfoCard icon={AlertTriangle} tone="warn">
                        Saving is unavailable in the current view. Your entries
                        are retained. Close and refresh to check this record and
                        your access.
                    </InfoCard>
                )}
                {record?.returned_notes && (
                    <InfoCard icon={AlertTriangle} tone="warn">
                        <strong>Changes requested</strong>
                        <p className="mt-1 whitespace-pre-wrap">
                            {record.returned_notes}
                        </p>
                    </InfoCard>
                )}
                {command.outcome && command.outcome.status !== 'confirmed' && (
                    <div
                        ref={feedback}
                        tabIndex={-1}
                        role="alert"
                        className="mb-4 outline-none"
                    >
                        <InfoCard
                            icon={AlertTriangle}
                            tone={
                                command.outcome.status === 'unknown'
                                    ? 'warn'
                                    : 'crit'
                            }
                        >
                            <p>{command.outcome.message}</p>
                            {command.outcome.status === 'unknown' && (
                                <>
                                    <p className="mt-2">
                                        {record
                                            ? `Look for timesheet #${record.id}.`
                                            : 'Review all records and clear filters if needed.'}{' '}
                                        A missing or inaccessible record does
                                        not prove that saving failed.
                                    </p>
                                    <Button
                                        asChild
                                        variant="outline"
                                        className="mt-3 min-h-11"
                                    >
                                        <a
                                            href={recoveryUrl}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                        >
                                            Check current timesheets in a new
                                            tab
                                            <ExternalLink className="size-4" />
                                        </a>
                                    </Button>
                                </>
                            )}
                        </InfoCard>
                    </div>
                )}
                {Object.keys(fieldErrors).length > 0 && (
                    <ul
                        role="alert"
                        className="mb-4 list-disc space-y-1 pl-5 text-status-critical"
                    >
                        {Object.entries(fieldErrors).map(([key, message]) => (
                            <li key={key}>{message}</li>
                        ))}
                    </ul>
                )}
                <WizardStepPane>
                    <fieldset disabled={locked} className="min-w-0 space-y-5">
                        <legend className="sr-only">{steps[step].label}</legend>
                        {step === 0 ? (
                            <>
                                {record ? (
                                    <InfoCard icon={CalendarDays}>
                                        <strong>{sourceLabel}</strong>
                                        <p>
                                            This edits the existing work record.
                                            Its source and activity stay the
                                            same.
                                        </p>
                                    </InfoCard>
                                ) : (
                                    <>
                                        <div className="flex flex-wrap gap-2">
                                            <Button
                                                variant={
                                                    linked
                                                        ? 'default'
                                                        : 'outline'
                                                }
                                                aria-pressed={linked}
                                                onClick={() =>
                                                    change('mode', 'shift')
                                                }
                                            >
                                                From a shift
                                            </Button>
                                            <Button
                                                variant={
                                                    !linked
                                                        ? 'default'
                                                        : 'outline'
                                                }
                                                aria-pressed={!linked}
                                                onClick={() =>
                                                    change('mode', 'manual')
                                                }
                                            >
                                                Manual activity
                                            </Button>
                                        </div>
                                        {linked ? (
                                            <div className="space-y-3">
                                                <Field
                                                    label="Find a shift"
                                                    htmlFor="timesheet-shift-search"
                                                >
                                                    <Input
                                                        id="timesheet-shift-search"
                                                        value={search}
                                                        onChange={(event) =>
                                                            setSearch(
                                                                event.target
                                                                    .value,
                                                            )
                                                        }
                                                        placeholder="Person, location or shift number"
                                                    />
                                                </Field>
                                                <p className="text-caption text-muted-foreground">
                                                    Available suggestions may
                                                    not include every shift.
                                                    Check Shifts if the work is
                                                    missing.
                                                </p>
                                                <div className="grid gap-2 sm:grid-cols-2">
                                                    {shifts
                                                        .filter((shift) =>
                                                            `${shift.id} ${shift.client?.first_name ?? ''} ${shift.client?.last_name ?? ''} ${shift.location ?? ''}`
                                                                .toLowerCase()
                                                                .includes(
                                                                    search.toLowerCase(),
                                                                ),
                                                        )
                                                        .map((shift) => (
                                                            <Button
                                                                key={shift.id}
                                                                variant={
                                                                    draft.shift
                                                                        ?.id ===
                                                                    shift.id
                                                                        ? 'secondary'
                                                                        : 'outline'
                                                                }
                                                                aria-pressed={
                                                                    draft.shift
                                                                        ?.id ===
                                                                    shift.id
                                                                }
                                                                onClick={() =>
                                                                    chooseShift(
                                                                        shift,
                                                                    )
                                                                }
                                                                className="h-auto min-h-20 items-start justify-start p-3 text-left whitespace-normal"
                                                            >
                                                                <span>
                                                                    <strong className="block">
                                                                        {shift.client
                                                                            ? `${shift.client.first_name} ${shift.client.last_name}`
                                                                            : `Shift #${shift.id}`}
                                                                    </strong>
                                                                    <span className="text-caption block">
                                                                        {formatDateTimeInZone(
                                                                            shift.starts_at,
                                                                            zone,
                                                                        )}{' '}
                                                                        →{' '}
                                                                        {formatDateTimeInZone(
                                                                            shift.ends_at,
                                                                            zone,
                                                                        )}
                                                                    </span>
                                                                    <span className="text-caption block">
                                                                        {shift.location ??
                                                                            'No location label'}{' '}
                                                                        · #
                                                                        {
                                                                            shift.id
                                                                        }
                                                                    </span>
                                                                </span>
                                                            </Button>
                                                        ))}
                                                </div>
                                                {shifts.length === 0 && (
                                                    <p className="text-subtle">
                                                        No available shift
                                                        suggestions. You can
                                                        record a manual activity
                                                        where appropriate.
                                                    </p>
                                                )}
                                            </div>
                                        ) : (
                                            <div className="grid gap-3 sm:grid-cols-2">
                                                {activities.map(
                                                    ({
                                                        key,
                                                        label,
                                                        description,
                                                        icon: Icon,
                                                    }) => (
                                                        <Button
                                                            key={key}
                                                            variant={
                                                                draft.activity ===
                                                                key
                                                                    ? 'secondary'
                                                                    : 'outline'
                                                            }
                                                            aria-pressed={
                                                                draft.activity ===
                                                                key
                                                            }
                                                            onClick={() =>
                                                                change(
                                                                    'activity',
                                                                    key,
                                                                )
                                                            }
                                                            className="h-auto min-h-20 justify-start gap-3 p-3 text-left whitespace-normal"
                                                        >
                                                            <Icon className="size-5 shrink-0" />
                                                            <span>
                                                                <strong className="block">
                                                                    {label}
                                                                </strong>
                                                                <span className="text-caption block font-normal">
                                                                    {
                                                                        description
                                                                    }
                                                                </span>
                                                            </span>
                                                        </Button>
                                                    ),
                                                )}
                                            </div>
                                        )}
                                    </>
                                )}
                                {linked ? (
                                    <p className="text-subtle">
                                        {person
                                            ? `${person.first_name} ${person.last_name}`
                                            : 'No individual person assigned'}{' '}
                                        ·{' '}
                                        {record?.site?.name ??
                                            draft.shift?.location ??
                                            'Linked shift location'}
                                    </p>
                                ) : (
                                    <div className="grid gap-4 sm:grid-cols-2">
                                        <Field
                                            label="Person (optional)"
                                            htmlFor="timesheet-client"
                                        >
                                            <TimesheetPicker
                                                id="timesheet-client"
                                                label="Person"
                                                value={draft.client}
                                                options={personOptions}
                                                onChange={(value) =>
                                                    change('client', value)
                                                }
                                            />
                                        </Field>
                                        {!record && (
                                            <Field
                                                label="Site (optional)"
                                                htmlFor="timesheet-site"
                                            >
                                                <TimesheetPicker
                                                    id="timesheet-site"
                                                    label="Site"
                                                    value={draft.site}
                                                    options={sites.map(
                                                        (site) => ({
                                                            id: site.id,
                                                            label: site.name,
                                                        }),
                                                    )}
                                                    onChange={(value) =>
                                                        change('site', value)
                                                    }
                                                />
                                            </Field>
                                        )}
                                    </div>
                                )}
                            </>
                        ) : step === 1 ? (
                            <>
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <Field
                                        label="Work date"
                                        htmlFor="timesheet-work-date"
                                        error={fieldErrors.work_date}
                                    >
                                        <DatePicker
                                            id="timesheet-work-date"
                                            label="Work date"
                                            timeZone={zone}
                                            value={draft.workDate}
                                            onChange={(value) =>
                                                change('workDate', value)
                                            }
                                        />
                                    </Field>
                                    <p className="text-caption self-end text-muted-foreground">
                                        Dates and times use {zone}. Overnight
                                        work needs its own end date.
                                    </p>
                                    <Field
                                        label="Start date"
                                        htmlFor="timesheet-start-date"
                                        error={fieldErrors.starts_at}
                                    >
                                        <DatePicker
                                            id="timesheet-start-date"
                                            label="Start date"
                                            timeZone={zone}
                                            value={draft.startDate}
                                            onChange={(value) =>
                                                change('startDate', value)
                                            }
                                        />
                                    </Field>
                                    <Field
                                        label="Start time"
                                        htmlFor="timesheet-start-time"
                                        error={fieldErrors.starts_at}
                                    >
                                        <TimePicker
                                            id="timesheet-start-time"
                                            label="Start time"
                                            timezone={zone}
                                            value={draft.startTime}
                                            onChange={(value) =>
                                                change('startTime', value)
                                            }
                                        />
                                    </Field>
                                    <Field
                                        label="End date"
                                        htmlFor="timesheet-end-date"
                                        error={fieldErrors.ends_at}
                                    >
                                        <DatePicker
                                            id="timesheet-end-date"
                                            label="End date"
                                            timeZone={zone}
                                            value={draft.endDate}
                                            onChange={(value) =>
                                                change('endDate', value)
                                            }
                                        />
                                    </Field>
                                    <Field
                                        label="End time"
                                        htmlFor="timesheet-end-time"
                                        error={fieldErrors.ends_at}
                                    >
                                        <TimePicker
                                            id="timesheet-end-time"
                                            label="End time"
                                            timezone={zone}
                                            value={draft.endTime}
                                            onChange={(value) =>
                                                change('endTime', value)
                                            }
                                        />
                                    </Field>
                                    <Field
                                        label="Break (minutes)"
                                        htmlFor="timesheet-break"
                                        error={fieldErrors.break_minutes}
                                    >
                                        <Input
                                            id="timesheet-break"
                                            type="number"
                                            min={0}
                                            max={240}
                                            step={1}
                                            value={draft.breakMinutes}
                                            onChange={(event) =>
                                                change(
                                                    'breakMinutes',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                    </Field>
                                    <Field
                                        label="Mileage (km)"
                                        htmlFor="timesheet-mileage"
                                        error={fieldErrors.mileage_km}
                                    >
                                        <Input
                                            id="timesheet-mileage"
                                            type="number"
                                            min={0}
                                            step="0.01"
                                            value={draft.mileage}
                                            onChange={(event) =>
                                                change(
                                                    'mileage',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                    </Field>
                                </div>
                                <p className="text-sm font-medium">
                                    Recorded hours:{' '}
                                    {hours === null
                                        ? 'Choose valid dates and times'
                                        : `${hours}h after the break`}
                                </p>
                                <div className="grid gap-3 sm:grid-cols-2">
                                    {(
                                        [
                                            'sleepover',
                                            'onCall',
                                            'publicHoliday',
                                            'residential',
                                        ] as const
                                    ).map((key) => (
                                        <label
                                            key={key}
                                            className="flex min-h-11 items-center gap-3 rounded-lg border p-3 text-sm"
                                        >
                                            <Checkbox
                                                checked={draft[key]}
                                                disabled={
                                                    linked &&
                                                    (key === 'sleepover' ||
                                                        key === 'onCall')
                                                }
                                                onCheckedChange={(value) =>
                                                    change(key, value === true)
                                                }
                                            />
                                            {
                                                {
                                                    sleepover: 'Sleepover',
                                                    onCall: 'On-call',
                                                    publicHoliday:
                                                        'Public holiday',
                                                    residential:
                                                        'Residential billable',
                                                }[key]
                                            }
                                        </label>
                                    ))}
                                </div>
                                {linked && (
                                    <p className="text-caption text-muted-foreground">
                                        Linked sleepover and on-call values
                                        follow the shift when saved.
                                    </p>
                                )}
                                <Field
                                    label="Allowance notes"
                                    htmlFor="timesheet-allowance"
                                    error={fieldErrors.allowance_notes}
                                >
                                    <Input
                                        id="timesheet-allowance"
                                        value={draft.allowanceNotes}
                                        onChange={(event) =>
                                            change(
                                                'allowanceNotes',
                                                event.target.value,
                                            )
                                        }
                                    />
                                </Field>
                                <Field
                                    label="Notes"
                                    htmlFor="timesheet-notes"
                                    error={fieldErrors.notes}
                                >
                                    <Textarea
                                        id="timesheet-notes"
                                        rows={4}
                                        value={draft.notes}
                                        onChange={(event) =>
                                            change('notes', event.target.value)
                                        }
                                    />
                                </Field>
                                {!record &&
                                    linked &&
                                    draft.tasks.length > 0 && (
                                        <div className="space-y-3">
                                            <h3 className="text-section-title">
                                                Shift tasks
                                            </h3>
                                            <InfoCard icon={ListChecks}>
                                                These task choices are kept with
                                                your draft here. This form does
                                                not save task progress; record
                                                completed tasks on the shift.
                                            </InfoCard>
                                            {draft.tasks.map((task) => (
                                                <div
                                                    key={task.id}
                                                    className="flex flex-wrap items-center gap-3 rounded-lg border p-3"
                                                >
                                                    <label className="flex min-h-11 flex-1 items-center gap-2">
                                                        <Checkbox
                                                            checked={
                                                                task.included
                                                            }
                                                            onCheckedChange={(
                                                                value,
                                                            ) =>
                                                                change(
                                                                    'tasks',
                                                                    draft.tasks.map(
                                                                        (
                                                                            item,
                                                                        ) =>
                                                                            item.id ===
                                                                            task.id
                                                                                ? {
                                                                                      ...item,
                                                                                      included:
                                                                                          value ===
                                                                                          true,
                                                                                  }
                                                                                : item,
                                                                    ),
                                                                )
                                                            }
                                                        />
                                                        {task.label}
                                                    </label>
                                                    <label className="flex min-h-11 items-center gap-2">
                                                        <Checkbox
                                                            checked={
                                                                task.completed
                                                            }
                                                            onCheckedChange={(
                                                                value,
                                                            ) =>
                                                                change(
                                                                    'tasks',
                                                                    draft.tasks.map(
                                                                        (
                                                                            item,
                                                                        ) =>
                                                                            item.id ===
                                                                            task.id
                                                                                ? {
                                                                                      ...item,
                                                                                      completed:
                                                                                          value ===
                                                                                          true,
                                                                                  }
                                                                                : item,
                                                                    ),
                                                                )
                                                            }
                                                        />
                                                        Completed
                                                    </label>
                                                    <span className="text-caption">
                                                        {task.minutes} min
                                                    </span>
                                                </div>
                                            ))}
                                        </div>
                                    )}
                                {!linked && (
                                    <div className="space-y-3">
                                        <h3 className="text-section-title">
                                            Activity items
                                        </h3>
                                        {record ? (
                                            <ul className="list-disc pl-5">
                                                {draft.activityItems.map(
                                                    (item, index) => (
                                                        <li key={index}>
                                                            {item}
                                                        </li>
                                                    ),
                                                )}
                                                {draft.activityItems.length ===
                                                    0 && (
                                                    <li>
                                                        No recorded activity
                                                        items
                                                    </li>
                                                )}
                                            </ul>
                                        ) : (
                                            <>
                                                <ul className="space-y-2">
                                                    {draft.activityItems.map(
                                                        (item, index) => (
                                                            <li
                                                                key={index}
                                                                className="flex items-center gap-2"
                                                            >
                                                                <span className="min-w-0 flex-1 break-words">
                                                                    {item}
                                                                </span>
                                                                <Button
                                                                    variant="ghost"
                                                                    size="icon"
                                                                    aria-label={`Remove activity item ${index + 1}`}
                                                                    onClick={() =>
                                                                        change(
                                                                            'activityItems',
                                                                            draft.activityItems.filter(
                                                                                (
                                                                                    _,
                                                                                    position,
                                                                                ) =>
                                                                                    position !==
                                                                                    index,
                                                                            ),
                                                                        )
                                                                    }
                                                                >
                                                                    <X className="size-4" />
                                                                </Button>
                                                            </li>
                                                        ),
                                                    )}
                                                </ul>
                                                <Field
                                                    label="Add an activity item"
                                                    htmlFor="timesheet-activity-item"
                                                >
                                                    <div className="flex gap-2">
                                                        <Input
                                                            id="timesheet-activity-item"
                                                            value={newItem}
                                                            onChange={(event) =>
                                                                !locked &&
                                                                setNewItem(
                                                                    event.target
                                                                        .value,
                                                                )
                                                            }
                                                            onKeyDown={(
                                                                event,
                                                            ) => {
                                                                if (
                                                                    event.key ===
                                                                    'Enter'
                                                                ) {
                                                                    event.preventDefault();
                                                                    if (
                                                                        timesheetText(
                                                                            newItem,
                                                                        )
                                                                    ) {
                                                                        change(
                                                                            'activityItems',
                                                                            [
                                                                                ...draft.activityItems,
                                                                                timesheetText(
                                                                                    newItem,
                                                                                )!,
                                                                            ],
                                                                        );
                                                                        setNewItem(
                                                                            '',
                                                                        );
                                                                    }
                                                                }
                                                            }}
                                                        />
                                                        <Button
                                                            variant="outline"
                                                            aria-label="Add activity item"
                                                            disabled={
                                                                !timesheetText(
                                                                    newItem,
                                                                )
                                                            }
                                                            onClick={() => {
                                                                change(
                                                                    'activityItems',
                                                                    [
                                                                        ...draft.activityItems,
                                                                        timesheetText(
                                                                            newItem,
                                                                        )!,
                                                                    ],
                                                                );
                                                                setNewItem('');
                                                            }}
                                                        >
                                                            <Plus className="size-4" />
                                                        </Button>
                                                    </div>
                                                </Field>
                                            </>
                                        )}
                                    </div>
                                )}
                            </>
                        ) : (
                            <div className="grid gap-4 sm:grid-cols-2">
                                <ReviewCard
                                    icon={CalendarDays}
                                    title="Recorded work"
                                    onEdit={() => setStep(0)}
                                >
                                    <ReviewRow
                                        label="Source"
                                        value={sourceLabel}
                                    />
                                    <ReviewRow
                                        label="Person"
                                        value={
                                            person
                                                ? `${person.first_name} ${person.last_name}`
                                                : draft.client
                                                  ? 'Selected person unavailable'
                                                  : 'No individual person'
                                        }
                                    />
                                    <ReviewRow
                                        label="Site"
                                        value={
                                            record?.site?.name ??
                                            (linked
                                                ? draft.shift?.location
                                                : sites.find(
                                                      (site) =>
                                                          String(site.id) ===
                                                          draft.site,
                                                  )?.name) ??
                                            (linked
                                                ? 'Linked shift location'
                                                : draft.client
                                                  ? 'Follows the selected person'
                                                  : 'Not assigned')
                                        }
                                    />
                                </ReviewCard>
                                <ReviewCard
                                    icon={Clock}
                                    title="Hours"
                                    onEdit={() => setStep(1)}
                                >
                                    <ReviewRow
                                        label="Work date"
                                        value={formatDateOnly(draft.workDate)}
                                    />
                                    <ReviewRow
                                        label="Start"
                                        value={`${formatDateOnly(draft.startDate)} · ${draft.startTime}`}
                                    />
                                    <ReviewRow
                                        label="End"
                                        value={`${formatDateOnly(draft.endDate)} · ${draft.endTime}`}
                                    />
                                    <ReviewRow label="Timezone" value={zone} />
                                    <ReviewRow
                                        label="Break"
                                        value={`${draft.breakMinutes} min`}
                                    />
                                    <ReviewRow
                                        label="Recorded hours"
                                        value={
                                            hours === null
                                                ? 'Check interval'
                                                : `${hours}h`
                                        }
                                    />
                                </ReviewCard>
                                <ReviewCard
                                    icon={FileText}
                                    title="Details"
                                    onEdit={() => setStep(1)}
                                    span
                                >
                                    <ReviewRow
                                        label="Mileage"
                                        value={mileageLabel}
                                    />
                                    <ReviewRow
                                        label="Work flags"
                                        value={
                                            [
                                                draft.sleepover && 'Sleepover',
                                                draft.onCall && 'On-call',
                                                draft.publicHoliday &&
                                                    'Public holiday',
                                                draft.residential &&
                                                    'Residential billable',
                                            ]
                                                .filter(Boolean)
                                                .join(' · ') || 'None selected'
                                        }
                                    />
                                    <ReviewRow
                                        label="Allowance notes"
                                        value={
                                            <span className="whitespace-pre-wrap">
                                                {draft.allowanceNotes || 'None'}
                                            </span>
                                        }
                                    />
                                    <ReviewRow
                                        label="Notes"
                                        value={
                                            <span className="whitespace-pre-wrap">
                                                {draft.notes || 'None'}
                                            </span>
                                        }
                                    />
                                    <ReviewRow
                                        label="Activity items"
                                        value={
                                            draft.activityItems.join(' · ') ||
                                            'None'
                                        }
                                    />
                                    {linked && !record && (
                                        <ReviewRow
                                            label="Shift task progress"
                                            value="Not changed by this form"
                                        />
                                    )}
                                </ReviewCard>
                            </div>
                        )}
                    </fieldset>
                </WizardStepPane>
            </WizardShell>
            <ConfirmDialog
                open={discard}
                onClose={() => setDiscard(false)}
                onConfirm={() => onOpenChange(false)}
                title={
                    command.outcome?.status === 'unknown'
                        ? 'Close this unconfirmed attempt?'
                        : 'Discard this timesheet draft?'
                }
                description={
                    command.outcome?.status === 'unknown'
                        ? 'Closing does not undo anything already saved. Check current records first; the entries held in this form will be lost.'
                        : 'Your unsaved hours and details will be lost.'
                }
                confirmText={
                    command.outcome?.status === 'unknown'
                        ? 'Close form'
                        : 'Discard draft'
                }
                cancelText="Keep editing"
            />
            <ConfirmDialog
                open={submitConfirm}
                onClose={() => !command.pending && setSubmitConfirm(false)}
                onConfirm={() => save(true)}
                title="Save and submit this timesheet?"
                description="Your current hours and details will be saved together and sent for approval."
                confirmText={command.pending ? 'Saving…' : 'Save and submit'}
                variant="default"
                processing={command.pending}
            />
        </>
    );
}
