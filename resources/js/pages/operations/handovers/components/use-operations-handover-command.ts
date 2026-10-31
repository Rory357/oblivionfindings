import axios from 'axios';
import { useInsertionEffect, useLayoutEffect, useState, useSyncExternalStore } from 'react';
import { HandoverCommandSession, type HandoverCommandState } from './handover-command-session';

const legacyState: HandoverCommandState = Object.freeze({ phase: 'editing', message: null, saved: null });
const legacySnapshot = () => legacyState;
const legacySubscribe = () => () => {};

/** Explicit Operations opt-in; the eMAR wizard has no session or transport. */
export function useOperationsHandoverCommand(enabled: boolean, actorId: number,
    context: { recordId: number | null; version: number | null; shiftId: number | null; canRead: boolean; canWrite: boolean }) {
    const [session] = useState(() => enabled ? new HandoverCommandSession(actorId, {
        request: async (method, url, body) => {
            const response = await axios.request({ method, url, data: body,
                validateStatus: () => true, headers: { Accept: 'application/json' } });
            return { status: response.status, data: response.data };
        },
    }) : null);
    useInsertionEffect(() => { session?.publishActor(actorId); }, [session, actorId]);
    const { recordId, version, shiftId, canRead, canWrite } = context;
    useInsertionEffect(() => { session?.publishContext({ recordId, version, shiftId, canRead, canWrite }); },
        [session, recordId, version, shiftId, canRead, canWrite]);
    useInsertionEffect(() => () => { session?.retire(); }, [session]);
    useLayoutEffect(() => { session?.notify(); }, [session, actorId, recordId, version, shiftId, canRead, canWrite]);
    const state = useSyncExternalStore(session?.subscribe ?? legacySubscribe,
        session?.snapshot ?? legacySnapshot, session?.snapshot ?? legacySnapshot);
    return { session, state };
}
