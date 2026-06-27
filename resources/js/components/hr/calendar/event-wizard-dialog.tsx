/* eslint-disable no-restricted-syntax -- Mirrors the Add-Client / leave-request
 * wizard chrome: the footer nav, category tiles and review surface use styled
 * native buttons + inputs, and the per-category accent tints use color-mix() on
 * semantic CSS tokens (var(--category-*), var(--status-*)) via inline style —
 * that IS the design-token system, not a raw Tailwind colour class. */
import { useForm } from '@inertiajs/react';
import {
    AlarmClock,
    ArrowRight,
    Building2,
    CalendarRange,
    ClipboardCheck,
    GraduationCap,
    Megaphone,
    PartyPopper,
    Trash2,
    Users,
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
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';
import {
    Field,
    ReviewCard,
    ReviewRow,
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
}

type IdName = { id: number; name: string };

export interface EventCategoryOption {
    id: number;
    key: string;
    label: string;
    icon: string | null;
    color_token: string;
}

const STEPS: readonly WizardStep[] = [
    { key: 'basics', label: 'Basics', blurb: 'Title & type', icon: Megaphone },
    { key: 'when', label: 'When', blurb: 'Dates & times', icon: CalendarRange },
    { key: 'who', label: 'Who & where', blurb: 'Audience & place', icon: Users },
    { key: 'review', label: 'Review', blurb: 'Confirm & save', icon: ClipboardCheck },
];

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
    initial?: CalendarEventInitial | null;
    /** Click-to-create prefill (YYYY-MM-DD) when creating a new event. */
    defaultDate?: string | null;
}) {
    const isEdit = !!initial;
    const wizard = useWizard(STEPS.length);
    const [submitted, setSubmitted] = useState(false);
    const [confirmDelete, setConfirmDelete] = useState(false);
    const [keepAdding, setKeepAdding] = useState(false);

    const form = useForm({
        title: '',
        description: '',
        event_type: 'company',
        starts_at: '',
        ends_at: '',
        is_all_day: false,
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
                location: initial.location ?? '',
                department_id: initial.department_id ? String(initial.department_id) : '',
                site_id: initial.site_id ? String(initial.site_id) : '',
            });
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
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    const close = () => {
        form.reset();
        form.clearErrors();
        wizard.reset();
        setSubmitted(false);
        onClose();
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

    const siteName = useMemo(
        () => sites.find((s) => String(s.id) === form.data.site_id)?.name ?? 'All sites',
        [sites, form.data.site_id],
    );
    const departmentName = useMemo(
        () => departments.find((d) => String(d.id) === form.data.department_id)?.name ?? '',
        [departments, form.data.department_id],
    );

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
        }));

    const onError = () => {
        if (form.errors.title || form.errors.event_type) wizard.goTo(0);
        else if (form.errors.starts_at || form.errors.ends_at) wizard.goTo(1);
    };

    const submit = (addAnother = false) => {
        transform();
        const opts = {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                onSaved();
                if (addAnother) {
                    form.reset();
                    form.clearErrors();
                    wizard.reset();
                    setKeepAdding(true);
                    toast.success('Event saved — add another');
                } else {
                    setSubmitted(true);
                }
            },
            onError,
        };
        if (isEdit && initial) {
            form.put(`/hr/calendar/events/${initial.id}`, opts);
        } else {
            form.post('/hr/calendar/events', opts);
        }
    };

    const doDelete = () => {
        if (!initial) return;
        form.delete(`/hr/calendar/events/${initial.id}`, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                onSaved();
                toast.success('Event deleted');
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
                pct={null}
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
                                <Trash2 className="h-4 w-4" />
                                Delete
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
                    </WizardStepPane>
                )}

                {/* ── Step 4 · Review ── */}
                {wizard.index === 3 && (
                    <WizardStepPane>
                        <StepHead icon={ClipboardCheck} title="Review & save" blurb="Check the details, then save the event." />
                        <ReviewCard
                            icon={RailIcon}
                            title={form.data.title || 'Untitled event'}
                        >
                            <ReviewRow label="When" value={prettyWhen(form.data.starts_at, form.data.ends_at, form.data.is_all_day)} />
                            <ReviewRow label="Category" value={meta.label} />
                            <ReviewRow label="Site" value={siteName} />
                            {departmentName ? <ReviewRow label="Department" value={departmentName} /> : null}
                            {form.data.location ? <ReviewRow label="Location" value={form.data.location} /> : null}
                            {form.data.description ? <ReviewRow label="Description" value={form.data.description} /> : null}
                        </ReviewCard>
                    </WizardStepPane>
                )}
            </WizardShell>

            <AlertDialog open={confirmDelete} onOpenChange={setConfirmDelete}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Delete event?</AlertDialogTitle>
                        <AlertDialogDescription>
                            This permanently removes “{form.data.title}” from the calendar. This can't be undone.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Keep event</AlertDialogCancel>
                        <AlertDialogAction
                            onClick={doDelete}
                            className="bg-status-critical text-white hover:bg-status-critical/90"
                        >
                            Delete event
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </>
    );
}

export default EventWizardDialog;
