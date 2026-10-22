import { ConfirmDialog } from '@/components/confirm-dialog';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Field, SelectInput } from '@/components/wizard/primitives';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardSuccessPane,
} from '@/components/wizard/shell';
import {
    formatDateTimeLong,
    toDatetimeLocal,
    workerTimeOffset,
} from '@/lib/datetime';
import { router } from '@inertiajs/react';
import axios from 'axios';
import { ClipboardCheck, ShieldCheck, UserRound } from 'lucide-react';
import { useRef, useState } from 'react';
import { ReferralDateTime } from './date-time';
import { EvidencePicker } from './evidence-picker';
import type { ProposalData, SupportProposal } from './proposal-types';
import { ProposalValues } from './proposal-values';
import { label, referralHref, type ReferralDetail } from './types';

export function ProposalReviewDialog({
    mode,
    referral,
    proposal,
    readiness,
    onClose,
}: {
    mode: 'acceptance' | 'approval';
    referral: ReferralDetail;
    proposal: SupportProposal;
    readiness: ProposalData['readiness'];
    onClose: () => void;
}) {
    const [step, setStep] = useState(0);
    const [result, setResult] = useState(
        mode === 'approval' ? 'conditional' : 'accepted',
    );
    const [party, setParty] = useState('person');
    const [signatory, setSignatory] = useState('');
    const [capacity, setCapacity] = useState('person');
    const [authorityNote, setAuthorityNote] = useState('');
    const [participation, setParticipation] = useState('');
    const [note, setNote] = useState('');
    const [conditions, setConditions] = useState('');
    const [at, setAt] = useState(
        toDatetimeLocal(Date.now()) +
            workerTimeOffset(new Date().toISOString()),
    );
    const [due, setDue] = useState(toDatetimeLocal(Date.now() + 7 * 86400000));
    const [evidence, setEvidence] = useState<number[]>([]);
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);
    const [saved, setSaved] = useState(false);
    const [uncertain, setUncertain] = useState(false);
    const [discard, setDiscard] = useState(false);
    const body = useRef<HTMLDivElement>(null);
    const pending = useRef(false);
    const steps = [
        {
            key: 'proposal',
            label: 'Review this version',
            blurb: 'Exact terms being reviewed',
            icon: ClipboardCheck,
        },
        {
            key: 'response',
            label:
                mode === 'approval'
                    ? 'Company decision'
                    : 'Person or payer response',
            blurb: 'Separate decision and evidence',
            icon: mode === 'approval' ? ShieldCheck : UserRound,
        },
        {
            key: 'confirm',
            label: 'Confirm',
            blurb: 'Preserve this response',
            icon: ClipboardCheck,
        },
    ];
    const go = (index: number) => {
        setStep(index);
        requestAnimationFrame(() => {
            body.current?.scrollTo({ top: 0 });
            body.current?.querySelector<HTMLElement>('[data-heading]')?.focus();
        });
    };
    const text = (
        title: string,
        value: string,
        setter: (value: string) => void,
        short = false,
    ) => (
        <Field label={title}>
            {short ? (
                <Input
                    value={value}
                    onChange={(event) => setter(event.target.value)}
                    maxLength={200}
                />
            ) : (
                <Textarea
                    value={value}
                    onChange={(event) => setter(event.target.value)}
                    maxLength={4000}
                />
            )}
        </Field>
    );
    function valid() {
        const invalid =
            note.trim().length < 5 ||
            (mode === 'approval'
                ? conditions.trim().length < 5 || !due
                : signatory.trim().length < 3 ||
                  authorityNote.trim().length < 5 ||
                  participation.trim().length < 5 ||
                  !at ||
                  (result === 'accepted' && !evidence.length));
        if (invalid) {
            setError(
                'Complete the response, supporting notes and required evidence before saving.',
            );
            go(1);
            return false;
        }
        return true;
    }
    async function save() {
        if (pending.current || uncertain || !valid()) return;
        pending.current = true;
        setBusy(true);
        setError('');
        const payload =
            mode === 'approval'
                ? {
                      version: referral.version,
                      result,
                      note,
                      conditions,
                      review_due_at: due,
                  }
                : {
                      version: referral.version,
                      kind:
                          party === 'person'
                              ? 'person_acceptance'
                              : 'payer_acceptance',
                      funding_case_id:
                          party === 'person' ? null : Number(party),
                      result,
                      signatory,
                      capacity: party === 'person' ? capacity : 'payer',
                      authority_note: authorityNote,
                      participation,
                      occurred_at: at,
                      note,
                      evidence_ids: evidence,
                  };
        try {
            const response = await axios.post<{ saved: boolean }>(
                `${referralHref(referral.reference)}/proposals/${proposal.id}/${mode}`,
                payload,
            );
            if (!response.data.saved) throw new Error('Save not confirmed');
            setSaved(true);
            router.reload({ only: ['referral'], preserveScroll: true });
        } catch (caught) {
            const response = axios.isAxiosError(caught)
                ? caught.response
                : undefined;
            setUncertain(!response || response.status === 409);
            setError(
                response?.data?.errors
                    ? Object.values(
                          response.data.errors as Record<string, string[]>,
                      )
                          .flat()
                          .join(' ')
                    : (response?.data?.message ??
                          'Save was not confirmed. Close this draft and review the current proposal and response history before repeating it.'),
            );
        } finally {
            pending.current = false;
            setBusy(false);
        }
    }
    return (
        <>
            <WizardShell
                open
                onClose={() => {
                    if (!busy) {
                        if (saved) onClose();
                        else setDiscard(true);
                    }
                }}
                title={
                    mode === 'approval'
                        ? 'Independent proposal approval'
                        : 'Record proposal acceptance'
                }
                description={`${referral.name} · proposal version ${proposal.version}`}
                railTitle="Support proposal"
                railIcon={ClipboardCheck}
                railSub="This response applies to this proposal version only. It does not commence care or activate a finance agreement."
                steps={steps}
                stepIndex={step}
                onStepClick={(index) => {
                    if (!busy) go(index);
                }}
                bodyRef={body}
                footerStart={
                    <Button
                        variant="ghost"
                        disabled={busy}
                        onClick={() => (step ? go(step - 1) : setDiscard(true))}
                    >
                        {step ? 'Back' : 'Cancel'}
                    </Button>
                }
                footerEnd={
                    step < 2 ? (
                        <Button
                            onClick={() => {
                                if (step === 0 || valid()) {
                                    setError('');
                                    go(step + 1);
                                }
                            }}
                        >
                            Continue
                        </Button>
                    ) : (
                        <Button
                            disabled={busy || uncertain}
                            onClick={() => void save()}
                        >
                            {busy ? 'Saving…' : 'Record response'}
                        </Button>
                    )
                }
                success={
                    saved ? (
                        <WizardSuccessPane
                            title="Proposal response recorded"
                            blurb="The response and evidence are retained for this version. Current readiness checks are refreshed separately; services have not been started."
                            actions={
                                <Button onClick={onClose}>View proposal</Button>
                            }
                        />
                    ) : undefined
                }
            >
                <div className="space-y-5">
                    <h2
                        data-heading
                        tabIndex={-1}
                        className="text-section-title"
                    >
                        {steps[step].label}
                    </h2>
                    {error && (
                        <Alert variant="warning">
                            <AlertDescription>
                                {error}
                                {uncertain && (
                                    <p>
                                        Close this draft and review the latest
                                        version and recorded responses before
                                        making a new decision.
                                    </p>
                                )}
                            </AlertDescription>
                        </Alert>
                    )}
                    {step === 0 && (
                        <>
                            <ProposalValues proposal={proposal} />
                            {!!readiness?.issues.length && (
                                <Alert variant="warning">
                                    <AlertDescription>
                                        <ul className="list-disc space-y-1 pl-4">
                                            {readiness.issues.map((issue) => (
                                                <li key={issue}>{issue}</li>
                                            ))}
                                        </ul>
                                    </AlertDescription>
                                </Alert>
                            )}
                        </>
                    )}
                    {step === 1 && (
                        <>
                            {mode === 'approval' ? (
                                <>
                                    <Alert variant="info">
                                        <AlertDescription>
                                            The author cannot approve their own
                                            proposal. Delegation is checked
                                            against each currency and budget
                                            period. Conditional approval cannot
                                            authorize service commencement.
                                        </AlertDescription>
                                    </Alert>
                                    <Field label="Company decision">
                                        <SelectInput
                                            value={result}
                                            onChange={setResult}
                                            placeholder="Choose decision"
                                            options={[
                                                'approved',
                                                'conditional',
                                                'rejected',
                                            ].map((value) => ({
                                                value,
                                                label: label(value),
                                            }))}
                                        />
                                    </Field>
                                    {text(
                                        'Internal conditions or reasons',
                                        conditions,
                                        setConditions,
                                    )}
                                    <ReferralDateTime
                                        id="proposal-approval-review"
                                        label="Approval review due"
                                        value={due}
                                        onChange={setDue}
                                    />
                                </>
                            ) : (
                                <>
                                    <Field label="Whose response is this?">
                                        <SelectInput
                                            value={party}
                                            onChange={setParty}
                                            placeholder="Choose person or payer"
                                            options={[
                                                {
                                                    value: 'person',
                                                    label: 'Person / authorized representative',
                                                },
                                                ...(
                                                    readiness?.private_payers ??
                                                    []
                                                ).map((payer) => ({
                                                    value: String(
                                                        payer.funding_case_id,
                                                    ),
                                                    label: `Private payer: ${payer.payer}`,
                                                })),
                                            ]}
                                        />
                                    </Field>
                                    <Field label="Response">
                                        <SelectInput
                                            value={result}
                                            onChange={setResult}
                                            placeholder="Choose response"
                                            options={[
                                                'accepted',
                                                'declined',
                                                'withdrawn',
                                            ].map((value) => ({
                                                value,
                                                label: label(value),
                                            }))}
                                        />
                                    </Field>
                                    {text(
                                        'Signatory / person responding',
                                        signatory,
                                        setSignatory,
                                        true,
                                    )}
                                    {party === 'person' && (
                                        <Field label="Responding as">
                                            <SelectInput
                                                value={capacity}
                                                onChange={setCapacity}
                                                placeholder="Choose capacity"
                                                options={[
                                                    {
                                                        value: 'person',
                                                        label: 'The person',
                                                    },
                                                    {
                                                        value: 'authorized_representative',
                                                        label: 'Authorized representative',
                                                    },
                                                ]}
                                            />
                                        </Field>
                                    )}
                                    {text(
                                        'Authority and identity checked',
                                        authorityNote,
                                        setAuthorityNote,
                                    )}
                                    {text(
                                        'How the person participated and understood the terms',
                                        participation,
                                        setParticipation,
                                    )}
                                    <ReferralDateTime
                                        id="proposal-response-at"
                                        label="Actual response date and time"
                                        value={at}
                                        onChange={setAt}
                                    />
                                    <Field label="Evidence for this exact version">
                                        <EvidencePicker
                                            reference={referral.reference}
                                            audience="funding"
                                            selected={evidence}
                                            onChange={setEvidence}
                                        />
                                    </Field>
                                </>
                            )}
                            {text('Response / review notes', note, setNote)}
                        </>
                    )}
                    {step === 2 && (
                        <ReviewCard
                            title="Response to record"
                            icon={ClipboardCheck}
                        >
                            <ReviewRow
                                label="Proposal version"
                                value={String(proposal.version)}
                            />
                            <ReviewRow label="Result" value={label(result)} />
                            <ReviewRow label="Notes" value={note} />
                            {mode === 'approval' ? (
                                <>
                                    <ReviewRow
                                        label="Conditions"
                                        value={conditions}
                                    />
                                    <ReviewRow
                                        label="Approval review due"
                                        value={formatDateTimeLong(due)}
                                    />
                                </>
                            ) : (
                                <>
                                    <ReviewRow
                                        label="Response from"
                                        value={
                                            party === 'person'
                                                ? 'Person / authorized representative'
                                                : (readiness?.private_payers.find(
                                                      (payer) =>
                                                          String(
                                                              payer.funding_case_id,
                                                          ) === party,
                                                  )?.payer ?? 'Private payer')
                                        }
                                    />
                                    <ReviewRow
                                        label="Signatory"
                                        value={signatory}
                                    />
                                    <ReviewRow
                                        label="Capacity"
                                        value={
                                            party === 'person'
                                                ? label(capacity)
                                                : 'Private payer'
                                        }
                                    />
                                    <ReviewRow
                                        label="Evidence documents"
                                        value={String(evidence.length)}
                                    />
                                    <ReviewRow
                                        label="Authority and identity checked"
                                        value={authorityNote}
                                    />
                                    <ReviewRow
                                        label="Person’s participation"
                                        value={participation}
                                    />
                                    <ReviewRow
                                        label="Actual response date and time"
                                        value={formatDateTimeLong(at)}
                                    />
                                </>
                            )}
                        </ReviewCard>
                    )}
                </div>
            </WizardShell>
            <ConfirmDialog
                open={discard}
                variant="destructive"
                onClose={() => setDiscard(false)}
                onConfirm={onClose}
                title="Close this proposal response?"
                description="Unsaved details will be cleared. If a save was not confirmed, check the response history before repeating it."
                confirmText="Close draft"
            />
        </>
    );
}
