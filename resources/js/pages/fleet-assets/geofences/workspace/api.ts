import { useEffect, useState } from 'react';
export const base = '/fleet-assets/geofences';
export class RequestError extends Error {
    constructor(
        message: string,
        public status: number,
        public errors: Record<string, string[]> = {},
    ) {
        super(message);
    }
}
export async function request<T>(
    url: string,
    method = 'GET',
    body?: unknown,
    signal?: AbortSignal,
): Promise<T> {
    const token = document.querySelector<HTMLMetaElement>(
        'meta[name="csrf-token"]',
    )?.content;
    const xsrf = document.cookie
        .split('; ')
        .find((c) => c.startsWith('XSRF-TOKEN='))
        ?.slice(11);
    const response = await fetch(url, {
        method,
        signal,
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...(token ? { 'X-CSRF-TOKEN': token } : {}),
            ...(xsrf ? { 'X-XSRF-TOKEN': decodeURIComponent(xsrf) } : {}),
        },
        ...(body !== undefined ? { body: JSON.stringify(body) } : {}),
    });
    const data = await response.json().catch(() => ({
        message: 'The server could not complete this request.',
    }));
    if (!response.ok)
        throw new RequestError(
            data.message ?? 'Request failed. Please retry.',
            response.status,
            data.errors,
        );
    return data as T;
}
export function query(path: string, params: Record<string, unknown>) {
    const qs = new URLSearchParams();
    Object.entries(params).forEach(([k, v]) => {
        if (v !== '' && v !== null && v !== undefined) qs.set(k, String(v));
    });
    return `${base}${path}?${qs}`;
}
export function useRemote<T>(url: string | null, refresh = 0) {
    const [data, setData] = useState<T | null>(null),
        [error, setError] = useState(''),
        [loading, setLoading] = useState(!!url),
        [retry, setRetry] = useState(0);
    useEffect(() => {
        setData(null);
        setError('');
        if (!url) {
            setLoading(false);
            return;
        }
        const controller = new AbortController();
        setLoading(true);
        request<T>(url, 'GET', undefined, controller.signal)
            .then((v) => {
                if (!controller.signal.aborted) setData(v);
            })
            .catch((e) => {
                if (!controller.signal.aborted) setError(e.message);
            })
            .finally(() => {
                if (!controller.signal.aborted) setLoading(false);
            });
        return () => controller.abort();
    }, [url, refresh, retry]);
    return { data, error, loading, reload: () => setRetry((n) => n + 1) };
}
export function useDebounced(value: string, delay = 300) {
    const [v, setV] = useState(value);
    useEffect(() => {
        const t = setTimeout(() => setV(value), delay);
        return () => clearTimeout(t);
    }, [value, delay]);
    return v;
}
