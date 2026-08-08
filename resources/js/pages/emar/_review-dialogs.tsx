/* eslint-disable no-restricted-syntax -- wizard fact/summary panes and the recommendation
   row editor are custom-layout bordered surfaces inside the wizard shell, not Card/Button;
   all colours are semantic tokens. */
import { MedsWizardDialog, SummaryRow } from '@/components/meds/wizard-shell';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Field, SelectInput, StepHead } from '@/components/wizard/primitives';
import { router, useForm } from '@inertiajs/react';
import {
    Activity,
    Calendar,
    CheckCircle,
    ClipboardCheck,
    Pill,
    Plus,
    Stethoscope,
    Trash2,
    User,
    Users,
} from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';

export type ReviewAction = {
    drug: string;
    action: string;
    rationale?: string | null;
    gp_status?: string;
    stage?: string;
};
export type ReviewRow = {
    id: number;
    client_id: number | null;
    client_name: string;
    site_id: number | null;
    site_name: string | null;
    review_type: string | null;
    status: string;
    scheduled_date: string | null;
    completed_date: string | null;
    reviewer_name: string | null;
    reviewer_role: string | null;
    reviewer_user_id: number | null;
    trigger_reason: string | null;
    medications_reviewed: unknown[];
    actions: ReviewAction[];
    clinical_summary: string | null;
    recommendations: string | null;
    drug_burden_index: number | string | null;
    falls_last_quarter: number | null;
    whanau_involved: boolean;
    whanau_notes: string | null;
    next_review_date: string | null;
    is_overdue: boolean;
};
export type ClientOpt = { id: number; first_name: string; last_name: string };
export type StaffOpt = { id: number; name: string };

export const REVIEW_TYPES = [
    { value: 'routine', label: 'Routine (3-monthly chart)' },
    { value: 'triggered', label: 'Triggered' },
    { value: 'comprehensive', label: 'Comprehensive' },
    { value: 'admission', label: 'Admission' },
    { value: 'discharge', label: 'Discharge' },
    { value: 'incident', label: 'Incident' },
];
const REVIEWER_ROLES = [
    'Pharmacist',
    'GP',
    'Nurse practitioner',
    'Registered nurse',
    'Other',
].map((r) => ({ value: r, label: r }));
export const ACTION_OPTIONS = [
    { value: 'Continue', label: 'Continue' },
    { value: 'Reduce', label: 'Reduce' },
    { value: 'Stop', label: 'Stop' },
    { value: 'Switch', label: 'Switch' },
    { value: 'Monitor', label: 'Monitor' },
];
export const actionTone = (a: string) =>
    a === 'Stop'
        ? 'bg-status-critical-bg text-status-critical'
        : a === 'Reduce'
          ? 'bg-status-warning-bg text-status-warning'
          : a === 'Switch'
            ? 'bg-accent text-primary'
            : a === 'Monitor'
              ? 'bg-status-info-bg text-status-info'
              : 'bg-muted text-muted-foreground';

// ── Schedule review (4-step) ─────────────────────────────────────────────────
export function ScheduleReviewDialog({
    clients,
    staff,
    defaultClientId,
    onClose,
}: {
    clients: ClientOpt[];
    staff: StaffOpt[];
    defaultClientId?: number | null;
    onClose: () => void;
}) {
    const [step, setStep] = useState(0);
    const form = useForm({
        client_id: defaultClientId ? String(defaultClientId) : '',
        review_type: 'routine',
        scheduled_date: '',
        reviewer_user_id: '',
        reviewer_name: '',
        reviewer_role: 'Pharmacist',
        trigger_reason: '',
    });
    const pickReviewer = (id: string) =>
        form.setData({
            ...form.data,
            reviewer_user_id: id,
            reviewer_name: staff.find((s) => String(s.id) === id)?.name ?? '',
        });
    const submit = () =>
        form.post('/emar/reviews', {
            preserveScroll: true,
            onSuccess: () => {
                toast.success('Review scheduled');
                onClose();
            },
            onError: () => toast.error('Please check the review details'),
        });
    const valid = [
        !!form.data.client_id,
        !!form.data.review_type && !!form.data.scheduled_date,
        true,
        true,
    ];
    return (
        <MedsWizardDialog
            open
            onClose={onClose}
            title="Schedule review"
            description="Schedule a medication review for a resident."
            railIcon={ClipboardCheck}
            railTitle="Schedule review"
            railSubtitle="Medication governance"
            steps={[
                {
                    key: 'resident',
                    label: 'Resident',
                    blurb: 'Who',
                    icon: User,
                },
                {
                    key: 'type',
                    label: 'Type & trigger',
                    blurb: 'Why',
                    icon: Activity,
                },
                {
                    key: 'reviewer',
                    label: 'Reviewer',
                    blurb: 'Assign',
                    icon: Stethoscope,
                },
                {
                    key: 'confirm',
                    label: 'Confirm',
                    blurb: 'Schedule',
                    icon: CheckCircle,
                },
            ]}
            stepIndex={step}
            onStepClick={(i) => i < step && setStep(i)}
            footer={
                <>
                    <Button
                        variant="ghost"
                        onClick={step === 0 ? onClose : () => setStep(step - 1)}
                        disabled={form.processing}
                    >
                        {step === 0 ? 'Cancel' : 'Back'}
                    </Button>
                    {step < 3 ? (
                        <Button
                            onClick={() => setStep(step + 1)}
                            disabled={!valid[step]}
                        >
                            Continue
                        </Button>
                    ) : (
                        <Button onClick={submit} disabled={form.processing}>
                            Schedule review
                        </Button>
                    )}
                </>
            }
        >
            {step === 0 && (
                <>
                    <StepHead
                        icon={User}
                        title="Resident"
                        blurb="Who is this review for?"
                    />
                    <Field label="Resident" required span>
                        <SelectInput
                            value={form.data.client_id}
                            onChange={(v) => form.setData('client_id', v)}
                            placeholder="Select resident…"
                            options={clients.map((c) => ({
                                value: String(c.id),
                                label: `${c.first_name} ${c.last_name}`,
                            }))}
                        />
                    </Field>
                </>
            )}
            {step === 1 && (
                <>
                    <StepHead
                        icon={Activity}
                        title="Type & trigger"
                        blurb="What kind of review and why now."
                    />
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <Field label="Review type" required>
                            <SelectInput
                                value={form.data.review_type}
                                onChange={(v) => form.setData('review_type', v)}
                                placeholder="Type…"
                                options={REVIEW_TYPES}
                            />
                        </Field>
                        <Field
                            label="Scheduled date"
                            required
                            error={form.errors.scheduled_date}
                        >
                            <Input
                                type="date"
                                value={form.data.scheduled_date}
                                onChange={(e) =>
                                    form.setData(
                                        'scheduled_date',
                                        e.target.value,
                                    )
                                }
                            />
                        </Field>
                        <Field
                            label="Trigger reason"
                            span
                            error={form.errors.trigger_reason}
                        >
                            <Input
                                value={form.data.trigger_reason}
                                onChange={(e) =>
                                    form.setData(
                                        'trigger_reason',
                                        e.target.value,
                                    )
                                }
                                placeholder="e.g. Recent fall, polypharmacy, post-discharge"
                            />
                        </Field>
                    </div>
                </>
            )}
            {step === 2 && (
                <>
                    <StepHead
                        icon={Stethoscope}
                        title="Reviewer"
                        blurb="Assign the reviewing clinician."
                    />
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <Field
                            label="Reviewer"
                            error={form.errors.reviewer_user_id}
                        >
                            <SelectInput
                                value={form.data.reviewer_user_id}
                                onChange={pickReviewer}
                                placeholder="Assign to…"
                                options={staff.map((s) => ({
                                    value: String(s.id),
                                    label: s.name,
                                }))}
                            />
                        </Field>
                        <Field label="Reviewer role">
                            <SelectInput
                                value={form.data.reviewer_role}
                                onChange={(v) =>
                                    form.setData('reviewer_role', v)
                                }
                                placeholder="Role…"
                                options={REVIEWER_ROLES}
                            />
                        </Field>
                    </div>
                </>
            )}
            {step === 3 && (
                <>
                    <StepHead
                        icon={CheckCircle}
                        title="Confirm"
                        blurb="Confirm and schedule."
                    />
                    <div className="rounded-lg border px-4">
                        <SummaryRow
                            label="Resident"
                            value={
                                clients.find(
                                    (c) => String(c.id) === form.data.client_id,
                                )
                                    ? `${clients.find((c) => String(c.id) === form.data.client_id)!.first_name} ${clients.find((c) => String(c.id) === form.data.client_id)!.last_name}`
                                    : '—'
                            }
                        />
                        <SummaryRow
                            label="Type"
                            value={
                                REVIEW_TYPES.find(
                                    (t) => t.value === form.data.review_type,
                                )?.label ?? form.data.review_type
                            }
                        />
                        <SummaryRow
                            label="Scheduled"
                            value={form.data.scheduled_date}
                        />
                        <SummaryRow
                            label="Reviewer"
                            value={form.data.reviewer_name || '—'}
                        />
                    </div>
                </>
            )}
        </MedsWizardDialog>
    );
}

// ── Conduct & complete review (5-step) — replaces Edit + Complete ────────────
export function ConductReviewDialog({
    review,
    onClose,
}: {
    review: ReviewRow;
    onClose: () => void;
}) {
    const [step, setStep] = useState(0);
    const [rows, setRows] = useState<ReviewAction[]>(
        review.actions.length ? review.actions : [],
    );
    const [busy, setBusy] = useState(false);
    const form = useForm({
        clinical_summary: review.clinical_summary ?? '',
        drug_burden_index: review.drug_burden_index
            ? String(review.drug_burden_index)
            : '',
        falls_last_quarter:
            review.falls_last_quarter != null
                ? String(review.falls_last_quarter)
                : '',
        recommendations: review.recommendations ?? '',
        whanau_involved: review.whanau_involved ?? false,
        whanau_notes: review.whanau_notes ?? '',
        next_review_date: review.next_review_date ?? '',
        confirmed: false,
    });
    const addRow = () =>
        setRows((r) => [
            ...r,
            {
                drug: '',
                action: 'Continue',
                rationale: '',
                gp_status: 'pending',
                stage: 'gp',
            },
        ]);
    const setRow = (i: number, patch: Partial<ReviewAction>) =>
        setRows((r) =>
            r.map((row, idx) => (idx === i ? { ...row, ...patch } : row)),
        );
    const delRow = (i: number) =>
        setRows((r) => r.filter((_, idx) => idx !== i));
    const hasStop = rows.some(
        (r) => r.action === 'Stop' || r.action === 'Reduce',
    );

    const submit = () => {
        setBusy(true);
        const clean = rows
            .filter((r) => r.drug.trim())
            .map((r) => ({
                drug: r.drug.trim(),
                action: r.action,
                rationale: r.rationale || null,
                gp_status: r.gp_status ?? 'pending',
                stage: r.stage ?? 'gp',
            }));
        router.post(
            `/emar/reviews/${review.id}/complete`,
            {
                clinical_summary: form.data.clinical_summary,
                drug_burden_index:
                    form.data.drug_burden_index === ''
                        ? null
                        : Number(form.data.drug_burden_index),
                falls_last_quarter:
                    form.data.falls_last_quarter === ''
                        ? null
                        : Number(form.data.falls_last_quarter),
                recommendations: form.data.recommendations || null,
                medications_reviewed: clean.map((r) => r.drug),
                actions: clean,
                whanau_involved: form.data.whanau_involved,
                whanau_notes: form.data.whanau_notes || null,
                next_review_date: form.data.next_review_date || null,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    toast.success('Review completed');
                    onClose();
                },
                onError: () => {
                    toast.error('A clinical summary is required');
                    setStep(1);
                },
                onFinish: () => setBusy(false),
            },
        );
    };
    const valid = [
        true,
        !!form.data.clinical_summary.trim(),
        true,
        true,
        form.data.confirmed,
    ];
    return (
        <MedsWizardDialog
            open
            onClose={onClose}
            title="Conduct review"
            description={`Conduct and sign off ${review.client_name}'s medication review.`}
            railIcon={Stethoscope}
            railTitle="Conduct review"
            railSubtitle={review.client_name}
            steps={[
                {
                    key: 'context',
                    label: 'Context',
                    blurb: 'Resident',
                    icon: User,
                },
                {
                    key: 'findings',
                    label: 'Findings',
                    blurb: 'Summary + DBI',
                    icon: Activity,
                },
                {
                    key: 'recs',
                    label: 'Recommendations',
                    blurb: 'Per drug',
                    icon: Pill,
                },
                {
                    key: 'whanau',
                    label: 'Whānau & next',
                    blurb: 'Involve + date',
                    icon: Users,
                },
                {
                    key: 'sign',
                    label: 'Sign-off',
                    blurb: 'Confirm',
                    icon: CheckCircle,
                },
            ]}
            stepIndex={step}
            onStepClick={(i) => i < step && setStep(i)}
            footer={
                <>
                    <Button
                        variant="ghost"
                        onClick={step === 0 ? onClose : () => setStep(step - 1)}
                        disabled={busy}
                    >
                        {step === 0 ? 'Cancel' : 'Back'}
                    </Button>
                    {step < 4 ? (
                        <Button
                            onClick={() => setStep(step + 1)}
                            disabled={!valid[step]}
                        >
                            Continue
                        </Button>
                    ) : (
                        <Button onClick={submit} disabled={busy || !valid[4]}>
                            Complete review
                        </Button>
                    )}
                </>
            }
        >
            {step === 0 && (
                <>
                    <StepHead
                        icon={User}
                        title="Context"
                        blurb="Resident and review context."
                    />
                    <div className="rounded-lg border px-4">
                        <SummaryRow
                            label="Resident"
                            value={review.client_name}
                        />
                        <SummaryRow
                            label="Type"
                            value={
                                REVIEW_TYPES.find(
                                    (t) => t.value === review.review_type,
                                )?.label ??
                                review.review_type ??
                                '—'
                            }
                        />
                        <SummaryRow
                            label="Trigger"
                            value={review.trigger_reason || '—'}
                        />
                        <SummaryRow
                            label="Scheduled"
                            value={review.scheduled_date ?? '—'}
                        />
                    </div>
                </>
            )}
            {step === 1 && (
                <>
                    <StepHead
                        icon={Activity}
                        title="Findings"
                        blurb="Clinical summary and polypharmacy measures."
                    />
                    <Field
                        label="Clinical summary"
                        required
                        span
                        error={form.errors.clinical_summary}
                    >
                        <textarea
                            value={form.data.clinical_summary}
                            onChange={(e) =>
                                form.setData('clinical_summary', e.target.value)
                            }
                            rows={4}
                            className="w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
                            placeholder="Summary of the review findings…"
                        />
                    </Field>
                    <div className="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <Field
                            label="Drug Burden Index"
                            error={form.errors.drug_burden_index}
                        >
                            <Input
                                type="number"
                                step="0.01"
                                min={0}
                                value={form.data.drug_burden_index}
                                onChange={(e) =>
                                    form.setData(
                                        'drug_burden_index',
                                        e.target.value,
                                    )
                                }
                                placeholder="e.g. 1.25"
                            />
                        </Field>
                        <Field
                            label="Falls (last quarter)"
                            error={form.errors.falls_last_quarter}
                        >
                            <Input
                                type="number"
                                min={0}
                                value={form.data.falls_last_quarter}
                                onChange={(e) =>
                                    form.setData(
                                        'falls_last_quarter',
                                        e.target.value,
                                    )
                                }
                            />
                        </Field>
                    </div>
                </>
            )}
            {step === 2 && (
                <>
                    <StepHead
                        icon={Pill}
                        title="Recommendations"
                        blurb="Record an action for each medication reviewed."
                    />
                    <div className="flex flex-col gap-3">
                        {rows.length === 0 && (
                            <p className="text-sm text-muted-foreground">
                                No recommendations yet — add one for each
                                medication you reviewed.
                            </p>
                        )}
                        {rows.map((row, i) => (
                            <div
                                key={i}
                                className="grid grid-cols-1 gap-2 rounded-lg border p-3 sm:grid-cols-[1.4fr_1fr_1.6fr_auto]"
                            >
                                <Input
                                    value={row.drug}
                                    onChange={(e) =>
                                        setRow(i, { drug: e.target.value })
                                    }
                                    placeholder="Medication"
                                />
                                <SelectInput
                                    value={row.action}
                                    onChange={(v) => setRow(i, { action: v })}
                                    placeholder="Action…"
                                    options={ACTION_OPTIONS}
                                />
                                <Input
                                    value={row.rationale ?? ''}
                                    onChange={(e) =>
                                        setRow(i, { rationale: e.target.value })
                                    }
                                    placeholder="Rationale"
                                />
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    onClick={() => delRow(i)}
                                    aria-label="Remove"
                                >
                                    <Trash2 className="h-4 w-4 text-status-critical" />
                                </Button>
                            </div>
                        ))}
                        <div>
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={addRow}
                            >
                                <Plus className="h-3.5 w-3.5" />
                                Add recommendation
                            </Button>
                        </div>
                    </div>
                </>
            )}
            {step === 3 && (
                <>
                    <StepHead
                        icon={Users}
                        title="Whānau & next review"
                        blurb="Whānau involvement and the next review date."
                    />
                    {hasStop && !form.data.whanau_involved && (
                        <div className="mb-3 rounded-lg border border-status-warning/30 bg-status-warning-bg/60 px-3 py-2 text-xs text-status-warning">
                            Stopping or reducing a medication — whānau should be
                            involved (HQSC expectation), especially for
                            sedatives/antipsychotics.
                        </div>
                    )}
                    <label className="flex items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            checked={form.data.whanau_involved}
                            onChange={(e) =>
                                form.setData(
                                    'whanau_involved',
                                    e.target.checked,
                                )
                            }
                            className="h-4 w-4 rounded border-border"
                        />
                        Whānau were involved in this review
                    </label>
                    <div className="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <Field
                            label="Whānau notes"
                            span
                            error={form.errors.whanau_notes}
                        >
                            <Input
                                value={form.data.whanau_notes}
                                onChange={(e) =>
                                    form.setData('whanau_notes', e.target.value)
                                }
                                placeholder="Discussion / decisions"
                            />
                        </Field>
                        <Field
                            label="Next review date"
                            error={form.errors.next_review_date}
                        >
                            <Input
                                type="date"
                                value={form.data.next_review_date}
                                onChange={(e) =>
                                    form.setData(
                                        'next_review_date',
                                        e.target.value,
                                    )
                                }
                            />
                        </Field>
                    </div>
                </>
            )}
            {step === 4 && (
                <>
                    <StepHead
                        icon={CheckCircle}
                        title="Sign-off"
                        blurb="Confirm and sign off the review."
                    />
                    <div className="mb-4 rounded-lg border px-4">
                        <SummaryRow
                            label="Recommendations"
                            value={`${rows.filter((r) => r.drug.trim()).length} drug(s)`}
                        />
                        <SummaryRow
                            label="Drug Burden Index"
                            value={form.data.drug_burden_index || '—'}
                        />
                        <SummaryRow
                            label="Whānau involved"
                            value={form.data.whanau_involved ? 'Yes' : 'No'}
                        />
                        <SummaryRow
                            label="Next review"
                            value={
                                form.data.next_review_date ||
                                'Auto (chart cycle)'
                            }
                        />
                    </div>
                    <label className="flex items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            checked={form.data.confirmed}
                            onChange={(e) =>
                                form.setData('confirmed', e.target.checked)
                            }
                            className="h-4 w-4 rounded border-border"
                        />
                        I confirm I conducted this medication review
                        {review.reviewer_name
                            ? ` as ${review.reviewer_name}`
                            : ''}
                        .
                    </label>
                </>
            )}
        </MedsWizardDialog>
    );
}

// ── Reschedule (compact) ─────────────────────────────────────────────────────
export function RescheduleReviewDialog({
    review,
    onClose,
}: {
    review: ReviewRow;
    onClose: () => void;
}) {
    const form = useForm({
        scheduled_date: review.scheduled_date ?? '',
        reason: '',
    });
    const submit = () => {
        const trigger = `${review.trigger_reason ? `${review.trigger_reason} · ` : ''}Rescheduled${form.data.reason ? `: ${form.data.reason}` : ''}`;
        router.put(
            `/emar/reviews/${review.id}`,
            {
                scheduled_date: form.data.scheduled_date,
                trigger_reason: trigger,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    toast.success('Review rescheduled');
                    onClose();
                },
                onError: () => toast.error('Could not reschedule'),
            },
        );
    };
    return (
        <MedsWizardDialog
            open
            onClose={onClose}
            title="Reschedule review"
            description={`Move ${review.client_name}'s review to a new date.`}
            railIcon={Calendar}
            railTitle="Reschedule"
            railSubtitle={review.client_name}
            steps={[
                {
                    key: 'date',
                    label: 'New date',
                    blurb: 'With reason',
                    icon: Calendar,
                },
            ]}
            stepIndex={0}
            onStepClick={() => {}}
            footer={
                <>
                    <Button
                        variant="ghost"
                        onClick={onClose}
                        disabled={form.processing}
                    >
                        Cancel
                    </Button>
                    <Button
                        onClick={submit}
                        disabled={form.processing || !form.data.scheduled_date}
                    >
                        Reschedule
                    </Button>
                </>
            }
        >
            <StepHead
                icon={Calendar}
                title="Reschedule"
                blurb="Pick a new date and record why."
            />
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <Field label="New date" required>
                    <Input
                        type="date"
                        value={form.data.scheduled_date}
                        onChange={(e) =>
                            form.setData('scheduled_date', e.target.value)
                        }
                    />
                </Field>
                <Field label="Reason" span>
                    <Input
                        value={form.data.reason}
                        onChange={(e) => form.setData('reason', e.target.value)}
                        placeholder="Why is it being moved?"
                    />
                </Field>
            </div>
        </MedsWizardDialog>
    );
}

// ── View detail (read-only) ──────────────────────────────────────────────────
export function ReviewDetailDialog({
    review,
    onClose,
}: {
    review: ReviewRow;
    onClose: () => void;
}) {
    return (
        <MedsWizardDialog
            open
            onClose={onClose}
            title="Review detail"
            description={`${review.client_name} · ${REVIEW_TYPES.find((t) => t.value === review.review_type)?.label ?? review.review_type ?? 'Review'}`}
            railIcon={ClipboardCheck}
            railTitle="Review detail"
            railSubtitle={review.client_name}
            steps={[
                {
                    key: 'detail',
                    label: 'Summary',
                    blurb: 'Read-only',
                    icon: ClipboardCheck,
                },
            ]}
            stepIndex={0}
            onStepClick={() => {}}
            footer={<Button onClick={onClose}>Close</Button>}
        >
            <div className="rounded-lg border px-4">
                <SummaryRow
                    label="Reviewer"
                    value={review.reviewer_name || '—'}
                />
                <SummaryRow label="Role" value={review.reviewer_role || '—'} />
                <SummaryRow
                    label="Completed"
                    value={review.completed_date || '—'}
                />
                <SummaryRow
                    label="Drug Burden Index"
                    value={review.drug_burden_index ?? '—'}
                />
                <SummaryRow
                    label="Falls (last quarter)"
                    value={review.falls_last_quarter ?? '—'}
                />
                <SummaryRow
                    label="Next review"
                    value={review.next_review_date || '—'}
                />
            </div>
            {review.clinical_summary && (
                <div className="mt-4">
                    <div className="mb-1 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                        Clinical summary
                    </div>
                    <p className="text-sm">{review.clinical_summary}</p>
                </div>
            )}
            {review.actions.length > 0 && (
                <div className="mt-4">
                    <div className="mb-2 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                        Medications reviewed
                    </div>
                    <div className="flex flex-col gap-2">
                        {review.actions.map((a, i) => (
                            <div
                                key={i}
                                className="flex items-center justify-between gap-3 rounded-lg border px-3 py-2"
                            >
                                <div>
                                    <span
                                        className={`rounded-full px-2 py-0.5 text-[11px] font-semibold ${actionTone(a.action)}`}
                                    >
                                        {a.action}
                                    </span>
                                    <span className="ml-2 font-medium">
                                        {a.drug}
                                    </span>
                                    {a.rationale && (
                                        <div className="text-xs text-muted-foreground">
                                            {a.rationale}
                                        </div>
                                    )}
                                </div>
                                <span className="text-xs text-muted-foreground capitalize">
                                    {a.gp_status ?? 'pending'}
                                </span>
                            </div>
                        ))}
                    </div>
                </div>
            )}
            {review.whanau_involved && (
                <div className="mt-4 rounded-lg border border-status-success/30 bg-status-success-bg/40 px-3 py-2 text-sm text-status-success">
                    Whānau involved
                    {review.whanau_notes ? ` — ${review.whanau_notes}` : ''}
                </div>
            )}
        </MedsWizardDialog>
    );
}
