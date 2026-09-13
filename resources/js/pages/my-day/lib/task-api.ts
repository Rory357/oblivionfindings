export class TaskRequestError extends Error {
    constructor(
        message: string,
        public fields: Record<string, string> = {},
        public uncertain = false,
    ) {
        super(message);
    }
}

export async function taskRequest<T>(
    url: string,
    method = 'GET',
    data?: unknown,
): Promise<T> {
    const token = document.querySelector<HTMLMetaElement>(
        'meta[name="csrf-token"]',
    )?.content;
    const xsrf = document.cookie
        .split('; ')
        .find((cookie) => cookie.startsWith('XSRF-TOKEN='))
        ?.slice(11);
    let response: Response;
    try {
        response = await fetch(url, {
            method,
            credentials: 'same-origin',
            cache: 'no-store',
            signal: AbortSignal.timeout(20_000),
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                ...(xsrf
                    ? { 'X-XSRF-TOKEN': decodeURIComponent(xsrf) }
                    : token
                      ? { 'X-CSRF-TOKEN': token }
                      : {}),
            },
            ...(data === undefined ? {} : { body: JSON.stringify(data) }),
        });
    } catch {
        throw new TaskRequestError(
            'The connection was interrupted. Keep this form open and retry to check the saved result.',
            {},
            method !== 'GET',
        );
    }
    const body = await response.json().catch(() => null);
    if (!response.ok || !body) {
        const fields = Object.fromEntries(
            Object.entries(body?.errors ?? {}).map(([key, value]) => [
                key,
                Array.isArray(value) ? String(value[0]) : String(value),
            ]),
        );
        const message =
            response.status === 419 || response.status === 401
                ? 'Your session expired. Sign in again in another window, then retry here.'
                : response.status === 403
                  ? 'Your access to this shift or person has changed. Refresh My Day before continuing.'
                  : (Object.values(fields)[0] ??
                    body?.message ??
                    'We could not confirm the saved result. Retry before adding this task again.');
        throw new TaskRequestError(
            message,
            fields,
            response.status >= 500 || !body,
        );
    }
    return body as T;
}
