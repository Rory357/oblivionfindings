import type { HandoverWorkerNotes } from '@/components/handover-person-notes';
import type { HandoverWriteValue } from '@/components/handover-write-form';
import type { HandoverEditor } from '@/hooks/use-handover-editor';
import { taskRequest, TaskRequestError } from '@/pages/my-day/lib/task-api';
import {
    useCallback,
    useEffect,
    useRef,
    useState,
    type Dispatch,
    type SetStateAction,
} from 'react';

/** Compare only persisted note content; the server trims and omits untouched people. */
export function handoverContentKey(notes?: HandoverWorkerNotes) {
    return JSON.stringify({
        shared_notes: notes?.shared_notes.trim() ?? '',
        people: (notes?.people ?? [])
            .map((person) => ({
                client_id: person.client_id,
                notes: person.notes.trim(),
                no_updates: !person.notes.trim() && person.no_updates,
                follow_up_needed: person.follow_up_needed,
                not_supported: person.not_supported ?? false,
            }))
            .filter(
                (person) =>
                    person.notes ||
                    person.no_updates ||
                    person.follow_up_needed ||
                    person.not_supported,
            )
            .sort((a, b) => a.client_id - b.client_id),
    });
}

type SaveResult = Pick<
    HandoverEditor,
    'handover_id' | 'expected_version' | 'status' | 'saved_at' | 'review_url'
>;

export function useHandoverDraftSave({
    shiftId,
    editor,
    value,
    loading,
    readOnly,
    setEditor,
    setValue,
}: {
    shiftId: number | null;
    editor: HandoverEditor | null;
    value: HandoverWriteValue;
    loading: boolean;
    readOnly: boolean;
    setEditor: Dispatch<SetStateAction<HandoverEditor | null>>;
    setValue: Dispatch<SetStateAction<HandoverWriteValue>>;
}) {
    const [state, setState] = useState<
        'idle' | 'saving' | 'saved' | 'failed' | 'conflict'
    >('idle');
    const [error, setError] = useState('');
    const [savedKey, setSavedKey] = useState('');
    const ready = useRef(false);
    const blocked = useRef(false);
    const lastSaved = useRef('');
    const lastAttempt = useRef('');
    const version = useRef<number | null>(null);
    const pending = useRef<Promise<boolean> | null>(null);
    const latest = useRef(value);
    const alive = useRef(true);
    latest.current = value;
    useEffect(() => {
        alive.current = true;
        return () => {
            alive.current = false;
        };
    }, []);
    useEffect(() => {
        if (loading) {
            ready.current = false;
            return;
        }
        if (!editor || ready.current) return;
        ready.current = true;
        blocked.current = false;
        version.current = editor.expected_version;
        lastSaved.current = handoverContentKey(value.worker_notes);
        setSavedKey(lastSaved.current);
        setState(editor.handover_id ? 'saved' : 'idle');
        setError('');
    }, [loading, editor, value.worker_notes]);

    const adopt = useCallback(
        (result: SaveResult, contentKey: string) => {
            version.current = result.expected_version;
            lastSaved.current = contentKey;
            if (!alive.current) return;
            setSavedKey(contentKey);
            setEditor((current) =>
                current
                    ? {
                          ...current,
                          ...result,
                          worker_notes: JSON.parse(
                              contentKey,
                          ) as HandoverWorkerNotes,
                      }
                    : current,
            );
            setValue((current) => ({
                ...current,
                expected_version: result.expected_version,
            }));
        },
        [setEditor, setValue],
    );

    const flush = useCallback((): Promise<boolean> => {
        if (pending.current) return pending.current;
        if (
            !alive.current ||
            !shiftId ||
            !ready.current ||
            readOnly ||
            blocked.current
        )
            return Promise.resolve(false);
        if (
            handoverContentKey(latest.current.worker_notes) ===
            lastSaved.current
        ) {
            setState('saved');
            setError('');
            return Promise.resolve(true);
        }
        const operation = (async () => {
            setError('');
            try {
                // A save in flight never overwrites newer typing. Drain to the newest snapshot.
                while (
                    alive.current &&
                    handoverContentKey(latest.current.worker_notes) !==
                        lastSaved.current
                ) {
                    const snapshot = latest.current;
                    const key = handoverContentKey(snapshot.worker_notes);
                    lastAttempt.current = key;
                    setState('saving');
                    const result = await taskRequest<SaveResult>(
                        '/attendance/handover',
                        'POST',
                        {
                            ...snapshot,
                            shift_id: shiftId,
                            expected_version: version.current,
                        },
                    );
                    adopt(result, key);
                }
                if (alive.current) setState('saved');
                return true;
            } catch (cause) {
                blocked.current = true;
                if (alive.current) {
                    setState(
                        cause instanceof TaskRequestError &&
                            cause.fields.handover
                            ? 'conflict'
                            : 'failed',
                    );
                    setError(
                        cause instanceof Error
                            ? cause.message
                            : 'The draft could not be saved. Your answers are still here.',
                    );
                }
                return false;
            } finally {
                pending.current = null;
            }
        })();
        pending.current = operation;
        return operation;
    }, [shiftId, readOnly, adopt]);

    const dirty =
        ready.current && handoverContentKey(value.worker_notes) !== savedKey;
    useEffect(() => {
        if (!dirty || loading || readOnly || blocked.current) return;
        const timer = window.setTimeout(() => {
            void flush();
        }, 800);
        return () => window.clearTimeout(timer);
    }, [value.worker_notes, dirty, loading, readOnly, flush]);
    useEffect(() => {
        const warn = (event: BeforeUnloadEvent) => {
            if (
                ready.current &&
                handoverContentKey(latest.current.worker_notes) !==
                    lastSaved.current
            ) {
                event.preventDefault();
                event.returnValue = '';
            }
        };
        window.addEventListener('beforeunload', warn);
        return () => window.removeEventListener('beforeunload', warn);
    }, []);

    const retrySave = async () => {
        if (!shiftId || pending.current) return false;
        setState('saving');
        try {
            const remote = await taskRequest<HandoverEditor>(
                `/attendance/shifts/${shiftId}/handover-draft`,
            );
            if (
                remote.status === 'submitted' ||
                remote.status === 'acknowledged'
            )
                throw new Error(
                    'This handover has been sent. Load the saved handover to continue.',
                );
            const remoteKey = handoverContentKey(remote.worker_notes);
            if (remote.expected_version !== version.current) {
                if (remoteKey !== lastAttempt.current) {
                    setState('conflict');
                    setError(
                        'Another window changed these notes. Your answers are still here. Load the saved draft before continuing.',
                    );
                    return false;
                }
                // Recover a successful write whose response was lost, without repeating it.
                adopt(remote, remoteKey);
            }
            blocked.current = false;
            return await flush();
        } catch (cause) {
            setState('failed');
            setError(
                cause instanceof Error
                    ? cause.message
                    : 'Could not check the saved draft.',
            );
            return false;
        }
    };

    return {
        state,
        dirty,
        error,
        flush,
        retrySave,
        saving: state === 'saving',
    };
}
