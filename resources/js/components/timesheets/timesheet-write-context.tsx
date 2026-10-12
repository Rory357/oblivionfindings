import { createContext, useContext, useInsertionEffect, useLayoutEffect, useRef, useState,
    useSyncExternalStore, type ReactNode } from 'react';
import { TimesheetWriteScope, type TimesheetWriteState } from './timesheet-write-scope';

const WriteContext = createContext<TimesheetWriteScope | null>(null);
/** Owning page integration and every mutation/read adapter are prerequisites.
 * This context does not grant permission or prove a server outcome. */
export function TimesheetWriteProvider({ actorId, children }: {
    actorId: unknown; children: ReactNode;
}) {
    const actor = useRef(actorId);
    const [scope] = useState(() => new TimesheetWriteScope(() => actor.current));
    // Publish only the committed identity, before descendant layout callbacks.
    // Do not notify subscribers or schedule React updates in insertion effects.
    useInsertionEffect(() => { actor.current = actorId; }, [actorId]);
    useLayoutEffect(() => { scope.observeActor(); }, [actorId, scope]);
    return <WriteContext.Provider value={scope}>{children}</WriteContext.Provider>;
}
const idle = (): TimesheetWriteState => 'idle';
const noSubscription = (_listener: () => void): (() => void) => () => {};
export function useTimesheetWriteCoordination() {
    const scope = useContext(WriteContext);
    const state = useSyncExternalStore(scope?.subscribe ?? noSubscription, scope?.snapshot ?? idle, idle);
    return { scope, state };
}
