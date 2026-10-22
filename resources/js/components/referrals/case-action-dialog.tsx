import { ConfirmDialog } from '@/components/confirm-dialog';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import {
    Field,
    SelectInput,
    TilePicker,
    type IconType,
} from '@/components/wizard/primitives';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardSuccessPane,
} from '@/components/wizard/shell';
import {
    formatDateTime,
    toDatetimeLocal,
    workerTimeOffset,
} from '@/lib/datetime';
import { router } from '@inertiajs/react';
import axios from 'axios';
import {
    CalendarClock,
    ChevronsUp,
    ClipboardCheck,
    Flag,
    Minus,
    Siren,
    UserRound,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { ReferralDateTime } from './date-time';
import {
    DuplicateClosureReview,
    type DuplicateReview,
} from './duplicate-closure';
import { priorityText, ReferralPriorityBadge } from './priority-badge';
import {
    label,
    referralHref,
    type Options,
    type Permissions,
    type ReferralDetail,
    type WorkItem,
} from './types';
import { WorkflowRequirements } from './workflow-requirements';

export type CaseAction =
    | 'assign'
    | 'contact'
    | 'next'
    | 'work'
    | 'finish'
    | 'transition'
    | 'hold'
    | 'close'
    | 'reopen'
    | 'priority';
const titles: Record<CaseAction, string> = {
    assign: 'Assign referral',
    contact: 'Record contact',
    next: 'Set next action',
    work: 'Plan follow-up',
    finish: 'Finish follow-up',
    transition: 'Change stage',
    hold: 'Manage waiting period',
    close: 'Close referral',
    reopen: 'Reopen referral',
    priority: 'Set priority',
};
// Built-in priorities, for payloads from before priorities were configurable in Settings › Referrals.
const BUILT_IN_PRIORITY = [
    { id: 'urgent', label: 'Urgent' },
    { id: 'high', label: 'High' },
    { id: 'standard', label: 'Standard' },
];
const PRIORITY_TILES: Record<string, { icon: IconType; description: string }> =
    {
        urgent: { icon: Siren, description: 'Act on it before other work' },
        high: { icon: ChevronsUp, description: 'Sooner than standard work' },
        standard: { icon: Minus, description: 'The usual order of work' },
    };
const choices = (values: string[]) =>
    values.map((value) => ({ value, label: label(value) }));
// Built-in reasons, for payloads from before reasons were configurable in Settings › Referrals.
const BUILT_IN_HOLD = [
    'awaiting_person',
    'awaiting_evidence',
    'awaiting_assessment',
    'awaiting_funding',
    'capacity_waitlist',
    'person_requested_pause',
];
const BUILT_IN_CLOSE = [
    'information_only',
    'person_withdrew',
    'another_provider',
    'outside_scope',
    'unable_to_contact',
    'duplicate',
    'test',
    'spam',
    'other',
];
const configured = (
    items: { id: string; label: string }[] | undefined,
    fallback: string[],
) =>
    items
        ? items.map((item) => ({ value: item.id, label: item.label }))
        : choices(fallback);
const localInstant = (value: string) =>
    toDatetimeLocal(value) + workerTimeOffset(value);

export function CaseActionDialog({
    action,
    referral,
    work,
    options,
    permissions,
    onClose,
    initialDueAt,
    initialCloseReason,
}: {
    action: CaseAction;
    referral: ReferralDetail;
    work?: WorkItem;
    options: Options;
    permissions: Permissions;
    onClose: () => void;
    initialDueAt?: string | null;
    /** Preselect a closure reason, e.g. "duplicate" from Review a duplicate. */
    initialCloseReason?: string;
}) {
    const [step, setStep] = useState(0);
    const [busy, setBusy] = useState(false);
    const [saved, setSaved] = useState(false);
    const [leaveRecord, setLeaveRecord] = useState(false);
    const [transferWork, setTransferWork] = useState(false);
    const [discard, setDiscard] = useState(false);
    const [error, setError] = useState('');
    const [conflict, setConflict] = useState(false);
    const [version, setVersion] = useState(referral.version);
    const [workVersion, setWorkVersion] = useState(work?.version);
    const [staff, setStaff] = useState<{ id: number; name: string }[]>([]);
    const [staffSearch, setStaffSearch] = useState('');
    const [staffError, setStaffError] = useState('');
    const [site, setSite] = useState(
        referral.site_id === null ? 'central' : String(referral.site_id),
    );
    const [owner, setOwner] = useState(
        String(work?.owner_id ?? referral.owner_id ?? ''),
    );
    const [team, setTeam] = useState(referral.team);
    const [summary, setSummary] = useState(work?.details.description ?? '');
    const [title, setTitle] = useState(work?.details.title ?? '');
    const [kind, setKind] = useState(work?.kind ?? 'call');
    const [audience, setAudience] = useState(work?.audience ?? 'intake');
    const [fundingCaseId, setFundingCaseId] = useState(
        work?.funding_case_id ? String(work.funding_case_id) : 'none',
    );
    const [fundingCases, setFundingCases] = useState(
        referral.funding?.cases.current_page === 1
            ? referral.funding.cases.data
            : [],
    );
    const [fundingPage, setFundingPage] = useState(
        referral.funding?.cases.current_page === 1 ? 1 : 0,
    );
    const [fundingLastPage, setFundingLastPage] = useState(
        referral.funding?.cases.last_page ?? 1,
    );
    const [loadingCases, setLoadingCases] = useState(false);
    const [channel, setChannel] = useState(referral.contact.channel ?? 'phone');
    const [outcome, setOutcome] = useState(
        action === 'finish' ? 'completed' : 'no_answer',
    );
    const [deviation, setDeviation] = useState('');
    const [targetStage, setTargetStage] = useState(
        referral.workflow_targets
            ? (referral.workflow_targets[0]?.id ?? '')
            : referral.stage === 'new'
              ? 'triage'
              : referral.stage === 'triage'
                ? 'assessment'
                : 'triage',
    );
    const workflowTarget = referral.workflow_targets?.find(
        (target) => target.id === targetStage,
    );
    const holdOptions = configured(referral.choices?.hold, BUILT_IN_HOLD);
    // Duplicate closure needs the identity reviewer's access; it stays available to them always.
    const closeOptions = configured(
        referral.choices?.close,
        BUILT_IN_CLOSE,
    ).filter(
        (option) =>
            option.value !== 'duplicate' || permissions['duplicates.manage'],
    );
    const [holdReason, setHoldReason] = useState(
        referral.hold_reason ?? holdOptions[0]?.value ?? '',
    );
    const [closeReason, setCloseReason] = useState(
        initialCloseReason ?? closeOptions[0]?.value ?? 'other',
    );
    const [duplicateReview, setDuplicateReview] = useState<DuplicateReview>({
        original: null,
        basis: '',
        confirmed: false,
    });
    const [testConfirmed, setTestConfirmed] = useState(false);
    const [informed, setInformed] = useState(false);
    const [communication, setCommunication] = useState('');
    const [cancelWork, setCancelWork] = useState(false);
    // Enabled priorities from the published settings, with their configured labels.
    const priorityOptions = referral.choices?.priority ?? BUILT_IN_PRIORITY;
    const priorityName = (key: string) =>
        priorityText(
            key,
            priorityOptions.find((option) => option.id === key)?.label ??
                (key === referral.priority ? referral.priority_label : null),
        );
    const [priority, setPriority] = useState('');
    const lifecycle = [
        'transition',
        'hold',
        'close',
        'reopen',
        'priority',
    ].includes(action);
    const [at, setAt] = useState(localInstant(new Date().toISOString()));
    const [due, setDue] = useState(
        work
            ? localInstant(work.due_at)
            : initialDueAt
              ? localInstant(initialDueAt)
              : toDatetimeLocal(Date.now() + 86400000),
    );
    const [end, setEnd] = useState(
        work?.ends_at ? localInstant(work.ends_at) : '',
    );
    const [next, setNext] = useState(
        action === 'next' ? referral.next_action : '',
    );
    const [makeNext, setMakeNext] = useState(
        work ? referral.primary_work_item_id === work.id : true,
    );
    const pending = useRef(false);
    const body = useRef<HTMLDivElement>(null);
    async function loadMoreFundingCases() {
        if (loadingCases) return;
        setLoadingCases(true);
        try {
            const response = await axios.get<{ referral: ReferralDetail }>(
                `${referralHref(referral.reference)}/data`,
                { params: { funding_page: fundingPage + 1 } },
            );
            const cases = response.data.referral.funding?.cases;
            if (!cases) throw new Error('Funding access unavailable');
            setFundingCases((current) => [
                ...current,
                ...cases.data.filter(
                    (entry) =>
                        !current.some((existing) => existing.id === entry.id),
                ),
            ]);
            setFundingPage(cases.current_page);
            setFundingLastPage(cases.last_page);
        } catch {
            setError(
                'Funding cases could not be loaded. Check current access or try again.',
            );
        } finally {
            setLoadingCases(false);
        }
    }
    const needsOwner = action === 'assign' || action === 'work';
    // Priority orders work only: it keeps the next action, its date and every clock.
    const needsNext =
        (lifecycle && action !== 'close' && action !== 'priority') ||
        action === 'contact' ||
        action === 'next' ||
        (action === 'finish' &&
            (work?.id === referral.primary_work_item_id ||
                !!work?.is_primary_funding_action));
    const stages = [
        {
            key: 'details',
            label: 'Details',
            blurb: titles[action],
            icon: UserRound,
        },
        ...(action === 'priority'
            ? []
            : [
                  {
                      key: 'plan',
                      label: 'Responsibility and timing',
                      blurb: 'A clear next step',
                      icon: CalendarClock,
                  },
              ]),
        {
            key: 'review',
            label: 'Review',
            blurb: 'Check and record',
            icon: ClipboardCheck,
        },
    ];
    const reviewStep = stages.length - 1;
    useEffect(() => {
        if (!needsOwner) return;
        const controller = new AbortController();
        const timer = window.setTimeout(() => {
            axios
                .get<{ staff: { id: number; name: string }[] }>(
                    '/operations/referrals/staff-options',
                    {
                        params: {
                            reference: referral.reference,
                            site_id: site === 'central' ? '' : site,
                            q: staffSearch,
                            purpose: action === 'assign' ? 'owner' : 'work',
                            audience,
                        },
                        signal: controller.signal,
                    },
                )
                .then((response) => {
                    setStaff(response.data.staff);
                    setStaffError('');
                })
                .catch((caught) => {
                    if (!axios.isCancel(caught)) {
                        setStaff([]);
                        setStaffError(
                            'Eligible staff could not be loaded. Check access or try another search.',
                        );
                    }
                });
        }, 250);
        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [needsOwner, referral.reference, site, staffSearch, action, audience]);
    const go = (value: number) => {
        setStep(value);
        requestAnimationFrame(() => {
            body.current?.scrollTo({ top: 0 });
            body.current?.querySelector<HTMLElement>('[data-heading]')?.focus();
        });
    };
    const close = () => {
        if (busy) return;
        if (saved && leaveRecord) router.visit('/operations/referrals');
        else if (saved) onClose();
        else setDiscard(true);
    };
    function invalidStep(index: number): string | null {
        if (
            action === 'transition' &&
            (!targetStage || (referral.workflow_targets && !workflowTarget))
        )
            return 'No stage change is available under this referral’s workflow and your current role.';
        if (action === 'transition' && workflowTarget?.restricted_requirements)
            return 'A colleague with access to the required evidence must make this stage change.';
        if (index === 0) {
            if (action === 'priority' && !priority)
                return 'Choose the new priority.';
            if (action === 'priority' && priority === referral.priority)
                return 'This is already the referral’s priority. Choose a different one.';
            if (
                action === 'close' &&
                closeReason === 'duplicate' &&
                (!duplicateReview.original ||
                    duplicateReview.basis.trim().length < 20 ||
                    !duplicateReview.confirmed)
            )
                return 'Review the original referral, explain the match and confirm it is the same enquiry.';
            if (action === 'close' && closeReason === 'test' && !testConfirmed)
                return 'Confirm this is a test entry with no genuine request for support.';
            if (
                action !== 'work' &&
                summary.trim().length <
                    (lifecycle || action === 'assign' ? 5 : 3)
            )
                return 'Add a clear summary or reason before continuing.';
            if (action === 'work' && !title.trim())
                return 'Give this follow-up a title.';
            if (
                action === 'work' &&
                kind === 'funding_follow_up' &&
                (audience !== 'funding' || fundingCaseId === 'none')
            )
                return 'Choose the funding audience and the specific funding case for this follow-up.';
            if (action === 'assign' && (!site || !team.trim()))
                return 'Choose the owning site and responsible team.';
        }
        if (index === 1) {
            if (needsOwner && !owner) return 'Choose an eligible named owner.';
            if (needsNext && !next.trim()) return 'Describe the next action.';
            if ((needsNext || action === 'work') && !due)
                return 'Choose a next action or follow-up date.';
            if (
                (needsNext || action === 'work') &&
                new Date(due).getTime() <= Date.now()
            )
                return 'Choose a future follow-up date and time.';
            if (action === 'contact' && !at)
                return 'Record when the contact happened.';
            if (action === 'close' && communication.trim().length < 5)
                return 'Record the communication or explain why contact was not appropriate.';
        }
        return null;
    }
    function continueStep() {
        const message = invalidStep(step);
        if (message) {
            setError(message);
            return;
        }
        setError('');
        go(step + 1);
    }
    async function save() {
        if (pending.current || saved) return;
        for (let index = 0; index < reviewStep; index++) {
            const message = invalidStep(index);
            if (message) {
                setError(message);
                go(index);
                return;
            }
        }
        pending.current = true;
        setBusy(true);
        setError('');
        const base = referralHref(referral.reference);
        const payload: Record<string, unknown> = { version };
        let url = base;
        let method: 'post' | 'patch' = 'post';
        if (action === 'assign') {
            url += '/assign';
            Object.assign(payload, {
                site_id: site === 'central' ? null : Number(site),
                owner_id: Number(owner),
                team,
                reason: summary,
                transfer_open_work:
                    transferWork &&
                    !!referral.owner_id &&
                    Number(owner) !== referral.owner_id,
            });
        }
        if (action === 'contact') {
            url += '/contacts';
            Object.assign(payload, {
                channel,
                outcome,
                audience,
                occurred_at: at,
                summary,
                preference_deviation: deviation || null,
                next_action: next,
                next_due_at: due,
            });
        }
        if (action === 'next') {
            url += '/next-action';
            method = 'patch';
            Object.assign(payload, {
                next_action: next,
                next_due_at: due,
                reason: summary,
            });
        }
        if (action === 'work') {
            url += '/work' + (work ? `/${work.id}` : '');
            method = work ? 'patch' : 'post';
            Object.assign(payload, {
                owner_id: Number(owner),
                funding_case_id: work
                    ? work.funding_case_id
                    : audience === 'funding' && fundingCaseId !== 'none'
                      ? Number(fundingCaseId)
                      : null,
                kind,
                audience,
                title,
                description: summary || null,
                due_at: due,
                ends_at: end || null,
                make_next_action: makeNext,
                ...(work ? { work_version: workVersion } : {}),
            });
        }
        if (action === 'finish' && work) {
            url += `/work/${work.id}/finish`;
            Object.assign(payload, {
                work_version: workVersion,
                outcome,
                note: summary,
                next_action: next || null,
                next_due_at: needsNext ? due : null,
            });
        }
        if (lifecycle) {
            url += `/lifecycle/${action}`;
            Object.assign(payload, { reason: summary });
            if (needsNext)
                Object.assign(payload, { next_action: next, next_due_at: due });
            if (action === 'priority') payload.priority = priority;
            if (action === 'transition') payload.stage = targetStage;
            if (action === 'hold')
                payload.hold_reason =
                    holdReason === 'release' ? null : holdReason;
            if (action === 'close')
                Object.assign(payload, {
                    closure_reason: closeReason,
                    person_informed: informed,
                    communication_note: communication,
                    cancel_open_work: cancelWork,
                    ...(closeReason === 'duplicate'
                        ? {
                              duplicate_reference:
                                  duplicateReview.original?.reference,
                              duplicate_version:
                                  duplicateReview.original?.version,
                              duplicate_basis: duplicateReview.basis,
                              same_enquiry_confirmed: duplicateReview.confirmed,
                          }
                        : {}),
                    ...(closeReason === 'test'
                        ? { test_entry_confirmed: testConfirmed }
                        : {}),
                });
        }
        try {
            const response = await axios.request<{
                saved: boolean;
                can_view?: boolean;
            }>({
                url,
                method,
                data: payload,
            });
            if (!response.data.saved) throw new Error('Save not confirmed');
            setSaved(true);
            const leaving =
                action === 'assign' && response.data.can_view === false;
            setLeaveRecord(leaving);
            if (!leaving)
                router.reload({ only: ['referral'], preserveScroll: true });
        } catch (caught) {
            const status = axios.isAxiosError(caught)
                ? (caught.response?.status ?? null)
                : null;
            const data = axios.isAxiosError(caught)
                ? caught.response?.data
                : null;
            setConflict(status === 409 || status === null);
            const messages = data?.errors
                ? Object.values(data.errors as Record<string, string[]>)
                      .flat()
                      .join(' ')
                : null;
            setError(
                messages ??
                    data?.message ??
                    'Save was not confirmed. Reload the record and check its activity before trying again. Your draft is kept here.',
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
                if (work) {
                    const currentWork = latest.work.data.find(
                        (item) => item.id === work.id,
                    );
                    if (!currentWork || currentWork.status !== 'open') {
                        setError(
                            'This follow-up is no longer open or visible on this page. Close the draft and review the current follow-ups.',
                        );
                        setConflict(true);
                        return;
                    }
                    setWorkVersion(currentWork.version);
                }
                setVersion(latest.version);
                setConflict(false);
                if (action === 'close' && closeReason === 'duplicate') {
                    setDuplicateReview((value) => ({
                        ...value,
                        original: null,
                        confirmed: false,
                    }));
                    setError(
                        'Latest referral loaded. Review the original referral again before confirming this duplicate. If the closure is already recorded, close this draft.',
                    );
                    go(0);
                    return;
                }
                setError(
                    'Latest record loaded. Compare the current next action and activity with your draft before saving. If your earlier action is already recorded, close this draft.',
                );
                go(reviewStep);
            },
        });
    }
    return (
        <>
            <WizardShell
                open
                onClose={close}
                title={titles[action]}
                description={`${titles[action]} for ${referral.name}`}
                railIcon={ClipboardCheck}
                railTitle={titles[action]}
                railSub={referral.name}
                steps={stages}
                stepIndex={step}
                onStepClick={(index) => {
                    if (!busy) go(index);
                }}
                bodyRef={body}
                footerStart={
                    <Button
                        variant="ghost"
                        disabled={busy}
                        onClick={() => (step ? go(step - 1) : close())}
                    >
                        {step ? 'Back' : 'Cancel'}
                    </Button>
                }
                footerEnd={
                    step < reviewStep ? (
                        <Button onClick={continueStep}>Continue</Button>
                    ) : (
                        <Button
                            disabled={busy || conflict}
                            onClick={() => void save()}
                        >
                            {busy ? 'Saving…' : 'Save'}
                        </Button>
                    )
                }
                success={
                    saved ? (
                        <WizardSuccessPane
                            title="Saved"
                            blurb={
                                leaveRecord
                                    ? 'The assignment is saved. This referral is now outside your record access. Return to Referrals to continue.'
                                    : 'The referral and its activity history have been updated together.'
                            }
                            actions={
                                <Button onClick={close}>
                                    {leaveRecord
                                        ? 'Return to Referrals'
                                        : 'Done'}
                                </Button>
                            }
                        />
                    ) : undefined
                }
            >
                <div className="space-y-5">
                    <h2
                        className="text-section-title"
                        tabIndex={-1}
                        data-heading
                    >
                        {stages[step].label}
                    </h2>
                    {error && (
                        <Alert variant="warning">
                            <AlertTitle>Review before saving</AlertTitle>
                            <AlertDescription>
                                {error}
                                {conflict && (
                                    <Button variant="outline" onClick={refresh}>
                                        Reload record and keep draft
                                    </Button>
                                )}
                            </AlertDescription>
                        </Alert>
                    )}
                    {step === 0 && (
                        <div className="space-y-5">
                            {action === 'assign' && (
                                <>
                                    <Field label="Owning site">
                                        <SelectInput
                                            value={site}
                                            disabled={
                                                referral.stage === 'converted'
                                            }
                                            onChange={(value) => {
                                                setSite(value);
                                                setOwner('');
                                            }}
                                            placeholder="Choose site"
                                            options={[
                                                ...(permissions.centralIntake
                                                    ? [
                                                          {
                                                              value: 'central',
                                                              label: 'Central intake',
                                                          },
                                                      ]
                                                    : []),
                                                ...options.sites.map(
                                                    (item) => ({
                                                        value: String(item.id),
                                                        label: item.name,
                                                    }),
                                                ),
                                            ]}
                                        />
                                    </Field>
                                    {referral.stage === 'converted' && (
                                        <p className="text-subtle">
                                            Site moves must be reviewed through
                                            the supported person’s placement and
                                            service workflow.
                                        </p>
                                    )}
                                    <Field label="Responsible team">
                                        <Input
                                            value={team}
                                            onChange={(event) =>
                                                setTeam(event.target.value)
                                            }
                                            maxLength={120}
                                        />
                                    </Field>
                                </>
                            )}
                            {(action === 'contact' || action === 'work') && (
                                <Alert variant="info">
                                    <AlertDescription>
                                        Safe contact:{' '}
                                        {referral.contact.channel ??
                                            'Not recorded'}{' '}
                                        ·{' '}
                                        {referral.contact.safe_time ||
                                            'No preferred time recorded'}
                                        .{' '}
                                        {referral.contact.leave_message
                                            ? 'Messages are safe.'
                                            : 'Do not leave messages.'}{' '}
                                        {referral.contact_permission
                                            ? ''
                                            : 'Follow-up contact permission has not been recorded.'}
                                    </AlertDescription>
                                </Alert>
                            )}
                            {action === 'contact' && (
                                <>
                                    <Field label="Contact method">
                                        <SelectInput
                                            value={channel}
                                            onChange={setChannel}
                                            placeholder="Method"
                                            options={choices([
                                                'phone',
                                                'email',
                                                'in_person',
                                                'video',
                                            ])}
                                        />
                                    </Field>
                                    <Field label="What happened?">
                                        <SelectInput
                                            value={outcome}
                                            onChange={setOutcome}
                                            placeholder="Outcome"
                                            options={[
                                                {
                                                    value: 'connected',
                                                    label: 'Successful human conversation',
                                                },
                                                ...choices([
                                                    'no_answer',
                                                    'message_left',
                                                    'sent',
                                                    'wrong_details',
                                                    'declined_contact',
                                                ]),
                                            ]}
                                        />
                                    </Field>
                                </>
                            )}
                            {action === 'work' && (
                                <>
                                    <Field label="Follow-up title">
                                        <Input
                                            value={title}
                                            onChange={(event) =>
                                                setTitle(event.target.value)
                                            }
                                            maxLength={500}
                                        />
                                    </Field>
                                    <Field label="Kind of work">
                                        <SelectInput
                                            value={kind}
                                            onChange={setKind}
                                            placeholder="Kind"
                                            options={choices([
                                                'follow_up',
                                                'funding_follow_up',
                                                'call',
                                                'meeting',
                                                'assessment',
                                                'document_request',
                                                'internal_review',
                                            ])}
                                        />
                                    </Field>
                                </>
                            )}
                            {(action === 'work' || action === 'contact') && (
                                <Field label="Information audience">
                                    <SelectInput
                                        value={audience}
                                        onChange={(value) => {
                                            if (work) return;
                                            setAudience(value);
                                            if (value !== 'intake')
                                                setMakeNext(false);
                                        }}
                                        placeholder="Audience"
                                        options={
                                            work
                                                ? [
                                                      {
                                                          value: work.audience,
                                                          label:
                                                              label(
                                                                  work.audience,
                                                              ) +
                                                              ' (original audience)',
                                                      },
                                                  ]
                                                : [
                                                      {
                                                          value: 'intake',
                                                          label: 'General intake',
                                                      },
                                                      ...(permissions[
                                                          'assessment.manage'
                                                      ]
                                                          ? [
                                                                {
                                                                    value: 'assessment',
                                                                    label: 'Assessment — restricted',
                                                                },
                                                            ]
                                                          : []),
                                                      ...(permissions[
                                                          'funding.manage'
                                                      ]
                                                          ? [
                                                                {
                                                                    value: 'funding',
                                                                    label: 'Funding — restricted',
                                                                },
                                                            ]
                                                          : []),
                                                  ]
                                        }
                                    />
                                </Field>
                            )}
                            {action === 'work' && audience === 'funding' && (
                                <>
                                    <Field label="Funding case">
                                        <SelectInput
                                            value={fundingCaseId}
                                            onChange={setFundingCaseId}
                                            placeholder="Choose funding case"
                                            options={
                                                work
                                                    ? [
                                                          {
                                                              value: fundingCaseId,
                                                              label:
                                                                  fundingCases.find(
                                                                      (entry) =>
                                                                          String(
                                                                              entry.id,
                                                                          ) ===
                                                                          fundingCaseId,
                                                                  )
                                                                      ?.pathway_label ??
                                                                  (fundingCaseId ===
                                                                  'none'
                                                                      ? 'General funding follow-up'
                                                                      : 'Original funding case'),
                                                          },
                                                      ]
                                                    : [
                                                          {
                                                              value: 'none',
                                                              label: 'General funding follow-up',
                                                          },
                                                          ...fundingCases
                                                              .filter(
                                                                  (entry) =>
                                                                      ![
                                                                          'closed',
                                                                          'withdrawn',
                                                                      ].includes(
                                                                          entry.status,
                                                                      ),
                                                              )
                                                              .map((entry) => ({
                                                                  value: String(
                                                                      entry.id,
                                                                  ),
                                                                  label: `${entry.pathway_label} · ${label(entry.status)} · ${entry.navigator_name ?? 'Navigator needed'}`,
                                                              })),
                                                      ]
                                            }
                                        />
                                    </Field>
                                    {!work && fundingPage < fundingLastPage && (
                                        <Button
                                            variant="outline"
                                            disabled={loadingCases}
                                            onClick={() =>
                                                void loadMoreFundingCases()
                                            }
                                        >
                                            {loadingCases
                                                ? 'Loading cases…'
                                                : 'Load more funding cases'}
                                        </Button>
                                    )}
                                    <p className="text-subtle">
                                        A funding follow-up needs a specific
                                        case. Case and audience stay fixed after
                                        creation so the history remains with the
                                        right application.
                                    </p>
                                </>
                            )}
                            {action === 'transition' && (
                                <Field label="Next stage">
                                    {referral.workflow_targets?.length ===
                                        0 && (
                                        <p className="text-subtle">
                                            No stage change is available under
                                            this referral’s recorded workflow
                                            and your current role. Ask an
                                            authorized coordinator to review it.
                                        </p>
                                    )}
                                    <SelectInput
                                        value={targetStage}
                                        onChange={setTargetStage}
                                        placeholder="Select stage"
                                        options={
                                            referral.workflow_targets?.map(
                                                (stage) => ({
                                                    value: stage.id,
                                                    label: stage.label,
                                                }),
                                            ) ??
                                            choices(
                                                [
                                                    'triage',
                                                    'assessment',
                                                    'funding_assistance',
                                                ].filter(
                                                    (value) =>
                                                        value !==
                                                        referral.stage,
                                                ),
                                            )
                                        }
                                    />
                                    <WorkflowRequirements
                                        target={workflowTarget}
                                    />
                                </Field>
                            )}
                            {action === 'priority' && (
                                <>
                                    <div className="flex flex-wrap items-center gap-2 rounded-lg bg-muted p-3 text-sm">
                                        <span className="text-muted-foreground">
                                            Current priority
                                        </span>
                                        <ReferralPriorityBadge
                                            priority={referral.priority}
                                            priorityLabel={
                                                referral.priority_label
                                            }
                                        />
                                    </div>
                                    <div className="space-y-2">
                                        <p
                                            id="priority-choice-label"
                                            className="text-sm font-medium"
                                        >
                                            New priority
                                        </p>
                                        <p
                                            id="priority-choice-hint"
                                            className="text-subtle"
                                        >
                                            Priority orders the team’s work. It
                                            keeps the stage, next action, due
                                            date and response times as they are.
                                        </p>
                                        <TilePicker
                                            labelledBy="priority-choice-label"
                                            describedBy="priority-choice-hint"
                                            value={priority}
                                            onChange={setPriority}
                                            options={priorityOptions.map(
                                                (option) => ({
                                                    key: option.id,
                                                    label: option.label,
                                                    icon:
                                                        PRIORITY_TILES[
                                                            option.id
                                                        ]?.icon ?? Flag,
                                                    description:
                                                        PRIORITY_TILES[
                                                            option.id
                                                        ]?.description,
                                                    meta:
                                                        option.id ===
                                                        referral.priority
                                                            ? 'Current priority'
                                                            : undefined,
                                                }),
                                            )}
                                        />
                                    </div>
                                </>
                            )}
                            {action === 'hold' && (
                                <>
                                    <Alert variant="info">
                                        <AlertDescription>
                                            Waiting keeps the current stage and
                                            its history. Record a named owner,
                                            review action and date; funding and
                                            assessment work can continue.
                                        </AlertDescription>
                                    </Alert>
                                    <Field label="Reason for waiting">
                                        <SelectInput
                                            value={holdReason}
                                            onChange={setHoldReason}
                                            placeholder="Select reason"
                                            options={[
                                                ...(referral.hold_reason
                                                    ? [
                                                          {
                                                              value: 'release',
                                                              label: 'End the waiting period',
                                                          },
                                                      ]
                                                    : []),
                                                ...holdOptions,
                                            ]}
                                        />
                                    </Field>
                                </>
                            )}
                            {action === 'close' && (
                                <>
                                    <Alert variant="warning">
                                        <AlertTitle>
                                            Resolve outstanding work
                                        </AlertTitle>
                                        <AlertDescription>
                                            Open funding cases must be resolved
                                            first. Follow-ups can be cancelled
                                            here only with the required access.
                                            Closure retains the referral and its
                                            history.
                                        </AlertDescription>
                                    </Alert>
                                    <Field label="Closure reason">
                                        <SelectInput
                                            value={closeReason}
                                            onChange={(value) => {
                                                setCloseReason(value);
                                                setDuplicateReview({
                                                    original: null,
                                                    basis: '',
                                                    confirmed: false,
                                                });
                                                setTestConfirmed(false);
                                            }}
                                            placeholder="Select reason"
                                            options={closeOptions}
                                        />
                                    </Field>
                                    {closeReason === 'duplicate' && (
                                        <DuplicateClosureReview
                                            source={referral.reference}
                                            value={duplicateReview}
                                            onChange={setDuplicateReview}
                                        />
                                    )}
                                    {closeReason === 'test' && (
                                        <label className="text-subtle flex items-start gap-3">
                                            <Checkbox
                                                checked={testConfirmed}
                                                onCheckedChange={(value) =>
                                                    setTestConfirmed(
                                                        value === true,
                                                    )
                                                }
                                            />
                                            This is a test entry with no genuine
                                            request for support.
                                        </label>
                                    )}
                                </>
                            )}
                            {action === 'finish' && (
                                <Field label="Outcome">
                                    <SelectInput
                                        value={outcome}
                                        onChange={setOutcome}
                                        placeholder="Outcome"
                                        options={choices([
                                            'completed',
                                            'cancelled',
                                        ])}
                                    />
                                </Field>
                            )}
                            <Field
                                label={
                                    lifecycle ||
                                    action === 'assign' ||
                                    action === 'next'
                                        ? 'Reason for this change'
                                        : action === 'finish'
                                          ? 'Completion or cancellation note'
                                          : 'Summary'
                                }
                            >
                                <Textarea
                                    value={summary}
                                    onChange={(event) =>
                                        setSummary(event.target.value)
                                    }
                                    maxLength={2000}
                                />
                            </Field>
                            {action === 'contact' && (
                                <Field
                                    label="Contact preference deviation (only if one actually occurred)"
                                    hint="Record what happened and the follow-up; this does not authorize contact."
                                >
                                    <Textarea
                                        value={deviation}
                                        onChange={(event) =>
                                            setDeviation(event.target.value)
                                        }
                                        maxLength={1000}
                                    />
                                </Field>
                            )}
                        </div>
                    )}
                    {step === 1 && step < reviewStep && (
                        <div className="space-y-5">
                            {action === 'close' && (
                                <>
                                    <div className="flex items-center gap-3">
                                        <Checkbox
                                            id="closure-informed"
                                            checked={informed}
                                            onCheckedChange={(value) =>
                                                setInformed(value === true)
                                            }
                                        />
                                        <Label
                                            htmlFor="closure-informed"
                                            className="flex items-center"
                                        >
                                            The person or authorized contact has
                                            been informed
                                        </Label>
                                    </div>
                                    <Field
                                        label="Communication or reason it was not appropriate"
                                        hint="Record what was shared, with whom and when, or why contact was not made."
                                    >
                                        <Textarea
                                            value={communication}
                                            onChange={(event) =>
                                                setCommunication(
                                                    event.target.value,
                                                )
                                            }
                                            maxLength={2000}
                                        />
                                    </Field>
                                    {permissions['tasks.manage'] && (
                                        <div className="flex items-center gap-3">
                                            <Checkbox
                                                id="closure-work"
                                                checked={cancelWork}
                                                onCheckedChange={(value) =>
                                                    setCancelWork(
                                                        value === true,
                                                    )
                                                }
                                            />
                                            <Label
                                                htmlFor="closure-work"
                                                className="flex items-center"
                                            >
                                                Cancel all remaining follow-ups
                                                with this closure
                                            </Label>
                                        </div>
                                    )}
                                </>
                            )}
                            {needsOwner && (
                                <>
                                    <Field label="Find eligible staff">
                                        <Input
                                            value={staffSearch}
                                            onChange={(event) =>
                                                setStaffSearch(
                                                    event.target.value,
                                                )
                                            }
                                            placeholder="Search staff by name"
                                        />
                                    </Field>
                                    <Field
                                        label="Named owner"
                                        error={staffError}
                                    >
                                        <SelectInput
                                            value={owner}
                                            onChange={setOwner}
                                            placeholder="Select eligible staff"
                                            options={staff.map((item) => ({
                                                value: String(item.id),
                                                label: item.name,
                                            }))}
                                        />
                                    </Field>
                                    <p className="text-subtle">
                                        Assignment does not grant access. Staff
                                        must already have the required
                                        permissions and site access.
                                    </p>
                                    {action === 'assign' && (
                                        <div className="space-y-3">
                                            {referral.owner_id &&
                                                Number(owner) !==
                                                    referral.owner_id && (
                                                    <label className="flex items-start gap-3">
                                                        <Checkbox
                                                            checked={
                                                                transferWork
                                                            }
                                                            disabled={
                                                                !permissions[
                                                                    'tasks.manage'
                                                                ]
                                                            }
                                                            onCheckedChange={(
                                                                checked,
                                                            ) =>
                                                                setTransferWork(
                                                                    checked ===
                                                                        true,
                                                                )
                                                            }
                                                        />
                                                        <span className="text-subtle">
                                                            Transfer the
                                                            outgoing owner’s
                                                            open follow-ups to
                                                            the new owner
                                                        </span>
                                                    </label>
                                                )}
                                            <p className="text-subtle">
                                                Existing dates, notes and
                                                completed work are retained.
                                                Funding navigators and
                                                follow-ups owned by other staff
                                                stay assigned to them. The
                                                change will be blocked if any
                                                open work loses access; a
                                                colleague may need to review
                                                restricted work first.
                                            </p>
                                        </div>
                                    )}
                                </>
                            )}
                            {action === 'contact' && (
                                <ReferralDateTime
                                    id="contact-occurred"
                                    label="Contact happened"
                                    value={at}
                                    onChange={setAt}
                                />
                            )}
                            {needsNext && (
                                <Field
                                    label={
                                        work?.is_primary_funding_action
                                            ? 'Next funding action — funding audience'
                                            : 'Next action — visible in the intake queue'
                                    }
                                >
                                    <Input
                                        value={next}
                                        onChange={(event) =>
                                            setNext(event.target.value)
                                        }
                                        maxLength={500}
                                    />
                                </Field>
                            )}
                            {(needsNext || action === 'work') && (
                                <ReferralDateTime
                                    id="action-due"
                                    label={
                                        action === 'work'
                                            ? 'Follow-up due or appointment start'
                                            : 'Next action due'
                                    }
                                    value={due}
                                    onChange={setDue}
                                />
                            )}
                            {action === 'work' && (
                                <>
                                    <ReferralDateTime
                                        id="work-end"
                                        label="Appointment end (optional)"
                                        value={end}
                                        onChange={setEnd}
                                    />
                                    {end && (
                                        <Button
                                            variant="ghost"
                                            onClick={() => setEnd('')}
                                        >
                                            Clear appointment end
                                        </Button>
                                    )}
                                    {audience === 'intake' && (
                                        <div className="flex items-center gap-3">
                                            <Checkbox
                                                id="make-primary"
                                                checked={makeNext}
                                                onCheckedChange={(value) =>
                                                    setMakeNext(value === true)
                                                }
                                            />
                                            <Label
                                                htmlFor="make-primary"
                                                className="flex items-center"
                                            >
                                                Make this the referral’s next
                                                action
                                            </Label>
                                        </div>
                                    )}
                                </>
                            )}
                            {action === 'finish' && !needsNext && (
                                <p className="text-subtle">
                                    This follow-up is separate from the
                                    referral’s current next action. Its outcome
                                    remains in the history.
                                </p>
                            )}
                        </div>
                    )}
                    {step === reviewStep && (
                        <div className="space-y-4">
                            <ReviewCard
                                icon={ClipboardCheck}
                                title={titles[action]}
                                onEdit={() => go(0)}
                            >
                                <ReviewRow
                                    label="Referral"
                                    value={referral.name}
                                />
                                <ReviewRow
                                    label="Summary / reason"
                                    value={summary}
                                />
                                {action === 'priority' && (
                                    <ReviewRow
                                        label="Priority"
                                        value={`${priorityName(referral.priority)} → ${priority ? priorityName(priority) : 'Not chosen'}`}
                                    />
                                )}
                                {action === 'transition' && (
                                    <ReviewRow
                                        label="Stage"
                                        value={
                                            referral.workflow_targets?.find(
                                                (stage) =>
                                                    stage.id === targetStage,
                                            )?.label ?? label(targetStage)
                                        }
                                    />
                                )}
                                {action === 'hold' && (
                                    <ReviewRow
                                        label="Waiting period"
                                        value={
                                            holdReason === 'release'
                                                ? 'End waiting period'
                                                : label(holdReason)
                                        }
                                    />
                                )}
                                {action === 'close' && (
                                    <>
                                        <ReviewRow
                                            label="Closure reason"
                                            value={label(closeReason)}
                                        />
                                        {closeReason === 'duplicate' && (
                                            <>
                                                <ReviewRow
                                                    label="Original referral"
                                                    value={`${duplicateReview.original?.name} · ${duplicateReview.original?.reference}`}
                                                />
                                                <ReviewRow
                                                    label="Duplicate review"
                                                    value={
                                                        duplicateReview.basis
                                                    }
                                                />
                                            </>
                                        )}
                                        {closeReason === 'test' && (
                                            <ReviewRow
                                                label="Test entry confirmed"
                                                value="No genuine request for support"
                                            />
                                        )}
                                        <ReviewRow
                                            label="Person informed"
                                            value={
                                                informed
                                                    ? 'Yes'
                                                    : 'No — explanation recorded'
                                            }
                                        />
                                        <ReviewRow
                                            label="Communication / explanation"
                                            value={communication}
                                        />
                                        <ReviewRow
                                            label="Outstanding follow-ups"
                                            value={
                                                cancelWork
                                                    ? 'Cancel with this closure'
                                                    : 'Must already be resolved'
                                            }
                                        />
                                    </>
                                )}
                                {action === 'contact' && (
                                    <ReviewRow
                                        label="Outcome"
                                        value={label(outcome)}
                                    />
                                )}
                                {action === 'work' && (
                                    <ReviewRow
                                        label="Follow-up"
                                        value={title}
                                    />
                                )}
                                {needsOwner && (
                                    <ReviewRow
                                        label="Owner"
                                        value={
                                            staff.find(
                                                (item) =>
                                                    String(item.id) === owner,
                                            )?.name ?? 'Select eligible staff'
                                        }
                                    />
                                )}
                                {needsNext && (
                                    <ReviewRow
                                        label="Next action"
                                        value={next}
                                    />
                                )}
                                {(needsNext || action === 'work') && (
                                    <ReviewRow
                                        label="Due (NZ time)"
                                        value={due.replace('T', ' ')}
                                    />
                                )}
                                {action === 'assign' && (
                                    <ReviewRow
                                        label="Responsible team"
                                        value={team}
                                    />
                                )}
                                {action === 'assign' && (
                                    <ReviewRow
                                        label="Open follow-ups"
                                        value={
                                            transferWork &&
                                            !!referral.owner_id &&
                                            Number(owner) !== referral.owner_id
                                                ? 'Transfer outgoing owner’s open follow-ups; retain other owners and all dates'
                                                : 'Keep existing follow-up owners; check they retain access'
                                        }
                                    />
                                )}
                                {action === 'assign' && (
                                    <ReviewRow
                                        label="Site"
                                        value={
                                            site === 'central'
                                                ? 'Central intake'
                                                : options.sites.find(
                                                      (item) =>
                                                          String(item.id) ===
                                                          site,
                                                  )?.name
                                        }
                                    />
                                )}
                            </ReviewCard>
                            <ReviewCard
                                icon={CalendarClock}
                                title="Current saved record"
                            >
                                <ReviewRow
                                    label="Next action"
                                    value={referral.next_action}
                                />
                                <ReviewRow
                                    label="Owner"
                                    value={referral.owner_name ?? referral.team}
                                />
                                {referral.activity.data
                                    .slice(0, 3)
                                    .map((event) => (
                                        <ReviewRow
                                            key={event.reference}
                                            label={label(
                                                event.kind.replaceAll('.', '_'),
                                            )}
                                            value={String(
                                                event.details.summary ??
                                                    event.details.note ??
                                                    event.details.reason ??
                                                    formatDateTime(
                                                        event.occurred_at,
                                                    ),
                                            )}
                                        />
                                    ))}
                            </ReviewCard>
                        </div>
                    )}
                </div>
            </WizardShell>
            <ConfirmDialog
                variant="destructive"
                open={discard}
                onClose={() => setDiscard(false)}
                onConfirm={onClose}
                title="Discard this draft?"
                description="Your unsaved answers will be cleared. If a save was not confirmed, check the referral’s activity before recording the action again."
                confirmText="Discard draft"
            />
        </>
    );
}
