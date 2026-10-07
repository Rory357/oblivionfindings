import { ConfirmDialog } from '@/components/confirm-dialog';
import { DatePicker } from '@/components/fleet-assets/maintenance/date-picker';
import {
    TimePicker,
    displayTime,
} from '@/components/fleet-assets/maintenance/time-picker';
import { RecordPicker } from '@/components/people-locations/record-picker';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
    WizardSuccessPane,
} from '@/components/wizard/shell';
import {
    WORKER_TIMEZONE,
    formatDateOnly,
    formatDurationMinutes,
} from '@/lib/datetime';
import {
    shiftInputInstant,
    shiftWallInput,
    shiftWeekday,
} from '@/lib/workforce-time-input';
import type { SharedData } from '@/types';
import type { FormDataConvertible, Page } from '@inertiajs/core';
import { router, useForm, usePage } from '@inertiajs/react';
import {
    CalendarClock,
    Check,
    CheckCircle2,
    ChevronLeft,
    ChevronRight,
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
    type LucideIcon,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { shiftSaveProjection } from './shift-save-values';
import {
    UNKNOWN_SHIFT_SAVE,
    useShiftSaveCommand,
    type ShiftSaveSource,
} from './use-shift-save-command';

import { EligibilityAlertBanner } from '@/components/eligibility/eligibility-alert-banner';
import {
    EligibilityStatusBadge,
    deriveEligibilityStatus,
} from '@/components/eligibility/eligibility-status-badge';
import {
    OverrideConfirmationDialog,
    type OverrideableWarning,
} from '@/components/eligibility/override-confirmation-dialog';
import { Button as GuardrailButton } from '@/components/ui/button';
import { Card as GuardrailCard } from '@/components/ui/card';
import {
    SHIFT_TYPES,
    SHIFT_TYPE_ACCENT_CLASSES,
    type ShiftTypeKey,
} from '@/lib/shift-types';
import { cn } from '@/lib/utils';
import { eligibility_preview as eligibilityPreview } from '@/routes/operations/shifts';
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
    role_shortages?: Array<{
        key: string;
        label?: string | null;
        missing?: number | string | null;
    }>;
} | null;

type WizStepKey =
    | 'type'
    | 'people'
    | 'schedule'
    | 'repeat'
    | 'tasks'
    | 'review';

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

const LICENCE_CLASSES = ['1', '2', '3', '4', '5', '6'] as const;
const LICENCE_ENDORSEMENTS = [
    { value: 'P', label: 'Passenger' },
    { value: 'V', label: 'Vehicle recovery' },
    { value: 'I', label: 'Dangerous goods' },
    { value: 'O', label: 'Tracks' },
    { value: 'F', label: 'Forklift' },
    { value: 'D', label: 'Driving instructor' },
    { value: 'T', label: 'Testing officer' },
    { value: 'R', label: 'Roller' },
    { value: 'W', label: 'Wheels' },
] as const;

export type EditableShift = {
    worker_timezone?: string;
    actor_id?: number;
    source?: ShiftSaveSource;
    id: number;
    starts_at: string;
    ends_at: string;
    status: string;
    shift_type?: string | null;
    location?: string | null;
    is_sleepover?: boolean;
    is_on_call?: boolean;
    is_lone_worker?: boolean;
    expected_break_minutes?: number | null;
    notes?: string | null;
    client?: { id: number } | null;
    staff?: { id: number } | null;
    site?: { id: number; name: string } | null;
    service_context_id?: number | null;
    coverage_roles?: string[] | null;
    required_licence_class?: string | null;
    required_licence_endorsements?: string[] | null;
    tasks?: Array<{
        id: number;
        label: string;
        scheduled_time?: string | null;
        can_edit?: boolean;
    }>;
};

type ShiftDialogTask = {
    id?: number;
    label: string;
    scheduled_time: string | null;
    can_edit?: boolean;
};

type EligibilityPreview = {
    is_eligible?: boolean;
    is_allowed?: boolean;
    blocked_reasons?: string[];
    warning_reasons?: string[];
    overrideable_warnings?: OverrideableWarning[];
};

type Props = {
    workerTimezone?: string;
    canOverrideEligibility?: boolean;
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
    defaultUserId?: number | null;
    lockedContext?: LockedContext;
    /** Coverage-gap reservation token; forwarded on save so the gap closes. */
    coverageReservationToken?: string | null;
    /** Coverage requirement id this shift fills; forwarded on save. */
    coverageRuleId?: number | string | null;
    /** Coverage role-shortage keys to pre-tag on the new shift. */
    defaultCoverageRoles?: string[];
    /** Pre-enable the recurring-weekly series (coverage "recurring cover"). */
    defaultRepeatWeekly?: boolean;
    defaultRepeatEndDate?: string | null;
    /** When set, the dialog flips into edit mode and pre-fills from this shift. */
    initialShift?: EditableShift | null;
};

/** Refuse incomplete current-record responses instead of silently clearing fields. */
export function isCurrentEditableShift(
    value: unknown,
    id: number,
    actor: number,
): value is EditableShift {
    if (!value || typeof value !== 'object' || Array.isArray(value))
        return false;
    const shift = value as EditableShift;
    const positive = (entry: unknown) =>
        typeof entry === 'number' && Number.isSafeInteger(entry) && entry > 0;
    const nullableId = (entry: unknown) => entry === null || positive(entry);
    const optionalText = (entry: unknown) =>
        entry === null || typeof entry === 'string';
    const instant = (entry: unknown) =>
        typeof entry === 'string' &&
        /(?:Z|[+-]\d{2}:\d{2})$/.test(entry) &&
        Number.isFinite(Date.parse(entry));
    const source = shift.source;
    if (
        !source ||
        !positive(actor) ||
        !positive(id) ||
        shift.id !== id ||
        shift.actor_id !== actor ||
        source.shift_id !== id ||
        !['draft', 'scheduled'].includes(shift.status) ||
        source.status !== shift.status ||
        !nullableId(source.client_id) ||
        !nullableId(source.site_id) ||
        !shift.site ||
        !positive(shift.site.id) ||
        typeof shift.site.name !== 'string' ||
        (source.site_id !== null && source.site_id !== shift.site.id) ||
        !nullableId(source.user_id) ||
        !nullableId(source.service_context_id) ||
        !nullableId(source.shift_series_id) ||
        !instant(shift.starts_at) ||
        !instant(shift.ends_at) ||
        typeof shift.worker_timezone !== 'string' ||
        typeof shift.shift_type !== 'string' ||
        !optionalText(shift.location) ||
        !optionalText(shift.notes) ||
        !optionalText(shift.required_licence_class) ||
        !nullableId(shift.service_context_id) ||
        shift.service_context_id !== source.service_context_id ||
        typeof shift.is_sleepover !== 'boolean' ||
        typeof shift.is_on_call !== 'boolean' ||
        typeof shift.is_lone_worker !== 'boolean' ||
        !(
            shift.expected_break_minutes === null ||
            (Number.isSafeInteger(shift.expected_break_minutes) &&
                Number(shift.expected_break_minutes) >= 0)
        ) ||
        !Array.isArray(shift.coverage_roles) ||
        !shift.coverage_roles.every((role) => typeof role === 'string') ||
        !Array.isArray(shift.required_licence_endorsements) ||
        !shift.required_licence_endorsements.every(
            (item) => typeof item === 'string',
        ) ||
        !(shift.client === null
            ? source.client_id === null
            : shift.client?.id === source.client_id) ||
        !(shift.staff === null
            ? source.user_id === null
            : shift.staff?.id === source.user_id) ||
        !Array.isArray(shift.tasks) ||
        !shift.tasks.every(
            (task) =>
                task &&
                positive(task.id) &&
                typeof task.label === 'string' &&
                optionalText(task.scheduled_time) &&
                typeof task.can_edit === 'boolean',
        )
    )
        return false;
    try {
        new Intl.DateTimeFormat('en', {
            timeZone: shift.worker_timezone,
        }).format();
    } catch {
        return false;
    }
    return true;
}

export function CreateShiftDialog(props: Props) {
    const { open, onClose } = props;
    const page = usePage<SharedData & { workerTimezone?: string }>();
    const actor = Number(page.props.auth.user?.id ?? 0);
    const [openingActor, setOpeningActor] = useState<number | null>(
        props.open ? actor : null,
    );
    const id = props.initialShift?.id;
    useEffect(() => {
        if (!open) setOpeningActor(null);
        else if (openingActor === null) setOpeningActor(actor);
        else if (actor <= 0 || openingActor !== actor) onClose();
    }, [open, onClose, openingActor, actor]);
    const [loaded, setLoaded] = useState<EditableShift | null>(null);
    const [loadError, setLoadError] = useState('');
    const [attempt, setAttempt] = useState(0);
    useEffect(() => {
        setLoaded(null);
        setLoadError('');
        if (!props.open || !id || actor <= 0 || openingActor !== actor) return;
        const controller = new AbortController();
        fetch(`/operations/shifts/${id}/editable`, {
            signal: controller.signal,
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        })
            .then(async (response) => {
                if (!response.ok)
                    throw new Error(
                        'The current shift could not be loaded. Your access or this record may have changed.',
                    );
                return response.json() as Promise<EditableShift>;
            })
            .then((shift) => {
                if (!controller.signal.aborted) {
                    if (!isCurrentEditableShift(shift, id, actor))
                        throw new Error(
                            'The returned shift does not match. Retry loading this record.',
                        );
                    setLoaded(shift);
                }
            })
            .catch((error) => {
                if (!controller.signal.aborted)
                    setLoadError(
                        error instanceof Error
                            ? error.message
                            : 'The shift could not be loaded.',
                    );
            });
        return () => controller.abort();
    }, [props.open, id, attempt, actor, openingActor]);
    if (
        !props.open ||
        actor <= 0 ||
        (openingActor !== null && openingActor !== actor)
    )
        return null;
    if (id && (!loaded || loaded.id !== id || loaded.actor_id !== actor))
        return (
            <WizardShell
                open
                onClose={props.onClose}
                title="Load shift for editing"
                description="Retrieve the current saved shift before editing."
                railIcon={CalendarClock}
                railTitle="Edit shift"
                railSub={`Shift #${id}`}
                steps={[
                    {
                        key: 'load',
                        label: 'Load saved shift',
                        blurb: 'Preserve current details',
                        icon: CalendarClock,
                    },
                ]}
                stepIndex={0}
                onStepClick={() => {}}
                footerStart={
                    <GuardrailButton variant="outline" onClick={props.onClose}>
                        Cancel
                    </GuardrailButton>
                }
            >
                <WizardStepPane>
                    {loadError ? (
                        <div className="space-y-3">
                            <p role="alert">{loadError}</p>
                            <GuardrailButton
                                onClick={() => setAttempt((n) => n + 1)}
                            >
                                Retry loading shift
                            </GuardrailButton>
                        </div>
                    ) : (
                        <p role="status" className="flex items-center gap-2">
                            <Loader2 className="size-4 animate-spin" />
                            Loading saved shift details…
                        </p>
                    )}
                </WizardStepPane>
            </WizardShell>
        );
    return (
        <ShiftDialogForm
            {...props}
            key={String(actor) + ':' + String(id ?? 'new')}
            initialShift={id ? loaded : null}
            workerTimezone={
                loaded?.worker_timezone ??
                props.workerTimezone ??
                page.props.workerTimezone
            }
            canOverrideEligibility={
                props.canOverrideEligibility ??
                Boolean(page.props.auth?.can?.shifts?.overrideEligibility)
            }
        />
    );
}

function ShiftDialogForm({
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
    defaultUserId = null,
    lockedContext = null,
    coverageReservationToken = null,
    coverageRuleId = null,
    defaultCoverageRoles,
    defaultRepeatWeekly = false,
    defaultRepeatEndDate = null,
    initialShift = null,
    workerTimezone = WORKER_TIMEZONE,
    canOverrideEligibility = false,
}: Props) {
    const isEdit = !!initialShift;
    const actorId = Number(usePage<SharedData>().props.auth.user?.id ?? 0);
    const command = useShiftSaveCommand(
        `${actorId}:${initialShift?.id ?? 'new'}`,
    );
    const [seriesUnknown, setSeriesUnknown] = useState(false);
    const uncertain = command.outcome?.status === 'unknown' || seriesUnknown;
    const toLocalDatetimeInput = (value?: string | null) =>
        shiftWallInput(value, workerTimezone);
    const defaultStartForToday = () =>
        `${shiftWallInput(new Date().toISOString(), workerTimezone).slice(0, 10)}T09:00`;
    const defaultEndForToday = () =>
        `${shiftWallInput(new Date().toISOString(), workerTimezone).slice(0, 10)}T17:00`;
    const weekdayFromDatetime = (value?: string | null) =>
        shiftWeekday(value, workerTimezone);
    const [saved, setSaved] = useState('');
    const [saveError, setSaveError] = useState('');
    const [seriesBusy, setSeriesBusy] = useState(false);
    const [discardOpen, setDiscardOpen] = useState(false);
    const initialClient = useMemo(() => {
        if (initialShift?.client?.id) {
            const found = clients.find((c) => c.id === initialShift.client?.id);
            if (found) return found;
        }
        if (initialShift) return null;
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
    }, [clients, defaultClientId, defaultSiteId, initialShift]);

    // Every client lives at a site — the location field follows it (the
    // coordinator can still type a custom location for community shifts).
    const siteNameFor = (siteId?: number | null) =>
        sites.find((s) => s.id === siteId)?.name ?? '';

    const form = useForm({
        client_id: (initialShift?.client?.id ?? initialClient?.id ?? '') as
            | number
            | '',
        service_context_id: (initialShift
            ? (initialShift.service_context_id ?? '')
            : (initialClient?.service_context_id ??
              defaultServiceContextId ??
              '')) as number | '',
        user_id: (initialShift
            ? (initialShift.staff?.id ?? '')
            : (defaultUserId ?? '')) as number | '',
        starts_at:
            toLocalDatetimeInput(initialShift?.starts_at ?? defaultStartsAt) ||
            defaultStartForToday(),
        ends_at:
            toLocalDatetimeInput(initialShift?.ends_at ?? defaultEndsAt) ||
            defaultEndForToday(),
        location: (initialShift
            ? (initialShift.location ?? '')
            : siteNameFor(initialClient?.site_id)) as string,
        notes: (initialShift?.notes ?? '') as string,
        status:
            initialShift?.status === 'draft'
                ? ('draft' as const)
                : ('scheduled' as const),
        shift_type: ((initialShift?.shift_type as ShiftTypeKey) ??
            'standard') as ShiftTypeKey,
        is_sleepover: !!initialShift?.is_sleepover,
        is_on_call: !!initialShift?.is_on_call,
        is_lone_worker: !!initialShift?.is_lone_worker,
        expected_break_minutes:
            initialShift?.expected_break_minutes != null
                ? String(initialShift.expected_break_minutes)
                : initialShift
                  ? ''
                  : '30',
        // Hydrate from initialShift in edit mode so submitting doesn't wipe
        // existing coverage roles / tasks on the server. We keep the task id
        // for existing rows so syncShiftTasks updates them in place instead of
        // recreating them.
        coverage_roles: (initialShift?.coverage_roles ??
            defaultCoverageRoles ??
            []) as string[],
        required_licence_class: initialShift?.required_licence_class ?? '',
        required_licence_endorsements:
            initialShift?.required_licence_endorsements ?? ([] as string[]),
        coverage_rule_id: (coverageRuleId ?? '') as number | string,
        coverage_reservation_token: (coverageReservationToken ?? '') as string,
        tasks: (initialShift?.tasks?.map((t) => ({
            id: t.id,
            label: t.label,
            scheduled_time: t.scheduled_time ?? null,
            can_edit: t.can_edit,
        })) ?? []) as ShiftDialogTask[],
        repeat_weekly: defaultRepeatWeekly,
        repeat_end_date: (defaultRepeatEndDate ?? '') as string,
        repeat_by_weekday: [
            weekdayFromDatetime(initialShift?.starts_at ?? defaultStartsAt),
        ] as Weekday[],
        return_to: '' as string,
        override_acknowledged: false,
        override_reason: '' as string,
    });

    // ── Wizard step machinery (Add Client / handover-wizard chrome) ──
    const WIZ_STEPS = useMemo(
        () =>
            (
                [
                    {
                        key: 'type',
                        label: 'Shift type',
                        blurb: 'What kind of shift',
                        icon: LayoutGrid,
                    },
                    {
                        key: 'people',
                        label: 'Who & where',
                        blurb: 'Client, location, staff',
                        icon: Users,
                    },
                    {
                        key: 'schedule',
                        label: 'Schedule',
                        blurb: 'Times, break, publish',
                        icon: Clock,
                    },
                    {
                        key: 'repeat',
                        label: 'Repeat weekly',
                        blurb: 'Optional recurring series',
                        icon: Repeat,
                    },
                    {
                        key: 'tasks',
                        label: 'Tasks & notes',
                        blurb: 'Worker checklist',
                        icon: Pencil,
                    },
                    {
                        key: 'review',
                        label: 'Review',
                        blurb: isEdit
                            ? 'Confirm and save'
                            : 'Confirm and create',
                        icon: CheckCircle2,
                    },
                ] as {
                    key: WizStepKey;
                    label: string;
                    blurb: string;
                    icon: LucideIcon;
                }[]
            ).filter((s) => !(isEdit && s.key === 'repeat')),
        [isEdit],
    );
    const [stepIndex, setStepIndex] = useState(0);
    const [stepErrors, setStepErrors] = useState<Record<string, string>>({});
    const cur = WIZ_STEPS[Math.min(stepIndex, WIZ_STEPS.length - 1)];

    function jumpTo(key: WizStepKey) {
        setStepErrors({});
        const i = WIZ_STEPS.findIndex((s) => s.key === key);
        if (i >= 0) setStepIndex(i);
    }

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
        setStepIndex(0);
        setStepErrors({});
        if (isEdit) return; // useForm initialiser handled edit-mode hydration
        form.setData({
            ...form.data,
            client_id: initialClient?.id ?? '',
            service_context_id:
                initialClient?.service_context_id ??
                defaultServiceContextId ??
                '',
            user_id: defaultUserId ?? '',
            location: siteNameFor(initialClient?.site_id),
            coverage_roles: defaultCoverageRoles ?? [],
            required_licence_class: '',
            required_licence_endorsements: [],
            coverage_rule_id: coverageRuleId ?? '',
            coverage_reservation_token: coverageReservationToken ?? '',
            starts_at:
                toLocalDatetimeInput(defaultStartsAt) || defaultStartForToday(),
            ends_at:
                toLocalDatetimeInput(defaultEndsAt) || defaultEndForToday(),
            repeat_weekly: defaultRepeatWeekly,
            repeat_end_date: defaultRepeatEndDate ?? '',
            repeat_by_weekday: [weekdayFromDatetime(defaultStartsAt)],
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

    function toggleLicenceEndorsement(endorsement: string) {
        const selected = new Set(form.data.required_licence_endorsements);
        if (selected.has(endorsement)) selected.delete(endorsement);
        else selected.add(endorsement);
        form.setData('required_licence_endorsements', Array.from(selected));
    }

    function addTask() {
        form.setData('tasks', [
            ...form.data.tasks,
            { label: '', scheduled_time: null },
        ]);
    }
    function setTask(i: number, label: string) {
        if (form.data.tasks[i]?.can_edit === false) return;
        const next = [...form.data.tasks];
        next[i] = { ...next[i], label };
        form.setData('tasks', next);
    }
    function setTaskScheduled(i: number, scheduled_time: string | null) {
        if (form.data.tasks[i]?.can_edit === false) return;
        const next = [...form.data.tasks];
        next[i] = { ...next[i], scheduled_time };
        form.setData('tasks', next);
    }
    function defaultTaskScheduledTime() {
        return form.data.starts_at?.slice(11, 16) || '09:00';
    }
    function removeTask(i: number) {
        if (form.data.tasks[i]?.can_edit === false) return;
        form.setData(
            'tasks',
            form.data.tasks.filter((_, idx) => idx !== i),
        );
    }

    function selectClient(idStr: string) {
        const id = Number(idStr) || '';
        const previous = clients.find(
            (x) => x.id === Number(form.data.client_id),
        );
        form.setData('client_id', id as number | '');
        const c = clients.find((x) => x.id === id);
        if (c?.service_context_id != null) {
            form.setData('service_context_id', c.service_context_id);
        }
        // Follow the client's home site into the location field — unless the
        // coordinator typed a custom location, which we keep.
        const wasAutoFilled =
            !form.data.location ||
            form.data.location === siteNameFor(previous?.site_id);
        if (wasAutoFilled) {
            form.setData('location', siteNameFor(c?.site_id));
        }
    }

    const durationLabel = useMemo(() => {
        try {
            const a = Date.parse(
                shiftInputInstant(
                    form.data.starts_at,
                    workerTimezone,
                    initialShift?.starts_at,
                ),
            );
            const b = Date.parse(
                shiftInputInstant(
                    form.data.ends_at,
                    workerTimezone,
                    initialShift?.ends_at,
                ),
            );
            if (a && b && b > a) return formatDurationMinutes((b - a) / 60_000);
        } catch {
            // fallthrough
        }
        return '—';
    }, [
        form.data.starts_at,
        form.data.ends_at,
        workerTimezone,
        initialShift?.starts_at,
        initialShift?.ends_at,
    ]);

    const summary = useMemo(() => {
        const day = formatDateOnly(form.data.starts_at.slice(0, 10), 'No date');
        const time = displayTime(form.data.starts_at.slice(11, 16));
        const client = clients.find(
            (c) => c.id === Number(form.data.client_id),
        );
        const name = client
            ? `${client.first_name} ${client.last_name}`.trim()
            : 'No client';
        const recurringSuffix = form.data.repeat_weekly
            ? ' · Weekly series'
            : '';
        return `${day} · ${time} · ${durationLabel} · ${name}${recurringSuffix}`;
    }, [form.data, clients, durationLabel]);

    const selectedClient = clients.find(
        (c) => c.id === Number(form.data.client_id),
    );
    const selectedStaff = staff.find((s) => s.id === Number(form.data.user_id));

    const [eligPreview, setEligPreview] = useState<EligibilityPreview | null>(
        null,
    );
    const [eligLoading, setEligLoading] = useState(false);
    const [overrideOpen, setOverrideOpen] = useState(false);
    const [eligError, setEligError] = useState('');
    const savingRef = useRef(false);
    const initialDraft = useRef(JSON.stringify(form.data));
    const busy = form.processing || seriesBusy || command.pending;
    const closeSafely = () => {
        if (busy || command.busy.current || savingRef.current) return;
        if (
            !saved &&
            (uncertain || JSON.stringify(form.data) !== initialDraft.current)
        )
            setDiscardOpen(true);
        else onClose();
    };
    const eligAbort = useRef<AbortController | null>(null);
    const eligTimer = useRef<ReturnType<typeof setTimeout> | null>(null);

    const eligibilityStatus = useMemo(
        () => (eligPreview ? deriveEligibilityStatus(eligPreview) : null),
        [eligPreview],
    );
    const eligibilityWarnings = eligPreview?.warning_reasons ?? [];
    const eligibilityBlocks = eligPreview?.blocked_reasons ?? [];
    const overrideWarnings = eligPreview?.overrideable_warnings?.length
        ? eligPreview.overrideable_warnings
        : eligibilityWarnings.map((message) => ({
              rule: 'information',
              message,
              overrideable: false,
          }));

    const fetchEligibility = useCallback(() => {
        eligAbort.current?.abort();
        if (eligTimer.current) clearTimeout(eligTimer.current);
        setEligPreview(null);
        setEligError('');
        const userId = form.data.user_id;
        const startsAt = form.data.starts_at;
        const endsAt = form.data.ends_at;

        // A pending or stale preview must never authorise a different edit.
        if (eligTimer.current) clearTimeout(eligTimer.current);
        eligAbort.current?.abort();
        setEligPreview(null);

        if (!userId || !startsAt || !endsAt) {
            setEligPreview(null);
            setEligLoading(false);
            return;
        }

        if (eligTimer.current) clearTimeout(eligTimer.current);
        setEligLoading(true);
        eligTimer.current = setTimeout(async () => {
            const controller = new AbortController();
            eligAbort.current = controller;
            setEligLoading(true);

            try {
                const query: Record<string, string | string[]> = {
                    user_id: String(userId),
                    client_id: String(form.data.client_id),
                    service_context_id: String(form.data.service_context_id),
                    starts_at: shiftInputInstant(
                        startsAt,
                        workerTimezone,
                        initialShift?.starts_at,
                    ),
                    ends_at: shiftInputInstant(
                        endsAt,
                        workerTimezone,
                        initialShift?.ends_at,
                    ),
                };

                const siteId =
                    selectedClient?.site_id ??
                    initialShift?.site?.id ??
                    defaultSiteId;
                if (siteId) query.site_id = String(siteId);
                if (initialShift?.id) query.shift_id = String(initialShift.id);
                if (form.data.shift_type)
                    query.shift_type = form.data.shift_type;
                if (form.data.coverage_roles?.length) {
                    query.coverage_roles = form.data.coverage_roles;
                }
                if (form.data.required_licence_class) {
                    query.required_licence_class =
                        form.data.required_licence_class;
                }
                if (form.data.required_licence_endorsements.length) {
                    query.required_licence_endorsements =
                        form.data.required_licence_endorsements;
                }

                const res = await fetch(eligibilityPreview.url({ query }), {
                    signal: controller.signal,
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'same-origin',
                });
                if (!res.ok) throw new Error('preview failed');
                const data = (await res.json()) as EligibilityPreview;
                if (!controller.signal.aborted) setEligPreview(data);
            } catch {
                if (!controller.signal.aborted) {
                    setEligPreview(null);
                    setEligError(
                        'Eligibility preview is unavailable. Check the entered times and retry. Saving still runs the required checks.',
                    );
                }
            } finally {
                if (!controller.signal.aborted) setEligLoading(false);
            }
        }, 500);
    }, [
        form.data.user_id,
        form.data.client_id,
        form.data.service_context_id,
        workerTimezone,
        initialShift?.starts_at,
        initialShift?.ends_at,
        form.data.starts_at,
        form.data.ends_at,
        form.data.shift_type,
        form.data.coverage_roles,
        form.data.required_licence_class,
        form.data.required_licence_endorsements,
        selectedClient?.site_id,
        initialShift?.id,
        initialShift?.site?.id,
        defaultSiteId,
    ]);

    useEffect(() => {
        if (!open) return;
        fetchEligibility();
        return () => {
            eligAbort.current?.abort();
            if (eligTimer.current) clearTimeout(eligTimer.current);
        };
    }, [fetchEligibility, open]);

    function showSaveErrors(errors: Record<string, string>) {
        const map: Record<string, string> = {
            start_date: 'starts_at',
            starts_time: 'starts_at',
            ends_time: 'ends_at',
            end_date: 'repeat_end_date',
            by_weekday: 'repeat_by_weekday',
        };
        const mapped = Object.fromEntries(
            Object.entries(errors).map(([key, value]) => [
                map[key] ?? key,
                value,
            ]),
        );
        for (const [key, value] of Object.entries(mapped))
            form.setError(key as keyof typeof form.data, value);
        const keys = Object.keys(mapped);
        const step: WizStepKey = keys.some((k) =>
            /^(client|user|location|service_context|coverage_roles|required_licence)/.test(
                k,
            ),
        )
            ? 'people'
            : keys.some((k) => /^(starts_at|ends_at|expected_break)/.test(k))
              ? 'schedule'
              : keys.some((k) => /^repeat_(end|by)/.test(k))
                ? 'repeat'
                : keys.some((k) => /^tasks/.test(k))
                  ? 'tasks'
                  : 'review';
        jumpTo(step);
        setSaveError(
            Object.values(mapped).join(' ') ||
                'The shift was not saved. Review the details and retry.',
        );
        // Recheck after a rejected save without losing the entered values.
        fetchEligibility();
    }
    useEffect(() => {
        const result = command.outcome;
        if (!result) return;
        if (result.status === 'confirmed') {
            setSaveError('');
            setSaved(
                result.receipt.changed
                    ? isEdit
                        ? 'Your shift changes were saved.'
                        : 'The shift was created.'
                    : 'The saved shift already matches these details.',
            );
            return;
        }
        if (result.status === 'rejected' && Object.keys(result.errors).length) {
            showSaveErrors(result.errors);
        } else {
            setSaveError(result.message);
            if (result.status === 'rejected' && result.eligibility) {
                setEligPreview(result.eligibility);
                setEligError('');
                jumpTo('review');
            }
        }
        // Consume each response once; subsequent field edits stay under user control.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [command.outcome]);

    // Recurring creation keeps its existing separate endpoint and response.
    // Its persistence contract is outside the single-Shift receipt.
    function confirmedSeries(page: Page) {
        const flash = page.props.flash as
            | { success?: string; error?: string }
            | undefined;
        if (
            !flash?.error &&
            typeof flash?.success === 'string' &&
            /^Recurring shifts created \(\d+\)\.$/.test(flash.success)
        ) {
            setSaved(flash.success);
            setSaveError('');
        } else {
            setSeriesUnknown(true);
            setSaveError(UNKNOWN_SHIFT_SAVE);
        }
    }
    function submitForm(overrideReason?: string) {
        if (
            savingRef.current ||
            command.busy.current ||
            command.held.current ||
            busy ||
            eligLoading ||
            saved ||
            uncertain
        )
            return;
        for (const step of WIZ_STEPS) {
            if (!validateStep(step.key)) {
                setStepIndex(WIZ_STEPS.indexOf(step));
                return;
            }
        }
        setSaveError('');
        eligAbort.current?.abort();
        if (eligTimer.current) clearTimeout(eligTimer.current);
        setEligLoading(false);
        form.clearErrors();
        if (isEdit || !form.data.repeat_weekly) {
            try {
                const values = shiftSaveProjection({
                    ...form.data,
                    starts_at: shiftInputInstant(
                        form.data.starts_at,
                        workerTimezone,
                        initialShift?.starts_at,
                    ),
                    ends_at: shiftInputInstant(
                        form.data.ends_at,
                        workerTimezone,
                        initialShift?.ends_at,
                    ),
                });
                const siteId =
                    selectedClient?.site_id ??
                    (initialShift?.source?.client_id === values.client_id
                        ? initialShift?.site?.id
                        : null);
                if (!siteId || (isEdit && !initialShift?.source)) {
                    setSaveError(
                        'The current site could not be confirmed. Reload this shift or choose a person with a permitted site. Your entries are kept here.',
                    );
                    return;
                }
                const payload: Record<string, FormDataConvertible> = {
                    ...values,
                    tasks:
                        values.tasks?.map((task) =>
                            task.id === null
                                ? {
                                      label: task.label,
                                      scheduled_time: task.scheduled_time,
                                  }
                                : task,
                        ) ?? undefined,
                    coverage_rule_id: form.data.coverage_rule_id || undefined,
                    coverage_reservation_token:
                        form.data.coverage_reservation_token || undefined,
                    return_to:
                        window.location.pathname + window.location.search,
                    override_acknowledged: Boolean(overrideReason),
                    override_reason: overrideReason ?? '',
                };
                if (
                    !isEdit &&
                    !values.required_licence_class &&
                    values.required_licence_endorsements.length === 0
                ) {
                    delete payload.required_licence_class;
                    delete payload.required_licence_endorsements;
                }
                void command.submit(
                    {
                        actorId,
                        source: initialShift?.source ?? null,
                        siteId,
                        values,
                    },
                    payload,
                );
            } catch (error) {
                setSaveError(
                    error instanceof Error
                        ? error.message
                        : 'Check the shift details before saving.',
                );
            }
            return;
        }
        savingRef.current = true;
        let seriesResponded = false;
        const callbacks = {
            preserveScroll: true,
            preserveState: true,
            onSuccess: (page: Page) => {
                seriesResponded = true;
                confirmedSeries(page);
            },
            onError: (errors: Record<string, string>) => {
                seriesResponded = true;
                showSaveErrors(errors);
            },
            onFinish: () => {
                savingRef.current = false;
                setSeriesBusy(false);
                if (!seriesResponded) {
                    setSeriesUnknown(true);
                    setSaveError(UNKNOWN_SHIFT_SAVE);
                }
            },
        };
        // Recurring series
        const starts = form.data.starts_at;
        const ends = form.data.ends_at;
        const startDate = starts?.slice(0, 10);
        const startsTime = starts?.slice(11, 16);
        const endsTime = ends?.slice(11, 16);
        setSeriesBusy(true);
        try {
            router.post(
                storeShiftSeries.url(),
                {
                    client_id: form.data.client_id,
                    service_context_id: form.data.service_context_id,
                    user_id: form.data.user_id || null,
                    timezone: workerTimezone,
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
                    is_lone_worker: form.data.is_lone_worker,
                    expected_break_minutes:
                        form.data.expected_break_minutes || null,
                    tasks: form.data.tasks.filter((t) => t.label.trim() !== ''),
                    coverage_rule_id: form.data.coverage_rule_id || undefined,
                    coverage_roles: form.data.coverage_roles,
                    ...(form.data.required_licence_class ||
                    form.data.required_licence_endorsements.length
                        ? {
                              required_licence_class:
                                  form.data.required_licence_class || null,
                              required_licence_endorsements:
                                  form.data.required_licence_endorsements,
                          }
                        : {}),
                    coverage_reservation_token:
                        form.data.coverage_reservation_token || undefined,
                    return_to:
                        typeof window !== 'undefined'
                            ? window.location.pathname + window.location.search
                            : undefined,
                },
                {
                    ...callbacks,
                },
            );
        } catch {
            savingRef.current = false;
            setSeriesBusy(false);
            setSeriesUnknown(true);
            setSaveError(UNKNOWN_SHIFT_SAVE);
        }
    }

    // Per-step client-side gates — the server stays authoritative; these only
    // stop an obviously-incomplete step from advancing.
    function validateStep(key: WizStepKey): boolean {
        const errs: Record<string, string> = {};
        if (key === 'people' && !form.data.client_id) {
            errs.client_id = 'Choose a client';
        }
        if (key === 'schedule') {
            try {
                shiftInputInstant(
                    form.data.starts_at,
                    workerTimezone,
                    initialShift?.starts_at,
                );
            } catch (error) {
                errs.starts_at =
                    error instanceof Error
                        ? error.message
                        : 'Check the start date and time.';
            }
            try {
                shiftInputInstant(
                    form.data.ends_at,
                    workerTimezone,
                    initialShift?.ends_at,
                );
            } catch (error) {
                errs.ends_at =
                    error instanceof Error
                        ? error.message
                        : 'Check the end date and time.';
            }
            if (
                !errs.starts_at &&
                !errs.ends_at &&
                Date.parse(
                    shiftInputInstant(
                        form.data.ends_at,
                        workerTimezone,
                        initialShift?.ends_at,
                    ),
                ) <=
                    Date.parse(
                        shiftInputInstant(
                            form.data.starts_at,
                            workerTimezone,
                            initialShift?.starts_at,
                        ),
                    )
            )
                errs.ends_at = 'End must be after the start';
        }
        if (key === 'repeat' && form.data.repeat_weekly) {
            if (form.data.repeat_by_weekday.length === 0) {
                errs.repeat_by_weekday = 'Pick at least one weekday';
            }
            if (!form.data.repeat_end_date) {
                errs.repeat_end_date = 'Pick a repeat end date';
            } else if (
                form.data.repeat_end_date < form.data.starts_at.slice(0, 10)
            ) {
                errs.repeat_end_date =
                    'End date must be on or after the first shift';
            }
        }
        setStepErrors(errs);
        return Object.keys(errs).length === 0;
    }

    const goNext = () => {
        if (!validateStep(cur.key)) return;
        setStepErrors({});
        setStepIndex((i) => Math.min(i + 1, WIZ_STEPS.length - 1));
    };
    const goBack = () => {
        setStepErrors({});
        setStepIndex((i) => Math.max(i - 1, 0));
    };

    const readinessPct = useMemo(() => {
        let have = 0;
        if (form.data.shift_type) have++;
        if (form.data.client_id) have++;
        if (form.data.starts_at && form.data.ends_at && durationLabel !== '—') {
            have++;
        }
        if (
            !form.data.repeat_weekly ||
            (form.data.repeat_by_weekday.length > 0 &&
                !!form.data.repeat_end_date)
        ) {
            have++;
        }
        return Math.round((have / 4) * 100);
    }, [
        form.data.shift_type,
        form.data.client_id,
        form.data.starts_at,
        form.data.ends_at,
        form.data.repeat_weekly,
        form.data.repeat_by_weekday,
        form.data.repeat_end_date,
        durationLabel,
    ]);

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        if (
            busy ||
            saved ||
            uncertain ||
            command.busy.current ||
            savingRef.current
        )
            return;
        // On every step except review, the primary action advances the
        // wizard — this also keeps Cmd/Ctrl+Enter working per step.
        if (cur.key !== 'review') {
            goNext();
            return;
        }
        if (eligLoading) return;
        if (isEdit && eligibilityStatus?.status === 'blocked') {
            return;
        }
        if (
            isEdit &&
            eligibilityStatus?.status === 'warnings' &&
            eligibilityWarnings.length > 0
        ) {
            if (
                eligPreview?.overrideable_warnings?.length &&
                !canOverrideEligibility
            ) {
                setSaveError(
                    'An authorised coordinator must review these eligibility warnings before saving.',
                );
                return;
            }
            setOverrideOpen(true);
            return;
        }
        submitForm();
    }

    return (
        <>
            <WizardShell
                open={open}
                onClose={closeSafely}
                title={
                    isEdit ? `Edit shift #${initialShift?.id}` : 'Create shift'
                }
                description="Plan a supported-living duty, staff, times and care tasks."
                railIcon={CalendarClock}
                railTitle={isEdit ? 'Edit shift' : 'Create shift'}
                railSub={workerTimezone}
                steps={WIZ_STEPS}
                stepIndex={stepIndex}
                onStepClick={(index) => {
                    if (!busy) {
                        setStepErrors({});
                        setStepIndex(index);
                    }
                }}
                pct={readinessPct}
                pctLabel="Required details"
                maxWidth="min(94vw, 1080px)"
                maxHeight="min(88vh, 820px)"
                footerStart={
                    <div className="flex items-center gap-2">
                        <GuardrailButton
                            type="button"
                            variant="outline"
                            className="frontline-hit"
                            onClick={closeSafely}
                            disabled={busy}
                        >
                            Cancel
                        </GuardrailButton>
                        {stepIndex > 0 && (
                            <GuardrailButton
                                type="button"
                                variant="ghost"
                                className="frontline-hit"
                                onClick={goBack}
                                disabled={busy}
                            >
                                <ChevronLeft className="size-4" />
                                Back
                            </GuardrailButton>
                        )}
                    </div>
                }
                footerEnd={
                    <GuardrailButton
                        type="submit"
                        form="workforce-shift-form"
                        className="frontline-hit"
                        disabled={
                            busy ||
                            uncertain ||
                            (cur.key === 'review' && eligLoading)
                        }
                    >
                        {busy ? (
                            <>
                                <Loader2 className="size-4 animate-spin" />
                                Saving…
                            </>
                        ) : cur.key === 'review' ? (
                            isEdit ? (
                                'Save changes'
                            ) : (
                                'Create shift'
                            )
                        ) : (
                            <>
                                Continue
                                <ChevronRight className="size-4" />
                            </>
                        )}
                    </GuardrailButton>
                }
                success={
                    saved ? (
                        <WizardSuccessPane
                            title={
                                isEdit
                                    ? 'Shift updated'
                                    : form.data.repeat_weekly
                                      ? 'Recurring shifts created'
                                      : 'Shift created'
                            }
                            blurb={saved}
                            actions={
                                <div className="flex max-w-lg min-w-0 flex-col items-center gap-4">
                                    {command.outcome?.status === 'confirmed' &&
                                        command.outcome.warnings.length > 0 && (
                                            <EligibilityAlertBanner
                                                type="warnings"
                                                title="Staff eligibility warnings"
                                                reasons={
                                                    command.outcome.warnings
                                                }
                                                className="text-left [overflow-wrap:anywhere]"
                                            />
                                        )}
                                    <GuardrailButton
                                        className="frontline-hit"
                                        onClick={onClose}
                                    >
                                        Done
                                    </GuardrailButton>
                                </div>
                            }
                        />
                    ) : undefined
                }
            >
                <form
                    id="workforce-shift-form"
                    data-shifts-create
                    onSubmit={handleSubmit}
                >
                    <WizardStepPane>
                        {uncertain && (
                            <div
                                className="mb-4 space-y-2 rounded-lg border border-status-warning/40 bg-status-warning-bg p-3"
                                role="status"
                            >
                                <p>
                                    Check the saved roster before making another
                                    attempt. This draft stays open while you
                                    check.
                                </p>
                                <GuardrailButton
                                    asChild
                                    variant="outline"
                                    className="frontline-hit"
                                >
                                    <a
                                        href="/operations/rostering?tab=shifts"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                    >
                                        Open roster to check
                                    </a>
                                </GuardrailButton>
                            </div>
                        )}
                        {saveError && (
                            <p
                                role="alert"
                                className="mb-4 rounded-lg border border-status-critical/30 bg-status-critical-bg p-3 text-sm text-status-critical"
                            >
                                {saveError}
                            </p>
                        )}
                        <fieldset
                            disabled={busy || uncertain}
                            className="min-w-0 space-y-4"
                        >
                            {lockedContext ? (
                                <LockedContextCard context={lockedContext} />
                            ) : null}

                            {(cur.key === 'people' || cur.key === 'review') &&
                            form.data.user_id ? (
                                <GuardrailCard
                                    unstyled
                                    className="mb-4 space-y-2 rounded-xl border border-border bg-card p-3"
                                >
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <div>
                                            <div className="text-sm font-semibold text-foreground">
                                                Staff eligibility
                                            </div>
                                            <div className="text-xs text-muted-foreground">
                                                {selectedStaff?.name ??
                                                    'Selected staff'}
                                            </div>
                                        </div>
                                        {eligLoading ? (
                                            <span className="inline-flex items-center gap-1.5 rounded-md border border-border px-2 py-1 text-xs text-muted-foreground">
                                                <Loader2 className="h-3.5 w-3.5 animate-spin" />
                                                Checking
                                            </span>
                                        ) : eligibilityStatus ? (
                                            <EligibilityStatusBadge
                                                status={
                                                    eligibilityStatus.status
                                                }
                                                warningCount={
                                                    eligibilityStatus.warningCount
                                                }
                                            />
                                        ) : null}
                                    </div>
                                    {!eligLoading &&
                                    eligibilityBlocks.length > 0 ? (
                                        <EligibilityAlertBanner
                                            type="blocked"
                                            reasons={eligibilityBlocks}
                                            title="This staff member cannot be assigned"
                                        />
                                    ) : null}
                                    {!eligLoading &&
                                    eligibilityBlocks.length === 0 &&
                                    eligibilityWarnings.length > 0 ? (
                                        <EligibilityAlertBanner
                                            type="warnings"
                                            reasons={eligibilityWarnings}
                                            title="Staff eligibility warnings"
                                        />
                                    ) : null}
                                    <p className="text-xs text-muted-foreground">
                                        Advisory preview
                                        {form.data.repeat_weekly
                                            ? ' for the first occurrence only'
                                            : ''}
                                        . The complete proposal is checked again
                                        when saved.
                                    </p>
                                    {eligError && (
                                        <div className="space-y-2">
                                            <p
                                                role="status"
                                                className="text-sm text-muted-foreground"
                                            >
                                                {eligError}
                                            </p>
                                            <GuardrailButton
                                                type="button"
                                                variant="outline"
                                                className="frontline-hit"
                                                onClick={fetchEligibility}
                                            >
                                                Retry eligibility check
                                            </GuardrailButton>
                                        </div>
                                    )}
                                </GuardrailCard>
                            ) : null}

                            {cur.key === 'type' ? (
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
                                    <FieldError
                                        message={form.errors.shift_type}
                                    />
                                    <label className="mt-3 flex cursor-pointer items-start gap-2.5 rounded-lg border border-border p-3 transition-colors hover:bg-muted/40">
                                        <input
                                            type="checkbox"
                                            className="mt-0.5 h-4 w-4 rounded border-border text-primary focus:ring-2 focus:ring-primary/40"
                                            checked={form.data.is_lone_worker}
                                            onChange={(e) =>
                                                form.setData(
                                                    'is_lone_worker',
                                                    e.target.checked,
                                                )
                                            }
                                        />
                                        <span className="text-sm">
                                            <span className="block font-medium text-foreground">
                                                Lone / remote worker
                                            </span>
                                            <span className="block text-xs text-muted-foreground">
                                                Flag this shift for Lone Worker
                                                Safety monitoring — it surfaces
                                                in the watch-tower as a shift
                                                needing a check-in session.
                                            </span>
                                        </span>
                                    </label>
                                </Section>
                            ) : null}

                            {cur.key === 'people' ? (
                                <Section first icon={Users} title="Who & where">
                                    <div className="grid gap-3 sm:grid-cols-2">
                                        <div>
                                            <Label required>
                                                Person supported
                                            </Label>
                                            <RecordPicker
                                                label="Person supported"
                                                value={String(
                                                    form.data.client_id,
                                                )}
                                                onChange={selectClient}
                                                disabled={busy || uncertain}
                                                options={clients.map((c) => ({
                                                    value: String(c.id),
                                                    label: `${c.first_name} ${c.last_name}`.trim(),
                                                    description: siteNameFor(
                                                        c.site_id,
                                                    ),
                                                }))}
                                            />
                                            {selectedClient ? (
                                                <ServiceContextHint
                                                    client={selectedClient}
                                                    serviceContexts={
                                                        serviceContexts
                                                    }
                                                />
                                            ) : null}
                                            <FieldError
                                                message={form.errors.client_id}
                                            />
                                            <FieldError
                                                message={stepErrors.client_id}
                                            />
                                        </div>

                                        <div>
                                            <Label htmlFor="csd-location">
                                                Location{' '}
                                                <span className="font-normal text-muted-foreground">
                                                    · follows the client's site
                                                </span>
                                            </Label>
                                            <input
                                                id="csd-location"
                                                className="input min-h-[44px]"
                                                value={form.data.location}
                                                onChange={(e) =>
                                                    form.setData(
                                                        'location',
                                                        e.target.value,
                                                    )
                                                }
                                                placeholder="e.g. Client's home or community venue"
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
                                            <FieldError
                                                message={form.errors.location}
                                            />
                                        </div>

                                        <div className="sm:col-span-2">
                                            <Label>Staff</Label>
                                            <RecordPicker
                                                label="Staff"
                                                value={String(
                                                    form.data.user_id,
                                                )}
                                                disabled={busy || uncertain}
                                                onChange={(value) =>
                                                    form.setData(
                                                        'user_id',
                                                        value === ''
                                                            ? ''
                                                            : Number(value),
                                                    )
                                                }
                                                options={[
                                                    {
                                                        value: '',
                                                        label: 'Open shift (unassigned)',
                                                    },
                                                    ...staff.map((person) => ({
                                                        value: String(
                                                            person.id,
                                                        ),
                                                        label: person.name,
                                                    })),
                                                ]}
                                            />
                                            {!form.data.user_id ? (
                                                <p className="mt-1 text-xs text-muted-foreground">
                                                    An unassigned shift is saved
                                                    as a draft. Assign staff or
                                                    publish it later from
                                                    Rostering.
                                                </p>
                                            ) : null}
                                            <FieldError
                                                message={form.errors.user_id}
                                            />
                                        </div>

                                        <div className="rounded-xl border border-border bg-muted/25 p-3 sm:col-span-2">
                                            <div className="mb-3">
                                                <div className="text-xs font-semibold text-foreground">
                                                    Driving requirement
                                                </div>
                                                <p className="mt-0.5 text-xs text-muted-foreground">
                                                    Optional. Leave blank for an
                                                    ordinary shift.
                                                </p>
                                            </div>
                                            <div className="grid gap-3 sm:grid-cols-[12rem_1fr]">
                                                <div>
                                                    <Label htmlFor="csd-licence-class">
                                                        Required licence class
                                                    </Label>
                                                    <select
                                                        id="csd-licence-class"
                                                        className="select min-h-[44px]"
                                                        value={
                                                            form.data
                                                                .required_licence_class
                                                        }
                                                        onChange={(e) =>
                                                            form.setData(
                                                                'required_licence_class',
                                                                e.target.value,
                                                            )
                                                        }
                                                    >
                                                        <option value="">
                                                            No class requirement
                                                        </option>
                                                        {LICENCE_CLASSES.map(
                                                            (licenceClass) => (
                                                                <option
                                                                    key={
                                                                        licenceClass
                                                                    }
                                                                    value={
                                                                        licenceClass
                                                                    }
                                                                >
                                                                    Class{' '}
                                                                    {
                                                                        licenceClass
                                                                    }
                                                                </option>
                                                            ),
                                                        )}
                                                    </select>
                                                </div>
                                                <div>
                                                    <Label>
                                                        Required endorsements
                                                    </Label>
                                                    <div className="flex flex-wrap gap-1.5">
                                                        {LICENCE_ENDORSEMENTS.map(
                                                            (endorsement) => {
                                                                const selected =
                                                                    form.data.required_licence_endorsements.includes(
                                                                        endorsement.value,
                                                                    );
                                                                return (
                                                                    <GuardrailButton
                                                                        unstyled
                                                                        key={
                                                                            endorsement.value
                                                                        }
                                                                        type="button"
                                                                        aria-label={`${endorsement.label} endorsement`}
                                                                        aria-pressed={
                                                                            selected
                                                                        }
                                                                        onClick={() =>
                                                                            toggleLicenceEndorsement(
                                                                                endorsement.value,
                                                                            )
                                                                        }
                                                                        className={cn(
                                                                            'frontline-tap rounded-md border px-2.5 text-xs font-semibold transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring',
                                                                            selected
                                                                                ? 'border-primary bg-primary/10 text-primary'
                                                                                : 'border-border bg-card text-muted-foreground hover:border-primary/40 hover:text-foreground',
                                                                        )}
                                                                    >
                                                                        {
                                                                            endorsement.value
                                                                        }{' '}
                                                                        ·{' '}
                                                                        {
                                                                            endorsement.label
                                                                        }
                                                                    </GuardrailButton>
                                                                );
                                                            },
                                                        )}
                                                    </div>
                                                </div>
                                            </div>
                                            <FieldError
                                                message={
                                                    form.errors
                                                        .required_licence_class
                                                }
                                            />
                                            <FieldError
                                                message={
                                                    form.errors
                                                        .required_licence_endorsements
                                                }
                                            />
                                        </div>
                                    </div>
                                </Section>
                            ) : null}

                            {cur.key === 'schedule' ? (
                                <Section
                                    first
                                    icon={Clock}
                                    title="Schedule"
                                    hint={
                                        durationLabel === '—'
                                            ? undefined
                                            : `${durationLabel} including any breaks`
                                    }
                                >
                                    <ScheduleStrip
                                        workerTimezone={workerTimezone}
                                        startsAt={form.data.starts_at}
                                        endsAt={form.data.ends_at}
                                        breakMinutes={
                                            form.data.expected_break_minutes
                                        }
                                        onStartsAtChange={(v) =>
                                            form.setData('starts_at', v)
                                        }
                                        onEndsAtChange={(v) =>
                                            form.setData('ends_at', v)
                                        }
                                        onBreakChange={(v) =>
                                            form.setData(
                                                'expected_break_minutes',
                                                v,
                                            )
                                        }
                                        duration={durationLabel}
                                    />
                                    <div className="mt-3">
                                        <Label required>Save as</Label>
                                        <StatusPicker
                                            value={
                                                form.data.user_id
                                                    ? form.data.status
                                                    : 'draft'
                                            }
                                            canSchedule={Boolean(
                                                form.data.user_id,
                                            )}
                                            onChange={(v) =>
                                                form.setData('status', v)
                                            }
                                        />
                                    </div>
                                    <FieldError
                                        message={form.errors.starts_at}
                                    />
                                    <FieldError message={form.errors.ends_at} />
                                    <FieldError
                                        message={stepErrors.starts_at}
                                    />
                                    <FieldError message={stepErrors.ends_at} />
                                </Section>
                            ) : null}

                            {cur.key === 'repeat' && !isEdit ? (
                                <Section
                                    first
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
                                                            <GuardrailButton
                                                                unstyled
                                                                key={d}
                                                                type="button"
                                                                onClick={() =>
                                                                    toggleWeekday(
                                                                        d,
                                                                    )
                                                                }
                                                                className={[
                                                                    'frontline-hit h-8 min-w-[44px] rounded-md px-3 text-xs font-semibold tabular-nums transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring',
                                                                    active
                                                                        ? 'bg-primary-fill text-primary-fill-foreground shadow-sm'
                                                                        : 'border border-border bg-card text-foreground hover:border-primary/40 hover:bg-primary/5',
                                                                ].join(' ')}
                                                            >
                                                                {
                                                                    WEEKDAY_LABEL[
                                                                        d
                                                                    ]
                                                                }
                                                            </GuardrailButton>
                                                        );
                                                    })}
                                                </div>
                                                <FieldError
                                                    message={
                                                        stepErrors.repeat_by_weekday
                                                    }
                                                />
                                            </div>
                                            <div className="grid items-end gap-3 sm:grid-cols-[1fr_auto]">
                                                <div>
                                                    <Label htmlFor="csd-rep-end">
                                                        Repeat end date
                                                    </Label>
                                                    <DatePicker
                                                        id="csd-rep-end"
                                                        label="Repeat end date"
                                                        value={
                                                            form.data
                                                                .repeat_end_date
                                                        }
                                                        timeZone={
                                                            workerTimezone
                                                        }
                                                        onChange={(date) =>
                                                            form.setData(
                                                                'repeat_end_date',
                                                                date,
                                                            )
                                                        }
                                                    />
                                                    <FieldError
                                                        message={
                                                            stepErrors.repeat_end_date
                                                        }
                                                    />
                                                </div>
                                                <div className="inline-flex h-9 items-center gap-2 self-end rounded-lg border border-primary/20 bg-primary/10 px-3 py-2 text-xs text-foreground">
                                                    <Sparkles className="h-3.5 w-3.5 text-primary" />
                                                    <span>
                                                        Multiple shifts will be
                                                        created across the date
                                                        range
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                    ) : null}
                                </Section>
                            ) : null}

                            {cur.key === 'tasks' ? (
                                <Section
                                    first
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
                                                    <GuardrailButton
                                                        unstyled
                                                        type="button"
                                                        onClick={addTask}
                                                        className="frontline-hit inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-medium text-primary hover:bg-primary/5"
                                                    >
                                                        <Plus className="h-3.5 w-3.5" />{' '}
                                                        Add
                                                    </GuardrailButton>
                                                ) : null}
                                            </div>
                                            {form.data.tasks.length === 0 ? (
                                                <GuardrailButton
                                                    unstyled
                                                    type="button"
                                                    onClick={addTask}
                                                    className="flex w-full items-center justify-center gap-2 rounded-lg border border-dashed border-border bg-muted/30 px-4 py-3 text-xs text-muted-foreground transition hover:border-primary/40 hover:bg-primary/5 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                                                >
                                                    <Plus className="h-3.5 w-3.5" />
                                                    Add the first task — e.g.
                                                    “Morning medication round”
                                                </GuardrailButton>
                                            ) : (
                                                <ul className="space-y-1.5">
                                                    {form.data.tasks.map(
                                                        (t, i) =>
                                                            t.can_edit ===
                                                            false ? (
                                                                <li
                                                                    key={`protected-${t.id}`}
                                                                    className="rounded-lg border border-border bg-muted/25 p-3"
                                                                >
                                                                    <p className="text-sm font-medium">
                                                                        {
                                                                            t.label
                                                                        }
                                                                    </p>
                                                                    <p className="text-xs text-muted-foreground">
                                                                        Linked
                                                                        care
                                                                        task
                                                                        {t.scheduled_time
                                                                            ? ` · ${displayTime(t.scheduled_time)}`
                                                                            : ''}
                                                                        .
                                                                        Managed
                                                                        in its
                                                                        source
                                                                        record.
                                                                    </p>
                                                                </li>
                                                            ) : (
                                                                <li
                                                                    key={
                                                                        t.id ??
                                                                        `new-${i}`
                                                                    }
                                                                    className="grid gap-2 rounded-lg border border-border/70 bg-background p-2 sm:grid-cols-[auto,minmax(0,1fr),auto,auto] sm:items-center"
                                                                >
                                                                    <span className="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-md bg-muted text-xs font-semibold text-muted-foreground tabular-nums">
                                                                        {i + 1}
                                                                    </span>
                                                                    <input
                                                                        className="input min-h-[44px] min-w-0"
                                                                        placeholder={`Task ${i + 1}`}
                                                                        aria-label={`Task ${i + 1} label`}
                                                                        value={
                                                                            t.label
                                                                        }
                                                                        onChange={(
                                                                            e,
                                                                        ) =>
                                                                            setTask(
                                                                                i,
                                                                                e
                                                                                    .target
                                                                                    .value,
                                                                            )
                                                                        }
                                                                    />
                                                                    <label className="inline-flex min-h-[44px] items-center gap-2 rounded-md border border-border px-2 text-xs whitespace-nowrap text-muted-foreground">
                                                                        <input
                                                                            type="checkbox"
                                                                            className="h-4 w-4 rounded border-border"
                                                                            checked={
                                                                                !!t.scheduled_time
                                                                            }
                                                                            onChange={(
                                                                                e,
                                                                            ) =>
                                                                                setTaskScheduled(
                                                                                    i,
                                                                                    e
                                                                                        .target
                                                                                        .checked
                                                                                        ? defaultTaskScheduledTime()
                                                                                        : null,
                                                                                )
                                                                            }
                                                                        />
                                                                        <span>
                                                                            Specific
                                                                            time
                                                                        </span>
                                                                    </label>
                                                                    {t.scheduled_time ? (
                                                                        <TimePicker
                                                                            id={`csd-task-time-${i}`}
                                                                            label={`Task ${i + 1} scheduled time`}
                                                                            value={
                                                                                t.scheduled_time
                                                                            }
                                                                            onChange={(
                                                                                time,
                                                                            ) =>
                                                                                setTaskScheduled(
                                                                                    i,
                                                                                    time,
                                                                                )
                                                                            }
                                                                        />
                                                                    ) : null}
                                                                    <GuardrailButton
                                                                        unstyled
                                                                        type="button"
                                                                        onClick={() =>
                                                                            removeTask(
                                                                                i,
                                                                            )
                                                                        }
                                                                        className="frontline-hit inline-flex h-9 w-9 items-center justify-center rounded-md text-muted-foreground hover:bg-muted hover:text-foreground"
                                                                        aria-label={`Remove task ${i + 1}`}
                                                                    >
                                                                        <Trash className="h-4 w-4" />
                                                                    </GuardrailButton>
                                                                </li>
                                                            ),
                                                    )}
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
                                                    · anything the worker should
                                                    know
                                                </span>
                                            </label>
                                            <textarea
                                                id="csd-notes"
                                                rows={4}
                                                className="textarea"
                                                placeholder="e.g. Prefers a quieter handover; check fridge for new medication."
                                                value={form.data.notes}
                                                onChange={(e) =>
                                                    form.setData(
                                                        'notes',
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                        </div>
                                    </div>
                                </Section>
                            ) : null}

                            {cur.key === 'review' ? (
                                <Section
                                    first
                                    icon={CheckCircle2}
                                    title="Review"
                                    hint={
                                        isEdit
                                            ? 'Confirm and save'
                                            : 'Confirm and create'
                                    }
                                >
                                    <div className="space-y-3">
                                        <div className="flex items-center gap-2.5 rounded-xl border border-primary/20 bg-primary/5 p-3">
                                            <span className="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary">
                                                <CalendarClock className="h-4 w-4" />
                                            </span>
                                            <div className="min-w-0">
                                                <div className="text-[10.5px] font-semibold tracking-wider text-muted-foreground uppercase">
                                                    {isEdit
                                                        ? 'Will update'
                                                        : 'Will create'}
                                                </div>
                                                <div className="truncate text-sm font-medium text-foreground">
                                                    {summary}
                                                </div>
                                            </div>
                                        </div>

                                        <div className="grid gap-3 sm:grid-cols-2">
                                            <ReviewCard
                                                icon={LayoutGrid}
                                                title="Shift details"
                                                onEdit={() => jumpTo('type')}
                                            >
                                                <ReviewRow
                                                    label="Type"
                                                    value={
                                                        SHIFT_TYPES.find(
                                                            (type) =>
                                                                type.key ===
                                                                form.data
                                                                    .shift_type,
                                                        )?.label ??
                                                        form.data.shift_type
                                                    }
                                                />
                                                <ReviewRow
                                                    label="Lone / remote worker"
                                                    value={
                                                        form.data.is_lone_worker
                                                            ? 'Yes'
                                                            : 'No'
                                                    }
                                                />
                                                <ReviewRow
                                                    label="Sleepover"
                                                    value={
                                                        form.data.is_sleepover
                                                            ? 'Yes'
                                                            : 'No'
                                                    }
                                                />
                                                <ReviewRow
                                                    label="On call"
                                                    value={
                                                        form.data.is_on_call
                                                            ? 'Yes'
                                                            : 'No'
                                                    }
                                                />
                                            </ReviewCard>
                                            <ReviewCard
                                                icon={Users}
                                                title="People and location"
                                                onEdit={() => jumpTo('people')}
                                            >
                                                <ReviewRow
                                                    label="Person supported"
                                                    value={
                                                        selectedClient
                                                            ? `${selectedClient.first_name} ${selectedClient.last_name}`
                                                            : 'Choose a person'
                                                    }
                                                />
                                                <ReviewRow
                                                    label="Staff"
                                                    value={
                                                        selectedStaff?.name ??
                                                        (form.data.user_id
                                                            ? `Staff #${form.data.user_id}`
                                                            : 'Open shift (unassigned)')
                                                    }
                                                />
                                                <ReviewRow
                                                    label="Location"
                                                    value={form.data.location}
                                                />
                                                <ReviewRow
                                                    label="Driving"
                                                    value={
                                                        [
                                                            form.data
                                                                .required_licence_class
                                                                ? `Class ${form.data.required_licence_class}`
                                                                : '',
                                                            form.data.required_licence_endorsements.join(
                                                                ', ',
                                                            ),
                                                        ]
                                                            .filter(Boolean)
                                                            .join(' · ') ||
                                                        'No requirement'
                                                    }
                                                />
                                                <ReviewRow
                                                    label="Coverage roles"
                                                    value={
                                                        form.data.coverage_roles.join(
                                                            ', ',
                                                        ) || 'None selected'
                                                    }
                                                />
                                            </ReviewCard>
                                            <ReviewCard
                                                icon={Clock}
                                                title="Schedule"
                                                onEdit={() =>
                                                    jumpTo('schedule')
                                                }
                                            >
                                                <ReviewRow
                                                    label="Start"
                                                    value={`${formatDateOnly(form.data.starts_at.slice(0, 10))} · ${displayTime(form.data.starts_at.slice(11, 16))}`}
                                                />
                                                <ReviewRow
                                                    label="End"
                                                    value={`${formatDateOnly(form.data.ends_at.slice(0, 10))} · ${displayTime(form.data.ends_at.slice(11, 16))}`}
                                                />
                                                <ReviewRow
                                                    label="Timezone"
                                                    value={workerTimezone}
                                                />
                                                <ReviewRow
                                                    label="Duration"
                                                    value={durationLabel}
                                                />
                                                <ReviewRow
                                                    label="Break"
                                                    value={
                                                        form.data
                                                            .expected_break_minutes ===
                                                        ''
                                                            ? 'Not set'
                                                            : `${form.data.expected_break_minutes} min`
                                                    }
                                                />
                                                <ReviewRow
                                                    label="Status"
                                                    value={
                                                        form.data.user_id &&
                                                        form.data.status ===
                                                            'scheduled'
                                                            ? 'Scheduled'
                                                            : 'Draft'
                                                    }
                                                />
                                            </ReviewCard>
                                            {!isEdit && (
                                                <ReviewCard
                                                    icon={Repeat}
                                                    title="Repeat"
                                                    onEdit={() =>
                                                        jumpTo('repeat')
                                                    }
                                                >
                                                    <ReviewRow
                                                        label="Schedule"
                                                        value={
                                                            form.data
                                                                .repeat_weekly
                                                                ? `Weekly on ${form.data.repeat_by_weekday.map((day) => WEEKDAY_LABEL[day]).join(', ')} until ${formatDateOnly(form.data.repeat_end_date)}`
                                                                : 'One-off shift'
                                                        }
                                                    />
                                                </ReviewCard>
                                            )}
                                            <ReviewCard
                                                icon={Pencil}
                                                title="Tasks and notes"
                                                onEdit={() => jumpTo('tasks')}
                                                span
                                            >
                                                {form.data.tasks
                                                    .filter((task) =>
                                                        task.label.trim(),
                                                    )
                                                    .map((task, index) => (
                                                        <ReviewRow
                                                            key={
                                                                task.id ??
                                                                `new-${index}`
                                                            }
                                                            label={`Task ${index + 1}${task.can_edit === false ? ' · linked' : ''}`}
                                                            value={`${task.label}${task.scheduled_time ? ` · ${displayTime(task.scheduled_time)}` : ''}`}
                                                        />
                                                    ))}
                                                <ReviewRow
                                                    label="Handover notes"
                                                    value={
                                                        form.data.notes ||
                                                        'No notes'
                                                    }
                                                />
                                            </ReviewCard>
                                        </div>

                                        {Object.keys(form.errors).length > 0 ? (
                                            <div className="rounded-lg border border-status-critical/35 bg-status-critical-bg p-3 text-xs">
                                                <div className="mb-1 font-semibold text-status-critical">
                                                    Fix before saving:
                                                </div>
                                                <ul className="list-inside list-disc space-y-0.5 text-foreground">
                                                    {Object.entries(
                                                        form.errors,
                                                    ).map(
                                                        ([field, message]) => (
                                                            <li key={field}>
                                                                {String(
                                                                    message,
                                                                )}
                                                            </li>
                                                        ),
                                                    )}
                                                </ul>
                                            </div>
                                        ) : null}

                                        {isEdit &&
                                        eligibilityStatus?.status ===
                                            'blocked' ? (
                                            <p className="text-xs text-status-critical">
                                                Resolve the eligibility blockers
                                                above before saving.
                                            </p>
                                        ) : null}
                                    </div>
                                </Section>
                            ) : null}
                        </fieldset>
                    </WizardStepPane>
                </form>
            </WizardShell>
            <ConfirmDialog
                open={discardOpen}
                onClose={() => setDiscardOpen(false)}
                onConfirm={onClose}
                title="Discard this shift draft?"
                description="Your unsaved changes will be lost."
                confirmText="Discard draft"
                cancelText="Keep editing"
            />
            <OverrideConfirmationDialog
                confirmLabel="Confirm and save"
                processingLabel="Saving…"
                open={overrideOpen}
                onOpenChange={setOverrideOpen}
                warnings={overrideWarnings}
                staffName={selectedStaff?.name}
                processing={busy}
                onConfirm={(reason) => {
                    setOverrideOpen(false);
                    submitForm(reason);
                }}
            />
        </>
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
                    <GuardrailButton
                        unstyled
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
                            <span className="absolute top-2 right-2 inline-flex h-4 w-4 items-center justify-center rounded-full bg-primary-fill text-primary-fill-foreground">
                                <Check className="h-3 w-3" strokeWidth={3} />
                            </span>
                        ) : null}
                    </GuardrailButton>
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
    workerTimezone,
}: {
    startsAt: string;
    endsAt: string;
    breakMinutes: string;
    onStartsAtChange: (value: string) => void;
    onEndsAtChange: (value: string) => void;
    onBreakChange: (value: string) => void;
    duration: string;
    workerTimezone: string;
}) {
    return (
        <div className="space-y-4">
            <p className="text-sm text-muted-foreground">
                All dates and times use {workerTimezone}. Duration: {duration}.
            </p>
            <div className="grid gap-4 sm:grid-cols-2">
                <div className="space-y-2">
                    <Label required>Start</Label>
                    <DatePicker
                        id="csd-start-date"
                        label="Start date"
                        value={startsAt.slice(0, 10)}
                        timeZone={workerTimezone}
                        onChange={(date) =>
                            onStartsAtChange(
                                `${date}T${startsAt.slice(11, 16) || '09:00'}`,
                            )
                        }
                    />
                    <TimePicker
                        id="csd-start-time"
                        label="Start time"
                        value={startsAt.slice(11, 16)}
                        onChange={(time) =>
                            onStartsAtChange(`${startsAt.slice(0, 10)}T${time}`)
                        }
                    />
                </div>
                <div className="space-y-2">
                    <Label required>End</Label>
                    <DatePicker
                        id="csd-end-date"
                        label="End date"
                        value={endsAt.slice(0, 10)}
                        timeZone={workerTimezone}
                        onChange={(date) =>
                            onEndsAtChange(
                                `${date}T${endsAt.slice(11, 16) || '17:00'}`,
                            )
                        }
                    />
                    <TimePicker
                        id="csd-end-time"
                        label="End time"
                        value={endsAt.slice(11, 16)}
                        onChange={(time) =>
                            onEndsAtChange(`${endsAt.slice(0, 10)}T${time}`)
                        }
                    />
                </div>
            </div>
            <div>
                <Label htmlFor="csd-break">Break (minutes)</Label>
                <input
                    id="csd-break"
                    type="number"
                    min={0}
                    max={720}
                    className="input min-h-[44px]"
                    value={breakMinutes}
                    onChange={(event) => onBreakChange(event.target.value)}
                />
            </div>
        </div>
    );
}

function StatusPicker({
    value,
    canSchedule,
    onChange,
}: {
    value: 'draft' | 'scheduled';
    canSchedule: boolean;
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
            hint: canSchedule
                ? 'Publish to the worker.'
                : 'Assign a staff member first.',
        },
    ];
    return (
        <div className="grid grid-cols-2 gap-2">
            {options.map((o) => {
                const active = value === o.key;
                const Icon = o.icon;
                return (
                    <GuardrailButton
                        unstyled
                        key={o.key}
                        type="button"
                        onClick={() => onChange(o.key)}
                        disabled={o.key === 'scheduled' && !canSchedule}
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
                                    ? 'bg-primary-fill text-primary-fill-foreground'
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
                    </GuardrailButton>
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
        <GuardrailButton
            unstyled
            type="button"
            role="switch"
            aria-checked={value}
            aria-label={ariaLabel}
            onClick={() => onChange(!value)}
            className={[
                'frontline-hit relative h-5 w-9 rounded-full transition',
                value ? 'bg-primary' : 'bg-muted',
            ].join(' ')}
        >
            <span
                className={[
                    'absolute top-0.5 h-4 w-4 rounded-full bg-white shadow transition-transform',
                    value ? 'translate-x-4' : 'translate-x-0.5',
                ].join(' ')}
            />
        </GuardrailButton>
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
            Service context: <span className="text-foreground">{ctx.name}</span>{' '}
            (inherited)
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
                        . Confirm the client and staff so coverage closes
                        safely.
                    </p>
                ) : null}
                {context.role_shortages && context.role_shortages.length ? (
                    <div className="mt-1.5 flex flex-wrap gap-1">
                        {context.role_shortages.map((role) => (
                            <span
                                key={role.key}
                                className="inline-flex items-center rounded-full bg-status-warning-bg px-2 py-0.5 text-[11px] font-medium text-status-warning"
                            >
                                {role.label ?? role.key}
                                {role.missing ? ` · ${role.missing} short` : ''}
                            </span>
                        ))}
                    </div>
                ) : null}
            </div>
        </div>
    );
}
