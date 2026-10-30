import { createContext, useContext, useInsertionEffect, useLayoutEffect, useState,
    useSyncExternalStore, type ReactNode } from 'react';
import { TimesheetWriteScope, type TimesheetWriteState } from './timesheet-write-scope';

const WriteContext = createContext<TimesheetWriteScope | null>(null);
function createWriteOwner(initialActor: unknown) {
    let committedActor = initialActor;
    return {
        scope: new TimesheetWriteScope(() => committedActor),
        publishActor: (actor: unknown) => { committedActor = actor; },
    };
}
/** Owning page integration and every mutation/read adapter are prerequisites.
 * This context does not grant permission or prove a server outcome. */
export function TimesheetWriteProvider({ actorId, children }: {
    actorId: unknown; children: ReactNode;
}) {
    const [owner] = useState(() => createWriteOwner(actorId));
    // Publish only the committed identity, before descendant layout callbacks.
    // Do not notify subscribers or schedule React updates in insertion effects.
    useInsertionEffect(() => { owner.publishActor(actorId); }, [actorId, owner]);
    useLayoutEffect(() => { owner.scope.observeActor(); }, [actorId, owner]);
    return <WriteContext.Provider value={owner.scope}>{children}</WriteContext.Provider>;
}
const idle = (): TimesheetWriteState => 'idle';
const noSubscription = (_listener: () => void): (() => void) => () => {};
export function useTimesheetWriteCoordination() {
    const scope = useContext(WriteContext);
    const state = useSyncExternalStore(scope?.subscribe ?? noSubscription, scope?.snapshot ?? idle, idle);
    return { scope, state };
}
