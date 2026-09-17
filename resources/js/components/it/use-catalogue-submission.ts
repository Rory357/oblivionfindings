import type { ItDraftCommitReference } from '@/hooks/it-ticket-draft-contract';
import axios from 'axios';
import { useEffect, useRef, useState } from 'react';
import { catalogueFiles } from './catalogue-attachments';
import type { CatalogValue } from './catalogue-request-fields';
import {
    isCatalogueSubmissionIdentity,
    readCatalogueSubmissionOutcome,
    type CatalogueSubmissionIdentity,
    type CatalogueSubmissionResult,
} from './catalogue-submission-contract';

export type CatalogueRequestValues = Record<string, CatalogValue>;
type Phase =
    | 'idle'
    | 'validation'
    | 'sending'
    | 'recovering'
    | 'cancelling'
    | 'unknown'
    | 'not_found'
    | 'session'
    | 'denied'
    | 'conflict'
    | 'committed'
    | 'cancelled'
    | 'unavailable';
type Payload = {
    actor_user_id: number;
    idempotency_key: string;
    schema_version: number;
    values: CatalogueRequestValues;
    site_id: number | null;
    requested_for_user_id?: number | null;
} & Partial<ItDraftCommitReference> & { staged_attachment_ids?: number[] };

// File bytes are immutable. Copy each mutable array while retaining its actual
// File objects; JSON serialization (and some structuredClone implementations)
// would turn them into empty objects and lose the evidence.
function copyValues(values: CatalogueRequestValues): CatalogueRequestValues {
    return Object.fromEntries(
        Object.entries(values).map(([key, value]) => [
            key,
            Array.isArray(value) ? [...value] : value,
        ]),
    ) as CatalogueRequestValues;
}

function submissionBody(payload: Payload | null): Payload | FormData | null {
    if (
        !payload ||
        !Object.values(payload.values).some(
            (value) => catalogueFiles(value).length > 0,
        )
    )
        return payload;
    const form = new FormData();
    const append = (key: string, value: CatalogValue | number | undefined) => {
        if (value === undefined) return;
        if (Array.isArray(value)) {
            value.forEach((entry, index) =>
                form.append(
                    `${key}[${index}]`,
                    entry instanceof File ? entry : String(entry),
                ),
            );
        } else {
            form.append(
                key,
                value === null
                    ? ''
                    : typeof value === 'boolean'
                      ? value
                          ? '1'
                          : '0'
                      : String(value),
            );
        }
    };
    for (const [key, value] of Object.entries(payload)) {
        if (key === 'values') continue;
        append(key, value as CatalogValue | number | undefined);
    }
    for (const [key, value] of Object.entries(payload.values))
        append(`values[${key}]`, value);
    return form;
}
const keyFor = (actorId: number, itemId: number) =>
    `it.catalogue.pending.v1.actor.${actorId}.item.${itemId}`;
const record = (value: unknown): value is Record<string, unknown> =>
    value !== null && typeof value === 'object' && !Array.isArray(value);

function pending(
    actorId: number,
    itemId: number,
): CatalogueSubmissionIdentity | null {
    try {
        const value: unknown = JSON.parse(
            sessionStorage.getItem(keyFor(actorId, itemId)) ?? 'null',
        );
        return isCatalogueSubmissionIdentity(value) &&
            value.actorId === actorId &&
            value.itemId === itemId
            ? value
            : null;
    } catch {
        return null;
    }
}

/** Mounted inside the actor/item keyed wizard; only identity metadata is persisted. */
export function useCatalogueSubmission(options: {
    actorId: number;
    itemId: number;
    schemaVersion: number;
    draftIdentity?: CatalogueSubmissionIdentity | null;
    timeoutMs?: number;
    onDenied: () => void;
    onSession: () => void;
}) {
    const [initial] = useState(() => {
        const restored = pending(options.actorId, options.itemId);
        let requestUuid = '';
        try {
            requestUuid = crypto.randomUUID();
        } catch {
            /* Fail closed if secure identity is unavailable. */
        }
        return {
            restored,
            identity: restored ??
                (options.draftIdentity &&
                isCatalogueSubmissionIdentity(options.draftIdentity) &&
                options.draftIdentity.actorId === options.actorId &&
                options.draftIdentity.itemId === options.itemId
                    ? options.draftIdentity
                    : null) ?? {
                    actorId: options.actorId,
                    itemId: options.itemId,
                    schemaVersion: options.schemaVersion,
                    requestUuid,
                },
        };
    });
    const identity = useRef(initial.identity);
    const [phase, setPhase] = useState<Phase>(
        initial.restored
            ? 'unknown'
            : isCatalogueSubmissionIdentity(initial.identity)
              ? 'idle'
              : 'unavailable',
    );
    const [message, setMessage] = useState<string | null>(
        initial.restored
            ? 'An earlier request is unconfirmed. Check its saved result before submitting again.'
            : !isCatalogueSubmissionIdentity(initial.identity)
              ? 'A secure request identity is unavailable. Reload this page before submitting.'
              : null,
    );
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [recoveryStored, setRecoveryStored] = useState(
        initial.restored !== null,
    );
    const [result, setResult] = useState<CatalogueSubmissionResult | null>(
        null,
    );
    const currentPhase = useRef(phase);
    currentPhase.current = phase;
    const frozen = useRef<Payload | null>(null);
    const controller = useRef<AbortController | null>(null);
    const epoch = useRef(0);
    const latest = useRef(options);
    latest.current = options;
    const transition = (next: Phase, detail: string | null = null) => {
        currentPhase.current = next;
        setPhase(next);
        setMessage(detail);
    };
    const remember = (binding: CatalogueSubmissionIdentity): boolean => {
        let stored = false;
        try {
            sessionStorage.setItem(
                keyFor(binding.actorId, binding.itemId),
                JSON.stringify(binding),
            );
            stored =
                pending(binding.actorId, binding.itemId)?.requestUuid ===
                binding.requestUuid;
        } catch {
            /* Private values never enter fallback storage. */
        }
        setRecoveryStored(stored);
        return stored;
    };
    const clearMarker = (binding: CatalogueSubmissionIdentity) => {
        try {
            if (
                pending(binding.actorId, binding.itemId)?.requestUuid ===
                binding.requestUuid
            )
                sessionStorage.removeItem(
                    keyFor(binding.actorId, binding.itemId),
                );
        } catch {
            /* RAM-only use remains available. */
        }
    };
    useEffect(
        () => () => {
            epoch.current++;
            controller.current?.abort();
            frozen.current = null;
        },
        [],
    );

    const execute = async (operation: 'submit' | 'recover' | 'cancel') => {
        if (
            controller.current ||
            ['denied', 'committed', 'cancelled', 'unavailable'].includes(
                currentPhase.current,
            ) ||
            !isCatalogueSubmissionIdentity(identity.current)
        )
            return;
        const binding = { ...identity.current };
        if (
            binding.actorId !== latest.current.actorId ||
            binding.itemId !== latest.current.itemId
        )
            return;
        if (operation === 'submit' && !frozen.current) return;
        const prior = pending(binding.actorId, binding.itemId);
        if (prior && prior.requestUuid !== binding.requestUuid) {
            identity.current = prior;
            frozen.current = null;
            setRecoveryStored(true);
            transition(
                'unknown',
                'An earlier view has an unconfirmed request for this form. Check that request first.',
            );
            return;
        }
        if (
            !remember(binding) &&
            operation === 'submit' &&
            ['idle', 'validation'].includes(currentPhase.current)
        ) {
            frozen.current = null;
            setErrors({
                idempotency_key:
                    'Browser recovery storage is unavailable. Enable it before submitting; your request has not been sent.',
            });
            transition('validation');
            return;
        }
        const wasUncertain = !['idle', 'validation'].includes(
            currentPhase.current,
        );
        const abort = new AbortController();
        controller.current = abort;
        const operationEpoch = ++epoch.current;
        setErrors({});
        transition(
            operation === 'submit'
                ? 'sending'
                : operation === 'recover'
                  ? 'recovering'
                  : 'cancelling',
        );
        const body =
            operation === 'submit'
                ? submissionBody(frozen.current)
                : {
                      actor_user_id: binding.actorId,
                      idempotency_key: binding.requestUuid,
                  };
        try {
            const response = await axios.post(
                `/it/catalog/${binding.itemId}/submissions${operation === 'submit' ? '' : `/${operation}`}`,
                body,
                {
                    signal: abort.signal,
                    timeout: latest.current.timeoutMs ?? 15000,
                    headers: { Accept: 'application/json' },
                },
            );
            if (operationEpoch !== epoch.current) return;
            const outcome = readCatalogueSubmissionOutcome(
                response.data,
                binding,
            );
            if (!outcome) {
                transition(
                    'unknown',
                    'The server response did not confirm this request. Check its saved result.',
                );
            } else if (outcome.status === 'committed') {
                clearMarker(binding);
                frozen.current = null;
                setResult(outcome);
                transition('committed');
            } else if (outcome.status === 'cancelled') {
                clearMarker(binding);
                frozen.current = null;
                transition('cancelled');
            } else {
                transition(
                    'not_found',
                    frozen.current
                        ? 'No saved result was found. Retry the original request or cancel its identity.'
                        : 'No saved result was found. Request details were not kept in browser storage. Cancel this identity before starting again.',
                );
            }
        } catch (error) {
            if (operationEpoch !== epoch.current) return;
            const status = axios.isAxiosError(error)
                ? error.response?.status
                : null;
            if (status === 401 || status === 419) {
                latest.current.onSession();
                transition(
                    'session',
                    'Sign in again as the original person, then check the saved result.',
                );
            } else if (status === 403 || status === 404) {
                frozen.current = null;
                latest.current.onDenied();
                transition(
                    'denied',
                    'This request is no longer available to the original account. Private details have been cleared. Return to IT & Support to refresh your access.',
                );
            } else if (
                status === 422 &&
                axios.isAxiosError(error) &&
                record(error.response?.data)
            ) {
                const raw = error.response.data.errors;
                const fields: Record<string, string> = {};
                if (record(raw))
                    for (const [field, value] of Object.entries(raw)) {
                        if (
                            Array.isArray(value) &&
                            typeof value[0] === 'string'
                        )
                            fields[field] = value[0];
                    }
                setErrors(fields);
                if (
                    wasUncertain ||
                    operation !== 'submit' ||
                    fields.idempotency_key ||
                    fields.schema_version
                ) {
                    transition(
                        'conflict',
                        'The original request or published form has changed. Check its result or cancel the uncommitted request before starting again.',
                    );
                } else {
                    frozen.current = null;
                    clearMarker(binding);
                    transition(
                        'validation',
                        'Check the request details before submitting again.',
                    );
                }
            } else if (status === 409) {
                transition(
                    'conflict',
                    'The request identity or form has changed. Check the original result before continuing.',
                );
            } else {
                transition(
                    'unknown',
                    'The request outcome is unconfirmed. Check its saved result before trying again.',
                );
            }
        } finally {
            if (operationEpoch === epoch.current) controller.current = null;
        }
    };
    const submit = (
        values: CatalogueRequestValues,
        siteId: number | null,
        requestedForId?: number | null,
        draftReference?: ItDraftCommitReference & {
            staged_attachment_ids: number[];
        },
    ) => {
        if (!['idle', 'validation'].includes(currentPhase.current)) return;
        frozen.current = {
            actor_user_id: identity.current.actorId,
            idempotency_key: identity.current.requestUuid,
            schema_version: identity.current.schemaVersion,
            values: copyValues(values),
            site_id: siteId,
            ...draftReference,
            ...(requestedForId !== undefined
                ? { requested_for_user_id: requestedForId }
                : {}),
        };
        return execute('submit');
    };
    const stopWaiting = () => {
        if (!controller.current) return;
        epoch.current++;
        controller.current.abort();
        controller.current = null;
        transition(
            'unknown',
            'Waiting stopped. The server may still finish; check the original result before continuing.',
        );
    };
    const deny = () => {
        epoch.current++;
        controller.current?.abort();
        controller.current = null;
        frozen.current = null;
        latest.current.onDenied();
        transition(
            'denied',
            'Access changed. Private request details have been cleared. Return to IT & Support to refresh your access.',
        );
    };
    const pauseForSession = (
        values: CatalogueRequestValues,
        siteId: number | null,
        requestedForId?: number | null,
    ) => {
        epoch.current++;
        controller.current?.abort();
        controller.current = null;
        if (
            currentPhase.current === 'idle' ||
            currentPhase.current === 'validation'
        ) {
            frozen.current = {
                actor_user_id: identity.current.actorId,
                idempotency_key: identity.current.requestUuid,
                schema_version: identity.current.schemaVersion,
                values: copyValues(values),
                site_id: siteId,
                ...(requestedForId !== undefined
                    ? { requested_for_user_id: requestedForId }
                    : {}),
            };
        }
        latest.current.onSession();
        remember(identity.current);
        transition(
            'session',
            'Sign in as the original person, then check the request before continuing.',
        );
    };
    return {
        phase,
        message,
        errors,
        result,
        submit,
        stopWaiting,
        deny,
        pauseForSession,
        clearFieldErrors: (field: string) => {
            if (!['idle', 'validation'].includes(currentPhase.current)) return;
            setErrors((previous) =>
                Object.fromEntries(
                    Object.entries(previous).filter(
                        ([key]) =>
                            key !== field &&
                            !key.startsWith(`${field}.`) &&
                            !(field.startsWith('values.') && key === 'values'),
                    ),
                ),
            );
        },
        recover: () => execute('recover'),
        cancel: () => execute('cancel'),
        retry: () => execute('submit'),
        canRetry: frozen.current !== null,
        identity: identity.current,
        recoveryStored,
        pending: ['sending', 'recovering', 'cancelling'].includes(phase),
        editing: ['idle', 'validation'].includes(phase),
    };
}
