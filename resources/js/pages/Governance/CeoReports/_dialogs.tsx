import { DiscardDraftDialog } from '@/components/governance/DiscardDraftDialog';
import {
    firstErrorStep,
    pageHasFlashError,
} from '@/components/governance/governance-dialog-deep-link';
import { Button } from '@/components/ui/button';
import { Card as GuardrailCard } from '@/components/ui/card';
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
import { formatDateLong, formatDateOnly } from '@/lib/datetime';
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
    switch (status) {
        case 'presented':
            return 'success';
        case 'submitted':
            return 'info';
        default:
            return 'neutral';
    }
}

export const ceoReportStatusLabel = (status: string) =>
    status.charAt(0).toUpperCase() + status.slice(1).replace(/_/g, ' ');

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
    { key: 'challenges_and_risks', label: 'Challenges & risks' },
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
        label: 'Meeting & period',
        blurb: 'Board meeting, period & deadline',
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
        label: 'Operations & people',
        blurb: 'Utilisation, wins & workforce',
        icon: Briefcase,
    },
    {
        key: 'finance',
        label: 'Finance & risk',
        blurb: 'Financials, risks & compliance',
        icon: ShieldCheck,
    },
    {
        key: 'strategy',
        label: 'Strategy & decisions',
        blurb: 'Strategic progress & decisions sought',
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
                <div className="rounded-lg border border-dashed border-border p-4 text-center text-sm text-muted-foreground">
                    No decisions for the board this period. Add one if you need
                    a vote.
                </div>
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
                            placeholder="Decision title (e.g. Approve FY27 budget)"
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
                        placeholder="CEO recommendation to the board."
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
                <div className="rounded-lg border border-dashed border-border p-4 text-center text-sm text-muted-foreground">
                    Nothing carried over from the previous report.
                </div>
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
                                options={[
                                    { value: 'open', label: 'Open' },
                                    {
                                        value: 'in_progress',
                                        label: 'In progress',
                                    },
                                    { value: 'done', label: 'Done' },
                                ]}
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
    const dt = new Date(meeting.scheduled_at);
    const end = new Date(dt);
    end.setDate(end.getDate() - 1);
    const start = new Date(end);
    start.setMonth(start.getMonth() - 1);
    start.setDate(start.getDate() + 1);
    const deadline = new Date(dt);
    deadline.setDate(deadline.getDate() - 3);
    const fmtDate = (d: Date) => d.toISOString().slice(0, 10);
    const fmtDateTime = (d: Date) => d.toISOString().slice(0, 16);
    return {
        period_start: fmtDate(start),
        period_end: fmtDate(end),
        deadline: fmtDateTime(deadline),
    };
}

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
                    ? 'Report submitted to the board'
                    : isEdit
                      ? 'Report saved'
                      : 'Draft saved'
            }
            blurb={
                done === 'board'
                    ? 'A KPI snapshot was captured at submission and the report is now with the board.'
                    : 'The report stays in draft until you submit it to the board.'
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
                description="A guided wizard to compose the CEO's board report section by section."
                railIcon={FileText}
                railTitle={isEdit ? 'Edit report' : 'New report'}
                railSub={
                    selectedMeeting ? selectedMeeting.title : 'CEO board report'
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
                                    onClick={() => save(true)}
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
                                        ? 'The meeting cannot be changed after the report is created.'
                                        : 'Locked from the meeting you opened.'}
                                </InfoCard>
                            ) : null}
                            <Field label="Deadline" error={err('deadline')}>
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
                                blurb="The top-line the board reads first."
                            />
                            {sectionField(
                                'executive_summary',
                                'Executive summary',
                                '3-paragraph top-line for the board. Headline outcomes only.',
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
                                'Service utilisation, occupancy, throughput. Use plain numbers.',
                            )}
                            {sectionField(
                                'key_achievements',
                                'Key achievements',
                                'Wins for the period the board should know about.',
                                4,
                            )}
                            {sectionField(
                                'staffing_update',
                                'Workforce update',
                                'Hires, exits, training compliance %, turnover.',
                                4,
                            )}
                        </div>
                    ) : null}

                    {step.key === 'finance' ? (
                        <div className="grid gap-4">
                            <StepHead
                                icon={ShieldCheck}
                                title="Finance, risk and compliance"
                                blurb="Budget position, material risks and regulatory standing."
                            />
                            {sectionField(
                                'financial_summary',
                                'Financial summary',
                                'Budget vs actual, key revenue / expense items, any variance > 5%.',
                            )}
                            {sectionField(
                                'challenges_and_risks',
                                'Challenges & risks',
                                'Risks above appetite, notifiable incidents, regulator engagement.',
                                4,
                            )}
                            {sectionField(
                                'compliance_status',
                                'Compliance status',
                                'Audits closed, evidence gaps, regulatory deadlines.',
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
                                'Movement against the current strategic plan and roadmap.',
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
                                        : 'Save the draft first, then attach documents from the report page.'
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
                                blurb="Save it as a draft, or submit it to the board — a KPI snapshot is captured on submission."
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
                                    title="Meeting & period"
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
                                        value={
                                            data.deadline
                                                ? data.deadline.replace('T', ' ')
                                                : null
                                        }
                                    />
                                </ReviewCard>
                                <ReviewCard
                                    icon={FileText}
                                    title="Narrative sections"
                                    onEdit={() => goTo('summary')}
                                >
                                    <ReviewRow
                                        label="Completed"
                                        value={`${sectionsFilled} of ${CEO_REPORT_SECTIONS.length}`}
                                    />
                                    <ReviewRow
                                        label="Missing"
                                        value={
                                            CEO_REPORT_SECTIONS.filter(
                                                (s) => !data[s.key].trim(),
                                            )
                                                .map((s) => s.label)
                                                .join(', ') || null
                                        }
                                    />
                                </ReviewCard>
                                <ReviewCard
                                    icon={Gavel}
                                    title="Board items"
                                    onEdit={() => goTo('strategy')}
                                >
                                    <ReviewRow
                                        label="Decisions sought"
                                        value={String(
                                            data.decisions_sought.length,
                                        )}
                                    />
                                    <ReviewRow
                                        label="Matters arising"
                                        value={String(
                                            data.matters_arising.length,
                                        )}
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
        </>
    );
}
