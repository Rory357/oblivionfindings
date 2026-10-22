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
} from '@/lib/datetime';
import { router, usePage } from '@inertiajs/react';
import axios from 'axios';
import {
    CalendarClock,
    ClipboardCheck,
    Plus,
    Trash2,
    Wallet,
} from 'lucide-react';
import { useEffect, useRef, useState, type ReactNode } from 'react';
import { ReferralDateTime } from './date-time';
import { EvidencePicker } from './evidence-picker';
import { FundingAllocation } from './funding-allocation';
import type { FundingCase, FundingLine } from './funding-types';
import {
    label,
    referralHref,
    type Options,
    type ReferralDetail,
} from './types';

export type FundingAction =
    | 'reassign'
    | 'prepare'
    | 'submission'
    | 'progress'
    | 'decision'
    | 'verification';
const titles: Record<FundingAction, string> = {
    reassign: 'Change navigator',
    prepare: 'Prepare funding assistance',
    submission: 'Record external submission',
    progress: 'Update funding progress',
    decision: 'Record external decision',
    verification: 'Independently verify funding',
};
const choices = (values: string[]) =>
    values.map((value) => ({ value, label: label(value) }));
const roleKeys = [
    'funding_body',
    'assessor',
    'host',
    'contracting_party',
    'invoice_payer',
];
function Tick({
    id,
    checked,
    onChange,
    children,
}: {
    id: string;
    checked: boolean;
    onChange: (value: boolean) => void;
    children: ReactNode;
}) {
    return (
        <div className="flex items-start gap-3">
            <Checkbox
                id={id}
                checked={checked}
                onCheckedChange={(value) => onChange(value === true)}
            />
            <Label htmlFor={id} className="flex items-center">
                {children}
            </Label>
        </div>
    );
}

export function FundingActionDialog({
    action,
    item,
    referral,
    options,
    onClose,
}: {
    action: FundingAction;
    item: FundingCase;
    referral: ReferralDetail;
    options: Options;
    onClose: () => void;
}) {
    const authority = referral.funding!.authority;
    const auth = usePage().props.auth as { user?: { id: number } } | undefined;
    const verificationConflict =
        action === 'verification' &&
        (auth?.user?.id === item.navigator_id ||
            auth?.user?.id === item.latest_decision?.recorded_by);
    const [step, setStep] = useState(0);
    const [version, setVersion] = useState(referral.version);
    const [caseVersion, setCaseVersion] = useState(item.version);
    const [navigator, setNavigator] = useState(String(item.navigator_id));
    const [staff, setStaff] = useState<{ id: number; name: string }[]>([]);
    const [query, setQuery] = useState('');
    const [staffError, setStaffError] = useState('');
    const [staffBusy, setStaffBusy] = useState(false);
    const [roles, setRoles] = useState<Record<string, string>>(
        Object.fromEntries(
            roleKeys.map((key) => [key, item.details.roles[key] ?? '']),
        ),
    );
    const [contact, setContact] = useState({
        name: item.details.external_contact?.name ?? '',
        email: item.details.external_contact?.email ?? '',
        phone: item.details.external_contact?.phone ?? '',
    });
    const [preparation, setPreparation] = useState(() => {
        const rows = [...item.details.preparation];
        item.details.guide.evidence_checklist.forEach((name) => {
            if (!rows.some((row) => row.item === name))
                rows.push({
                    item: name,
                    status: 'needed',
                    note: '',
                    evidence_ids: [],
                });
        });
        return rows;
    });
    const [blocker, setBlocker] = useState(item.details.blocker ?? '');
    const [status, setStatus] = useState(
        action === 'verification'
            ? 'needs_clarification'
            : item.status === 'decision_received'
              ? 'closed'
              : 'preparing',
    );
    const [reason, setReason] = useState('');
    const [personOutcome, setPersonOutcome] = useState('');
    const [cancelWork, setCancelWork] = useState(false);
    const [next, setNext] = useState(item.next_action ?? '');
    const [due, setDue] = useState(
        item.next_due_at && new Date(item.next_due_at).getTime() > Date.now()
            ? toDatetimeLocal(item.next_due_at)
            : toDatetimeLocal(Date.now() + 86400000),
    );
    const [evidence, setEvidence] = useState<number[]>([]);
    const [destination, setDestination] = useState('');
    const [channel, setChannel] = useState('agency_portal');
    const [submittedAt, setSubmittedAt] = useState(toDatetimeLocal(Date.now()));
    const [submittedBy, setSubmittedBy] = useState('person');
    const [externalReference, setExternalReference] = useState('');
    const [acknowledgement, setAcknowledgement] = useState('');
    // An agency acknowledgement may arrive after the submission; it is recorded once, later, through progress.
    const awaitingAcknowledgement =
        !!item.details.last_submission &&
        !item.details.last_submission.acknowledgement;
    const [personReviewed, setPersonReviewed] = useState(false);
    const [sharingIndex, setSharingIndex] = useState('');
    const [deviation, setDeviation] = useState('');
    const [outcome, setOutcome] = useState(
        item.pathway === 'private' ? 'private_confirmed' : 'approved',
    );
    const [decisionMaker, setDecisionMaker] = useState('');
    const [decisionDate, setDecisionDate] = useState('');
    const [existing, setExisting] = useState(false);
    const [conditions, setConditions] = useState('');
    const [validFrom, setValidFrom] = useState('');
    const [validUntil, setValidUntil] = useState('');
    const [reviewDate, setReviewDate] = useState('');
    const [deadlineSource, setDeadlineSource] = useState('');
    const newLine = (): FundingLine => ({
        service_id: item.personal_income ? null : item.details.service_id,
        quantity: null,
        provider_quantity: null,
        unit: 'money',
        unit_label: null,
        period: 'week',
        currency: 'NZD',
        restrictions: null,
    });
    const [lines, setLines] = useState<FundingLine[]>([newLine()]);
    const [resolved, setResolved] = useState(false);
    const [compatible, setCompatible] = useState(false);
    const [scopeNote, setScopeNote] = useState('');
    const [reviewDue, setReviewDue] = useState(
        toDatetimeLocal(Date.now() + 7 * 86400000),
    );
    const [busy, setBusy] = useState(false);
    const [saved, setSaved] = useState(false);
    const [error, setError] = useState('');
    const [conflict, setConflict] = useState(false);
    const [discard, setDiscard] = useState(false);
    const body = useRef<HTMLDivElement>(null);
    const pending = useRef(false);
    const reviewedDecisionId = useRef(item.latest_decision?.id);
    const terminal =
        action === 'progress' && ['withdrawn', 'closed'].includes(status);
    const steps = [
        {
            key: 'details',
            label: 'Details and evidence',
            blurb: titles[action],
            icon: Wallet,
        },
        {
            key: 'next',
            label: 'Next action',
            blurb: terminal
                ? 'Resolve outstanding assistance'
                : 'Keep a named follow-up',
            icon: CalendarClock,
        },
        {
            key: 'review',
            label: 'Review',
            blurb: 'Check the recorded outcome',
            icon: ClipboardCheck,
        },
    ];
    useEffect(() => {
        if (action !== 'reassign') return;
        const controller = new AbortController();
        const timer = window.setTimeout(() => {
            setStaffBusy(true);
            setStaffError('');
            axios
                .get<{ staff: { id: number; name: string }[] }>(
                    '/operations/referrals/staff-options',
                    {
                        params: {
                            reference: referral.reference,
                            site_id: referral.site_id ?? '',
                            purpose: 'funding',
                            audience: 'funding',
                            q: query,
                        },
                        signal: controller.signal,
                    },
                )
                .then((response) => setStaff(response.data.staff))
                .catch((caught) => {
                    if (!axios.isCancel(caught))
                        setStaffError(
                            'Eligible navigators could not be loaded. Try another search.',
                        );
                })
                .finally(() => {
                    if (!controller.signal.aborted) setStaffBusy(false);
                });
        }, 250);
        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [action, query, referral.reference, referral.site_id]);
    const go = (index: number) => {
        setStep(index);
        requestAnimationFrame(() => {
            body.current?.scrollTo({ top: 0 });
            body.current?.querySelector<HTMLElement>('[data-heading]')?.focus();
        });
    };
    function valid(index: number) {
        if (verificationConflict) {
            setError(
                'Ask a separate authorized colleague to verify this decision. You are its navigator or recorded the decision.',
            );
            return false;
        }
        let message = '';
        if (index === 0) {
            if (
                ['reassign', 'prepare', 'progress', 'decision'].includes(
                    action,
                ) &&
                reason.trim().length < 5
            )
                message =
                    'Record a reason with enough detail for the next colleague.';
            if (action === 'reassign' && !navigator)
                message = 'Choose an eligible navigator.';
            if (
                action === 'prepare' &&
                preparation.some(
                    (row) =>
                        !row.item.trim() ||
                        row.note.trim().length < 3 ||
                        (row.status === 'checked' && !row.evidence_ids.length),
                )
            )
                message =
                    'Give each preparation item a note, and link evidence for checked items.';
            if (action === 'progress' && personOutcome.trim().length < 5)
                message = 'Record the person’s wishes and outcome.';
            const shortAcknowledgement =
                acknowledgement.trim() !== '' &&
                acknowledgement.trim().length < 5;
            if (action === 'progress' && shortAcknowledgement)
                message =
                    'Give the acknowledgement at least 5 characters, or leave it blank until it arrives.';
            if (
                action === 'submission' &&
                (!destination.trim() ||
                    !submittedAt ||
                    shortAcknowledgement ||
                    !personReviewed ||
                    !evidence.length)
            )
                message =
                    'Record the actual submission details, the person’s review and source evidence. An acknowledgement is optional; if you add one, give it at least 5 characters.';
            if (
                action === 'decision' &&
                (!decisionMaker.trim() ||
                    !decisionDate ||
                    conditions.trim().length < 3 ||
                    !evidence.length ||
                    (outcome !== 'declined' && !lines.length))
            )
                message =
                    'Record the external decision details and evidence, including any awarded support lines.';
            if (
                action === 'verification' &&
                (!item.latest_decision ||
                    scopeNote.trim().length < 5 ||
                    !evidence.length ||
                    !reviewDue)
            )
                message =
                    'Record what was checked, link supporting evidence and choose the next verification review date.';
        } else if (index === 1 && !terminal && (!next.trim() || !due))
            message = 'Record the next action and due date.';
        if (message) {
            setError(message);
            return false;
        }
        return true;
    }
    function payload() {
        const common = {
            version,
            case_version: caseVersion,
            next_action: terminal ? null : next,
            next_due_at: terminal ? null : due,
        };
        if (action === 'reassign')
            return { ...common, navigator_id: Number(navigator), reason };
        if (action === 'prepare')
            return {
                ...common,
                navigator_id: item.navigator_id,
                roles: Object.fromEntries(
                    Object.entries(roles).map(([key, value]) => [
                        key,
                        value || null,
                    ]),
                ),
                external_contact: Object.fromEntries(
                    Object.entries(contact).map(([key, value]) => [
                        key,
                        value || null,
                    ]),
                ),
                preparation,
                blocker: blocker || null,
                reason,
            };
        if (action === 'progress')
            return {
                ...common,
                status,
                reason,
                person_outcome: personOutcome,
                cancel_open_work: cancelWork,
                ...(awaitingAcknowledgement && acknowledgement.trim()
                    ? { acknowledgement: acknowledgement.trim() }
                    : {}),
            };
        if (action === 'submission')
            return {
                ...common,
                destination,
                channel,
                submitted_at: submittedAt,
                submitted_by: submittedBy,
                external_reference: externalReference || null,
                acknowledgement: acknowledgement.trim() || null,
                person_reviewed: personReviewed,
                evidence_ids: evidence,
                authority_version: authority.version,
                sharing_index:
                    sharingIndex === '' ? null : Number(sharingIndex),
                authority_deviation: deviation || null,
            };
        if (action === 'decision')
            return {
                ...common,
                outcome,
                decision_maker: decisionMaker,
                external_reference: externalReference.trim() || null,
                decision_date: decisionDate,
                evidence_ids: evidence,
                existing_authorization: existing,
                conditions,
                valid_from: validFrom || null,
                valid_until: validUntil || null,
                review_date: reviewDate || null,
                deadline_source: deadlineSource || null,
                lines: outcome === 'declined' ? [] : lines,
                reason,
            };
        return {
            ...common,
            decision_id: item.latest_decision?.id,
            status,
            conditions_resolved: resolved,
            provider_compatible: compatible,
            scope_note: scopeNote,
            evidence_ids: evidence,
            review_due_at: reviewDue,
        };
    }
    async function save() {
        if (pending.current || conflict) return;
        for (const index of [0, 1])
            if (!valid(index)) {
                go(index);
                return;
            }
        pending.current = true;
        setBusy(true);
        setError('');
        try {
            const response = await axios.post<{ saved: boolean }>(
                `${referralHref(referral.reference)}/funding/${item.id}/${action}`,
                payload(),
            );
            if (!response.data.saved) throw new Error('Save not confirmed');
            setSaved(true);
            router.reload({ only: ['referral'], preserveScroll: true });
        } catch (caught) {
            const response = axios.isAxiosError(caught)
                ? caught.response
                : undefined;
            setConflict(!response || response.status === 409);
            setError(
                response?.data?.errors
                    ? Object.values(
                          response.data.errors as Record<string, string[]>,
                      )
                          .flat()
                          .join(' ')
                    : (response?.data?.message ??
                          'Save was not confirmed. Review the latest case before trying again.'),
            );
        } finally {
            pending.current = false;
            setBusy(false);
        }
    }
    function refresh() {
        router.reload({
            only: ['referral'],
            preserveScroll: true,
            onSuccess: (page) => {
                const latest = page.props.referral as ReferralDetail;
                const current = latest.funding?.cases.data.find(
                    (entry) => entry.id === item.id,
                );
                if (!current) {
                    setError(
                        'This case is no longer on the current page or access changed. Close this draft and reopen the current case.',
                    );
                    return;
                }
                if (
                    action === 'verification' &&
                    current.latest_decision?.id !== reviewedDecisionId.current
                ) {
                    setError(
                        'A different external decision is now current. Close this draft and begin a new verification so the new terms are checked independently.',
                    );
                    return;
                }
                setVersion(latest.version);
                setCaseVersion(current.version);
                setConflict(false);
                setError(
                    'Latest case loaded. Compare its current status and decision with this draft before saving. If the action already appears in history, close the draft.',
                );
                go(2);
            },
        });
    }
    const text = (
        name: string,
        value: string,
        change: (value: string) => void,
        multiline = false,
        maxLength = 2000,
    ) => (
        <Field label={name}>
            {multiline ? (
                <Textarea
                    value={value}
                    onChange={(event) => change(event.target.value)}
                    maxLength={maxLength}
                />
            ) : (
                <Input
                    value={value}
                    onChange={(event) => change(event.target.value)}
                    maxLength={maxLength}
                />
            )}
        </Field>
    );
    const evidenceField = (
        <Field label="Supporting documents">
            <EvidencePicker
                reference={referral.reference}
                audience="funding"
                selected={evidence}
                onChange={setEvidence}
                allowQuarantined={action === 'decision'}
            />
        </Field>
    );
    const setLine = (index: number, values: Partial<FundingLine>) =>
        setLines((current) =>
            current.map((line, position) =>
                position === index ? { ...line, ...values } : line,
            ),
        );
    return (
        <>
            <WizardShell
                open
                title={titles[action]}
                description={`${item.pathway_label} · ${referral.name}`}
                railTitle="Funding assistance"
                railIcon={Wallet}
                railSub="External funding decisions, internal verification and service commencement are separate steps."
                steps={steps}
                stepIndex={step}
                onStepClick={(index) => {
                    if (!busy) go(index);
                }}
                bodyRef={body}
                onClose={() => {
                    if (!busy) {
                        if (saved) onClose();
                        else setDiscard(true);
                    }
                }}
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
                            disabled={verificationConflict}
                            onClick={() => {
                                if (valid(step)) {
                                    setError('');
                                    go(step + 1);
                                }
                            }}
                        >
                            Continue
                        </Button>
                    ) : (
                        <Button
                            disabled={busy || conflict || verificationConflict}
                            onClick={() => void save()}
                        >
                            {busy ? 'Saving…' : 'Save funding update'}
                        </Button>
                    )
                }
                success={
                    saved ? (
                        <WizardSuccessPane
                            title="Funding update saved"
                            blurb={
                                action === 'submission'
                                    ? 'The external submission and next action are recorded. No application or message was sent by this screen.'
                                    : action === 'decision'
                                      ? 'The external decision is preserved as a new version. An independent reviewer must verify it before service funding can be considered.'
                                      : 'The case history and follow-up have been updated. This does not start support or create a billable service.'
                            }
                            actions={
                                <Button onClick={onClose}>
                                    View funding case
                                </Button>
                            }
                        />
                    ) : undefined
                }
            >
                <div className="space-y-5">
                    <h2
                        className="text-section-title"
                        data-heading
                        tabIndex={-1}
                    >
                        {steps[step].label}
                    </h2>
                    {error && (
                        <Alert variant="warning">
                            <AlertDescription>
                                {error}
                                {conflict && (
                                    <Button variant="outline" onClick={refresh}>
                                        Reload and keep draft
                                    </Button>
                                )}
                            </AlertDescription>
                        </Alert>
                    )}
                    {step === 0 && (
                        <>
                            {action === 'reassign' && (
                                <>
                                    <Alert variant="info">
                                        <AlertDescription>
                                            Changing the navigator restores
                                            accountability. It does not renew
                                            consent. Their open funding
                                            follow-ups transfer with the case;
                                            completed work keeps its original
                                            history.
                                        </AlertDescription>
                                    </Alert>
                                    {text(
                                        'Find eligible navigator',
                                        query,
                                        setQuery,
                                        false,
                                        100,
                                    )}
                                    {staffError && (
                                        <p
                                            role="alert"
                                            className="text-destructive"
                                        >
                                            {staffError}
                                        </p>
                                    )}
                                    <Field label="Navigator">
                                        <SelectInput
                                            value={navigator}
                                            onChange={setNavigator}
                                            placeholder={
                                                staffBusy
                                                    ? 'Loading eligible navigators…'
                                                    : 'Choose navigator'
                                            }
                                            options={[
                                                ...(!staff.some(
                                                    (person) =>
                                                        String(person.id) ===
                                                        navigator,
                                                ) && navigator
                                                    ? [
                                                          {
                                                              value: navigator,
                                                              label:
                                                                  item.navigator_name ??
                                                                  'Previous navigator — choose an eligible colleague',
                                                              disabled: true,
                                                          },
                                                      ]
                                                    : []),
                                                ...staff.map((person) => ({
                                                    value: String(person.id),
                                                    label: person.name,
                                                })),
                                            ]}
                                        />
                                    </Field>
                                </>
                            )}
                            {action === 'prepare' && (
                                <>
                                    <p className="text-subtle">
                                        Navigator:{' '}
                                        {item.navigator_name ??
                                            'Needs reassignment'}
                                        . Use Change navigator to transfer
                                        accountability.
                                    </p>
                                    <div className="grid gap-4 sm:grid-cols-2">
                                        {roleKeys.map((key) => (
                                            <div key={key}>
                                                {text(
                                                    label(key),
                                                    roles[key],
                                                    (value) =>
                                                        setRoles({
                                                            ...roles,
                                                            [key]: value,
                                                        }),
                                                    false,
                                                    300,
                                                )}
                                            </div>
                                        ))}
                                    </div>
                                    <h3 className="text-section-title">
                                        External contact
                                    </h3>
                                    {(['name', 'email', 'phone'] as const).map(
                                        (key) => (
                                            <div key={key}>
                                                {text(
                                                    label(key),
                                                    contact[key],
                                                    (value) =>
                                                        setContact({
                                                            ...contact,
                                                            [key]: value,
                                                        }),
                                                    false,
                                                    key === 'phone' ? 50 : 160,
                                                )}
                                            </div>
                                        ),
                                    )}
                                    <h3 className="text-section-title">
                                        Preparation checklist
                                    </h3>
                                    {preparation.length === 0 && (
                                        <p className="text-subtle">
                                            No checklist was configured for this
                                            pathway version. Confirm the
                                            agency’s current evidence
                                            requirements and record outstanding
                                            items below before preparing a
                                            submission.
                                        </p>
                                    )}
                                    {preparation.map((row, index) => (
                                        <div
                                            key={index}
                                            className="space-y-4 rounded-lg bg-muted p-4"
                                        >
                                            <p className="font-medium">
                                                {row.item}
                                            </p>
                                            <Field label="Progress">
                                                <SelectInput
                                                    value={row.status}
                                                    placeholder="Choose progress"
                                                    options={choices([
                                                        'needed',
                                                        'requested',
                                                        'received',
                                                        'checked',
                                                        'not_applicable',
                                                    ])}
                                                    onChange={(value) =>
                                                        setPreparation(
                                                            (current) =>
                                                                current.map(
                                                                    (
                                                                        entry,
                                                                        position,
                                                                    ) =>
                                                                        position ===
                                                                        index
                                                                            ? {
                                                                                  ...entry,
                                                                                  status: value,
                                                                              }
                                                                            : entry,
                                                                ),
                                                        )
                                                    }
                                                />
                                            </Field>
                                            {text(
                                                row.status === 'not_applicable'
                                                    ? 'Why this item is not needed'
                                                    : 'Evidence owner, request/due dates and preparation note',
                                                row.note,
                                                (value) =>
                                                    setPreparation((current) =>
                                                        current.map(
                                                            (
                                                                entry,
                                                                position,
                                                            ) =>
                                                                position ===
                                                                index
                                                                    ? {
                                                                          ...entry,
                                                                          note: value,
                                                                      }
                                                                    : entry,
                                                        ),
                                                    ),
                                                true,
                                                1000,
                                            )}
                                            <EvidencePicker
                                                reference={referral.reference}
                                                audience="funding"
                                                selected={row.evidence_ids}
                                                onChange={(ids) =>
                                                    setPreparation((current) =>
                                                        current.map(
                                                            (
                                                                entry,
                                                                position,
                                                            ) =>
                                                                position ===
                                                                index
                                                                    ? {
                                                                          ...entry,
                                                                          evidence_ids:
                                                                              ids,
                                                                      }
                                                                    : entry,
                                                        ),
                                                    )
                                                }
                                            />
                                        </div>
                                    ))}
                                    {text(
                                        'What is blocking progress?',
                                        blocker,
                                        setBlocker,
                                        true,
                                        1000,
                                    )}
                                </>
                            )}
                            {action === 'progress' && (
                                <>
                                    <Field label="Progress">
                                        <SelectInput
                                            value={status}
                                            onChange={setStatus}
                                            placeholder="Choose progress"
                                            options={choices(
                                                item.status ===
                                                    'decision_received'
                                                    ? ['closed', 'withdrawn']
                                                    : [
                                                          'preparing',
                                                          'awaiting_assessment',
                                                          'awaiting_decision',
                                                          'withdrawn',
                                                      ],
                                            )}
                                        />
                                    </Field>
                                    {text(
                                        'Person’s wishes and outcome',
                                        personOutcome,
                                        setPersonOutcome,
                                        true,
                                    )}
                                    {awaitingAcknowledgement &&
                                        text(
                                            'Agency acknowledgement (optional, if it has arrived)',
                                            acknowledgement,
                                            setAcknowledgement,
                                            true,
                                        )}
                                    <p className="text-subtle">
                                        Completing or withdrawing this
                                        assistance case does not close the
                                        referral. A funding decline can lead to
                                        a review or another pathway.
                                    </p>
                                </>
                            )}
                            {action === 'submission' && (
                                <>
                                    <Alert variant="info">
                                        <AlertDescription>
                                            Record an application already
                                            submitted externally. Review the
                                            person’s information together before
                                            submission. This screen sends
                                            nothing to an agency.
                                        </AlertDescription>
                                    </Alert>
                                    {text(
                                        'Actual destination / agency',
                                        destination,
                                        setDestination,
                                        false,
                                        300,
                                    )}
                                    <Field label="Submitted by">
                                        <SelectInput
                                            value={submittedBy}
                                            onChange={setSubmittedBy}
                                            placeholder="Choose who submitted"
                                            options={choices([
                                                'person',
                                                'staff',
                                                'other_professional',
                                            ])}
                                        />
                                    </Field>
                                    <Field label="Channel">
                                        <SelectInput
                                            value={channel}
                                            onChange={setChannel}
                                            placeholder="Choose channel"
                                            options={choices([
                                                'agency_portal',
                                                'email',
                                                'post',
                                                'in_person',
                                                'phone',
                                            ])}
                                        />
                                    </Field>
                                    <ReferralDateTime
                                        id="funding-submitted"
                                        label="Actual submission date and time"
                                        value={submittedAt}
                                        onChange={setSubmittedAt}
                                    />
                                    {text(
                                        'External reference (if supplied)',
                                        externalReference,
                                        setExternalReference,
                                        false,
                                        160,
                                    )}
                                    {text(
                                        'Agency acknowledgement (optional, if already received)',
                                        acknowledgement,
                                        setAcknowledgement,
                                        true,
                                    )}
                                    <p className="text-subtle">
                                        Submitted and acknowledged are recorded
                                        separately. If the agency replies later,
                                        record it through Update funding
                                        progress.
                                    </p>
                                    <Tick
                                        id="funding-person-reviewed"
                                        checked={personReviewed}
                                        onChange={setPersonReviewed}
                                    >
                                        The person reviewed the information
                                        before it was submitted
                                    </Tick>
                                    {submittedBy === 'staff' && (
                                        <Field label="Current sharing permission">
                                            <SelectInput
                                                value={sharingIndex}
                                                onChange={setSharingIndex}
                                                placeholder="Choose permission matching the recipient"
                                                options={authority.sharing.map(
                                                    (permission) => ({
                                                        value: String(
                                                            permission.index,
                                                        ),
                                                        label: `${permission.recipient} · ${permission.purpose}`,
                                                    }),
                                                )}
                                            />
                                        </Field>
                                    )}
                                    {text(
                                        'Actual authority deviation, if one occurred',
                                        deviation,
                                        setDeviation,
                                        true,
                                    )}
                                    <p className="text-subtle">
                                        A recorded deviation documents what
                                        happened; it does not grant permission
                                        for future disclosure.
                                    </p>
                                    {evidenceField}
                                </>
                            )}
                            {action === 'decision' && (
                                <>
                                    <Field label="External outcome">
                                        <SelectInput
                                            value={outcome}
                                            onChange={setOutcome}
                                            placeholder="Choose outcome"
                                            options={choices(
                                                item.pathway === 'private'
                                                    ? [
                                                          'private_confirmed',
                                                          'declined',
                                                      ]
                                                    : [
                                                          'approved',
                                                          'partially_approved',
                                                          'conditional',
                                                          'declined',
                                                      ],
                                            )}
                                        />
                                    </Field>
                                    {text(
                                        'Decision-maker / agency',
                                        decisionMaker,
                                        setDecisionMaker,
                                        false,
                                        300,
                                    )}
                                    {text(
                                        'External decision reference (if supplied)',
                                        externalReference,
                                        setExternalReference,
                                        false,
                                        160,
                                    )}
                                    <Field label="Decision date">
                                        <DatePicker
                                            id="funding-decision-date"
                                            label="Decision date"
                                            value={decisionDate}
                                            onChange={setDecisionDate}
                                        />
                                    </Field>
                                    <Tick
                                        id="funding-existing-award"
                                        checked={existing}
                                        onChange={setExisting}
                                    >
                                        This is an existing authorization
                                        supplied to us, rather than a decision
                                        following our recorded submission
                                    </Tick>
                                    {text(
                                        'Conditions and restrictions, including if none are stated',
                                        conditions,
                                        setConditions,
                                        true,
                                        4000,
                                    )}
                                    <div className="grid gap-4 sm:grid-cols-2">
                                        <Field label="Valid from (leave unknown dates blank)">
                                            <DatePicker
                                                id="funding-valid-from"
                                                label="Valid from"
                                                allowClear
                                                value={validFrom}
                                                onChange={setValidFrom}
                                            />
                                        </Field>
                                        <Field label="Valid until">
                                            <DatePicker
                                                id="funding-valid-until"
                                                label="Valid until"
                                                allowClear
                                                value={validUntil}
                                                onChange={setValidUntil}
                                            />
                                        </Field>
                                        <Field label="Review / renewal date">
                                            <DatePicker
                                                id="funding-review-date"
                                                label="Review date"
                                                allowClear
                                                value={reviewDate}
                                                onChange={setReviewDate}
                                            />
                                        </Field>
                                    </div>
                                    {text(
                                        'Source of any review deadline',
                                        deadlineSource,
                                        setDeadlineSource,
                                        true,
                                        1000,
                                    )}
                                    {outcome !== 'declined' && (
                                        <>
                                            <h3 className="text-section-title">
                                                Original authorization lines
                                            </h3>
                                            <p className="text-subtle">
                                                Keep the awarded units and
                                                period. Leave unknown quantities
                                                blank; no conversion to money is
                                                inferred.
                                            </p>
                                            {lines.map((line, index) => (
                                                <div
                                                    key={index}
                                                    className="space-y-4 rounded-lg bg-muted p-4"
                                                >
                                                    <div className="flex items-center justify-between">
                                                        <h4 className="font-medium">
                                                            Line {index + 1}
                                                        </h4>
                                                        <Button
                                                            variant="ghost"
                                                            aria-label={`Remove funding line ${index + 1}`}
                                                            onClick={() =>
                                                                setLines(
                                                                    (current) =>
                                                                        current.filter(
                                                                            (
                                                                                _,
                                                                                position,
                                                                            ) =>
                                                                                position !==
                                                                                index,
                                                                        ),
                                                                )
                                                            }
                                                        >
                                                            <Trash2 className="size-4" />
                                                        </Button>
                                                    </div>
                                                    {!item.personal_income && (
                                                        <Field label="Authorized service">
                                                            <SelectInput
                                                                value={
                                                                    line.service_id ??
                                                                    ''
                                                                }
                                                                onChange={(
                                                                    value,
                                                                ) =>
                                                                    setLine(
                                                                        index,
                                                                        {
                                                                            service_id:
                                                                                value,
                                                                        },
                                                                    )
                                                                }
                                                                placeholder="Choose service"
                                                                options={item.details.guide.service_ids.map(
                                                                    (
                                                                        value,
                                                                    ) => ({
                                                                        value,
                                                                        label:
                                                                            options.services.find(
                                                                                (
                                                                                    service,
                                                                                ) =>
                                                                                    service.id ===
                                                                                    value,
                                                                            )
                                                                                ?.label ??
                                                                            label(
                                                                                value,
                                                                            ),
                                                                    }),
                                                                )}
                                                            />
                                                        </Field>
                                                    )}
                                                    <div className="grid gap-4 sm:grid-cols-2">
                                                        {text(
                                                            'Original award quantity',
                                                            String(
                                                                line.quantity ??
                                                                    '',
                                                            ),
                                                            (value) =>
                                                                setLine(index, {
                                                                    quantity:
                                                                        value ||
                                                                        null,
                                                                }),
                                                            false,
                                                            15,
                                                        )}
                                                        {!item.personal_income &&
                                                            text(
                                                                'Quantity allocated to this provider',
                                                                String(
                                                                    line.provider_quantity ??
                                                                        '',
                                                                ),
                                                                (value) =>
                                                                    setLine(
                                                                        index,
                                                                        {
                                                                            provider_quantity:
                                                                                value ||
                                                                                null,
                                                                        },
                                                                    ),
                                                                false,
                                                                15,
                                                            )}
                                                        <Field label="Original unit">
                                                            <SelectInput
                                                                value={
                                                                    line.unit
                                                                }
                                                                onChange={(
                                                                    value,
                                                                ) =>
                                                                    setLine(
                                                                        index,
                                                                        {
                                                                            unit: value,
                                                                        },
                                                                    )
                                                                }
                                                                placeholder="Choose unit"
                                                                options={choices(
                                                                    [
                                                                        'money',
                                                                        'hours',
                                                                        'days',
                                                                        'visits',
                                                                        'other',
                                                                    ],
                                                                )}
                                                            />
                                                        </Field>
                                                        <Field label="Period">
                                                            <SelectInput
                                                                value={
                                                                    line.period
                                                                }
                                                                onChange={(
                                                                    value,
                                                                ) =>
                                                                    setLine(
                                                                        index,
                                                                        {
                                                                            period: value,
                                                                        },
                                                                    )
                                                                }
                                                                placeholder="Choose period"
                                                                options={choices(
                                                                    [
                                                                        'week',
                                                                        'fortnight',
                                                                        'month',
                                                                        'year',
                                                                        'total',
                                                                    ],
                                                                )}
                                                            />
                                                        </Field>
                                                        {line.unit ===
                                                            'money' &&
                                                            text(
                                                                'Currency code',
                                                                line.currency,
                                                                (value) =>
                                                                    setLine(
                                                                        index,
                                                                        {
                                                                            currency:
                                                                                value.toUpperCase(),
                                                                        },
                                                                    ),
                                                                false,
                                                                3,
                                                            )}
                                                        {line.unit ===
                                                            'other' &&
                                                            text(
                                                                'Unit as written in the authorization',
                                                                line.unit_label ??
                                                                    '',
                                                                (value) =>
                                                                    setLine(
                                                                        index,
                                                                        {
                                                                            unit_label:
                                                                                value ||
                                                                                null,
                                                                        },
                                                                    ),
                                                                false,
                                                                80,
                                                            )}
                                                    </div>
                                                    {text(
                                                        'Line restrictions',
                                                        line.restrictions ?? '',
                                                        (value) =>
                                                            setLine(index, {
                                                                restrictions:
                                                                    value ||
                                                                    null,
                                                            }),
                                                        true,
                                                    )}
                                                </div>
                                            ))}
                                            <Button
                                                variant="outline"
                                                disabled={lines.length >= 30}
                                                onClick={() =>
                                                    setLines((current) => [
                                                        ...current,
                                                        newLine(),
                                                    ])
                                                }
                                            >
                                                <Plus className="size-4" />
                                                Add authorization line
                                            </Button>
                                        </>
                                    )}
                                    {evidenceField}
                                </>
                            )}
                            {action === 'verification' && (
                                <>
                                    <Alert variant="info">
                                        <AlertDescription>
                                            A separate authorized colleague must
                                            verify the latest decision. The
                                            navigator and person who recorded it
                                            cannot verify it.
                                            {verificationConflict &&
                                                ' You are its navigator or recorded this decision. Close this review and ask a separate authorized colleague to complete it.'}
                                        </AlertDescription>
                                    </Alert>
                                    <ReviewCard
                                        icon={Wallet}
                                        title="Current external decision"
                                    >
                                        <ReviewRow
                                            label="Decision version"
                                            value={String(
                                                item.latest_decision?.version ??
                                                    'None',
                                            )}
                                        />
                                        <ReviewRow
                                            label="Outcome"
                                            value={label(
                                                item.latest_decision?.outcome ??
                                                    'none',
                                            )}
                                        />
                                        <ReviewRow
                                            label="External reference"
                                            value={
                                                item.latest_decision?.terms
                                                    .external_reference ??
                                                'None'
                                            }
                                        />
                                        <ReviewRow
                                            label="Conditions"
                                            value={
                                                item.latest_decision?.terms
                                                    .conditions ?? 'None'
                                            }
                                        />
                                    </ReviewCard>
                                    <Field label="Verification result">
                                        <SelectInput
                                            value={status}
                                            onChange={setStatus}
                                            placeholder="Choose result"
                                            options={choices([
                                                'needs_clarification',
                                                'verified',
                                            ])}
                                        />
                                    </Field>
                                    {item.latest_decision?.terms.lines.map(
                                        (line, index) => (
                                            <FundingAllocation
                                                key={index}
                                                line={line}
                                                options={options}
                                            />
                                        ),
                                    )}
                                    <p className="text-subtle">
                                        Authorization dates:{' '}
                                        {formatDateOnly(
                                            item.latest_decision?.terms
                                                .valid_from,
                                            'Unknown',
                                        )}{' '}
                                        to{' '}
                                        {formatDateOnly(
                                            item.latest_decision?.terms
                                                .valid_until,
                                            'Unknown',
                                        )}
                                        . Check these against the source
                                        documents.
                                    </p>
                                    <Tick
                                        id="funding-conditions-resolved"
                                        checked={resolved}
                                        onChange={setResolved}
                                    >
                                        Conditions have been checked and
                                        resolved
                                    </Tick>
                                    <Tick
                                        id="funding-provider-compatible"
                                        checked={compatible}
                                        onChange={setCompatible}
                                    >
                                        Provider, payer and purchasing scope are
                                        compatible with the proposed support
                                    </Tick>
                                    {text(
                                        'What was checked and what remains unclear',
                                        scopeNote,
                                        setScopeNote,
                                        true,
                                        4000,
                                    )}
                                    <ReferralDateTime
                                        id="funding-verification-review"
                                        label="Verification review due"
                                        value={reviewDue}
                                        onChange={setReviewDue}
                                    />
                                    {evidenceField}
                                </>
                            )}
                            {[
                                'reassign',
                                'prepare',
                                'progress',
                                'decision',
                            ].includes(action) &&
                                text(
                                    'Reason for this update',
                                    reason,
                                    setReason,
                                    true,
                                )}
                        </>
                    )}
                    {step === 1 &&
                        (terminal ? (
                            <>
                                <p className="text-subtle">
                                    Resolve the open funding follow-ups before
                                    ending assistance, or explicitly cancel them
                                    here. Other referral work remains open.
                                </p>
                                <Tick
                                    id="funding-cancel-work"
                                    checked={cancelWork}
                                    onChange={setCancelWork}
                                >
                                    Cancel outstanding funding follow-ups with
                                    the recorded reason (requires
                                    task-management permission)
                                </Tick>
                            </>
                        ) : (
                            <>
                                {text(
                                    'Next funding action',
                                    next,
                                    setNext,
                                    false,
                                    500,
                                )}
                                <ReferralDateTime
                                    id="funding-next-due"
                                    label="Next action due"
                                    value={due}
                                    onChange={setDue}
                                />
                                <p className="text-subtle">
                                    Owner:{' '}
                                    {action === 'reassign'
                                        ? (staff.find(
                                              (person) =>
                                                  String(person.id) ===
                                                  navigator,
                                          )?.name ?? item.navigator_name)
                                        : item.navigator_name}
                                </p>
                            </>
                        ))}
                    {step === 2 && (
                        <>
                            <ReviewCard
                                icon={ClipboardCheck}
                                title="Funding update"
                            >
                                <ReviewRow
                                    label="Action"
                                    value={titles[action]}
                                />
                                <ReviewRow
                                    label="Pathway"
                                    value={item.pathway_label}
                                />
                                <ReviewRow
                                    label="Current case status"
                                    value={label(item.status)}
                                />
                                {reason && (
                                    <ReviewRow label="Reason" value={reason} />
                                )}
                                {['progress', 'verification'].includes(
                                    action,
                                ) && (
                                    <ReviewRow
                                        label="New result"
                                        value={label(status)}
                                    />
                                )}
                                {action === 'progress' &&
                                    awaitingAcknowledgement &&
                                    acknowledgement.trim() && (
                                        <ReviewRow
                                            label="Agency acknowledgement"
                                            value={acknowledgement.trim()}
                                        />
                                    )}
                                {action === 'decision' && (
                                    <>
                                        <ReviewRow
                                            label="External outcome"
                                            value={label(outcome)}
                                        />
                                        <ReviewRow
                                            label="Reference"
                                            value={
                                                externalReference.trim() ||
                                                'None supplied'
                                            }
                                        />
                                        <ReviewRow
                                            label="Decision-maker / agency"
                                            value={decisionMaker}
                                        />
                                        <ReviewRow
                                            label="Decision date"
                                            value={formatDateOnly(decisionDate)}
                                        />
                                        <ReviewRow
                                            label="Authorization source"
                                            value={
                                                existing
                                                    ? 'Existing authorization supplied to us'
                                                    : 'Decision following a recorded submission'
                                            }
                                        />
                                        <ReviewRow
                                            label="Conditions and restrictions"
                                            value={conditions || 'Not recorded'}
                                        />
                                        <ReviewRow
                                            label="Valid from"
                                            value={formatDateOnly(
                                                validFrom,
                                                'Unknown',
                                            )}
                                        />
                                        <ReviewRow
                                            label="Valid until"
                                            value={formatDateOnly(
                                                validUntil,
                                                'Unknown',
                                            )}
                                        />
                                        <ReviewRow
                                            label="Review / renewal date"
                                            value={formatDateOnly(
                                                reviewDate,
                                                'Not recorded',
                                            )}
                                        />
                                        <ReviewRow
                                            label="Review deadline source"
                                            value={
                                                deadlineSource || 'Not recorded'
                                            }
                                        />
                                        <ReviewRow
                                            label="Support lines"
                                            value={String(
                                                outcome === 'declined'
                                                    ? 0
                                                    : lines.length,
                                            )}
                                        />
                                    </>
                                )}
                                {action === 'submission' && (
                                    <>
                                        <ReviewRow
                                            label="Destination"
                                            value={destination}
                                        />
                                        <ReviewRow
                                            label="Submitted by"
                                            value={label(submittedBy)}
                                        />
                                        <ReviewRow
                                            label="Channel"
                                            value={label(channel)}
                                        />
                                        <ReviewRow
                                            label="Actual submission time"
                                            value={formatDateTimeLong(
                                                submittedAt,
                                            )}
                                        />
                                        <ReviewRow
                                            label="External reference"
                                            value={
                                                externalReference ||
                                                'Not supplied'
                                            }
                                        />
                                        <ReviewRow
                                            label="Agency acknowledgement"
                                            value={
                                                acknowledgement.trim() ||
                                                'Not yet received'
                                            }
                                        />
                                        <ReviewRow
                                            label="Person reviewed the information"
                                            value={
                                                personReviewed ? 'Yes' : 'No'
                                            }
                                        />
                                        <ReviewRow
                                            label="Recorded authority deviation"
                                            value={deviation || 'None recorded'}
                                        />
                                    </>
                                )}
                                {[
                                    'decision',
                                    'submission',
                                    'verification',
                                ].includes(action) && (
                                    <ReviewRow
                                        label="Linked documents"
                                        value={String(evidence.length)}
                                    />
                                )}
                                <ReviewRow
                                    label="Next action"
                                    value={
                                        terminal
                                            ? cancelWork
                                                ? 'Cancel outstanding funding work'
                                                : 'Existing work must be resolved'
                                            : next
                                    }
                                />
                                <ReviewRow
                                    label="Due"
                                    value={
                                        terminal
                                            ? 'Assistance ending'
                                            : due
                                              ? formatDateTimeLong(due)
                                              : 'Choose date'
                                    }
                                />
                            </ReviewCard>
                            {action === 'decision' &&
                                outcome !== 'declined' &&
                                lines.map((line, index) => (
                                    <FundingAllocation
                                        key={index}
                                        line={line}
                                        options={options}
                                    />
                                ))}
                            {action === 'verification' && (
                                <ReviewCard
                                    title="Verification checks"
                                    icon={ClipboardCheck}
                                >
                                    <ReviewRow
                                        label="Conditions resolved"
                                        value={resolved ? 'Yes' : 'No'}
                                    />
                                    <ReviewRow
                                        label="Provider and payer scope compatible"
                                        value={compatible ? 'Yes' : 'No'}
                                    />
                                    <ReviewRow
                                        label="Review notes"
                                        value={scopeNote}
                                    />
                                    <ReviewRow
                                        label="Review due"
                                        value={
                                            reviewDue
                                                ? formatDateTimeLong(reviewDue)
                                                : 'Not recorded'
                                        }
                                    />
                                </ReviewCard>
                            )}
                            {action === 'prepare' && (
                                <ReviewCard
                                    title="Preparation review"
                                    icon={ClipboardCheck}
                                >
                                    {roleKeys.map((key) => (
                                        <ReviewRow
                                            key={key}
                                            label={label(key)}
                                            value={roles[key] || 'Not recorded'}
                                        />
                                    ))}
                                    {preparation.map((row, index) => (
                                        <ReviewRow
                                            key={index}
                                            label={row.item}
                                            value={`${label(row.status)} · ${row.note}`}
                                        />
                                    ))}
                                    <ReviewRow
                                        label="Blocker"
                                        value={blocker || 'None recorded'}
                                    />
                                </ReviewCard>
                            )}
                            <p className="text-subtle">
                                Review any current changes before saving.
                                Previous decisions and completed work remain in
                                history.
                            </p>
                        </>
                    )}
                </div>
            </WizardShell>
            <ConfirmDialog
                variant="destructive"
                open={discard}
                onClose={() => setDiscard(false)}
                onConfirm={onClose}
                title="Discard funding update?"
                description="Unsaved changes will be cleared. If a save was not confirmed, check the case history before repeating it."
                confirmText="Discard draft"
            />
        </>
    );
}
