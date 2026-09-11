import { ConfirmDialog } from '@/components/confirm-dialog';
import { Button } from '@/components/ui/button';
import type {
    ItDraftFields,
    ItDraftSnapshot,
} from '@/hooks/it-ticket-draft-contract';
import { useItTicketDraft } from '@/hooks/use-it-ticket-draft';
import { router } from '@inertiajs/react';
import axios from 'axios';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import {
    ItBulkResultPanel,
    readItBulkResult,
    type ItBulkResult,
} from './it-bulk-result';
import { TicketDraftRecovery } from './ticket-draft-recovery';
import {
    TicketVersionConflict,
    type TicketVersions,
} from './ticket-version-conflict';

type State = 'editing' | 'sending' | 'unknown' | 'session' | 'access' | 'done';
const record = (value: unknown): value is Record<string, unknown> =>
    !!value && typeof value === 'object' && !Array.isArray(value);
const positive = (value: unknown): value is number =>
    Number.isSafeInteger(value) && (value as number) > 0;

/** Shared browser transport; canonical ticket services own every write. */
export function useTicketFormCommand({
    actorId,
    ticketIds,
    expectedVersions,
    fields,
    dirty,
    bulk = false,
    operation = 'update',
    draftsEnabled = false,
    acceptedFields,
    acceptsFields,
    onResume,
    onClear,
    onClose,
    onCommitted,
    onBulkResult,
    onCloseAutoFocus,
}: {
    actorId: number | undefined;
    ticketIds: number[];
    expectedVersions: TicketVersions;
    fields: ItDraftFields;
    dirty: boolean;
    bulk?: boolean;
    operation?: 'update' | 'close';
    draftsEnabled?: boolean;
    acceptedFields?: readonly string[];
    acceptsFields?: (fields: ItDraftFields) => boolean;
    onResume: (fields: ItDraftFields) => boolean;
    onClear: () => void;
    onClose: () => void;
    onCommitted: () => void;
    onBulkResult?: (result: ItBulkResult) => void;
    onCloseAutoFocus?: (event: Event) => void;
}) {
    const [origin] = useState(() => ({ actorId, ids: [...ticketIds] }));
    const [versions, setVersions] = useState(() => ({ ...expectedVersions }));
    const [state, setState] = useState<State>('editing');
    const [message, setMessage] = useState<string | null>(null);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [outcome, setOutcome] = useState<ItBulkResult | null>(null);
    const [resumeRejected, setResumeRejected] = useState(false);
    const [settledOperation, setSettledOperation] = useState(0);
    const [leave, setLeave] = useState<(() => void) | null>(null);
    const epoch = useRef(0);
    const request = useRef<AbortController | null>(null);
    const currentActor = useRef(actorId);
    currentActor.current = actorId;
    const callbacks = useRef({
        onClear,
        onClose,
        onCommitted,
        onBulkResult,
        onResume,
    });
    callbacks.current = {
        onClear,
        onClose,
        onCommitted,
        onBulkResult,
        onResume,
    };
    const approvedNavigation = useRef(false);
    const alert = useRef<HTMLDivElement>(null);
    const outcomeRef = useRef(outcome);
    outcomeRef.current = outcome;
    const reported = useRef<ItBulkResult | null>(null);
    const reportOutcome = useCallback(() => {
        if (outcomeRef.current && reported.current !== outcomeRef.current) {
            reported.current = outcomeRef.current;
            callbacks.current.onBulkResult?.(outcomeRef.current);
        }
    }, []);
    const scopeChanged =
        actorId !== origin.actorId ||
        (!bulk && ticketIds.join(',') !== origin.ids.join(','));
    const deny = useCallback(() => {
        ++epoch.current;
        request.current?.abort();
        request.current = null;
        setState('access');
        setErrors({});
        setOutcome(null);
        setLeave(null);
        setMessage(
            'Your account, selection or ticket access changed. The entered details are concealed. Reload before continuing.',
        );
        callbacks.current.onClear();
    }, []);
    useEffect(() => {
        if (scopeChanged) deny();
    }, [scopeChanged, deny]);
    useEffect(
        () => () => {
            ++epoch.current;
            request.current?.abort();
        },
        [],
    );
    useEffect(() => {
        if (!message && !Object.keys(errors).length) return;
        const timer = window.setTimeout(() => alert.current?.focus(), 0);
        return () => window.clearTimeout(timer);
    }, [message, errors]);
    const closing = operation === 'close';
    const persistent = draftsEnabled && !bulk && !closing;
    const context = useMemo(
        () => ({
            purpose: 'ticket_edit' as const,
            ticketId: origin.ids[0] ?? 0,
        }),
        [origin],
    );
    const snapshot: ItDraftSnapshot = useMemo(
        () => ({
            fields,
            step_index: 0,
            base_ticket_version: versions[origin.ids[0]] ?? null,
        }),
        [fields, versions, origin],
    );
    const draft = useItTicketDraft({
        enabled: persistent,
        actorId: origin.actorId,
        context,
        active:
            !closing &&
            !bulk &&
            state !== 'access' &&
            state !== 'done' &&
            !scopeChanged,
        onAccessLost: deny,
        workingSnapshot: snapshot,
        workingDirty: dirty,
        workingOutcomeUnknown: ['sending', 'unknown', 'session'].includes(
            state,
        ),
        workingSettledOperationToken: settledOperation,
        acceptedFields,
        acceptsSnapshot: (candidate) =>
            positive(candidate.base_ticket_version) &&
            (acceptsFields?.(candidate.fields) ?? true),
    });
    const saved = persistent && draft.isSaved(snapshot);
    // A different editor's draft remains recoverable, but must not be overwritten
    // through this form's manual save control after its payload was rejected.
    const displayedDraft =
        resumeRejected && draft.draft
            ? {
                  ...draft,
                  draft: {
                      ...draft.draft,
                      capabilities: {
                          ...draft.draft.capabilities,
                          save: false,
                          submit: false,
                      },
                  },
              }
            : draft;
    const busy = state === 'sending';
    const concealed =
        scopeChanged ||
        state === 'access' ||
        state === 'session' ||
        draft.state === 'session_expired' ||
        draft.state === 'access_denied';
    const locked =
        concealed ||
        state !== 'editing' ||
        (!closing && draft.memoryBlocked) ||
        (persistent &&
            (draft.state !== 'ready' || draft.busy || resumeRejected));
    const ready =
        !locked &&
        positive(origin.actorId) &&
        origin.ids.length > 0 &&
        origin.ids.every((id) => positive(versions[id])) &&
        (!persistent || (saved && draft.draft?.capabilities.submit === true));
    const save = draft.save;
    useEffect(() => {
        if (
            !persistent ||
            state !== 'editing' ||
            resumeRejected ||
            !dirty ||
            draft.state !== 'ready' ||
            draft.busy ||
            draft.memoryBlocked ||
            saved ||
            Object.keys(draft.errors).length
        )
            return;
        const timer = window.setTimeout(() => void save(snapshot), 800);
        return () => window.clearTimeout(timer);
    }, [
        persistent,
        state,
        resumeRejected,
        dirty,
        draft.state,
        draft.busy,
        draft.memoryBlocked,
        saved,
        draft.errors,
        save,
        snapshot,
    ]);

    const unconfirmed =
        busy || (['unknown', 'session'].includes(state) && outcome === null);
    const requiresConfirmation =
        (dirty && !saved) || busy || draft.busy || unconfirmed;
    const close = () => {
        if (requiresConfirmation)
            setLeave(() => () => callbacks.current.onClose());
        else callbacks.current.onClose();
    };
    useEffect(() => {
        if (!requiresConfirmation || state === 'access' || scopeChanged) return;
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
            setLeave(() => () => {
                approvedNavigation.current = true;
                router.visit(visit.url, visit);
            });
        });
        return () => {
            window.removeEventListener('beforeunload', beforeUnload);
            remove();
        };
    }, [requiresConfirmation, state, scopeChanged]);
    const cancelWait = () => {
        ++epoch.current;
        request.current?.abort();
        request.current = null;
        setState('unknown');
        setMessage(
            'The wait stopped. The request may still finish; review the current state before applying anything again.',
        );
    };
    const submit = async () => {
        if (!ready || request.current) return;
        const original = structuredClone(snapshot);
        const reference = persistent
            ? draft.submissionReference(original)
            : null;
        if (persistent && !reference) return;
        const controller = new AbortController();
        request.current = controller;
        const token = ++epoch.current;
        const same = () =>
            epoch.current === token && currentActor.current === origin.actorId;
        setState('sending');
        setMessage(null);
        setErrors({});
        setOutcome(null);
        const payload = bulk
            ? {
                  ...original.fields,
                  actor_user_id: origin.actorId,
                  ids: origin.ids,
                  expected_versions: { ...versions },
                  action: closing ? 'close' : 'status',
              }
            : {
                  ...original.fields,
                  actor_user_id: origin.actorId,
                  expected_version: versions[origin.ids[0]],
                  ...(reference ?? {}),
              };
        try {
            const response = await axios.request({
                method: bulk || closing ? 'post' : 'patch',
                url: bulk
                    ? '/it/tickets/bulk'
                    : `/it/tickets/${origin.ids[0]}${closing ? '/close' : ''}`,
                data: payload,
                headers: { Accept: 'application/json' },
                timeout: 30000,
                signal: controller.signal,
            });
            if (!same()) return;
            const body: unknown = response.data;
            const viewer = record(body)
                ? bulk
                    ? body.viewer_user_id
                    : record(body.data)
                      ? body.data.viewer_user_id
                      : null
                : null;
            if (positive(viewer) && viewer !== origin.actorId) {
                deny();
                return;
            }
            if (
                response.status !== 200 ||
                !record(body) ||
                viewer !== origin.actorId
            )
                throw new Error('Unconfirmed response');
            if (bulk) {
                const result = readItBulkResult(body.result, 'tickets', {
                    action: closing ? 'close' : 'status',
                    ids: origin.ids,
                });
                if (
                    body.status !== 'completed' ||
                    !result ||
                    result.action !== (closing ? 'close' : 'status') ||
                    result.selected !== origin.ids.length ||
                    result.items.some(
                        (item, index) => item.id !== origin.ids[index],
                    ) ||
                    result.updated !==
                        result.items.filter((item) => item.status === 'updated')
                            .length ||
                    result.unchanged !==
                        result.items.filter(
                            (item) => item.status === 'unchanged',
                        ).length
                )
                    throw new Error('Unconfirmed selection');
                setOutcome(result);
                if (result.rejected > 0) {
                    setState('unknown');
                    setMessage(
                        'Some selected tickets were not changed. Review the permitted list and choose a new selection; this form will not retry automatically.',
                    );
                    return;
                }
                reported.current = result;
                callbacks.current.onBulkResult?.(result);
            } else {
                if (
                    body.status !== 'committed' ||
                    !record(body.data) ||
                    body.data.id !== origin.ids[0] ||
                    !positive(body.data.lock_version) ||
                    body.data.lock_version < versions[origin.ids[0]]
                )
                    throw new Error('Unconfirmed commit');
                if (
                    closing &&
                    (body.data.operation !== 'ticket.close' ||
                        body.data.status !== 'closed' ||
                        body.data.reason !== original.fields.reason ||
                        body.data.lock_version <= versions[origin.ids[0]])
                )
                    throw new Error('Unconfirmed close');
                if (
                    reference &&
                    !draft.acknowledgeConsumed(body.data.draft, original)
                )
                    throw new Error('Unconfirmed consumed draft');
                if (!reference && body.data.draft !== undefined)
                    throw new Error('Unexpected consumed draft');
            }
            setState('done');
            if (!closing) draft.clearOwnedBrowserWork();
            approvedNavigation.current = true;
            callbacks.current.onCommitted();
        } catch (error) {
            if (!same()) return;
            const response = axios.isAxiosError(error)
                ? error.response
                : undefined;
            if ([403, 404].includes(response?.status ?? 0)) {
                deny();
                return;
            }
            if ([401, 419].includes(response?.status ?? 0)) {
                setState('session');
                setMessage(
                    'Sign in with the same account, then review the current ticket or selection before continuing.',
                );
            } else {
                const body = record(response?.data) ? response.data : {};
                const nextErrors: Record<string, string> = {};
                if (record(body.errors))
                    for (const [key, raw] of Object.entries(body.errors)) {
                        const text = Array.isArray(raw) ? raw[0] : raw;
                        if (typeof text === 'string' && text.length <= 2000)
                            nextErrors[key] = text;
                    }
                setErrors(nextErrors);
                if (response?.status === 422)
                    setSettledOperation((value) => value + 1);
                setState(
                    response?.status === 422 &&
                        (!closing || typeof nextErrors.reason === 'string')
                        ? 'editing'
                        : 'unknown',
                );
                setMessage(
                    response?.status === 422
                        ? typeof body.message === 'string' &&
                          body.message.length <= 2000
                            ? body.message
                            : 'The changes were rejected. Correct the retained details before trying again.'
                        : response?.status === 409
                          ? 'The ticket or saved draft changed. Review the current version before applying the retained details.'
                          : 'The outcome is unconfirmed. The request may have finished; review the current state before applying anything again.',
                );
            }
        } finally {
            if (same()) request.current = null;
        }
    };
    const recovery = (
        <>
            {(message || Object.keys(errors).length > 0) && (
                <div
                    role="alert"
                    ref={alert}
                    tabIndex={-1}
                    className="space-y-2 text-sm text-status-critical"
                >
                    <p>{message}</p>
                    {Object.entries(errors).map(([key, text]) => (
                        <p key={key}>{text}</p>
                    ))}
                    {state === 'session' && (
                        <Button asChild variant="outline">
                            <a href="/login" target="_blank" rel="noreferrer">
                                Sign in again
                            </a>
                        </Button>
                    )}
                    {state === 'access' && (
                        <Button asChild variant="outline">
                            <a
                                href={
                                    bulk
                                        ? '/it?tab=tickets'
                                        : `/it/tickets/${origin.ids[0]}`
                                }
                            >
                                Reload current work
                            </a>
                        </Button>
                    )}
                </div>
            )}
            {bulk && <ItBulkResultPanel result={outcome} />}
            {bulk && ['unknown', 'session'].includes(state) && (
                <Button
                    variant="outline"
                    onClick={() =>
                        setLeave(() => () => {
                            approvedNavigation.current = true;
                            router.visit('/it?tab=tickets');
                        })
                    }
                >
                    Review current list
                </Button>
            )}
            {!bulk && !busy && state !== 'access' && (
                <TicketVersionConflict
                    actorId={origin.actorId}
                    ticketId={origin.ids[0]}
                    error={
                        ['unknown', 'session'].includes(state) ||
                        draft.browserBlocker !== null ||
                        (draft.current ?? draft.draft)?.blocker?.code ===
                            'ticket_changed'
                            ? 'Review current access and the saved version before applying these details.'
                            : undefined
                    }
                    onAccessLost={deny}
                    onSessionLost={() => setState('session')}
                    onReviewed={(version, current) => {
                        if (current?.status === 'closed') {
                            setState('unknown');
                            setMessage(
                                closing
                                    ? 'This ticket is already closed. No further close request was sent. Review its timeline before leaving this editor; your reason remains here.'
                                    : 'This ticket is closed. Reopen it through its ticket page before changing these details. Your entered evidence remains here.',
                            );
                            return;
                        }
                        setVersions({ [origin.ids[0]]: version });
                        if (!closing)
                            draft.acknowledgeReviewedBrowserWork(version);
                        setState('editing');
                        setErrors({});
                        setMessage(
                            'Current ticket reviewed. Check the retained details and save explicitly.',
                        );
                    }}
                />
            )}
            {!closing && !bulk && state === 'editing' && (
                <TicketDraftRecovery
                    draft={displayedDraft}
                    snapshot={snapshot}
                    hasLocalChanges={dirty}
                    onResumeMemory={(resumed) => {
                        const version = resumed.snapshot.base_ticket_version;
                        if (
                            !positive(version) ||
                            resumed.files.length ||
                            !callbacks.current.onResume(resumed.snapshot.fields)
                        )
                            return;
                        setVersions({ [origin.ids[0]]: version });
                        setResumeRejected(false);
                        setErrors({});
                        setState(
                            resumed.canonicalOutcomeUnknown
                                ? 'unknown'
                                : 'editing',
                        );
                        setMessage(
                            resumed.canonicalOutcomeUnknown
                                ? 'The earlier request is unconfirmed. Review the current ticket before applying these retained details.'
                                : 'Browser details restored with their original ticket version. Review them before saving.',
                        );
                    }}
                    onResume={(resumed) => {
                        if (
                            !positive(resumed.draft.base_ticket_version) ||
                            resumed.attachments.length ||
                            !callbacks.current.onResume(resumed.payload.fields)
                        ) {
                            setResumeRejected(true);
                            setMessage(
                                'This saved draft contains different ticket properties. Resume it in the property editor, or discard the saved draft before starting these changes.',
                            );
                            return;
                        }
                        setResumeRejected(false);
                        setVersions({
                            [origin.ids[0]]: resumed.draft.base_ticket_version,
                        });
                        setErrors({});
                        setMessage(null);
                    }}
                    onDiscarded={() => {
                        setResumeRejected(false);
                        setMessage(
                            'Saved draft discarded. Local details remain here; start a new draft to save them.',
                        );
                    }}
                    onStartNew={() => {
                        setResumeRejected(false);
                        setMessage(null);
                    }}
                    renderReview={(resumed) =>
                        !concealed && (
                            <dl className="space-y-2">
                                {Object.entries(resumed.payload.fields).map(
                                    ([key, value]) => (
                                        <div key={key}>
                                            <dt className="font-medium">
                                                {key.replaceAll('_', ' ')}
                                            </dt>
                                            <dd className="break-words whitespace-pre-wrap">
                                                {value === null
                                                    ? 'Clear value'
                                                    : String(value)}
                                            </dd>
                                        </div>
                                    ),
                                )}
                            </dl>
                        )
                    }
                />
            )}
        </>
    );
    const confirmation = (
        <ConfirmDialog
            open={leave !== null}
            onClose={() => setLeave(null)}
            onCloseAutoFocus={onCloseAutoFocus}
            onConfirm={() => {
                ++epoch.current;
                request.current?.abort();
                request.current = null;
                reportOutcome();
                leave?.();
            }}
            title={
                unconfirmed
                    ? 'Leave with an unconfirmed outcome?'
                    : 'Leave these unsaved changes?'
            }
            description={`${unconfirmed ? 'A submitted request may still finish. Leaving does not cancel or undo it. ' : ''}${closing ? 'Leaving discards this browser copy of the closing reason. It does not undo any recorded closure. Cancel to keep editing.' : saved ? 'The saved draft remains available to resume.' : bulk ? 'Unsaved local details will be discarded. Any earlier saved draft remains available.' : 'Unsaved details remain in this open application for explicit recovery after checking your access. A full reload or closing the application loses unsaved browser work.'}`}
            confirmText="Leave form"
        />
    );
    return {
        state,
        errors,
        busy,
        concealed,
        locked,
        ready,
        saved,
        versions,
        submit,
        close,
        cancelWait,
        recovery,
        confirmation,
    };
}
