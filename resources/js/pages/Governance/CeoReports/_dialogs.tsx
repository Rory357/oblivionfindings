import { ConfirmDialog } from '@/components/confirm-dialog';
import { DiscardDraftDialog } from '@/components/governance/DiscardDraftDialog';
import {
    firstErrorStep,
    pageHasFlashError,
} from '@/components/governance/governance-dialog-deep-link';
import { Button } from '@/components/ui/button';
import { Card as GuardrailCard } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import type { StatusVariant } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import {
    Field,
    InfoCard,
    SelectInput,
    StepHead,
} from '@/components/wizard/primitives';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
    WizardSuccessPane,
    type WizardStep,
} from '@/components/wizard/shell';
import {
    formatDateLong,
    formatDateOnly,
    toDateInput,
    toDatetimeLocal,
} from '@/lib/datetime';
import { governanceStatus } from '@/lib/governance-labels';
import { router, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    Briefcase,
    CalendarDays,
    ChevronLeft,
    ChevronRight,
    ClipboardCheck,
    FileText,
    Gavel,
    Loader2,
    Lock,
    MessageCircleQuestion,
    Paperclip,
    Plus,
    Save,
    Send,
    ShieldCheck,
    Trash2,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { AttachmentsPanel, type Attachment } from './_attachments';

export function ceoReportStatusVariant(status: string): StatusVariant {
    return governanceStatus('ceo_report_status', status).variant;
}

export const ceoReportStatusLabel = (status: string) =>
    governanceStatus('ceo_report_status', status).label;

/** One chip for a report: "Overdue" wins while a draft is past its deadline. */
export function ceoReportChip(status: string, isOverdue: boolean) {
    return governanceStatus(
        'ceo_report_status',
        isOverdue && status === 'draft' ? 'overdue' : status,
    );
}

/** Matters arising carry their own simple status. */
export const MATTER_STATUSES = [
    { value: 'open', label: 'Open', variant: 'warning' as StatusVariant },
    {
        value: 'in_progress',
        label: 'In progress',
        variant: 'info' as StatusVariant,
    },
    { value: 'done', label: 'Done', variant: 'success' as StatusVariant },
];

export function matterStatus(status: string | null | undefined) {
    return (
        MATTER_STATUSES.find((option) => option.value === status) ??
        MATTER_STATUSES[0]
    );
}

/** "2026-09-10T17:00" (NZ wall time from a datetime input) → "10 Sep 2026, 5:00 pm". */
export function formatWallTime(value: string | null | undefined): string | null {
    if (!value) return null;
    const match = /^(\d{4}-\d{2}-\d{2})T(\d{2}):(\d{2})/.exec(value);
    if (!match) return null;
    const [, date, hours, minutes] = match;
    const hour = Number(hours);
    const suffix = hour >= 12 ? 'pm' : 'am';
    const twelve = hour % 12 === 0 ? 12 : hour % 12;
    return `${formatDateOnly(date)}, ${twelve}:${minutes} ${suffix}`;
}

/** Whole-day arithmetic on a calendar date string, free of time zones. */
function shiftDate(ymd: string, days: number, months = 0): string {
    const [year, month, day] = ymd.split('-').map(Number);
    const shifted = new Date(Date.UTC(year, month - 1 + months, day + days));
    return shifted.toISOString().slice(0, 10);
}

// ── Form shape ────────────────────────────────────────────────────────────

export interface MeetingOption {
    id: number;
    title: string;
    scheduled_at: string | null;
}

export interface DecisionSoughtRow {
    title: string;
    detail: string;
    recommendation: string;
}

export interface MatterArisingRow {
    title: string;
    status: 'open' | 'in_progress' | 'done' | string;
    update: string;
}

export type CeoReportFormValues = {
    governance_meeting_id: string;
    period_start: string;
    period_end: string;
    deadline: string;
    executive_summary: string;
    operational_summary: string;
    key_achievements: string;
    challenges_and_risks: string;
    staffing_update: string;
    compliance_status: string;
    financial_summary: string;
    recommendations: string;
    decisions_sought: DecisionSoughtRow[];
    matters_arising: MatterArisingRow[];
};

export interface CeoReportInitialValues extends Partial<CeoReportFormValues> {
    id?: number;
    attachments?: Attachment[];
}

type SectionKey = Exclude<
    keyof CeoReportFormValues,
    | 'governance_meeting_id'
    | 'period_start'
    | 'period_end'
    | 'deadline'
    | 'decisions_sought'
    | 'matters_arising'
>;

export const CEO_REPORT_SECTIONS: Array<{ key: SectionKey; label: string }> = [
    { key: 'executive_summary', label: 'Executive summary' },
    { key: 'operational_summary', label: 'Operational summary' },
    { key: 'key_achievements', label: 'Key achievements' },
    { key: 'financial_summary', label: 'Financial summary' },
    { key: 'challenges_and_risks', label: 'Challenges and risks' },
    { key: 'compliance_status', label: 'Compliance status' },
    { key: 'staffing_update', label: 'Workforce update' },
    { key: 'recommendations', label: 'Strategic progress' },
];

// ── Steps ─────────────────────────────────────────────────────────────────

type StepKey =
    | 'meeting'
    | 'summary'
    | 'operations'
    | 'finance'
    | 'strategy'
    | 'matters'
    | 'attachments'
    | 'review';

export const CEO_REPORT_STEPS: readonly (WizardStep & { key: StepKey })[] = [
    {
        key: 'meeting',
        label: 'Meeting and period',
        blurb: 'Board meeting, period and deadline',
        icon: CalendarDays,
    },
    {
        key: 'summary',
        label: 'Executive summary',
        blurb: 'Headline outcomes for the board',
        icon: FileText,
    },
    {
        key: 'operations',
        label: 'Operations and people',
        blurb: 'Services, wins and workforce',
        icon: Briefcase,
    },
    {
        key: 'finance',
        label: 'Finance and risk',
        blurb: 'Money, risks and compliance',
        icon: ShieldCheck,
    },
    {
        key: 'strategy',
        label: 'Strategy and decisions',
        blurb: 'Strategic progress and decisions sought',
        icon: Gavel,
    },
    {
        key: 'matters',
        label: 'Matters arising',
        blurb: 'Items carried from last meeting',
        icon: MessageCircleQuestion,
    },
    {
        key: 'attachments',
        label: 'Attachments',
        blurb: 'Supporting documents',
        icon: Paperclip,
    },
    {
        key: 'review',
        label: 'Review',
        blurb: 'Save as draft or submit',
        icon: ClipboardCheck,
    },
];

const FIELD_STEPS: Record<string, StepKey> = {
    governance_meeting_id: 'meeting',
    period_start: 'meeting',
    period_end: 'meeting',
    deadline: 'meeting',
    executive_summary: 'summary',
    operational_summary: 'operations',
    key_achievements: 'operations',
    staffing_update: 'operations',
    financial_summary: 'finance',
    challenges_and_risks: 'finance',
    compliance_status: 'finance',
    recommendations: 'strategy',
    decisions_sought: 'strategy',
    matters_arising: 'matters',
};

// ── Repeatable rows ───────────────────────────────────────────────────────

function DecisionsSoughtEditor({
    rows,
    onChange,
}: {
    rows: DecisionSoughtRow[];
    onChange: (rows: DecisionSoughtRow[]) => void;
}) {
    const update = (i: number, patch: Partial<DecisionSoughtRow>) => {
        onChange(rows.map((r, idx) => (idx === i ? { ...r, ...patch } : r)));
    };
    const add = () =>
        onChange([...rows, { title: '', detail: '', recommendation: '' }]);
    const remove = (i: number) => onChange(rows.filter((_, idx) => idx !== i));

    return (
        <div className="space-y-3">
            {rows.length === 0 && (
                <EmptyState
                    variant="compact"
                    icon={Gavel}
                    title="No decisions for the board"
                    description="Add one if you need the board to decide something."
                />
            )}
            {rows.map((row, i) => (
                <GuardrailCard
                    unstyled
                    key={i}
                    className="space-y-2 rounded-lg border border-border bg-card/40 p-3"
                >
                    <div className="flex items-start justify-between gap-2">
                        <Input
                            aria-label={`Decision ${i + 1} title`}
                            placeholder="e.g. Approve the 2026/27 budget"
                            value={row.title}
                            onChange={(e) =>
                                update(i, { title: e.target.value })
                            }
                            className="font-medium"
                        />
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            aria-label="Remove decision"
                            onClick={() => remove(i)}
                        >
                            <Trash2 className="h-4 w-4 text-status-critical" />
                        </Button>
                    </div>
                    <Textarea
                        rows={2}
                        aria-label={`Decision ${i + 1} background`}
                        placeholder="Why is this decision needed? Background and context."
                        value={row.detail}
                        onChange={(e) => update(i, { detail: e.target.value })}
                    />
                    <Textarea
                        rows={2}
                        aria-label={`Decision ${i + 1} recommendation`}
                        placeholder="What the CEO recommends the board decides."
                        value={row.recommendation}
                        onChange={(e) =>
                            update(i, { recommendation: e.target.value })
                        }
                    />
                </GuardrailCard>
            ))}
            <Button type="button" variant="outline" size="sm" onClick={add}>
                <Plus className="mr-1.5 h-4 w-4" />
                Add decision sought
            </Button>
        </div>
    );
}

function MattersArisingEditor({
    rows,
    onChange,
}: {
    rows: MatterArisingRow[];
    onChange: (rows: MatterArisingRow[]) => void;
}) {
    const update = (i: number, patch: Partial<MatterArisingRow>) => {
        onChange(rows.map((r, idx) => (idx === i ? { ...r, ...patch } : r)));
    };
    const add = () =>
        onChange([...rows, { title: '', status: 'open', update: '' }]);
    const remove = (i: number) => onChange(rows.filter((_, idx) => idx !== i));

    return (
        <div className="space-y-3">
            {rows.length === 0 && (
                <EmptyState
                    variant="compact"
                    icon={MessageCircleQuestion}
                    title="Nothing carried over"
                    description="Add anything from the last meeting the board asked to hear back about."
                />
            )}
            {rows.map((row, i) => (
                <GuardrailCard
                    unstyled
                    key={i}
                    className="space-y-2 rounded-lg border border-border bg-card/40 p-3"
                >
                    <div className="flex items-start gap-2">
                        <Input
                            aria-label={`Matter ${i + 1} title`}
                            placeholder="Matter title (from previous report)"
                            value={row.title}
                            onChange={(e) =>
                                update(i, { title: e.target.value })
                            }
                            className="font-medium"
                        />
                        <div className="w-40 shrink-0">
                            <SelectInput
                                value={row.status}
                                onChange={(v) => update(i, { status: v })}
                                placeholder="Status"
                                ariaLabel={`Matter ${i + 1} status`}
                                options={MATTER_STATUSES.map((option) => ({
                                    value: option.value,
                                    label: option.label,
                                }))}
                            />
                        </div>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            aria-label="Remove matter"
                            onClick={() => remove(i)}
                        >
                            <Trash2 className="h-4 w-4 text-status-critical" />
                        </Button>
                    </div>
                    <Textarea
                        rows={2}
                        aria-label={`Matter ${i + 1} update`}
                        placeholder="What's happened since the previous board meeting?"
                        value={row.update}
                        onChange={(e) => update(i, { update: e.target.value })}
                    />
                </GuardrailCard>
            ))}
            <Button type="button" variant="outline" size="sm" onClick={add}>
                <Plus className="mr-1.5 h-4 w-4" />
                Add matter arising
            </Button>
        </div>
    );
}

// ── Initial defaults from a meeting ───────────────────────────────────────

function defaultsFromMeeting(meeting: MeetingOption | null): {
    period_start: string;
    period_end: string;
    deadline: string;
} {
    if (!meeting?.scheduled_at) {
        return { period_start: '', period_end: '', deadline: '' };
    }
    // Every default is an NZ calendar date / wall time — never a UTC slice,
    // which would move dates by a day and times by 12–13 hours.
    const meetingDay = toDateInput(meeting.scheduled_at);
    if (!meetingDay) {
        return { period_start: '', period_end: '', deadline: '' };
    }
    const periodEnd = shiftDate(meetingDay, -1);
    const periodStart = shiftDate(periodEnd, 1, -1);
    const deadline = toDatetimeLocal(
        new Date(Date.parse(meeting.scheduled_at) - 3 * 86_400_000),
    );
    return {
        period_start: periodStart,
        period_end: periodEnd,
        deadline,
    };
}

export { defaultsFromMeeting as ceoReportDefaultsFromMeeting };

function validateStep(
    step: StepKey,
    data: CeoReportFormValues,
    isEdit: boolean,
): Record<string, string> {
    const errors: Record<string, string> = {};
    if (step !== 'meeting') return errors;
    if (!isEdit && !data.governance_meeting_id) {
        errors.governance_meeting_id = 'Choose the board meeting.';
    }
    if (
        data.period_start &&
        data.period_end &&
        data.period_end < data.period_start
    ) {
        errors.period_end = 'The period cannot end before it starts.';
    }
    return errors;
}

// ── Section textarea ──────────────────────────────────────────────────────

function SectionTextarea({
    id,
    label,
    hint,
    value,
    error,
    onChange,
    rows = 5,
}: {
    id: string;
    label: string;
    hint?: string;
    value: string;
    error?: string;
    onChange: (v: string) => void;
    rows?: number;
}) {
    return (
        <Field label={label} error={error} htmlFor={id}>
            <div className="space-y-1">
                {hint && <p className="text-caption">{hint}</p>}
                <Textarea
                    id={id}
                    aria-label={label}
                    rows={rows}
                    value={value}
                    onChange={(e) => onChange(e.target.value)}
                    placeholder="Write each paragraph on its own line. Blank lines split sections."
                />
            </div>
        </Field>
    );
}

// ── Wizard dialog ─────────────────────────────────────────────────────────

export interface CeoReportDialogProps {
    isOpen: boolean;
    onClose: () => void;
    meetings: MeetingOption[];
    /** When set, lock the form to this meeting (used from Meeting Show). */
    meetingId?: number | string | null;
    lockMeeting?: boolean;
    /** When passed, the wizard enters edit mode and PUTs to this report id. */
    initial?: CeoReportInitialValues;
    /** Called after a successful save/submit. */
    onSaved?: () => void;
}

export function CeoReportWizardDialog(props: CeoReportDialogProps) {
    // Re-mount the body each open so the form resets cleanly.
    return props.isOpen ? <CeoReportWizardBody {...props} /> : null;
}

/** Backwards-compatible name for existing imports. */
export const CeoReportDialog = CeoReportWizardDialog;

function CeoReportWizardBody({
    isOpen,
    onClose,
    meetings,
    meetingId = null,
    lockMeeting = false,
    initial,
    onSaved,
}: CeoReportDialogProps) {
    const isEdit = Boolean(initial?.id);

    const meetingMap = useMemo(
        () => new Map(meetings.map((m) => [String(m.id), m])),
        [meetings],
    );
    const initialMeetingId = String(
        initial?.governance_meeting_id ?? meetingId ?? '',
    );
    const meetingDefaults = defaultsFromMeeting(
        meetingMap.get(initialMeetingId) ?? null,
    );

    const form = useForm<CeoReportFormValues>({
        governance_meeting_id: initialMeetingId,
        period_start: initial?.period_start ?? meetingDefaults.period_start,
        period_end: initial?.period_end ?? meetingDefaults.period_end,
        deadline: initial?.deadline ?? meetingDefaults.deadline,
        executive_summary: initial?.executive_summary ?? '',
        operational_summary: initial?.operational_summary ?? '',
        key_achievements: initial?.key_achievements ?? '',
        challenges_and_risks: initial?.challenges_and_risks ?? '',
        staffing_update: initial?.staffing_update ?? '',
        compliance_status: initial?.compliance_status ?? '',
        financial_summary: initial?.financial_summary ?? '',
        recommendations: initial?.recommendations ?? '',
        decisions_sought: initial?.decisions_sought ?? [],
        matters_arising: initial?.matters_arising ?? [],
    });
    const { data, setData, processing } = form;

    const [stepIndex, setStepIndex] = useState(0);
    const [clientErrors, setClientErrors] = useState<Record<string, string>>(
        {},
    );
    const [submitting, setSubmitting] = useState<'draft' | 'board' | null>(
        null,
    );
    const [done, setDone] = useState<'draft' | 'board' | null>(null);
    const [confirmClose, setConfirmClose] = useState(false);
    const [confirmSubmit, setConfirmSubmit] = useState(false);

    // Sync period defaults when a different meeting is picked (create only).
    useEffect(() => {
        if (isEdit) return;
        const selected = meetingMap.get(data.governance_meeting_id) ?? null;
        if (!selected) return;
        const d = defaultsFromMeeting(selected);
        if (!data.period_start) setData('period_start', d.period_start);
        if (!data.period_end) setData('period_end', d.period_end);
        if (!data.deadline) setData('deadline', d.deadline);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [data.governance_meeting_id]);

    const step = CEO_REPORT_STEPS[stepIndex];
    const err = (name: string): string | undefined =>
        clientErrors[name] ??
        (form.errors as Record<string, string | undefined>)[name];
    const goTo = (key: StepKey) => {
        const index = CEO_REPORT_STEPS.findIndex((s) => s.key === key);
        if (index >= 0) setStepIndex(index);
    };

    const sectionsFilled = CEO_REPORT_SECTIONS.filter(
        (section) => data[section.key].trim().length > 0,
    ).length;
    const pct = useMemo(() => {
        const filled =
            [
                data.governance_meeting_id,
                data.period_start,
                data.period_end,
                data.deadline,
            ].filter(Boolean).length + sectionsFilled;
        return Math.round((filled / (4 + CEO_REPORT_SECTIONS.length)) * 100);
    }, [data, sectionsFilled]);

    const next = () => {
        const errors = validateStep(step.key, data, isEdit);
        setClientErrors(errors);
        if (Object.keys(errors).length > 0) return;
        setStepIndex((i) => Math.min(i + 1, CEO_REPORT_STEPS.length - 1));
    };

    const requestClose = () => {
        if (form.isDirty && !done) {
            setConfirmClose(true);
            return;
        }
        onClose();
    };

    const finish = (mode: 'draft' | 'board', page: unknown) => {
        setSubmitting(null);
        if (pageHasFlashError(page)) return;
        onSaved?.();
        setDone(mode);
    };

    const save = (submitToBoard: boolean) => {
        const all = validateStep('meeting', data, isEdit);
        if (Object.keys(all).length > 0) {
            setClientErrors(all);
            goTo('meeting');
            return;
        }
        setClientErrors({});
        const mode = submitToBoard ? 'board' : 'draft';
        setSubmitting(mode);

        const onError = (errors: Record<string, string>) => {
            setSubmitting(null);
            const target = firstErrorStep(errors, FIELD_STEPS, 'review');
            if (target) goTo(target);
        };

        if (isEdit && initial?.id) {
            const reportId = initial.id;
            form.transform((current) => current);
            form.put(`/governance/ceo-reports/${reportId}`, {
                preserveScroll: true,
                preserveState: true,
                onError,
                onSuccess: (page) => {
                    if (!submitToBoard || pageHasFlashError(page)) {
                        finish('draft', page);
                        return;
                    }
                    router.post(
                        `/governance/ceo-reports/${reportId}/submit`,
                        undefined,
                        {
                            preserveScroll: true,
                            preserveState: true,
                            onSuccess: (submitted) => finish(mode, submitted),
                            onError: () => setSubmitting(null),
                        },
                    );
                },
            });
        } else {
            // submit_immediately travels with this request (setData is async).
            form.transform((current) => ({
                ...current,
                submit_immediately: submitToBoard,
            }));
            form.post('/governance/ceo-reports', {
                preserveScroll: true,
                preserveState: true,
                onError,
                onSuccess: (page) => finish(mode, page),
            });
        }
    };

    const selectedMeeting = meetingMap.get(data.governance_meeting_id) ?? null;
    const meetingLocked = isEdit || lockMeeting;
    const isReview = step.key === 'review';
    const busy = processing || submitting !== null;

    const success = done ? (
        <WizardSuccessPane
            title={
                done === 'board'
                    ? 'Report sent to the board'
                    : isEdit
                      ? 'Report saved'
                      : 'Draft saved'
            }
            blurb={
                done === 'board'
                    ? "The key figures were saved as they stood when you submitted it, and the report can't be edited now."
                    : 'The report stays a draft until you submit it to the board.'
            }
            actions={<Button onClick={onClose}>Close</Button>}
        />
    ) : undefined;

    const sectionField = (
        key: SectionKey,
        label: string,
        hint: string,
        rows = 5,
    ) => (
        <SectionTextarea
            id={`ceo-${key}`}
            label={label}
            hint={hint}
            value={data[key]}
            error={err(key)}
            onChange={(value) => setData(key, value)}
            rows={rows}
        />
    );

    return (
        <>
            <WizardShell
                open={isOpen}
                onClose={requestClose}
                title={isEdit ? 'Edit CEO report' : 'New CEO report'}
                description="Write the CEO's report for a board meeting, one section at a time."
                railIcon={FileText}
                railTitle={isEdit ? 'Edit report' : 'New report'}
                railSub={
                    selectedMeeting ? selectedMeeting.title : 'CEO report'
                }
                steps={CEO_REPORT_STEPS}
                stepIndex={stepIndex}
                onStepClick={setStepIndex}
                pct={pct}
                success={success}
                maxHeight="min(88vh, 820px)"
                footerStart={
                    stepIndex > 0 ? (
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={() =>
                                setStepIndex((i) => Math.max(i - 1, 0))
                            }
                        >
                            <ChevronLeft className="h-4 w-4" /> Back
                        </Button>
                    ) : null
                }
                footerEnd={
                    <>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={requestClose}
                        >
                            Cancel
                        </Button>
                        {isReview ? (
                            <>
                                <Button
                                    type="button"
                                    variant="secondary"
                                    onClick={() => save(false)}
                                    disabled={busy}
                                >
                                    {submitting === 'draft' ? (
                                        <Loader2 className="h-4 w-4 animate-spin" />
                                    ) : (
                                        <Save className="h-4 w-4" />
                                    )}
                                    {isEdit ? 'Save changes' : 'Save as draft'}
                                </Button>
                                <Button
                                    type="button"
                                    onClick={() => setConfirmSubmit(true)}
                                    disabled={busy}
                                >
                                    {submitting === 'board' ? (
                                        <Loader2 className="h-4 w-4 animate-spin" />
                                    ) : (
                                        <Send className="h-4 w-4" />
                                    )}
                                    Submit to board
                                </Button>
                            </>
                        ) : (
                            <Button type="button" onClick={next}>
                                Continue <ChevronRight className="h-4 w-4" />
                            </Button>
                        )}
                    </>
                }
            >
                <WizardStepPane key={step.key}>
                    {step.key === 'meeting' ? (
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="sm:col-span-2">
                                <StepHead
                                    icon={CalendarDays}
                                    title="Which meeting is this for?"
                                    blurb="One report per meeting. The period and deadline default from the meeting date."
                                />
                            </div>
                            <Field
                                label="Board meeting"
                                required
                                span
                                error={err('governance_meeting_id')}
                            >
                                {meetingLocked ? (
                                    <Input
                                        id="ceo-meeting"
                                        value={
                                            selectedMeeting?.title ??
                                            'Linked meeting'
                                        }
                                        readOnly
                                    />
                                ) : (
                                    <SelectInput
                                        value={data.governance_meeting_id}
                                        onChange={(value) =>
                                            setData(
                                                'governance_meeting_id',
                                                value,
                                            )
                                        }
                                        placeholder="Select meeting…"
                                        ariaLabel="Board meeting"
                                        options={meetings.map((m) => ({
                                            value: String(m.id),
                                            label: m.scheduled_at
                                                ? `${m.title} (${formatDateLong(m.scheduled_at)})`
                                                : m.title,
                                        }))}
                                    />
                                )}
                            </Field>
                            {meetingLocked ? (
                                <InfoCard icon={Lock}>
                                    {isEdit
                                        ? "The meeting can't be changed after the report is created."
                                        : 'This report is for the meeting you opened.'}
                                </InfoCard>
                            ) : null}
                            <Field
                                label="Deadline"
                                hint="New Zealand time"
                                error={err('deadline')}
                            >
                                <Input
                                    id="ceo-deadline"
                                    type="datetime-local"
                                    value={data.deadline}
                                    onChange={(e) =>
                                        setData('deadline', e.target.value)
                                    }
                                />
                            </Field>
                            <div className="hidden sm:block" />
                            <Field
                                label="Period start"
                                error={err('period_start')}
                            >
                                <Input
                                    id="ceo-period-start"
                                    type="date"
                                    value={data.period_start}
                                    onChange={(e) =>
                                        setData('period_start', e.target.value)
                                    }
                                />
                            </Field>
                            <Field label="Period end" error={err('period_end')}>
                                <Input
                                    id="ceo-period-end"
                                    type="date"
                                    value={data.period_end}
                                    onChange={(e) =>
                                        setData('period_end', e.target.value)
                                    }
                                />
                            </Field>
                        </div>
                    ) : null}

                    {step.key === 'summary' ? (
                        <div className="grid gap-4">
                            <StepHead
                                icon={FileText}
                                title="Executive summary"
                                blurb="The part the board reads first."
                            />
                            {sectionField(
                                'executive_summary',
                                'Executive summary',
                                'Up to three short paragraphs with the main things the board should know.',
                                8,
                            )}
                        </div>
                    ) : null}

                    {step.key === 'operations' ? (
                        <div className="grid gap-4">
                            <StepHead
                                icon={Briefcase}
                                title="Operations and people"
                                blurb="How services ran this period and what changed in the workforce."
                            />
                            {sectionField(
                                'operational_summary',
                                'Operational summary',
                                'How busy services were — for example how full homes were and how many people were supported. Use plain numbers.',
                            )}
                            {sectionField(
                                'key_achievements',
                                'Key achievements',
                                'Wins from this period the board should know about.',
                                4,
                            )}
                            {sectionField(
                                'staffing_update',
                                'Workforce update',
                                'New staff, people who left, how many are up to date with training, and staff turnover.',
                                4,
                            )}
                        </div>
                    ) : null}

                    {step.key === 'finance' ? (
                        <div className="grid gap-4">
                            <StepHead
                                icon={ShieldCheck}
                                title="Finance, risk and compliance"
                                blurb="Where the money is, the big risks, and whether legal requirements are met."
                            />
                            {sectionField(
                                'financial_summary',
                                'Financial summary',
                                'Spending compared with the budget, the main income and costs, and anything more than 5% over or under budget.',
                            )}
                            {sectionField(
                                'challenges_and_risks',
                                'Challenges and risks',
                                "Risks above the board's limit, incidents that had to be reported, and contact with regulators.",
                                4,
                            )}
                            {sectionField(
                                'compliance_status',
                                'Compliance status',
                                'Audits finished, missing evidence, and upcoming legal deadlines.',
                                4,
                            )}
                        </div>
                    ) : null}

                    {step.key === 'strategy' ? (
                        <div className="grid gap-4">
                            <StepHead
                                icon={Gavel}
                                title="Strategy and decisions sought"
                                blurb="Progress against the plan and anything the board must vote on."
                            />
                            {sectionField(
                                'recommendations',
                                'Strategic progress',
                                'Progress on the strategic plan and roadmap since the last report.',
                            )}
                            <Field
                                label="Decisions sought"
                                error={err('decisions_sought')}
                            >
                                <DecisionsSoughtEditor
                                    rows={data.decisions_sought}
                                    onChange={(rows) =>
                                        setData('decisions_sought', rows)
                                    }
                                />
                            </Field>
                        </div>
                    ) : null}

                    {step.key === 'matters' ? (
                        <div className="grid gap-4">
                            <StepHead
                                icon={MessageCircleQuestion}
                                title="Matters arising"
                                blurb="Update the board on items carried forward from the previous meeting."
                            />
                            <Field error={err('matters_arising')}>
                                <MattersArisingEditor
                                    rows={data.matters_arising}
                                    onChange={(rows) =>
                                        setData('matters_arising', rows)
                                    }
                                />
                            </Field>
                        </div>
                    ) : null}

                    {step.key === 'attachments' ? (
                        <div className="grid gap-4">
                            <StepHead
                                icon={Paperclip}
                                title="Supporting documents"
                                blurb={
                                    isEdit
                                        ? 'Files upload straight to the report.'
                                        : 'Save the draft first — then you can add documents from the report page.'
                                }
                            />
                            <AttachmentsPanel
                                reportId={isEdit ? (initial?.id ?? null) : null}
                                attachments={initial?.attachments ?? []}
                                canManage
                            />
                        </div>
                    ) : null}

                    {isReview ? (
                        <div className="grid gap-4">
                            <StepHead
                                icon={ClipboardCheck}
                                title="Review the report"
                                blurb="Save it as a draft, or submit it to the board. The key figures are saved as they stand when you submit."
                            />
                            {Object.keys(form.errors).length > 0 ? (
                                <InfoCard icon={AlertTriangle} tone="crit">
                                    Some details need attention — check the
                                    highlighted steps.
                                </InfoCard>
                            ) : null}
                            <div className="grid gap-3 sm:grid-cols-2">
                                <ReviewCard
                                    icon={CalendarDays}
                                    title="Meeting and period"
                                    onEdit={() => goTo('meeting')}
                                >
                                    <ReviewRow
                                        label="Meeting"
                                        value={selectedMeeting?.title}
                                    />
                                    <ReviewRow
                                        label="Period"
                                        value={
                                            data.period_start && data.period_end
                                                ? `${formatDateOnly(data.period_start)} – ${formatDateOnly(data.period_end)}`
                                                : null
                                        }
                                    />
                                    <ReviewRow
                                        label="Deadline"
                                        value={formatWallTime(data.deadline)}
                                    />
                                </ReviewCard>
                                <ReviewCard
                                    icon={FileText}
                                    title="Report sections"
                                    onEdit={() => goTo('summary')}
                                    span
                                >
                                    {CEO_REPORT_SECTIONS.map((section) => (
                                        <ReviewRow
                                            key={section.key}
                                            label={section.label}
                                            value={
                                                firstLine(data[section.key]) ??
                                                'Not written yet'
                                            }
                                        />
                                    ))}
                                </ReviewCard>
                                <ReviewCard
                                    icon={Gavel}
                                    title="Decisions and matters arising"
                                    onEdit={() => goTo('strategy')}
                                >
                                    <ReviewRow
                                        label="Decisions sought"
                                        value={
                                            data.decisions_sought
                                                .map((row) => row.title.trim())
                                                .filter(Boolean)
                                                .join('; ') || 'None'
                                        }
                                    />
                                    <ReviewRow
                                        label="Matters arising"
                                        value={
                                            data.matters_arising
                                                .map((row) => row.title.trim())
                                                .filter(Boolean)
                                                .join('; ') || 'None'
                                        }
                                    />
                                </ReviewCard>
                                <ReviewCard
                                    icon={Paperclip}
                                    title="Attachments"
                                    onEdit={() => goTo('attachments')}
                                >
                                    <ReviewRow
                                        label="Files"
                                        value={
                                            isEdit
                                                ? String(
                                                      initial?.attachments
                                                          ?.length ?? 0,
                                                  )
                                                : 'Added after saving'
                                        }
                                    />
                                </ReviewCard>
                            </div>
                        </div>
                    ) : null}
                </WizardStepPane>
            </WizardShell>

            <DiscardDraftDialog
                open={confirmClose}
                onKeepEditing={() => setConfirmClose(false)}
                onDiscard={() => {
                    setConfirmClose(false);
                    onClose();
                }}
                description="Unsaved changes to this CEO report will be lost."
            />

            <ConfirmDialog
                open={confirmSubmit}
                onClose={() => setConfirmSubmit(false)}
                onConfirm={() => save(true)}
                title="Submit your report to the board?"
                description="Board members will be able to read it. You won't be able to edit it afterwards, and the key figures are saved as they stand right now."
                confirmText="Submit to board"
                variant="default"
            />
        </>
    );
}

/** The first line of a section, shortened for the review step. */
function firstLine(value: string): string | null {
    const line = value
        .split(/\r\n|\r|\n/)
        .map((part) => part.trim())
        .find(Boolean);
    if (!line) return null;
    return line.length > 140 ? `${line.slice(0, 137)}…` : line;
}
