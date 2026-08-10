/* eslint-disable no-restricted-syntax -- This modal mirrors the bespoke Add-Client
 * wizard chrome: the footer / tile picker / drop-zone use styled native
 * buttons + inputs, and the per-leave-type accent tints + range band use
 * color-mix() on semantic CSS tokens (var(--primary), var(--status-*)) via inline
 * style — that IS the design-token system, not a raw Tailwind colour class. */
import { useForm } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    ArrowRight,
    CalendarClock,
    CalendarDays,
    CalendarRange,
    ClipboardCheck,
    Leaf,
    MessageSquare,
    MinusCircle,
    MoreHorizontal,
    Pencil,
    ShieldCheck,
    Sun,
    Timer,
    Upload,
    UserCheck,
    UserPlus,
    Users,
} from 'lucide-react';
import {
    useEffect,
    useMemo,
    useState,
    type CSSProperties,
    type ReactNode,
} from 'react';
import { toast } from 'sonner';

import { Textarea } from '@/components/ui/textarea';
import { fireConfetti } from '@/lib/confetti';
import { cn } from '@/lib/utils';

import { LeaveCalendarRange, shortDay } from './leave-calendar-range';
import { PeoplePicker, type PersonOption } from './people-picker';
import {
    Field,
    SelectInput,
    StepHead,
    useWizard,
    WizardShell,
    WizardStepPane,
    WizardSuccessPane,
    type IconType,
    type WizardStep,
} from './wizard';

export interface LeaveStaff {
    id: number;
    name: string;
    email: string;
}

export interface LeaveTypeOption {
    value: string;
    label: string;
}

type LeavePreview = {
    hours: number;
    period: string;
    available_before: number;
    projected_remaining: number;
    insufficient: boolean;
    has_roster_conflict: boolean;
    approver: string | null;
    approval_due_at: string | null;
};

const STEPS: readonly WizardStep[] = [
    {
        key: 'type',
        label: 'Type & dates',
        blurb: 'What & when',
        icon: CalendarRange,
    },
    {
        key: 'note',
        label: 'Note & docs',
        blurb: 'Add context',
        icon: MessageSquare,
    },
    {
        key: 'review',
        label: 'Review',
        blurb: 'Confirm & send',
        icon: ClipboardCheck,
    },
];

const PERIOD_OPTIONS: LeaveTypeOption[] = [
    { value: 'full_day', label: 'Full day' },
    { value: 'half_day_am', label: 'Half day — morning' },
    { value: 'half_day_pm', label: 'Half day — afternoon' },
];

/** Per-leave-type icon + accent token + warm sub-label for the tile picker. */
type TileMeta = { icon: IconType; accent: string; sub: string };
const TILE_META: Record<string, TileMeta> = {
    annual: { icon: Sun, accent: 'var(--primary)', sub: 'Holiday & rest' },
    sick: {
        icon: Activity,
        accent: 'var(--status-warning)',
        sub: 'Unwell or caring',
    },
    bereavement: {
        icon: Leaf,
        accent: 'var(--status-success)',
        sub: 'Loss of someone',
    },
    family_violence: {
        icon: ShieldCheck,
        accent: 'var(--status-critical)',
        sub: 'Safety & support',
    },
    parental: {
        icon: UserPlus,
        accent: 'var(--chart-4)',
        sub: 'New family time',
    },
    public_holiday: {
        icon: CalendarDays,
        accent: 'var(--primary)',
        sub: 'Statutory day',
    },
    alternative: {
        icon: CalendarClock,
        accent: 'var(--status-success)',
        sub: 'Day in lieu',
    },
    unpaid: {
        icon: MinusCircle,
        accent: 'var(--muted-foreground)',
        sub: 'Time without pay',
    },
    toil: {
        icon: Timer,
        accent: 'var(--muted-foreground)',
        sub: 'Time off in lieu',
    },
    other: {
        icon: MoreHorizontal,
        accent: 'var(--muted-foreground)',
        sub: 'Anything else',
    },
};
const FALLBACK_TILE: TileMeta = {
    icon: CalendarRange,
    accent: 'var(--muted-foreground)',
    sub: 'Leave',
};
const tileMeta = (value: string): TileMeta => TILE_META[value] ?? FALLBACK_TILE;

/** First two initials of a name, for the staff avatar chips. */
function initials(name: string): string {
    return (
        name
            .split(/\s+/)
            .filter(Boolean)
            .slice(0, 2)
            .map((w) => w[0]?.toUpperCase() ?? '')
            .join('') || '—'
    );
}

function parseIso(iso: string): Date {
    const [y, m, d] = iso.split('-').map(Number);
    return new Date(y, m - 1, d);
}
function toIso(d: Date): string {
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

/** "Wed 8 Jul" — short weekday + day + month for the range line. */
function shortDayMonth(iso: string): string {
    const d = parseIso(iso);
    return d.toLocaleDateString('en-NZ', {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
    });
}

/**
 * Optimistic client estimate of working days / hours while the authoritative
 * server `/preview` is in flight (Mon–Fri × 8h, minus known public holidays).
 * Reconciled against `preview.hours` the moment it lands.
 */
function estimateWork(
    start: string,
    end: string,
    period: string,
    holidays: Record<string, string>,
): {
    workingDays: number;
    hours: number;
    holidaysInRange: { iso: string; name: string }[];
} {
    if (!start || !end)
        return { workingDays: 0, hours: 0, holidaysInRange: [] };
    let workingDays = 0;
    const holidaysInRange: { iso: string; name: string }[] = [];
    const a = parseIso(start);
    const b = parseIso(end);
    for (let d = new Date(a); d <= b; d.setDate(d.getDate() + 1)) {
        const iso = toIso(d);
        if (holidays[iso]) {
            holidaysInRange.push({ iso, name: holidays[iso] });
            continue;
        }
        const dow = d.getDay();
        if (dow === 0 || dow === 6) continue;
        workingDays++;
    }
    const singleDay = start === end;
    let hours = workingDays * 8;
    if (singleDay && workingDays > 0 && period !== 'full_day') hours = 4;
    return { workingDays, hours, holidaysInRange };
}

/**
 * Single shared leave-request modal (handover §5) — warm redesign. `mode="manager"`
 * (default) picks a recipient and posts to hr.leave.store; `mode="self"` locks the
 * recipient to the current user, posts to hr.my.leave.store and fires confetti. The
 * tile picker sets the leave type, the inline calendar sets the date range, and the
 * server preview (engine hours — PH-aware + part-day — balance impact, roster
 * conflict, approver) drives the live balance card + review summary.
 */
export function LeaveRequestDialog({
    open,
    onClose,
    staff,
    leaveTypes,
    mode = 'manager',
    currentUser,
    initial,
    holidays = {},
    onSubmitted,
}: {
    open: boolean;
    onClose: () => void;
    staff: LeaveStaff[];
    leaveTypes: LeaveTypeOption[];
    mode?: 'self' | 'manager';
    currentUser?: { name: string };
    initial?: { leave_type?: string; starts_at?: string; ends_at?: string };
    /** Public holidays in scope (ISO date → name) — decorative calendar highlight. */
    holidays?: Record<string, string>;
    onSubmitted?: () => void;
}) {
    const isSelf = mode === 'self';
    const postUrl = isSelf ? '/hr/my/leave' : '/hr/leave';
    const previewUrl = isSelf ? '/hr/my/leave/preview' : '/hr/leave/preview';

    const wizard = useWizard(STEPS.length);
    const form = useForm<{
        user_id: string;
        leave_type: string;
        period: string;
        starts_at: string;
        ends_at: string;
        hours_requested: string;
        reason: string;
        supporting_doc: File | null;
    }>({
        user_id: '',
        leave_type: '',
        period: 'full_day',
        starts_at: '',
        ends_at: '',
        hours_requested: '',
        reason: '',
        supporting_doc: null,
    });

    const [preview, setPreview] = useState<LeavePreview | null>(null);
    const [previewLoading, setPreviewLoading] = useState(false);
    const [submitted, setSubmitted] = useState(false);

    const close = () => {
        form.reset();
        form.clearErrors();
        wizard.reset();
        setPreview(null);
        setSubmitted(false);
        onClose();
    };

    const people: PersonOption[] = useMemo(
        () =>
            staff.map((s) => ({
                value: String(s.id),
                label: s.name,
                sub: s.email,
            })),
        [staff],
    );

    // Seed from `initial` (the "Duplicate" action) each time the dialog opens.
    useEffect(() => {
        if (open && initial) {
            if (initial.leave_type)
                form.setData('leave_type', initial.leave_type);
            if (initial.starts_at) form.setData('starts_at', initial.starts_at);
            if (initial.ends_at) form.setData('ends_at', initial.ends_at);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    const singleDay =
        form.data.starts_at !== '' && form.data.starts_at === form.data.ends_at;

    const staffName = isSelf
        ? (currentUser?.name ?? 'You')
        : (staff.find((s) => String(s.id) === form.data.user_id)?.name ?? '—');
    const staffFirst = staffName.split(/\s+/)[0] || staffName;
    const meta = tileMeta(form.data.leave_type);
    const typeLabel =
        leaveTypes.find((t) => t.value === form.data.leave_type)?.label ?? '—';

    const canSubmit =
        (isSelf || form.data.user_id !== '') &&
        form.data.leave_type !== '' &&
        form.data.starts_at !== '' &&
        form.data.ends_at !== '';

    const work = useMemo(
        () =>
            estimateWork(
                form.data.starts_at,
                form.data.ends_at,
                form.data.period,
                holidays,
            ),
        [form.data.starts_at, form.data.ends_at, form.data.period, holidays],
    );

    const displayHours = preview ? preview.hours : work.hours;

    // Live server preview — fires whenever the request is complete enough, so the
    // rail balance card + duration summary reflect the authoritative PH-aware engine
    // (debounced to coalesce rapid calendar clicks). Drives Hours / Balance impact /
    // Approver / insufficient / roster conditionals.
    useEffect(() => {
        if (!open || !canSubmit) {
            setPreview(null);
            setPreviewLoading(false);
            return;
        }
        const params = new URLSearchParams({
            leave_type: form.data.leave_type,
            period: singleDay ? form.data.period : 'full_day',
            starts_at: form.data.starts_at,
            ends_at: form.data.ends_at,
        });
        if (!isSelf && form.data.user_id)
            params.set('user_id', form.data.user_id);

        let cancelled = false;
        setPreviewLoading(true);
        const timer = setTimeout(() => {
            fetch(`${previewUrl}?${params.toString()}`, {
                headers: { Accept: 'application/json' },
            })
                .then((r) => (r.ok ? r.json() : null))
                .then((data) => {
                    if (!cancelled) setPreview(data as LeavePreview | null);
                })
                .catch(() => {
                    if (!cancelled) setPreview(null);
                })
                .finally(() => {
                    if (!cancelled) setPreviewLoading(false);
                });
        }, 280);
        return () => {
            cancelled = true;
            clearTimeout(timer);
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [
        open,
        form.data.leave_type,
        form.data.period,
        form.data.starts_at,
        form.data.ends_at,
        form.data.user_id,
        singleDay,
        isSelf,
        previewUrl,
    ]);

    const submit = () => {
        form.transform((data) => ({
            ...data,
            period: singleDay ? data.period : 'full_day',
        }));
        form.post(postUrl, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: (page) => {
                const flash = (page.props as { flash?: { error?: string } })
                    .flash;
                if (flash?.error) {
                    toast.error('Could not submit leave', {
                        description: flash.error,
                    });
                    return;
                }
                if (isSelf) {
                    toast.success('Leave request sent 🌴', {
                        description: `${typeLabel} submitted for approval.`,
                    });
                    fireConfetti();
                }
                setSubmitted(true);
            },
            onError: () => {
                if (
                    form.errors.user_id ||
                    form.errors.leave_type ||
                    form.errors.period ||
                    form.errors.starts_at ||
                    form.errors.ends_at ||
                    form.errors.hours_requested
                ) {
                    wizard.goTo(0);
                }
            },
        });
    };

    const TypeIcon = meta.icon;

    return (
        <WizardShell
            open={open}
            onClose={close}
            title={isSelf ? 'Request leave' : 'New leave request'}
            description={
                isSelf
                    ? 'Submit a leave request to your manager.'
                    : 'Submit a leave request for approval.'
            }
            railIcon={CalendarRange}
            railTitle={isSelf ? 'Time off' : 'Leave request'}
            railSub={isSelf ? 'HR · My leave' : 'HR · Team'}
            steps={STEPS}
            stepIndex={wizard.index}
            onStepClick={wizard.goTo}
            pct={null}
            maxWidth="min(96vw, 980px)"
            maxHeight="min(86vh, 724px)"
            railExtra={
                <BalanceCard
                    typeLabel={
                        form.data.leave_type ? typeLabel : 'Leave balance'
                    }
                    icon={TypeIcon}
                    accent={meta.accent}
                    preview={preview}
                    requested={displayHours}
                    hasRange={canSubmit}
                />
            }
            success={
                submitted ? (
                    <WizardSuccessPane
                        title={
                            isSelf
                                ? 'Enjoy your time off 🌴'
                                : 'Request submitted'
                        }
                        blurb={
                            isSelf
                                ? `Your ${typeLabel.toLowerCase()} for ${form.data.starts_at ? shortDayMonth(form.data.starts_at) : ''}${form.data.ends_at && form.data.ends_at !== form.data.starts_at ? ` – ${shortDayMonth(form.data.ends_at)}` : ''} is on its way${preview?.approver ? ` to ${preview.approver}` : ''}. We'll let you know the moment it's approved.`
                                : `${staffName}'s leave has been sent for approval.`
                        }
                        actions={
                            <button
                                type="button"
                                onClick={() => {
                                    onSubmitted?.();
                                    close();
                                }}
                                className="rounded-[10px] border border-border bg-card px-[18px] py-2.5 text-sm font-semibold text-foreground hover:bg-muted"
                            >
                                Back to start
                            </button>
                        }
                    />
                ) : undefined
            }
            footerStart={
                wizard.isFirst ? null : (
                    <button
                        type="button"
                        onClick={wizard.back}
                        className="rounded-md px-3 py-2 text-sm font-semibold text-muted-foreground hover:bg-muted"
                    >
                        Back
                    </button>
                )
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
                        <button
                            type="button"
                            onClick={submit}
                            disabled={!canSubmit || form.processing}
                            style={
                                !canSubmit || form.processing
                                    ? undefined
                                    : {
                                          boxShadow:
                                              '0 6px 16px -6px oklch(from var(--primary) l c h / 0.7)',
                                      }
                            }
                            className={cn(
                                'inline-flex items-center gap-2 rounded-[10px] bg-primary px-[18px] py-2.5 text-sm font-semibold whitespace-nowrap text-primary-foreground transition-opacity hover:brightness-95',
                                (!canSubmit || form.processing) &&
                                    'cursor-not-allowed opacity-50',
                            )}
                        >
                            {form.processing
                                ? 'Submitting…'
                                : isSelf
                                  ? 'Submit request'
                                  : 'Send for approval'}
                            {!form.processing && (
                                <ArrowRight className="h-[15px] w-[15px]" />
                            )}
                        </button>
                    ) : (
                        <button
                            type="button"
                            onClick={wizard.next}
                            style={{
                                boxShadow:
                                    '0 6px 16px -6px oklch(from var(--primary) l c h / 0.7)',
                            }}
                            className="inline-flex items-center gap-2 rounded-[10px] bg-primary px-[18px] py-2.5 text-sm font-semibold whitespace-nowrap text-primary-foreground hover:brightness-95"
                        >
                            Continue
                            <ArrowRight className="h-[15px] w-[15px]" />
                        </button>
                    )}
                </>
            }
        >
            {wizard.index === 0 && (
                <WizardStepPane>
                    <StepHead
                        icon={CalendarRange}
                        title={
                            isSelf
                                ? 'When are you away?'
                                : "Who's away, and when?"
                        }
                        blurb={
                            isSelf
                                ? "Pick a leave type and your dates — we'll add up the working hours for you."
                                : 'Choose the team member, the leave type and the dates.'
                        }
                    />

                    {!isSelf && (
                        <div className="mb-[18px]">
                            <Field
                                label="Staff member"
                                required
                                error={form.errors.user_id}
                            >
                                <PeoplePicker
                                    value={form.data.user_id}
                                    onChange={(v) => form.setData('user_id', v)}
                                    people={people}
                                    placeholder="Select a staff member…"
                                />
                            </Field>
                        </div>
                    )}

                    <div className="grid gap-6 sm:[grid-template-columns:288px_1fr]">
                        {/* ── Leave-type tiles ── */}
                        <div>
                            <div className="mb-2.5 text-[13px] font-semibold">
                                Leave type{' '}
                                <span className="text-status-critical">*</span>
                            </div>
                            <div className="flex flex-col gap-2">
                                {leaveTypes.map((t) => {
                                    const m = tileMeta(t.value);
                                    const Icon = m.icon;
                                    const active =
                                        form.data.leave_type === t.value;
                                    return (
                                        <button
                                            key={t.value}
                                            type="button"
                                            aria-pressed={active}
                                            onClick={() =>
                                                form.setData(
                                                    'leave_type',
                                                    t.value,
                                                )
                                            }
                                            style={
                                                active
                                                    ? ({
                                                          borderColor: m.accent,
                                                          background: `color-mix(in oklch, ${m.accent} 8%, var(--card))`,
                                                          boxShadow: `0 0 0 3px color-mix(in oklch, ${m.accent} 16%, transparent)`,
                                                      } as CSSProperties)
                                                    : undefined
                                            }
                                            className={cn(
                                                'flex items-center gap-[11px] rounded-[13px] border bg-card px-[11px] py-[9px] text-left transition-all focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                                                active
                                                    ? ''
                                                    : 'border-border hover:border-primary/50',
                                            )}
                                        >
                                            <span
                                                className="grid h-9 w-9 shrink-0 place-items-center rounded-[10px]"
                                                style={{
                                                    background: `color-mix(in oklch, ${m.accent} 12%, transparent)`,
                                                    color: m.accent,
                                                }}
                                            >
                                                <Icon className="h-[19px] w-[19px]" />
                                            </span>
                                            <span className="min-w-0">
                                                <span className="block text-[13.5px] font-semibold">
                                                    {t.label}
                                                </span>
                                                <span className="block truncate text-[11.5px] text-muted-foreground">
                                                    {m.sub}
                                                </span>
                                            </span>
                                        </button>
                                    );
                                })}
                            </div>
                            {form.errors.leave_type ? (
                                <p className="mt-1.5 flex items-center gap-1 text-xs text-status-critical">
                                    <AlertTriangle className="h-3 w-3 shrink-0" />
                                    {form.errors.leave_type}
                                </p>
                            ) : null}
                        </div>

                        {/* ── Calendar + duration ── */}
                        <div>
                            <LeaveCalendarRange
                                start={form.data.starts_at || null}
                                end={form.data.ends_at || null}
                                holidays={holidays}
                                onChange={(s, e) => {
                                    form.setData('starts_at', s ?? '');
                                    form.setData('ends_at', e ?? '');
                                }}
                            />
                            {(form.errors.starts_at || form.errors.ends_at) && (
                                <p className="mt-1.5 flex items-center gap-1 text-xs text-status-critical">
                                    <AlertTriangle className="h-3 w-3 shrink-0" />
                                    {form.errors.starts_at ||
                                        form.errors.ends_at}
                                </p>
                            )}

                            {/* Duration summary */}
                            <div
                                className="mt-3 flex items-center gap-3.5 rounded-[13px] border px-3.5 py-3"
                                style={{
                                    borderColor:
                                        displayHours > 0
                                            ? 'color-mix(in oklch, var(--primary) 30%, var(--card))'
                                            : 'var(--border)',
                                    background:
                                        displayHours > 0
                                            ? 'color-mix(in oklch, var(--primary) 5%, var(--card))'
                                            : 'var(--card)',
                                }}
                            >
                                <div className="shrink-0 text-center">
                                    <div className="text-[23px] leading-none font-bold tracking-tight text-primary">
                                        {previewLoading && !preview
                                            ? '…'
                                            : displayHours}
                                    </div>
                                    <div className="text-[10px] font-semibold tracking-wider text-muted-foreground uppercase">
                                        Hours
                                    </div>
                                </div>
                                <div className="h-9 w-px shrink-0 bg-border" />
                                <div className="min-w-0">
                                    <div className="text-[13.5px] font-semibold">
                                        {form.data.starts_at &&
                                        form.data.ends_at
                                            ? `${shortDayMonth(form.data.starts_at)} – ${shortDayMonth(form.data.ends_at)}`
                                            : form.data.starts_at
                                              ? `${shortDayMonth(form.data.starts_at)} — pick an end date`
                                              : 'Pick your dates'}
                                    </div>
                                    <div className="text-xs text-muted-foreground">
                                        {work.workingDays > 0
                                            ? `${work.workingDays} working day${work.workingDays === 1 ? '' : 's'}${work.holidaysInRange.length ? ` · ${work.holidaysInRange.length} public holiday${work.holidaysInRange.length === 1 ? '' : 's'} skipped` : ''}`
                                            : 'Tap a start and end day on the calendar'}
                                    </div>
                                </div>
                            </div>

                            {singleDay && (
                                <div className="mt-3">
                                    <Field
                                        label="Part-day"
                                        hint="single-day leave only"
                                        error={form.errors.period}
                                    >
                                        <SelectInput
                                            value={form.data.period}
                                            onChange={(v) =>
                                                form.setData('period', v)
                                            }
                                            placeholder="Full day"
                                            options={PERIOD_OPTIONS}
                                        />
                                    </Field>
                                </div>
                            )}

                            {work.holidaysInRange.length > 0 && (
                                <div className="mt-2 inline-flex items-center gap-1.5 rounded-full border border-status-warning/40 bg-status-warning-bg px-2.5 py-1 text-[11.5px] font-semibold text-status-warning">
                                    <Sun className="h-3 w-3" />
                                    {work.holidaysInRange[0].name} (
                                    {shortDay(work.holidaysInRange[0].iso)}) is
                                    a public holiday — it won&apos;t come off
                                    the balance.
                                </div>
                            )}
                        </div>
                    </div>
                </WizardStepPane>
            )}

            {wizard.index === 1 && (
                <WizardStepPane>
                    <StepHead
                        icon={MessageSquare}
                        title={isSelf ? 'Anything to add?' : 'Add some context'}
                        blurb={
                            isSelf
                                ? 'A short note helps your manager say yes faster. Attach a certificate if you have one.'
                                : 'Notes and any supporting document for the record.'
                        }
                    />
                    <div className="max-w-[560px]">
                        <Field
                            label="Note"
                            hint="— optional"
                            error={form.errors.reason}
                        >
                            <Textarea
                                rows={4}
                                value={form.data.reason}
                                onChange={(e) =>
                                    form.setData('reason', e.target.value)
                                }
                                placeholder={
                                    isSelf
                                        ? 'A line or two for your manager (optional)…'
                                        : 'Context for the record (optional)…'
                                }
                                className="min-h-[104px] rounded-[12px]"
                            />
                        </Field>

                        <div className="mt-[18px]">
                            <div className="mb-1.5 text-[13px] font-semibold">
                                Supporting document{' '}
                                <span className="font-normal text-muted-foreground">
                                    — optional · PDF, JPG, PNG, DOC · max 5 MB
                                </span>
                            </div>
                            <label className="flex cursor-pointer items-center gap-3.5 rounded-[12px] border-[1.5px] border-dashed border-border bg-background px-4 py-[15px] transition-colors hover:border-primary hover:bg-primary/5">
                                <span className="grid h-10 w-10 shrink-0 place-items-center rounded-[11px] bg-primary/10 text-primary">
                                    <Upload className="h-5 w-5" />
                                </span>
                                <span className="min-w-0 flex-1">
                                    <span className="block truncate text-[13.5px] font-semibold">
                                        {form.data.supporting_doc
                                            ? form.data.supporting_doc.name
                                            : 'Drop a file or browse'}
                                    </span>
                                    <span className="block text-[11.5px] text-muted-foreground">
                                        e.g. a medical certificate
                                    </span>
                                </span>
                                <input
                                    type="file"
                                    accept=".pdf,.jpg,.jpeg,.png,.doc,.docx"
                                    onChange={(e) =>
                                        form.setData(
                                            'supporting_doc',
                                            e.target.files?.[0] ?? null,
                                        )
                                    }
                                    className="hidden"
                                />
                                <span className="rounded-lg border border-primary/40 bg-card px-3 py-1.5 text-[12.5px] font-semibold text-primary">
                                    Browse
                                </span>
                            </label>
                            {form.errors.supporting_doc ? (
                                <p className="mt-1.5 flex items-center gap-1 text-xs text-status-critical">
                                    <AlertTriangle className="h-3 w-3 shrink-0" />
                                    {form.errors.supporting_doc}
                                </p>
                            ) : null}
                        </div>
                    </div>
                </WizardStepPane>
            )}

            {wizard.index === 2 && (
                <WizardStepPane>
                    <StepHead
                        icon={ClipboardCheck}
                        title={isSelf ? 'All set?' : 'Review & submit'}
                        blurb={
                            isSelf
                                ? "Here's your request at a glance — submit when it looks right."
                                : 'Confirm the details and send for approval.'
                        }
                    />
                    <div className="max-w-[580px]">
                        {/* Hero summary */}
                        <div className="overflow-hidden rounded-[16px] border border-border bg-card">
                            <div
                                className="flex items-center gap-3.5 px-[18px] py-4"
                                style={{
                                    background:
                                        'linear-gradient(120deg, color-mix(in oklch, var(--primary) 7%, var(--card)), color-mix(in oklch, var(--primary) 2.5%, var(--card)))',
                                }}
                            >
                                <span className="grid h-[46px] w-[46px] shrink-0 place-items-center rounded-[13px] bg-card text-primary shadow-[0_2px_8px_-2px_color-mix(in_oklch,var(--primary)_30%,transparent)]">
                                    <TypeIcon className="h-[23px] w-[23px]" />
                                </span>
                                <div className="min-w-0 flex-1">
                                    <div className="text-base font-bold tracking-tight">
                                        {typeLabel}
                                    </div>
                                    <div className="text-[13px] text-muted-foreground">
                                        {form.data.starts_at &&
                                        form.data.ends_at
                                            ? `${shortDayMonth(form.data.starts_at)} – ${shortDayMonth(form.data.ends_at)}`
                                            : '—'}{' '}
                                        · {displayHours}h
                                    </div>
                                </div>
                                <button
                                    type="button"
                                    onClick={() => wizard.goTo(0)}
                                    className="inline-flex items-center gap-1.5 rounded-[9px] border border-primary/40 bg-card px-[11px] py-1.5 text-[12.5px] font-semibold text-primary hover:bg-primary/5"
                                >
                                    <Pencil className="h-[13px] w-[13px]" />{' '}
                                    Edit
                                </button>
                            </div>

                            <div className="px-[18px] py-1.5">
                                <HeroRow
                                    label={isSelf ? 'You' : 'Staff member'}
                                >
                                    <span className="inline-flex items-center gap-2">
                                        <span className="grid h-[22px] w-[22px] place-items-center rounded-full bg-primary/15 text-[10px] font-bold text-primary">
                                            {initials(staffName)}
                                        </span>
                                        {staffName}
                                    </span>
                                </HeroRow>
                                <HeroRow label="Working time">
                                    {displayHours}h · {work.workingDays} working
                                    day
                                    {work.workingDays === 1 ? '' : 's'}
                                </HeroRow>
                                <div className="border-b border-muted py-3">
                                    <div className="mb-2 flex items-center justify-between gap-4">
                                        <span className="text-[13px] text-muted-foreground">
                                            Balance impact
                                        </span>
                                        <span className="text-[13px] font-semibold">
                                            {preview ? (
                                                <>
                                                    {preview.available_before}h
                                                    →{' '}
                                                    <span
                                                        className={
                                                            preview.insufficient
                                                                ? 'text-status-critical'
                                                                : 'text-status-success'
                                                        }
                                                    >
                                                        {
                                                            preview.projected_remaining
                                                        }
                                                        h
                                                    </span>
                                                </>
                                            ) : previewLoading ? (
                                                'Calculating…'
                                            ) : (
                                                '—'
                                            )}
                                        </span>
                                    </div>
                                    <BalanceBar
                                        available={
                                            preview?.available_before ?? 0
                                        }
                                        requested={
                                            preview?.hours ?? displayHours
                                        }
                                        insufficient={!!preview?.insufficient}
                                    />
                                </div>
                                <HeroRow label="Note">
                                    {form.data.reason || '—'}
                                </HeroRow>
                                <HeroRow label="Document" last>
                                    {form.data.supporting_doc?.name ||
                                        'None attached'}
                                </HeroRow>
                            </div>
                        </div>

                        {/* Approver line */}
                        <div className="mt-3 flex items-center gap-[11px] rounded-[12px] border border-status-success/40 bg-status-success-bg px-3.5 py-[11px]">
                            <span className="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-card text-status-success">
                                <UserCheck className="h-[17px] w-[17px]" />
                            </span>
                            <div className="flex-1 text-[12.5px] leading-snug text-foreground">
                                {isSelf
                                    ? `Goes to ${preview?.approver ?? 'your team lead'}${preview?.approver ? ' (Team Lead)' : ''}${preview?.approval_due_at ? ` · usually approved by ${new Date(preview.approval_due_at).toLocaleDateString('en-NZ', { day: 'numeric', month: 'short' })}` : ' · usually approved within 2 days'}.`
                                    : 'You can approve this now, or it will route to the duty manager.'}
                            </div>
                        </div>

                        {preview?.has_roster_conflict ? (
                            <div className="mt-2.5 flex items-center gap-2.5 rounded-[12px] border border-status-warning/40 bg-status-warning-bg px-3.5 py-2.5 text-[12.5px] font-semibold text-status-warning">
                                <CalendarRange className="h-4 w-4 shrink-0" />
                                Heads up —{' '}
                                {isSelf ? 'you are' : `${staffFirst} is`}{' '}
                                rostered on a shift during these dates.
                            </div>
                        ) : null}

                        {preview?.insufficient ? (
                            <div className="mt-2.5 flex items-center gap-2.5 rounded-[12px] border border-status-critical/40 bg-status-critical-bg px-3.5 py-2.5 text-[12.5px] font-semibold text-status-critical">
                                <AlertTriangle className="h-4 w-4 shrink-0" />
                                Not enough balance — this will go negative. A
                                manager can still approve with escalation.
                            </div>
                        ) : null}
                    </div>
                </WizardStepPane>
            )}
        </WizardShell>
    );
}

/** Label / value line in the review hero summary. */
function HeroRow({
    label,
    children,
    last,
}: {
    label: string;
    children: ReactNode;
    last?: boolean;
}) {
    return (
        <div
            className={cn(
                'flex items-center justify-between gap-4 py-[11px]',
                last ? '' : 'border-b border-muted',
            )}
        >
            <span className="shrink-0 text-[13px] text-muted-foreground">
                {label}
            </span>
            <span className="min-w-0 text-right text-[13px] font-medium">
                {children}
            </span>
        </div>
    );
}

/** Hatched primary fill showing how much of the available balance the request uses. */
function BalanceBar({
    available,
    requested,
    insufficient,
}: {
    available: number;
    requested: number;
    insufficient: boolean;
}) {
    const pct =
        available > 0
            ? Math.min(100, Math.round((requested / available) * 100))
            : requested > 0
              ? 100
              : 0;
    return (
        <div className="h-2 overflow-hidden rounded-full bg-muted">
            <div
                className="h-full rounded-full transition-[width] duration-300"
                style={{
                    width: `${pct}%`,
                    background: insufficient
                        ? 'var(--status-critical)'
                        : 'repeating-linear-gradient(45deg, var(--primary), var(--primary) 3px, var(--ring) 3px, var(--ring) 6px)',
                }}
            />
        </div>
    );
}

/** Live "leave balance" rail card — remaining now + projected after this request. */
function BalanceCard({
    typeLabel,
    icon: Icon,
    accent,
    preview,
    requested,
    hasRange,
}: {
    typeLabel: string;
    icon: IconType;
    accent: string;
    preview: LeavePreview | null;
    requested: number;
    hasRange: boolean;
}) {
    const remaining = preview?.available_before ?? null;
    const after = preview?.projected_remaining ?? null;
    const insufficient = !!preview?.insufficient;
    const days = (h: number) => Math.round(h / 8);

    return (
        <div className="relative">
            {/* decorative palm + sun motif */}
            <svg
                aria-hidden="true"
                viewBox="0 0 120 120"
                className="pointer-events-none absolute -right-3 -bottom-2 h-[120px] w-[120px] opacity-50"
                fill="none"
                strokeWidth="2.2"
                strokeLinecap="round"
            >
                <circle cx="88" cy="34" r="13" stroke="var(--status-warning)" />
                <path d="M42 118 C44 96 46 72 50 56" stroke="var(--primary)" />
                <path
                    d="M50 56 C40 50 28 50 18 58"
                    stroke="var(--status-success)"
                />
                <path
                    d="M50 56 C44 44 34 38 22 38"
                    stroke="var(--status-success)"
                />
                <path
                    d="M50 56 C56 46 66 42 78 44"
                    stroke="var(--status-success)"
                />
                <path
                    d="M50 56 C58 52 70 52 80 60"
                    stroke="var(--status-success)"
                />
            </svg>

            <div className="relative rounded-[14px] border border-border bg-card p-3.5 shadow-[0_2px_10px_-4px_color-mix(in_oklch,var(--primary)_25%,transparent)]">
                <div className="flex items-center justify-between">
                    <span className="truncate text-[11px] font-bold tracking-wide text-muted-foreground uppercase">
                        {typeLabel}
                    </span>
                    <span
                        className="grid h-[22px] w-[22px] shrink-0 place-items-center rounded-[7px]"
                        style={{
                            background: `color-mix(in oklch, ${accent} 12%, transparent)`,
                            color: accent,
                        }}
                    >
                        <Icon className="h-[13px] w-[13px]" />
                    </span>
                </div>

                {remaining != null ? (
                    <>
                        <div className="mt-2 flex items-baseline gap-1.5">
                            <span className="text-[26px] leading-none font-bold tracking-tight">
                                {remaining}h
                            </span>
                            <span className="text-xs text-muted-foreground">
                                ≈ {days(remaining)} days left
                            </span>
                        </div>
                        <div className="mt-2.5">
                            <BalanceBar
                                available={remaining}
                                requested={requested}
                                insufficient={insufficient}
                            />
                        </div>
                        {after != null && requested > 0 ? (
                            <div className="mt-2.5 flex items-center gap-1.5 text-[11.5px] text-muted-foreground">
                                <span className="h-2 w-2 rounded-[2px] bg-primary" />
                                After this request{' '}
                                <strong
                                    className={cn(
                                        'font-bold',
                                        insufficient
                                            ? 'text-status-critical'
                                            : 'text-status-success',
                                    )}
                                >
                                    {after}h
                                </strong>{' '}
                                ({days(after)} days)
                            </div>
                        ) : null}
                    </>
                ) : (
                    <div className="mt-2 flex items-center gap-2 text-xs text-muted-foreground">
                        <Users className="h-3.5 w-3.5 shrink-0" />
                        {hasRange
                            ? 'Calculating your balance…'
                            : 'Pick a type and dates to see your balance.'}
                    </div>
                )}
            </div>
        </div>
    );
}

export default LeaveRequestDialog;
