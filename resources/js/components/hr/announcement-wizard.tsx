/* eslint-disable no-restricted-syntax -- The audience chips, schedule tiles and
 * channel toggles are bespoke wizard surfaces (raw <button>/<textarea>) styled
 * with semantic design tokens, matching the Add-Client / Leave-request kit. */
import {
    Field,
    InfoCard,
    ReviewCard,
    ReviewRow,
    Ring,
    StepHead,
    TilePicker,
    WizardShell,
    WizardStepPane,
    WizardSuccessPane,
    useWizard,
    type IconType,
    type WizardStep,
} from '@/components/hr/wizard';
import { Button } from '@/components/ui/button';
import {
    FileDropzone,
    StagedFileCard,
    formatFileSize,
} from '@/components/ui/file-dropzone';
import { Input } from '@/components/ui/input';
import { fireConfetti } from '@/lib/confetti';
import { cn } from '@/lib/utils';
import { useForm } from '@inertiajs/react';
import {
    AlertCircle,
    AlertTriangle,
    ArrowLeft,
    ArrowRight,
    Bell,
    CalendarClock,
    CheckCheck,
    Eye,
    Globe,
    Info,
    Megaphone,
    Send,
    Users,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';

export type SegmentOption = { key: string; label: string; count: number };
export type AnnouncementSegments = {
    all_count: number;
    sites: SegmentOption[];
    departments: SegmentOption[];
    roles: SegmentOption[];
};
export type AnnouncementTarget = {
    type: 'all' | 'site' | 'department' | 'role' | 'user';
    value: string | null;
};
export type AnnouncementWizardInitial = {
    id?: number;
    title?: string;
    content?: string;
    priority?: string;
    status?: string;
    targets?: AnnouncementTarget[];
    published_at?: string | null;
    expires_at?: string | null;
    ack_deadline?: string | null;
    recurrence?: string | null;
    recurrence_ends_at?: string | null;
    is_pinned?: boolean;
    requires_acknowledgement?: boolean;
};

type Priority = 'low' | 'normal' | 'high' | 'urgent';

const STEPS: WizardStep[] = [
    {
        key: 'message',
        label: 'Message',
        blurb: 'Title, content, priority',
        icon: Megaphone,
    },
    {
        key: 'audience',
        label: 'Audience',
        blurb: 'Who receives it',
        icon: Users,
    },
    {
        key: 'delivery',
        label: 'Delivery',
        blurb: 'Schedule & channels',
        icon: CalendarClock,
    },
    {
        key: 'review',
        label: 'Review',
        blurb: 'Confirm & publish',
        icon: CheckCheck,
    },
];

const PRIORITY_TILES: {
    key: Priority;
    label: string;
    description: string;
    icon: IconType;
    accent: string;
}[] = [
    {
        key: 'low',
        label: 'Low',
        description: 'FYI, no rush',
        icon: Info,
        accent: 'text-muted-foreground',
    },
    {
        key: 'normal',
        label: 'Normal',
        description: 'Standard notice',
        icon: Info,
        accent: 'text-status-info',
    },
    {
        key: 'high',
        label: 'High',
        description: 'Please read soon',
        icon: AlertTriangle,
        accent: 'text-status-warning',
    },
    {
        key: 'urgent',
        label: 'Urgent',
        description: 'Action required now',
        icon: AlertCircle,
        accent: 'text-status-critical',
    },
];

function toDatetimeLocal(value?: string | null): string {
    if (!value) return '';
    const d = new Date(value);
    if (Number.isNaN(d.getTime())) return '';
    const pad = (n: number) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

function toDateOnly(value?: string | null): string {
    if (!value) return '';
    const d = new Date(value);
    if (Number.isNaN(d.getTime())) return '';
    const pad = (n: number) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
}

/** Selected-segment keys split from an initial targets list. */
function splitInitialTargets(targets?: AnnouncementTarget[]) {
    const all =
        (targets ?? []).some((t) => t.type === 'all') ||
        !targets ||
        targets.length === 0;
    const pick = (type: AnnouncementTarget['type']) =>
        (targets ?? [])
            .filter((t) => t.type === type && t.value)
            .map((t) => String(t.value));
    return {
        all,
        sites: pick('site'),
        departments: pick('department'),
        roles: pick('role'),
    };
}

export function AnnouncementWizard({
    open,
    onClose,
    segments,
    announcementId,
    initial,
    onSuccess,
}: {
    open: boolean;
    onClose: () => void;
    segments: AnnouncementSegments;
    announcementId?: number;
    initial?: AnnouncementWizardInitial;
    onSuccess?: () => void;
}) {
    const editing = Boolean(announcementId);
    const wizard = useWizard(STEPS.length);
    const [done, setDone] = useState(false);
    const [recipientCount, setRecipientCount] = useState<number | null>(null);
    const [staffPreview, setStaffPreview] = useState(false);
    const [keepOpen, setKeepOpen] = useState(false);

    const initialSplit = useMemo(
        () => splitInitialTargets(initial?.targets),
        [initial],
    );

    const [targetAll, setTargetAll] = useState(initialSplit.all);
    const [siteKeys, setSiteKeys] = useState<string[]>(initialSplit.sites);
    const [deptKeys, setDeptKeys] = useState<string[]>(
        initialSplit.departments,
    );
    const [roleKeys, setRoleKeys] = useState<string[]>(initialSplit.roles);
    const [scheduleMode, setScheduleMode] = useState<'now' | 'later'>(
        initial?.status === 'scheduled' ? 'later' : 'now',
    );

    const form = useForm<{
        title: string;
        content: string;
        priority: Priority;
        published_at: string;
        expires_at: string;
        ack_deadline: string;
        recurrence: string;
        recurrence_ends_at: string;
        is_pinned: boolean;
        requires_acknowledgement: boolean;
        push_to_bell: boolean;
        attachments: File[];
    }>({
        title: initial?.title ?? '',
        content: initial?.content ?? '',
        priority: (initial?.priority as Priority) ?? 'normal',
        published_at: toDatetimeLocal(initial?.published_at),
        expires_at: toDateOnly(initial?.expires_at),
        ack_deadline: toDateOnly(initial?.ack_deadline),
        recurrence: initial?.recurrence ?? '',
        recurrence_ends_at: toDateOnly(initial?.recurrence_ends_at),
        is_pinned: initial?.is_pinned ?? false,
        requires_acknowledgement: initial?.requires_acknowledgement ?? false,
        push_to_bell:
            initial?.priority === 'high' || initial?.priority === 'urgent',
        attachments: [] as File[],
    });

    const buildTargets = (): AnnouncementTarget[] => {
        if (targetAll) return [{ type: 'all', value: null }];
        const out: AnnouncementTarget[] = [];
        siteKeys.forEach((v) => out.push({ type: 'site', value: v }));
        deptKeys.forEach((v) => out.push({ type: 'department', value: v }));
        roleKeys.forEach((v) => out.push({ type: 'role', value: v }));
        return out;
    };

    const targetSignature = JSON.stringify({
        targetAll,
        siteKeys,
        deptKeys,
        roleKeys,
    });

    // Live recipient count — debounced, cancellable.
    useEffect(() => {
        if (!open) return;
        const targets = buildTargets();
        if (targets.length === 0) {
            setRecipientCount(0);
            return;
        }
        const controller = new AbortController();
        const timer = setTimeout(() => {
            fetch(
                `/hr/announcements/preview?targets=${encodeURIComponent(JSON.stringify(targets))}`,
                {
                    headers: { Accept: 'application/json' },
                    signal: controller.signal,
                },
            )
                .then((r) => r.json())
                .then((d) =>
                    setRecipientCount(
                        typeof d.count === 'number' ? d.count : null,
                    ),
                )
                .catch(() => {});
        }, 300);
        return () => {
            clearTimeout(timer);
            controller.abort();
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, targetSignature]);

    const completeness = useMemo(() => {
        let pct = 0;
        if (form.data.title.trim()) pct += 30;
        if (form.data.content.trim()) pct += 30;
        if (targetAll || siteKeys.length || deptKeys.length || roleKeys.length)
            pct += 25;
        pct += Math.round((wizard.index / (STEPS.length - 1)) * 15);
        return Math.min(100, pct);
    }, [
        form.data.title,
        form.data.content,
        targetAll,
        siteKeys,
        deptKeys,
        roleKeys,
        wizard.index,
    ]);

    const reset = () => {
        form.reset();
        form.clearErrors();
        setTargetAll(true);
        setSiteKeys([]);
        setDeptKeys([]);
        setRoleKeys([]);
        setScheduleMode('now');
        wizard.reset();
        setRecipientCount(null);
    };

    const close = () => {
        setDone(false);
        onClose();
    };

    const submit = (intent: 'publish' | 'schedule' | 'draft') => {
        form.transform((data) => {
            const payload: Record<string, unknown> = {
                ...data,
                intent,
                targets: buildTargets(),
                expires_at: data.expires_at || null,
                ack_deadline: data.ack_deadline || null,
                recurrence:
                    intent === 'schedule' ? data.recurrence || null : null,
                recurrence_ends_at:
                    intent === 'schedule'
                        ? data.recurrence_ends_at || null
                        : null,
                published_at:
                    intent === 'schedule'
                        ? data.published_at || null
                        : intent === 'draft'
                          ? data.published_at || null
                          : null,
            };
            if (editing) payload._method = 'put';
            return payload as unknown as typeof data;
        });

        const url = editing
            ? `/hr/announcements/${announcementId}`
            : '/hr/announcements';
        form.post(url, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                onSuccess?.();
                if (
                    intent === 'publish' &&
                    recipientCount &&
                    recipientCount > 0
                ) {
                    fireConfetti();
                }
                if (keepOpen && !editing) {
                    reset();
                    toast.success('Saved — start another');
                    return;
                }
                setDone(true);
            },
            onError: () => {
                // Jump to the earliest step holding an error.
                if (
                    form.errors.title ||
                    form.errors.content ||
                    form.errors.priority
                )
                    wizard.goTo(0);
            },
        });
    };

    const hasAudience =
        targetAll ||
        siteKeys.length > 0 ||
        deptKeys.length > 0 ||
        roleKeys.length > 0;
    const primaryLabel =
        scheduleMode === 'later'
            ? 'Schedule'
            : editing
              ? 'Save changes'
              : 'Publish';
    const primaryIntent: 'publish' | 'schedule' =
        scheduleMode === 'later' ? 'schedule' : 'publish';

    const scheduledInPast =
        scheduleMode === 'later' &&
        form.data.published_at &&
        new Date(form.data.published_at).getTime() < Date.now();
    const urgentNoDeadline =
        form.data.priority === 'urgent' &&
        form.data.requires_acknowledgement &&
        !form.data.ack_deadline;

    const audienceLabel = useMemo(() => {
        if (targetAll) return 'All staff';
        const parts: string[] = [];
        if (roleKeys.length)
            parts.push(
                `${roleKeys.length} role${roleKeys.length > 1 ? 's' : ''}`,
            );
        if (deptKeys.length)
            parts.push(
                `${deptKeys.length} dept${deptKeys.length > 1 ? 's' : ''}`,
            );
        if (siteKeys.length)
            parts.push(
                `${siteKeys.length} site${siteKeys.length > 1 ? 's' : ''}`,
            );
        return parts.join(' · ') || 'No one yet';
    }, [targetAll, roleKeys, deptKeys, siteKeys]);

    const success = done ? (
        <WizardSuccessPane
            title={
                editing
                    ? 'Announcement updated'
                    : scheduleMode === 'later'
                      ? 'Announcement scheduled'
                      : 'Announcement published'
            }
            blurb={
                scheduleMode === 'later'
                    ? 'It will send automatically at the time you set and alert the audience then.'
                    : `Delivered to ${recipientCount ?? '—'} recipients across in-app and the header bell.`
            }
            actions={<Button onClick={close}>Done</Button>}
        />
    ) : undefined;

    const railExtra = (
        <div className="rounded-xl border border-border bg-card p-3">
            <div className="text-[10px] font-bold tracking-wide text-muted-foreground uppercase">
                Live delivery
            </div>
            <div className="mt-1.5 flex items-baseline gap-1.5">
                <span className="text-2xl font-bold text-primary tabular-nums">
                    {recipientCount ?? '—'}
                </span>
                <span className="text-[11px] text-muted-foreground">
                    recipients
                </span>
            </div>
            <div className="mt-1 text-[11px] text-muted-foreground">
                {scheduleMode === 'later' ? 'Scheduled' : 'Sends immediately'}
                {form.data.requires_acknowledgement ? ' · ack required' : ''}
            </div>
        </div>
    );

    return (
        <WizardShell
            open={open}
            onClose={close}
            title={editing ? 'Edit announcement' : 'New announcement'}
            description="Compose, target, schedule and publish a company announcement."
            railIcon={Megaphone}
            railTitle={editing ? 'Edit announcement' : 'New announcement'}
            railSub="Company communications"
            steps={STEPS}
            stepIndex={wizard.index}
            onStepClick={wizard.goTo}
            pct={completeness}
            railExtra={railExtra}
            success={success}
            footerStart={
                wizard.index > 0 ? (
                    <Button variant="outline" onClick={wizard.back}>
                        <ArrowLeft className="mr-1.5 h-4 w-4" /> Back
                    </Button>
                ) : (
                    <Button variant="ghost" onClick={close}>
                        Cancel
                    </Button>
                )
            }
            footerEnd={
                wizard.isLast ? (
                    <>
                        {!editing && (
                            <Button
                                variant="outline"
                                onClick={() => submit('draft')}
                                disabled={form.processing}
                            >
                                Save draft
                            </Button>
                        )}
                        <Button
                            onClick={() => submit(primaryIntent)}
                            disabled={form.processing || !hasAudience}
                        >
                            <Send className="mr-1.5 h-4 w-4" /> {primaryLabel}
                        </Button>
                    </>
                ) : (
                    <Button onClick={wizard.next}>
                        Continue <ArrowRight className="ml-1.5 h-4 w-4" />
                    </Button>
                )
            }
        >
            {/* STEP 0 — MESSAGE */}
            {wizard.index === 0 && (
                <WizardStepPane>
                    <StepHead
                        icon={Megaphone}
                        title="Message"
                        blurb="What do you want to tell the team?"
                    />
                    <div className="space-y-5">
                        <Field label="Title" required error={form.errors.title}>
                            <Input
                                value={form.data.title}
                                onChange={(e) =>
                                    form.setData('title', e.target.value)
                                }
                                placeholder="e.g. Updated visitor sign-in procedure"
                                maxLength={255}
                            />
                        </Field>
                        <Field
                            label="Content"
                            required
                            error={form.errors.content}
                        >
                            <textarea
                                value={form.data.content}
                                onChange={(e) =>
                                    form.setData('content', e.target.value)
                                }
                                placeholder="Write your announcement…"
                                rows={6}
                                maxLength={10000}
                                className="w-full resize-y rounded-md border border-border bg-card px-3 py-2 text-sm text-foreground outline-none focus-visible:ring-2 focus-visible:ring-primary"
                            />
                        </Field>
                        <div>
                            <div className="mb-1.5 text-sm font-medium">
                                Priority
                            </div>
                            <TilePicker
                                cols={2}
                                value={form.data.priority}
                                onChange={(v) => {
                                    form.setData('priority', v as Priority);
                                    if (v === 'high' || v === 'urgent')
                                        form.setData('push_to_bell', true);
                                }}
                                options={PRIORITY_TILES}
                            />
                        </div>
                        <div>
                            <div className="mb-1.5 text-sm font-medium">
                                Attachments
                            </div>
                            <FileDropzone
                                accept=".pdf,.jpg,.jpeg,.png,.gif,.webp,.doc,.docx,.xls,.xlsx"
                                hint="PDF, images, documents — up to 10MB each"
                                onFiles={(files) =>
                                    form.setData('attachments', [
                                        ...form.data.attachments,
                                        ...files,
                                    ])
                                }
                            />
                            {form.data.attachments.length > 0 && (
                                <div className="mt-3 grid gap-2 sm:grid-cols-2">
                                    {form.data.attachments.map((file, i) => (
                                        <StagedFileCard
                                            key={`${file.name}-${i}`}
                                            file={file}
                                            onRemove={() =>
                                                form.setData(
                                                    'attachments',
                                                    form.data.attachments.filter(
                                                        (_, idx) => idx !== i,
                                                    ),
                                                )
                                            }
                                        />
                                    ))}
                                </div>
                            )}
                        </div>
                    </div>
                </WizardStepPane>
            )}

            {/* STEP 1 — AUDIENCE */}
            {wizard.index === 1 && (
                <WizardStepPane>
                    <StepHead
                        icon={Users}
                        title="Audience"
                        blurb="Target any combination of sites, departments and roles."
                    />
                    <button
                        type="button"
                        onClick={() => setTargetAll((v) => !v)}
                        className={cn(
                            'flex w-full items-center justify-between rounded-xl border p-3.5 text-left transition-colors',
                            targetAll
                                ? 'border-primary bg-primary/10'
                                : 'border-border bg-card hover:border-primary/50',
                        )}
                    >
                        <span className="flex items-center gap-3">
                            <Globe className="h-5 w-5 text-primary" />
                            <span>
                                <span className="block text-sm font-bold">
                                    All staff
                                </span>
                                <span className="block text-xs text-muted-foreground">
                                    Everyone in your organisation ·{' '}
                                    {segments.all_count} people
                                </span>
                            </span>
                        </span>
                        <span
                            className={cn(
                                'grid h-5 w-5 place-items-center rounded-full border',
                                targetAll
                                    ? 'border-primary bg-primary text-primary-foreground'
                                    : 'border-border',
                            )}
                        >
                            {targetAll && <CheckCheck className="h-3 w-3" />}
                        </span>
                    </button>

                    {!targetAll && (
                        <div className="mt-5 space-y-5">
                            <SegmentGroup
                                title="Sites"
                                options={segments.sites}
                                selected={siteKeys}
                                onChange={setSiteKeys}
                            />
                            <SegmentGroup
                                title="Departments"
                                options={segments.departments}
                                selected={deptKeys}
                                onChange={setDeptKeys}
                            />
                            <SegmentGroup
                                title="Roles"
                                options={segments.roles}
                                selected={roleKeys}
                                onChange={setRoleKeys}
                            />
                        </div>
                    )}

                    <div className="mt-6">
                        <InfoCard
                            icon={Users}
                            tone={recipientCount === 0 ? 'warn' : 'info'}
                        >
                            <b>{recipientCount ?? '—'} recipients</b> match this
                            targeting.
                            {recipientCount === 0 &&
                                ' Select at least one segment.'}
                        </InfoCard>
                    </div>
                </WizardStepPane>
            )}

            {/* STEP 2 — DELIVERY */}
            {wizard.index === 2 && (
                <WizardStepPane>
                    <StepHead
                        icon={CalendarClock}
                        title="Delivery & schedule"
                        blurb="When it sends, when it expires, and how people get it."
                    />
                    <div className="space-y-5">
                        <div className="grid grid-cols-1 gap-2.5 sm:grid-cols-2">
                            <ScheduleTile
                                active={scheduleMode === 'now'}
                                icon={Send}
                                title="Publish now"
                                blurb="Goes live immediately"
                                onClick={() => setScheduleMode('now')}
                            />
                            <ScheduleTile
                                active={scheduleMode === 'later'}
                                icon={CalendarClock}
                                title="Schedule"
                                blurb="Send at a set time"
                                onClick={() => setScheduleMode('later')}
                            />
                        </div>

                        {scheduleMode === 'later' && (
                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <Field
                                    label="Publish at"
                                    required
                                    error={form.errors.published_at}
                                >
                                    <Input
                                        type="datetime-local"
                                        value={form.data.published_at}
                                        onChange={(e) =>
                                            form.setData(
                                                'published_at',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </Field>
                                <Field label="Repeats">
                                    <select
                                        value={form.data.recurrence}
                                        onChange={(e) =>
                                            form.setData(
                                                'recurrence',
                                                e.target.value,
                                            )
                                        }
                                        className="h-10 w-full rounded-md border border-border bg-card px-3 text-sm outline-none focus-visible:ring-2 focus-visible:ring-primary"
                                    >
                                        <option value="">
                                            Does not repeat
                                        </option>
                                        <option value="weekly">Weekly</option>
                                        <option value="monthly">Monthly</option>
                                    </select>
                                </Field>
                            </div>
                        )}

                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <Field label="Expires" hint="optional">
                                <Input
                                    type="date"
                                    value={form.data.expires_at}
                                    onChange={(e) =>
                                        form.setData(
                                            'expires_at',
                                            e.target.value,
                                        )
                                    }
                                />
                            </Field>
                            <Field
                                label="Acknowledgement deadline"
                                hint="optional"
                            >
                                <Input
                                    type="date"
                                    value={form.data.ack_deadline}
                                    onChange={(e) =>
                                        form.setData(
                                            'ack_deadline',
                                            e.target.value,
                                        )
                                    }
                                />
                            </Field>
                        </div>

                        <div className="divide-y divide-border overflow-hidden rounded-xl border border-border">
                            <ToggleRow
                                icon={CheckCheck}
                                title="Require acknowledgement"
                                blurb="Track who has read & confirmed"
                                checked={form.data.requires_acknowledgement}
                                onChange={(v) =>
                                    form.setData('requires_acknowledgement', v)
                                }
                            />
                            <ToggleRow
                                icon={Bell}
                                title="Push to header-bell inbox"
                                blurb="Also appears in the notification centre"
                                checked={form.data.push_to_bell}
                                onChange={(v) =>
                                    form.setData('push_to_bell', v)
                                }
                            />
                        </div>
                    </div>
                </WizardStepPane>
            )}

            {/* STEP 3 — REVIEW */}
            {wizard.index === 3 && (
                <WizardStepPane>
                    <StepHead
                        icon={CheckCheck}
                        title="Review & publish"
                        blurb="Check it over before it goes out."
                    />
                    <div className="space-y-4">
                        <ReviewCard
                            icon={Megaphone}
                            title="Message"
                            onEdit={() => wizard.goTo(0)}
                        >
                            <div className="flex items-center gap-2">
                                <Ring pct={completeness} size={44} />
                                <div className="min-w-0">
                                    <div className="truncate text-sm font-bold">
                                        {form.data.title || 'Untitled'}
                                    </div>
                                    <div className="line-clamp-2 text-xs text-muted-foreground">
                                        {form.data.content || '—'}
                                    </div>
                                </div>
                            </div>
                        </ReviewCard>
                        <ReviewCard
                            icon={Users}
                            title="Delivery"
                            onEdit={() => wizard.goTo(1)}
                        >
                            <ReviewRow label="Audience" value={audienceLabel} />
                            <ReviewRow
                                label="Recipients"
                                value={
                                    recipientCount != null
                                        ? String(recipientCount)
                                        : '—'
                                }
                            />
                            <ReviewRow
                                label="Priority"
                                value={form.data.priority}
                            />
                            <ReviewRow
                                label="When"
                                value={
                                    scheduleMode === 'later'
                                        ? form.data.published_at || 'Scheduled'
                                        : 'Immediately'
                                }
                            />
                            <ReviewRow
                                label="Requires ack"
                                value={
                                    form.data.requires_acknowledgement
                                        ? 'Yes'
                                        : 'No'
                                }
                            />
                            <ReviewRow
                                label="Header bell"
                                value={form.data.push_to_bell ? 'On' : 'Off'}
                            />
                            <ReviewRow
                                label="Attachments"
                                value={form.data.attachments.length || '—'}
                            />
                        </ReviewCard>

                        {recipientCount === 0 && (
                            <InfoCard icon={AlertTriangle} tone="crit">
                                This announcement has <b>no recipients</b>. Add
                                an audience before publishing.
                            </InfoCard>
                        )}
                        {scheduledInPast && (
                            <InfoCard icon={AlertTriangle} tone="warn">
                                The scheduled time is in the past — it will
                                publish immediately.
                            </InfoCard>
                        )}
                        {urgentNoDeadline && (
                            <InfoCard icon={AlertTriangle} tone="warn">
                                This is an <b>urgent</b> notice with no
                                acknowledgement deadline. Consider adding one so
                                reminders can escalate.
                            </InfoCard>
                        )}

                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <button
                                type="button"
                                onClick={() => setStaffPreview((v) => !v)}
                                className="inline-flex items-center gap-1.5 rounded-md border border-border bg-card px-3 py-1.5 text-xs font-semibold hover:bg-accent"
                            >
                                <Eye className="h-3.5 w-3.5" />{' '}
                                {staffPreview
                                    ? 'Hide staff preview'
                                    : 'Preview as staff'}
                            </button>
                            {!editing && (
                                <label className="flex items-center gap-2 text-xs font-medium text-muted-foreground">
                                    <input
                                        type="checkbox"
                                        checked={keepOpen}
                                        onChange={(e) =>
                                            setKeepOpen(e.target.checked)
                                        }
                                    />
                                    Keep open to add another
                                </label>
                            )}
                        </div>

                        {staffPreview && (
                            <div className="rounded-xl border border-border bg-muted/30 p-4">
                                <div className="text-[10px] font-bold tracking-wide text-muted-foreground uppercase">
                                    As staff will see it
                                </div>
                                <div className="mt-2 text-base font-bold">
                                    {form.data.title || 'Untitled'}
                                </div>
                                <div className="mt-1 text-sm whitespace-pre-wrap text-muted-foreground">
                                    {form.data.content || '—'}
                                </div>
                                {form.data.attachments.length > 0 && (
                                    <div className="mt-2 text-xs text-muted-foreground">
                                        {form.data.attachments.length}{' '}
                                        attachment(s) ·{' '}
                                        {formatFileSize(
                                            form.data.attachments.reduce(
                                                (s, f) => s + f.size,
                                                0,
                                            ),
                                        )}
                                    </div>
                                )}
                            </div>
                        )}
                    </div>
                </WizardStepPane>
            )}
        </WizardShell>
    );
}

function SegmentGroup({
    title,
    options,
    selected,
    onChange,
}: {
    title: string;
    options: SegmentOption[];
    selected: string[];
    onChange: (next: string[]) => void;
}) {
    if (options.length === 0) return null;
    const toggle = (key: string) =>
        onChange(
            selected.includes(key)
                ? selected.filter((k) => k !== key)
                : [...selected, key],
        );
    return (
        <div>
            <div className="mb-2 text-[11px] font-bold tracking-wide text-muted-foreground uppercase">
                {title}
            </div>
            <div className="flex flex-wrap gap-2">
                {options.map((o) => {
                    const active = selected.includes(o.key);
                    return (
                        <button
                            key={o.key}
                            type="button"
                            onClick={() => toggle(o.key)}
                            className={cn(
                                'inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-[13px] font-medium transition-colors',
                                active
                                    ? 'border-primary bg-primary/10 text-primary'
                                    : 'border-border bg-card hover:border-primary/50',
                            )}
                        >
                            {o.label}
                            <span className="opacity-60">· {o.count}</span>
                        </button>
                    );
                })}
            </div>
        </div>
    );
}

function ScheduleTile({
    active,
    icon: Icon,
    title,
    blurb,
    onClick,
}: {
    active: boolean;
    icon: IconType;
    title: string;
    blurb: string;
    onClick: () => void;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            className={cn(
                'flex items-start gap-2.5 rounded-lg border p-3 text-left transition-all hover:border-primary/50',
                active
                    ? 'border-primary bg-primary/10 ring-1 ring-primary/40'
                    : 'border-border bg-card/50',
            )}
        >
            <Icon className="mt-0.5 h-5 w-5 text-primary" />
            <span>
                <span className="block text-sm font-semibold">{title}</span>
                <span className="block text-xs text-muted-foreground">
                    {blurb}
                </span>
            </span>
        </button>
    );
}

function ToggleRow({
    icon: Icon,
    title,
    blurb,
    checked,
    onChange,
}: {
    icon: IconType;
    title: string;
    blurb: string;
    checked: boolean;
    onChange: (v: boolean) => void;
}) {
    return (
        <button
            type="button"
            onClick={() => onChange(!checked)}
            className="flex w-full items-center justify-between gap-3 bg-card px-4 py-3.5 text-left"
        >
            <span className="flex items-center gap-3">
                <Icon className="h-4 w-4 text-muted-foreground" />
                <span>
                    <span className="block text-sm font-semibold">{title}</span>
                    <span className="block text-xs text-muted-foreground">
                        {blurb}
                    </span>
                </span>
            </span>
            <span
                className={cn(
                    'relative h-6 w-11 shrink-0 rounded-full transition-colors',
                    checked ? 'bg-primary' : 'bg-muted',
                )}
            >
                <span
                    className={cn(
                        'absolute top-0.5 h-5 w-5 rounded-full bg-card shadow transition-all',
                        checked ? 'left-[22px]' : 'left-0.5',
                    )}
                />
            </span>
        </button>
    );
}

export default AnnouncementWizard;
