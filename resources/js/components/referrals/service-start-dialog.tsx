import { ConfirmDialog } from '@/components/confirm-dialog';
import { DatePicker } from '@/components/fleet-assets/maintenance/date-picker';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Field, SelectInput } from '@/components/wizard/primitives';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardSuccessPane,
} from '@/components/wizard/shell';
import {
    formatDateOnly,
    formatDateTimeLong,
    toDatetimeLocal,
    workerTimeOffset,
} from '@/lib/datetime';
import { router } from '@inertiajs/react';
import axios from 'axios';
import { CalendarCheck, ClipboardCheck, FileCheck, Users } from 'lucide-react';
import { useRef, useState } from 'react';
import { ReferralDateTime, validReferralDateTime } from './date-time';
import { EvidencePicker } from './evidence-picker';
import type {
    StartOperation,
    StartPreview,
    StartReadiness,
} from './service-start-types';
import { referralHref } from './types';

export function ServiceStartDialog({
    reference,
    preview,
    operation,
    onClose,
}: {
    reference: string;
    preview: StartPreview;
    operation: StartOperation;
    onClose: () => void;
}) {
    const [step, setStep] = useState(0);
    const [agreementId, setAgreementId] = useState('');
    const [planId, setPlanId] = useState('');
    const [date, setDate] = useState(preview.start?.agreed_date ?? '');
    const [mapping, setMapping] = useState<Record<string, string>>({});
    const [workers, setWorkers] = useState<number[]>([]);
    const [evidence, setEvidence] = useState<number[]>([]);
    const [signatory, setSignatory] = useState('');
    const [signedAt, setSignedAt] = useState('');
    const [agreementNote, setAgreementNote] = useState('');
    const [careNote, setCareNote] = useState('');
    const [staffNote, setStaffNote] = useState('');
    const [actual, setActual] = useState(() => {
        const now = new Date();
        return `${toDatetimeLocal(now)}:${String(now.getSeconds()).padStart(2, '0')}${workerTimeOffset(now.toISOString())}`;
    });
    const [note, setNote] = useState('');
    const [acknowledged, setAcknowledged] = useState(false);
    const [separate, setSeparate] = useState(false);
    const [readiness, setReadiness] = useState<StartReadiness | null>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [uncertain, setUncertain] = useState(false);
    const [conflict, setConflict] = useState(false);
    const [accessLost, setAccessLost] = useState(false);
    const [saved, setSaved] = useState(false);
    const [discard, setDiscard] = useState(false);
    const sending = useRef(false);
    const issued = useRef<Record<string, unknown> | null>(null);
    const body = useRef<HTMLDivElement>(null);
    const agreement = preview.agreements.find(
        (item) => item.id === Number(agreementId),
    );
    const plan = preview.care_plans.find((item) => item.id === Number(planId));
    const scheduling = operation === 'schedule';
    const title = scheduling
        ? 'Review and agree start'
        : operation === 'commence'
          ? 'Confirm actual start'
          : 'Cancel scheduled start';
    const steps = scheduling
        ? [
              {
                  key: 'records',
                  label: 'Service and date',
                  blurb: 'Map the current agreement',
                  icon: CalendarCheck,
              },
              {
                  key: 'evidence',
                  label: 'Care and evidence',
                  blurb: 'Consent, signature and staff',
                  icon: Users,
              },
              {
                  key: 'confirm',
                  label: 'Readiness review',
                  blurb: 'Current checks and approval',
                  icon: ClipboardCheck,
              },
          ]
        : [
              {
                  key: 'confirm',
                  label: title,
                  blurb: 'Record the reviewed outcome',
                  icon: ClipboardCheck,
              },
          ];
    function go(next: number) {
        setStep(next);
        setError('');
        setReadiness(null);
        requestAnimationFrame(() =>
            body.current?.querySelector<HTMLElement>('[data-heading]')?.focus(),
        );
    }
    function valid(): boolean {
        const okay = !scheduling
            ? note.trim().length >= 10 &&
              (operation === 'cancel' ||
                  (validReferralDateTime(actual) && acknowledged))
            : step === 0
              ? !!date &&
                !!agreement &&
                !!plan &&
                !!preview.proposal &&
                preview.proposal.lines.every((line) => !!mapping[line.key])
              : step === 1
                ? workers.length > 0 &&
                  evidence.length > 0 &&
                  signatory.trim().length >= 3 &&
                  validReferralDateTime(signedAt) &&
                  [agreementNote, careNote, staffNote].every(
                      (value) => value.trim().length >= 10,
                  )
                : acknowledged && separate;
        if (!okay)
            setError(
                'Complete the required fields and confirmations before continuing.',
            );
        return okay;
    }
    function selection() {
        return {
            proposal_id: preview.proposal?.id,
            service_agreement_id: Number(agreementId),
            care_plan_id: Number(planId),
            agreement_fingerprint: agreement?.fingerprint,
            care_plan_digest: plan?.digest,
            agreed_date: date,
            staff_ids: workers,
            agreement_lines: preview.proposal?.lines.map((line) => ({
                line_key: line.key,
                line_item_id: Number(mapping[line.key]),
            })),
            agreement_evidence_ids: evidence,
            signatory,
            signed_at: signedAt,
            agreement_review: agreementNote,
            care_safety_review: careNote,
            staffing_review: staffNote,
            acknowledge_current_terms: acknowledged,
            acknowledge_separate_commencement: separate,
        };
    }
    async function submit(checkOnly = false) {
        if (sending.current || conflict || (!uncertain && !valid())) return;
        sending.current = true;
        setBusy(true);
        setError('');
        const payload = checkOnly
            ? { version: preview.version, ...selection() }
            : (issued.current ?? {
                  version: preview.version,
                  submission_key: crypto.randomUUID(),
                  ...(scheduling
                      ? selection()
                      : operation === 'commence'
                        ? {
                              commenced_at: actual,
                              note,
                              acknowledge_actual_start: acknowledged,
                          }
                        : { reason: note }),
              });
        if (!checkOnly) issued.current = payload;
        try {
            const { data } = await axios.post(
                `${referralHref(reference)}/service-start/${checkOnly ? 'check' : operation}`,
                payload,
            );
            if (checkOnly) setReadiness(data as StartReadiness);
            else {
                setSaved(true);
                setUncertain(false);
                router.reload({
                    only: ['referral', 'permissions'],
                    preserveScroll: true,
                });
            }
        } catch (caught) {
            const status = axios.isAxiosError(caught)
                ? caught.response?.status
                : undefined;
            setUncertain(!checkOnly && (!status || status >= 500));
            if (!checkOnly && status && status < 500) issued.current = null;
            setConflict(status === 409 || status === 403 || status === 404);
            setAccessLost(status === 403 || status === 404);
            setError(
                axios.isAxiosError(caught)
                    ? (caught.response?.data?.message ??
                          'The result could not be confirmed. Retry to check the saved receipt.')
                    : 'The result could not be confirmed. Retry to check the saved receipt.',
            );
        } finally {
            sending.current = false;
            setBusy(false);
        }
    }
    return (
        <>
            <WizardShell
                open
                onClose={() => !busy && (saved ? onClose() : setDiscard(true))}
                title={title}
                description="A separate decision for this service."
                railIcon={CalendarCheck}
                railTitle="Start support"
                railSub="Onboarding, start approval and actual commencement remain separate milestones."
                steps={steps}
                stepIndex={step}
                bodyRef={body}
                onStepClick={(next) => {
                    if (
                        !busy &&
                        !uncertain &&
                        !conflict &&
                        (next < step || valid())
                    )
                        go(Math.min(next, step + 1));
                }}
                footerStart={
                    <Button
                        variant="ghost"
                        disabled={busy}
                        onClick={() =>
                            step > 0 && !uncertain && !conflict
                                ? go(step - 1)
                                : setDiscard(true)
                        }
                    >
                        {step > 0 && !uncertain && !conflict ? 'Back' : 'Close'}
                    </Button>
                }
                footerEnd={
                    <Button
                        disabled={
                            busy ||
                            conflict ||
                            (scheduling &&
                                step === 2 &&
                                !!readiness &&
                                !readiness.ready)
                        }
                        onClick={() =>
                            uncertain
                                ? void submit()
                                : scheduling && step < 2
                                  ? valid() && go(step + 1)
                                  : void submit(scheduling && !readiness)
                        }
                    >
                        {busy
                            ? 'Checking…'
                            : uncertain
                              ? 'Retry same decision'
                              : scheduling && step < 2
                                ? 'Continue'
                                : scheduling && !readiness
                                  ? 'Check readiness'
                                  : title}
                    </Button>
                }
                success={
                    saved ? (
                        <WizardSuccessPane
                            title={
                                scheduling
                                    ? 'Start date agreed'
                                    : operation === 'commence'
                                      ? 'Actual start recorded'
                                      : 'Scheduled start cancelled'
                            }
                            blurb={
                                scheduling
                                    ? 'A follow-up is assigned to confirm actual commencement. This approval has not activated the person or recorded delivery.'
                                    : 'The decision is recorded. Funding assistance and other open follow-ups remain assigned.'
                            }
                            actions={
                                <Button onClick={onClose}>
                                    Return to referral
                                </Button>
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
                                {conflict && (
                                    <p>
                                        Close and reload the review before
                                        continuing.
                                    </p>
                                )}
                            </AlertDescription>
                        </Alert>
                    )}
                    {!accessLost && (
                        <fieldset
                            disabled={busy || uncertain || conflict}
                            className="space-y-5"
                        >
                            {scheduling && step === 0 && (
                                <>
                                    <ReviewCard
                                        title="Supported person and proposal"
                                        icon={FileCheck}
                                    >
                                        <ReviewRow
                                            label="Person"
                                            value={preview.person.name}
                                        />
                                        <ReviewRow
                                            label="Proposal"
                                            value={`${preview.proposal?.title ?? 'Missing proposal'} · Version ${preview.proposal?.version ?? '—'}`}
                                        />
                                        <ReviewRow
                                            label="Period"
                                            value={`${formatDateOnly(preview.proposal?.starts_on)} – ${formatDateOnly(preview.proposal?.ends_on)}`}
                                        />
                                    </ReviewCard>
                                    <Field label="Agreed start date" required>
                                        <DatePicker
                                            id="service-agreed-date"
                                            label="Agreed start date"
                                            value={date}
                                            onChange={setDate}
                                        />
                                    </Field>
                                    {(!preview.agreements.length ||
                                        !preview.care_plans.length) && (
                                        <Alert>
                                            <AlertDescription>
                                                {!preview.agreements.length &&
                                                    'No service agreement is available for this person. Have an authorised staff member prepare and approve the agreement. '}
                                                {!preview.care_plans.length &&
                                                    'No care plan is available for this person. Have an authorised staff member prepare and review the care and safety plan. '}
                                                Return and refresh this review
                                                once those records are ready.
                                                Creating the person does not
                                                start support.
                                            </AlertDescription>
                                        </Alert>
                                    )}
                                    <Field label="Canonical agreement" required>
                                        <SelectInput
                                            value={agreementId}
                                            ariaLabel="Canonical agreement"
                                            placeholder="Choose agreement"
                                            onChange={(value) => {
                                                setAgreementId(value);
                                                setMapping({});
                                            }}
                                            options={preview.agreements.map(
                                                (item) => ({
                                                    value: String(item.id),
                                                    label: `${item.title} · ${item.status}`,
                                                }),
                                            )}
                                        />
                                    </Field>
                                    {agreement && (
                                        <>
                                            <Button asChild variant="link">
                                                <a
                                                    href={`/operations/service-agreements/${agreement.id}`}
                                                    target="_blank"
                                                    rel="noreferrer"
                                                >
                                                    Review full agreement (opens
                                                    new tab)
                                                </a>
                                            </Button>
                                            <p className="text-subtle">
                                                {formatDateOnly(
                                                    agreement.starts_on,
                                                )}{' '}
                                                –{' '}
                                                {formatDateOnly(
                                                    agreement.ends_on,
                                                )}{' '}
                                                · NZD · GST{' '}
                                                {agreement.gst_inclusive
                                                    ? 'inclusive'
                                                    : 'exclusive'}
                                            </p>
                                            {preview.proposal?.lines.map(
                                                (line) => (
                                                    <Field
                                                        key={line.key}
                                                        label={`${line.description} · ${line.quantity ?? 'Unknown'} ${line.unit_label || line.unit} per ${line.period}`}
                                                        required
                                                    >
                                                        <SelectInput
                                                            value={
                                                                mapping[
                                                                    line.key
                                                                ] ?? ''
                                                            }
                                                            ariaLabel={`Agreement line for ${line.description}`}
                                                            placeholder="Map to an agreement line"
                                                            onChange={(value) =>
                                                                setMapping(
                                                                    (
                                                                        current,
                                                                    ) => ({
                                                                        ...current,
                                                                        [line.key]:
                                                                            value,
                                                                    }),
                                                                )
                                                            }
                                                            options={agreement.lines.map(
                                                                (item) => ({
                                                                    value: String(
                                                                        item.id,
                                                                    ),
                                                                    label: `${item.description} · NZD ${item.unit_price}/${item.unit}`,
                                                                }),
                                                            )}
                                                        />
                                                    </Field>
                                                ),
                                            )}
                                        </>
                                    )}
                                    <Field label="Current care plan" required>
                                        <SelectInput
                                            value={planId}
                                            ariaLabel="Current care plan"
                                            placeholder="Choose care plan"
                                            onChange={setPlanId}
                                            options={preview.care_plans.map(
                                                (item) => ({
                                                    value: String(item.id),
                                                    label: `${item.title} · Version ${item.version} · ${item.digest ? item.status : 'Agreement policy needs review'}`,
                                                    disabled: !item.digest,
                                                }),
                                            )}
                                        />
                                    </Field>
                                    {plan && (
                                        <Button asChild variant="link">
                                            <a
                                                href={`/operations/care-plans/${plan.id}`}
                                                target="_blank"
                                                rel="noreferrer"
                                            >
                                                Review full care plan (opens new
                                                tab)
                                            </a>
                                        </Button>
                                    )}
                                    {preview.more_options && (
                                        <p className="text-subtle">
                                            Recent records are shown. Review the
                                            person’s records if the required
                                            agreement, care plan or worker is
                                            missing.
                                        </p>
                                    )}
                                </>
                            )}
                            {scheduling && step === 1 && (
                                <>
                                    <Field
                                        label="Person or authorized signatory"
                                        required
                                    >
                                        <Input
                                            value={signatory}
                                            onChange={(event) =>
                                                setSignatory(event.target.value)
                                            }
                                            maxLength={200}
                                        />
                                    </Field>
                                    <ReferralDateTime
                                        id="service-agreement-signed"
                                        label="Agreement signed at"
                                        value={signedAt}
                                        onChange={setSignedAt}
                                    />
                                    <p className="text-subtle">
                                        Choose the evidence showing acceptance
                                        of these exact canonical agreement
                                        terms.
                                    </p>
                                    <EvidencePicker
                                        reference={reference}
                                        audience="funding"
                                        selected={evidence}
                                        onChange={setEvidence}
                                    />
                                    <Field
                                        label="Agreement and signature review"
                                        required
                                    >
                                        <Textarea
                                            value={agreementNote}
                                            onChange={(event) =>
                                                setAgreementNote(
                                                    event.target.value,
                                                )
                                            }
                                            maxLength={4000}
                                        />
                                    </Field>
                                    <Field
                                        label="Care, safety and consent review"
                                        required
                                    >
                                        <Textarea
                                            value={careNote}
                                            onChange={(event) =>
                                                setCareNote(event.target.value)
                                            }
                                            maxLength={4000}
                                        />
                                    </Field>
                                    <fieldset className="space-y-3">
                                        <legend className="mb-2 font-medium">
                                            Named assigned workers
                                        </legend>
                                        {preview.staff.length === 0 && (
                                            <p className="text-subtle">
                                                Assign eligible staff on the
                                                person’s record first.
                                            </p>
                                        )}
                                        {preview.staff.map((worker) => (
                                            <div
                                                key={worker.id}
                                                className="flex items-center gap-3"
                                            >
                                                <Checkbox
                                                    id={`start-worker-${worker.id}`}
                                                    checked={workers.includes(
                                                        worker.id,
                                                    )}
                                                    onCheckedChange={(
                                                        checked,
                                                    ) =>
                                                        setWorkers((current) =>
                                                            checked === true
                                                                ? [
                                                                      ...current,
                                                                      worker.id,
                                                                  ]
                                                                : current.filter(
                                                                      (id) =>
                                                                          id !==
                                                                          worker.id,
                                                                  ),
                                                        )
                                                    }
                                                />
                                                <Label
                                                    htmlFor={`start-worker-${worker.id}`}
                                                >
                                                    {worker.name}
                                                </Label>
                                            </div>
                                        ))}
                                    </fieldset>
                                    <Field
                                        label="Staffing and cover arrangements"
                                        required
                                    >
                                        <Textarea
                                            value={staffNote}
                                            onChange={(event) =>
                                                setStaffNote(event.target.value)
                                            }
                                            maxLength={4000}
                                        />
                                    </Field>
                                </>
                            )}
                            {scheduling && step === 2 && (
                                <>
                                    <ReviewCard
                                        title="Decision to approve"
                                        icon={ClipboardCheck}
                                    >
                                        <ReviewRow
                                            label="Person"
                                            value={preview.person.name}
                                        />
                                        <ReviewRow
                                            label="Agreed start"
                                            value={formatDateOnly(date)}
                                        />
                                        <ReviewRow
                                            label="Agreement"
                                            value={agreement?.title ?? ''}
                                        />
                                        <ReviewRow
                                            label="Care plan"
                                            value={plan?.title ?? ''}
                                        />
                                        <ReviewRow
                                            label="Named workers"
                                            value={preview.staff
                                                .filter((worker) =>
                                                    workers.includes(worker.id),
                                                )
                                                .map((worker) => worker.name)
                                                .join(', ')}
                                        />
                                        <ReviewRow
                                            label="Signature evidence"
                                            value={`${evidence.length} selected documents`}
                                        />
                                        <ReviewRow
                                            label="Signatory"
                                            value={signatory}
                                        />
                                        <ReviewRow
                                            label="Agreement signed at"
                                            value={formatDateTimeLong(signedAt)}
                                        />
                                        <ReviewRow
                                            label="Agreement review"
                                            value={agreementNote}
                                        />
                                        <ReviewRow
                                            label="Care and consent review"
                                            value={careNote}
                                        />
                                        <ReviewRow
                                            label="Staffing arrangements"
                                            value={staffNote}
                                        />
                                    </ReviewCard>
                                    <div className="flex items-start gap-3">
                                        <Checkbox
                                            id="start-current-terms"
                                            checked={acknowledged}
                                            onCheckedChange={(checked) => {
                                                setAcknowledged(
                                                    checked === true,
                                                );
                                                setReadiness(null);
                                            }}
                                        />
                                        <Label htmlFor="start-current-terms">
                                            I reviewed these exact agreement
                                            terms, signed evidence, current care
                                            and staffing. I am independent of
                                            the proposal and agreement author.
                                        </Label>
                                    </div>
                                    <div className="flex items-start gap-3">
                                        <Checkbox
                                            id="start-separate"
                                            checked={separate}
                                            onCheckedChange={(checked) => {
                                                setSeparate(checked === true);
                                                setReadiness(null);
                                            }}
                                        />
                                        <Label htmlFor="start-separate">
                                            This approves a date. Actual
                                            commencement must be recorded
                                            separately, with readiness checked
                                            again.
                                        </Label>
                                    </div>
                                    {readiness && (
                                        <Alert
                                            variant={
                                                readiness.ready
                                                    ? 'info'
                                                    : 'warning'
                                            }
                                        >
                                            <AlertDescription>
                                                {readiness.ready ? (
                                                    'Current readiness checks pass.'
                                                ) : (
                                                    <ul className="list-disc space-y-2 pl-5">
                                                        {readiness.issues.map(
                                                            (issue) => (
                                                                <li key={issue}>
                                                                    {issue}
                                                                </li>
                                                            ),
                                                        )}
                                                    </ul>
                                                )}
                                            </AlertDescription>
                                        </Alert>
                                    )}
                                    {readiness && (
                                        <ReviewCard
                                            title="Agreement capacity check"
                                            icon={FileCheck}
                                        >
                                            <ReviewRow
                                                label="Required budget"
                                                value={`${readiness.agreement_mapping.currency} ${readiness.agreement_mapping.required_budget} · GST ${readiness.agreement_mapping.budget_basis}`}
                                            />
                                            {readiness.agreement_mapping.mapping.map(
                                                (row) => (
                                                    <ReviewRow
                                                        key={row.line_key}
                                                        label={
                                                            preview.proposal?.lines.find(
                                                                (line) =>
                                                                    line.key ===
                                                                    row.line_key,
                                                            )?.description ??
                                                            row.line_key
                                                        }
                                                        value={`${row.quantity} ${row.agreement_unit} over ${row.periods} stated periods`}
                                                    />
                                                ),
                                            )}
                                            <p className="text-subtle">
                                                The check includes a full final
                                                partial period. It does not
                                                prorate, convert currency,
                                                consume a budget or create an
                                                invoice.
                                            </p>
                                        </ReviewCard>
                                    )}
                                    {readiness && !readiness.ready && (
                                        <Button
                                            variant="outline"
                                            onClick={() => void submit(true)}
                                        >
                                            Recheck readiness
                                        </Button>
                                    )}
                                </>
                            )}
                            {!scheduling && (
                                <>
                                    <ReviewCard
                                        title={preview.person.name}
                                        icon={CalendarCheck}
                                    >
                                        <ReviewRow
                                            label="Agreed start"
                                            value={formatDateOnly(
                                                preview.start?.agreed_date,
                                            )}
                                        />
                                    </ReviewCard>
                                    {operation === 'commence' && (
                                        <ReferralDateTime
                                            id="service-actual-start"
                                            label="Actual start"
                                            value={actual}
                                            onChange={setActual}
                                            includeSeconds
                                        />
                                    )}
                                    <Field
                                        label={
                                            operation === 'cancel'
                                                ? 'Reason for cancelling this start'
                                                : 'What happened at the actual start'
                                        }
                                        required
                                    >
                                        <Textarea
                                            value={note}
                                            onChange={(event) =>
                                                setNote(event.target.value)
                                            }
                                            maxLength={4000}
                                        />
                                    </Field>
                                    {operation === 'commence' && (
                                        <div className="flex items-start gap-3">
                                            <Checkbox
                                                id="start-actually-began"
                                                checked={acknowledged}
                                                onCheckedChange={(checked) =>
                                                    setAcknowledged(
                                                        checked === true,
                                                    )
                                                }
                                            />
                                            <Label htmlFor="start-actually-began">
                                                Support actually began at this
                                                time. This is not a planned
                                                start or an invoice.
                                            </Label>
                                        </div>
                                    )}
                                    {operation === 'cancel' && (
                                        <p className="text-subtle">
                                            This cancels the pending start and
                                            its confirmation follow-up. Existing
                                            active support and other funding
                                            work are preserved.
                                        </p>
                                    )}
                                </>
                            )}
                        </fieldset>
                    )}
                </div>
            </WizardShell>
            <ConfirmDialog
                open={discard}
                variant="destructive"
                onClose={() => setDiscard(false)}
                title={
                    uncertain
                        ? 'Leave an unconfirmed decision?'
                        : 'Close the start review?'
                }
                description={
                    uncertain
                        ? 'The decision may already have saved. Retry the same request to check its receipt, or reopen the service review to inspect its current status.'
                        : 'Unsaved entries will be discarded.'
                }
                confirmText="Close review"
                onConfirm={onClose}
            />
        </>
    );
}
