import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Field, InfoCard, SelectInput } from '@/components/wizard/primitives';
import { useSafetyForm } from '@/hooks/use-safety-workspace-drafts';
import { formatDateOnly } from '@/lib/datetime';
import {
    RISK_REGISTER,
    type RiskCommand,
} from '@/lib/health-safety/risk-navigation';
import { Info } from 'lucide-react';
import { useState } from 'react';
import { CONSEQUENCE_LABELS, LIKELIHOOD_LABELS } from './ra-kit';
import type { RaDetail } from './types';

export const RISK_COMMAND_LABELS: Record<RiskCommand, string> = {
    approve: 'Approve & activate',
    review: 'Request review',
    residual: 'Record residual risk',
    'complete-review': 'Complete review',
    archive: 'Archive assessment',
};
export function riskCommandAllowed(
    detail: RaDetail,
    command: RiskCommand,
): boolean {
    const capability = {
        approve: 'activate',
        review: 'mark_for_review',
        residual: 'record_residual',
        'complete-review': 'complete_review',
        archive: 'archive',
    } as const;
    return detail.can.manage && detail.can[capability[command]] === true;
}

/** Each command has its own payload; recording a score never silently completes review. */
export function RaCommand({
    detail,
    command,
    onCancel,
    onSaved,
}: {
    detail: RaDetail;
    command: RiskCommand;
    onCancel: () => void;
    onSaved: () => void;
}) {
    const form = useSafetyForm({
        approver_note: '',
        review_note: '',
        review_due_at: '',
        residual_likelihood:
            detail.residual_likelihood == null
                ? ''
                : String(detail.residual_likelihood),
        residual_consequence:
            detail.residual_consequence == null
                ? ''
                : String(detail.residual_consequence),
        risk_acceptable:
            detail.risk_acceptable == null
                ? ''
                : detail.risk_acceptable
                  ? '1'
                  : '0',
    });
    const [failure, setFailure] = useState<string | null>(null);
    const descriptions: Record<RiskCommand, string> = {
        approve:
            'Confirm that this assessment and its controls are ready to use. Your approval is recorded in its history.',
        review: 'Flag this assessment for review. Its existing approval and review due date remain on the record.',
        residual:
            'Record the risk remaining after controls. This does not complete the review or change its due date.',
        'complete-review':
            'Confirm what was checked and set the next review date. This returns the assessment to active; changes to approved controls need a new version.',
        archive:
            'Retain this assessment as a historical record. Linked safety reviews and corrective actions keep their own status.',
    };
    return (
        <form
            className="space-y-5"
            onSubmit={(event) => {
                event.preventDefault();
                if (form.processing || !riskCommandAllowed(detail, command))
                    return;
                setFailure(null);
                form.transform((data) =>
                    command === 'approve'
                        ? { approver_note: data.approver_note || null }
                        : command === 'residual'
                          ? {
                                residual_likelihood: data.residual_likelihood
                                    ? Number(data.residual_likelihood)
                                    : null,
                                residual_consequence: data.residual_consequence
                                    ? Number(data.residual_consequence)
                                    : null,
                                risk_acceptable:
                                    data.risk_acceptable === ''
                                        ? null
                                        : data.risk_acceptable === '1',
                                review_note: data.review_note || null,
                            }
                          : command === 'complete-review'
                            ? {
                                  review_note: data.review_note,
                                  review_due_at: data.review_due_at,
                              }
                            : {},
                );
                form.post(
                    `${RISK_REGISTER}/${detail.id}/${command === 'approve' ? 'activate' : command}`,
                    {
                        preserveState: true,
                        preserveScroll: true,
                        onSuccess: (page) => {
                            const flash = page.props.flash as
                                | { error?: string }
                                | undefined;
                            if (flash?.error) {
                                setFailure(flash.error);
                                return;
                            }
                            form.reset();
                            form.clearErrors();
                            onSaved();
                        },
                    },
                );
            }}
        >
            <h2 className="text-section-title">
                {RISK_COMMAND_LABELS[command]}
            </h2>
            <InfoCard icon={Info}>{descriptions[command]}</InfoCard>
            {command === 'residual' && (
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field
                        label="Residual likelihood"
                        required
                        error={form.errors.residual_likelihood}
                    >
                        <SelectInput
                            frontline
                            value={form.data.residual_likelihood}
                            onChange={(v) =>
                                form.setData('residual_likelihood', v)
                            }
                            placeholder="Choose likelihood"
                            options={LIKELIHOOD_LABELS.map((label, i) => ({
                                value: String(i + 1),
                                label,
                            }))}
                        />
                    </Field>
                    <Field
                        label="Residual consequence"
                        required
                        error={form.errors.residual_consequence}
                    >
                        <SelectInput
                            frontline
                            value={form.data.residual_consequence}
                            onChange={(v) =>
                                form.setData('residual_consequence', v)
                            }
                            placeholder="Choose consequence"
                            options={CONSEQUENCE_LABELS.map((label, i) => ({
                                value: String(i + 1),
                                label,
                            }))}
                        />
                    </Field>
                    <Field
                        label="Is the remaining risk acceptable?"
                        required
                        error={form.errors.risk_acceptable}
                    >
                        <SelectInput
                            frontline
                            value={form.data.risk_acceptable}
                            onChange={(v) => form.setData('risk_acceptable', v)}
                            placeholder="Choose an assessment"
                            options={[
                                { value: '1', label: 'Yes' },
                                { value: '0', label: 'No' },
                            ]}
                        />
                    </Field>
                </div>
            )}
            {(command === 'residual' || command === 'complete-review') && (
                <Field
                    label={
                        command === 'complete-review'
                            ? 'Review findings'
                            : 'Residual risk notes'
                    }
                    required={command === 'complete-review'}
                    error={form.errors.review_note}
                >
                    <Textarea
                        aria-label={
                            command === 'complete-review'
                                ? 'Review findings'
                                : 'Residual risk notes'
                        }
                        maxLength={5000}
                        required={command === 'complete-review'}
                        value={form.data.review_note}
                        onChange={(event) =>
                            form.setData('review_note', event.target.value)
                        }
                        rows={5}
                    />
                </Field>
            )}
            {command === 'complete-review' && (
                <Field
                    label="Next review due"
                    required
                    error={form.errors.review_due_at}
                >
                    <Input
                        type="date"
                        aria-label="Next review due"
                        required
                        value={form.data.review_due_at}
                        onChange={(event) =>
                            form.setData('review_due_at', event.target.value)
                        }
                    />
                    <p className="text-subtle">
                        Current review due:{' '}
                        {formatDateOnly(detail.review_due_at, 'Not set')}.
                        Choose a future NZ calendar date.
                    </p>
                </Field>
            )}
            {command === 'approve' && (
                <Field label="Approval note" error={form.errors.approver_note}>
                    <Textarea
                        aria-label="Approval note"
                        value={form.data.approver_note}
                        onChange={(event) =>
                            form.setData('approver_note', event.target.value)
                        }
                        rows={4}
                    />
                </Field>
            )}
            {(failure || Object.keys(form.errors).length > 0) && (
                <div role="alert" className="text-sm text-status-critical">
                    {failure ?? Object.values(form.errors).join(' ')}
                </div>
            )}
            <div className="flex flex-wrap gap-3">
                <Button
                    className="frontline-tap"
                    type="submit"
                    disabled={form.processing}
                >
                    {form.processing ? 'Saving…' : RISK_COMMAND_LABELS[command]}
                </Button>
                <Button
                    className="frontline-tap"
                    variant="outline"
                    type="button"
                    onClick={onCancel}
                    disabled={form.processing}
                >
                    Cancel
                </Button>
            </div>
        </form>
    );
}
