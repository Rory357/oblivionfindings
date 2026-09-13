import { ConfirmDialog } from '@/components/confirm-dialog';
import {
    Field,
    StepHead,
    TilePicker,
    WizardShell,
    WizardStepPane,
    WizardSuccessPane,
    type WizardStep,
} from '@/components/hr/wizard';
import type { KbDraft } from '@/components/it/it-wizards';
import { TicketDraftRecovery } from '@/components/it/ticket-draft-recovery';
import { TICKET_RESOLUTION_OUTCOMES as OUTCOMES } from '@/components/it/ticket-resolution';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Textarea } from '@/components/ui/textarea';
import {
    resumedDraftBaseVersion,
    type ItDraftCommitReference,
    type ItDraftSnapshot,
} from '@/hooks/it-ticket-draft-contract';
import { useItTicketDraft } from '@/hooks/use-it-ticket-draft';
import type { SharedData } from '@/types';
import { router, usePage } from '@inertiajs/react';
import axios from 'axios';
import { BookOpen, CheckCircle2 } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';

const STEPS: readonly WizardStep[] = [
    {
        key: 'resolve',
        label: 'Resolve',
        blurb: 'What fixed it',
        icon: CheckCircle2,
    },
];
const validOutcome = (value: unknown): value is string =>
    typeof value === 'string' && OUTCOMES.some((item) => item.key === value);
interface TicketIdentity {
    id: number;
    lock_version: number;
    reference: string | null;
    title: string;
}
interface Props {
    ticket: TicketIdentity;
    onDraftKb?: (draft: KbDraft) => void;
    onClose: () => void;
}
interface CurrentTicket extends TicketIdentity {
    status: string;
    canManage: boolean;
    resolution: {
        code: string;
        summary: string | null;
        verification: string | null;
    } | null;
}
type State =
    | 'editing'
    | 'submitting'
    | 'unknown'
    | 'reviewing'
    | 'reviewed'
    | 'session'
    | 'access'
    | 'done';
const record = (value: unknown): value is Record<string, unknown> =>
    !!value && typeof value === 'object' && !Array.isArray(value);
const positive = (value: unknown): value is number =>
    Number.isSafeInteger(value) && (value as number) > 0;
const working = (status: string) =>
    ['open', 'in_progress', 'waiting'].includes(status);

/** One canonical resolve dialog, isolated again whenever its actor or ticket changes. */
export function ResolveTicketDialog(props: Props) {
    const page = usePage<
        SharedData & { draftRecovery?: { enabled: boolean } }
    >();
    const actorId = page.props.auth.user?.id;
    return (
        <ResolveTicketForm
            key={`${actorId ?? 'none'}:${props.ticket.id}`}
            {...props}
            actorId={actorId}
            draftsEnabled={page.props.draftRecovery?.enabled === true}
        />
    );
}

function ResolveTicketForm({
    ticket,
    actorId,
    onClose,
    onDraftKb,
    draftsEnabled,
}: Props & { actorId: number | undefined; draftsEnabled: boolean }) {
    const [note, setNote] = useState('');
    const [outcome, setOutcome] = useState('');
    const [verification, setVerification] = useState('');
    const [notify, setNotify] = useState(true);
    const [version, setVersion] = useState(ticket.lock_version);
    const [state, setState] = useState<State>('editing');
    const [unconfirmed, setUnconfirmed] = useState(false);
    const [settledOperationToken, setSettledOperationToken] = useState(0);
    const [message, setMessage] = useState<string | null>(null);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [workBlocker, setWorkBlocker] = useState<{
        kind: 'task' | 'approval';
        id: number | null;
    } | null>(null);
    const [current, setCurrent] = useState<CurrentTicket | null>(null);
    const [leave, setLeave] = useState<null | {
        run: () => void;
        navigation: boolean;
    }>(null);
    const alertRef = useRef<HTMLDivElement>(null);
    const controller = useRef<AbortController | null>(null);
    const epoch = useRef(0);
    const approvedNavigation = useRef(false);
    const onCloseRef = useRef(onClose);
    const submitted = useRef<
        | ({
              note: string;
              resolution_code: string;
              resolution_verification: string;
              notify_requester: boolean;
              expected_version: number;
              actor_user_id: number;
          } & Partial<ItDraftCommitReference>)
        | null
    >(null);
    const draftContext = useMemo(
        () => ({ purpose: 'public_resolution' as const, ticketId: ticket.id }),
        [ticket.id],
    );
    const snapshot: ItDraftSnapshot = useMemo(
        () => ({
            fields: {
                note,
                resolution_code: outcome,
                resolution_verification: verification,
                notify_requester: notify,
            },
            step_index: 0,
            base_ticket_version: version,
        }),
        [note, outcome, verification, notify, version],
    );
    const draft = useItTicketDraft({
        enabled: draftsEnabled,
        actorId,
        context: draftContext,
        active: state !== 'done' && state !== 'access',
        workingSnapshot: snapshot,
        workingDirty:
            state !== 'done' &&
            state !== 'access' &&
            (note.length > 0 ||
                outcome.length > 0 ||
                verification.length > 0 ||
                !notify),
        workingOutcomeUnknown: unconfirmed,
        workingSettledOperationToken: settledOperationToken,
        acceptedFields: [
            'note',
            'resolution_code',
            'resolution_verification',
            'notify_requester',
        ],
        onAccessLost: () =>
            deny(
                'Your saved resolution draft is no longer available. The entered note is concealed.',
            ),
    });
    const draftSaved = draftsEnabled && draft.isSaved(snapshot);
    const saveDraft = draft.save;
    const busy = state === 'submitting' || state === 'reviewing';
    const concealed =
        state === 'access' ||
        state === 'session' ||
        draft.state === 'session_expired' ||
        draft.state === 'access_denied';
    const dirty =
        state !== 'done' &&
        state !== 'access' &&
        (note.length > 0 ||
            outcome.length > 0 ||
            verification.length > 0 ||
            !notify);
    const draftLocked =
        draftsEnabled && (draft.state !== 'ready' || draft.busy);
    const locked =
        state !== 'editing' || draftLocked || draft.busy || draft.memoryBlocked;
    const valid =
        note.trim().length > 0 &&
        note.length <= 5000 &&
        validOutcome(outcome) &&
        verification.trim().length > 0 &&
        verification.length <= 5000 &&
        positive(actorId) &&
        !locked &&
        (!draftsEnabled ||
            (draftSaved && draft.draft?.capabilities.submit === true));

    useEffect(() => {
        if (
            !draftsEnabled ||
            state !== 'editing' ||
            draft.state !== 'ready' ||
            draft.busy ||
            draft.memoryBlocked ||
            draftSaved ||
            Object.keys(draft.errors).length ||
            (!note.length &&
                !outcome.length &&
                !verification.length &&
                notify &&
                !draft.draft?.has_content)
        )
            return;
        const timer = window.setTimeout(() => void saveDraft(snapshot), 800);
        return () => window.clearTimeout(timer);
    }, [
        draftsEnabled,
        state,
        draft.state,
        draft.busy,
        draft.memoryBlocked,
        draftSaved,
        draft.errors,
        draft.draft?.has_content,
        note,
        outcome,
        verification,
        notify,
        snapshot,
        saveDraft,
    ]);

    useEffect(() => {
        onCloseRef.current = onClose;
    }, [onClose]);
    useEffect(() => {
        if (!message && Object.keys(errors).length === 0) return;
        // Run after the dialog's focus restoration when a pending control changes.
        const focus = window.setTimeout(() => alertRef.current?.focus(), 0);
        return () => window.clearTimeout(focus);
    }, [message, errors]);
    useEffect(
        () => () => {
            ++epoch.current;
            controller.current?.abort();
            submitted.current = null;
        },
        [],
    );
    useEffect(() => {
        if (
            state === 'done' ||
            (!dirty && !busy && !draft.busy && !unconfirmed) ||
            (draftSaved && !busy && !draft.busy && !unconfirmed)
        )
            return;
        const beforeUnload = (event: BeforeUnloadEvent) => {
            event.preventDefault();
            event.returnValue = '';
        };
        window.addEventListener('beforeunload', beforeUnload);
        const remove = router.on('before', (event) => {
            if (
                event.detail.visit.method !== 'get' ||
                approvedNavigation.current
            )
                return;
            event.preventDefault();
            const visit = event.detail.visit;
            setLeave({
                navigation: true,
                run: () => router.visit(visit.url, visit),
            });
        });
        return () => {
            window.removeEventListener('beforeunload', beforeUnload);
            remove();
        };
    }, [busy, dirty, state, draftSaved, draft.busy, unconfirmed]);

    function deny(copy: string) {
        setWorkBlocker(null);
        draft.clearBrowserWork();
        setNote('');
        setOutcome('');
        setVerification('');
        setNotify(true);
        submitted.current = null;
        setCurrent(null);
        setErrors({});
        setMessage(copy);
        setState('access');
        setUnconfirmed(false);
        setLeave(null);
    }
    const failure = (error: unknown, phase: 'submit' | 'review') => {
        setWorkBlocker(null);
        const response = axios.isAxiosError(error) ? error.response : undefined;
        const body = record(response?.data) ? response.data : {};
        if (response?.status === 403 || response?.status === 404) {
            deny(
                'This ticket is no longer available for you to resolve. The entered note is concealed.',
            );
            return;
        }
        if (response?.status === 401 || response?.status === 419) {
            setState('session');
            setMessage(
                'Your session expired. Sign in with the same account, then review the current ticket.',
            );
            setErrors({});
            return;
        }
        if (phase === 'submit' && response?.status === 422) {
            const blocker = body.blocker;
            if (
                body.code === 'ticket_resolution_blocked' &&
                record(blocker) &&
                blocker.ticket_id === ticket.id &&
                blocker.viewer_user_id === actorId &&
                (blocker.kind === 'task' || blocker.kind === 'approval') &&
                (positive(blocker.record_id) ||
                    (blocker.kind === 'approval' && blocker.record_id === null))
            ) {
                setWorkBlocker({
                    kind: blocker.kind,
                    id: blocker.record_id as number | null,
                });
            }
            const fieldErrors: Record<string, string> = {};
            if (record(body.errors))
                for (const key of [
                    'note',
                    'resolution_code',
                    'resolution_verification',
                    'notify_requester',
                    'expected_version',
                    'actor_user_id',
                    'draft_uuid',
                    'draft_revision',
                ]) {
                    const value = body.errors[key];
                    const text = Array.isArray(value)
                        ? value.find((item) => typeof item === 'string')
                        : value;
                    if (typeof text === 'string') fieldErrors[key] = text;
                }
            setErrors(fieldErrors);
            setMessage(
                typeof body.message === 'string'
                    ? body.message
                    : 'Check the resolution details. Your note is retained.',
            );
            setState('editing');
            setUnconfirmed(false);
            setSettledOperationToken((token) => token + 1);
            submitted.current = null;
            return;
        }
        setErrors({});
        setState('unknown');
        setMessage(
            response?.status === 409
                ? 'The ticket or draft changed. Review the current ticket before applying your retained note.'
                : phase === 'review'
                  ? 'Current details could not be confirmed. Your note is retained. Try reviewing again.'
                  : 'The resolution result is unknown. The request may have finished. Review the current ticket before trying again.',
        );
    };

    const submit = async () => {
        if (controller.current || !valid || actorId === undefined) return;
        const draftReference = draftsEnabled
            ? draft.submissionReference(snapshot)
            : null;
        if (draftsEnabled && !draftReference) {
            setMessage(
                'Save and review this exact draft before resolving the ticket.',
            );
            return;
        }
        const payload = {
            note,
            resolution_code: outcome,
            resolution_verification: verification,
            notify_requester: notify,
            expected_version: version,
            actor_user_id: actorId,
            ...(draftReference ?? {}),
        };
        submitted.current = payload;
        const request = new AbortController();
        controller.current = request;
        const token = ++epoch.current;
        setState('submitting');
        setWorkBlocker(null);
        setUnconfirmed(true);
        setMessage(null);
        setErrors({});
        setCurrent(null);
        try {
            const response = await axios.post(
                `/it/tickets/${ticket.id}/resolve`,
                payload,
                {
                    headers: { Accept: 'application/json' },
                    signal: request.signal,
                    timeout: 30000,
                },
            );
            if (epoch.current !== token || request.signal.aborted) return;
            const body: unknown = response.data;
            if (
                record(body) &&
                record(body.data) &&
                positive(body.data.viewer_user_id) &&
                body.data.viewer_user_id !== actorId
            ) {
                deny(
                    'Your signed-in account changed. Reload the ticket before continuing.',
                );
                return;
            }
            if (
                response.status !== 200 ||
                !record(body) ||
                body.status !== 'committed' ||
                !record(body.data) ||
                body.data.id !== ticket.id ||
                body.data.viewer_user_id !== actorId ||
                !positive(body.data.lock_version) ||
                body.data.lock_version <= payload.expected_version ||
                !record(body.data.resolution) ||
                body.data.resolution.code !== payload.resolution_code ||
                body.data.resolution.summary !== payload.note.trim() ||
                body.data.resolution.verification !==
                    payload.resolution_verification.trim()
            )
                throw new Error('Unconfirmed resolution acknowledgement');
            if (
                draftReference &&
                !draft.acknowledgeConsumed(body.data.draft, snapshot)
            )
                throw new Error('Unconfirmed draft consumption');
            if (!draftReference && body.data.draft !== undefined)
                throw new Error('Unexpected draft acknowledgement');
            setState('done');
            setUnconfirmed(false);
            draft.clearOwnedBrowserWork();
            setMessage(null);
        } catch (error) {
            if (epoch.current === token && !request.signal.aborted)
                failure(error, 'submit');
        } finally {
            if (epoch.current === token) controller.current = null;
        }
    };

    const review = async () => {
        if (controller.current || actorId === undefined) return;
        const request = new AbortController();
        controller.current = request;
        const token = ++epoch.current;
        setState('reviewing');
        setMessage(null);
        setErrors({});
        setCurrent(null);
        try {
            const response = await axios.get(`/it/tickets/${ticket.id}`, {
                headers: { Accept: 'application/json' },
                signal: request.signal,
                timeout: 30000,
            });
            if (epoch.current !== token || request.signal.aborted) return;
            const body: unknown = response.data;
            if (response.status !== 200)
                throw new Error('Unconfirmed current ticket response');
            if (!record(body) || body.viewer_user_id !== actorId) {
                deny(
                    'Your signed-in account changed. Reload the ticket before continuing.',
                );
                return;
            }
            const item = body.ticket;
            if (
                !record(item) ||
                item.id !== ticket.id ||
                !positive(item.lock_version) ||
                typeof item.title !== 'string' ||
                typeof item.status !== 'string' ||
                !record(body.can) ||
                typeof body.can.manage !== 'boolean'
            )
                throw new Error('Invalid current ticket');
            if (!body.can.manage) {
                deny(
                    'Your current account can no longer resolve this ticket. The entered note is concealed.',
                );
                return;
            }
            const saved = item.resolution;
            if (
                saved != null &&
                (!record(saved) ||
                    typeof saved.code !== 'string' ||
                    (saved.summary !== null &&
                        typeof saved.summary !== 'string') ||
                    (saved.verification !== null &&
                        typeof saved.verification !== 'string'))
            )
                throw new Error('Invalid current resolution');
            setCurrent({
                id: ticket.id,
                lock_version: item.lock_version,
                title: item.title,
                reference:
                    typeof item.reference === 'string' ? item.reference : null,
                status: item.status,
                canManage: true,
                resolution:
                    saved == null
                        ? null
                        : {
                              code: saved.code as string,
                              summary: saved.summary as string | null,
                              verification: saved.verification as string | null,
                          },
            });
            setState('reviewed');
            setMessage(
                working(item.status)
                    ? 'Review the current ticket. Keeping your note does not submit it.'
                    : 'This ticket is already settled. Check its conversation to confirm which resolution was recorded; this dialog cannot confirm an earlier unknown request.',
            );
        } catch (error) {
            if (epoch.current === token && !request.signal.aborted)
                failure(error, 'review');
        } finally {
            if (epoch.current === token) controller.current = null;
        }
    };

    const cancelWait = () => {
        ++epoch.current;
        controller.current?.abort();
        controller.current = null;
        setCurrent(null);
        setState('unknown');
        setMessage(
            'The wait was cancelled. A submitted resolution may still finish; review the current ticket before trying again.',
        );
    };
    const close = () => {
        const run = () => {
            onCloseRef.current();
            if (state === 'done') router.reload({ preserveScroll: true });
        };
        if ((dirty && !draftSaved) || busy || draft.busy || unconfirmed)
            setLeave({ run, navigation: false });
        else run();
    };

    return (
        <>
            <WizardShell
                open
                onClose={close}
                title="Resolve ticket"
                description={
                    concealed
                        ? 'Resolution access needs recovery'
                        : `${ticket.reference ?? 'Ticket'} — ${ticket.title}`
                }
                railIcon={CheckCircle2}
                railTitle="Resolve"
                railSub={
                    concealed
                        ? 'IT & Support'
                        : (ticket.reference ?? 'IT helpdesk')
                }
                steps={STEPS}
                stepIndex={0}
                onStepClick={() => undefined}
                pct={
                    concealed
                        ? 0
                        : Math.round(
                              ([
                                  note.trim().length > 0,
                                  validOutcome(outcome),
                                  verification.trim().length > 0,
                              ].filter(Boolean).length /
                                  3) *
                                  100,
                          )
                }
                success={
                    state === 'done' ? (
                        <WizardSuccessPane
                            title="Resolved"
                            blurb="The resolution and public note are saved on the ticket. Notification delivery is tracked separately."
                            actions={
                                <>
                                    {onDraftKb ? (
                                        <Button
                                            variant="outline"
                                            onClick={() =>
                                                onDraftKb({
                                                    title: ticket.title,
                                                    body: `${submitted.current?.note ?? note}\n\nHow it was checked: ${submitted.current?.resolution_verification ?? verification}`,
                                                })
                                            }
                                        >
                                            <BookOpen className="size-4" />
                                            Draft KB article
                                        </Button>
                                    ) : null}
                                    <Button asChild variant="outline">
                                        <a href={`/it/tickets/${ticket.id}`}>
                                            View resolved ticket
                                        </a>
                                    </Button>
                                    <Button onClick={close}>Done</Button>
                                </>
                            }
                        />
                    ) : undefined
                }
                footerEnd={
                    <>
                        <Button variant="ghost" onClick={close}>
                            Cancel
                        </Button>
                        {busy ? (
                            <Button variant="outline" onClick={cancelWait}>
                                Cancel wait
                            </Button>
                        ) : null}
                        <Button
                            onClick={() => void submit()}
                            disabled={!valid || busy}
                        >
                            {state === 'submitting'
                                ? 'Resolving…'
                                : 'Resolve ticket'}
                        </Button>
                    </>
                }
            >
                <WizardStepPane>
                    {state !== 'access' && state !== 'session' ? (
                        <fieldset disabled={state !== 'editing'}>
                            <TicketDraftRecovery
                                draft={draft}
                                snapshot={snapshot}
                                hasLocalChanges={dirty}
                                onResumeMemory={(restored) => {
                                    const fields = restored.snapshot.fields;
                                    if (
                                        restored.files.length ||
                                        (fields.note !== undefined &&
                                            typeof fields.note !== 'string') ||
                                        (fields.resolution_code != null &&
                                            fields.resolution_code !== '' &&
                                            !validOutcome(
                                                fields.resolution_code,
                                            )) ||
                                        (fields.resolution_verification !=
                                            null &&
                                            typeof fields.resolution_verification !==
                                                'string') ||
                                        (fields.notify_requester !==
                                            undefined &&
                                            typeof fields.notify_requester !==
                                                'boolean') ||
                                        !positive(
                                            restored.snapshot
                                                .base_ticket_version,
                                        )
                                    ) {
                                        setMessage(
                                            'The retained resolution could not be confirmed. Open its matching editor before continuing.',
                                        );
                                        return;
                                    }
                                    setNote(fields.note ?? '');
                                    setOutcome(fields.resolution_code ?? '');
                                    setVerification(
                                        fields.resolution_verification ?? '',
                                    );
                                    setNotify(fields.notify_requester ?? true);
                                    setVersion(
                                        restored.snapshot.base_ticket_version,
                                    );
                                    setErrors({});
                                    setCurrent(null);
                                    setUnconfirmed(
                                        restored.canonicalOutcomeUnknown,
                                    );
                                    setState(
                                        restored.canonicalOutcomeUnknown ||
                                            restored.blocker
                                            ? 'unknown'
                                            : 'editing',
                                    );
                                    setMessage(
                                        restored.canonicalOutcomeUnknown
                                            ? 'Your note is restored, but its earlier resolution is still unconfirmed. Review the current ticket before any further submission.'
                                            : (restored.blocker?.message ??
                                                  null),
                                    );
                                }}
                                onResume={(saved) => {
                                    const fields = saved.payload.fields;
                                    const savedVersion =
                                        resumedDraftBaseVersion(saved);
                                    if (
                                        saved.attachments.length ||
                                        (fields.note !== undefined &&
                                            typeof fields.note !== 'string') ||
                                        (fields.resolution_code != null &&
                                            fields.resolution_code !== '' &&
                                            !validOutcome(
                                                fields.resolution_code,
                                            )) ||
                                        (fields.resolution_verification !=
                                            null &&
                                            typeof fields.resolution_verification !==
                                                'string') ||
                                        (fields.notify_requester !==
                                            undefined &&
                                            typeof fields.notify_requester !==
                                                'boolean') ||
                                        !positive(savedVersion)
                                    ) {
                                        setMessage(
                                            'The saved resolution draft could not be confirmed. Review it before continuing.',
                                        );
                                        return;
                                    }
                                    setNote(fields.note ?? '');
                                    setOutcome(fields.resolution_code ?? '');
                                    setVerification(
                                        fields.resolution_verification ?? '',
                                    );
                                    setNotify(fields.notify_requester ?? true);
                                    setVersion(savedVersion);
                                    setErrors({});
                                    setMessage(null);
                                }}
                                onDiscarded={() => {
                                    setNote('');
                                    setOutcome('');
                                    setVerification('');
                                    setNotify(true);
                                    setErrors({});
                                    setMessage(null);
                                }}
                                onStartNew={() => {
                                    setNote('');
                                    setOutcome('');
                                    setVerification('');
                                    setNotify(true);
                                    setErrors({});
                                    setMessage(null);
                                }}
                                renderReview={(saved) => (
                                    <>
                                        <p>
                                            Outcome:{' '}
                                            {OUTCOMES.find(
                                                (item) =>
                                                    item.key ===
                                                    saved.payload.fields
                                                        .resolution_code,
                                            )?.label ?? 'Not selected'}
                                        </p>
                                        <p className="break-words whitespace-pre-wrap">
                                            {typeof saved.payload.fields
                                                .note === 'string'
                                                ? saved.payload.fields.note
                                                : 'No saved note'}
                                        </p>
                                        <p className="break-words whitespace-pre-wrap">
                                            How it was checked:{' '}
                                            {saved.payload.fields
                                                .resolution_verification ||
                                                'Not recorded'}
                                        </p>
                                        <p>
                                            Saved ticket version:{' '}
                                            {saved.draft.base_ticket_version ??
                                                'Unavailable'}
                                        </p>
                                    </>
                                )}
                            />
                            {(draft.draft?.blocker || draft.browserBlocker) &&
                            state === 'editing' ? (
                                <Button
                                    variant="outline"
                                    onClick={() => void review()}
                                >
                                    Review current ticket
                                </Button>
                            ) : null}
                        </fieldset>
                    ) : null}
                    {message || Object.keys(errors).length ? (
                        <Alert ref={alertRef} tabIndex={-1}>
                            <AlertTitle>
                                {concealed
                                    ? 'Resolution access unavailable'
                                    : state === 'editing'
                                      ? 'Resolution needs attention'
                                      : 'Review the resolution outcome'}
                            </AlertTitle>
                            <AlertDescription>
                                {message ? <p>{message}</p> : null}
                                {Object.values(errors).map((error, index) => (
                                    <p key={index}>{error}</p>
                                ))}
                                {workBlocker &&
                                    !concealed &&
                                    state === 'editing' && (
                                        <Button asChild variant="outline">
                                            <a
                                                href={`/it/tickets/${ticket.id}?tab=${workBlocker.kind === 'task' ? 'tasks' : 'approvals'}${workBlocker.id === null ? '' : `#${workBlocker.kind}-${workBlocker.id}`}`}
                                                target="_blank"
                                                rel="noreferrer"
                                            >
                                                {workBlocker.kind === 'task'
                                                    ? 'Review required task'
                                                    : 'Review required approval'}{' '}
                                                (opens new tab)
                                            </a>
                                        </Button>
                                    )}
                                {state === 'session' ? (
                                    <Button asChild variant="outline">
                                        <a
                                            href="/login"
                                            target="_blank"
                                            rel="noreferrer"
                                        >
                                            Sign in
                                        </a>
                                    </Button>
                                ) : null}
                                {state === 'access' ? (
                                    <Button asChild variant="outline">
                                        <a href={`/it/tickets/${ticket.id}`}>
                                            Reload ticket
                                        </a>
                                    </Button>
                                ) : null}
                                {state === 'unknown' || state === 'session' ? (
                                    <Button
                                        variant="outline"
                                        onClick={() => void review()}
                                    >
                                        Review current ticket
                                    </Button>
                                ) : null}
                            </AlertDescription>
                        </Alert>
                    ) : null}
                    {state === 'reviewing' ? (
                        <p role="status">Loading the current ticket…</p>
                    ) : null}
                    {current && state === 'reviewed' ? (
                        <section
                            className="space-y-3 rounded-lg border border-border bg-muted/30 p-4"
                            aria-label="Current ticket review"
                        >
                            <p className="font-semibold">
                                {current.reference ?? 'Ticket'} —{' '}
                                {current.title}
                            </p>
                            <p>
                                Current status:{' '}
                                {current.status.replaceAll('_', ' ')} · Version{' '}
                                {current.lock_version}
                            </p>
                            {current.resolution && (
                                <div className="space-y-2 border-t border-border pt-3">
                                    <p className="font-medium">
                                        Recorded resolution:{' '}
                                        {OUTCOMES.find(
                                            (item) =>
                                                item.key ===
                                                current.resolution?.code,
                                        )?.label ?? current.resolution.code}
                                    </p>
                                    <p className="break-words whitespace-pre-wrap">
                                        {current.resolution.summary ??
                                            'No explanation recorded.'}
                                    </p>
                                    <p className="break-words whitespace-pre-wrap">
                                        How it was checked:{' '}
                                        {current.resolution.verification ??
                                            'Not recorded for this resolution.'}
                                    </p>
                                </div>
                            )}
                            <Button asChild variant="outline">
                                <a
                                    href={`/it/tickets/${ticket.id}`}
                                    target="_blank"
                                    rel="noreferrer"
                                >
                                    Open current ticket in a new tab
                                </a>
                            </Button>
                            {working(current.status) ? (
                                <Button
                                    variant="outline"
                                    onClick={() => {
                                        if (
                                            (draft.browserOutcomeUnknown ||
                                                draft.browserBlocker) &&
                                            !draft.acknowledgeReviewedBrowserWork(
                                                current.lock_version,
                                            )
                                        )
                                            return;
                                        setVersion(current.lock_version);
                                        setState('editing');
                                        setUnconfirmed(false);
                                        setSettledOperationToken(
                                            (token) => token + 1,
                                        );
                                        setCurrent(null);
                                        setMessage(null);
                                        submitted.current = null;
                                    }}
                                >
                                    Use this version and keep my note
                                </Button>
                            ) : null}
                        </section>
                    ) : null}
                    {!concealed ? (
                        <>
                            <StepHead
                                icon={CheckCircle2}
                                title="What fixed it?"
                                blurb="Your explanation and verification become a public reply that the requester can read."
                            />
                            <fieldset
                                disabled={locked}
                                className="grid gap-3.5"
                            >
                                <Field
                                    label="Resolution outcome"
                                    required
                                    error={errors.resolution_code}
                                >
                                    <TilePicker
                                        value={outcome}
                                        onChange={setOutcome}
                                        options={OUTCOMES}
                                    />
                                </Field>
                                <Field
                                    label="Resolution note"
                                    required
                                    error={errors.note}
                                >
                                    <Textarea
                                        value={note}
                                        onChange={(event) =>
                                            setNote(event.target.value)
                                        }
                                        maxLength={5000}
                                        rows={5}
                                        placeholder="Explain what changed or what the requester should do."
                                        aria-invalid={!!errors.note}
                                        autoFocus
                                    />
                                </Field>
                                <Field
                                    label="How was it checked?"
                                    required
                                    error={errors.resolution_verification}
                                >
                                    <Textarea
                                        value={verification}
                                        onChange={(event) =>
                                            setVerification(event.target.value)
                                        }
                                        maxLength={5000}
                                        rows={3}
                                        placeholder="Describe the checks performed and the result. Include any limits or follow-up needed."
                                        aria-invalid={
                                            !!errors.resolution_verification
                                        }
                                    />
                                </Field>
                                <label className="flex min-h-11 items-center gap-2 text-sm font-medium">
                                    <Checkbox
                                        checked={notify}
                                        onCheckedChange={(value) =>
                                            setNotify(value === true)
                                        }
                                    />
                                    Notify the requester about the resolution
                                </label>
                            </fieldset>
                        </>
                    ) : null}
                </WizardStepPane>
            </WizardShell>
            <ConfirmDialog
                open={leave !== null}
                onClose={() => setLeave(null)}
                onConfirm={() => {
                    if (!leave) return;
                    draft.clearOwnedBrowserWork();
                    approvedNavigation.current = true;
                    leave.run();
                    approvedNavigation.current = false;
                }}
                title={
                    unconfirmed
                        ? 'Leave with an unconfirmed outcome?'
                        : 'Discard the resolution note?'
                }
                description={
                    unconfirmed
                        ? 'The request may still finish. Leaving does not cancel or undo a resolution. Your local note will be discarded; check the ticket before sending again.'
                        : draftsEnabled
                          ? 'Unsaved changes in this form will be discarded. Any earlier saved draft remains available to resume. Cancel to keep working here.'
                          : 'The entered note will be discarded. Cancel to keep working on it.'
                }
                confirmText={
                    leave?.navigation
                        ? 'Discard and continue'
                        : 'Discard and close'
                }
            />
        </>
    );
}
