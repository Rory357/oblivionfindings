import axios from 'axios';
import { useCallback, useEffect, useRef, useState } from 'react';

export interface TicketWatcher {
    id: number;
    name: string;
    href?: string | null;
    receives_updates?: boolean;
}
export interface WatcherOption {
    id: number;
    name: string;
}
interface Intent {
    actorId: number;
    ticketId: number;
    userId: number;
    version: number;
    watching: boolean;
}
interface Review {
    version: number;
    watchers: TicketWatcher[];
    options: WatcherOption[];
}
interface Projection extends Review {
    source: 'review' | 'acknowledgement';
    sourceWatchers: TicketWatcher[];
    sourceOptions: WatcherOption[];
}
export interface WatcherAcknowledgement {
    id: number;
    viewer_user_id: number;
    watcher_user_id: number;
    watching: boolean;
    changed: boolean;
    lock_version: number;
}
type Stage =
    | 'editing'
    | 'sending'
    | 'unknown'
    | 'conflict'
    | 'reviewing'
    | 'reviewed'
    | 'session'
    | 'access'
    | 'done';
const record = (value: unknown): value is Record<string, unknown> =>
    !!value && typeof value === 'object' && !Array.isArray(value);
const positive = (value: unknown): value is number =>
    Number.isSafeInteger(value) && Number(value) > 0;
const keyFor = (actor: number, ticket: number) => `${actor}:${ticket}`;
// Only an exact desired-membership command survives same-document navigation.
// No names/content, browser storage, background sends or automatic retry.
const pending = new Map<string, Intent>();
const rejectedBeforeUncertainty = new WeakSet<Intent>();
const warnPending = (event: BeforeUnloadEvent) => {
    event.preventDefault();
    event.returnValue = '';
};
function syncUnloadWarning() {
    window.removeEventListener('beforeunload', warnPending);
    if (pending.size) window.addEventListener('beforeunload', warnPending);
}
export function purgeTicketWatcherCommandsForActor(actorId: number) {
    for (const [key, entry] of pending)
        if (entry.actorId === actorId) pending.delete(key);
    syncUnloadWarning();
}
function forget(intent: Intent | null) {
    if (
        intent &&
        pending.get(keyFor(intent.actorId, intent.ticketId)) === intent
    )
        pending.delete(keyFor(intent.actorId, intent.ticketId));
    syncUnloadWarning();
}

export function readTicketWatcherAcknowledgement(
    body: unknown,
    intent: Intent,
): WatcherAcknowledgement | null {
    if (!record(body) || body.status !== 'committed' || !record(body.data))
        return null;
    const data = body.data;
    if (
        data.id !== intent.ticketId ||
        data.viewer_user_id !== intent.actorId ||
        data.watcher_user_id !== intent.userId ||
        data.watching !== intent.watching ||
        typeof data.changed !== 'boolean' ||
        !positive(data.lock_version) ||
        (data.changed
            ? data.lock_version !== intent.version + 1
            : data.lock_version < intent.version)
    )
        return null;
    return data as unknown as WatcherAcknowledgement;
}

function readReview(
    body: unknown,
    actorId: number,
    ticketId: number,
): Review | null {
    if (
        !record(body) ||
        body.viewer_user_id !== actorId ||
        !record(body.ticket) ||
        body.ticket.id !== ticketId ||
        !positive(body.ticket.lock_version) ||
        !record(body.can) ||
        body.can.manageWatchers !== true ||
        !Array.isArray(body.ticket.watchers) ||
        !Array.isArray(body.watcherOptions)
    )
        return null;
    const validPerson = (value: unknown): value is WatcherOption =>
        record(value) && positive(value.id) && typeof value.name === 'string';
    if (
        !body.watcherOptions.every(validPerson) ||
        !body.ticket.watchers.every(
            (row) =>
                validPerson(row) &&
                record(row) &&
                typeof row.receives_updates === 'boolean',
        )
    )
        return null;
    if (
        new Set(body.watcherOptions.map((row) => row.id)).size !==
            body.watcherOptions.length ||
        new Set(body.ticket.watchers.map((row: TicketWatcher) => row.id))
            .size !== body.ticket.watchers.length
    )
        return null;
    return {
        version: body.ticket.lock_version,
        watchers: body.ticket.watchers,
        options: body.watcherOptions,
    };
}

/** Desired state, original actor and displayed version bind every watcher command. */
export function useTicketWatcherCommand({
    actorId,
    ticketId,
    version,
    canManage,
    watchers,
    options,
    onCommitted,
    authorizationReady = true,
}: {
    actorId: number | null | undefined;
    ticketId: number;
    version: number;
    canManage: boolean;
    authorizationReady?: boolean;
    watchers: TicketWatcher[];
    options: WatcherOption[];
    onCommitted: (version: number) => void;
}) {
    const scope = `${actorId}:${ticketId}`;
    const current = useRef({ actorId, ticketId, canManage, scope });
    current.current = { actorId, ticketId, canManage, scope };
    const callback = useRef(onCommitted);
    callback.current = onCommitted;
    const [stateScope, setStateScope] = useState(scope);
    const [intent, setIntent] = useState<Intent | null>(() =>
        positive(actorId)
            ? (pending.get(keyFor(actorId, ticketId)) ?? null)
            : null,
    );
    const intentRef = useRef(intent);
    intentRef.current = intent;
    const [open, setOpen] = useState(!!intent);
    const [stage, setStage] = useState<Stage>(intent ? 'unknown' : 'editing');
    const [message, setMessage] = useState<string | null>(
        intent
            ? 'A previous watcher change needs review. Check current watchers before continuing.'
            : null,
    );
    const [reviewed, setReviewed] = useState<Review | null>(null);
    const [ack, setAck] = useState<WatcherAcknowledgement | null>(null);
    const [local, setLocal] = useState<Projection | null>(null);
    const inputs = useRef({ watchers, options });
    inputs.current = { watchers, options };
    const projectReview = (review: Review) =>
        setLocal({
            ...review,
            source: 'review',
            sourceWatchers: inputs.current.watchers,
            sourceOptions: inputs.current.options,
        });
    const request = useRef<AbortController | null>(null);
    const epoch = useRef(0);
    const previousActor = useRef(actorId);
    const cancelOperation = useCallback(() => {
        ++epoch.current;
        request.current?.abort();
        request.current = null;
    }, []);
    const deny = useCallback(() => {
        cancelOperation();
        const actor = current.current.actorId;
        if (positive(actor))
            pending.delete(keyFor(actor, current.current.ticketId));
        syncUnloadWarning();
        setIntent(null);
        setReviewed(null);
        setLocal(null);
        setAck(null);
        setStage('access');
        setOpen(true);
        setMessage(
            'Your account or ticket access changed. Watcher details are concealed. Reload to continue.',
        );
    }, [cancelOperation]);
    useEffect(() => {
        if (previousActor.current !== actorId) {
            const old = previousActor.current;
            if (positive(old)) purgeTicketWatcherCommandsForActor(old);
            previousActor.current = actorId;
        }
        if (stateScope !== scope) {
            cancelOperation();
            setStateScope(scope);
            setReviewed(null);
            setLocal(null);
            setAck(null);
            const retained = positive(actorId)
                ? (pending.get(keyFor(actorId, ticketId)) ?? null)
                : null;
            setIntent(retained);
            setOpen(!!retained);
            setStage(retained ? 'unknown' : 'editing');
            setMessage(
                retained
                    ? 'A previous watcher change needs review. Check current watchers before continuing.'
                    : null,
            );
        }
        if (authorizationReady && !canManage && intentRef.current) deny();
    }, [
        actorId,
        ticketId,
        canManage,
        authorizationReady,
        scope,
        stateScope,
        cancelOperation,
        deny,
    ]);
    useEffect(() => () => cancelOperation(), [cancelOperation]);
    useEffect(() => {
        if (!intent || stage === 'done' || stage === 'access') return;
        const unload = (event: BeforeUnloadEvent) => {
            event.preventDefault();
            event.returnValue = '';
        };
        window.addEventListener('beforeunload', unload);
        return () => window.removeEventListener('beforeunload', unload);
    }, [intent, stage]);
    const concealed =
        stateScope !== scope || stage === 'session' || stage === 'access';
    const latest =
        local &&
        (local.version > version ||
            (local.version === version &&
                local.source === 'review' &&
                local.sourceWatchers === watchers &&
                local.sourceOptions === options))
            ? local
            : { version, watchers, options };
    const begin = (userId: number, watching: boolean) => {
        if (
            !canManage ||
            !positive(actorId) ||
            !positive(userId) ||
            !positive(latest.version) ||
            concealed
        )
            return;
        const retained = pending.get(keyFor(actorId, ticketId));
        if (retained) {
            setIntent(retained);
            setStage('unknown');
            setOpen(true);
            setReviewed(null);
            setAck(null);
            setMessage(
                'Review the pending watcher change before starting another.',
            );
            return;
        }
        if (
            watching
                ? !latest.options.some((row) => row.id === userId)
                : !latest.watchers.some((row) => row.id === userId)
        )
            return;
        setIntent({
            actorId,
            ticketId,
            userId,
            version: latest.version,
            watching,
        });
        setOpen(true);
        setStage('editing');
        setMessage(null);
        setReviewed(null);
        setAck(null);
    };
    const execute = async (review: boolean) => {
        const original = intentRef.current;
        if (
            !original ||
            request.current ||
            current.current.scope !== stateScope ||
            original.actorId !== current.current.actorId ||
            !current.current.canManage
        )
            return;
        if (!review && stage !== 'editing' && stage !== 'unknown') return;
        const prior = pending.get(keyFor(original.actorId, original.ticketId));
        if (!review && prior && prior !== original) {
            setIntent(prior);
            setStage('unknown');
            setOpen(true);
            setReviewed(null);
            setMessage(
                'Another watcher change is pending for this ticket. Review it before starting this change.',
            );
            return;
        }
        if (
            !review &&
            !pending.has(keyFor(original.actorId, original.ticketId)) &&
            pending.size >= 20
        ) {
            setMessage(
                'Review an earlier pending watcher change before sending another. This change is still here.',
            );
            return;
        }
        if (!review) {
            pending.set(keyFor(original.actorId, original.ticketId), original);
            syncUnloadWarning();
        }
        const controller = new AbortController();
        request.current = controller;
        const operation = ++epoch.current;
        const stillCurrent = () =>
            !controller.signal.aborted &&
            epoch.current === operation &&
            current.current.scope === stateScope &&
            current.current.actorId === original.actorId &&
            current.current.canManage;
        setStage(review ? 'reviewing' : 'sending');
        setMessage(null);
        setReviewed(null);
        setAck(null);
        try {
            const response = review
                ? await axios.get<unknown>(`/it/tickets/${original.ticketId}`, {
                      headers: { Accept: 'application/json' },
                      signal: controller.signal,
                      timeout: 20000,
                  })
                : await axios.patch<unknown>(
                      `/it/tickets/${original.ticketId}/watchers/${original.userId}`,
                      {
                          actor_user_id: original.actorId,
                          expected_version: original.version,
                          watching: original.watching,
                      },
                      {
                          headers: { Accept: 'application/json' },
                          signal: controller.signal,
                          timeout: 20000,
                      },
                  );
            if (!stillCurrent()) return;
            if (response.status !== 200)
                throw new Error('Unconfirmed response');
            if (
                record(response.data) &&
                (review
                    ? response.data.viewer_user_id !== original.actorId
                    : record(response.data.data) &&
                      response.data.data.viewer_user_id !== original.actorId)
            ) {
                deny();
                return;
            }
            if (review) {
                if (
                    record(response.data) &&
                    record(response.data.can) &&
                    response.data.can.manageWatchers === false
                ) {
                    deny();
                    return;
                }
                const proof = readReview(
                    response.data,
                    original.actorId,
                    original.ticketId,
                );
                if (!proof || proof.version < original.version)
                    throw new Error('Unconfirmed current watchers');
                setReviewed(proof);
                projectReview(proof);
                setStage('reviewed');
                setMessage(
                    'Review the current watchers. Using this version does not send your change.',
                );
            } else {
                const result = readTicketWatcherAcknowledgement(
                    response.data,
                    original,
                );
                if (!result) throw new Error('Unconfirmed watcher change');
                forget(original);
                setAck(result);
                setStage('done');
                const person =
                    latest.options.find((row) => row.id === original.userId) ??
                    latest.watchers.find((row) => row.id === original.userId);
                setLocal({
                    ...latest,
                    source: 'acknowledgement',
                    sourceWatchers: inputs.current.watchers,
                    sourceOptions: inputs.current.options,
                    version: result.lock_version,
                    watchers: original.watching
                        ? latest.watchers.some(
                              (row) => row.id === original.userId,
                          )
                            ? latest.watchers
                            : [
                                  ...latest.watchers,
                                  {
                                      id: original.userId,
                                      name: person?.name ?? 'Watcher',
                                  },
                              ]
                        : latest.watchers.filter(
                              (row) => row.id !== original.userId,
                          ),
                });
                try {
                    callback.current(result.lock_version);
                } catch {
                    setMessage(
                        'The watcher change is confirmed. Refresh the ticket to load the latest register.',
                    );
                }
            }
        } catch (error) {
            if (!stillCurrent()) return;
            const status = axios.isAxiosError(error)
                ? error.response?.status
                : undefined;
            if (status === 403 || status === 404) {
                deny();
                return;
            }
            if (status === 401 || status === 419) {
                setReviewed(null);
                setLocal(null);
                setStage('session');
                setMessage(
                    'Your session expired. Sign in with the same account, then review current watchers. The pending change is retained.',
                );
            } else if (!review && status === 422) {
                if (!prior && stage === 'editing')
                    rejectedBeforeUncertainty.add(original);
                setStage('conflict');
                setMessage(
                    'This attempt was rejected. The person or ticket may no longer be eligible. Review current watchers; an earlier uncertain attempt may already have finished.',
                );
            } else if (!review && status === 409) {
                if (!prior && stage === 'editing')
                    rejectedBeforeUncertainty.add(original);
                setStage('conflict');
                setMessage(
                    'The ticket changed. Your proposed watcher change is retained. Review current watchers before reapplying.',
                );
            } else {
                setStage('unknown');
                setMessage(
                    review
                        ? 'Current watchers could not be confirmed. Your pending change is retained.'
                        : 'The watcher change could not be confirmed. Retry the exact change or review current watchers.',
                );
            }
        } finally {
            if (epoch.current === operation) request.current = null;
        }
    };
    const cancelWait = () => {
        if (!request.current) return;
        cancelOperation();
        setReviewed(null);
        setStage('unknown');
        setMessage(
            'Waiting stopped. This does not cancel a submitted change. Retry the same change or review current watchers.',
        );
    };
    const close = () => {
        if (request.current) cancelWait();
        // Closing uncertain work hides the dialog; its exact command remains in RAM.
        if (intent && !pending.has(keyFor(intent.actorId, intent.ticketId)))
            setIntent(null);
        setOpen(false);
        setReviewed(null);
    };
    const requireOriginalOutcome = () => {
        if (!intent || !reviewed) return false;
        if (
            !rejectedBeforeUncertainty.has(intent) &&
            pending.get(keyFor(intent.actorId, intent.ticketId)) === intent &&
            reviewed.version === intent.version &&
            reviewed.watchers.some((row) => row.id === intent.userId) !==
                intent.watching
        ) {
            setMessage(
                'An earlier request may still apply at this version. Retry the exact change or check current watchers again before clearing it.',
            );
            setReviewed(null);
            setStage('unknown');
            return true;
        }
        return false;
    };
    const adopt = () => {
        if (
            !intent ||
            !reviewed ||
            stage !== 'reviewed' ||
            requireOriginalOutcome()
        )
            return;
        const allowed =
            !intent.watching ||
            reviewed.options.some((row) => row.id === intent.userId);
        if (!allowed) return;
        forget(intent);
        projectReview(reviewed);
        setIntent({ ...intent, version: reviewed.version });
        setStage('editing');
        setReviewed(null);
        setMessage(
            'Current version adopted. Apply the change explicitly when ready.',
        );
    };
    const finishReview = () => {
        if (
            !intent ||
            !reviewed ||
            stage !== 'reviewed' ||
            requireOriginalOutcome()
        )
            return;
        forget(intent);
        projectReview(reviewed);
        setIntent(null);
        setOpen(false);
        setReviewed(null);
        setMessage(null);
        setStage('editing');
    };
    return {
        open: open && stateScope === scope,
        stage,
        message,
        intent: concealed ? null : intent,
        reviewed: concealed ? null : reviewed,
        acknowledgement: concealed ? null : ack,
        watchers: concealed ? [] : latest.watchers,
        options: concealed ? [] : latest.options,
        canManage: canManage && !concealed,
        concealed,
        busy: stage === 'sending' || stage === 'reviewing',
        pending: !!intent && stage !== 'done' && stage !== 'access',
        begin,
        send: () => execute(false),
        review: () => execute(true),
        adopt,
        close,
        cancelWait,
        finishReview,
        reopen: () => setOpen(true),
    };
}

export type TicketWatcherCommand = ReturnType<typeof useTicketWatcherCommand>;
