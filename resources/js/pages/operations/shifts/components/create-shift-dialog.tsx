import { router, useForm } from '@inertiajs/react';
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
    X,
    type LucideIcon,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';

import { EligibilityAlertBanner } from '@/components/eligibility/eligibility-alert-banner';
import {
    EligibilityStatusBadge,
    deriveEligibilityStatus,
} from '@/components/eligibility/eligibility-status-badge';
import {
    OverrideConfirmationDialog,
    type OverrideableWarning,
} from '@/components/eligibility/override-confirmation-dialog';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    SHIFT_TYPES,
    SHIFT_TYPE_ACCENT_CLASSES,
    type ShiftTypeKey,
} from '@/lib/shift-types';
import { cn } from '@/lib/utils';
import {
    eligibility_preview as eligibilityPreview,
    store as storeShift,
    update as updateShift,
} from '@/routes/operations/shifts';
import { store as storeShiftSeries } from '@/routes/operations/shifts/series';
import * as VisuallyHidden from '@radix-ui/react-visually-hidden';
import { Button as GuardrailButton } from '@/components/ui/button';
import { Card as GuardrailCard } from '@/components/ui/card';

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

type WizStepKey = 'type' | 'people' | 'schedule' | 'repeat' | 'tasks' | 'review';

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
    }>;
};

type ShiftDialogTask = {
    id?: number;
    label: string;
    scheduled_time: string | null;
};

type EligibilityPreview = {
    is_eligible?: boolean;
    is_allowed?: boolean;
    blocked_reasons?: string[];
    warning_reasons?: string[];
    overrideable_warnings?: OverrideableWarning[];
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
    if (/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d+)?)?$/.test(value)) {
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

// `<input type="datetime-local">` emits naive wall time like
// "2026-05-30T09:00". The server's Carbon::parse() reads that as UTC, so
// we convert through a Date (which interprets naive strings as local) and
// emit an ISO string with the offset Carbon can normalise correctly.
function localDatetimeInputToIso(
    value: string | null | undefined,
): string | null {
    if (!value || !/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/.test(value)) return null;
    const d = new Date(value);
    if (Number.isNaN(d.getTime())) return null;
    return d.toISOString();
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
    defaultUserId = null,
    lockedContext = null,
    coverageReservationToken = null,
    coverageRuleId = null,
    defaultCoverageRoles,
    defaultRepeatWeekly = false,
    defaultRepeatEndDate = null,
    initialShift = null,
}: Props) {
    const isEdit = !!initialShift;
    const initialClient = useMemo(() => {
        if (initialShift?.client?.id) {
            const found = clients.find((c) => c.id === initialShift.client?.id);
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
    }, [clients, defaultClientId, defaultSiteId, initialShift?.client?.id]);

    // Every client lives at a site — the location field follows it (the
    // coordinator can still type a custom location for community shifts).
    const siteNameFor = (siteId?: number | null) =>
        sites.find((s) => s.id === siteId)?.name ?? '';

    const form = useForm({
        client_id: (initialShift?.client?.id ?? initialClient?.id ?? '') as
            | number
            | '',
        service_context_id: (initialShift?.service_context_id ??
            initialClient?.service_context_id ??
            defaultServiceContextId ??
            '') as number | '',
        user_id: (initialShift?.staff?.id ?? defaultUserId ?? '') as
            | number
            | '',
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
                : '30',
        // Hydrate from initialShift in edit mode so submitting doesn't wipe
        // existing coverage roles / tasks on the server. We keep the task id
        // for existing rows so syncShiftTasks updates them in place instead of
        // recreating them.
        coverage_roles: (initialShift?.coverage_roles ??
            defaultCoverageRoles ??
            []) as string[],
        required_licence_class:
            initialShift?.required_licence_class ?? '',
        required_licence_endorsements:
            initialShift?.required_licence_endorsements ?? ([] as string[]),
        coverage_rule_id: (coverageRuleId ?? '') as number | string,
        coverage_reservation_token: (coverageReservationToken ?? '') as string,
        tasks: (initialShift?.tasks?.map((t) => ({
            id: t.id,
            label: t.label,
            scheduled_time: t.scheduled_time ?? null,
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
                    { key: 'type', label: 'Shift type', blurb: 'What kind of shift', icon: LayoutGrid },
                    { key: 'people', label: 'Who & where', blurb: 'Client, location, staff', icon: Users },
                    { key: 'schedule', label: 'Schedule', blurb: 'Times, break, publish', icon: Clock },
                    { key: 'repeat', label: 'Repeat weekly', blurb: 'Optional recurring series', icon: Repeat },
                    { key: 'tasks', label: 'Tasks & notes', blurb: 'Worker checklist', icon: Pencil },
                    {
                        key: 'review',
                        label: 'Review',
                        blurb: isEdit ? 'Confirm and save' : 'Confirm and create',
                        icon: CheckCircle2,
                    },
                ] as { key: WizStepKey; label: string; blurb: string; icon: LucideIcon }[]
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
        const next = [...form.data.tasks];
        next[i] = { ...next[i], label };
        form.setData('tasks', next);
    }
    function setTaskScheduled(i: number, scheduled_time: string | null) {
        const next = [...form.data.tasks];
        next[i] = { ...next[i], scheduled_time };
        form.setData('tasks', next);
    }
    function defaultTaskScheduledTime() {
        return form.data.starts_at?.slice(11, 16) || '09:00';
    }
    function removeTask(i: number) {
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
            const a = new Date(form.data.starts_at).getTime();
            const b = new Date(form.data.ends_at).getTime();
            if (a && b && b > a) return `${((b - a) / 3_600_000).toFixed(1)}h`;
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
            if (
                !Number.isNaN(startDate.getTime()) &&
                !Number.isNaN(endDate.getTime())
            ) {
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
    const selectedStaff = staff.find((s) => s.id === Number(form.data.user_id));

    const [eligPreview, setEligPreview] = useState<EligibilityPreview | null>(
        null,
    );
    const [eligLoading, setEligLoading] = useState(false);
    const [overrideOpen, setOverrideOpen] = useState(false);
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
              rule: 'unknown',
              message,
              overrideable: true,
          }));

    const fetchEligibility = useCallback(() => {
        const userId = form.data.user_id;
        const startsAt = form.data.starts_at;
        const endsAt = form.data.ends_at;

        if (!userId || !startsAt || !endsAt) {
            setEligPreview(null);
            setEligLoading(false);
            return;
        }

        if (eligTimer.current) clearTimeout(eligTimer.current);
        eligTimer.current = setTimeout(async () => {
            eligAbort.current?.abort();
            const controller = new AbortController();
            eligAbort.current = controller;
            setEligLoading(true);

            try {
                const query: Record<string, string | string[]> = {
                    user_id: String(userId),
                    starts_at: localDatetimeInputToIso(startsAt) ?? startsAt,
                    ends_at: localDatetimeInputToIso(endsAt) ?? endsAt,
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
                if (!controller.signal.aborted) setEligPreview(null);
            } finally {
                if (!controller.signal.aborted) setEligLoading(false);
            }
        }, 500);
    }, [
        form.data.user_id,
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

    function submitForm(overrideReason?: string) {
        // Round-trip the current URL so the server's redirect after save
        // lands the user back on the same week / filter combo instead of
        // resetting to the default index. Also convert the local
        // <input type="datetime-local"> values to an ISO string with the
        // browser's timezone offset — Carbon::parse() on the server then
        // stores the correct UTC instant. Otherwise the naive local time
        // is interpreted as UTC and the saved shift drifts by the user's
        // offset (e.g. NZST shifts get pushed 12 hours into the future).
        form.transform((data) => {
            const payload = {
                ...data,
                starts_at:
                    localDatetimeInputToIso(data.starts_at) ?? data.starts_at,
                ends_at:
                    localDatetimeInputToIso(data.ends_at) ?? data.ends_at,
                return_to:
                    typeof window !== 'undefined'
                        ? window.location.pathname + window.location.search
                        : data.return_to,
                override_acknowledged: Boolean(overrideReason),
                override_reason: overrideReason ?? '',
            };

            if (
                !isEdit &&
                !data.required_licence_class &&
                data.required_licence_endorsements.length === 0
            ) {
                const {
                    required_licence_class: _class,
                    required_licence_endorsements: _endorsements,
                    ...ordinaryShift
                } = payload;
                return ordinaryShift;
            }

            return payload;
        });
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
        router.post(
            storeShiftSeries.url(),
            {
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
                preserveScroll: true,
                onSuccess: () => onClose(),
            },
        );
    }

    // Per-step client-side gates — the server stays authoritative; these only
    // stop an obviously-incomplete step from advancing.
    function validateStep(key: WizStepKey): boolean {
        const errs: Record<string, string> = {};
        if (key === 'people' && !form.data.client_id) {
            errs.client_id = 'Choose a client';
        }
        if (key === 'schedule') {
            if (!form.data.starts_at) errs.starts_at = 'Start time is required';
            if (!form.data.ends_at) errs.ends_at = 'End time is required';
            if (
                form.data.starts_at &&
                form.data.ends_at &&
                new Date(form.data.ends_at).getTime() <=
                    new Date(form.data.starts_at).getTime()
            ) {
                errs.ends_at = 'End must be after the start';
            }
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
        if (
            form.data.starts_at &&
            form.data.ends_at &&
            new Date(form.data.ends_at).getTime() >
                new Date(form.data.starts_at).getTime()
        ) {
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
    ]);

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        // On every step except review, the primary action advances the
        // wizard — this also keeps Cmd/Ctrl+Enter working per step.
        if (cur.key !== 'review') {
            goNext();
            return;
        }
        if (isEdit && eligibilityStatus?.status === 'blocked') {
            return;
        }
        if (
            isEdit &&
            eligibilityStatus?.status === 'warnings' &&
            eligibilityWarnings.length > 0
        ) {
            setOverrideOpen(true);
            return;
        }
        submitForm();
    }

    return (
        <>
            <Dialog open={open} onOpenChange={(o) => (!o ? onClose() : null)}>
                <DialogContent
                    className="flex h-[min(820px,92vh)] !w-full !max-w-[min(96vw,1080px)] flex-col gap-0 overflow-hidden !rounded-2xl !p-0 md:flex-row [&>button]:hidden"
                    onInteractOutside={(e) => e.preventDefault()}
                >
                    <VisuallyHidden.Root>
                        <DialogTitle>
                            {isEdit
                                ? `Edit shift #${initialShift?.id}`
                                : 'Create shift'}
                        </DialogTitle>
                        <DialogDescription>
                            {isEdit
                                ? 'Update the schedule, staff, tasks or notes for this shift.'
                                : 'Schedule an appointment or rostered shift. Add tasks and optionally repeat weekly.'}
                        </DialogDescription>
                    </VisuallyHidden.Root>

                    {/* Stepper rail */}
                    <aside className="hidden w-[248px] shrink-0 flex-col border-r border-sidebar-border bg-sidebar p-4 md:flex">
                        <div className="mb-4 flex items-center gap-2.5">
                            <span className="flex h-9 w-9 items-center justify-center rounded-xl bg-primary/15 text-primary">
                                <CalendarClock className="h-4.5 w-4.5" />
                            </span>
                            <div className="min-w-0">
                                <h2 className="text-sm font-bold">
                                    {isEdit ? 'Edit shift' : 'Create shift'}
                                </h2>
                                <div className="truncate text-[11.5px] text-muted-foreground">
                                    {isEdit
                                        ? `Shift #${initialShift?.id}`
                                        : 'Roster a new shift'}
                                </div>
                            </div>
                        </div>
                        <div className="flex flex-1 flex-col gap-1">
                            {WIZ_STEPS.map((s, i) => {
                                const Icon = s.icon;
                                const active = i === stepIndex;
                                const done = i < stepIndex;
                                return (
                                    <GuardrailButton unstyled
                                        key={s.key}
                                        type="button"
                                        onClick={() => {
                                            setStepErrors({});
                                            setStepIndex(i);
                                        }}
                                        className={cn(
                                            'flex items-start gap-2.5 rounded-lg px-2.5 py-2 text-left transition-colors',
                                            active
                                                ? 'bg-primary/10'
                                                : 'hover:bg-accent',
                                        )}
                                    >
                                        <span
                                            className={cn(
                                                'flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-[11px] font-bold',
                                                active
                                                    ? 'bg-primary text-primary-foreground'
                                                    : done
                                                      ? 'bg-status-success-bg text-status-success'
                                                      : 'bg-muted text-muted-foreground',
                                            )}
                                        >
                                            {done ? (
                                                <Check className="h-3.5 w-3.5" />
                                            ) : (
                                                <Icon className="h-3.5 w-3.5" />
                                            )}
                                        </span>
                                        <span className="min-w-0">
                                            <span className="block text-[13px] leading-tight font-semibold">
                                                {s.label}
                                            </span>
                                            <span className="block text-[11px] text-muted-foreground">
                                                {s.blurb}
                                            </span>
                                        </span>
                                    </GuardrailButton>
                                );
                            })}
                        </div>
                        <GuardrailCard unstyled className="mt-3 rounded-lg border border-border bg-card p-3">
                            <div className="flex items-center justify-between text-[11.5px] font-semibold">
                                <span>Shift readiness</span>
                                <span className="tabular-nums">
                                    {readinessPct}%
                                </span>
                            </div>
                            <div className="mt-1.5 h-1.5 overflow-hidden rounded-full bg-muted">
                                <div
                                    className="h-full rounded-full bg-primary transition-all"
                                    style={{ width: `${readinessPct}%` }}
                                />
                            </div>
                        </GuardrailCard>
                    </aside>

                    {/* Main panel */}
                    <form
                        data-shifts-create
                        onSubmit={handleSubmit}
                        className="flex min-w-0 flex-1 flex-col"
                    >
                        <header className="flex items-center justify-between border-b border-border px-5 py-3">
                            <div className="text-[12.5px] text-muted-foreground">
                                Step {stepIndex + 1} of {WIZ_STEPS.length} ·{' '}
                                <b className="text-foreground">{cur.label}</b>
                            </div>
                            <div className="flex shrink-0 items-center gap-2">
                                <span className="hidden items-center gap-1 rounded-md border border-border px-1.5 py-1 text-[10.5px] text-muted-foreground sm:inline-flex">
                                    <kbd className="font-sans font-semibold">
                                        ⌘
                                    </kbd>
                                    <kbd className="font-sans font-semibold">
                                        ↵
                                    </kbd>
                                    <span>to continue</span>
                                </span>
                                <GuardrailButton unstyled
                                    type="button"
                                    onClick={onClose}
                                    aria-label="Close dialog"
                                    className="rounded-md p-1 text-muted-foreground hover:bg-accent hover:text-foreground"
                                >
                                    <X className="h-4.5 w-4.5" />
                                </GuardrailButton>
                            </div>
                        </header>
                        <div className="h-[3px] shrink-0 bg-muted">
                            <div
                                className="h-full bg-primary transition-all"
                                style={{
                                    width: `${((stepIndex + 1) / WIZ_STEPS.length) * 100}%`,
                                }}
                            />
                        </div>

                        {/* Body */}
                        <div className="flex-1 overflow-y-auto px-6 py-4">
                            {lockedContext ? (
                                <LockedContextCard context={lockedContext} />
                            ) : null}

                            {(cur.key === 'people' || cur.key === 'review') &&
                            form.data.user_id ? (
                                <GuardrailCard unstyled className="mb-4 space-y-2 rounded-xl border border-border bg-card p-3">
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
                                                Safety monitoring — it surfaces in
                                                the watch-tower as a shift needing
                                                a check-in session.
                                            </span>
                                        </span>
                                    </label>
                                </Section>
                            ) : null}

                            {cur.key === 'people' ? (
                            <Section first icon={Users} title="Who & where">
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
                                            className="input"
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
                                                        : (Number(
                                                              e.target.value,
                                                          ) as number),
                                                )
                                            }
                                        >
                                            <option value="">
                                                Unassigned (create an open
                                                shift)
                                            </option>
                                            {staff.map((s) => (
                                                <option key={s.id} value={s.id}>
                                                    {s.name}
                                                </option>
                                            ))}
                                        </select>
                                        {!form.data.user_id ? (
                                            <p className="mt-1 text-xs text-muted-foreground">
                                                Leave blank to publish as an
                                                open shift — staff can be
                                                assigned later from Rostering.
                                            </p>
                                        ) : null}
                                        <FieldError
                                            message={form.errors.user_id}
                                        />
                                    </div>

                                    <div className="sm:col-span-2 rounded-xl border border-border bg-muted/25 p-3">
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
                                                    className="select"
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
                                                                {licenceClass}
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
                                                                        'min-h-9 rounded-md border px-2.5 text-xs font-semibold transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring',
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
                                    <Label required>Publish as</Label>
                                    <StatusPicker
                                        value={form.data.status}
                                        onChange={(v) =>
                                            form.setData('status', v)
                                        }
                                    />
                                </div>
                                <FieldError message={form.errors.starts_at} />
                                <FieldError message={form.errors.ends_at} />
                                <FieldError message={stepErrors.starts_at} />
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
                                                            <GuardrailButton unstyled
                                                                key={d}
                                                                type="button"
                                                                onClick={() =>
                                                                    toggleWeekday(
                                                                        d,
                                                                    )
                                                                }
                                                                className={[
                                                                    'h-8 min-w-[44px] rounded-md px-3 text-xs font-semibold tabular-nums transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring',
                                                                    active
                                                                        ? 'bg-primary text-primary-foreground shadow-sm'
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
                                                    <input
                                                        id="csd-rep-end"
                                                        type="date"
                                                        className="input"
                                                        value={
                                                            form.data
                                                                .repeat_end_date
                                                        }
                                                        onChange={(e) =>
                                                            form.setData(
                                                                'repeat_end_date',
                                                                e.target.value,
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
                                                <GuardrailButton unstyled
                                                    type="button"
                                                    onClick={addTask}
                                                    className="inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-medium text-primary hover:bg-primary/5"
                                                >
                                                    <Plus className="h-3.5 w-3.5" />{' '}
                                                    Add
                                                </GuardrailButton>
                                            ) : null}
                                        </div>
                                        {form.data.tasks.length === 0 ? (
                                            <GuardrailButton unstyled
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
                                                {form.data.tasks.map((t, i) => (
                                                    <li
                                                        key={i}
                                                        className="grid gap-2 rounded-lg border border-border/70 bg-background p-2 sm:grid-cols-[auto,minmax(0,1fr),auto,auto] sm:items-center"
                                                    >
                                                        <span className="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-md bg-muted text-xs font-semibold text-muted-foreground tabular-nums">
                                                            {i + 1}
                                                        </span>
                                                        <input
                                                            className="input min-w-0"
                                                            placeholder={`Task ${i + 1}`}
                                                            value={t.label}
                                                            onChange={(e) =>
                                                                setTask(
                                                                    i,
                                                                    e.target
                                                                        .value,
                                                                )
                                                            }
                                                        />
                                                        <label className="inline-flex h-9 items-center gap-2 rounded-md border border-border px-2 text-xs whitespace-nowrap text-muted-foreground">
                                                            <input
                                                                type="checkbox"
                                                                className="h-4 w-4 rounded border-border"
                                                                checked={
                                                                    !!t.scheduled_time
                                                                }
                                                                onChange={(e) =>
                                                                    setTaskScheduled(
                                                                        i,
                                                                        e.target
                                                                            .checked
                                                                            ? defaultTaskScheduledTime()
                                                                            : null,
                                                                    )
                                                                }
                                                            />
                                                            <span>
                                                                Specific time
                                                            </span>
                                                        </label>
                                                        {t.scheduled_time ? (
                                                            <input
                                                                type="time"
                                                                aria-label={`Task ${i + 1} scheduled time`}
                                                                className="input h-9 w-full sm:w-[7.5rem]"
                                                                value={
                                                                    t.scheduled_time
                                                                }
                                                                onChange={(e) =>
                                                                    setTaskScheduled(
                                                                        i,
                                                                        e.target
                                                                            .value ||
                                                                            null,
                                                                    )
                                                                }
                                                            />
                                                        ) : null}
                                                        <GuardrailButton unstyled
                                                            type="button"
                                                            onClick={() =>
                                                                removeTask(i)
                                                            }
                                                            className="inline-flex h-9 w-9 items-center justify-center rounded-md text-muted-foreground hover:bg-muted hover:text-foreground"
                                                            aria-label={`Remove task ${i + 1}`}
                                                        >
                                                            <Trash className="h-4 w-4" />
                                                        </GuardrailButton>
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

                                        <dl className="grid gap-x-6 gap-y-3 rounded-xl border border-border bg-card p-4 sm:grid-cols-2">
                                            <ReviewRow
                                                label="Shift type"
                                                value={
                                                    SHIFT_TYPES.find(
                                                        (t) =>
                                                            t.key ===
                                                            form.data
                                                                .shift_type,
                                                    )?.label ??
                                                    form.data.shift_type
                                                }
                                                onEdit={() => jumpTo('type')}
                                            />
                                            <ReviewRow
                                                label="Client"
                                                value={
                                                    selectedClient
                                                        ? `${selectedClient.first_name} ${selectedClient.last_name}`.trim()
                                                        : '—'
                                                }
                                                onEdit={() => jumpTo('people')}
                                            />
                                            <ReviewRow
                                                label="Staff"
                                                value={
                                                    selectedStaff?.name ??
                                                    'Open shift (unassigned)'
                                                }
                                                onEdit={() => jumpTo('people')}
                                            />
                                            <ReviewRow
                                                label="Driving requirement"
                                                value={
                                                    form.data
                                                        .required_licence_class ||
                                                    form.data
                                                        .required_licence_endorsements
                                                        .length
                                                        ? [
                                                              form.data
                                                                  .required_licence_class
                                                                  ? `Class ${form.data.required_licence_class}`
                                                                  : null,
                                                              form.data.required_licence_endorsements
                                                                  .length
                                                                  ? `${form.data.required_licence_endorsements.join(', ')} endorsement${form.data.required_licence_endorsements.length === 1 ? '' : 's'}`
                                                                  : null,
                                                          ]
                                                              .filter(Boolean)
                                                              .join(' · ')
                                                        : 'None'
                                                }
                                                onEdit={() => jumpTo('people')}
                                            />
                                            <ReviewRow
                                                label="Location"
                                                value={
                                                    form.data.location || '—'
                                                }
                                                onEdit={() => jumpTo('people')}
                                            />
                                            <ReviewRow
                                                label="Schedule"
                                                value={`${form.data.starts_at.replace('T', ' ')} → ${form.data.ends_at.replace('T', ' ')} · ${durationLabel}`}
                                                onEdit={() =>
                                                    jumpTo('schedule')
                                                }
                                            />
                                            <ReviewRow
                                                label="Break · publish"
                                                value={`${form.data.expected_break_minutes || 0} min · ${form.data.status === 'draft' ? 'Draft' : 'Scheduled'}`}
                                                onEdit={() =>
                                                    jumpTo('schedule')
                                                }
                                            />
                                            {!isEdit ? (
                                                <ReviewRow
                                                    label="Repeat"
                                                    value={
                                                        form.data.repeat_weekly
                                                            ? `Weekly on ${form.data.repeat_by_weekday.map((d) => WEEKDAY_LABEL[d]).join(', ')} until ${form.data.repeat_end_date || '—'}`
                                                            : 'One-off shift'
                                                    }
                                                    onEdit={() =>
                                                        jumpTo('repeat')
                                                    }
                                                />
                                            ) : null}
                                            <ReviewRow
                                                label="Tasks · notes"
                                                value={`${
                                                    form.data.tasks.filter(
                                                        (t) =>
                                                            t.label.trim() !==
                                                            '',
                                                    ).length
                                                } task${form.data.tasks.filter((t) => t.label.trim() !== '').length === 1 ? '' : 's'}${form.data.notes ? ' · has handover notes' : ''}`}
                                                onEdit={() => jumpTo('tasks')}
                                            />
                                        </dl>

                                        {Object.keys(form.errors).length >
                                        0 ? (
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
                                                Resolve the eligibility
                                                blockers above before saving.
                                            </p>
                                        ) : null}
                                    </div>
                                </Section>
                            ) : null}
                        </div>

                        {/* Footer */}
                        <footer className="flex items-center justify-between gap-2 border-t border-border bg-muted/30 px-5 py-3.5">
                            <div>
                                {stepIndex > 0 ? (
                                    <GuardrailButton unstyled
                                        type="button"
                                        onClick={goBack}
                                        className="inline-flex items-center gap-1 rounded-lg px-3 py-2 text-xs font-semibold text-muted-foreground hover:bg-accent hover:text-foreground"
                                    >
                                        <ChevronLeft className="h-4 w-4" />
                                        Back
                                    </GuardrailButton>
                                ) : null}
                            </div>
                            <div className="flex shrink-0 items-center gap-2">
                                <GuardrailButton unstyled
                                    type="button"
                                    onClick={onClose}
                                    className="rounded-lg border border-border bg-background px-3 py-2 text-xs font-semibold transition-colors hover:bg-accent"
                                >
                                    Cancel
                                </GuardrailButton>
                                {cur.key === 'review' ? (
                                    <button
                                        type="submit"
                                        disabled={form.processing}
                                        className="inline-flex items-center gap-1.5 rounded-lg bg-primary px-3.5 py-2 text-xs font-semibold text-primary-foreground transition-colors hover:bg-primary/90 disabled:cursor-not-allowed disabled:opacity-70"
                                    >
                                        {form.processing ? (
                                            <>
                                                <Loader2 className="h-3.5 w-3.5 animate-spin" />
                                                Saving…
                                            </>
                                        ) : (
                                            <>
                                                <Check className="h-3.5 w-3.5" />
                                                {isEdit
                                                    ? 'Save changes'
                                                    : 'Create shift'}
                                            </>
                                        )}
                                    </button>
                                ) : (
                                    <button
                                        type="submit"
                                        className="inline-flex items-center gap-1.5 rounded-lg bg-primary px-3.5 py-2 text-xs font-semibold text-primary-foreground transition-colors hover:bg-primary/90"
                                    >
                                        Continue
                                        <ChevronRight className="h-4 w-4" />
                                    </button>
                                )}
                            </div>
                        </footer>
                    </form>
                </DialogContent>
            </Dialog>
            <OverrideConfirmationDialog
                open={overrideOpen}
                onOpenChange={setOverrideOpen}
                warnings={overrideWarnings}
                staffName={selectedStaff?.name}
                processing={form.processing}
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

function ReviewRow({
    label,
    value,
    onEdit,
}: {
    label: string;
    value: string;
    onEdit: () => void;
}) {
    return (
        <div className="flex items-start justify-between gap-3">
            <div className="min-w-0">
                <dt className="text-[10.5px] font-semibold tracking-wider text-muted-foreground uppercase">
                    {label}
                </dt>
                <dd className="mt-0.5 text-[13px] text-foreground">{value}</dd>
            </div>
            <GuardrailButton unstyled
                type="button"
                onClick={onEdit}
                className="shrink-0 rounded-md px-1.5 py-0.5 text-xs font-medium text-primary hover:bg-primary/5"
            >
                Edit
            </GuardrailButton>
        </div>
    );
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
                    <GuardrailButton unstyled
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
                            <span className="absolute top-2 right-2 inline-flex h-4 w-4 items-center justify-center rounded-full bg-primary text-primary-foreground">
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
                <div className="text-[10.5px] font-semibold tracking-wider text-muted-foreground uppercase">
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
                    <GuardrailButton unstyled
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
        <GuardrailButton unstyled
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
