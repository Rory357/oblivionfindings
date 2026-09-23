import { useRef, useState } from 'react';

type FieldErrors = Record<string, string>;
type PendingCommand = { key: string; signature: string; url: string };
type CommandOptions = { method?: 'POST' | 'PUT' | 'DELETE' };

/** A stable identity for a payload, including staged files, so a retry reuses its key. */
function signatureOf(data: Record<string, unknown> | FormData): string {
    if (!(data instanceof FormData)) return JSON.stringify(data);
    const parts: string[] = [];
    data.forEach((value, key) => {
        parts.push(
            value instanceof File
                ? `${key}=file:${value.name}:${value.size}:${value.lastModified}`
                : `${key}=${String(value)}`,
        );
    });

    return parts.join('&');
}

function csrfHeaders(): Record<string, string> {
    const xsrf = document.cookie
        .split('; ')
        .find((part) => part.startsWith('XSRF-TOKEN='))
        ?.slice(11);
    const csrf = document.querySelector<HTMLMetaElement>(
        'meta[name="csrf-token"]',
    )?.content;

    return xsrf
        ? { 'X-XSRF-TOKEN': decodeURIComponent(xsrf) }
        : csrf
          ? { 'X-CSRF-TOKEN': csrf }
          : {};
}

/** Keep an uncertain request's exact identity until its result is confirmed. */
export function useVehicleRecordCommand<T>(
    isResult: (value: unknown) => value is T,
) {
    const pending = useRef<PendingCommand | null>(null);
    const busy = useRef(false);
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<FieldErrors>({});
    const [message, setMessage] = useState('');
    const [uncertain, setUncertain] = useState(false);
    const [requiresReload, setRequiresReload] = useState(false);

    async function submit(
        url: string,
        data: Record<string, unknown> | FormData,
        options: CommandOptions = {},
    ): Promise<T | null> {
        if (busy.current || requiresReload) return null;
        const signature = signatureOf(data);
        if (
            !uncertain &&
            (pending.current?.signature !== signature ||
                pending.current?.url !== url)
        ) {
            pending.current = { key: crypto.randomUUID(), signature, url };
        }
        const command = pending.current;
        if (!command) return null;
        const isForm = data instanceof FormData;
        // An uncertain retry resends the original body, whatever the caller now holds.
        const body = isForm ? data : uncertain ? command.signature : signature;
        busy.current = true;
        setProcessing(true);
        setErrors({});
        setMessage('');
        try {
            const response = await fetch(command.url, {
                method: options.method ?? 'POST',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: {
                    Accept: 'application/json',
                    ...(isForm ? {} : { 'Content-Type': 'application/json' }),
                    'X-Requested-With': 'XMLHttpRequest',
                    'Idempotency-Key': command.key,
                    ...csrfHeaders(),
                },
                body,
            });
            const result: unknown = await response.json().catch(() => null);
            if (response.ok && isResult(result)) {
                pending.current = null;
                setUncertain(false);
                return result;
            }
            const serverMessage =
                result &&
                typeof result === 'object' &&
                'message' in result &&
                typeof result.message === 'string'
                    ? result.message
                    : '';
            if (
                response.status === 422 &&
                result &&
                typeof result === 'object' &&
                'errors' in result
            ) {
                const raw = result.errors;
                const fields: FieldErrors = {};
                if (raw && typeof raw === 'object') {
                    Object.entries(raw).forEach(([key, value]) => {
                        const first = Array.isArray(value) ? value[0] : value;
                        if (typeof first === 'string') fields[key] = first;
                    });
                }
                setErrors(fields);
                setMessage(
                    'Check the highlighted fields. Your entries have been kept.',
                );
                setUncertain(false);
            } else if ([401, 403, 404, 409, 419].includes(response.status)) {
                setRequiresReload(true);
                setUncertain(false);
                setMessage(
                    response.status === 409
                        ? serverMessage ||
                              'This record changed while you were editing. Review its latest version before saving again.'
                        : response.status === 403
                          ? 'You no longer have access to make this change. Reload this vehicle to check what is available.'
                          : 'Your access or session has changed. Reload this vehicle to check what is available.',
                );
            } else {
                setUncertain(true);
                setMessage(
                    'The save could not be confirmed. Retry the same submission to check its result safely.',
                );
            }
        } catch {
            setUncertain(true);
            setMessage(
                'The connection was interrupted. Retry the same submission; your entries have been kept.',
            );
        } finally {
            busy.current = false;
            setProcessing(false);
        }
        return null;
    }

    const clearError = (field: string) =>
        setErrors((current) => {
            const next = { ...current };
            delete next[field];
            return next;
        });
    /** Forget any pending identity once the caller has reloaded the source. */
    const reset = () => {
        pending.current = null;
        setErrors({});
        setMessage('');
        setUncertain(false);
        setRequiresReload(false);
    };
    return {
        submit,
        processing,
        errors,
        clearError,
        message,
        uncertain,
        requiresReload,
        reset,
        locked: processing || uncertain || requiresReload,
    };
}

/** Accepts any JSON object response as the command result. */
export const isJsonObject = (
    value: unknown,
): value is Record<string, unknown> =>
    !!value && typeof value === 'object' && !Array.isArray(value);
