import { useCallback, useEffect, useRef, useState } from 'react';
import type {
    ControlledAction,
    ControlledActionResult,
    ControlledActionValues,
    ControlledProductPayload,
} from './product-types';

const ENDPOINT = '/emar/controlled/product';
/** Keep reader scope on refresh; view/search controls do not alter the API read. */
export function controlledProductReadUrl(url: string): string {
    const current = new URLSearchParams(url.split('?')[1] ?? '');
    const query = new URLSearchParams();
    for (const key of [
        'site_id',
        'client_id',
        'client_medication_id',
        'date',
    ]) {
        const value = current.get(key);
        if (value) query.set(key, value);
    }
    return ENDPOINT + (query.size ? `?${query}` : '');
}
export class ControlledApiError extends Error {
    constructor(
        message: string,
        public errors: Record<string, string> = {},
        public status = 0,
        public saveUncertain = false,
    ) {
        super(message);
    }
}
function csrfHeaders(): Record<string, string> {
    const token = document.querySelector<HTMLMetaElement>(
        'meta[name="csrf-token"]',
    )?.content;
    const cookie = document.cookie
        .split('; ')
        .find((value) => value.startsWith('XSRF-TOKEN='));
    return token
        ? { 'X-CSRF-TOKEN': token }
        : cookie
          ? { 'X-XSRF-TOKEN': decodeURIComponent(cookie.substring(11)) }
          : {};
}
async function readResponse<T>(
    response: Response,
    saveRequest = false,
): Promise<T> {
    const contentType = response.headers.get('content-type') ?? '';
    if (!contentType.includes('json'))
        throw new ControlledApiError(
            saveRequest && (response.ok || response.status >= 500)
                ? 'We couldn’t confirm the save. Retry the same request to check its result.'
                : 'Your session may have ended. Refresh the page and try again.',
            {},
            response.status,
            saveRequest && (response.ok || response.status >= 500),
        );
    const body = await response.json();
    if (!response.ok) {
        const errors = Object.fromEntries(
            Object.entries(body.errors ?? {}).map(([key, value]) => [
                key,
                Array.isArray(value) ? String(value[0]) : String(value),
            ]),
        );
        throw new ControlledApiError(
            saveRequest && response.status >= 500
                ? 'We couldn’t confirm the save. Retry the same request to check its result.'
                : (body.message ??
                      'This was not saved. Check the details and try again.'),
            errors,
            response.status,
            saveRequest && response.status >= 500,
        );
    }
    return body as T;
}
export async function recordControlledAction(
    action: ControlledAction,
    values: ControlledActionValues,
    clientRequestUuid: string,
): Promise<ControlledActionResult> {
    if (!navigator.onLine)
        throw new ControlledApiError(
            'You’re offline — nothing was saved. Your entries are still here. Try again when you’re online.',
        );
    try {
        const fields: ControlledActionValues = {
            ...values,
            action,
            client_request_uuid: clientRequestUuid,
        };
        const multipart = Object.values(fields).some(
            (value) => value instanceof File,
        );
        const form = new FormData();
        if (multipart)
            Object.entries(fields).forEach(([key, value]) => {
                if (value instanceof File) form.append(key, value);
                else if (Array.isArray(value))
                    value.forEach((item, index) => {
                        if (typeof item === 'object' && item !== null) {
                            Object.entries(item).forEach(
                                ([field, fieldValue]) => {
                                    if (fieldValue !== undefined)
                                        form.append(
                                            `${key}[${index}][${field}]`,
                                            String(fieldValue),
                                        );
                                },
                            );
                        } else form.append(key + '[]', String(item));
                    });
                else
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
            });
        return await readResponse<ControlledActionResult>(
            await fetch(`${ENDPOINT}/actions/${action}`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    ...(multipart
                        ? {}
                        : { 'Content-Type': 'application/json' }),
                    ...csrfHeaders(),
                },
                body: multipart ? form : JSON.stringify(fields),
            }),
            true,
        );
    } catch (error) {
        if (error instanceof ControlledApiError) throw error;
        throw new ControlledApiError(
            'We couldn’t confirm the save. Your entries are still here. Retry to check the same request safely.',
            {},
            0,
            true,
        );
    }
}
export function useControlledProduct(
    initial?: ControlledProductPayload,
    pageUrl?: string,
) {
    const [browserSearch, setBrowserSearch] = useState(() =>
        typeof window === 'undefined' ? '' : window.location.search,
    );
    const readUrl = controlledProductReadUrl(pageUrl ?? browserSearch);
    const [snapshot, setSnapshot] = useState({
        url: readUrl,
        payload: initial ?? (null as ControlledProductPayload | null),
    });
    const payload = snapshot.url === readUrl ? snapshot.payload : null;
    const [loading, setLoading] = useState(!initial);
    const [error, setError] = useState<ControlledApiError | null>(null);
    const [offline, setOffline] = useState(
        typeof navigator !== 'undefined' && !navigator.onLine,
    );
    const generation = useRef(0);
    const controller = useRef<AbortController | null>(null);
    const currentScope = useRef(readUrl);
    useEffect(() => {
        currentScope.current = readUrl;
    }, [readUrl]);
    const cancelRead = useCallback(() => {
        controller.current?.abort();
        generation.current++;
    }, []);
    const refresh = useCallback(async () => {
        controller.current?.abort();
        const active = new AbortController();
        controller.current = active;
        const request = ++generation.current;
        setLoading(true);
        try {
            const data = await readResponse<ControlledProductPayload>(
                await fetch(readUrl, {
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json' },
                    signal: active.signal,
                }),
            );
            if (generation.current === request) {
                setSnapshot({ url: readUrl, payload: data });
                setError(null);
            }
        } catch (cause) {
            if (!active.signal.aborted && generation.current === request) {
                if (
                    cause instanceof ControlledApiError &&
                    [401, 403, 404, 419].includes(cause.status)
                )
                    setSnapshot({ url: readUrl, payload: null });
                setError(
                    cause instanceof ControlledApiError
                        ? cause
                        : new ControlledApiError(
                              'We couldn’t load controlled medicines. Try again.',
                          ),
                );
            }
        } finally {
            if (generation.current === request) setLoading(false);
        }
    }, [readUrl]);
    useEffect(() => {
        const navigate = () => setBrowserSearch(window.location.search);
        window.addEventListener('popstate', navigate);
        return () => window.removeEventListener('popstate', navigate);
    }, []);
    useEffect(() => {
        void refresh();
        const update = () => setOffline(!navigator.onLine);
        window.addEventListener('online', update);
        window.addEventListener('offline', update);
        return () => {
            cancelRead();
            window.removeEventListener('online', update);
            window.removeEventListener('offline', update);
        };
    }, [refresh, cancelRead]);
    const act = useCallback(
        async (
            action: ControlledAction,
            values: ControlledActionValues,
            uuid: string,
        ) => {
            const result = await recordControlledAction(action, values, uuid);
            // A completed action must not replace or abort a newly selected read.
            if (currentScope.current !== readUrl) return result;
            if (result.payload && readUrl === ENDPOINT) {
                generation.current++;
                controller.current?.abort();
                setSnapshot({ url: readUrl, payload: result.payload });
                setLoading(false);
                setError(null);
            } else await refresh();
            return result;
        },
        [refresh, readUrl],
    );
    return {
        payload,
        loading: loading || snapshot.url !== readUrl,
        error,
        offline,
        refresh,
        act,
    };
}
export type ControlledWorkspace = ReturnType<typeof useControlledProduct>;
