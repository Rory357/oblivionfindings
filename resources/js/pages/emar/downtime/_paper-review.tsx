import ConfirmDialog from '@/components/confirm-dialog';
import {
    localDateTimeLabel,
    validLocalDateTime,
} from '@/components/fleet-assets/maintenance/date-time-field';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
} from '@/components/wizard/shell';
import { formatDateTime } from '@/lib/datetime';
import { useForm } from '@inertiajs/react';
import { ClipboardCheck, FileText, Loader2, ShieldCheck } from 'lucide-react';
import { useState } from 'react';
import type { PaperClinicalFacts, PaperEntry, PaperStockFacts } from './types';

export function PaperFactsReview({
    clinical,
    stock,
    doseUnit,
}: {
    clinical?: PaperClinicalFacts | null;
    stock?: PaperStockFacts | null;
    doseUnit?: string | null;
}) {
    if (!clinical && !stock) return null;
    return (
        <ReviewCard title="Dose and pack evidence" icon={ClipboardCheck}>
            {clinical && (
                <>
                    <ReviewRow
                        label="Actual amount"
                        value={`${clinical.quantity_given || 'Not recorded'} ${doseUnit ?? ''}`}
                    />
                    <ReviewRow
                        label="Compared with order"
                        value={
                            clinical.amount_mode
                                ? clinical.amount_mode.replaceAll('_', ' ')
                                : 'Not recorded'
                        }
                    />
                    {clinical.amount_reason && (
                        <ReviewRow
                            label="Different amount reason"
                            value={clinical.amount_reason}
                        />
                    )}
                    {clinical.late_reason && (
                        <ReviewRow
                            label="Outside-window reason"
                            value={clinical.late_reason}
                        />
                    )}
                    {clinical.prn_reason && (
                        <ReviewRow
                            label="As-needed reason"
                            value={clinical.prn_reason}
                        />
                    )}
                    {clinical.effect_check_due_at && (
                        <ReviewRow
                            label="Effect check due"
                            value={
                                validLocalDateTime(clinical.effect_check_due_at)
                                    ? localDateTimeLabel(
                                          clinical.effect_check_due_at,
                                      )
                                    : formatDateTime(
                                          clinical.effect_check_due_at,
                                      )
                            }
                        />
                    )}
                    {clinical.more_severity && (
                        <ReviewRow
                            label="Severity"
                            value={clinical.more_severity}
                        />
                    )}
                    {clinical.more_immediate_action && (
                        <ReviewRow
                            label="Immediate action"
                            value={clinical.more_immediate_action}
                        />
                    )}
                </>
            )}
            {stock && (
                <>
                    <ReviewRow
                        label="Removed from stock"
                        value={`${stock.quantity_removed || 'Not recorded'} ${stock.unit}`}
                    />
                    <ReviewRow
                        label="Wasted"
                        value={`${stock.quantity_wasted === '' ? 'Not recorded' : stock.quantity_wasted} ${stock.unit}`}
                    />
                    {stock.waste_reason && (
                        <ReviewRow
                            label="Waste reason"
                            value={stock.waste_reason}
                        />
                    )}
                    {stock.lines.map((line) => (
                        <ReviewRow
                            key={line.lot_id}
                            label={`Pack ${line.lot_id}`}
                            value={`${line.quantity || 'Amount not recorded'} ${stock.unit} removed · ${line.quantity_wasted === '' ? 'waste not recorded' : `${line.quantity_wasted} ${stock.unit} wasted`}`}
                        />
                    ))}
                    {!stock.lines.length && (
                        <ReviewRow
                            label="Actual packs"
                            value="Not identified — stock review needed"
                        />
                    )}
                </>
            )}
        </ReviewCard>
    );
}

export function RecoveryAuthorizationDialog({
    entry,
    downtimeId,
    onClose,
}: {
    entry: PaperEntry;
    downtimeId: number;
    onClose: () => void;
}) {
    const [step, setStep] = useState(0);
    const [discard, setDiscard] = useState(false);
    const form = useForm({ reason: '', accountable_confirmation: false });
    const steps = [
        {
            key: 'evidence',
            label: 'Check evidence',
            blurb: 'Actual facts and grant',
            icon: FileText,
        },
        {
            key: 'decision',
            label: 'Review decision',
            blurb: 'Why recovery is justified',
            icon: ShieldCheck,
        },
    ];
    const close = () => {
        if (!form.processing) {
            if (form.isDirty) setDiscard(true);
            else onClose();
        }
    };
    return (
        <>
            <WizardShell
                frontline
                open
                onClose={close}
                title="Review historical recording authority"
                description="Review one confirmed paper dose after its original emergency grant has expired."
                railIcon={ShieldCheck}
                railTitle="Historical recovery"
                railSub={`${entry.snapshot.person} · ${entry.snapshot.medicine}`}
                steps={steps}
                stepIndex={step}
                onStepClick={(index) => {
                    if (!form.processing && index < step) setStep(index);
                }}
                footerStart={
                    <Button
                        variant="outline"
                        disabled={form.processing}
                        onClick={step ? () => setStep(0) : close}
                    >
                        {step ? 'Back' : 'Cancel'}
                    </Button>
                }
                footerEnd={
                    <Button
                        disabled={
                            form.processing ||
                            (step === 1 &&
                                (!form.data.reason.trim() ||
                                    !form.data.accountable_confirmation))
                        }
                        onClick={() => {
                            if (!step) {
                                setStep(1);
                                return;
                            }
                            form.post(
                                `/emar/downtime/${downtimeId}/paper/${entry.id}/recovery-authorization`,
                                { preserveScroll: true, onSuccess: onClose },
                            );
                        }}
                    >
                        {form.processing && (
                            <Loader2 className="size-4 animate-spin" />
                        )}
                        {step ? 'Record my review' : 'Continue'}
                    </Button>
                }
            >
                <WizardStepPane key={steps[step].key}>
                    <fieldset
                        disabled={form.processing}
                        className="min-w-0 space-y-5"
                    >
                        {Object.keys(form.errors).length > 0 && (
                            <div
                                role="alert"
                                className="rounded-lg border border-status-critical bg-status-critical-bg p-3 text-status-critical-foreground"
                            >
                                {Object.entries(form.errors).map(
                                    ([key, error]) => (
                                        <p key={key}>{error}</p>
                                    ),
                                )}
                            </div>
                        )}
                        {step === 0 ? (
                            <>
                                <ReviewCard
                                    title="Confirmed paper record"
                                    icon={FileText}
                                >
                                    <ReviewRow
                                        label="Person / medicine"
                                        value={`${entry.snapshot.person} · ${entry.snapshot.medicine}`}
                                    />
                                    <ReviewRow
                                        label="Actual time"
                                        value={formatDateTime(entry.given_at)}
                                    />
                                    <ReviewRow
                                        label="Actual giver"
                                        value={entry.given_by}
                                    />
                                    <ReviewRow
                                        label="Second person"
                                        value={entry.witness ?? 'None recorded'}
                                    />
                                    <ReviewRow
                                        label="Entered"
                                        value={`${entry.entered_by} · ${formatDateTime(entry.entered_at)}`}
                                    />
                                </ReviewCard>
                                <PaperFactsReview
                                    clinical={entry.clinical_facts}
                                    stock={entry.stock_evidence}
                                    doseUnit={entry.snapshot.dose_unit}
                                />
                                <p className="text-subtle">
                                    The original grant must cover the actual
                                    dose time. The giver must confirm their own
                                    record. Your review permits this one
                                    historical entry to be checked again; it
                                    does not renew emergency access or post the
                                    dose.
                                </p>
                            </>
                        ) : (
                            <>
                                <div className="space-y-2">
                                    <Label htmlFor="paper-authority-reason">
                                        Why this historical entry can be
                                        recovered
                                    </Label>
                                    <Textarea
                                        id="paper-authority-reason"
                                        value={form.data.reason}
                                        onChange={(event) =>
                                            form.setData(
                                                'reason',
                                                event.target.value,
                                            )
                                        }
                                        placeholder="Record the evidence you checked and why this decision is justified."
                                    />
                                </div>
                                <div className="flex items-start gap-3">
                                    <Checkbox
                                        id="paper-authority-confirm"
                                        checked={
                                            form.data.accountable_confirmation
                                        }
                                        onCheckedChange={(value) =>
                                            form.setData(
                                                'accountable_confirmation',
                                                value === true,
                                            )
                                        }
                                    />
                                    <Label
                                        htmlFor="paper-authority-confirm"
                                        className="leading-relaxed"
                                    >
                                        I am the independent reviewer. I checked
                                        the signed paper, actual giver, time and
                                        original authority. This decision is
                                        recorded under my account.
                                    </Label>
                                </div>
                                <p className="text-subtle">
                                    Stock evidence, competencies, required
                                    second-person confirmation and current
                                    access will still be checked before applying
                                    the dose.
                                </p>
                            </>
                        )}
                    </fieldset>
                </WizardStepPane>
            </WizardShell>
            <ConfirmDialog
                frontline
                open={discard}
                onClose={() => setDiscard(false)}
                onConfirm={onClose}
                title="Discard this review draft?"
                description="Your review decision has not been saved."
                confirmText="Discard draft"
            />
        </>
    );
}
