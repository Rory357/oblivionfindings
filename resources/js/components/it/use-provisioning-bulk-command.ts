import axios from 'axios';
import { useEffect, useLayoutEffect, useRef, useState } from 'react';
import {
    newProvisioningUuid,
    readProvisioningOutcome,
    type ProvisioningCommandOutcome,
} from './use-provisioning-command';

export type ProvisioningBulkOperation = 'assign' | 'cancel' | 'retry';
export interface ProvisioningBulkReference {
    actorId: number;
    operation: ProvisioningBulkOperation;
    items: { id: number; version: number; requestUuid: string }[];
}
type RowPhase =
    | 'ready'
    | 'sending'
    | 'recovering'
    | 'cancelling'
    | 'committed'
    | 'cancelled'
    | 'not_found'
    | 'blocked'
    | 'conflict'
    | 'unknown'
    | 'denied';
export interface ProvisioningBulkRow {
    id: number;
    phase: RowPhase;
    message: string;
    needsNewReview: boolean;
    outcome: ProvisioningCommandOutcome | null;
}
const record = (value: unknown): value is Record<string, unknown> =>
    value !== null && typeof value === 'object' && !Array.isArray(value);
const positive = (value: unknown): value is number =>
    typeof value === 'number' && Number.isSafeInteger(value) && value > 0;
const uuid = (value: unknown): value is string =>
    typeof value === 'string' &&
    /^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i.test(
        value,
    );
export const provisioningBulkStorageKey = (actorId: number) =>
    'it.provisioning.bulk.v1.' + actorId;

export function readProvisioningBulkReference(
    value: unknown,
    actorId: number,
): ProvisioningBulkReference | null {
    if (
        !record(value) ||
        Object.keys(value).length !== 3 ||
        value.actorId !== actorId ||
        !['assign', 'cancel', 'retry'].includes(String(value.operation)) ||
        !Array.isArray(value.items) ||
        value.items.length < 1 ||
        value.items.length > 20
    )
        return null;
    const items: ProvisioningBulkReference['items'] = [];
    for (const row of value.items) {
        if (
            !record(row) ||
            Object.keys(row).length !== 3 ||
            !positive(row.id) ||
            !positive(row.version) ||
            !uuid(row.requestUuid)
        )
            return null;
        items.push({
            id: row.id,
            version: row.version,
            requestUuid: row.requestUuid.toLowerCase(),
        });
    }
    if (
        new Set(items.map((row) => row.id)).size !== items.length ||
        new Set(items.map((row) => row.requestUuid)).size !== items.length
    )
        return null;
    return {
        actorId,
        operation: value.operation as ProvisioningBulkOperation,
        items,
    };
}

export function hasProvisioningBulkReference(actorId: number): boolean {
    try {
        return (
            sessionStorage.getItem(provisioningBulkStorageKey(actorId)) !== null
        );
    } catch {
        return false;
    }
}

/** One retained reference per original row. Names and reviewed command fields stay in RAM. */
export function useProvisioningBulkCommand(
    actorId: number,
    initialSelection?: {
        operation: ProvisioningBulkOperation;
        items: { id: number; version: number }[];
    },
) {
    const [initial] = useState(() => {
        try {
            const saved = sessionStorage.getItem(
                provisioningBulkStorageKey(actorId),
            );
            if (saved !== null) {
                const reference = readProvisioningBulkReference(
                    JSON.parse(saved),
                    actorId,
                );
                if (!reference) throw new Error('Invalid retained reference');
                return { reference, restored: true, message: '' };
            }
            if (!initialSelection) throw new Error('No retained reference');
            const reference = readProvisioningBulkReference(
                {
                    actorId,
                    operation: initialSelection.operation,
                    items: initialSelection.items.map((row) => ({
                        ...row,
                        requestUuid: newProvisioningUuid(),
                    })),
                },
                actorId,
            );
            if (!reference) throw new Error('Invalid selection');
            return { reference, restored: false, message: '' };
        } catch {
            return {
                reference: null,
                restored: false,
                message:
                    'The browser recovery reference could not be read. Check the selected tasks before preparing another bulk action.',
            };
        }
    });
    const reference = initial.reference;
    const [rows, setRows] = useState<ProvisioningBulkRow[]>(() =>
        (reference?.items ?? []).map((row) => ({
            id: row.id,
            phase: initial.restored ? 'unknown' : 'ready',
            message: initial.restored
                ? 'Check the original command outcome.'
                : 'Ready for review.',
            needsNewReview: false,
            outcome: null,
        })),
    );
    const [message, setMessage] = useState(initial.message);
    const [busy, setBusy] = useState(false);
    const [hidden, setHidden] = useState(false);
    const [hasFrozen, setHasFrozen] = useState(false);
    const actor = useRef(actorId);
    const mounted = useRef(true);
    const busyRef = useRef(false);
    const hasDispatched = useRef(initial.restored);
    const epoch = useRef(0);
    const pending = useRef<AbortController | null>(null);
    const frozen = useRef<Record<string, unknown> | null>(null);
    useLayoutEffect(() => {
        if (actor.current === actorId) return;
        actor.current = actorId;
        epoch.current++;
        pending.current?.abort();
        pending.current = null;
        busyRef.current = false;
        frozen.current = null;
        setBusy(false);
        setHasFrozen(false);
        setHidden(true);
        setRows((currentRows) =>
            currentRows.map((row) =>
                ['sending', 'recovering', 'cancelling'].includes(row.phase)
                    ? {
                          ...row,
                          phase: 'unknown',
                          message:
                              'Account changed. Check the original outcome after signing in again.',
                      }
                    : row,
            ),
        );
    }, [actorId]);
    useEffect(() => {
        mounted.current = true;
        const invalidatePending = () => {
            epoch.current++;
        };
        return () => {
            mounted.current = false;
            invalidatePending();
            pending.current?.abort();
            frozen.current = null;
        };
    }, []);
    const concealed =
        hidden || (reference !== null && actorId !== reference.actorId);
    const terminal =
        rows.length > 0 &&
        rows.every((row) => ['committed', 'cancelled'].includes(row.phase));
    const serial = reference ? JSON.stringify(reference) : '';
    const removeOwnReference = () => {
        if (!reference) return;
        try {
            const saved = sessionStorage.getItem(
                provisioningBulkStorageKey(reference.actorId),
            );
            if (
                saved &&
                JSON.stringify(
                    readProvisioningBulkReference(
                        JSON.parse(saved),
                        reference.actorId,
                    ),
                ) === serial
            ) {
                sessionStorage.removeItem(
                    provisioningBulkStorageKey(reference.actorId),
                );
            }
        } catch {
            /* A retained terminal reference can still be checked later. */
        }
    };
    useEffect(() => {
        if (!terminal || !reference) return;
        const key = provisioningBulkStorageKey(reference.actorId);
        try {
            const saved = sessionStorage.getItem(key);
            if (
                saved &&
                JSON.stringify(
                    readProvisioningBulkReference(
                        JSON.parse(saved),
                        reference.actorId,
                    ),
                ) === serial
            )
                sessionStorage.removeItem(key);
        } catch {
            /* Keep the recoverable reference if storage is unavailable. */
        }
    }, [terminal, reference, serial]);

    const retain = (): boolean => {
        if (!reference) return false;
        try {
            const key = provisioningBulkStorageKey(reference.actorId);
            const saved = sessionStorage.getItem(key);
            if (
                saved !== null &&
                JSON.stringify(
                    readProvisioningBulkReference(
                        JSON.parse(saved),
                        reference.actorId,
                    ),
                ) !== serial
            ) {
                setMessage(
                    'Another bulk action needs recovery before this selection can be sent.',
                );
                return false;
            }
            sessionStorage.setItem(key, serial);
            if (sessionStorage.getItem(key) !== serial)
                throw new Error('Reference not retained');
            return true;
        } catch {
            setMessage(
                'The browser cannot retain the original command references. No further requests were sent.',
            );
            return false;
        }
    };
    const update = (id: number, changes: Partial<ProvisioningBulkRow>) =>
        setRows((current) =>
            current.map((row) =>
                row.id === id ? { ...row, ...changes } : row,
            ),
        );
    const current = (token: number) =>
        mounted.current &&
        epoch.current === token &&
        reference !== null &&
        actor.current === reference.actorId;

    const perform = async (
        item: ProvisioningBulkReference['items'][number],
        action: 'send' | 'recover' | 'cancel',
        token: number,
    ): Promise<boolean> => {
        if (!reference || !current(token)) return false;
        const abort = new AbortController();
        hasDispatched.current = true;
        pending.current = abort;
        update(item.id, {
            phase:
                action === 'send'
                    ? 'sending'
                    : action === 'recover'
                      ? 'recovering'
                      : 'cancelling',
            message: 'Checking the original command…',
        });
        const identity = {
            actorId: reference.actorId,
            kind: 'request' as const,
            targetId: item.id,
            operation: reference.operation,
            requestUuid: item.requestUuid,
        };
        const base =
            '/it/provisioning/commands/request/' +
            item.id +
            '/' +
            reference.operation;
        const binding = {
            actor_user_id: reference.actorId,
            request_uuid: item.requestUuid,
        };
        try {
            const options = {
                signal: abort.signal,
                timeout: 30000,
                headers: { Accept: 'application/json' },
            };
            const response =
                action === 'send'
                    ? await axios.post(
                          base,
                          {
                              ...frozen.current,
                              ...binding,
                              expected_version: item.version,
                          },
                          options,
                      )
                    : action === 'cancel'
                      ? await axios.post(base + '/cancel', binding, options)
                      : await axios.get(base, { ...options, params: binding });
            if (!current(token)) return false;
            const outcome = readProvisioningOutcome(response.data, identity);
            if (!outcome) {
                update(item.id, {
                    phase: 'unknown',
                    message:
                        'This response did not confirm the original command.',
                });
                return true;
            }
            update(item.id, {
                phase: outcome.status,
                outcome,
                message:
                    outcome.status === 'committed'
                        ? 'Recorded change confirmed.'
                        : outcome.status === 'cancelled'
                          ? 'Uncommitted command cancelled; no recorded change was undone.'
                          : 'No saved result exists. Check or cancel this original identity before preparing another change.',
            });
            return true;
        } catch (error) {
            if (!current(token)) return false;
            const status = axios.isAxiosError(error)
                ? error.response?.status
                : null;
            if ([401, 403, 404, 419].includes(status ?? 0)) {
                setHidden(true);
                frozen.current = null;
                setHasFrozen(false);
                update(item.id, {
                    phase: 'denied',
                    message:
                        'Account or access changed. Private details are hidden.',
                });
                setMessage(
                    'Further requests stopped. Sign in with the original account and check the retained outcomes.',
                );
                return false;
            }
            if (status === 409)
                update(item.id, {
                    phase: 'conflict',
                    needsNewReview: true,
                    message:
                        'This task changed. Check the outcome, cancel any unsent command, then review the current task.',
                });
            else if (status === 422)
                update(item.id, {
                    phase: 'blocked',
                    message:
                        'This change was not accepted. Check its outcome and review the task requirements.',
                });
            else
                update(item.id, {
                    phase: 'unknown',
                    message:
                        'The outcome is unconfirmed. Check this original command.',
                });
            return true;
        } finally {
            if (current(token)) pending.current = null;
        }
    };
    const run = async (
        ids: number[],
        action: 'send' | 'recover' | 'cancel',
    ) => {
        if (
            !reference ||
            concealed ||
            busyRef.current ||
            !mounted.current ||
            actor.current !== reference.actorId ||
            !retain()
        )
            return;
        if (action === 'send' && !frozen.current) return;
        busyRef.current = true;
        setBusy(true);
        const token = ++epoch.current;
        try {
            for (const item of reference.items.filter((row) =>
                ids.includes(row.id),
            )) {
                if (!current(token) || !(await perform(item, action, token)))
                    break;
            }
        } finally {
            if (current(token)) {
                busyRef.current = false;
                setBusy(false);
            }
        }
    };
    return {
        reference,
        rows,
        message,
        busy,
        concealed,
        terminal,
        restored: initial.restored,
        canSubmit:
            reference !== null &&
            !concealed &&
            !busy &&
            rows.length > 0 &&
            rows.every((row) => row.phase === 'ready'),
        submit: (payload: Record<string, unknown>) => {
            if (
                !reference ||
                concealed ||
                busyRef.current ||
                !rows.every((row) => row.phase === 'ready')
            )
                return;
            frozen.current = structuredClone(
                reference.operation === 'assign'
                    ? { assigned_to_user_id: payload.assigned_to_user_id }
                    : { reason: payload.reason },
            );
            setHasFrozen(true);
            void run(
                reference.items.map((row) => row.id),
                'send',
            );
        },
        recover: (id: number) => run([id], 'recover'),
        cancel: (id: number) => run([id], 'cancel'),
        canRetry: (id: number) =>
            hasFrozen &&
            rows.some(
                (row) =>
                    row.id === id &&
                    row.phase === 'not_found' &&
                    !row.needsNewReview,
            ),
        retry: (id: number) =>
            hasFrozen &&
            rows.some(
                (row) =>
                    row.id === id &&
                    row.phase === 'not_found' &&
                    !row.needsNewReview,
            )
                ? run([id], 'send')
                : Promise.resolve(),
        stopWaiting: () => {
            if (!busyRef.current) return;
            pending.current?.abort();
            pending.current = null;
            epoch.current++;
            busyRef.current = false;
            setBusy(false);
            setRows((currentRows) =>
                currentRows.map((row) =>
                    ['sending', 'recovering', 'cancelling'].includes(row.phase)
                        ? {
                              ...row,
                              phase: 'unknown',
                              message:
                                  'Waiting stopped; the server may still finish. Check the original outcome.',
                          }
                        : row,
                ),
            );
        },
        forgetIfSafe: () => {
            if (
                !busyRef.current &&
                (terminal ||
                    (!hasDispatched.current &&
                        rows.every((row) => row.phase === 'ready')))
            )
                removeOwnReference();
        },
    };
}
