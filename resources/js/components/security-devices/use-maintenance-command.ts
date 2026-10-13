import axios from 'axios';
import { useEffect, useRef, useState } from 'react';
import {
    canonicalMaintenanceInput,
    forgetMaintenancePending,
    keepMaintenancePending,
    loadMaintenancePending,
    maintenanceCommandHash,
    maintenanceCommandReply,
    type MaintenanceCommandReply,
    type MaintenanceCommandScope,
    type MaintenanceOperation,
    type MaintenancePending,
} from './maintenance-command';

type Snapshot = {
    phase: 'idle' | 'sending' | 'unconfirmed' | 'recovering';
    pending: MaintenancePending | null;
    corrupt: boolean;
    message: string | null;
    errors: Record<string, string>;
};
function initial(actor?: number): Snapshot {
    try {
        const stored = loadMaintenancePending(actor);
        return {
            phase: stored.pending || stored.corrupt ? 'unconfirmed' : 'idle',
            ...stored,
            message: stored.pending
                ? 'An earlier maintenance save is unconfirmed. Check its original request before making another change.'
                : stored.corrupt
                  ? 'The maintenance recovery record cannot be verified. Review the list and contact an administrator.'
                  : null,
            errors: {},
        };
    } catch {
        return {
            phase: 'unconfirmed',
            pending: null,
            corrupt: true,
            message:
                'This browser cannot keep a maintenance recovery record. Reopen settings in a supported browser.',
            errors: {},
        };
    }
}
const base = '/security-devices/settings/monitoring/maintenance';
export function useMaintenanceCommand(options: {
    actorId?: number;
    active: boolean;
    context: string;
}) {
    const [snapshot, setSnapshot] = useState(() => initial(options.actorId));
    const current = useRef(snapshot);
    const latest = useRef(options);
    latest.current = options;
    const alive = useRef(true),
        epoch = useRef(0),
        call = useRef<AbortController | null>(null);
    const frozen = useRef<{
        body: Record<string, unknown>;
        pending: MaintenancePending;
    } | null>(null);
    const update = (value: Snapshot) => {
        current.current = value;
        setSnapshot(value);
    };
    useEffect(() => {
        const generation = epoch;
        alive.current = true;
        return () => {
            alive.current = false;
            generation.current += 1;
            call.current?.abort();
            frozen.current = null;
        };
    }, []);
    useEffect(() => {
        epoch.current++;
        call.current?.abort();
        call.current = null;
        frozen.current = null;
        update(initial(options.actorId));
    }, [options.actorId, options.active, options.context]);
    const active = () =>
        alive.current && latest.current.active && !!latest.current.actorId;
    const success = (
        value: unknown,
        p: MaintenancePending,
    ): MaintenanceCommandReply => {
        const reply = maintenanceCommandReply(value, p);
        forgetMaintenancePending(p.request_uuid);
        frozen.current = null;
        update({
            ...current.current,
            phase: 'idle',
            pending: null,
            corrupt: false,
            errors: {},
            message:
                reply.current_window.state === 'cancelled'
                    ? reply.window.state === 'cancelled'
                        ? 'Maintenance window cancelled.'
                        : 'Your maintenance change was saved. This window has since been cancelled.'
                    : reply.current_window.version > reply.window.version
                      ? 'Your maintenance change was saved. The window has been updated since then; review its latest details.'
                      : reply.current_window.state === 'completed'
                        ? 'Maintenance change saved. This window has finished.'
                        : 'Maintenance window saved.',
        });
        return reply;
    };
    const uncertain = (p: MaintenancePending | null) =>
        update({
            ...current.current,
            phase: 'unconfirmed',
            pending: p,
            errors: {},
            message:
                'The save could not be confirmed. Check its original request before submitting another change.',
        });
    const sendFrozen = async (): Promise<MaintenanceCommandReply | null> => {
        const original = frozen.current;
        if (!original || !active() || call.current) return null;
        const token = ++epoch.current,
            abort = new AbortController();
        call.current = abort;
        update({
            ...current.current,
            phase: 'sending',
            pending: original.pending,
            message: null,
            errors: {},
        });
        const path =
            original.pending.target_window_id === null
                ? base
                : base +
                  '/' +
                  original.pending.target_window_id +
                  (original.pending.operation === 'cancel' ? '/cancel' : '');
        try {
            const config = {
                signal: abort.signal,
                timeout: 15000,
                headers: { Accept: 'application/json' },
            };
            const response =
                original.pending.operation === 'update'
                    ? await axios.patch(
                          path,
                          structuredClone(original.body),
                          config,
                      )
                    : await axios.post(
                          path,
                          structuredClone(original.body),
                          config,
                      );
            if (token !== epoch.current || !active()) return null;
            return success(response.data, original.pending);
        } catch (error) {
            if (token !== epoch.current || !active()) return null;
            if (axios.isAxiosError(error) && error.response?.status === 422) {
                const raw = error.response.data?.errors;
                const errors =
                    raw && typeof raw === 'object'
                        ? Object.fromEntries(
                              Object.entries(raw).map(([key, value]) => [
                                  key,
                                  Array.isArray(value)
                                      ? String(value[0])
                                      : String(value),
                              ]),
                          )
                        : {
                              form: 'Review the maintenance fields and try again.',
                          };
                try {
                    forgetMaintenancePending(original.pending.request_uuid);
                } catch {
                    uncertain(original.pending);
                    return null;
                }
                frozen.current = null;
                update({
                    ...current.current,
                    phase: 'idle',
                    pending: null,
                    message: null,
                    errors,
                });
            } else uncertain(original.pending);
            return null;
        } finally {
            if (token === epoch.current) call.current = null;
        }
    };
    const submit = async (
        operation: MaintenanceOperation,
        target: number | null,
        body: Record<string, unknown>,
        scope: MaintenanceCommandScope,
    ): Promise<MaintenanceCommandReply | null> => {
        if (
            !active() ||
            call.current ||
            current.current.phase !== 'idle' ||
            current.current.pending ||
            current.current.corrupt
        )
            return null;
        const token = ++epoch.current;
        update({
            ...current.current,
            phase: 'sending',
            message: null,
            errors: {},
        });
        try {
            const pendingBody = canonicalMaintenanceInput(
                operation,
                structuredClone({
                    ...body,
                    request_uuid: crypto.randomUUID(),
                    expected_actor_id: latest.current.actorId,
                }),
            );
            const hash = await maintenanceCommandHash(
                operation,
                target,
                pendingBody,
            );
            if (token !== epoch.current || !active()) return null;
            const pending: MaintenancePending = {
                schema_version: 1,
                actor_id: latest.current.actorId!,
                request_uuid: String(pendingBody.request_uuid),
                operation,
                target_window_id: target,
                expected_version:
                    operation === 'create' ? null : Number(body.version),
                request_hash: hash,
                scope,
            };
            keepMaintenancePending(pending);
            frozen.current = { body: pendingBody, pending };
            return await sendFrozen();
        } catch {
            if (token === epoch.current && active())
                update({
                    ...current.current,
                    phase: 'unconfirmed',
                    corrupt: true,
                    message:
                        'This browser could not keep a verified recovery record. No new request was sent.',
                    errors: {},
                });
            return null;
        }
    };
    const recover = async (): Promise<MaintenanceCommandReply | null> => {
        const pending = current.current.pending;
        if (!pending || !active() || call.current) return null;
        const token = ++epoch.current,
            abort = new AbortController();
        call.current = abort;
        update({
            ...current.current,
            phase: 'recovering',
            message: null,
            errors: {},
        });
        try {
            const response = await axios.get(
                base + '/requests/' + pending.request_uuid,
                {
                    params: {
                        expected_actor_id: latest.current.actorId,
                        site_id: pending.scope.site_id,
                    },
                    signal: abort.signal,
                    timeout: 15000,
                    headers: { Accept: 'application/json' },
                },
            );
            if (token !== epoch.current || !active()) return null;
            return success(response.data, pending);
        } catch {
            if (token === epoch.current && active()) uncertain(pending);
            return null;
        } finally {
            if (token === epoch.current) call.current = null;
        }
    };
    return {
        ...snapshot,
        blocked:
            snapshot.phase !== 'idle' || !!snapshot.pending || snapshot.corrupt,
        busy: snapshot.phase === 'sending' || snapshot.phase === 'recovering',
        canRetry: !!frozen.current && snapshot.phase === 'unconfirmed',
        submit,
        recover,
        retry: sendFrozen,
        clearErrors: () => {
            if (current.current.phase === 'idle')
                update({ ...current.current, errors: {} });
        },
    };
}
