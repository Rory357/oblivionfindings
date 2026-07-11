/* eslint-disable no-restricted-syntax -- Mirrors the Add-Client / leave-request
 * wizard chrome: the footer nav, category tiles and review surface use styled
 * native buttons + inputs, and the per-category accent tints use color-mix() on
 * semantic CSS tokens (var(--category-*), var(--status-*)) via inline style —
 * that IS the design-token system, not a raw Tailwind colour class. */
import { useForm } from '@inertiajs/react';
import {
    AlarmClock,
    Archive,
    ArrowRight,
    Bell,
    Building2,
    CalendarRange,
    ClipboardCheck,
    GraduationCap,
    Megaphone,
    Paperclip,
    PartyPopper,
    Repeat,
    Users,
    X,
    type LucideIcon,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';

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
import { FileDropzone, StagedFileCard } from '@/components/ui/file-dropzone';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';
import { PeoplePicker, type PersonOption } from '../people-picker';
import {
    Field,
    ReviewCard,
    ReviewRow,
    Segmented,
    SelectInput,
    StepHead,
    SubHead,
    useWizard,
    WizardShell,
    WizardStepPane,
    WizardSuccessPane,
    type WizardStep,
} from './../wizard';

export interface CalendarEventInitial {
    id: number;
    title: string;
    description: string | null;
    event_type: string;
    starts_at: string;
    ends_at: string;
    is_all_day: boolean;
    location: string | null;
    department_id: number | null;
    site_id: number | null;
    rrule?: string | null;
    recurrence_until?: string | null;
    audience_type?: 'org' | 'site' | 'department' | 'people' | null;
    audience_user_ids?: number[];
    reminders?: { offset_minutes: number; channel: string }[];
    attachments?: EventAttachment[];
    /** Set when editing a single occurrence of a recurring series. */
    scope?: 'all' | 'this' | 'following';
    occurrence_date?: string | null;
}

type IdName = { id: number; name: string };

export interface EventCategoryOption {
    id: number;
    key: string;
    label: string;
    icon: string | null;
    color_token: string;
}

export interface EventAttachment {
    id: number;
    name: string;
    mime: string | null;
    size: number;
    url: string;
}

/** Read Laravel's XSRF cookie for non-Inertia multipart uploads. */
function xsrfToken(): string {
    const match = document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='));
    return match ? decodeURIComponent(match.split('=')[1]) : '';
}

const STEPS: readonly WizardStep[] = [
    { key: 'basics', label: 'Basics', blurb: 'Title & type', icon: Megaphone },
    { key: 'when', label: 'When', blurb: 'Dates & times', icon: CalendarRange },
    { key: 'who', label: 'Who & where', blurb: 'Audience & place', icon: Users },
    { key: 'details', label: 'Details', blurb: 'Reminders & files', icon: Bell },
    { key: 'review', label: 'Review', blurb: 'Confirm & save', icon: ClipboardCheck },
];

/** Reminder lead-time presets the user can toggle. */
const REMINDER_PRESETS: { minutes: number; label: string }[] = [
    { minutes: 0, label: 'At start' },
    { minutes: 10, label: '10 min before' },
    { minutes: 60, label: '1 hour before' },
    { minutes: 1440, label: '1 day before' },
];
const reminderLabel = (m: number): string =>
    REMINDER_PRESETS.find((p) => p.minutes === m)?.label ?? `${m} min before`;

/** Client-side icon + sublabel per known category key (DB stores icon by name). */
const CATEGORY_STYLE: Record<string, { icon: LucideIcon; sub: string }> = {
    company: { icon: Building2, sub: 'Org-wide notice' },
    team: { icon: Users, sub: 'For a team or site' },
    training: { icon: GraduationCap, sub: 'Course or session' },
    social: { icon: PartyPopper, sub: 'Get-together' },
    holiday: { icon: CalendarRange, sub: 'Closure or obs.' },
};
const styleFor = (key: string) => CATEGORY_STYLE[key] ?? { icon: CalendarRange, sub: '' };

type CategoryMeta = { value: string; label: string; icon: LucideIcon; accent: string; sub: string };
const metaFor = (cat: EventCategoryOption): CategoryMeta => ({
    value: cat.key,
    label: cat.label,
    icon: styleFor(cat.key).icon,
    accent: `var(--${cat.color_token})`,
    sub: styleFor(cat.key).sub,
});

/** Recurrence presets ↔ the RFC-5545 subset the backend expands. */
const RECUR_PRESETS: { key: string; label: string; rrule: string | null }[] = [
    { key: 'none', label: 'Does not repeat', rrule: null },
    { key: 'DAILY', label: 'Daily', rrule: 'FREQ=DAILY' },
    { key: 'WEEKLY', label: 'Weekly', rrule: 'FREQ=WEEKLY' },
    { key: 'FORTNIGHTLY', label: 'Every 2 weeks', rrule: 'FREQ=WEEKLY;INTERVAL=2' },
    { key: 'MONTHLY', label: 'Monthly', rrule: 'FREQ=MONTHLY' },
    { key: 'QUARTERLY', label: 'Every 3 months', rrule: 'FREQ=MONTHLY;INTERVAL=3' },
];
const presetFromRrule = (rrule: string | null | undefined): string =>
    RECUR_PRESETS.find((p) => p.rrule === (rrule || null))?.key ?? 'none';
const rruleFromPreset = (key: string): string | null =>
    RECUR_PRESETS.find((p) => p.key === key)?.rrule ?? null;

function recurrenceSummary(preset: string, until: string): string {
    const label = RECUR_PRESETS.find((p) => p.key === preset)?.label ?? 'Does not repeat';
    if (preset === 'none') return 'Occurs once';
    const base = `Occurs ${label.toLowerCase()}`;
    if (!until) return base;
    const d = new Date(until);
    if (Number.isNaN(d.getTime())) return base;
    return `${base} until ${d.toLocaleDateString('en-NZ', { day: 'numeric', month: 'short', year: 'numeric' })}`;
}

/** Trim a server ISO string to what a datetime-local / date input wants. */
const toLocalInput = (value: string, allDay: boolean) =>
    value ? value.substring(0, allDay ? 10 : 16) : '';

function prettyWhen(start: string, end: string, allDay: boolean): string {
    if (!start) return '—';
    const fmt = (v: string) =>
        new Date(v).toLocaleDateString('en-NZ', {
            weekday: 'short',
            day: 'numeric',
            month: 'short',
            ...(allDay ? {} : { hour: 'numeric', minute: '2-digit' }),
        });
    const a = fmt(start);
    const b = end ? fmt(end) : '';
    return b && b !== a ? `${a} – ${b}` : a;
}

/**
 * The full event wizard for `/hr/calendar` — Add-Client-grade, the single
 * instance used for both create and edit (the quick-add popover escalates into
 * it). Posts to /hr/calendar/events. Audience/RSVP, recurrence, reminders and
 * attachments are a tracked follow-up; this covers the live schema end-to-end.
 */
export function EventWizardDialog({
    open,
    onClose,
    onSaved,
    sites,
    departments,
    categories,
    staff,
    initial,
    defaultDate,
}: {
    open: boolean;
    onClose: () => void;
    /** Called after a successful save so the page can refetch the feed. */
    onSaved: () => void;
    sites: IdName[];
    departments: IdName[];
    categories: EventCategoryOption[];
    staff: PersonOption[];
    initial?: CalendarEventInitial | null;
    /** Click-to-create prefill (YYYY-MM-DD) when creating a new event. */
    defaultDate?: string | null;
}) {
    const isEdit = !!initial;
    const wizard = useWizard(STEPS.length);
    const [submitted, setSubmitted] = useState(false);
    const [confirmDelete, setConfirmDelete] = useState(false);
    const [keepAdding, setKeepAdding] = useState(false);
    const [reminderChannel, setReminderChannel] = useState<'notification' | 'email'>('notification');
    const [stagedFiles, setStagedFiles] = useState<File[]>([]);
    const [existingAttachments, setExistingAttachments] = useState<EventAttachment[]>([]);
    const [removedAttachmentIds, setRemovedAttachmentIds] = useState<number[]>([]);

    const form = useForm({
        title: '',
        description: '',
        event_type: 'company',
        starts_at: '',
        ends_at: '',
        is_all_day: false,
        rrule: '' as string,
        recurrence_until: '' as string,
        audience_type: 'org' as 'org' | 'site' | 'department' | 'people',
        audience_user_ids: [] as number[],
        reminders: [] as { offset_minutes: number; channel: string }[],
        location: '',
        department_id: '',
        site_id: '',
    });

    // Seed (create defaults or edit values) each time the dialog opens.
    useEffect(() => {
        if (!open) return;
        if (initial) {
            form.setData({
                title: initial.title,
                description: initial.description ?? '',
                event_type: initial.event_type,
                starts_at: toLocalInput(initial.starts_at, initial.is_all_day),
                ends_at: toLocalInput(initial.ends_at, initial.is_all_day),
                is_all_day: initial.is_all_day,
                rrule: initial.rrule ?? '',
                recurrence_until: initial.recurrence_until ? initial.recurrence_until.substring(0, 10) : '',
                audience_type: initial.audience_type ?? 'org',
                audience_user_ids: initial.audience_user_ids ?? [],
                reminders: initial.reminders ?? [],
                location: initial.location ?? '',
                department_id: initial.department_id ? String(initial.department_id) : '',
                site_id: initial.site_id ? String(initial.site_id) : '',
            });
            setReminderChannel(
                (initial.reminders?.[0]?.channel as 'notification' | 'email') ?? 'notification',
            );
            setExistingAttachments(initial.attachments ?? []);
        } else if (defaultDate) {
            form.setData((d) => ({
                ...d,
                starts_at: `${defaultDate}T09:00`,
                ends_at: `${defaultDate}T10:00`,
                is_all_day: false,
            }));
        }
        wizard.reset();
        setSubmitted(false);
        setStagedFiles([]);
        setRemovedAttachmentIds([]);
        if (!initial) setExistingAttachments([]);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    const close = () => {
        form.reset();
        form.clearErrors();
        wizard.reset();
        setSubmitted(false);
        setStagedFiles([]);
        setExistingAttachments([]);
        setRemovedAttachmentIds([]);
        onClose();
    };

    const uploadStagedFiles = async (eventId: number): Promise<void> => {
        const token = xsrfToken();
        for (const file of stagedFiles) {
            const body = new FormData();
            body.append('file', file);
            await fetch(`/hr/calendar/events/${eventId}/attachments`, {
                method: 'POST',
                body,
                credentials: 'same-origin',
                headers: { 'X-XSRF-TOKEN': token, Accept: 'application/json' },
            }).catch(() => undefined);
        }
        for (const id of removedAttachmentIds) {
            await fetch(`/hr/calendar/attachments/${id}`, {
                method: 'DELETE',
                credentials: 'same-origin',
                headers: { 'X-XSRF-TOKEN': token, Accept: 'application/json' },
            }).catch(() => undefined);
        }
    };

    const selectedCategory =
        categories.find((c) => c.key === form.data.event_type) ?? categories[0];
    const meta = selectedCategory
        ? metaFor(selectedCategory)
        : { value: 'company', label: 'Company', icon: Building2, accent: 'var(--category-hr)', sub: '' };
    const canSubmit =
        form.data.title.trim() !== '' &&
        form.data.starts_at !== '' &&
        form.data.ends_at !== '';

    // Completeness meter (matches the prototype) — required basics + nice-to-haves.
    const completeness = useMemo(() => {
        const checks = [
            form.data.title.trim() !== '',
            form.data.starts_at !== '' && form.data.ends_at !== '',
            !!form.data.event_type,
            !!(form.data.location || form.data.site_id || form.data.department_id),
            !!(
                form.data.description ||
                form.data.reminders.length ||
                form.data.audience_user_ids.length ||
                stagedFiles.length
            ),
        ];
        return Math.round((checks.filter(Boolean).length / checks.length) * 100);
    }, [
        form.data.title,
        form.data.starts_at,
        form.data.ends_at,
        form.data.event_type,
        form.data.location,
        form.data.site_id,
        form.data.department_id,
        form.data.description,
        form.data.reminders.length,
        form.data.audience_user_ids.length,
        stagedFiles.length,
    ]);

    const siteName = useMemo(
        () => sites.find((s) => String(s.id) === form.data.site_id)?.name ?? 'All sites',
        [sites, form.data.site_id],
    );
    const departmentName = useMemo(
        () => departments.find((d) => String(d.id) === form.data.department_id)?.name ?? '',
        [departments, form.data.department_id],
    );
    const reachText = useMemo(() => {
        switch (form.data.audience_type) {
            case 'org':
                return 'Visible to everyone in the organisation.';
            case 'site':
                return form.data.site_id ? `Everyone at ${siteName}.` : 'Pick a site above to scope this.';
            case 'department':
                return form.data.department_id
                    ? `Everyone in ${departmentName}.`
                    : 'Pick a department above to scope this.';
            case 'people': {
                const n = form.data.audience_user_ids.length;
                return `${n} ${n === 1 ? 'person' : 'people'} invited — they can RSVP.`;
            }
            default:
                return '';
        }
    }, [
        form.data.audience_type,
        form.data.site_id,
        form.data.department_id,
        form.data.audience_user_ids,
        siteName,
        departmentName,
    ]);

    const setAllDay = (next: boolean) => {
        form.setData((d) => ({
            ...d,
            is_all_day: next,
            starts_at: toLocalInput(d.starts_at, next),
            ends_at: toLocalInput(d.ends_at, next),
        }));
    };

    const transform = () =>
        form.transform((data) => ({
            ...data,
            site_id: data.site_id === '' ? null : data.site_id,
            department_id: data.department_id === '' ? null : data.department_id,
            rrule: data.rrule === '' ? null : data.rrule,
            recurrence_until: data.recurrence_until === '' ? null : data.recurrence_until,
            ...(isEdit && initial?.scope
                ? { scope: initial.scope, occurrence_date: initial.occurrence_date ?? null }
                : {}),
        }));

    const onError = () => {
        if (form.errors.title || form.errors.event_type) wizard.goTo(0);
        else if (form.errors.starts_at || form.errors.ends_at) wizard.goTo(1);
    };

    const finishSave = async (eventId: number | undefined, addAnother: boolean) => {
        if ((stagedFiles.length > 0 || removedAttachmentIds.length > 0) && eventId) {
            await uploadStagedFiles(eventId);
        }
        onSaved();
        if (addAnother) {
            form.reset();
            form.clearErrors();
            wizard.reset();
            setStagedFiles([]);
            setRemovedAttachmentIds([]);
            setKeepAdding(true);
            toast.success('Event saved — add another');
        } else {
            setSubmitted(true);
        }
    };

    const submit = (addAnother = false) => {
        transform();
        const opts = {
            preserveScroll: true,
            preserveState: true,
            onError,
        };
        if (isEdit && initial) {
            form.put(`/hr/calendar/events/${initial.id}`, {
                ...opts,
                onSuccess: () => void finishSave(initial.id, addAnother),
            });
        } else {
            form.post('/hr/calendar/events', {
                ...opts,
                onSuccess: (page) => {
                    const flash = (page.props as { flash?: { createdEventId?: number } }).flash;
                    void finishSave(flash?.createdEventId, addAnother);
                },
            });
        }
    };

    const doArchive = () => {
        if (!initial) return;
        form.delete(`/hr/calendar/events/${initial.id}`, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                onSaved();
                toast.success('Event archived');
                close();
            },
        });
    };

    const RailIcon = meta.icon;

    return (
        <>
            <WizardShell
                open={open}
                onClose={close}
                title={isEdit ? 'Edit event' : 'New event'}
                description="Create or edit a company / HR calendar event."
                railIcon={RailIcon}
                railTitle={isEdit ? 'Edit event' : 'New event'}
                railSub="HR · Calendar"
                steps={STEPS}
                stepIndex={wizard.index}
                onStepClick={wizard.goTo}
                pct={completeness}
                maxWidth="min(96vw, 980px)"
                maxHeight="min(86vh, 724px)"
                success={
                    submitted ? (
                        <WizardSuccessPane
                            title={isEdit ? 'Event updated' : 'Event created'}
                            blurb={`“${form.data.title}” is on the calendar for ${prettyWhen(form.data.starts_at, form.data.ends_at, form.data.is_all_day)}.`}
                            actions={
                                <button
                                    type="button"
                                    onClick={close}
                                    className="rounded-[10px] border border-border bg-card px-[18px] py-2.5 text-sm font-semibold text-foreground hover:bg-muted"
                                >
                                    Done
                                </button>
                            }
                        />
                    ) : undefined
                }
                footerStart={
                    <div className="flex items-center gap-2">
                        {!wizard.isFirst ? (
                            <button
                                type="button"
                                onClick={wizard.back}
                                className="rounded-md px-3 py-2 text-sm font-semibold text-muted-foreground hover:bg-muted"
                            >
                                Back
                            </button>
                        ) : null}
                        {isEdit ? (
                            <button
                                type="button"
                                onClick={() => setConfirmDelete(true)}
                                className="inline-flex items-center gap-1.5 rounded-md px-3 py-2 text-sm font-semibold text-status-critical hover:bg-status-critical-bg"
                            >
                                <Archive className="h-4 w-4" />
                                Archive
                            </button>
                        ) : null}
                    </div>
                }
                footerEnd={
                    <>
                        <button
                            type="button"
                            onClick={close}
                            className="rounded-md px-3 py-2 text-sm font-semibold text-muted-foreground hover:bg-muted"
                        >
                            Cancel
                        </button>
                        {wizard.isLast ? (
                            <>
                                {!isEdit ? (
                                    <button
                                        type="button"
                                        onClick={() => submit(true)}
                                        disabled={!canSubmit || form.processing}
                                        className={cn(
                                            'rounded-[10px] border border-border bg-card px-[16px] py-2.5 text-sm font-semibold text-foreground hover:bg-muted',
                                            (!canSubmit || form.processing) && 'cursor-not-allowed opacity-50',
                                        )}
                                    >
                                        Save &amp; add another
                                    </button>
                                ) : null}
                                <button
                                    type="button"
                                    onClick={() => submit(false)}
                                    disabled={!canSubmit || form.processing}
                                    style={
                                        !canSubmit || form.processing
                                            ? undefined
                                            : { boxShadow: '0 6px 16px -6px oklch(from var(--primary) l c h / 0.7)' }
                                    }
                                    className={cn(
                                        'inline-flex items-center gap-2 rounded-[10px] bg-primary px-[18px] py-2.5 text-sm font-semibold whitespace-nowrap text-primary-foreground hover:brightness-95',
                                        (!canSubmit || form.processing) && 'cursor-not-allowed opacity-50',
                                    )}
                                >
                                    {form.processing ? 'Saving…' : isEdit ? 'Save changes' : 'Create event'}
                                    {!form.processing && <ArrowRight className="h-[15px] w-[15px]" />}
                                </button>
                            </>
                        ) : (
                            <button
                                type="button"
                                onClick={wizard.next}
                                style={{ boxShadow: '0 6px 16px -6px oklch(from var(--primary) l c h / 0.7)' }}
                                className="inline-flex items-center gap-2 rounded-[10px] bg-primary px-[18px] py-2.5 text-sm font-semibold whitespace-nowrap text-primary-foreground hover:brightness-95"
                            >
                                Continue
                                <ArrowRight className="h-[15px] w-[15px]" />
                            </button>
                        )}
                    </>
                }
            >
                {/* ── Step 1 · Basics ── */}
                {wizard.index === 0 && (
                    <WizardStepPane>
                        <StepHead icon={Megaphone} title="What's the event?" blurb="Give it a clear title and pick a category." />
                        <div className="mb-[18px]">
                            <Field label="Title" required error={form.errors.title}>
                                <Input
                                    value={form.data.title}
                                    onChange={(e) => form.setData('title', e.target.value)}
                                    placeholder="e.g. All-staff hui, Fire drill, Team lunch"
                                    autoFocus
                                />
                            </Field>
                        </div>

                        <SubHead icon={CalendarRange}>Category</SubHead>
                        <div className="mt-2 grid grid-cols-2 gap-2 sm:grid-cols-3">
                            {categories.map((c) => {
                                const m = metaFor(c);
                                const Icon = m.icon;
                                const active = form.data.event_type === c.key;
                                return (
                                    <button
                                        key={c.key}
                                        type="button"
                                        onClick={() => form.setData('event_type', c.key)}
                                        style={active ? { borderColor: m.accent } : undefined}
                                        className={cn(
                                            'flex items-start gap-2.5 rounded-xl border p-3 text-left transition-colors',
                                            active ? 'bg-accent' : 'border-border hover:bg-muted/50',
                                        )}
                                    >
                                        <span
                                            className="grid h-9 w-9 flex-none place-items-center rounded-lg"
                                            style={{ background: `color-mix(in oklch, ${m.accent} 16%, transparent)`, color: m.accent }}
                                        >
                                            <Icon className="h-[18px] w-[18px]" />
                                        </span>
                                        <span className="min-w-0">
                                            <span className="block text-[13px] font-semibold">{c.label}</span>
                                            <span className="block text-[11px] text-muted-foreground">{m.sub}</span>
                                        </span>
                                    </button>
                                );
                            })}
                        </div>

                        <div className="mt-[18px]">
                            <Field label="Description" error={form.errors.description}>
                                <Textarea
                                    value={form.data.description}
                                    onChange={(e) => form.setData('description', e.target.value)}
                                    rows={3}
                                    placeholder="Optional — what's happening, who should come, anything to bring."
                                />
                            </Field>
                        </div>
                    </WizardStepPane>
                )}

                {/* ── Step 2 · When ── */}
                {wizard.index === 1 && (
                    <WizardStepPane>
                        <StepHead icon={CalendarRange} title="When is it?" blurb="Set the start and end. Toggle all-day for closures or full-day events." />
                        <label className="mb-4 inline-flex cursor-pointer items-center gap-2 text-sm font-medium">
                            <input
                                type="checkbox"
                                checked={form.data.is_all_day}
                                onChange={(e) => setAllDay(e.target.checked)}
                                className="rounded border-border"
                            />
                            All-day event
                        </label>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field label="Starts" required error={form.errors.starts_at}>
                                <Input
                                    type={form.data.is_all_day ? 'date' : 'datetime-local'}
                                    value={form.data.starts_at}
                                    onChange={(e) => form.setData('starts_at', e.target.value)}
                                />
                            </Field>
                            <Field label="Ends" required error={form.errors.ends_at}>
                                <Input
                                    type={form.data.is_all_day ? 'date' : 'datetime-local'}
                                    value={form.data.ends_at}
                                    onChange={(e) => form.setData('ends_at', e.target.value)}
                                />
                            </Field>
                        </div>
                        <div className="mt-4 flex items-center gap-2 rounded-lg bg-muted/40 px-3 py-2 text-[12.5px] text-muted-foreground">
                            <AlarmClock className="h-4 w-4" />
                            {prettyWhen(form.data.starts_at, form.data.ends_at, form.data.is_all_day)}
                        </div>

                        {/* Recurrence */}
                        <div className="mt-5">
                            <SubHead icon={Repeat}>Repeats</SubHead>
                            <div className="mt-2 grid gap-4 sm:grid-cols-2">
                                <Field label="Repeat">
                                    <SelectInput
                                        value={presetFromRrule(form.data.rrule)}
                                        onChange={(v) =>
                                            form.setData((d) => ({
                                                ...d,
                                                rrule: rruleFromPreset(v) ?? '',
                                                recurrence_until: v === 'none' ? '' : d.recurrence_until,
                                            }))
                                        }
                                        placeholder="Does not repeat"
                                        ariaLabel="Repeat"
                                        options={RECUR_PRESETS.map((p) => ({ value: p.key, label: p.label }))}
                                    />
                                </Field>
                                {form.data.rrule ? (
                                    <Field label="Until (optional)">
                                        <Input
                                            type="date"
                                            value={form.data.recurrence_until}
                                            onChange={(e) => form.setData('recurrence_until', e.target.value)}
                                        />
                                    </Field>
                                ) : null}
                            </div>
                            {form.data.rrule ? (
                                <p className="mt-2 text-[12px] font-medium text-primary">
                                    {recurrenceSummary(presetFromRrule(form.data.rrule), form.data.recurrence_until)}
                                </p>
                            ) : null}
                        </div>
                    </WizardStepPane>
                )}

                {/* ── Step 3 · Who & where ── */}
                {wizard.index === 2 && (
                    <WizardStepPane>
                        <StepHead icon={Users} title="Who & where" blurb="Scope it to a site and add a location. Leave the site blank for org-wide." />
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field label="Site" error={form.errors.site_id}>
                                <SelectInput
                                    value={form.data.site_id || 'none'}
                                    onChange={(v) => form.setData('site_id', v === 'none' ? '' : v)}
                                    placeholder="All sites"
                                    ariaLabel="Site"
                                    options={[
                                        { value: 'none', label: 'All sites (org-wide)' },
                                        ...sites.map((s) => ({ value: String(s.id), label: s.name })),
                                    ]}
                                />
                            </Field>
                            <Field label="Department" error={form.errors.department_id}>
                                <SelectInput
                                    value={form.data.department_id || 'none'}
                                    onChange={(v) => form.setData('department_id', v === 'none' ? '' : v)}
                                    placeholder="Any department"
                                    ariaLabel="Department"
                                    options={[
                                        { value: 'none', label: 'Any department' },
                                        ...departments.map((d) => ({ value: String(d.id), label: d.name })),
                                    ]}
                                />
                            </Field>
                        </div>
                        <div className="mt-4">
                            <Field label="Location" error={form.errors.location}>
                                <Input
                                    value={form.data.location}
                                    onChange={(e) => form.setData('location', e.target.value)}
                                    placeholder="e.g. Head office boardroom, Zoom link"
                                />
                            </Field>
                        </div>

                        {/* Audience */}
                        <div className="mt-5">
                            <SubHead icon={Users}>Audience</SubHead>
                            <div className="mt-2">
                                <Segmented
                                    value={form.data.audience_type}
                                    onChange={(v) => form.setData('audience_type', v as typeof form.data.audience_type)}
                                    options={[
                                        { value: 'org', label: 'Everyone' },
                                        { value: 'site', label: 'This site' },
                                        { value: 'department', label: 'This department' },
                                        { value: 'people', label: 'Specific people' },
                                    ]}
                                />
                            </div>

                            {form.data.audience_type === 'people' ? (
                                <div className="mt-3 space-y-2">
                                    <PeoplePicker
                                        value=""
                                        onChange={(v) => {
                                            const id = Number(v);
                                            if (id && !form.data.audience_user_ids.includes(id)) {
                                                form.setData('audience_user_ids', [...form.data.audience_user_ids, id]);
                                            }
                                        }}
                                        people={staff.filter((s) => !form.data.audience_user_ids.includes(Number(s.value)))}
                                        placeholder="Add a person…"
                                    />
                                    {form.data.audience_user_ids.length > 0 ? (
                                        <div className="flex flex-wrap gap-1.5">
                                            {form.data.audience_user_ids.map((id) => {
                                                const person = staff.find((s) => Number(s.value) === id);
                                                return (
                                                    <span
                                                        key={id}
                                                        className="inline-flex items-center gap-1.5 rounded-full bg-accent px-2.5 py-1 text-[12px] font-medium"
                                                    >
                                                        {person?.label ?? `#${id}`}
                                                        <button
                                                            type="button"
                                                            aria-label={`Remove ${person?.label ?? 'person'}`}
                                                            onClick={() =>
                                                                form.setData(
                                                                    'audience_user_ids',
                                                                    form.data.audience_user_ids.filter((x) => x !== id),
                                                                )
                                                            }
                                                            className="text-muted-foreground hover:text-status-critical"
                                                        >
                                                            <X className="h-3 w-3" />
                                                        </button>
                                                    </span>
                                                );
                                            })}
                                        </div>
                                    ) : null}
                                </div>
                            ) : null}

                            <p className="mt-2 text-[12px] text-muted-foreground">{reachText}</p>
                        </div>
                    </WizardStepPane>
                )}

                {/* ── Step 4 · Details (reminders & files) ── */}
                {wizard.index === 3 && (
                    <WizardStepPane>
                        <StepHead icon={Bell} title="Reminders & files" blurb="Nudge attendees ahead of time and attach anything useful." />

                        <SubHead icon={Bell}>Reminders</SubHead>
                        <div className="mt-2">
                            <Segmented
                                value={reminderChannel}
                                onChange={(v) => {
                                    const ch = v as 'notification' | 'email';
                                    setReminderChannel(ch);
                                    form.setData(
                                        'reminders',
                                        form.data.reminders.map((r) => ({ ...r, channel: ch })),
                                    );
                                }}
                                options={[
                                    { value: 'notification', label: 'In-app' },
                                    { value: 'email', label: 'Email' },
                                ]}
                            />
                        </div>
                        <div className="mt-3 flex flex-wrap gap-2">
                            {REMINDER_PRESETS.map((p) => {
                                const active = form.data.reminders.some((r) => r.offset_minutes === p.minutes);
                                return (
                                    <button
                                        key={p.minutes}
                                        type="button"
                                        onClick={() =>
                                            form.setData(
                                                'reminders',
                                                active
                                                    ? form.data.reminders.filter((r) => r.offset_minutes !== p.minutes)
                                                    : [...form.data.reminders, { offset_minutes: p.minutes, channel: reminderChannel }],
                                            )
                                        }
                                        aria-pressed={active}
                                        className={cn(
                                            'inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-[12.5px] font-medium transition-colors',
                                            active
                                                ? 'border-primary bg-primary/10 text-primary'
                                                : 'border-border text-muted-foreground hover:bg-muted/50',
                                        )}
                                    >
                                        {active ? <Bell className="h-3.5 w-3.5" /> : null}
                                        {p.label}
                                    </button>
                                );
                            })}
                        </div>
                        <p className="mt-2 text-[12px] text-muted-foreground">
                            {form.data.reminders.length === 0
                                ? 'No reminders — attendees just see it on the calendar.'
                                : `${form.data.reminders.length} reminder${form.data.reminders.length === 1 ? '' : 's'} via ${reminderChannel === 'email' ? 'email' : 'in-app notification'}.`}
                        </p>

                        {/* Attachments */}
                        <div className="mt-5">
                            <SubHead icon={Paperclip}>Attachments</SubHead>
                            {existingAttachments.length > 0 ? (
                                <div className="mt-2 space-y-1.5">
                                    {existingAttachments.map((a) => (
                                        <div
                                            key={a.id}
                                            className="flex items-center gap-2 rounded-lg border border-border px-3 py-2 text-[13px]"
                                        >
                                            <Paperclip className="h-3.5 w-3.5 text-muted-foreground" />
                                            <a
                                                href={a.url}
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                className="min-w-0 flex-1 truncate font-medium hover:underline"
                                            >
                                                {a.name}
                                            </a>
                                            <button
                                                type="button"
                                                aria-label={`Remove ${a.name}`}
                                                onClick={() => {
                                                    setRemovedAttachmentIds((prev) => [...prev, a.id]);
                                                    setExistingAttachments((prev) => prev.filter((x) => x.id !== a.id));
                                                }}
                                                className="text-muted-foreground hover:text-status-critical"
                                            >
                                                <X className="h-3.5 w-3.5" />
                                            </button>
                                        </div>
                                    ))}
                                </div>
                            ) : null}

                            <div className="mt-2">
                                <FileDropzone
                                    onFiles={(files) => setStagedFiles((prev) => [...prev, ...files])}
                                    hint="PDF, Word, Excel, images — up to 10 MB each"
                                />
                            </div>
                            {stagedFiles.length > 0 ? (
                                <div className="mt-2 space-y-1.5">
                                    {stagedFiles.map((file, i) => (
                                        <StagedFileCard
                                            key={`${file.name}-${i}`}
                                            file={file}
                                            onRemove={() => setStagedFiles((prev) => prev.filter((_, idx) => idx !== i))}
                                        />
                                    ))}
                                </div>
                            ) : null}
                        </div>
                    </WizardStepPane>
                )}

                {/* ── Step 5 · Review ── */}
                {wizard.index === 4 && (
                    <WizardStepPane>
                        <StepHead icon={ClipboardCheck} title="Review & save" blurb="Check the details, then save the event." />
                        <ReviewCard
                            icon={RailIcon}
                            title={form.data.title || 'Untitled event'}
                        >
                            <ReviewRow label="When" value={prettyWhen(form.data.starts_at, form.data.ends_at, form.data.is_all_day)} />
                            {form.data.rrule ? (
                                <ReviewRow
                                    label="Repeats"
                                    value={recurrenceSummary(presetFromRrule(form.data.rrule), form.data.recurrence_until)}
                                />
                            ) : null}
                            <ReviewRow label="Category" value={meta.label} />
                            <ReviewRow label="Site" value={siteName} />
                            {departmentName ? <ReviewRow label="Department" value={departmentName} /> : null}
                            <ReviewRow label="Audience" value={reachText} />
                            {form.data.reminders.length > 0 ? (
                                <ReviewRow
                                    label="Reminders"
                                    value={form.data.reminders
                                        .map((r) => reminderLabel(r.offset_minutes))
                                        .join(', ')}
                                />
                            ) : null}
                            {existingAttachments.length + stagedFiles.length > 0 ? (
                                <ReviewRow
                                    label="Attachments"
                                    value={`${existingAttachments.length + stagedFiles.length} file${existingAttachments.length + stagedFiles.length === 1 ? '' : 's'}`}
                                />
                            ) : null}
                            {form.data.location ? <ReviewRow label="Location" value={form.data.location} /> : null}
                            {form.data.description ? <ReviewRow label="Description" value={form.data.description} /> : null}
                        </ReviewCard>
                    </WizardStepPane>
                )}
            </WizardShell>

            <AlertDialog open={confirmDelete} onOpenChange={setConfirmDelete}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Archive event?</AlertDialogTitle>
                        <AlertDialogDescription>
                            Archiving “{form.data.title}” removes it from active calendars but retains attendees, reminders, and attachments. It can be restored later.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Keep event</AlertDialogCancel>
                        <AlertDialogAction
                            onClick={doArchive}
                        >
                            Archive event
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </>
    );
}

export default EventWizardDialog;
