import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import {
    Field,
    InfoCard,
    StepHead,
    TilePicker,
} from '@/components/wizard/primitives';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
    WizardSuccessPane,
    type WizardStep,
} from '@/components/wizard/shell';
import { useSafetyDraftState } from '@/hooks/use-safety-workspace-drafts';
import { formatDateTimeLong } from '@/lib/datetime';
import { useForm } from '@inertiajs/react';
import axios from 'axios';
import { ClipboardCheck, FileText, Settings, ShieldCheck } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import {
    APPROVAL_MODES,
    approvalModeLabel,
    confirmsApprovalPolicyReceipt,
    type ApprovalMode,
    type ApprovalReceipt,
    type ApprovalSnapshot,
} from './approval-policy';

const STEPS: WizardStep[] = [
    {
        key: 'policy',
        label: 'Approval workflow',
        blurb: 'Choose how a plan is approved',
        icon: ShieldCheck,
    },
    {
        key: 'reason',
        label: 'Reason',
        blurb: 'Explain the organisation decision',
        icon: FileText,
    },
    {
        key: 'review',
        label: 'Review',
        blurb: 'Check the effect and save',
        icon: ClipboardCheck,
    },
];
export function ApprovalPolicyDialog({
    policy: suppliedPolicy,
    canManage: suppliedCanManage,
    onClose,
}: {
    policy: ApprovalSnapshot;
    canManage: boolean;
    onClose: () => void;
}) {
    const [checked, setChecked] = useState<{
        source: ApprovalSnapshot;
        policy: ApprovalSnapshot;
        canManage: boolean;
    } | null>(null);
    const [checking, setChecking] = useState(false);
    const [checkFailed, setCheckFailed] = useState(false);
    const request = useRef<AbortController | null>(null);
    useEffect(
        () => () => request.current?.abort(),
        [suppliedPolicy, suppliedCanManage],
    );
    const policy =
        checked?.source === suppliedPolicy ? checked.policy : suppliedPolicy;
    const canManage =
        suppliedCanManage &&
        (checked?.source === suppliedPolicy ? checked.canManage : true);
    const [step, setStep] = useState(0);
    const [attempted, setAttempted] = useState(false);
    const [receipt, setReceipt] = useState<ApprovalReceipt | null>(null);
    const [failure, setFailure] = useState<string | null>(null);
    const [initial] = useState(() => ({
        mode: (policy.mode ?? '') as ApprovalMode | '',
        reason: '',
        expected_policy_revision: policy.policy_revision ?? -1,
        idempotency_key: crypto.randomUUID(),
    }));
    const form = useForm(initial);
    useSafetyDraftState({
        dirty: !receipt && (form.isDirty || attempted),
        busy: form.processing || checking,
    });
    const revisionAvailable =
        Number.isSafeInteger(policy.policy_revision) &&
        policy.policy_revision !== null &&
        policy.policy_revision >= 0;
    const stale = policy.policy_revision !== form.data.expected_policy_revision;
    const needsReason =
        !policy.configuration_valid || form.data.mode !== policy.mode;
    const ready =
        !checking &&
        !checkFailed &&
        canManage &&
        revisionAvailable &&
        !stale &&
        !!form.data.mode &&
        (!needsReason || !!form.data.reason.trim());
    const checkCurrent = async () => {
        if (form.processing || checking) return;
        const controller = new AbortController();
        request.current = controller;
        setChecking(true);
        try {
            const response = await axios.get('/health-safety/settings', {
                headers: { Accept: 'application/json' },
                signal: controller.signal,
            });
            if (controller.signal.aborted) return;
            const current = response.data?.policy as
                | ApprovalSnapshot
                | undefined;
            if (
                !current ||
                current.setting_key !== suppliedPolicy.setting_key ||
                typeof current.configuration_valid !== 'boolean' ||
                response.data.can_manage !== true
            )
                throw new Error('Current policy unavailable');
            setChecked({
                source: suppliedPolicy,
                policy: current,
                canManage: true,
            });
            setCheckFailed(false);
            setFailure(null);
        } catch (error) {
            if (controller.signal.aborted) return;
            setCheckFailed(true);
            if (
                axios.isAxiosError(error) &&
                [401, 403].includes(error.response?.status ?? 0)
            )
                setChecked({
                    source: suppliedPolicy,
                    policy,
                    canManage: false,
                });
            setFailure(
                'The current policy could not be checked. Your entries remain here. Retry the check before saving.',
            );
        } finally {
            if (request.current === controller) {
                request.current = null;
                setChecking(false);
            }
        }
    };
    const change = (key: 'mode' | 'reason', value: string) => {
        setFailure(null);
        setAttempted(false);
        form.setData({
            ...form.data,
            [key]: value,
            idempotency_key: crypto.randomUUID(),
        });
    };
    const save = () => {
        if (!ready || form.processing) return;
        setAttempted(true);
        setFailure(null);
        const submitted = { ...form.data };
        form.put('/health-safety/settings/restraint-plan-approval', {
            preserveScroll: true,
            preserveState: true,
            onSuccess: (page) => {
                const accepted = (
                    page.props as { health_safety_policy_receipt?: unknown }
                ).health_safety_policy_receipt;
                if (
                    confirmsApprovalPolicyReceipt(
                        accepted,
                        submitted,
                        policy.setting_key,
                    )
                )
                    setReceipt(accepted);
                else
                    setFailure(
                        'The save could not be confirmed. Your entries remain here. Check the current policy and change history before sending again.',
                    );
            },
            onError: () =>
                setFailure(
                    'The policy was not confirmed as saved. Your entries remain here; review the messages before trying again.',
                ),
        });
    };
    return (
        <WizardShell
            open
            onClose={onClose}
            title="Review behaviour support approval policy"
            description="Review the approval workflow and record the organisation decision."
            railIcon={Settings}
            railTitle="Approval policy"
            railSub="Behaviour support plans"
            steps={STEPS.map((item) => ({
                ...item,
                disabled: form.processing,
            }))}
            stepIndex={step}
            onStepClick={setStep}
            pct={ready ? 100 : form.data.mode ? 50 : 0}
            footerStart={
                <Button
                    variant="outline"
                    size="sm"
                    className="frontline-hit"
                    disabled={form.processing}
                    onClick={step ? () => setStep(step - 1) : onClose}
                >
                    {step ? 'Back' : 'Cancel'}
                </Button>
            }
            footerEnd={
                step < 2 ? (
                    <Button
                        size="sm"
                        className="frontline-hit"
                        disabled={
                            form.processing ||
                            !canManage ||
                            !form.data.mode ||
                            (step === 1 &&
                                needsReason &&
                                !form.data.reason.trim())
                        }
                        onClick={() => setStep(step + 1)}
                    >
                        Continue
                    </Button>
                ) : (
                    <Button
                        size="sm"
                        className="frontline-hit"
                        disabled={!ready || form.processing}
                        onClick={save}
                    >
                        {form.processing ? 'Saving policy…' : 'Save policy'}
                    </Button>
                )
            }
            success={
                receipt ? (
                    <WizardSuccessPane
                        title="Approval policy saved"
                        blurb={
                            <>
                                {approvalModeLabel(receipt.mode)} · revision{' '}
                                {receipt.policy_revision}
                                <br />
                                Recorded{' '}
                                {formatDateTimeLong(receipt.recorded_at)}.
                                Existing decisions retain their original policy.
                            </>
                        }
                        actions={
                            <Button className="frontline-hit" onClick={onClose}>
                                View current policy
                            </Button>
                        }
                    />
                ) : undefined
            }
        >
            <WizardStepPane>
                {(!canManage || !revisionAvailable || stale) && (
                    <InfoCard icon={ShieldCheck} tone="info">
                        {!canManage
                            ? 'Your settings access changed. Your entries remain here.'
                            : !revisionAvailable
                              ? 'The policy revision is unavailable. An authorised configuration review is required before saving.'
                              : 'The policy changed while you were editing. Review the current policy before sending your retained entries.'}
                        {canManage && revisionAvailable && stale && (
                            <Button
                                variant="outline"
                                size="sm"
                                className="frontline-hit mt-3"
                                disabled={form.processing}
                                onClick={() => {
                                    form.setData({
                                        ...form.data,
                                        expected_policy_revision:
                                            policy.policy_revision!,
                                        idempotency_key: crypto.randomUUID(),
                                    });
                                    setAttempted(false);
                                    setStep(0);
                                }}
                            >
                                Review current policy
                            </Button>
                        )}
                    </InfoCard>
                )}
                {(failure || Object.keys(form.errors).length > 0) && (
                    <div
                        role="alert"
                        className="mb-4 space-y-1 rounded-lg border border-status-critical/30 bg-status-critical-bg p-3 text-sm text-status-critical-foreground"
                    >
                        {failure && <p>{failure}</p>}
                        {Object.entries(form.errors).map(([field, message]) => (
                            <p key={field}>{message}</p>
                        ))}
                    </div>
                )}
                {step === 0 && (
                    <div className="@container space-y-4">
                        <StepHead
                            icon={ShieldCheck}
                            title="Approval workflow"
                            blurb="Choose the workflow used for new behaviour support plan decisions."
                        />
                        <p className="text-sm text-muted-foreground">
                            Current policy: {approvalModeLabel(policy.mode)} ·
                            revision {policy.policy_revision ?? 'unavailable'}.
                        </p>
                        <Field label="Approval workflow" required>
                            <TilePicker
                                value={form.data.mode}
                                onChange={(mode) => change('mode', mode)}
                                cols={2}
                                options={APPROVAL_MODES.map((mode) => ({
                                    ...mode,
                                    icon: ShieldCheck,
                                    disabled:
                                        !canManage || form.processing
                                            ? 'Unavailable while access is withdrawn or saving.'
                                            : null,
                                }))}
                            />
                        </Field>
                        <InfoCard icon={ShieldCheck} tone="info">
                            Each mode requires an explicit decision against the
                            exact plan version. This setting does not approve a
                            plan, establish professional qualification or
                            authorise medication.
                        </InfoCard>
                    </div>
                )}
                {step === 1 && (
                    <div className="space-y-4">
                        <StepHead
                            icon={FileText}
                            title="Record the reason"
                            blurb="A reason is required when changing or restoring the workflow."
                        />
                        <Field
                            label="Reason for this policy decision"
                            required={needsReason}
                            error={form.errors.reason}
                        >
                            <Textarea
                                aria-label="Reason for this policy decision"
                                rows={5}
                                maxLength={2000}
                                value={form.data.reason}
                                disabled={form.processing}
                                onChange={(event) =>
                                    change('reason', event.target.value)
                                }
                            />
                        </Field>
                        <p className="text-sm text-muted-foreground">
                            The decision keeps its actor, time, previous policy
                            and new policy in the change history.
                        </p>
                    </div>
                )}
                {step === 2 && (
                    <div className="space-y-4">
                        <StepHead
                            icon={ClipboardCheck}
                            title="Review the policy decision"
                            blurb="Check the effect before saving."
                        />
                        <ReviewCard
                            icon={ShieldCheck}
                            title="Approval workflow"
                            onEdit={() => setStep(0)}
                            span
                        >
                            <ReviewRow
                                label="Current workflow"
                                value={approvalModeLabel(policy.mode)}
                            />
                            <ReviewRow
                                label="Selected workflow"
                                value={approvalModeLabel(
                                    form.data.mode || null,
                                )}
                            />
                            <ReviewRow
                                label="Reviewed revision"
                                value={
                                    form.data.expected_policy_revision < 0
                                        ? 'Unavailable'
                                        : form.data.expected_policy_revision
                                }
                            />
                            <ReviewRow
                                label="Effect"
                                value={
                                    APPROVAL_MODES.find(
                                        (mode) => mode.key === form.data.mode,
                                    )?.description
                                }
                            />
                        </ReviewCard>
                        <ReviewCard
                            icon={FileText}
                            title="Reason"
                            onEdit={() => setStep(1)}
                            span
                        >
                            <ReviewRow
                                label="Reason"
                                value={
                                    form.data.reason ||
                                    'Keeping the current workflow; no reason entered.'
                                }
                            />
                        </ReviewCard>
                        <p className="text-sm text-muted-foreground">
                            Existing decisions keep the policy recorded when
                            they were made. A plan still needs its own current
                            approval, evidence and publication checks.
                        </p>
                        <Button
                            variant="outline"
                            size="sm"
                            className="frontline-hit"
                            disabled={form.processing || checking}
                            onClick={checkCurrent}
                        >
                            {checking
                                ? 'Checking current policy…'
                                : 'Check current policy'}
                        </Button>
                    </div>
                )}
            </WizardStepPane>
        </WizardShell>
    );
}
