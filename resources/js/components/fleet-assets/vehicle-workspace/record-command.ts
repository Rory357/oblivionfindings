import { useRef, useState } from 'react';

type FieldErrors = Record<string, string>;
type PendingCommand = { key: string; body: string; url: string };

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
        data: Record<string, unknown>,
    ): Promise<T | null> {
        if (busy.current || requiresReload) return null;
        const body = JSON.stringify(data);
        if (
            !uncertain &&
            (pending.current?.body !== body || pending.current?.url !== url)
        ) {
            pending.current = { key: crypto.randomUUID(), body, url };
        }
        const command = pending.current;
        if (!command) return null;
        busy.current = true;
        setProcessing(true);
        setErrors({});
        setMessage('');
        try {
            const xsrf = document.cookie
                .split('; ')
                .find((part) => part.startsWith('XSRF-TOKEN='))
                ?.slice(11);
            const csrf = document.querySelector<HTMLMetaElement>(
                'meta[name="csrf-token"]',
            )?.content;
            const response = await fetch(command.url, {
                method: 'POST',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'Idempotency-Key': command.key,
                    ...(xsrf
                        ? { 'X-XSRF-TOKEN': decodeURIComponent(xsrf) }
                        : csrf
                          ? { 'X-CSRF-TOKEN': csrf }
                          : {}),
                },
                body: command.body,
            });
            const result: unknown = await response.json().catch(() => null);
            if (response.ok && isResult(result)) {
                pending.current = null;
                setUncertain(false);
                return result;
            }
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
                        ? 'This record changed while you were editing. Review its latest version before saving again.'
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
    return {
        submit,
        processing,
        errors,
        clearError,
        message,
        uncertain,
        requiresReload,
        locked: processing || uncertain || requiresReload,
    };
}
