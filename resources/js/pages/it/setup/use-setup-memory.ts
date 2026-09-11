import axios from 'axios';
import { useEffect, useRef, useState, useSyncExternalStore } from 'react';

export type SetupResource = 'teams' | 'queues' | 'services';
export type SetupFields = Record<string, unknown>;
export type SetupScope = {
    user_ids?: number[];
    site_ids?: number[];
    team_ids?: number[];
    service_ids?: number[];
};
export type SetupWork = {
    configuration_version: string | null;
    base_fields: SetupFields;
    fields: SetupFields;
    step_index: number;
    outcomeUnknown: boolean;
    submitted?: SetupFields | null;
    command_uuid?: string | null;
};
type Candidate = SetupWork & {
    actor_user_id: number;
    resource: SetupResource;
    record_id: number | null;
    context_uuid: string;
    bound_scopes: SetupScope[];
};
type Entry = {
    id: string;
    candidate: Candidate;
    owner: symbol | null;
    bytes: number;
    settled: number;
};
export type SetupProof = {
    current_configuration_version: string | null;
    capabilities: { submit: boolean };
    blocker: 'configuration_changed' | null;
};
const entries = new Map<string, Entry>();
const listeners = new Set<() => void>();
let revision = 0;
let actor: number | undefined;
let unloadRegistered = false;
const warnOnUnload = (event: BeforeUnloadEvent) => {
    event.preventDefault();
    event.returnValue = '';
};
const changed = () => {
    if (typeof window !== 'undefined') {
        if (entries.size && !unloadRegistered) {
            window.addEventListener('beforeunload', warnOnUnload);
            unloadRegistered = true;
        } else if (!entries.size && unloadRegistered) {
            window.removeEventListener('beforeunload', warnOnUnload);
            unloadRegistered = false;
        }
    }
    revision++;
    listeners.forEach((notify) => notify());
};
const subscribe = (notify: () => void) => {
    listeners.add(notify);
    return () => listeners.delete(notify);
};
const snapshot = () => revision;
const ceiling = 64 * 1024 * 1024;
const exact = (value: unknown) => JSON.stringify(value);
const ids = (values: unknown[]) => [
    ...new Set(
        values.filter(
            (id): id is number =>
                Number.isSafeInteger(id) && (id as number) > 0,
        ),
    ),
];
const scopeFor = (fields: SetupFields): SetupScope => ({
    user_ids: ids([
        fields.manager_user_id,
        fields.owner_user_id,
        fields.default_assignee_user_id,
        fields.cover_user_id,
        ...(Array.isArray(fields.members)
            ? fields.members.map(
                  (member) => (member as { user_id?: unknown }).user_id,
              )
            : []),
    ]),
    site_ids: ids(Array.isArray(fields.site_ids) ? fields.site_ids : []),
    team_ids: ids([fields.team_id]),
    service_ids: ids(
        Array.isArray(fields.service_ids) ? fields.service_ids : [],
    ),
});
export function clearSetupMemory() {
    entries.clear();
    actor = undefined;
    changed();
}

async function requestCandidate(
    candidate: Candidate,
    nonce: string,
    signal: AbortSignal,
) {
    return axios.post(
        '/it/setup/validate-candidate',
        {
            actor_user_id: candidate.actor_user_id,
            resource: candidate.resource,
            record_id: candidate.record_id,
            context_uuid: candidate.context_uuid,
            candidate_uuid: nonce,
            configuration_version: candidate.configuration_version,
            base_fields: candidate.base_fields,
            fields: candidate.fields,
            step_index: candidate.step_index,
            bound_scopes: candidate.bound_scopes,
        },
        {
            signal,
            headers: { Accept: 'application/json' },
            validateStatus: () => true,
        },
    );
}
function verifiedProof(
    status: number,
    data: unknown,
    candidate: Candidate,
    nonce: string,
): SetupProof {
    const proof = (data as { candidate?: Record<string, unknown> } | null)
        ?.candidate;
    if (
        status !== 200 ||
        !proof ||
        proof.authorized !== true ||
        proof.candidate_uuid !== nonce ||
        proof.actor_user_id !== candidate.actor_user_id ||
        proof.resource !== candidate.resource ||
        proof.record_id !== candidate.record_id ||
        proof.context_uuid !== candidate.context_uuid ||
        proof.configuration_version !== candidate.configuration_version ||
        (candidate.record_id === null
            ? proof.current_configuration_version !== null
            : typeof proof.current_configuration_version !== 'string' ||
              !/^[a-f0-9]{64}$/.test(proof.current_configuration_version)) ||
        typeof (proof.capabilities as { submit?: unknown } | null)?.submit !==
            'boolean' ||
        (proof.blocker !== null && proof.blocker !== 'configuration_changed')
    ) {
        throw new Error(
            'Current access could not be verified. Your retained form has not been opened.',
        );
    }
    return proof as SetupProof;
}

export function useSetupMemory(options: {
    active: boolean;
    actorId?: number;
    resource: SetupResource;
    recordId: number | null;
    work: SetupWork;
    dirty: boolean;
    settledToken?: number;
    timeoutMs?: number;
    acceptsWork?: (work: SetupWork) => boolean;
    canRecover?: boolean;
    commandPendingOnly?: boolean;
}) {
    const owner = useRef(Symbol('setup-form'));
    const owned = useRef<string | null>(null);
    const context = useRef<string>(crypto.randomUUID());
    const observed = useRef<{ actorId?: number; initialized: boolean }>({
        initialized: false,
    });
    const suppressed = useRef<string | null>(null);
    const history = useRef<SetupScope[]>([]);
    const observedScope = useRef<string | null>(null);
    const latest = useRef(options);
    latest.current = options;
    const controller = useRef<AbortController | null>(null);
    const epoch = useRef(0);
    const [busy, setBusy] = useState(false);
    const [warning, setWarning] = useState<string | null>(null);
    const [failure, setFailure] = useState<'session_expired' | 'denied' | null>(
        null,
    );
    useSyncExternalStore(subscribe, snapshot, snapshot);
    const workKey = exact(options.work);
    const release = () => {
        const entry = owned.current ? entries.get(owned.current) : undefined;
        if (entry?.owner === owner.current) {
            entry.owner = null;
            changed();
        }
        owned.current = null;
    };
    const clearOwned = () => {
        suppressed.current = exact(latest.current.work);
        const entry = owned.current ? entries.get(owned.current) : undefined;
        if (entry?.owner === owner.current) {
            entries.delete(entry.id);
            changed();
        }
        owned.current = null;
        history.current = [];
        context.current = crypto.randomUUID();
    };
    useEffect(() => {
        if (
            !observed.current.initialized ||
            observed.current.actorId !== options.actorId
        ) {
            observed.current = { initialized: true, actorId: options.actorId };
            if (actor !== options.actorId) {
                entries.clear();
                actor = options.actorId;
                changed();
            }
        }
        const scopeKey = `${options.actorId}:${options.resource}:${options.recordId}`;
        if (observedScope.current !== scopeKey) {
            epoch.current++;
            controller.current?.abort();
            controller.current = null;
            setBusy(false);
            release();
            history.current = [];
            context.current = crypto.randomUUID();
            suppressed.current = null;
            observedScope.current = scopeKey;
        }
        if (!options.active) {
            epoch.current++;
            controller.current?.abort();
            controller.current = null;
            setBusy(false);
            release();
            history.current = [];
            context.current = crypto.randomUUID();
            return;
        }
        if (!options.actorId || actor !== options.actorId) return;
        const current = owned.current ? entries.get(owned.current) : undefined;
        if (
            current?.candidate.outcomeUnknown &&
            (!options.work.outcomeUnknown ||
                exact(current.candidate.submitted) !==
                    exact(options.work.submitted) ||
                current.candidate.configuration_version !==
                    options.work.configuration_version ||
                current.candidate.command_uuid !== options.work.command_uuid) &&
            current.settled === (options.settledToken ?? 0)
        ) {
            setWarning(
                'The previous save is still unconfirmed. Review its outcome before changing this retained copy.',
            );
            return;
        }
        if (!options.dirty && !options.work.outcomeUnknown) {
            if (current) clearOwned();
            return;
        }
        if (suppressed.current === workKey) return;
        suppressed.current = null;
        const scopes = [...history.current];
        for (const fields of [
            options.work.base_fields,
            options.work.fields,
            options.work.submitted ?? {},
        ]) {
            const next = scopeFor(fields);
            if (!scopes.some((scope) => exact(scope) === exact(next)))
                scopes.push(next);
        }
        if (scopes.length > 100) {
            setWarning(
                'This form has reached the recovery scope limit. Keep it open, or deliberately discard this form before starting again.',
            );
            return;
        }
        const candidate: Candidate = structuredClone({
            ...options.work,
            actor_user_id: options.actorId,
            resource: options.resource,
            record_id: options.recordId,
            context_uuid: context.current,
            bound_scopes: scopes,
        });
        const bytes = new TextEncoder().encode(exact(candidate)).byteLength;
        const used =
            [...entries.values()].reduce(
                (total, entry) => total + entry.bytes,
                0,
            ) - (current?.bytes ?? 0);
        if ((!current && entries.size >= 20) || used + bytes > ceiling) {
            setWarning(
                'Browser recovery is full. Keep this form open, or deliberately discard an older retained form to make room.',
            );
            return;
        }
        history.current = scopes;
        const id = current?.id ?? crypto.randomUUID();
        const next = {
            id,
            candidate,
            owner: owner.current,
            bytes,
            settled: options.settledToken ?? 0,
        };
        if (
            current &&
            exact(current.candidate) === exact(candidate) &&
            current.settled === next.settled
        )
            return;
        owned.current = id;
        entries.set(id, next);
        changed();
        setWarning(null);
    }, [
        options.active,
        options.actorId,
        options.resource,
        options.recordId,
        options.dirty,
        options.settledToken,
        options.work,
        workKey,
    ]);
    useEffect(
        () => () => {
            epoch.current++;
            controller.current?.abort();
            release();
        },
        [],
    );
    const notices =
        actor === options.actorId
            ? [...entries.values()]
                  .filter(
                      (entry) =>
                          entry.owner === null &&
                          entry.candidate.actor_user_id === options.actorId &&
                          entry.candidate.resource === options.resource &&
                          entry.candidate.record_id === options.recordId,
                  )
                  .map((entry) => ({
                      id: entry.id,
                      unknown: entry.candidate.outcomeUnknown,
                  }))
            : [];
    const cancel = () => {
        epoch.current++;
        controller.current?.abort();
        controller.current = null;
        setBusy(false);
    };
    const resume = async (
        id: string,
    ): Promise<{ work: SetupWork; proof: SetupProof } | null> => {
        const entry = entries.get(id);
        const canReplaceCurrent = () =>
            !latest.current.dirty &&
            latest.current.canRecover !== false &&
            (!latest.current.work.outcomeUnknown ||
                (latest.current.commandPendingOnly === true &&
                    latest.current.recordId === null &&
                    !latest.current.work.submitted &&
                    !!latest.current.work.command_uuid &&
                    latest.current.work.command_uuid ===
                        entry?.candidate.command_uuid));
        if (controller.current || busy || !canReplaceCurrent()) {
            setWarning(
                'Keep or discard the current form before resuming another retained form.',
            );
            return null;
        }
        const binding = latest.current;
        if (
            !entry ||
            entry.owner ||
            entry.candidate.actor_user_id !== binding.actorId ||
            actor !== binding.actorId ||
            entry.candidate.resource !== binding.resource ||
            entry.candidate.record_id !== binding.recordId
        )
            return null;
        const candidate = structuredClone(entry.candidate);
        const candidateUuid = crypto.randomUUID();
        const abort = new AbortController();
        controller.current = abort;
        const operation = ++epoch.current;
        setBusy(true);
        setWarning(null);
        setFailure(null);
        const timer = setTimeout(
            () => abort.abort(),
            options.timeoutMs ?? 15000,
        );
        try {
            const response = await requestCandidate(
                candidate,
                candidateUuid,
                abort.signal,
            );
            if (
                operation !== epoch.current ||
                abort.signal.aborted ||
                actor !== binding.actorId ||
                latest.current.actorId !== binding.actorId ||
                latest.current.resource !== binding.resource ||
                latest.current.recordId !== binding.recordId ||
                !latest.current.active
            )
                return null;
            if (response.status === 401 || response.status === 419) {
                setFailure('session_expired');
                throw new Error(
                    'Sign in again, then retry Resume. Retained values remain concealed.',
                );
            }
            if (response.status === 403 || response.status === 404) {
                entries.delete(id);
                changed();
                setFailure('denied');
                throw new Error(
                    'This retained form is no longer available to your current access.',
                );
            }
            const proof = verifiedProof(
                response.status,
                response.data,
                candidate,
                candidateUuid,
            );
            if (
                latest.current.acceptsWork &&
                !latest.current.acceptsWork(structuredClone(candidate))
            ) {
                setWarning(
                    'Recover the pending create for this form before opening another retained proposal.',
                );
                return null;
            }
            if (
                entries.get(id) !== entry ||
                entry.owner ||
                !canReplaceCurrent()
            )
                return null;
            entries.delete(id);
            // A reloaded command marker contains no original fields. Replace
            // only this mounted placeholder after proof for its exact UUID.
            if (owned.current) {
                const placeholder = entries.get(owned.current);
                if (placeholder?.owner === owner.current)
                    entries.delete(owned.current);
                owned.current = null;
            }
            changed();
            context.current = candidate.context_uuid;
            history.current = candidate.bound_scopes;
            return { work: candidate, proof };
        } catch (error) {
            if (operation === epoch.current)
                setWarning(
                    abort.signal.aborted
                        ? 'The recovery wait ended. Your retained form is still available.'
                        : error instanceof Error
                          ? error.message
                          : 'Recovery failed. Try again.',
                );
            return null;
        } finally {
            clearTimeout(timer);
            if (operation === epoch.current) {
                setBusy(false);
                controller.current = null;
            }
        }
    };
    const discard = (id: string) => {
        const entry = entries.get(id);
        if (
            !entry ||
            entry.owner ||
            entry.candidate.actor_user_id !== latest.current.actorId ||
            actor !== latest.current.actorId ||
            entry.candidate.resource !== latest.current.resource ||
            entry.candidate.record_id !== latest.current.recordId
        )
            return;
        entries.delete(id);
        changed();
    };
    const checkCurrentAccess = async (): Promise<
        SetupProof | 'denied' | null
    > => {
        const binding = latest.current;
        if (
            controller.current ||
            busy ||
            !binding.active ||
            !binding.actorId ||
            actor !== binding.actorId
        )
            return null;
        const candidate: Candidate = structuredClone({
            ...binding.work,
            actor_user_id: binding.actorId,
            resource: binding.resource,
            record_id: binding.recordId,
            context_uuid: context.current,
            bound_scopes: [
                ...history.current,
                scopeFor(binding.work.base_fields),
                scopeFor(binding.work.fields),
                scopeFor(binding.work.submitted ?? {}),
            ].filter(
                (scope, index, scopes) =>
                    scopes.findIndex(
                        (entry) => exact(scope) === exact(entry),
                    ) === index,
            ),
        });
        const nonce = crypto.randomUUID();
        const abort = new AbortController();
        controller.current = abort;
        const operation = ++epoch.current;
        setBusy(true);
        setWarning(null);
        setFailure(null);
        const timer = setTimeout(
            () => abort.abort(),
            options.timeoutMs ?? 15000,
        );
        try {
            const response = await requestCandidate(
                candidate,
                nonce,
                abort.signal,
            );
            if (
                operation !== epoch.current ||
                abort.signal.aborted ||
                actor !== binding.actorId ||
                latest.current.actorId !== binding.actorId ||
                !latest.current.active ||
                exact(latest.current.work) !== exact(binding.work)
            )
                return null;
            if (response.status === 401 || response.status === 419) {
                setFailure('session_expired');
                throw new Error(
                    'Sign in again, then check access. Your entered values remain concealed.',
                );
            }
            if (response.status === 403 || response.status === 404) {
                clearOwned();
                setFailure('denied');
                setWarning(
                    'Your current access does not allow this form. Its retained browser copy was removed.',
                );
                return 'denied';
            }
            return verifiedProof(
                response.status,
                response.data,
                candidate,
                nonce,
            );
        } catch (error) {
            if (operation === epoch.current)
                setWarning(
                    abort.signal.aborted
                        ? 'The access check ended. Your entered work remains concealed.'
                        : error instanceof Error
                          ? error.message
                          : 'Access could not be verified.',
                );
            return null;
        } finally {
            clearTimeout(timer);
            if (operation === epoch.current) {
                setBusy(false);
                controller.current = null;
            }
        }
    };
    const acknowledgeCommand = (requestUuid: string) => {
        for (const [id, entry] of entries) {
            if (
                entry.candidate.actor_user_id === latest.current.actorId &&
                actor === latest.current.actorId &&
                entry.candidate.resource === latest.current.resource &&
                entry.candidate.command_uuid === requestUuid
            ) {
                entries.delete(id);
                if (owned.current === id) owned.current = null;
            }
        }
        changed();
    };
    return {
        notices,
        warning,
        failure,
        busy,
        resume,
        cancel,
        discard,
        clearOwned,
        checkCurrentAccess,
        acknowledgeCommand,
    };
}
