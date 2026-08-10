/* Schedule medication review — BUILD-NEW modal on the shared Add-Client wizard
 * chrome. Creates a scheduled MedicationReview via emar.reviews.store
 * (EmarController@storeReview). Completing a review stays on the /emar/reviews
 * deep page. */
import { MedsWizardDialog, SummaryRow } from '@/components/meds/wizard-shell';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import {
    Field,
    InfoCard,
    SelectInput,
    StepHead,
} from '@/components/wizard/primitives';
import { router } from '@inertiajs/react';
import { CalendarCheck, ClipboardCheck, Info } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';

import type { ClientOption } from './report-error-modal';

const STEPS = [
    {
        key: 'details',
        label: 'Review details',
        blurb: 'Client & type',
        icon: CalendarCheck,
    },
    {
        key: 'confirm',
        label: 'Confirm & schedule',
        blurb: 'Reason & save',
        icon: ClipboardCheck,
    },
];

const REVIEW_TYPES = [
    { value: 'Routine', label: 'Routine' },
    { value: 'Triggered', label: 'Triggered' },
    { value: 'Comprehensive', label: 'Comprehensive (medicines review)' },
    { value: 'Admission', label: 'Admission' },
    { value: 'Discharge', label: 'Discharge' },
    { value: 'Incident', label: 'Post-incident' },
];

const REVIEWER_ROLES = [
    { value: 'Pharmacist', label: 'Pharmacist' },
    { value: 'GP', label: 'GP' },
    { value: 'Nurse', label: 'Nurse' },
    { value: 'Specialist', label: 'Specialist' },
];

export function MedicationReviewModal({
    open,
    onClose,
    clients,
    initialClientId,
}: {
    open: boolean;
    onClose: () => void;
    clients: ClientOption[];
    initialClientId?: number | null;
}) {
    const [step, setStep] = useState(0);
    const [saving, setSaving] = useState(false);
    const [clientId, setClientId] = useState(
        initialClientId ? String(initialClientId) : '',
    );
    const [reviewType, setReviewType] = useState('');
    const [scheduledDate, setScheduledDate] = useState('');
    const [reviewerName, setReviewerName] = useState('');
    const [reviewerRole, setReviewerRole] = useState('');
    const [triggerReason, setTriggerReason] = useState('');

    const reset = () => {
        setStep(0);
        setClientId(initialClientId ? String(initialClientId) : '');
        setReviewType('');
        setScheduledDate('');
        setReviewerName('');
        setReviewerRole('');
        setTriggerReason('');
    };

    const close = () => {
        reset();
        onClose();
    };

    const step1Ok = clientId && reviewType && scheduledDate;

    const submit = () => {
        setSaving(true);
        router.post(
            '/emar/reviews',
            {
                client_id: Number(clientId),
                review_type: reviewType,
                scheduled_date: scheduledDate,
                reviewer_name: reviewerName || null,
                reviewer_role: reviewerRole || null,
                trigger_reason: triggerReason || null,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    toast.success('Medication review scheduled');
                    close();
                },
                onError: () => toast.error('Could not schedule the review'),
                onFinish: () => setSaving(false),
            },
        );
    };

    const clientName =
        clients.find((c) => String(c.id) === clientId)?.name ?? '—';

    const footer = (
        <>
            <Button
                variant="ghost"
                onClick={step === 0 ? close : () => setStep(0)}
                disabled={saving}
            >
                {step === 0 ? 'Cancel' : 'Back'}
            </Button>
            {step === 0 ? (
                <Button onClick={() => setStep(1)} disabled={!step1Ok}>
                    Continue
                </Button>
            ) : (
                <Button onClick={submit} disabled={saving}>
                    <CalendarCheck className="h-4 w-4" />
                    {saving ? 'Scheduling…' : 'Schedule review'}
                </Button>
            )}
        </>
    );

    return (
        <MedsWizardDialog
            open={open}
            onClose={close}
            title="Schedule a medication review"
            description="Schedule a medication chart review for a client."
            railIcon={CalendarCheck}
            railTitle="Medication review"
            railSubtitle="Schedule a chart review"
            steps={STEPS}
            stepIndex={step}
            onStepClick={(i) => i < step && setStep(i)}
            footer={footer}
        >
            {step === 0 ? (
                <div className="grid gap-5 sm:grid-cols-2">
                    <StepHead
                        icon={CalendarCheck}
                        title="Review details"
                        blurb="Who, what kind, and when."
                    />
                    <Field label="Client" required>
                        <SelectInput
                            value={clientId}
                            onChange={setClientId}
                            placeholder="Select client"
                            options={clients.map((c) => ({
                                value: String(c.id),
                                label: c.site
                                    ? `${c.name} · ${c.site}`
                                    : c.name,
                            }))}
                        />
                    </Field>
                    <Field label="Review type" required>
                        <SelectInput
                            value={reviewType}
                            onChange={setReviewType}
                            placeholder="Select type"
                            options={REVIEW_TYPES}
                        />
                    </Field>
                    <Field label="Scheduled date" required>
                        {/* eslint-disable-next-line no-restricted-syntax -- native date input; no shadcn date control in wizard primitives. */}
                        <input
                            type="date"
                            value={scheduledDate}
                            onChange={(e) => setScheduledDate(e.target.value)}
                            className="h-10 w-full rounded-md border border-border bg-background px-3 text-sm outline-none focus:ring-2 focus:ring-primary/40"
                        />
                    </Field>
                    <Field label="Reviewer role">
                        <SelectInput
                            value={reviewerRole}
                            onChange={setReviewerRole}
                            placeholder="Select role"
                            options={REVIEWER_ROLES}
                        />
                    </Field>
                    <Field label="Reviewer name" span>
                        <Input
                            value={reviewerName}
                            onChange={(e) => setReviewerName(e.target.value)}
                            placeholder="Optional — who will conduct it"
                        />
                    </Field>
                </div>
            ) : (
                <div className="grid gap-5 sm:grid-cols-2">
                    <StepHead
                        icon={ClipboardCheck}
                        title="Confirm & schedule"
                        blurb="Note why, then schedule."
                    />
                    <Field label="Trigger / reason" span>
                        <Textarea
                            value={triggerReason}
                            onChange={(e) => setTriggerReason(e.target.value)}
                            rows={3}
                            placeholder="Why is this review needed? (optional)"
                        />
                    </Field>
                    <div className="col-span-full rounded-lg border border-border">
                        <div className="px-4">
                            <SummaryRow label="Client" value={clientName} />
                            <SummaryRow
                                label="Type"
                                value={reviewType || '—'}
                            />
                            <SummaryRow
                                label="Scheduled"
                                value={scheduledDate || '—'}
                            />
                            <SummaryRow
                                label="Reviewer"
                                value={
                                    [reviewerName, reviewerRole]
                                        .filter(Boolean)
                                        .join(' · ') || '—'
                                }
                            />
                        </div>
                    </div>
                    <InfoCard icon={Info}>
                        This schedules the review; complete it (findings &
                        outcome) from the review schedule when it's done.
                    </InfoCard>
                </div>
            )}
        </MedsWizardDialog>
    );
}

export default MedicationReviewModal;
