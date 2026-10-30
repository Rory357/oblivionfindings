import { ConfirmDialog } from '@/components/confirm-dialog';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import { Field, SelectInput } from '@/components/wizard/primitives';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardSuccessPane,
} from '@/components/wizard/shell';
import { formatDateOnly, formatDateTimeLong } from '@/lib/datetime';
import { router } from '@inertiajs/react';
import axios from 'axios';
import { ClipboardCheck, ShieldCheck, Wallet } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { ReferralDateTime, validReferralDateTime } from './date-time';
import { EvidencePicker } from './evidence-picker';
import type { SupportProposal } from './proposal-types';
import {
    label,
    referralHref,
    type Permissions,
    type ReferralDetail,
} from './types';

type ArrangementData = {
    enabled: boolean;
    current: boolean;
    gap: {
        ready: boolean;
        issues: string[];
        currency: string | null;
        gross: string;
    };
    arrangement: {
        id: number;
        authored_by: number;
        authored_at: string;
        terms: {
            payer: string;
            budget_reference: string;
            cost_cap: string;
            gross: string;
            currency: string;
            starts_on: string;
            ends_on: string;
            review_due_at: string;
            reason: string;
            evidence_ids: number[];
        };
        availability: { usable: boolean; issues: string[] };
    } | null;
    history: {
        id: number;
        actor_id: number;
        actor_name: string;
        occurred_at: string;
        details: { result: string; note: string };
    }[];
};

export function InternalFundingDialog({
    referral,
    proposal,
    permissions,
    actorId,
    onClose,
}: {
    referral: ReferralDetail;
    proposal: SupportProposal;
    permissions: Permissions;
    actorId?: number;
    onClose: () => void;
}) {
    const [data, setData] = useState<ArrangementData | null>(null);
    const [error, setError] = useState('');
    const [retry, setRetry] = useState(0);
    const [step, setStep] = useState(0);
    const [busy, setBusy] = useState(false);
    const [saved, setSaved] = useState(false);
    const [uncertain, setUncertain] = useState(false);
    const [discard, setDiscard] = useState(false);
    const [form, setForm] = useState({
        payer: '',
        budget_reference: '',
        cost_cap: '',
        reason: '',
        review_due_at: '',
        evidence_ids: [] as number[],
    });
    const [result, setResult] = useState('rejected');
    const [note, setNote] = useState('');
    const pending = useRef(false);
    const body = useRef<HTMLDivElement>(null);
    useEffect(() => {
        const controller = new AbortController();
        setData(null);
        setError('');
        axios
            .get(
                `${referralHref(referral.reference)}/proposals/${proposal.id}/internal-funding`,
                { signal: controller.signal },
            )
            .then(({ data }) => {
                if (!controller.signal.aborted) setData(data);
            })
            .catch(() => {
                if (!controller.signal.aborted)
                    setError(
                        'Company funding could not be loaded. Check your access or try again.',
                    );
            });
        return () => controller.abort();
    }, [referral.reference, proposal.id, retry]);
    const arrangement = data?.arrangement;
    const open = referral.stage !== 'closed' && !referral.general_enquiry;
    const canPrepare =
        !arrangement &&
        data?.current &&
        data.enabled &&
        data.gap.ready &&
        permissions['funding.manage'] &&
        open;
    const canReview =
        !!arrangement &&
        permissions['proposals.approve'] &&
        arrangement.authored_by !== actorId;
    const canAct = canPrepare || canReview;
    const fieldsValid = arrangement
        ? note.trim().length >= 10
        : form.payer.trim().length >= 3 &&
          form.budget_reference.trim().length >= 3 &&
          /^\d{1,10}(\.\d{1,2})?$/.test(form.cost_cap) &&
          form.reason.trim().length >= 10 &&
          validReferralDateTime(form.review_due_at) &&
          form.evidence_ids.length > 0;
    const dirty =
        note !== '' ||
        Object.values(form).some((value) =>
            Array.isArray(value) ? value.length > 0 : value !== '',
        );
    const close = () => {
        if (!busy) {
            if (dirty && !saved && !uncertain) setDiscard(true);
            else onClose();
        }
    };
    const go = (next: number) => {
        setStep(next);
        requestAnimationFrame(() => {
            body.current?.scrollTo({ top: 0 });
            body.current?.focus();
        });
    };
    const steps = [
        {
            key: 'terms',
            label: 'Exact support and cost',
            blurb: 'Full temporary period',
            icon: Wallet,
        },
        {
            key: 'decision',
            label: arrangement ? 'Company decision' : 'Payer and budget',
            blurb: 'Evidence and review',
            icon: ShieldCheck,
        },
        {
            key: 'confirm',
            label: 'Confirm',
            blurb: 'Keep a dated record',
            icon: ClipboardCheck,
        },
    ];
    async function save() {
        if (pending.current || !canAct || !fieldsValid || uncertain) return;
        pending.current = true;
        setBusy(true);
        setError('');
        try {
            const response = await axios.post(
                arrangement
                    ? `${referralHref(referral.reference)}/internal-funding/${arrangement.id}/review`
                    : `${referralHref(referral.reference)}/proposals/${proposal.id}/internal-funding`,
                {
                    version: referral.version,
                    ...(arrangement ? { result, note } : form),
                },
            );
            if (!response.data.saved) throw new Error('Save not confirmed');
            setSaved(true);
            router.reload({
                only: ['referral', 'permissions'],
                preserveScroll: true,
            });
        } catch (caught) {
            const response = axios.isAxiosError(caught)
                ? caught.response
                : undefined;
            setUncertain(
                !response ||
                    response.status >= 500 ||
                    [403, 404, 409].includes(response.status),
            );
            setError(
                response?.data?.errors
                    ? Object.values(response.data.errors).flat().join(' ')
                    : (response?.data?.message ??
                          'Saving was not confirmed. Close and reopen this record to check its saved state before making another decision.'),
            );
        } finally {
            pending.current = false;
            setBusy(false);
        }
    }
    const edit = (
        name: 'payer' | 'budget_reference' | 'cost_cap',
        title: string,
    ) => (
        <Field label={title} required>
            <Input
                value={form[name]}
                maxLength={name === 'cost_cap' ? 13 : 200}
                inputMode={name === 'cost_cap' ? 'decimal' : undefined}
                onChange={(event) =>
                    setForm({ ...form, [name]: event.target.value })
                }
            />
        </Field>
    );
    return (
        <>
            <WizardShell
                open
                onClose={close}
                title="Temporary company funding"
                description={`Proposal version ${proposal.version} · ${referral.name}`}
                railTitle="Company commitment"
                railIcon={Wallet}
                railSub="Separate from external funding"
                steps={canAct ? steps : steps.slice(0, 1)}
                stepIndex={step}
                headerLabel={steps[step].label}
                sequential
                onStepClick={(next) => {
                    if (next < step && !busy && !uncertain) go(next);
                }}
                footerStart={
                    !saved && step > 0 ? (
                        <Button
                            variant="outline"
                            disabled={busy || uncertain}
                            onClick={() => go(step - 1)}
                        >
                            Back
                        </Button>
                    ) : undefined
                }
                footerEnd={
                    saved || !canAct || uncertain ? (
                        <Button
                            variant="outline"
                            disabled={busy}
                            onClick={close}
                        >
                            {uncertain
                                ? 'Close and check saved record'
                                : 'Close'}
                        </Button>
                    ) : step < 2 ? (
                        <Button
                            disabled={!data || (step === 1 && !fieldsValid)}
                            onClick={() => go(step + 1)}
                        >
                            Continue
                        </Button>
                    ) : (
                        <Button
                            disabled={busy || !fieldsValid}
                            onClick={() => void save()}
                        >
                            {busy
                                ? 'Saving…'
                                : arrangement
                                  ? 'Record company decision'
                                  : 'Submit for independent review'}
                        </Button>
                    )
                }
            >
                <div
                    ref={body}
                    tabIndex={-1}
                    className="min-h-0 flex-1 space-y-5 overflow-y-auto p-5 outline-none"
                >
                    {error && (
                        <Alert variant="warning">
                            <AlertDescription>
                                {error}
                                {!data && (
                                    <Button
                                        variant="outline"
                                        onClick={() =>
                                            setRetry((value) => value + 1)
                                        }
                                    >
                                        Try again
                                    </Button>
                                )}
                            </AlertDescription>
                        </Alert>
                    )}
                    {!data && !error && (
                        <p role="status">Loading company funding…</p>
                    )}
                    {saved ? (
                        <WizardSuccessPane
                            actions={
                                <Button onClick={onClose}>
                                    Return to proposals
                                </Button>
                            }
                            title={
                                arrangement
                                    ? 'Company decision recorded'
                                    : 'Arrangement prepared'
                            }
                            blurb="Separate proposal approval, the person’s agreement and all service-start checks still apply. No external award, invoice or budget posting was created."
                        />
                    ) : (
                        data && (
                            <>
                                <Alert variant="info">
                                    <AlertDescription>
                                        This is an internal company commitment
                                        for the full temporary period. It does
                                        not represent government funding or
                                        remove consent, safety, contract or
                                        staffing requirements.
                                    </AlertDescription>
                                </Alert>
                                {step === 0 && (
                                    <>
                                        <ReviewCard
                                            title="Support being considered"
                                            icon={Wallet}
                                        >
                                            <ReviewRow
                                                label="Proposal"
                                                value={`${proposal.terms.title} · version ${proposal.version}`}
                                            />
                                            <ReviewRow
                                                label="Dates"
                                                value={`${formatDateOnly(proposal.terms.starts_on)} – ${formatDateOnly(proposal.terms.ends_on)}`}
                                            />
                                            <ReviewRow
                                                label="Costed gap for the full period"
                                                value={
                                                    data.gap.currency
                                                        ? `${data.gap.currency} ${data.gap.gross}`
                                                        : 'Not yet costed'
                                                }
                                            />
                                            <p className="text-subtle">
                                                Use total-term support
                                                quantities for temporary
                                                funding. Recurring weekly or
                                                monthly prices are not silently
                                                converted into a full-period
                                                budget.
                                            </p>
                                        </ReviewCard>
                                        {!data.enabled && (
                                            <Alert variant="warning">
                                                <AlertDescription>
                                                    Temporary company funding is
                                                    disabled in published
                                                    settings. Recorded decisions
                                                    remain available.
                                                </AlertDescription>
                                            </Alert>
                                        )}
                                        {!arrangement &&
                                            data.gap.issues.length > 0 && (
                                                <Alert variant="warning">
                                                    <AlertDescription>
                                                        <ul className="list-disc pl-5">
                                                            {data.gap.issues.map(
                                                                (issue) => (
                                                                    <li
                                                                        key={
                                                                            issue
                                                                        }
                                                                    >
                                                                        {issue}
                                                                    </li>
                                                                ),
                                                            )}
                                                        </ul>
                                                    </AlertDescription>
                                                </Alert>
                                            )}
                                        {arrangement && (
                                            <ReviewCard
                                                title="Recorded company arrangement"
                                                icon={ShieldCheck}
                                            >
                                                <StatusBadge
                                                    status={
                                                        arrangement.availability
                                                            .usable
                                                            ? 'approved'
                                                            : 'review_required'
                                                    }
                                                />
                                                <ReviewRow
                                                    label="Responsible payer"
                                                    value={
                                                        arrangement.terms.payer
                                                    }
                                                />
                                                <ReviewRow
                                                    label="Budget reference"
                                                    value={
                                                        arrangement.terms
                                                            .budget_reference
                                                    }
                                                />
                                                <ReviewRow
                                                    label="Full-period cap"
                                                    value={`${arrangement.terms.currency} ${arrangement.terms.cost_cap}`}
                                                />
                                                <ReviewRow
                                                    label="Review due"
                                                    value={formatDateTimeLong(
                                                        arrangement.terms
                                                            .review_due_at,
                                                    )}
                                                />
                                                <ReviewRow
                                                    label="Reason"
                                                    value={
                                                        arrangement.terms.reason
                                                    }
                                                />
                                                <ReviewRow
                                                    label="Evidence references"
                                                    value={arrangement.terms.evidence_ids.join(
                                                        ', ',
                                                    )}
                                                />
                                                {arrangement.availability.issues.map(
                                                    (issue) => (
                                                        <p
                                                            className="text-subtle"
                                                            key={issue}
                                                        >
                                                            {issue}
                                                        </p>
                                                    ),
                                                )}
                                                {arrangement.authored_by ===
                                                    actorId && (
                                                    <p className="text-subtle">
                                                        Another authorized
                                                        colleague must review
                                                        the arrangement you
                                                        prepared.
                                                    </p>
                                                )}
                                            </ReviewCard>
                                        )}
                                        {data.history.map((event) => (
                                            <ReviewCard
                                                key={event.id}
                                                title={label(
                                                    event.details.result,
                                                )}
                                                icon={ShieldCheck}
                                            >
                                                <ReviewRow
                                                    label="Recorded"
                                                    value={formatDateTimeLong(
                                                        event.occurred_at,
                                                    )}
                                                />
                                                <ReviewRow
                                                    label="Reviewed by"
                                                    value={event.actor_name}
                                                />
                                                <ReviewRow
                                                    label="Decision"
                                                    value={event.details.note}
                                                />
                                            </ReviewCard>
                                        ))}
                                    </>
                                )}
                                {step === 1 && (
                                    <fieldset
                                        disabled={busy || uncertain}
                                        className="space-y-5"
                                    >
                                        {arrangement ? (
                                            <>
                                                <Field
                                                    label="Company decision"
                                                    required
                                                >
                                                    <SelectInput
                                                        value={result}
                                                        placeholder="Choose a decision"
                                                        options={[
                                                            ...(data.enabled &&
                                                            data.current &&
                                                            open &&
                                                            data.history[0]
                                                                ?.details
                                                                .result !==
                                                                'approved'
                                                                ? [
                                                                      {
                                                                          value: 'approved',
                                                                          label: 'Approve within my delegation',
                                                                      },
                                                                  ]
                                                                : []),
                                                            {
                                                                value: 'rejected',
                                                                label: 'Reject',
                                                            },
                                                            {
                                                                value: 'revoked',
                                                                label: 'Revoke previous approval',
                                                            },
                                                        ]}
                                                        onChange={setResult}
                                                    />
                                                </Field>
                                                <Field
                                                    label="Decision reason"
                                                    required
                                                >
                                                    <Textarea
                                                        value={note}
                                                        maxLength={4000}
                                                        onChange={(event) =>
                                                            setNote(
                                                                event.target
                                                                    .value,
                                                            )
                                                        }
                                                    />
                                                </Field>
                                                <p className="text-subtle">
                                                    Approval uses your
                                                    configured full-period
                                                    delegation and creates a
                                                    review task assigned to you.
                                                    Revoking approval flags
                                                    existing support for manager
                                                    review; it does not silently
                                                    stop care.
                                                </p>
                                            </>
                                        ) : (
                                            <>
                                                {edit(
                                                    'payer',
                                                    'Responsible company payer',
                                                )}
                                                {edit(
                                                    'budget_reference',
                                                    'Finance budget reference',
                                                )}
                                                {edit(
                                                    'cost_cap',
                                                    `Full-period cost cap (${data.gap.currency ?? 'currency'})`,
                                                )}
                                                <Field
                                                    label="Reason for temporary company support"
                                                    required
                                                >
                                                    <Textarea
                                                        value={form.reason}
                                                        maxLength={4000}
                                                        onChange={(event) =>
                                                            setForm({
                                                                ...form,
                                                                reason: event
                                                                    .target
                                                                    .value,
                                                            })
                                                        }
                                                    />
                                                </Field>
                                                <ReferralDateTime
                                                    id="company-funding-review"
                                                    label="Review due"
                                                    value={form.review_due_at}
                                                    onChange={(value) =>
                                                        setForm({
                                                            ...form,
                                                            review_due_at:
                                                                value,
                                                        })
                                                    }
                                                />
                                                <h3 className="text-section-title">
                                                    Budget and commitment
                                                    evidence
                                                </h3>
                                                <p className="text-subtle">
                                                    Select available funding
                                                    documents that substantiate
                                                    the payer, budget and
                                                    proposed commitment. Upload
                                                    missing documents in the
                                                    referral’s Documents tab
                                                    first.
                                                </p>
                                                <EvidencePicker
                                                    reference={
                                                        referral.reference
                                                    }
                                                    audience="funding"
                                                    selected={form.evidence_ids}
                                                    onChange={(evidence_ids) =>
                                                        setForm({
                                                            ...form,
                                                            evidence_ids,
                                                        })
                                                    }
                                                />
                                            </>
                                        )}
                                    </fieldset>
                                )}
                                {step === 2 && (
                                    <ReviewCard
                                        title="Confirm the company record"
                                        icon={ClipboardCheck}
                                    >
                                        <ReviewRow
                                            label="Exact proposal"
                                            value={`Version ${proposal.version} · ${formatDateOnly(proposal.terms.starts_on)} – ${formatDateOnly(proposal.terms.ends_on)}`}
                                        />
                                        {arrangement ? (
                                            <>
                                                <ReviewRow
                                                    label="Decision"
                                                    value={label(result)}
                                                />
                                                <ReviewRow
                                                    label="Reason"
                                                    value={note}
                                                />
                                            </>
                                        ) : (
                                            <>
                                                <ReviewRow
                                                    label="Responsible payer"
                                                    value={form.payer}
                                                />
                                                <ReviewRow
                                                    label="Budget"
                                                    value={
                                                        form.budget_reference
                                                    }
                                                />
                                                <ReviewRow
                                                    label="Full-period cap"
                                                    value={`${data.gap.currency} ${form.cost_cap}`}
                                                />
                                                <ReviewRow
                                                    label="Review due"
                                                    value={formatDateTimeLong(
                                                        form.review_due_at,
                                                    )}
                                                />
                                                <ReviewRow
                                                    label="Reason"
                                                    value={form.reason}
                                                />
                                                <ReviewRow
                                                    label="Evidence"
                                                    value={`${form.evidence_ids.length} selected ${form.evidence_ids.length === 1 ? 'document' : 'documents'}`}
                                                />
                                            </>
                                        )}
                                        <p className="text-subtle">
                                            This record is preserved. Changes to
                                            its terms require a revised proposal
                                            and a new company arrangement.
                                        </p>
                                    </ReviewCard>
                                )}
                            </>
                        )
                    )}
                </div>
            </WizardShell>
            <ConfirmDialog
                open={discard}
                onClose={() => setDiscard(false)}
                onConfirm={onClose}
                title="Discard this unsaved decision?"
                description="Your entered details have not been saved."
                confirmText="Discard changes"
                variant="destructive"
            />
        </>
    );
}
