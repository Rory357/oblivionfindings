import {
    useCallback,
    useEffect,
    useLayoutEffect,
    useRef,
    useState,
} from 'react';
import {
    acquireCallBrowserLease,
    browserCallJournal,
    callRecoveryKey,
    type CallBrowserLease,
    type CallRecoveryJournal,
    type CallRecoveryRecord,
} from './call-recovery-journal';

type Status = 'checking' | 'ready' | 'other' | 'unavailable';
/** IDs survive a reload; authority, recipient names and microphone consent never do. */
export function useCallRecovery(actor: number | null, enabled: boolean) {
    const identity = useRef({ actor, enabled });
    useLayoutEffect(() => {
        identity.current = { actor, enabled };
    }, [actor, enabled]);
    const owner = useRef<{
        actor: number;
        controller: AbortController;
        journal: CallRecoveryJournal;
        lease: CallBrowserLease | null;
        record: CallRecoveryRecord | null;
        claiming: boolean;
    } | null>(null);
    const [state, setState] = useState<{
        actor: number | null;
        status: Status;
        restored: CallRecoveryRecord | null;
    }>({ actor, status: 'checking', restored: null });
    const current = useCallback(
        (session: NonNullable<typeof owner.current>) =>
            owner.current === session &&
            !session.controller.signal.aborted &&
            identity.current.enabled &&
            identity.current.actor === session.actor,
        [],
    );
    const sync = useCallback(async () => {
        const session = owner.current;
        if (!session || !current(session) || session.claiming) return;
        if (session.lease && session.record) return;
        const initial = session.journal.read();
        if (
            initial.status === 'empty' &&
            typeof navigator.locks?.request === 'function'
        ) {
            session.lease?.release();
            session.lease = null;
            setState({ actor: session.actor, status: 'ready', restored: null });
            return;
        }
        setState({ actor: session.actor, status: 'checking', restored: null });
        session.claiming = true;
        try {
            const lease =
                session.lease ??
                (await acquireCallBrowserLease(
                    session.actor,
                    session.controller.signal,
                ));
            if (!current(session)) {
                lease?.release();
                return;
            }
            if (!lease) {
                setState({
                    actor: session.actor,
                    status: 'other',
                    restored: null,
                });
                return;
            }
            session.lease = lease;
            const saved = session.journal.read();
            if (saved.status === 'unavailable') {
                setState({
                    actor: session.actor,
                    status: 'unavailable',
                    restored: null,
                });
                return;
            }
            session.record = saved.record;
            setState({
                actor: session.actor,
                status: 'ready',
                restored: saved.record,
            });
            // Idle pages must not keep another tab from making a call.
            if (!saved.record) {
                lease.release();
                session.lease = null;
            }
        } catch {
            if (current(session))
                setState({
                    actor: session.actor,
                    status: 'unavailable',
                    restored: null,
                });
        } finally {
            session.claiming = false;
        }
    }, [current]);
    useEffect(() => {
        setState({
            actor,
            status: enabled && actor ? 'checking' : 'ready',
            restored: null,
        });
        const journal = actor && enabled ? browserCallJournal(actor) : null;
        if (!actor || !enabled || !journal) {
            if (enabled && actor)
                setState({ actor, status: 'unavailable', restored: null });
            return;
        }
        const session = {
            actor,
            controller: new AbortController(),
            journal,
            lease: null as CallBrowserLease | null,
            record: null as CallRecoveryRecord | null,
            claiming: false,
        };
        owner.current = session;
        void sync();
        const changed = (event: StorageEvent) => {
            if (event.key === callRecoveryKey(actor) || event.key === null)
                void sync();
        };
        window.addEventListener('storage', changed);
        return () => {
            session.controller.abort();
            session.lease?.release();
            if (owner.current === session) owner.current = null;
            window.removeEventListener('storage', changed);
        };
    }, [actor, enabled, sync]);
    const acquire = useCallback(async () => {
        const session = owner.current;
        if (!session || !current(session) || session.claiming) return false;
        if (session.lease)
            return session.journal.read().status !== 'unavailable';
        session.claiming = true;
        try {
            const lease = await acquireCallBrowserLease(
                session.actor,
                session.controller.signal,
            );
            if (!current(session)) {
                lease?.release();
                return false;
            }
            if (!lease) {
                setState({
                    actor: session.actor,
                    status: 'other',
                    restored: null,
                });
                return false;
            }
            session.lease = lease;
            const saved = session.journal.read();
            session.record = saved.record;
            if (saved.status === 'unavailable') {
                setState({
                    actor: session.actor,
                    status: 'unavailable',
                    restored: null,
                });
                return false;
            }
            setState({
                actor: session.actor,
                status: 'ready',
                restored: saved.record,
            });
            // A record discovered at dispatch belongs to an earlier attempt. Restore it first.
            return !saved.record;
        } catch {
            if (current(session))
                setState({
                    actor: session.actor,
                    status: 'unavailable',
                    restored: null,
                });
            return false;
        } finally {
            session.claiming = false;
        }
    }, [current]);
    const write = useCallback(
        (record: CallRecoveryRecord) => {
            const session = owner.current;
            if (
                !session ||
                !current(session) ||
                !session.lease ||
                !session.journal.write(record, session.record)
            )
                throw new Error(
                    'Call recovery could not be saved on this browser. Your microphone is off. Check browser storage before trying again.',
                );
            session.record = record;
            setState({
                actor: session.actor,
                status: 'ready',
                restored: record,
            });
        },
        [current],
    );
    const clear = useCallback(
        (matches: (record: CallRecoveryRecord) => boolean) => {
            const session = owner.current;
            if (
                !session ||
                !current(session) ||
                !session.lease ||
                !session.record ||
                !matches(session.record)
            )
                return;
            if (!session.journal.clear(session.record)) {
                setState({
                    actor: session.actor,
                    status: 'unavailable',
                    restored: null,
                });
                return;
            }
            session.record = null;
            setState({ actor: session.actor, status: 'ready', restored: null });
        },
        [current],
    );
    const releaseIdle = useCallback(() => {
        const session = owner.current;
        if (session && current(session) && !session.record) {
            session.lease?.release();
            session.lease = null;
        }
    }, [current]);
    return {
        status: state.actor === actor ? state.status : ('checking' as Status),
        restored: state.actor === actor ? state.restored : null,
        sync,
        acquire,
        write,
        clear,
        releaseIdle,
        record: () => owner.current?.record ?? null,
    };
}
