import axios from 'axios';
import {
    useCallback,
    useEffect,
    useLayoutEffect,
    useRef,
    useState,
} from 'react';
import { newItCommentUuid } from './it-ticket-comment-contract';
import { draftRecord } from './it-ticket-draft-contract';
import {
    freezeItMergeIntent,
    itMergePayload,
    itMergeReceiptParameters,
    itMergeReceiptPath,
    readItMergeResult,
    validItMergeIdentity,
    type ItMergeIdentity,
    type ItMergeIntent,
    type ItMergeResult,
} from './it-ticket-merge-contract';
import type { ItMergePreview } from './it-ticket-merge-preview';

type Stage =
    | 'editing'
    | 'sending'
    | 'recovering'
    | 'cancelling'
    | 'unknown'
    | 'rejected'
    | 'session'
    | 'access'
    | 'committed'
    | 'cancelled';
interface State {
    settledOperationToken: number;
    stage: Stage;
    message: string | null;
    result: ItMergeResult | null;
    references: ItMergeIdentity[];
    concealed: boolean;
}
const initial = (): State => ({
    settledOperationToken: 0,
    stage: 'editing',
    message: null,
    result: null,
    references: [],
    concealed: false,
});
const storageKey = (actorId: number, sourceId: number) =>
    `it.pending-merge-command.v1.${actorId}:${sourceId}`;

/** Opaque references only: reasons, titles, review proofs and inventory never enter browser storage. */
export function pendingItMergeCommands(
    actorId: number,
    sourceId: number,
): ItMergeIdentity[] {
    const raw = sessionStorage.getItem(storageKey(actorId, sourceId));
    if (raw === null) return [];
    const values: unknown = JSON.parse(raw);
    if (!Array.isArray(values) || values.length > 20)
        throw new Error('Merge references are unavailable.');
    return values.map((entry) => {
        if (
            !draftRecord(entry) ||
            typeof entry.targetId !== 'number' ||
            typeof entry.requestUuid !== 'string'
        )
            throw new Error('Merge references are unavailable.');
        const identity = {
            actorId,
            sourceId,
            targetId: entry.targetId,
            requestUuid: entry.requestUuid,
        };
        if (!validItMergeIdentity(identity))
            throw new Error('Merge references are unavailable.');
        return identity;
    });
}
function markReference(identity: ItMergeIdentity, add: boolean) {
    const references = pendingItMergeCommands(
        identity.actorId,
        identity.sourceId,
    ).filter((entry) => entry.requestUuid !== identity.requestUuid);
    if (add) references.push(identity);
    if (references.length > 20)
        throw new Error('Resolve pending merge commands first.');
    const key = storageKey(identity.actorId, identity.sourceId);
    if (references.length)
        sessionStorage.setItem(
            key,
            JSON.stringify(
                references.map(({ targetId, requestUuid }) => ({
                    targetId,
                    requestUuid,
                })),
            ),
        );
    else sessionStorage.removeItem(key);
    return references;
}

/** Receipts settle commands. Hosts separately own draft retention, current review and navigation. */
export function useItTicketMergeCommand({
    actorId,
    sourceId,
    onSettled,
    onConceal,
}: {
    actorId: number;
    sourceId: number;
    onSettled: (result: ItMergeResult) => void;
    onConceal: (kind: 'session' | 'access') => void;
}) {
    const scope = `${actorId}:${sourceId}`;
    const [state, setState] = useState<State>(initial);
    const [stateScope, setStateScope] = useState(scope);
    const currentScope = useRef(scope);
    const callbacks = useRef({ onSettled, onConceal });
    const active = useRef<AbortController | null>(null);
    const epoch = useRef(0);
    const identity = useRef<ItMergeIdentity | null>(null);
    const intent = useRef<Readonly<ItMergeIntent> | null>(null);
    const uncertain = useRef(false);
    const concealed = useRef(false);
    const mounted = useRef(true);
    useLayoutEffect(() => {
        currentScope.current = scope;
        callbacks.current = { onSettled, onConceal };
    });
    useEffect(() => {
        mounted.current = true;
        ++epoch.current;
        active.current?.abort();
        active.current = null;
        identity.current = null;
        intent.current = null;
        uncertain.current = false;
        concealed.current = false;
        setStateScope(scope);
        try {
            const references = pendingItMergeCommands(actorId, sourceId);
            setState({ ...initial(), references });
        } catch {
            setState({
                ...initial(),
                stage: 'unknown',
                message:
                    'Pending merge references could not be read. Restore browser storage before starting a merge.',
            });
        }
        return () => {
            mounted.current = false;
            // This operation counter invalidates the latest request; it is not a DOM ref.
            // eslint-disable-next-line react-hooks/exhaustive-deps
            ++epoch.current;
            active.current?.abort();
            active.current = null;
            intent.current = null;
        };
    }, [scope, actorId, sourceId]);

    const run = useCallback(
        async (
            operation: 'send' | 'check' | 'cancel',
            requested: ItMergeIdentity,
            proposal: Readonly<ItMergeIntent> | null,
        ) => {
            if (
                !mounted.current ||
                currentScope.current !== scope ||
                active.current ||
                !validItMergeIdentity(requested) ||
                requested.actorId !== actorId ||
                requested.sourceId !== sourceId ||
                (operation === 'send' && (!proposal || concealed.current))
            )
                return;
            if (
                uncertain.current &&
                identity.current &&
                identity.current.requestUuid !== requested.requestUuid
            )
                return;
            identity.current = { ...requested };
            const wasUncertain = uncertain.current;
            if (operation !== 'check') {
                try {
                    const references = markReference(requested, true);
                    setState((previous) => ({ ...previous, references }));
                } catch {
                    if (!wasUncertain) intent.current = null;
                    setState((previous) => ({
                        ...previous,
                        stage: wasUncertain ? 'unknown' : 'rejected',
                        message:
                            'The recovery reference could not be kept. No new request was sent. Restore browser storage and retry.',
                    }));
                    return;
                }
            }
            const controller = new AbortController();
            active.current = controller;
            const token = ++epoch.current;
            const valid = () =>
                mounted.current &&
                currentScope.current === scope &&
                token === epoch.current;
            const config = {
                signal: controller.signal,
                timeout: 25000,
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            };
            setState((previous) => ({
                ...previous,
                stage:
                    operation === 'send'
                        ? 'sending'
                        : operation === 'check'
                          ? 'recovering'
                          : 'cancelling',
                message: null,
                result: null,
            }));
            if (operation !== 'check') uncertain.current = true;
            try {
                const response =
                    operation === 'send'
                        ? await axios.post(
                              `/it/tickets/${sourceId}/merge`,
                              itMergePayload(proposal!),
                              config,
                          )
                        : operation === 'cancel'
                          ? await axios.post(
                                `${itMergeReceiptPath(requested)}/cancel`,
                                itMergeReceiptParameters(requested),
                                config,
                            )
                          : await axios.get(itMergeReceiptPath(requested), {
                                ...config,
                                params: itMergeReceiptParameters(requested),
                            });
                if (!valid()) return;
                const result =
                    response.status === 200
                        ? readItMergeResult(response.data, requested, proposal)
                        : null;
                if (!result) {
                    uncertain.current = true;
                    setState((previous) => ({
                        ...previous,
                        stage: 'unknown',
                        message:
                            'The merge result could not be confirmed. Check its saved result before trying another merge.',
                    }));
                    return;
                }
                uncertain.current = false;
                intent.current = null;
                let references: ItMergeIdentity[] | undefined;
                try {
                    references = markReference(requested, false);
                } catch {
                    /* A verified receipt remains authoritative if reference cleanup fails. */
                }
                setState((previous) => ({
                    ...previous,
                    stage: result.status,
                    settledOperationToken: previous.settledOperationToken + 1,
                    result,
                    message: null,
                    references:
                        references ??
                        previous.references.filter(
                            (entry) =>
                                entry.requestUuid !== requested.requestUuid,
                        ),
                }));
                try {
                    callbacks.current.onSettled(result);
                } catch {
                    setState((previous) => ({
                        ...previous,
                        message:
                            result.status === 'committed'
                                ? 'The merge is confirmed. Open the surviving ticket to continue.'
                                : 'Cancellation is confirmed. Close this form to continue.',
                    }));
                }
            } catch (error) {
                if (!valid()) return;
                const status = axios.isAxiosError(error)
                    ? error.response?.status
                    : undefined;
                const body: unknown = axios.isAxiosError(error)
                    ? error.response?.data
                    : undefined;
                if (
                    status === 401 ||
                    status === 419 ||
                    status === 403 ||
                    (status === 404 &&
                        !(
                            operation === 'check' &&
                            draftRecord(body) &&
                            body.code === 'merge_receipt_unconfirmed' &&
                            body.viewer_user_id === requested.actorId &&
                            body.source_id === requested.sourceId &&
                            body.target_id === requested.targetId &&
                            body.request_uuid === requested.requestUuid
                        ))
                ) {
                    const kind =
                        status === 401 || status === 419 ? 'session' : 'access';
                    concealed.current = true;
                    intent.current = null;
                    uncertain.current = true;
                    setState((previous) => ({
                        ...previous,
                        stage: kind,
                        concealed: true,
                        message:
                            kind === 'session'
                                ? 'Sign in with the original account, then check the saved merge result.'
                                : status === 404 && operation === 'check'
                                  ? 'No accessible receipt was found. This does not prove the merge failed. Details are concealed until current access is reviewed; check again or explicitly cancel this command.'
                                  : 'Merge details are no longer available. Access to both tickets is required to check the saved result.',
                    }));
                    callbacks.current.onConceal(kind);
                } else if (
                    operation === 'send' &&
                    !wasUncertain &&
                    (status === 422 ||
                        (status === 409 &&
                            draftRecord(body) &&
                            body.code === 'stale_ticket'))
                ) {
                    // A fresh command received a definitive rejection. A retry after an
                    // uncertain response cannot use a new rejection to erase the earlier attempt.
                    uncertain.current = false;
                    intent.current = null;
                    let message =
                        status === 409
                            ? 'One of the tickets changed. Review both current records before merging.'
                            : 'The merge was not accepted. Review the tickets and your reason before trying again.';
                    if (draftRecord(body) && draftRecord(body.errors)) {
                        const messages = [
                            'reason',
                            'review_token',
                            'form',
                        ].flatMap((field) => {
                            const value =
                                body.errors && draftRecord(body.errors)
                                    ? body.errors[field]
                                    : null;
                            return Array.isArray(value)
                                ? value
                                      .filter(
                                          (entry): entry is string =>
                                              typeof entry === 'string',
                                      )
                                      .map((entry) => entry.slice(0, 1000))
                                : [];
                        });
                        if (messages.length)
                            message = messages.slice(0, 3).join(' ');
                    }
                    let references: ItMergeIdentity[] | undefined;
                    try {
                        references = markReference(requested, false);
                    } catch {
                        /* Keep the opaque reference available for explicit cancellation. */
                    }
                    setState((previous) => ({
                        ...previous,
                        stage: 'rejected',
                        settledOperationToken:
                            previous.settledOperationToken + 1,
                        message,
                        references: references ?? previous.references,
                    }));
                } else {
                    uncertain.current = true;
                    setState((previous) => ({
                        ...previous,
                        stage: 'unknown',
                        message:
                            status === 404
                                ? 'No accessible receipt was found. This does not prove the merge failed. Check again or explicitly cancel this command.'
                                : 'The merge result is not confirmed. Check its saved result, retry the same command or explicitly cancel it.',
                    }));
                }
            } finally {
                if (valid()) active.current = null;
            }
        },
        [scope, actorId, sourceId],
    );

    const send = useCallback(
        async (preview: ItMergePreview, reason: string) => {
            if (
                currentScope.current !== scope ||
                active.current ||
                uncertain.current ||
                concealed.current ||
                intent.current
            )
                return;
            let proposal: Readonly<ItMergeIntent> | null = null;
            try {
                if (pendingItMergeCommands(actorId, sourceId).length) {
                    setState((previous) => ({
                        ...previous,
                        message:
                            'Check or cancel earlier merge commands before starting another.',
                    }));
                    return;
                }
                proposal = freezeItMergeIntent(
                    {
                        actorId,
                        sourceId,
                        targetId: preview.target.id,
                        requestUuid: newItCommentUuid(),
                    },
                    preview,
                    reason,
                );
            } catch {
                /* Secure identity or storage is unavailable: do not send. */
            }
            if (!proposal) {
                setState((previous) => ({
                    ...previous,
                    stage: 'rejected',
                    message:
                        'A current review, reason and secure recovery reference are required before merging.',
                }));
                return;
            }
            intent.current = proposal;
            await run('send', proposal, proposal);
        },
        [scope, actorId, sourceId, run],
    );
    const check = (reference?: ItMergeIdentity) => {
        const target = reference ?? identity.current;
        if (target)
            return run(
                'check',
                target,
                intent.current?.requestUuid === target.requestUuid
                    ? intent.current
                    : null,
            );
    };
    const cancel = (reference?: ItMergeIdentity) => {
        const target = reference ?? identity.current;
        if (target)
            return run(
                'cancel',
                target,
                intent.current?.requestUuid === target.requestUuid
                    ? intent.current
                    : null,
            );
    };
    const retry = () => {
        const proposal = intent.current;
        if (proposal) return run('send', proposal, proposal);
    };
    const stop = () => {
        if (!active.current || currentScope.current !== scope) return;
        ++epoch.current;
        active.current.abort();
        active.current = null;
        uncertain.current = true;
        setState((previous) => ({
            ...previous,
            stage: 'unknown',
            message:
                'Stopped waiting. The merge may still finish; check its saved result or explicitly cancel this command.',
        }));
    };
    const visible =
        stateScope === scope ? state : { ...initial(), concealed: true };
    return {
        ...visible,
        outcomeUnknown: uncertain.current,
        reviewed() {
            if (
                currentScope.current !== scope ||
                active.current ||
                uncertain.current
            )
                return false;
            try {
                if (pendingItMergeCommands(actorId, sourceId).length)
                    return false;
            } catch {
                return false;
            }
            concealed.current = false;
            intent.current = null;
            setState((previous) => ({
                ...previous,
                stage: 'editing',
                concealed: false,
                message: null,
                result: null,
            }));
            return true;
        },
        conceal(kind: 'access' | 'session') {
            if (currentScope.current !== scope) return;
            ++epoch.current;
            active.current?.abort();
            active.current = null;
            intent.current = null;
            concealed.current = true;
            setState((previous) => ({
                ...previous,
                stage: kind,
                concealed: true,
                result: null,
            }));
        },
        send,
        check,
        cancel,
        retry,
        stop,
        busy: ['sending', 'recovering', 'cancelling'].includes(visible.stage),
        canRetry:
            stateScope === scope &&
            !visible.concealed &&
            intent.current !== null,
    };
}
