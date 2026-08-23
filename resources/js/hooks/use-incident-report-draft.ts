import axios from 'axios';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';

export type IncidentReportDraftStatus =
    | 'idle'
    | 'loading'
    | 'dirty'
    | 'saving'
    | 'saved'
    | 'resume_available'
    | 'error'
    | 'session_expired';

type DraftEnvelope<T> = {
    mode: 'incident' | 'near_miss';
    entry_context: 'incidents' | 'health_safety' | 'control_room';
    step_index: number;
    form: T;
};

type DraftResponse<T> = {
    request_uuid: string;
    revision: number;
    saved_at: string;
    expires_at: string;
    draft?: DraftEnvelope<T>;
};

type DraftIdentity = {
    requestUuid: string;
    resume: boolean;
};

function createUuid(): string {
    const cryptoApi = globalThis.crypto;

    if (typeof cryptoApi?.randomUUID === 'function') {
        return cryptoApi.randomUUID();
    }

    if (typeof cryptoApi?.getRandomValues !== 'function') {
        throw new Error('Secure UUID generation is not available.');
    }

    const bytes = cryptoApi.getRandomValues(new Uint8Array(16));
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = Array.from(bytes, (byte) =>
        byte.toString(16).padStart(2, '0'),
    ).join('');

    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

function pointerKey(
    userId: number,
    mode: 'incident' | 'near_miss',
    entryContext: 'incidents' | 'health_safety' | 'control_room',
): string {
    return `oblivion:incident-report-draft:v1:${userId}:${entryContext}:${mode}`;
}

function initialIdentity(key: string): DraftIdentity {
    if (typeof window !== 'undefined') {
        const stored = window.localStorage.getItem(key);
        if (
            stored &&
            /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(
                stored,
            )
        ) {
            return { requestUuid: stored, resume: true };
        }
    }

    return { requestUuid: createUuid(), resume: false };
}

function draftUrl(requestUuid: string): string {
    return `/incidents/drafts/${requestUuid}`;
}

function responseStatus(error: unknown): number | null {
    return axios.isAxiosError(error) ? (error.response?.status ?? null) : null;
}

function validationMessage(error: unknown): string | null {
    if (!axios.isAxiosError(error) || error.response?.status !== 422) {
        return null;
    }

    const errors = (
        error.response.data as { errors?: Record<string, string[]> } | undefined
    )?.errors;

    return errors ? (Object.values(errors).flat()[0] ?? null) : null;
}

export function useIncidentReportDraft<T extends Record<string, unknown>>({
    userId,
    open,
    enabled,
    mode,
    entryContext,
    stepIndex,
    form,
    debounceMs = 800,
    onRestore,
}: {
    userId: number;
    open: boolean;
    enabled: boolean;
    mode: 'incident' | 'near_miss';
    entryContext: 'incidents' | 'health_safety' | 'control_room';
    stepIndex: number;
    form: T;
    debounceMs?: number;
    onRestore: (draft: DraftEnvelope<T>) => void;
}) {
    const key = useMemo(
        () => pointerKey(userId, mode, entryContext),
        [entryContext, mode, userId],
    );
    const [identity, setIdentity] = useState<DraftIdentity>(() =>
        initialIdentity(key),
    );
    const [loaded, setLoaded] = useState(!identity.resume);
    const [status, setStatus] = useState<IncidentReportDraftStatus>('idle');
    const [savedAt, setSavedAt] = useState<number | null>(null);
    const [message, setMessage] = useState<string | null>(null);
    const [pendingDraft, setPendingDraft] = useState<DraftEnvelope<T> | null>(
        null,
    );
    const [recoveryBlocked, setRecoveryBlocked] = useState(false);
    const [recoveryAttempt, setRecoveryAttempt] = useState(0);
    const revisionRef = useRef(0);
    const timerRef = useRef<number | null>(null);
    const serialRef = useRef<Promise<unknown>>(Promise.resolve());
    const operationRef = useRef(0);
    const lastSavedRef = useRef<string | null>(null);
    const suspendedRef = useRef(false);
    const resumeRef = useRef(identity.resume);
    const keyRef = useRef(key);
    const onRestoreRef = useRef(onRestore);
    const snapshot = useMemo<DraftEnvelope<T>>(
        () => ({
            mode,
            entry_context: entryContext,
            step_index: stepIndex,
            form,
        }),
        [entryContext, form, mode, stepIndex],
    );
    const snapshotJson = useMemo(() => JSON.stringify(snapshot), [snapshot]);
    const snapshotRef = useRef(snapshot);
    const snapshotJsonRef = useRef(snapshotJson);

    useEffect(() => {
        onRestoreRef.current = onRestore;
    }, [onRestore]);

    useEffect(() => {
        snapshotRef.current = snapshot;
        snapshotJsonRef.current = snapshotJson;
    }, [snapshot, snapshotJson]);

    useEffect(() => {
        if (keyRef.current === key) return;

        operationRef.current += 1;
        if (timerRef.current !== null) {
            window.clearTimeout(timerRef.current);
            timerRef.current = null;
        }
        serialRef.current = Promise.resolve();
        keyRef.current = key;
        const nextIdentity = initialIdentity(key);
        resumeRef.current = nextIdentity.resume;
        setIdentity(nextIdentity);
    }, [key]);

    useEffect(() => {
        if (open && typeof window !== 'undefined') {
            window.localStorage.setItem(key, identity.requestUuid);
        }
    }, [identity.requestUuid, key, open]);

    useEffect(() => {
        if (!open) {
            return;
        }

        suspendedRef.current = false;
        revisionRef.current = 0;
        lastSavedRef.current = null;
        setPendingDraft(null);
        setRecoveryBlocked(false);
        setSavedAt(null);
        setMessage(null);

        if (!resumeRef.current) {
            setLoaded(true);
            setStatus('idle');
            return;
        }

        let cancelled = false;
        setLoaded(false);
        setStatus('loading');

        void axios
            .get<DraftResponse<T>>(draftUrl(identity.requestUuid), {
                headers: { Accept: 'application/json' },
            })
            .then((response) => {
                if (cancelled) return;

                if (!response.data.draft) {
                    resumeRef.current = false;
                    setStatus('idle');
                    return;
                }

                revisionRef.current = response.data.revision;
                lastSavedRef.current = JSON.stringify(response.data.draft);
                setSavedAt(Date.parse(response.data.saved_at));

                if (
                    response.data.draft.mode !== mode ||
                    response.data.draft.entry_context !== entryContext
                ) {
                    suspendedRef.current = true;
                    setRecoveryBlocked(true);
                    setStatus('error');
                    setMessage(
                        'This saved report belongs to a different incident workflow. Discard it here or reopen the matching report.',
                    );
                    return;
                }

                suspendedRef.current = true;
                setPendingDraft(response.data.draft);
                setStatus('resume_available');
            })
            .catch((error: unknown) => {
                if (cancelled) return;

                const httpStatus = responseStatus(error);
                if (httpStatus === 404) {
                    if (typeof window !== 'undefined') {
                        window.localStorage.removeItem(key);
                    }
                    const nextIdentity = {
                        requestUuid: createUuid(),
                        resume: false,
                    };
                    resumeRef.current = false;
                    suspendedRef.current = false;
                    revisionRef.current = 0;
                    lastSavedRef.current = null;
                    setIdentity(nextIdentity);
                    setStatus('idle');
                    return;
                }

                suspendedRef.current = true;
                setRecoveryBlocked(true);
                if (httpStatus === 401 || httpStatus === 419) {
                    setStatus('session_expired');
                    setMessage(
                        'Sign in again to recover this incident draft. No new changes were sent.',
                    );
                    return;
                }

                setStatus('error');
                setMessage(
                    'The saved draft could not be checked. Reconnect and retry before closing.',
                );
            })
            .finally(() => {
                if (!cancelled) setLoaded(true);
            });

        return () => {
            cancelled = true;
        };
    }, [entryContext, identity.requestUuid, key, mode, open, recoveryAttempt]);

    const resume = useCallback(() => {
        if (!pendingDraft) return;

        onRestoreRef.current(pendingDraft);
        suspendedRef.current = false;
        setPendingDraft(null);
        setRecoveryBlocked(false);
        setStatus('saved');
        setMessage(null);
    }, [pendingDraft]);

    const performSave = useCallback(async (): Promise<boolean> => {
        if (!loaded || suspendedRef.current) return false;

        const operation = operationRef.current;
        const body = snapshotRef.current;
        const bodyJson = snapshotJsonRef.current;
        setStatus('saving');
        setMessage(null);

        const put = async () =>
            axios.put<DraftResponse<T>>(
                draftUrl(identity.requestUuid),
                {
                    expected_revision: revisionRef.current,
                    mode: body.mode,
                    entry_context: body.entry_context,
                    step_index: body.step_index,
                    form: body.form,
                },
                { headers: { Accept: 'application/json' } },
            );

        try {
            let response;
            try {
                response = await put();
            } catch (error) {
                if (operation !== operationRef.current) return false;
                if (responseStatus(error) !== 409) throw error;

                // Never silently choose a winner between two browser tabs.
                // Preserve this tab's fields and require an explicit reload
                // to review the actor-owned current revision.
                await axios.get<DraftResponse<T>>(
                    draftUrl(identity.requestUuid),
                    { headers: { Accept: 'application/json' } },
                );
                if (operation !== operationRef.current) return false;
                suspendedRef.current = true;
                setRecoveryBlocked(true);
                setStatus('error');
                setMessage(
                    'This draft changed in another window. Reload to review the saved version before continuing.',
                );
                return false;
            }

            if (operation !== operationRef.current) return false;
            revisionRef.current = response.data.revision;
            lastSavedRef.current = bodyJson;
            resumeRef.current = true;
            setSavedAt(Date.parse(response.data.saved_at));
            setStatus('saved');
            return true;
        } catch (error) {
            if (operation !== operationRef.current) return false;
            const httpStatus = responseStatus(error);
            if (httpStatus === 401 || httpStatus === 419) {
                setStatus('session_expired');
                setMessage(
                    'Your session ended. Sign in again, then retry this draft before closing.',
                );
            } else {
                setStatus('error');
                setMessage(
                    validationMessage(error) ??
                        'Not saved yet. Keep this report open, reconnect, then retry.',
                );
            }
            return false;
        }
    }, [identity.requestUuid, loaded]);

    const saveNow = useCallback((): Promise<boolean> => {
        if (timerRef.current !== null) {
            window.clearTimeout(timerRef.current);
            timerRef.current = null;
        }

        const queued = serialRef.current
            .catch(() => undefined)
            .then(performSave);
        serialRef.current = queued;
        return queued;
    }, [performSave]);

    useEffect(() => {
        if (
            !open ||
            !enabled ||
            !loaded ||
            suspendedRef.current ||
            snapshotJson === lastSavedRef.current
        ) {
            return;
        }

        setStatus('dirty');
        if (timerRef.current !== null) {
            window.clearTimeout(timerRef.current);
        }
        timerRef.current = window.setTimeout(() => {
            timerRef.current = null;
            void saveNow();
        }, debounceMs);

        return () => {
            if (timerRef.current !== null) {
                window.clearTimeout(timerRef.current);
                timerRef.current = null;
            }
        };
    }, [debounceMs, enabled, loaded, open, saveNow, snapshotJson]);

    useEffect(() => {
        if (!open || status !== 'error') return;

        const retry = () => {
            if (recoveryBlocked) {
                setRecoveryAttempt((attempt) => attempt + 1);
                return;
            }

            if (enabled) void saveNow();
        };
        window.addEventListener('online', retry);
        return () => window.removeEventListener('online', retry);
    }, [enabled, open, recoveryBlocked, saveNow, status]);

    const hasUnsavedChanges =
        !pendingDraft &&
        !recoveryBlocked &&
        snapshotJson !== lastSavedRef.current;

    useEffect(() => {
        if (!open || !enabled || !hasUnsavedChanges) return;

        const warnBeforeUnload = (event: BeforeUnloadEvent) => {
            event.preventDefault();
            event.returnValue = '';
        };
        window.addEventListener('beforeunload', warnBeforeUnload);
        return () =>
            window.removeEventListener('beforeunload', warnBeforeUnload);
    }, [enabled, hasUnsavedChanges, open]);

    const discard = useCallback(async (): Promise<boolean> => {
        if (timerRef.current !== null) {
            window.clearTimeout(timerRef.current);
            timerRef.current = null;
        }

        const preserveSuspension = pendingDraft !== null || recoveryBlocked;
        suspendedRef.current = true;
        await serialRef.current.catch(() => undefined);

        try {
            await axios.delete(draftUrl(identity.requestUuid), {
                headers: { Accept: 'application/json' },
            });
            if (typeof window !== 'undefined') {
                window.localStorage.removeItem(key);
            }
            revisionRef.current = 0;
            lastSavedRef.current = null;
            resumeRef.current = false;
            setPendingDraft(null);
            setRecoveryBlocked(false);
            setSavedAt(null);
            setStatus('idle');
            setMessage(null);
            operationRef.current += 1;
            serialRef.current = Promise.resolve();
            return true;
        } catch (error) {
            suspendedRef.current = preserveSuspension;
            const httpStatus = responseStatus(error);
            setStatus(
                httpStatus === 401 || httpStatus === 419
                    ? 'session_expired'
                    : 'error',
            );
            setMessage(
                httpStatus === 401 || httpStatus === 419
                    ? 'Sign in again before discarding this saved draft.'
                    : 'The draft could not be discarded. Reconnect and retry.',
            );
            return false;
        }
    }, [identity.requestUuid, key, pendingDraft, recoveryBlocked]);

    const consume = useCallback(() => {
        operationRef.current += 1;
        suspendedRef.current = true;
        if (timerRef.current !== null) {
            window.clearTimeout(timerRef.current);
            timerRef.current = null;
        }
        serialRef.current = Promise.resolve();
        if (typeof window !== 'undefined') {
            window.localStorage.removeItem(key);
        }
        lastSavedRef.current = null;
        resumeRef.current = false;
        setPendingDraft(null);
        setRecoveryBlocked(false);
        setSavedAt(null);
        setStatus('idle');
        setMessage(null);
    }, [key]);

    const beginNew = useCallback(() => {
        operationRef.current += 1;
        if (timerRef.current !== null) {
            window.clearTimeout(timerRef.current);
            timerRef.current = null;
        }
        serialRef.current = Promise.resolve();
        if (typeof window !== 'undefined') {
            window.localStorage.removeItem(key);
        }
        suspendedRef.current = false;
        revisionRef.current = 0;
        lastSavedRef.current = null;
        resumeRef.current = false;
        setPendingDraft(null);
        setRecoveryBlocked(false);
        setSavedAt(null);
        setStatus('idle');
        setMessage(null);
        setLoaded(true);
        setIdentity({ requestUuid: createUuid(), resume: false });
    }, [key]);

    return {
        requestUuid: identity.requestUuid,
        loaded,
        status,
        savedAt,
        message,
        resumeAvailable: pendingDraft !== null,
        recoveryBlocked,
        hasSavedDraft: savedAt !== null,
        hasUnsavedChanges,
        resume,
        saveNow,
        discard,
        consume,
        beginNew,
    };
}
