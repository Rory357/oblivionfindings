import { useInsertionEffect, useLayoutEffect, useMemo, useState, useSyncExternalStore } from 'react';
import { HandoverWorkLifetime, type HandoverWorkContext } from './handover-work-lifetime';

/** Unmounted Operations/Attendance owner foundation. The caller supplies
 * authorised current context; this hook does not grant or fetch permission.
 * Shared eMAR callers do not import or invoke this hook. */
export function useHandoverWorkLifetime({
    actorId, recordId, expectedVersion, canRead, canWrite,
}: HandoverWorkContext) {
    const frame = useMemo(() => ({ actorId, recordId, expectedVersion, canRead, canWrite }),
        [actorId, recordId, expectedVersion, canRead, canWrite]);
    const [work] = useState(() => new HandoverWorkLifetime(frame));
    // Only the committed identity is published. A speculative render cannot
    // invalidate old work or install a new account's admission.
    useInsertionEffect(() => { work.publishContext(frame); }, [work, frame]);
    useInsertionEffect(() => () => { work.retire(); }, [work]);
    // UI notification occurs outside insertion so descendants' layout
    // callbacks already read the new context without scheduling state there.
    useLayoutEffect(() => { work.observeContext(); }, [work, frame]);
    const state = useSyncExternalStore(work.subscribe, work.snapshot, work.snapshot);
    return { work, state };
}
